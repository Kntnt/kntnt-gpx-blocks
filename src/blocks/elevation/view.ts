/**
 * GPX Elevation frontend Interactivity API module.
 *
 * Registers the `kntnt-gpx-blocks` store's `initElevation` callback and
 * mounts the chart's SVG into the block wrapper. Step 3 ships the two
 * axis lines; Step 4 layers tick marks and labels onto the same SVG;
 * Step 5 layers the elevation curve (a filled area under the curve
 * plus the open stroke on top) by reading the per-mapId `samples`
 * array from the Interactivity state slice and projecting through the
 * shared {@link ChartScale} helper. Step 6 layers the cursor: a circle
 * anchored to the curve plus an L-shape pair of guide lines pointing
 * at the corresponding x and y axis ticks. The cursor's geometry is
 * driven by `state[ mapId ].fraction`, which both Map and Elevation
 * read and write through their respective `data-wp-watch--cursor`
 * callbacks (cross-block sync).
 *
 * The frontend keeps the chart geometry in lock-step with the editor
 * preview by sharing the pure helpers under `./geometry/`. The host
 * differs (vanilla DOM here, React in `chart.tsx`) but the math is
 * identical, so the rendered output is byte-faithful across editor
 * and frontend for any given data + typography combination.
 *
 * @since 1.0.0
 */

import { getContext, getElement, store } from '@wordpress/interactivity';

import {
	applyCursorPosition,
	hideCursor,
	updateHitRect,
	type CursorElements,
} from './cursor';
import {
	buildCursorElementsForLifecycle,
	readCursorSettingsFromContext,
	type CursorContextShape,
} from './cursor-bootstrap';
import {
	bindPointerHandlers,
	bindPointerHandlersWhenVisible,
} from './cursor-input';
import { interpolateSample, projectCursor } from './geometry/cursor';
import { buildFillPathD, buildStrokePathD } from './geometry/curve';
import {
	computeMargins,
	type Margins,
	type MarginsInput,
} from './geometry/margins';
import { createTextMeasurer } from './geometry/measure';
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
 * Shape of the per-mapId state slice the Elevation module reads. The
 * slice is shared with the Map block: `Render_Map` writes `geojson`
 * (and other map-only fields plus `fraction: null`); `Render_Elevation`
 * writes `statistics` and `samples`. Both blocks read and write
 * `fraction` to drive cross-block cursor sync.
 *
 * @since 1.0.0
 */
interface ElevationStateSlice {
	statistics?: ElevationStatistics;
	samples?: ReadonlyArray< ElevationSample >;
	fraction?: number | null;
}

/**
 * Tracks which wrapper elements have already been mounted so a
 * double-init under the Interactivity API does not stack a second
 * SVG on top of the first. Per-element idempotency, not per-mapId,
 * because every Elevation block on the page is a separate wrapper.
 *
 * The slot is claimed synchronously at the start of `initElevation`,
 * before the `await document.fonts.ready`, so a second
 * Interactivity-mount trigger arriving mid-await still no-ops cleanly.
 * The companion {@link mountedElevations} WeakMap is populated only
 * after the first `drawChart` completes — see Step 6 *Lifecycle*.
 *
 * @since 1.0.0
 */
const mounted = new WeakSet< Element >();

/**
 * Per-wrapper lifecycle state for the Elevation chart.
 *
 * Held by {@link mountedElevations} so the Interactivity watch
 * callback can locate the right wrapper's geometry, scale, and cursor
 * elements without re-walking the DOM. Set only after the first
 * `drawChart` completes so the watch never observes an incomplete
 * entry; the watch's standard read-fraction-first-then-guard idiom
 * handles the race window between `mounted.add()` and `mountedElevations.set()`.
 *
 * `cursorElements` is `null` when the block's `showCursor` toggle
 * (issue #144) is off — the cursor lifecycle is skipped entirely in
 * that branch and the watch callback returns silently.
 *
 * @since 1.0.0
 */
interface ElevationEntry {
	readonly svg: SVGSVGElement;
	readonly wrapper: HTMLElement;
	readonly samples: ReadonlyArray< ElevationSample >;
	readonly distance: number;
	readonly mapId: string;
	scale: ChartScale;
	readonly cursorElements: CursorElements | null;
}

