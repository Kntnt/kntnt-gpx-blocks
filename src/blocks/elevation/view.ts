/**
 * GPX Elevation frontend Interactivity API module.
 *
 * Registers the `kntnt-gpx-blocks` store's `initElevation` callback and
 * mounts the chart's SVG into the block wrapper. Step 3 ships the two
 * axis lines; Step 4 layers tick marks and labels onto the same SVG.
 * Step 5 will add the elevation curve, Step 6 the cursor with cross-
 * block sync, Step 7 the tooltip.
 *
 * The frontend keeps the chart geometry in lock-step with the editor
 * preview by sharing the pure helpers under `./geometry/`. The host
 * differs (vanilla DOM here, React in `chart.tsx`) but the math is
 * identical, so the rendered output is byte-faithful across editor
 * and frontend for any given data + typography combination.
 *
 * Step 4 makes no store writes and does not yet read
 * `state[mapId].fraction`; Step 6 wires those in.
 *
 * @since 1.0.0
 */

import { getContext, getElement, store } from '@wordpress/interactivity';

import { formatXLabels, formatYLabels } from './geometry/format';
import {
	computeMargins,
	type Margins,
	type MarginsInput,
} from './geometry/margins';
import {
	createTextMeasurer,
	type TypographyAttributes,
} from './geometry/measure';
import { computeTickCount, niceTicks } from './geometry/ticks';

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
 * Only the fields Step 3 / Step 4 cares about are listed; the rest of
 * the slice (the Map block's geojson, fraction, etc.) is left untyped
 * at this surface because the elevation chart never reads it directly.
 *
 * @since 1.0.0
 */
interface ElevationStateSlice {
	readonly statistics?: ElevationStatistics;
}

/**
 * One projected tick — SVG-space coordinate plus formatted label.
 *
 * @since 1.0.0
 */
interface ProjectedTick {
	readonly position: number;
	readonly label: string;
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
 * Builds the projected X-axis tick set for the redraw.
 *
 * Generates a nice tick set over `[0, distance]`, filters to
 * `value ≤ distance` (Strava-style bounds), formats with the m/km
 * unit chosen for the distance, and projects each value to its SVG
 * x-coordinate.
 *
 * @since 1.0.0
 *
 * @param distance Track distance in metres.
 * @param availX   Plot width in user units.
 * @param refWidth Width of the worst-case X reference label.
 * @param em       Resolved Tick-labels font-size in pixels.
 * @param wLeft    Left margin in user units.
 * @return Projected ticks in ascending order.
 */
function buildXTicks(
	distance: number,
	availX: number,
	refWidth: number,
	em: number,
	wLeft: number
): readonly ProjectedTick[] {
	const n = computeTickCount( availX, refWidth, em );
	const raw = niceTicks( 0, distance, n );
	const values = raw.values.filter( ( v ) => v <= distance );
	const labels = formatXLabels( values, raw.step, distance );
	return values.map( ( v, i ) => ( {
		position: wLeft + ( v / distance ) * availX,
		label: labels[ i ] as string,
	} ) );
}

/**
 * Builds the projected Y-axis tick set for the redraw.
 *
 * @since 1.0.0
 *
 * @param yMin      Inflated-where-needed Y range minimum.
 * @param yMax      Inflated-where-needed Y range maximum.
 * @param availY    Plot height in user units.
 * @param refHeight Height of the reference label.
 * @param em        Resolved Tick-labels font-size in pixels.
 * @param wTop      Top margin in user units.
 * @param hBottom   Bottom margin in user units.
 * @param H         SVG rendered height in user units.
 * @return Projected ticks ordered from low (bottom) to high (top).
 */
function buildYTicks(
	yMin: number,
	yMax: number,
	availY: number,
	refHeight: number,
	em: number,
	wTop: number,
	hBottom: number,
	H: number
): readonly ProjectedTick[] {
	const n = computeTickCount( availY, refHeight, em );
	const raw = niceTicks( yMin, yMax, n );
	const labels = formatYLabels( raw.values, raw.step );
	const firstValue = raw.values[ 0 ] ?? yMin;
	const lastValue = raw.values[ raw.values.length - 1 ] ?? yMax;
	const span = lastValue - firstValue;
	const bottom = H - hBottom;

	if ( span <= 0 ) {
		return raw.values.map( ( _, i ) => ( {
			position: bottom,
			label: labels[ i ] as string,
		} ) );
	}

	// Suppress unused parameter warnings — wTop participates in span
	// computation through the caller's availY = H - wTop - h.
	void wTop;

	return raw.values.map( ( v, i ) => ( {
		position: bottom - ( ( v - firstValue ) / span ) * availY,
		label: labels[ i ] as string,
	} ) );
}

/**
 * Removes every existing child element under `svg` whose class
 * matches the supplied selector. Used between redraws to wipe the
 * Step 3 axis lines and the Step 4 tick / label groups before
 * inserting fresh ones — cheaper than diffing for ≤ 20 elements.
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
 * Replaces the chart's axis lines, tick mark groups, and tick label
 * groups inside the SVG.
 *
 * The viewBox is resynced on every redraw because the SVG's rendered
 * size and its user-unit space share a 1:1 mapping.
 *
 * @since 1.0.0
 *
 * @param svg     The SVG host.
 * @param w       Current rendered width in CSS pixels.
 * @param h       Current rendered height in CSS pixels.
 * @param data    Chart input data (elevation range + distance).
 * @param margins Resolved margin scalars from the margin algorithm.
 */
function drawChart(
	svg: SVGSVGElement,
	w: number,
	h: number,
	data: MarginsInput,
	margins: Margins
): void {
	svg.setAttribute( 'viewBox', `0 0 ${ w } ${ h }` );

	// Wipe any previous run's elements. Selecting on the documented
	// class names keeps us from clobbering future curve / cursor
	// surfaces that share the same SVG host.
	removeMatching(
		svg,
		[
			'.kntnt-gpx-blocks-elevation-axis-x',
			'.kntnt-gpx-blocks-elevation-axis-y',
			'.kntnt-gpx-blocks-elevation-ticks-x',
			'.kntnt-gpx-blocks-elevation-ticks-y',
			'.kntnt-gpx-blocks-elevation-tick-labels-x',
			'.kntnt-gpx-blocks-elevation-tick-labels-y',
		].join( ',' )
	);

	const em = margins.em;
	const halfEm = 0.5 * em;
	const refXWidth = 2 * ( margins.wRight - halfEm );
	const refHeight = margins.h - halfEm;
	const availX = w - margins.wLeft - margins.wRight;
	const availY = h - margins.wTop - margins.h;

	if ( availX <= 0 || availY <= 0 ) {
		return;
	}

	const plotLeft = margins.wLeft;
	const plotRight = w - margins.wRight;
	const plotTop = margins.wTop;
	const plotBottom = h - margins.h;
	const tickMarkLength = 0.2 * em;
	const labelOffset = halfEm;

	const axisStroke = 'var(--kntnt-gpx-blocks-elevation-axis)';
	const labelFill = 'var(--kntnt-gpx-blocks-elevation-axis-label)';

	// Two axis lines anchored to the plot rectangle's bottom-left
	// corner (plotLeft, plotBottom).
	svg.appendChild(
		createSvg( 'line', {
			class: 'kntnt-gpx-blocks-elevation-axis-x',
			x1: String( plotLeft ),
			y1: String( plotBottom ),
			x2: String( plotRight ),
			y2: String( plotBottom ),
			stroke: axisStroke,
			'stroke-width': '1',
		} )
	);
	svg.appendChild(
		createSvg( 'line', {
			class: 'kntnt-gpx-blocks-elevation-axis-y',
			x1: String( plotLeft ),
			y1: String( plotBottom ),
			x2: String( plotLeft ),
			y2: String( plotTop ),
			stroke: axisStroke,
			'stroke-width': '1',
		} )
	);

	// Build the Step 4 tick sets. Y range honours the Step 3 Case-B
	// inflation around a flat track so niceTicks emits a usable set.
	const flatY = data.minElevation === data.maxElevation;
	const yMin = flatY ? data.minElevation - 1 : data.minElevation;
	const yMax = flatY ? data.maxElevation + 1 : data.maxElevation;
	const xTicks = buildXTicks(
		data.distance,
		availX,
		refXWidth,
		em,
		plotLeft
	);
	const yTicks = buildYTicks(
		yMin,
		yMax,
		availY,
		refHeight,
		em,
		plotTop,
		margins.h,
		h
	);

	// X tick marks (downward from the X axis).
	const xMarks = createSvg( 'g', {
		class: 'kntnt-gpx-blocks-elevation-ticks-x',
	} );
	for ( const t of xTicks ) {
		xMarks.appendChild(
			createSvg( 'line', {
				x1: String( t.position ),
				y1: String( plotBottom ),
				x2: String( t.position ),
				y2: String( plotBottom + tickMarkLength ),
				stroke: axisStroke,
				'stroke-width': '1',
			} )
		);
	}
	svg.appendChild( xMarks );

	// Y tick marks (leftward from the Y axis).
	const yMarks = createSvg( 'g', {
		class: 'kntnt-gpx-blocks-elevation-ticks-y',
	} );
	for ( const t of yTicks ) {
		yMarks.appendChild(
			createSvg( 'line', {
				x1: String( plotLeft ),
				y1: String( t.position ),
				x2: String( plotLeft - tickMarkLength ),
				y2: String( t.position ),
				stroke: axisStroke,
				'stroke-width': '1',
			} )
		);
	}
	svg.appendChild( yMarks );

	// X tick labels (centred under the X axis with `hanging` baseline).
	const xLabels = createSvg( 'g', {
		class: 'kntnt-gpx-blocks-elevation-tick-labels-x',
		fill: labelFill,
	} );
	for ( const t of xTicks ) {
		const text = createSvg( 'text', {
			x: String( t.position ),
			y: String( plotBottom + labelOffset ),
			'text-anchor': 'middle',
			'dominant-baseline': 'hanging',
		} );
		text.textContent = t.label;
		xLabels.appendChild( text );
	}
	svg.appendChild( xLabels );

	// Y tick labels (right-anchored at the Y axis with `central`
	// baseline so the visible glyph centres on the tick mark).
	const yLabels = createSvg( 'g', {
		class: 'kntnt-gpx-blocks-elevation-tick-labels-y',
		fill: labelFill,
	} );
	for ( const t of yTicks ) {
		const text = createSvg( 'text', {
			x: String( plotLeft - labelOffset ),
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
			// `--kntnt-gpx-blocks-elevation-axis-label` custom properties
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
				drawChart( svg, w, h, data, margins );
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
