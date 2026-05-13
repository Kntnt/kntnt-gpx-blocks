/**
 * GPX Elevation frontend Interactivity API module.
 *
 * Registers the `kntnt-gpx-blocks` store's `initElevation` callback and
 * mounts the chart's SVG into the block wrapper. Step 3 ships the two
 * axis lines; Step 4 layers tick marks and labels onto the same SVG;
 * Step 5 layers the elevation curve (a filled area under the curve
 * plus the open stroke on top) by reading the per-mapId `samples`
 * array from the Interactivity state slice and projecting through the
 * shared {@link ChartScale} helper.
 *
 * The frontend keeps the chart geometry in lock-step with the editor
 * preview by sharing the pure helpers under `./geometry/`. The host
 * differs (vanilla DOM here, React in `chart.tsx`) but the math is
 * identical, so the rendered output is byte-faithful across editor
 * and frontend for any given data + typography combination.
 *
 * Step 5 makes no store writes and does not yet read
 * `state[mapId].fraction`; Step 6 wires those in.
 *
 * @since 1.0.0
 */

import { getContext, getElement, store } from '@wordpress/interactivity';

import { buildFillPathD, buildStrokePathD } from './geometry/curve';
import {
	computeMargins,
	type Margins,
	type MarginsInput,
} from './geometry/margins';
import {
	createTextMeasurer,
	type TypographyAttributes,
} from './geometry/measure';
import { computeChartScale, type ChartScale } from './geometry/scale';

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
 * A single LTTB-downsampled `(distance, elevation)` sample, as emitted
 * by `Rendering\Elevation_Samples::compute()` server-side.
 *
 * @since 1.0.0
 */
type ElevationSample = readonly [ number, number ];

/**
 * Shape of the per-mapId state slice the Elevation module reads.
 *
 * Only the fields Step 3 / Step 4 / Step 5 care about are listed; the
 * rest of the slice (the Map block's geojson, fraction, etc.) is left
 * untyped at this surface because the elevation chart never reads it
 * directly.
 *
 * @since 1.0.0
 */
