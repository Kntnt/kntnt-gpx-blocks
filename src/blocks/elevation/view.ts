/**
 * GPX Elevation frontend Interactivity API module.
 *
 * Registers the `kntnt-gpx-blocks` store (merged with the Map module's
 * registration) and implements:
 *
 * - `callbacks.initElevation` — locates the server-rendered cursor LINE
 *   inside the SVG and the cursor DOT plus TOOLTIP HTML overlays alongside
 *   the SVG (issue #136), reads the SVG-space chart bounds from the SVG's
 *   own viewBox attribute, and attaches native Pointer Events on the SVG
 *   that write `state[mapId].fraction` from the pointer's x-position.
 *   `pointerdown` sets the fraction immediately (so a tap pins the cursor)
 *   and calls `setPointerCapture` so a drag keeps updating the fraction
 *   even when the finger drifts off the SVG. The cursor stays at the last
 *   position after the gesture ends; mouse `pointerleave` on the block
 *   element nulls the fraction (skipped on touch — touch has no hover, and
 *   lifting the finger would otherwise dismiss the cursor before the user
 *   could read it). CSS `touch-action: none` on the SVG suppresses the
 *   browser's scroll-on-touch behaviour, which would otherwise translate
 *   any slight vertical jitter during a horizontal scrub into a page
 *   scroll.
 * - `callbacks.onElevationCursorChange` — reacts to `state[mapId].fraction`
 *   changes (from Elevation itself or from GPX Map) by updating the cursor
 *   line's `x1`/`x2` attributes (inside the SVG, in viewBox logical units)
 *   and the HTML overlays' `style.left` / `style.top` (as percentages of
 *   the plot rectangle, since the overlay container spans the same plot
 *   rectangle in CSS pixels via shared padding variables). Issue #136
 *   moved the cursor dot and tooltip out of the SVG so they are immune to
 *   the wrapper-as-image layout's non-uniform stretch. Does NOT write back
 *   to state (no feedback loop). Namespaced per block so the Map module's
 *   own watch callback is not overwritten when both modules register into
 *   the shared `kntnt-gpx-blocks` store.
 *
 * The SVG (with the cursor line inside it) and the HTML cursor overlays are
 * fully server-rendered by `Render_Elevation.php`. The cursor-update
 * primitives — fraction-of-plot-rect math, DOM-mutation writes — live in
 * `./cursor.ts` so they are unit-testable independently of the
 * Interactivity API store.
 *
 * @since 1.0.0
 */

