/**
 * GPX Elevation frontend Interactivity API module.
 *
 * Registers the `kntnt-gpx-blocks` store (merged with the Map module's
 * registration) and implements:
 *
 * - `callbacks.initElevation` — locates the server-rendered cursor group in
 *   the SVG, reads the chart dimensions from data attributes on that group,
 *   and attaches native Pointer Events on the SVG that write
 *   `state[mapId].fraction` from the pointer's x-position. `pointerdown`
 *   sets the fraction immediately (so a tap pins the cursor) and calls
 *   `setPointerCapture` so a drag keeps updating the fraction even when the
 *   finger drifts off the SVG. The cursor stays at the last position after
 *   the gesture ends; mouse `pointerleave` on the block element nulls the
 *   fraction (skipped on touch — touch has no hover, and lifting the finger
 *   would otherwise dismiss the cursor before the user could read it).
 *   CSS `touch-action: none` on the SVG suppresses the browser's
 *   scroll-on-touch behaviour, which would otherwise translate any slight
 *   vertical jitter during a horizontal scrub into a page scroll.
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
import { interpolateSample, sampleToSvg, type ChartBounds } from './geometry';

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
	/**
	 * Padded y-axis lower bound (metres) — matches the value PHP used to
	 * render the polyline. Required so the cursor sits on the curve rather
	 * than on the raw LTTB min/max range.
	 */
	yMin: number;
	/** Padded y-axis upper bound (metres). */
	yMax: number;
	/**
	 * Total track distance (metres). The Map block writes this; the
	 * Elevation block reads it to map fraction → distance in metres.
	 */
	totalDistance?: number;
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
	/**
	 * Total track distance (metres) snapshotted at mount time. Falls back
	 * to the LTTB series's last x-value when PHP did not supply one (e.g.
	 * when only Elevation is on the page); same physical meaning either
	 * way because LTTB preserves the track endpoints.
	 */
	totalDistance: number;
	/** Padded y-axis lower bound (metres) — matches the rendered polyline. */
	yMin: number;
	/** Padded y-axis upper bound (metres). */
	yMax: number;
	/** The server-rendered <g> cursor group element. */
	cursorGroup: SVGGElement;
	/** The vertical cursor line inside the group. */
	cursorLine: SVGLineElement;
	/** The dot on the elevation polyline. */
	cursorDot: SVGCircleElement;
	/** The tooltip rect behind the text. */
	tooltipRect: SVGRectElement;
	/** The parent <text> element for the two-line tooltip. */
	tooltipText: SVGTextElement;
	/** The first <tspan> child — distance row. */
	tooltipDistance: SVGTSpanElement;
	/** The second <tspan> child — elevation row. */
	tooltipElevation: SVGTSpanElement;
	/** Chart boundaries in SVG viewBox logical units. */
	chart: ChartBounds;
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
 * Format a distance value for the tooltip's first row.
 *
 * Switches from metres to kilometres at the 1000 m threshold, matching the
 * x-axis tick labels. Kilometres carry one decimal; metres are rounded to
 * the nearest whole number.
 *
 * @since 1.0.0
 *
 * @param distanceM - Distance in metres.
 * @return Formatted label, e.g. "3.2 km" or "245 m".
 */
function formatDistance( distanceM: number ): string {
	return distanceM >= 1000
		? `${ ( distanceM / 1000 ).toFixed( 1 ) } km`
		: `${ Math.round( distanceM ) } m`;
}

/**
 * Format an elevation value for the tooltip's second row.
 *
 * Always rendered in metres rounded to the nearest whole number — the GPX
 * vertical resolution does not justify decimals here.
 *
 * @since 1.0.0
 *
 * @param elevationM - Elevation in metres.
 * @return Formatted label, e.g. "245 m".
 */