interface ElevationStateSlice {
	readonly statistics?: ElevationStatistics;
	readonly samples?: ReadonlyArray< ElevationSample >;
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
 * Narrows an unknown value to a finite number, or returns `null` for
 * anything else.
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
 * Removes every existing child element under `svg` whose class
 * matches the supplied selector. Used between redraws to wipe the
 * Step 3 axis lines, the Step 4 tick / label groups, and the Step 5
 * plot paths before inserting fresh ones — cheaper than diffing for
 * ≤ 20 elements.
 *
 * @since 1.0.0
 *
 * @param svg      SVG host.
 * @param selector CSS selector matching the elements to remove.
 */
function removeMatching( svg: SVGSVGElement, selector: string ): void {
	for ( const existing of Array.from( svg.querySelectorAll( selector ) ) ) {
		existing.remove();
	}
}

/**
 * Creates an SVG element with the supplied attributes set.
 *
 * @since 1.0.0
 *
 * @param tag        SVG tag name (`'line'`, `'text'`, `'g'`, …).
 * @param attributes Attribute key/value pairs.
 * @return The created element.
 */
function createSvg(
	tag: string,
	attributes: Record< string, string >
): SVGElement {
	const node = document.createElementNS( SVG_NS, tag );
	for ( const [ name, value ] of Object.entries( attributes ) ) {
		node.setAttribute( name, value );
	}
	return node;
}

/**
 * Appends the two axis lines to the SVG. Layer order: axis lines sit
 * below every other surface so a curve peak or a tick label can paint
 * over them.
 *
 * @since 1.0.0
 *
 * @param svg   SVG host.
 * @param scale The resolved chart scale.
 */
function appendAxes( svg: SVGSVGElement, scale: ChartScale ): void {
	const axisStroke = 'var(--kntnt-gpx-blocks-elevation-axis)';
	svg.appendChild(
		createSvg( 'line', {
			class: 'kntnt-gpx-blocks-elevation-axis-x',
			x1: String( scale.plotLeft ),
			y1: String( scale.plotBottom ),
			x2: String( scale.plotRight ),
			y2: String( scale.plotBottom ),
			stroke: axisStroke,
			'stroke-width': '1',
		} )
	);
	svg.appendChild(
		createSvg( 'line', {
			class: 'kntnt-gpx-blocks-elevation-axis-y',
			x1: String( scale.plotLeft ),
			y1: String( scale.plotBottom ),
			x2: String( scale.plotLeft ),
			y2: String( scale.plotTop ),
			stroke: axisStroke,
			'stroke-width': '1',
		} )
	);
}

/**
 * Appends the two `<path>` elements that draw the elevation curve —
 * a closed fill path under the curve and an open stroke path on top.
 *
 * Both are always emitted regardless of `plotFillColor`; the fill
 * path's visibility is governed by the resolved CSS variable
 * (`transparent` default; the user's colour engages it). Skips
 * emission entirely when `samples.length < 2` — the curve builders
 * return `''` and the SVG carries axes/ticks but no `<path>` markup.
 *
 * @since 1.0.0
 *
 * @param svg     SVG host.
 * @param scale   The resolved chart scale.
 * @param samples LTTB-downsampled (distance, elevation) pairs.
 */
function appendCurvePaths(
	svg: SVGSVGElement,
	scale: ChartScale,
	samples: ReadonlyArray< ElevationSample >
): void {
	if ( samples.length < 2 ) {
		return;
	}
	const fillD = buildFillPathD(
		samples,
		scale.projectX,
		scale.projectY,
		scale.plotBottom
	);
	const strokeD = buildStrokePathD( samples, scale.projectX, scale.projectY );
	svg.appendChild(
		createSvg( 'path', {
			class: 'kntnt-gpx-blocks-elevation-plot-fill',
			d: fillD,
			fill: 'var(--kntnt-gpx-blocks-elevation-plot-fill)',
			stroke: 'none',
		} )
	);
	svg.appendChild(
		createSvg( 'path', {
			class: 'kntnt-gpx-blocks-elevation-plot-line',
			d: strokeD,
			fill: 'none',
			stroke: 'var(--kntnt-gpx-blocks-elevation-plot-line)',
			'stroke-width': '2',
			'stroke-linejoin': 'round',
			'stroke-linecap': 'round',
			'vector-effect': 'non-scaling-stroke',
		} )
	);
}

/**
 * Appends the four tick groups (two tick-mark groups + two tick-label
 * groups) to the SVG. Layer order: tick scaffolding sits above the
 * curve so labels stay legible where the curve passes through them.
 *
 * @since 1.0.0
 *
 * @param svg   SVG host.
 * @param scale The resolved chart scale.
 */
function appendTicks( svg: SVGSVGElement, scale: ChartScale ): void {
	const tickMarkLength = 0.2 * scale.em;
	const labelOffset = 0.5 * scale.em;
	const axisStroke = 'var(--kntnt-gpx-blocks-elevation-axis)';
	const labelFill = 'var(--kntnt-gpx-blocks-elevation-axis-label)';

	const xMarks = createSvg( 'g', {
		class: 'kntnt-gpx-blocks-elevation-ticks-x',
	} );
	for ( const t of scale.xTicks ) {
		xMarks.appendChild(
			createSvg( 'line', {
				x1: String( t.position ),
				y1: String( scale.plotBottom ),
				x2: String( t.position ),
				y2: String( scale.plotBottom + tickMarkLength ),
				stroke: axisStroke,
				'stroke-width': '1',
			} )
		);
	}
	svg.appendChild( xMarks );

	const yMarks = createSvg( 'g', {
		class: 'kntnt-gpx-blocks-elevation-ticks-y',
	} );
	for ( const t of scale.yTicks ) {
		yMarks.appendChild(
			createSvg( 'line', {
				x1: String( scale.plotLeft ),
				y1: String( t.position ),
				x2: String( scale.plotLeft - tickMarkLength ),
				y2: String( t.position ),
				stroke: axisStroke,
				'stroke-width': '1',
			} )
		);
	}
	svg.appendChild( yMarks );

	const xLabels = createSvg( 'g', {
		class: 'kntnt-gpx-blocks-elevation-tick-labels-x',
		fill: labelFill,
	} );
	for ( const t of scale.xTicks ) {
		const text = createSvg( 'text', {
			x: String( t.position ),
			y: String( scale.plotBottom + labelOffset ),
			'text-anchor': 'middle',
			'dominant-baseline': 'hanging',
		} );
		text.textContent = t.label;
		xLabels.appendChild( text );
	}
	svg.appendChild( xLabels );

	const yLabels = createSvg( 'g', {
		class: 'kntnt-gpx-blocks-elevation-tick-labels-y',
		fill: labelFill,
	} );
	for ( const t of scale.yTicks ) {
		const text = createSvg( 'text', {
			x: String( scale.plotLeft - labelOffset ),
			y: String( t.position ),
			'text-anchor': 'end',
			'dominant-baseline': 'central',
		} );
		text.textContent = t.label;
		yLabels.appendChild( text );
	}
	svg.appendChild( yLabels );
}

/**
 * Replaces the chart's axis lines, curve paths, tick mark groups, and
 * tick label groups inside the SVG.
 *
 * The viewBox is resynced on every redraw because the SVG's rendered
 * size and its user-unit space share a 1:1 mapping. Layer order
 * matches the Step 5 spec: axes → fill → stroke → tick marks → tick
 * labels.
 *
 * @since 1.0.0
 *
 * @param svg     The SVG host.
 * @param w       Current rendered width in CSS pixels.
 * @param h       Current rendered height in CSS pixels.
 * @param data    Chart input data (elevation range + distance).
 * @param samples LTTB-downsampled (distance, elevation) pairs.
 * @param margins Resolved margin scalars from the margin algorithm.
 */
function drawChart(
	svg: SVGSVGElement,
	w: number,
	h: number,
	data: MarginsInput,
	samples: ReadonlyArray< ElevationSample >,
	margins: Margins
): void {
	svg.setAttribute( 'viewBox', `0 0 ${ w } ${ h }` );

	// Wipe any previous run's elements. Selecting on the documented
	// class names keeps us from clobbering future cursor / tooltip
	// surfaces that share the same SVG host.
	removeMatching(
		svg,
		[
			'.kntnt-gpx-blocks-elevation-axis-x',
			'.kntnt-gpx-blocks-elevation-axis-y',
			'.kntnt-gpx-blocks-elevation-plot-fill',
			'.kntnt-gpx-blocks-elevation-plot-line',
			'.kntnt-gpx-blocks-elevation-ticks-x',
			'.kntnt-gpx-blocks-elevation-ticks-y',
			'.kntnt-gpx-blocks-elevation-tick-labels-x',
			'.kntnt-gpx-blocks-elevation-tick-labels-y',
		].join( ',' )
	);

	const scale = computeChartScale( {
		distance: data.distance,
		minElevation: data.minElevation,
		maxElevation: data.maxElevation,
		margins,
		width: w,
		height: h,
	} );

	if ( scale.xTicks.length === 0 ) {
		return;
	}

	appendAxes( svg, scale );
	appendCurvePaths( svg, scale, samples );
	appendTicks( svg, scale );
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

/**
 * Coerces an unknown value into the `(distance, elevation)` samples
 * array `Elevation_Samples` emits. Drops malformed entries silently.
 *
 * @since 1.0.0
 *
 * @param value Candidate value from the state slice.
 * @return The validated array (possibly empty).
 */
function readSamples( value: unknown ): ReadonlyArray< ElevationSample > {
	if ( ! Array.isArray( value ) ) {
		return [];
	}
	const result: ElevationSample[] = [];
	for ( const entry of value ) {
		if (
			Array.isArray( entry ) &&
			entry.length >= 2 &&
			typeof entry[ 0 ] === 'number' &&
			typeof entry[ 1 ] === 'number' &&
			Number.isFinite( entry[ 0 ] ) &&
			Number.isFinite( entry[ 1 ] )
		) {
			result.push( [ entry[ 0 ], entry[ 1 ] ] );
		}
	}
	return result;
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
		 * tick redraw without recomputing margins.
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
			const samples = readSamples( slice?.samples );

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
			// `--kntnt-gpx-blocks-elevation-axis` /
			// `--kntnt-gpx-blocks-elevation-axis-label` /
			// `--kntnt-gpx-blocks-elevation-plot-*` custom properties
			// and any inline typography the SCSS rule converts into the
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
				drawChart( svg, w, h, data, samples, margins );
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

			// ResizeObserver redraws on container size change without
			// invalidating margins.
			if ( typeof ResizeObserver !== 'undefined' ) {
				const ro = new ResizeObserver( redraw );
				ro.observe( svg );
			}
		},
	},
} );