import { getContext, getElement, store } from '@wordpress/interactivity';
import { interpolateSample, sampleToSvg, type ChartBounds } from './geometry';
import {
	applyCursorPosition,
	clamp,
	formatDistance,
	formatElevation,
	hideCursor,
	samplePositionPercent,
	showCursor,
	type CursorOverlayElements,
} from './cursor';

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
	/** The block wrapper element — the positioning origin for the overlays. */
	wrapper: HTMLElement;
	/** The server-rendered SVG element. */
	svg: SVGSVGElement;
	/** The vertical cursor line inside the SVG. */
	cursorLine: SVGLineElement;
	/** The HTML wrapper element holding the dot and tooltip overlays. */
	cursorOverlay: HTMLElement;
	/** The dot on the elevation polyline (HTML element). */
	cursorDot: HTMLElement;
	/** The tooltip container element (HTML). */
	tooltip: HTMLElement;
	/** The first child of the tooltip — distance row. */
	tooltipDistance: HTMLElement;
	/** The second child of the tooltip — elevation row. */
	tooltipElevation: HTMLElement;
	/** SVG-space chart bounds — derived from the viewBox post-#135. */
	chart: ChartBounds;
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
 * Convert a client-space pointer x-coordinate to a fraction [0, 1] relative
 * to the SVG chart's plot area.
 *
 * Uses the SVG element's bounding rect to map client pixels to the plot
 * rectangle's horizontal range. Under the wrapper-as-image layout
 * (issue #135) the SVG fills the plot rectangle exactly, so the SVG's
 * bounding rect *is* the plot rectangle in CSS pixels.
 *
 * @since 1.0.0
 *
 * @param clientX - Pointer x in client (viewport) pixels.
 * @param svg     - The SVG element.
 * @return Fraction in [0, 1], already clamped.
 */
function clientXToFraction( clientX: number, svg: SVGSVGElement ): number {
	const rect = svg.getBoundingClientRect();
	if ( rect.width === 0 ) {
		return 0;
	}
	return clamp( ( clientX - rect.left ) / rect.width, 0, 1 );
}

// ─── Store ────────────────────────────────────────────────────────────────────

const { state } = store< { state: PluginState } >( 'kntnt-gpx-blocks', {
	state: {} as PluginState,
	callbacks: {
		/**
		 * Mount hook for the elevation chart container.
		 *
		 * Locates the server-rendered SVG, the cursor LINE inside it, and the
		 * cursor HTML overlays alongside it. Wires `pointermove` /
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

			const wrapper = ref as HTMLElement;

			const { mapId } = getContext< ElevationContext >();
			const mapState = state[ mapId ];

			// No state slice — bail; the PHP render should always populate it.
			if ( ! mapState ) {
				return;
			}

			// Locate the SVG and the cursor LINE inside it.
			const svg = wrapper.querySelector< SVGSVGElement >( 'svg' );
			if ( ! svg ) {
				return;
			}
			const cursorLine = svg.querySelector< SVGLineElement >(
				'.kntnt-gpx-blocks-elevation-cursor-line'
			);
			if ( ! cursorLine ) {
				return;
			}

			// Locate the HTML cursor overlays (issue #136 — moved out of the
			// SVG so they are immune to the wrapper-as-image layout's
			// non-uniform stretch).
			const cursorOverlay = wrapper.querySelector< HTMLElement >(
				'.kntnt-gpx-blocks-elevation-cursor'
			);
			const cursorDot = wrapper.querySelector< HTMLElement >(
				'.kntnt-gpx-blocks-elevation-cursor-dot'
			);
			const tooltip = wrapper.querySelector< HTMLElement >(
				'.kntnt-gpx-blocks-elevation-cursor-tooltip'
			);
			const tooltipDistance = wrapper.querySelector< HTMLElement >(
				'.kntnt-gpx-blocks-elevation-cursor-tooltip-distance'
			);
			const tooltipElevation = wrapper.querySelector< HTMLElement >(
				'.kntnt-gpx-blocks-elevation-cursor-tooltip-elevation'
			);

			if (
				! cursorOverlay ||
				! cursorDot ||
				! tooltip ||
				! tooltipDistance ||
				! tooltipElevation
			) {
				return;
			}

			// Derive SVG-space chart bounds from the viewBox. Under the
			// wrapper-as-image layout (issue #135) `PLOT_INSET = 0`, so the
			// chart occupies the full viewBox — `left = 0`, `right =
			// viewBox.width`, `top = 0`, `bottom = viewBox.height`. These
			// are the bounds the cursor LINE writes its `x1`/`x2` against.
			const vb = svg.viewBox.baseVal;
			const chart: ChartBounds = {
				left: 0,
				right: vb.width || 1200,
				top: 0,
				bottom: vb.height || 300,
			};

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

			// Record the mount entry immediately so onElevationCursorChange can
			// update the cursor as soon as fraction changes — even before the
			// pointer handlers are bound. The cursor sync only mutates DOM
			// attributes and never needs Leaflet, so it is safe to activate
			// right away.
			mountedElevations.set( ref, {
				points,
				totalDistance,
				yMin,
				yMax,
				wrapper,
				svg,
				cursorLine,
				cursorOverlay,
				cursorDot,
				tooltip,
				tooltipDistance,
				tooltipElevation,
				chart,
			} );

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
							svg
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
								svg
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
				wrapper.addEventListener( 'pointerleave', ( (
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
		 * React to changes in state[mapId].fraction and update the cursor.
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
		 * Issue #136 — the cursor dot and tooltip live in HTML overlays
		 * outside the SVG so they are immune to the wrapper-as-image
		 * layout's non-uniform stretch. The cursor line stays inside the
		 * SVG and continues to write `x1` / `x2` in viewBox units.
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

			const elements: CursorOverlayElements = {
				cursorLine: entry.cursorLine,
				cursorOverlay: entry.cursorOverlay,
				cursorDot: entry.cursorDot,
				tooltip: entry.tooltip,
				tooltipDistance: entry.tooltipDistance,
				tooltipElevation: entry.tooltipElevation,
			};

			// Null/undefined fraction — hide the cursor, unless the server
			// rendered a preview cursor at fraction=0.5 for editor mode. The
			// preview survives the initial mount-time watch fire (when fraction
			// is still undefined) and is cleared as soon as a real fraction
			// arrives, so live scrubbing immediately overrides the preview.
			if ( fraction === null || fraction === undefined ) {
				if ( entry.cursorOverlay.dataset.preview === '1' ) {
					return;
				}
				hideCursor( elements );
				return;
			}

			// First real fraction received — discard the preview flag so future
			// null transitions hide the cursor like on the frontend.
			if ( entry.cursorOverlay.dataset.preview === '1' ) {
				delete entry.cursorOverlay.dataset.preview;
			}

			const { points, totalDistance, yMin, yMax, chart } = entry;

			// Null points or too few to resolve — keep hidden.
			if ( ! points || points.length < 2 ) {
				hideCursor( elements );
				return;
			}

			// Interpolate a (distance, elevation) sample at the requested
			// fraction by binary-searching the LTTB distance array. The
			// tooltip continues to display these LTTB-interpolated values —
			// see docs/architecture.md § Decided behavior for the migration
			// path to full-fidelity tooltip values.
			const sample = interpolateSample( points, totalDistance, fraction );
			if ( ! sample ) {
				hideCursor( elements );
				return;
			}

			// Project the sample into SVG-space using the padded yMin/yMax
			// PHP also rendered the polyline with, so the cursor sits exactly
			// on the curve at every fraction. The cursor LINE writes the
			// viewBox-x directly; the HTML overlays use the fraction-of-plot-
			// rect percentages so they resolve against the overlay container,
			// which spans the same plot rectangle in CSS pixels.
			const { cx } = sampleToSvg(
				sample,
				totalDistance,
				yMin,
				yMax,
				chart
			);
			const { fxPct, fyPct } = samplePositionPercent(
				sample[ 0 ],
				sample[ 1 ],
				totalDistance,
				yMin,
				yMax
			);

			// Reveal the cursor line and the HTML overlay wrapper before
			// reading any layout values from the overlay's children — the
			// tooltip's intrinsic width is `0` while its container is
			// `display:none`, so we must un-hide first.
			showCursor( elements );

			applyCursorPosition( elements, {
				cx,
				fxPct,
				fyPct,
				distanceLabel: formatDistance( sample[ 0 ] ),
				elevationLabel: formatElevation( sample[ 1 ] ),
			} );
		},
	},
} );