/**
 * Wrapper-keyed registry of fully-initialised elevation mounts. The
 * watch callback `onElevationCursorChange` reads from this; the
 * pointer-input layer's deferred binding callback also reads from it
 * to recover the cursor hit-rect once the chart enters the viewport.
 *
 * @since 1.0.0
 */
const mountedElevations = new WeakMap< Element, ElevationEntry >();

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
 * The cursor classes are deliberately absent from every call site:
 * cursor elements are persistent across redraws (created once on the
 * first `drawChart`, repositioned forever).
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
 * tick label groups inside the SVG, then returns the resolved scale
 * so the caller can position the cursor without recomputing.
 *
 * The viewBox is resynced on every redraw because the SVG's rendered
 * size and its user-unit space share a 1:1 mapping. Layer order
 * matches the Step 5 spec: axes → fill → stroke → tick marks → tick
 * labels. The cursor `<g>` is *not* removed here; it is persistent
 * across redraws and the caller updates its hit-rect plus the
 * dot/line positions through `./cursor.ts`.
 *
 * Returns `null` for the sentinel scale (degenerate plot rectangle);
 * the caller skips the cursor lifecycle in that branch too.
 *
 * @since 1.0.0
 *
 * @param svg     The SVG host.
 * @param w       Current rendered width in CSS pixels.
 * @param h       Current rendered height in CSS pixels.
 * @param data    Chart input data (elevation range + distance).
 * @param samples LTTB-downsampled (distance, elevation) pairs.
 * @param margins Resolved margin scalars from the margin algorithm.
 * @return The resolved scale, or `null` for the sentinel branch.
 */
function drawChart(
	svg: SVGSVGElement,
	w: number,
	h: number,
	data: MarginsInput,
	samples: ReadonlyArray< ElevationSample >,
	margins: Margins
): ChartScale | null {
	svg.setAttribute( 'viewBox', `0 0 ${ w } ${ h }` );

	// Wipe any previous run's elements. Selecting on the documented
	// class names keeps us from clobbering the persistent cursor `<g>`
	// (created once on the first redraw) and any future tooltip
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
		return null;
	}

	appendAxes( svg, scale );
	appendCurvePaths( svg, scale, samples );
	appendTicks( svg, scale );

	return scale;
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

/**
 * Synchronises the cursor with the current `state[ mapId ].fraction`
 * value through the entry's cached scale. Hides the cursor when the
 * fraction is null/undefined or when interpolation cannot produce a
 * sample (degenerate samples array).
 *
 * Invoked from `redraw` (so a fresh resize re-pins the cursor at the
 * new geometry) and from the watch callback (so a fraction write
 * elsewhere on the page moves the cursor).
 *
 * Returns silently when `entry.cursorElements` is `null` — the block's
 * `showCursor` toggle (issue #144) is off and there is no cursor `<g>`
 * to drive. This branch is reached during a watch fire on a
 * cursor-disabled Elevation block when a sibling Map block writes the
 * shared fraction.
 *
 * @since 1.0.0
 *
 * @param entry    The wrapper's lifecycle state.
 * @param fraction The fraction to project, or `null` to hide.
 */
function syncCursor(
	entry: ElevationEntry,
	fraction: number | null | undefined
): void {
	if ( ! entry.cursorElements ) {
		return;
	}
	if ( fraction === null || fraction === undefined ) {
		hideCursor( entry.cursorElements );
		return;
	}
	const sample = interpolateSample(
		entry.samples,
		fraction * entry.distance
	);
	if ( ! sample ) {
		hideCursor( entry.cursorElements );
		return;
	}
	const projected = projectCursor( sample, entry.scale );
	applyCursorPosition( entry.cursorElements, projected, entry.scale );
}

/**
 * Reads the `kntnt-gpx-blocks` Interactivity state. The `state`
 * surface is a mutable record keyed by `mapId`; both reads and writes
 * pass through this helper so the cast is centralised.
 *
 * @since 1.0.0
 *
 * @return The state record.
 */
