/**
 * GPX Elevation frontend Interactivity API module.
 *
 * Registers the `kntnt-gpx-blocks` store (merged with the Map module's
 * registration) and implements:
 *
 * - `callbacks.initElevation` — locates the server-rendered cursor group in
 *   the SVG, reads the chart dimensions from data attributes on that group,
 *   and attaches `pointermove` / `pointerleave` handlers on the SVG that
 *   write `state[mapId].fraction` from the pointer's x-position.
 * - `callbacks.onElevationCursorChange` — reacts to `state[mapId].fraction`
 *   changes (from Elevation itself or from GPX Map) by updating the cursor
 *   group's vertical line, dot, and tooltip text. Does NOT write back to state
 *   (no feedback loop). Namespaced per block so the Map module's own watch
 *   callback is not overwritten when both modules register into the shared
 *   `kntnt-gpx-blocks` store.
 *
 * The SVG and cursor group are fully server-rendered by Render_Elevation.php.
 * The cursor group carries `data-plot-left`, `data-plot-right`, `data-plot-top`,
 * and `data-plot-bottom` attributes matching the PHP constants so this module
 * never has to re-derive the chart margins.
 *
 * @since 1.0.0
 */

import { getContext, getElement, store } from '@wordpress/interactivity';

// ─── Types ────────────────────────────────────────────────────────────────────

/**
 * Shape of the per-map state slice this module cares about.
 * The full type is declared in the Map module; Elevation only reads and writes
 * `fraction` and reads `elevation`.
 *
 * @since 1.0.0
 */
interface ElevationState {
	fraction: number | null;
	/** Downsampled (distance, elevation) pairs from LTTB, hydrated by PHP. */
	elevation: Array< [ number, number ] >;
}

/**
 * The namespaced state keyed by mapId.
 *
 * @since 1.0.0
 */
interface PluginState {
	[ mapId: string ]: ElevationState;
}

/**
 * Context shape set via data-wp-context on the block element.
 *
 * @since 1.0.0
 */
interface ElevationContext {
	mapId: string;
}

/**
 * Per-element runtime data for the elevation chart.
 *
 * @since 1.0.0
 */
interface ElevationEntry {
	/** Downsampled (distance, elevation) pairs from state at mount time. */
	points: Array< [ number, number ] >;
	/** The server-rendered <g> cursor group element. */
	cursorGroup: SVGGElement;
	/** The vertical cursor line inside the group. */
	cursorLine: SVGLineElement;
	/** The dot on the elevation polyline. */
	cursorDot: SVGCircleElement;
	/** The tooltip rect behind the text. */
	tooltipRect: SVGRectElement;
	/** The tooltip text label. */
	tooltipText: SVGTextElement;
	/** Chart boundaries in SVG viewBox logical units. */
	chart: {
		left: number;
		right: number;
		top: number;
		bottom: number;
	};
	/** The SVG element itself, used for coordinate conversion. */
	svg: SVGSVGElement;
}

// ─── Module state ─────────────────────────────────────────────────────────────

/**
 * Per-mount data keyed by the block's root element.
 *
 * WeakMap ensures entries are garbage-collected when the element is removed.
 *
 * @since 1.0.0
 */
const mountedElevations = new WeakMap< Element, ElevationEntry >();

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Clamp a value to [min, max].
 *
 * @since 1.0.0
 *
 * @param value - Value to clamp.
 * @param min   - Lower bound.
 * @param max   - Upper bound.
 * @return Clamped value.
 */
function clamp( value: number, min: number, max: number ): number {
	return Math.max( min, Math.min( max, value ) );
}

/**
 * Convert a client-space pointer x-coordinate to a fraction [0, 1] relative
 * to the SVG chart's plot area.
 *
 * Uses the SVG element's bounding rect and the viewBox width to map client
 * pixels to logical units, then derives the fraction from the chart margins.
 *
 * @since 1.0.0
 *
 * @param clientX   - Pointer x in client (viewport) pixels.
 * @param svg       - The SVG element.
 * @param chart     - Chart boundaries in viewBox logical units.
 * @param viewWidth - ViewBox width (typically 1200).
 * @return Fraction in [0, 1], already clamped.
 */
function clientXToFraction(
	clientX: number,
	svg: SVGSVGElement,
	chart: ElevationEntry[ 'chart' ],
	viewWidth: number
): number {
	const rect = svg.getBoundingClientRect();
	if ( rect.width === 0 ) {
		return 0;
	}

	// Map client x to viewBox logical x.
	const viewX = ( ( clientX - rect.left ) / rect.width ) * viewWidth;

	// Convert to fraction within the chart area.
	const chartWidth = chart.right - chart.left;
	if ( chartWidth <= 0 ) {
		return 0;
	}

	return clamp( ( viewX - chart.left ) / chartWidth, 0, 1 );
}

