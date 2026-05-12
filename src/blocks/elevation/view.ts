/**
 * GPX Elevation frontend Interactivity API module.
 *
 * Registers the `kntnt-gpx-blocks` store's `initElevation` callback and
 * mounts the chart's SVG into the block wrapper. Step 3 ships the two
 * axis lines only — Step 4 layers tick marks + labels onto the same
 * SVG, Step 5 layers the elevation curve, Step 6 layers the cursor
 * with cross-block sync, Step 7 layers the tooltip.
 *
 * The frontend keeps the chart geometry in lock-step with the editor
 * preview by sharing the pure helpers under `./geometry/`. The host
 * differs (vanilla DOM here, React in `chart.tsx`) but the math is
 * identical, so the rendered output is byte-faithful across editor
 * and frontend for any given data + typography combination.
 *
 * Step 3 makes no store writes and does not yet read
 * `state[mapId].fraction`; Step 6 wires those in.
 *
 * @since 1.0.0
 */

import { getContext, getElement, store } from '@wordpress/interactivity';

import { computeMargins, type MarginsInput } from './geometry/margins';
import {
	createTextMeasurer,
	type TypographyAttributes,
} from './geometry/measure';

/**
 * SVG namespace constant.
 *
 * @since 1.0.0
 */
const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * Statistics shape emitted by `Render_Elevation::render()` on the
 * per-mapId state slice.
 *
 * @since 1.0.0
 */
interface ElevationStatistics {
	readonly min_elevation: number | null;
	readonly max_elevation: number | null;
	readonly distance: number | null;
}

/**
 * Shape of the per-mapId state slice the Elevation module reads.
 *
 * Only the fields Step 3 cares about are listed; the rest of the
 * slice (the Map block's geojson, fraction, etc.) is left untyped at
 * this surface because the elevation chart never reads it directly.
 *
 * @since 1.0.0
 */
interface ElevationStateSlice {
	readonly statistics?: ElevationStatistics;
}

/**
 * Tracks which wrapper elements have already been mounted so a
 * double-init under the Interactivity API does not stack a second
 * SVG on top of the first. Per-element idempotency, not per-mapId,
 * because every Elevation block on the page is a separate wrapper.
 *
 * @since 1.0.0
 */
const mounted = new WeakSet< Element >();

/**
 * Tag function — narrows an unknown value to a finite number, or
 * returns `null` for anything else.
 *
 * @since 1.0.0
 *
 * @param value Candidate value.
 * @return The number when finite; otherwise `null`.
 */
function asFiniteNumber( value: unknown ): number | null {
	return typeof value === 'number' && Number.isFinite( value ) ? value : null;
}

/**
 * Validates the statistics payload and converts it into the
 * {@link MarginsInput} the margin algorithm expects.
 *
 * Returns `null` when any of the Step 3 healthy-state preconditions
 * fails (missing elevation, zero distance). The frontend renders
 * those degenerate states through PHP's `render_warning()` instead of
 * mounting a chart, so this function never sees them under a normal
 * flow — but a defence-in-depth check keeps a malformed payload from
 * landing as `NaN` in the SVG geometry.
 *
 * @since 1.0.0
 *
 * @param stats Raw statistics object from the state slice.
 * @return The validated chart data, or `null` if unrenderable.
 */
function statisticsToMarginsInput(
	stats: ElevationStatistics | undefined
): MarginsInput | null {
	if ( ! stats ) {
		return null;
	}
	const min = asFiniteNumber( stats.min_elevation );
	const max = asFiniteNumber( stats.max_elevation );
	if ( min === null || max === null ) {
		return null;
	}
	const distance = asFiniteNumber( stats.distance );
	if ( distance === null || distance <= 0 ) {
		return null;
	}
	return { minElevation: min, maxElevation: max, distance };
}

/**
 * Replaces the chart's two axis `<line>` elements inside the SVG.
 *
 * Removes any existing axis lines before adding fresh ones so a
 * resize-driven redraw does not stack duplicates. The viewBox is
 * resynced on every redraw because the SVG's rendered size and the
 * SVG user-unit space share a 1:1 mapping.
 *
 * @since 1.0.0
 *
 * @param svg            The SVG host.
 * @param w              Current rendered width in CSS pixels.
 * @param h              Current rendered height in CSS pixels.
 * @param margins        Resolved margin scalars from the margin algorithm.
 * @param margins.wLeft  Left margin in user units.
 * @param margins.wRight Right margin in user units.
 * @param margins.h      Bottom margin in user units.
 */