function getStateRecord(): Record< string, ElevationStateSlice | undefined > {
	const handle = store( 'kntnt-gpx-blocks' ) as unknown as {
		readonly state: Record< string, ElevationStateSlice | undefined >;
	};
	return handle.state;
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
		 * Step 6 layers the cursor lifecycle onto the existing mount
		 * pipeline: cursor elements are created once during the first
		 * `drawChart`, then repositioned forever; pointer handlers
		 * attach when the chart approaches the viewport; the watch
		 * callback `onElevationCursorChange` propagates fraction
		 * writes from the shared store back to the cursor's geometry.
		 *
		 * @since 1.0.0
		 */
		async initElevation(): Promise< void > {
			// Locate the wrapper element and guard against double-init.
			// The synchronous `mounted.add()` claim happens before the
			// first `await` so a second Interactivity-mount trigger
			// arriving mid-await still no-ops cleanly.
			const element = getElement();
			const ref = element?.ref;
			if ( ! ref || ! ( ref instanceof Element ) ) {
				return;
			}
			if ( mounted.has( ref ) ) {
				return;
			}
			mounted.add( ref );

			// Read the state slice for this block's `mapId` plus the
			// three Cursor & guides toggles (issue #144). The toggles
			// flow through the per-block Interactivity context so two
			// Elevation blocks bound to the same Map can disagree about
			// cursor visibility. Skip silently when the slice or the
			// statistics are missing — PHP's warning path has already
			// handled those branches.
			const ctx = getContext<
				{
					readonly mapId?: string;
				} & CursorContextShape
			>();
			const mapId = typeof ctx?.mapId === 'string' ? ctx.mapId : '';
			if ( mapId === '' ) {
				return;
			}
			const cursorSettings = readCursorSettingsFromContext( ctx );
			const { showCursor } = cursorSettings;
			const stateRecord = getStateRecord();
			const slice = stateRecord[ mapId ];
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

			// Mount the SVG host. The wrapper carries the colour
			// custom properties (axis / axis-label / plot-* / cursor)
			// and the eight tick-label typography custom properties;
			// SCSS converts the latter into font-* declarations on the
			// SVG. The measurer's hidden <text> nodes (direct SVG
			// children) and the visible tick <text> labels (under
			// the `<g class="…-tick-labels-x/y">` groups) inherit
			// those declarations through the standard CSS chain.
			const svg = document.createElementNS(
				SVG_NS,
				'svg'
			) as SVGSVGElement;
			svg.setAttribute( 'class', 'kntnt-gpx-blocks-elevation-chart-svg' );
			svg.setAttribute( 'width', '100%' );
			svg.setAttribute( 'height', '100%' );
			ref.appendChild( svg );

			// Compute margins once. ResizeObserver does not invalidate
			// them — margins depend only on data and on the chart
			// SVG's resolved typography, neither of which a wrapper-
			// size change implies. The measurer reads the typography
			// through CSS inheritance from the SVG host (whose font-*
			// declarations come from the wrapper's tick-label custom
			// properties emitted by Render_Elevation server-side), so
			// no typography bundle has to be threaded through the API.
			const measure = createTextMeasurer( svg );
			let margins = computeMargins( data, measure );

			const wrapper = ref as HTMLElement;
			let entry: ElevationEntry | null = null;

			const redraw = (): void => {
				const { w, h } = readSize( svg );
				if ( w === 0 || h === 0 ) {
					return;
				}
				const scale = drawChart( svg, w, h, data, samples, margins );
				if ( ! scale ) {
					return;
				}

				// Cursor lifecycle. The first successful redraw creates
				// the cursor `<g>` plus its children and publishes the
				// entry to `mountedElevations`. Subsequent redraws only
				// update the hit-rect to track the new plot rectangle
				// and re-pin the cursor at the cached fraction so a
				// resize keeps the cursor anchored. When the block's
				// `showCursor` toggle (issue #144) is off, the cursor
				// `<g>` is not created at all — the entry's
				// `cursorElements` stays `null`, `syncCursor` no-ops,
				// and the pointer handlers are never bound. The
				// per-guide toggles further gate each guide's `<line>`
				// inside `createCursorElements`.
				if ( ! entry ) {
					const cursorElements = buildCursorElementsForLifecycle(
						cursorSettings,
						svg,
						scale
					);
					entry = {
						svg,
						wrapper,
						samples,
						distance: data.distance,
						mapId,
						scale,
						cursorElements,
					};
					mountedElevations.set( ref, entry );
				} else {
					if ( entry.cursorElements ) {
						updateHitRect( entry.cursorElements, scale );
					}
					entry.scale = scale;
				}

				// Re-pin the cursor at the current fraction; null hides.
				// `syncCursor` returns silently when the cursor was gated
				// off, so the lookup itself is harmless.
				const currentFraction = getStateRecord()[ mapId ]?.fraction;
				syncCursor( entry, currentFraction );
			};

			redraw();

			// Re-measure when late-loaded fonts replace the fallback
			// metrics. The frontend has no other event that can
			// change measurement results in place — block attributes
			// are baked into the server-rendered HTML at request time.
			if ( typeof document !== 'undefined' && document.fonts ) {
				document.fonts.addEventListener( 'loadingdone', () => {
					margins = computeMargins( data, measure );
					redraw();
				} );
			}

			// ResizeObserver redraws on container size change without
			// invalidating margins.
			if ( typeof ResizeObserver !== 'undefined' ) {
				const ro = new ResizeObserver( redraw );
				ro.observe( svg );
			}

			// Pointer-input layer. Defer binding until the chart
			// approaches the viewport so the listeners attach only
			// when the user is about to interact with the chart.
			// Cursor sync (the watch above) is *not* gated by the
			// observer — it fires as soon as the entry is published.
			//
			// Skipped entirely when the block's `showCursor` toggle
			// (issue #144) is off: with no cursor `<g>` there is no
			// hit-rect to listen on, and the block must not write the
			// shared fraction either — that responsibility belongs to
			// the Map block alone in that configuration.
			if ( showCursor ) {
				bindPointerHandlersWhenVisible( ref, () => {
					const published = mountedElevations.get( ref );
					if ( ! published?.cursorElements ) {
						return;
					}
					bindPointerHandlers(
						published.cursorElements.hitRect,
						wrapper,
						{
							setFraction( value: number | null ): void {
								const liveSlice = getStateRecord()[ mapId ];
								if ( liveSlice ) {
									liveSlice.fraction = value;
								}
							},
						}
					);
				} );
			}
		},

		/**
		 * Reacts to changes in `state[ mapId ].fraction` and moves the
		 * cursor accordingly.
		 *
		 * The fraction is read at the very top of the function, before
		 * any guard, so the Interactivity API's signal-tracking
		 * establishes the subscription on the very first watch run.
		 * `mountedElevations` is populated only after the first
		 * `drawChart` completes, so this watch fires at least once
		 * before the entry is available — returning silently in that
		 * branch is correct because `redraw` itself calls
		 * {@link syncCursor} at the end of its first run, which renders
		 * the cursor at the published fraction.
		 *
		 * Named per block (rather than the generic `onCursorChange`) so
		 * registering both Map and Elevation modules into the same
		 * `kntnt-gpx-blocks` store does not overwrite each other's
		 * callbacks. The Map block's mirror is `onMapCursorChange`.
		 *
		 * @since 1.0.0
		 */
		onElevationCursorChange(): void {
			const ctx = getContext<
				{
					readonly mapId?: string;
				} & CursorContextShape
			>();
			const mapId = typeof ctx?.mapId === 'string' ? ctx.mapId : '';
			if ( mapId === '' ) {
				return;
			}

			// Functional-disablement guard (issue #144). When the block's
			// `showCursor` toggle is off there is no cursor `<g>` to drive
			// — return silently. Reading the fraction first to register
			// the subscription would be wasted bookkeeping (it can never
			// drive a write here), so the guard sits at the top.
			const { showCursor } = readCursorSettingsFromContext( ctx );
			if ( ! showCursor ) {
				return;
			}

			const fraction = getStateRecord()[ mapId ]?.fraction;
			const ref = getElement()?.ref;
			if ( ! ref || ! ( ref instanceof Element ) ) {
				return;
			}
			const entry = mountedElevations.get( ref );
			if ( ! entry ) {
				return;
			}
			syncCursor( entry, fraction );
		},
	},
} );