/**
 * Build the tooltip label string for a given point.
 *
 * Formats distance in km when >= 1000 m, otherwise in m; elevation always in
 * metres.
 *
 * @since 1.0.0
 *
 * @param distanceM  - Distance in metres.
 * @param elevationM - Elevation in metres.
 * @return Formatted label, e.g. "3.2 km | 245 m".
 */
function formatTooltip( distanceM: number, elevationM: number ): string {
	const dist =
		distanceM >= 1000
			? `${ ( distanceM / 1000 ).toFixed( 1 ) } km`
			: `${ Math.round( distanceM ) } m`;
	const elev = `${ Math.round( elevationM ) } m`;
	return `${ dist } | ${ elev }`;
}

// ─── Store ────────────────────────────────────────────────────────────────────

const { state } = store< { state: PluginState } >( 'kntnt-gpx-blocks', {
	state: {} as PluginState,
	callbacks: {
		/**
		 * Mount hook for the elevation chart container.
		 *
		 * Locates the server-rendered cursor group inside the SVG, reads the chart
		 * boundaries from its data attributes, and wires `pointermove` /
		 * `pointerleave` handlers that write `state[mapId].fraction`.
		 *
		 * @since 1.0.0
		 */
		initElevation() {
			const { ref } = getElement();
			if ( ! ref ) {
				return;
			}

			// Guard against re-entry on ServerSideRender re-renders.
			if ( mountedElevations.has( ref ) ) {
				return;
			}

			const { mapId } = getContext< ElevationContext >();
			const mapState = state[ mapId ];

			// No state slice — bail; the PHP render should always populate it.
			if ( ! mapState ) {
				return;
			}

			// Locate the SVG and the server-rendered cursor group.
			const svg = ref.querySelector< SVGSVGElement >( 'svg' );
			if ( ! svg ) {
				return;
			}
			const cursorGroup = svg.querySelector< SVGGElement >(
				'.kntnt-gpx-blocks-elevation-cursor'
			);
			if ( ! cursorGroup ) {
				return;
			}

			const cursorLine = cursorGroup.querySelector< SVGLineElement >(
				'.kntnt-gpx-blocks-elevation-cursor-line'
			);
			const cursorDot = cursorGroup.querySelector< SVGCircleElement >(
				'.kntnt-gpx-blocks-elevation-cursor-dot'
			);
			const tooltipRect = cursorGroup.querySelector< SVGRectElement >(
				'.kntnt-gpx-blocks-elevation-cursor-tooltip-bg'
			);
			const tooltipText = cursorGroup.querySelector< SVGTextElement >(
				'.kntnt-gpx-blocks-elevation-cursor-tooltip-text'
			);

			// All cursor children are server-rendered; abort if any are missing.
			if (
				! cursorLine ||
				! cursorDot ||
				! tooltipRect ||
				! tooltipText
			) {
				return;
			}

			// Read chart boundaries from the cursor group's data attributes.
			// These are written by build_svg() and match the PHP MARGIN_* constants.
			const plotLeft = parseInt(
				cursorGroup.dataset.plotLeft ?? '56',
				10
			);
			const plotRight = parseInt(
				cursorGroup.dataset.plotRight ?? '1184',
				10
			);
			const plotTop = parseInt( cursorGroup.dataset.plotTop ?? '16', 10 );
			const plotBottom = parseInt(
				cursorGroup.dataset.plotBottom ?? '272',
				10
			);

			// Snapshot the elevation data array at mount time. Reads once from state
			// so the watch callback can use the stored reference without re-reading.
			const points = ( mapState.elevation ?? [] ) as Array<
				[ number, number ]
			>;

			const chart = {
				left: plotLeft,
				right: plotRight,
				top: plotTop,
				bottom: plotBottom,
			};

			// Record the mount entry immediately so onElevationCursorChange can
			// update the SVG cursor as soon as fraction changes — even before
			// the pointer handlers are bound. The cursor sync only mutates SVG
			// attributes and never needs Leaflet, so it is safe to activate
			// right away.
			mountedElevations.set( ref, {
				points,
				cursorGroup,
				cursorLine,
				cursorDot,
				tooltipRect,
				tooltipText,
				chart,
				svg,
			} );

			// ViewBox width — matches the PHP constant VIEWBOX_WIDTH (1200).
			const viewWidth = svg.viewBox.baseVal.width || 1200;

			// Defer pointer-event handler binding until the chart is in (or near)
			// the viewport. This mirrors the Map block's IntersectionObserver
			// pattern: heavy DOM listeners are attached lazily, but the cursor-sync
			// watch (onElevationCursorChange) is already live because mountedElevations is
			// set above.
			const bindPointerHandlers = () => {
				// Attach pointer tracking on the SVG element. pointermove computes
				// fraction from the x-position and writes it to shared state.
				svg.addEventListener( 'pointermove', ( e: PointerEvent ) => {
					const fraction = clientXToFraction(
						e.clientX,
						svg,
						chart,
						viewWidth
					);
					state[ mapId ].fraction = fraction;
				} );

				// Null the fraction when the pointer leaves so both cursors hide.
				svg.addEventListener( 'pointerleave', () => {
					state[ mapId ].fraction = null;
				} );
			};

			// Use IntersectionObserver when available (all evergreen browsers).
			// Fall back to immediate binding when the API is absent.
			if ( typeof IntersectionObserver !== 'undefined' ) {
				const observer = new IntersectionObserver(
					( entries, obs ) => {
						if ( ! entries[ 0 ]?.isIntersecting ) {
							return;
						}

						// Disconnect after first intersection — one-shot pattern.
						obs.disconnect();
						bindPointerHandlers();
					},
					// rootMargin pre-triggers 200 px before the SVG enters view,
					// matching the Map block's lazy-mount margin.
					{ rootMargin: '200px 0px', threshold: 0 }
				);
				observer.observe( ref );
			} else {
				// IntersectionObserver unavailable — bind immediately so the block
				// is still interactive.
				bindPointerHandlers();
			}
		},

		/**
		 * React to changes in state[mapId].fraction and update the cursor group.
		 *
		 * Called by data-wp-watch whenever fraction changes (written by either
		 * the Elevation's own pointermove or the Map block). Does NOT write back
		 * to state — that would create a feedback loop.
		 *
		 * Named per block (rather than the generic `onCursorChange`) so that
		 * registering both Map and Elevation modules into the same
		 * `kntnt-gpx-blocks` store does not overwrite each other's callbacks.
		 *
		 * @since 1.0.0
		 */
		onElevationCursorChange() {
			const { ref } = getElement();
			if ( ! ref ) {
				return;
			}

			const entry = mountedElevations.get( ref );
			if ( ! entry ) {
				return;
			}

			const { mapId } = getContext< ElevationContext >();
			const fraction = state[ mapId ]?.fraction;

			// Null/undefined fraction — hide the cursor group.
			if ( fraction === null || fraction === undefined ) {
				entry.cursorGroup.style.display = 'none';
				return;
			}

			const {
				points,
				cursorLine,
				cursorDot,
				tooltipRect,
				tooltipText,
				chart,
			} = entry;

			// Null points or too few to resolve — keep hidden.
			if ( ! points || points.length < 2 ) {
				entry.cursorGroup.style.display = 'none';
				return;
			}

			// Map fraction to the nearest downsampled elevation point.
			const index = Math.round( fraction * ( points.length - 1 ) );
			const clamped = clamp( index, 0, points.length - 1 );
			const pt = points[ clamped ] as [ number, number ];

			const totalDistance = (
				points[ points.length - 1 ] as [ number, number ]
			 )[ 0 ];
			const yMin = Math.min( ...points.map( ( p ) => p[ 1 ] ) );
			const yMax = Math.max( ...points.map( ( p ) => p[ 1 ] ) );

			const chartWidth = chart.right - chart.left;
			const chartHeight = chart.bottom - chart.top;

			// X position from fraction directly — close enough because LTTB
			// preserves endpoints and the fraction is in distance space.
			const cx =
				chart.left +
				( totalDistance > 0
					? ( pt[ 0 ] / totalDistance ) * chartWidth
					: 0 );

			// Y position: scale elevation within [yMin, yMax] to chart height;
			// SVG y grows downward so invert the ratio.
			const ySpan = yMax > yMin ? yMax - yMin : 1;
			const cy =
				chart.bottom - ( ( pt[ 1 ] - yMin ) / ySpan ) * chartHeight;

			// Update the vertical cursor line.
			cursorLine.setAttribute( 'x1', String( cx ) );
			cursorLine.setAttribute( 'x2', String( cx ) );

			// Move the dot to the data point.
			cursorDot.setAttribute( 'cx', String( cx ) );
			cursorDot.setAttribute( 'cy', String( cy ) );

			// Position the tooltip rect and text, keeping the rect within SVG bounds.
			const tooltipWidth = 120;
			const tooltipHeight = 28;
			const rectX = clamp(
				cx - tooltipWidth / 2,
				chart.left,
				chart.right - tooltipWidth
			);
			tooltipRect.setAttribute( 'x', String( rectX ) );
			tooltipRect.setAttribute( 'y', String( chart.top ) );
			tooltipRect.setAttribute( 'width', String( tooltipWidth ) );
			tooltipRect.setAttribute( 'height', String( tooltipHeight ) );

			// Update tooltip text content.
			tooltipText.setAttribute( 'x', String( rectX + tooltipWidth / 2 ) );
			tooltipText.setAttribute( 'y', String( chart.top + 6 ) );
			tooltipText.textContent = formatTooltip( pt[ 0 ], pt[ 1 ] );

			// Make the cursor group visible.
			entry.cursorGroup.style.display = '';
		},
	},
} );