function drawAxes(
	svg: SVGSVGElement,
	w: number,
	h: number,
	margins: { wLeft: number; wRight: number; h: number }
): void {
	svg.setAttribute( 'viewBox', `0 0 ${ w } ${ h }` );

	for ( const existing of Array.from(
		svg.querySelectorAll( '.kntnt-gpx-blocks-elevation-axis-x' )
	) ) {
		existing.remove();
	}
	for ( const existing of Array.from(
		svg.querySelectorAll( '.kntnt-gpx-blocks-elevation-axis-y' )
	) ) {
		existing.remove();
	}

	const xLine = document.createElementNS( SVG_NS, 'line' );
	xLine.setAttribute( 'class', 'kntnt-gpx-blocks-elevation-axis-x' );
	xLine.setAttribute( 'x1', String( margins.wLeft ) );
	xLine.setAttribute( 'y1', String( h - margins.h ) );
	xLine.setAttribute( 'x2', String( w - margins.wRight ) );
	xLine.setAttribute( 'y2', String( h - margins.h ) );
	xLine.setAttribute( 'stroke', 'var(--kntnt-gpx-blocks-elevation-axis)' );
	xLine.setAttribute( 'stroke-width', '1' );
	svg.appendChild( xLine );

	const yLine = document.createElementNS( SVG_NS, 'line' );
	yLine.setAttribute( 'class', 'kntnt-gpx-blocks-elevation-axis-y' );
	yLine.setAttribute( 'x1', String( margins.wLeft ) );
	yLine.setAttribute( 'y1', String( h - margins.h ) );
	yLine.setAttribute( 'x2', String( margins.wLeft ) );
	yLine.setAttribute( 'y2', '0' );
	yLine.setAttribute( 'stroke', 'var(--kntnt-gpx-blocks-elevation-axis)' );
	yLine.setAttribute( 'stroke-width', '1' );
	svg.appendChild( yLine );
}

/**
 * Reads the current rendered size of an SVG element via
 * `getBoundingClientRect`. Negative-or-NaN values clamp to zero so a
 * pre-layout call does not propagate garbage into the viewBox.
 *
 * @since 1.0.0
 *
 * @param svg The SVG element.
 * @return `{ w, h }` with non-negative values.
 */
function readSize( svg: SVGSVGElement ): {
	readonly w: number;
	readonly h: number;
} {
	const rect = svg.getBoundingClientRect();
	return {
		w: rect.width > 0 ? rect.width : 0,
		h: rect.height > 0 ? rect.height : 0,
	};
}

store( 'kntnt-gpx-blocks', {
	callbacks: {
		/**
		 * Mounts the Elevation chart on its wrapper element.
		 *
		 * Awaits `document.fonts.ready` before measuring so the
		 * margin algorithm reads the final webfont metrics rather
		 * than the fallback's. A `loadingdone` listener re-measures
		 * when late-loaded fonts arrive. ResizeObserver triggers
		 * axis redraw without recomputing margins.
		 *
		 * @since 1.0.0
		 */
		async initElevation(): Promise< void > {
			// Locate the wrapper element and guard against double-init.
			const element = getElement();
			const ref = element?.ref;
			if ( ! ref || ! ( ref instanceof Element ) ) {
				return;
			}
			if ( mounted.has( ref ) ) {
				return;
			}
			mounted.add( ref );

			// Read the state slice for this block's `mapId`. Skip
			// silently when the slice or the statistics are missing —
			// PHP's warning path has already handled those branches.
			const ctx = getContext< { readonly mapId?: string } >();
			const mapId = typeof ctx?.mapId === 'string' ? ctx.mapId : '';
			if ( mapId === '' ) {
				return;
			}
			const stateAny = store( 'kntnt-gpx-blocks' ) as unknown as {
				readonly state: Record<
					string,
					ElevationStateSlice | undefined
				>;
			};
			const slice = stateAny.state[ mapId ];
			const data = statisticsToMarginsInput( slice?.statistics );
			if ( ! data ) {
				return;
			}

			// Wait for fonts before the first measurement. `document.fonts`
			// is universally present in modern browsers; the guard is a
			// defence-in-depth for headless environments.
			if ( typeof document !== 'undefined' && document.fonts ) {
				try {
					await document.fonts.ready;
				} catch {
					// `fonts.ready` rejecting is extremely rare; fall
					// through so the chart still mounts.
				}
			}

			// Mount the SVG host. The wrapper carries the
			// `--kntnt-gpx-blocks-elevation-axis` custom property and
			// any inline typography the SCSS rule converts into the
			// SVG's font-* declarations; the measurer's hidden <text>
			// nodes inherit those values through the standard CSS
			// inheritance chain.
			const svg = document.createElementNS(
				SVG_NS,
				'svg'
			) as SVGSVGElement;
			svg.setAttribute( 'class', 'kntnt-gpx-blocks-elevation-chart-svg' );
			svg.setAttribute( 'width', '100%' );
			svg.setAttribute( 'height', '100%' );
			ref.appendChild( svg );

			// Compute margins once. ResizeObserver does not invalidate
			// them — margins depend only on data + typography, neither
			// of which a wrapper-size change implies.
			const measure = createTextMeasurer( svg );
			const typography: TypographyAttributes = {};
			let margins = computeMargins( data, typography, measure );

			const redraw = (): void => {
				const { w, h } = readSize( svg );
				if ( w === 0 || h === 0 ) {
					return;
				}
				drawAxes( svg, w, h, margins );
			};

			redraw();

			// Re-measure when late-loaded fonts replace the fallback
			// metrics. The dep list above does not include typography
			// because the wrapper's resolved typography is constant
			// for a single block instance — fonts.loadingdone is the
			// one event that can change measurement results in place.
			if ( typeof document !== 'undefined' && document.fonts ) {
				document.fonts.addEventListener( 'loadingdone', () => {
					margins = computeMargins( data, typography, measure );
					redraw();
				} );
			}

			// ResizeObserver redraws axes on container size change.
			if ( typeof ResizeObserver !== 'undefined' ) {
				const ro = new ResizeObserver( redraw );
				ro.observe( svg );
			}
		},
	},
} );