function formatElevation( elevationM: number ): string {
	return `${ Math.round( elevationM ) } m`;
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
			const tooltipDistance =
				cursorGroup.querySelector< SVGTSpanElement >(
					'.kntnt-gpx-blocks-elevation-cursor-tooltip-distance'
				);
			const tooltipElevation =
				cursorGroup.querySelector< SVGTSpanElement >(
					'.kntnt-gpx-blocks-elevation-cursor-tooltip-elevation'
				);

			// All cursor children are server-rendered; abort if any are missing.
			if (
				! cursorLine ||
				! cursorDot ||
				! tooltipRect ||
				! tooltipText ||
				! tooltipDistance ||
				! tooltipElevation
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

			// Snapshot the padded y-bounds and total distance from state. yMin
			// and yMax must come from PHP (they were used to render the
			// polyline); falling back to the LTTB raw min/max would put the
			// cursor off the curve. totalDistance falls back to the LTTB
			// series's last x — same physical end-point because LTTB
			// preserves track endpoints.
			const lastPoint = points[ points.length - 1 ];
			const fallbackTotal = lastPoint !== undefined ? lastPoint[ 0 ] : 0;
			const totalDistance =
				typeof mapState.totalDistance === 'number' &&
				mapState.totalDistance > 0
					? mapState.totalDistance
					: fallbackTotal;
			const yMin = typeof mapState.yMin === 'number' ? mapState.yMin : 0;
			const yMax = typeof mapState.yMax === 'number' ? mapState.yMax : 1;

			const chart: ChartBounds = {
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
				totalDistance,
				yMin,
				yMax,
				cursorGroup,
				cursorLine,
				cursorDot,
				tooltipRect,
				tooltipText,
				tooltipDistance,
				tooltipElevation,
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
				let scrubbing = false;

				svg.addEventListener(
					'pointerdown',
					( event: PointerEvent ) => {
						// Ignore secondary pointers during an active scrub so a
						// stray second finger cannot hijack capture mid-gesture.
						if ( scrubbing ) {
							return;
						}

						event.preventDefault();
						scrubbing = true;
						svg.setPointerCapture( event.pointerId );
						state[ mapId ].fraction = clientXToFraction(
							event.clientX,
							svg,
							chart,
							viewWidth
						);
					}
				);

				svg.addEventListener(
					'pointermove',
					( event: PointerEvent ) => {
						// Capture during a scrub keeps this firing even when the
						// pointer is off the SVG; for plain mouse hover the event
						// also fires when the mouse is over the chart. Touch has
						// no hover, so the `mouse` branch is a no-op for touch.
						if ( scrubbing || event.pointerType === 'mouse' ) {
							state[ mapId ].fraction = clientXToFraction(
								event.clientX,
								svg,
								chart,
								viewWidth
							);
						}
					}
				);

				const endScrub = ( event: PointerEvent ) => {
					if ( ! scrubbing ) {
						return;
					}
					scrubbing = false;
					if ( svg.hasPointerCapture( event.pointerId ) ) {
						svg.releasePointerCapture( event.pointerId );
					}
				};

				svg.addEventListener( 'pointerup', endScrub );
				svg.addEventListener( 'pointercancel', endScrub );

				// Belt-and-suspenders against browsers (and touch emulators
				// like Firefox responsive design mode) where `touch-action:
				// none` on SVG elements is partially honoured. While
				// scrubbing, every touchmove on the SVG is preventDefault'd
				// to guarantee the page does not scroll. `passive: false` is
				// required for preventDefault to take effect.
				svg.addEventListener(
					'touchmove',
					( event: TouchEvent ) => {
						if ( scrubbing ) {
							event.preventDefault();
						}
					},
					{ passive: false }
				);

				// Mouse leaves the block — null the fraction so the cursor
				// disappears. Skip while scrubbing so brief excursions out
				// of the block during a fast mouse scrub do not flicker the
				// cursor off. Skip on touch entirely: a finger lift fires
				// `pointerleave` automatically (the touch pointer ceases),
				// and we want the cursor to stay at its last position so
				// the user can read it (and the corresponding map cursor)
				// after pointing.
				ref.addEventListener( 'pointerleave', ( (
					event: PointerEvent
				) => {
					if ( scrubbing || event.pointerType === 'touch' ) {
						return;
					}
					state[ mapId ].fraction = null;
				} ) as EventListener );
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
		 * The fraction is read at the very top of the function, before any
		 * guard, so the Interactivity API's signal-tracking establishes the
		 * subscription on the very first watch run regardless of whether the
		 * mount entry happens to be present yet. The Elevation block does set
		 * `mountedElevations` synchronously in `initElevation`, so in practice
		 * the entry is always present here, but the guard-after-read pattern
		 * is the robust idiom and matches the Map block's `onMapCursorChange`.
		 *
		 * @since 1.0.0
		 */
		onElevationCursorChange() {
			const { mapId } = getContext< ElevationContext >();
			const fraction = state[ mapId ]?.fraction;

			const { ref } = getElement();
			if ( ! ref ) {
				return;
			}

			const entry = mountedElevations.get( ref );
			if ( ! entry ) {
				return;
			}

			// Null/undefined fraction — hide the cursor group, unless the server
			// rendered a preview cursor at fraction=0.5 for editor mode. The
			// preview survives the initial mount-time watch fire (when fraction
			// is still undefined) and is cleared as soon as a real fraction
			// arrives, so live scrubbing immediately overrides the preview.
			if ( fraction === null || fraction === undefined ) {
				if ( entry.cursorGroup.dataset.preview === '1' ) {
					return;
				}
				entry.cursorGroup.style.display = 'none';
				return;
			}

			// First real fraction received — discard the preview flag so future
			// null transitions hide the cursor like on the frontend.
			if ( entry.cursorGroup.dataset.preview === '1' ) {
				delete entry.cursorGroup.dataset.preview;
			}

			const {
				points,
				totalDistance,
				yMin,
				yMax,
				cursorLine,
				cursorDot,
				tooltipRect,
				tooltipText,
				tooltipDistance,
				tooltipElevation,
				chart,
			} = entry;

			// Null points or too few to resolve — keep hidden.
			if ( ! points || points.length < 2 ) {
				entry.cursorGroup.style.display = 'none';
				return;
			}

			// Interpolate a (distance, elevation) sample at the requested
			// fraction by binary-searching the LTTB distance array. The
			// tooltip continues to display these LTTB-interpolated values —
			// see docs/architecture.md § Decided behavior for the migration
			// path to full-fidelity tooltip values.
			const sample = interpolateSample( points, totalDistance, fraction );
			if ( ! sample ) {
				entry.cursorGroup.style.display = 'none';
				return;
			}

			// Project the sample into SVG-space using the padded yMin/yMax
			// PHP also rendered the polyline with, so the cursor sits exactly
			// on the curve at every fraction.
			const { cx, cy } = sampleToSvg(
				sample,
				totalDistance,
				yMin,
				yMax,
				chart
			);

			// Update the vertical cursor line.
			cursorLine.setAttribute( 'x1', String( cx ) );
			cursorLine.setAttribute( 'x2', String( cx ) );

			// Move the dot to the data point.
			cursorDot.setAttribute( 'cx', String( cx ) );
			cursorDot.setAttribute( 'cy', String( cy ) );

			// Position the tooltip rect and text, keeping the rect within SVG bounds.
			// Width and height match the values written by Render_Elevation::build_svg
			// when it server-renders the cursor group; if those change there, change
			// these constants too.
			const tooltipWidth = 130;
			const tooltipHeight = 50;
			const rectX = clamp(
				cx - tooltipWidth / 2,
				chart.left,
				chart.right - tooltipWidth
			);
			tooltipRect.setAttribute( 'x', String( rectX ) );
			tooltipRect.setAttribute( 'y', String( chart.top ) );
			tooltipRect.setAttribute( 'width', String( tooltipWidth ) );
			tooltipRect.setAttribute( 'height', String( tooltipHeight ) );

			// Position the parent <text> centred horizontally inside the rect and
			// re-anchor each <tspan> to the same x so the two-line label stays
			// centred as the cursor moves. The y on the parent and the dy on the
			// second tspan are set once by PHP and need no per-frame update.
			const textX = rectX + tooltipWidth / 2;
			tooltipText.setAttribute( 'x', String( textX ) );
			tooltipDistance.setAttribute( 'x', String( textX ) );
			tooltipElevation.setAttribute( 'x', String( textX ) );
			tooltipDistance.textContent = formatDistance( sample[ 0 ] );
			tooltipElevation.textContent = formatElevation( sample[ 1 ] );

			// Make the cursor group visible.
			entry.cursorGroup.style.display = '';
		},
	},
} );
