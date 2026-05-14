/**
 * Chart scale helper for the elevation chart.
 *
 * Owns the projection + tick-generation logic shared by both hosts of
 * the chart. Both hosts (React in the editor, vanilla DOM under the
 * Interactivity API on the frontend) consume the same {@link ChartScale}
 * to draw axes, tick marks, tick labels, the elevation curve, the
 * cursor, and the tooltip.
 *
 * The helper is pure: no DOM, no React state. Its inputs are the chart's
 * data + cached margins + current rendered SVG dimensions; its outputs
 * are projection functions plus the X and Y tick sets in SVG user units.
 *
 * Sentinel branch: when the chart's plot rectangle would be degenerate
 * (`availX <= 0` or `availY <= 0`), the helper returns empty tick sets
 * and `NaN`-returning projection functions. Callers detect that branch
 * by inspecting `xTicks.length === 0` and skip all drawing — the
 * `chart.tsx` / `view.ts` redraw paths already test `w === 0 || h === 0`
 * before invoking this helper, so the sentinel branch is hit only on
 * truly impossible margin/wrapper combinations.
 *
 * Case-B inflation (`minElevation === maxElevation`) is owned by this
 * module: when the bound track is flat, the Y range expands to
 * `[min − 1, min + 1]` so the tick generator still emits a usable label
 * set centred on the constant value.
 *
 * @since 1.0.0
 */

import { formatXLabels, formatYLabels } from './format';
import type { Margins } from './margins';
import { computeTickCount, niceTicks } from './ticks';

/**
 * One projected tick — the SVG-space coordinate plus the formatted
 * label. Built once per redraw by {@link computeChartScale} and
 * consumed verbatim by both hosts.
 *
 * @since 1.0.0
 */
export interface ProjectedTick {
	readonly position: number;
	readonly label: string;
}

/**
 * Input to {@link computeChartScale}. Bundles the data, the cached
 * margins, and the current rendered SVG dimensions into a single
 * parameter — the helper is called from two hosts and a struct
 * argument keeps the call sites stable as the surface evolves.
 *
 * @since 1.0.0
 */
export interface ChartScaleInput {
	readonly distance: number;
	readonly minElevation: number;
	readonly maxElevation: number;
	readonly margins: Margins;
	readonly width: number;
	readonly height: number;
}

/**
 * The resolved chart scale. Carries the plot rectangle, the projection
 * functions, the (possibly Case-B-inflated) Y range, and the X / Y tick
 * sets in SVG user units.
 *
 * @since 1.0.0
 */
export interface ChartScale {
	readonly distance: number;
	readonly niceYMin: number;
	readonly niceYMax: number;
	readonly plotLeft: number;
	readonly plotRight: number;
	readonly plotTop: number;
	readonly plotBottom: number;
	readonly availX: number;
	readonly availY: number;
	readonly em: number;
	readonly projectX: ( distance: number ) => number;
	readonly projectY: ( elevation: number ) => number;
	readonly xTicks: readonly ProjectedTick[];
	readonly yTicks: readonly ProjectedTick[];
}

/**
 * Builds the sentinel `ChartScale` for the degenerate branch.
 *
 * Returned verbatim from {@link computeChartScale} when either available
 * extent is non-positive. Callers detect this by `xTicks.length === 0`
 * and skip all drawing.
 *
 * @since 1.0.0
 *
 * @param input The original input, used to populate the few fields the
 *              sentinel still has meaningful values for (the plot
 *              rectangle scalars).
 * @return The empty-tick sentinel.
 */
function sentinel( input: ChartScaleInput ): ChartScale {
	const { margins, width, height } = input;
	return {
		distance: input.distance,
		niceYMin: input.minElevation,
		niceYMax: input.maxElevation,
		plotLeft: margins.wLeft,
		plotRight: width - margins.wRight,
		plotTop: margins.wTop,
		plotBottom: height - margins.h,
		availX: width - margins.wLeft - margins.wRight,
		availY: height - margins.wTop - margins.h,
		em: margins.em,
		projectX: () => Number.NaN,
		projectY: () => Number.NaN,
		xTicks: [],
		yTicks: [],
	};
}

/**
 * Computes the chart's scale for one redraw.
 *
 * Performs Step 4's full redraw geometry in a single pass: derives the
 * plot rectangle, generates X and Y tick sets via `niceTicks`, projects
 * tick positions, and bundles `projectX` / `projectY` arrow functions
 * for the curve / cursor / tooltip surfaces to consume.
 *
 * The Step 3 Case-B substitution lives inside this function — callers
 * pass raw `minElevation` / `maxElevation` and never recompose the
 * inflation themselves.
 *
 * Returns a sentinel `ChartScale` (empty tick sets, NaN projections)
 * when either available extent is non-positive.
 *
 * @since 1.0.0
 *
 * @param input See {@link ChartScaleInput}.
 * @return The resolved {@link ChartScale}.
 */
export function computeChartScale( input: ChartScaleInput ): ChartScale {
	const { distance, minElevation, maxElevation, margins, width, height } =
		input;

	const plotLeft = margins.wLeft;
	const plotRight = width - margins.wRight;
	const plotTop = margins.wTop;
	const plotBottom = height - margins.h;
	const availX = plotRight - plotLeft;
	const availY = plotBottom - plotTop;
	const em = margins.em;

	if ( availX <= 0 || availY <= 0 ) {
		return sentinel( input );
	}

	// Apply the Step 3 Case-B substitution: a flat track inflates by
	// ±1 m so `niceTicks` still emits a renderable label set.
	const flatY = minElevation === maxElevation;
	const yMin = flatY ? minElevation - 1 : minElevation;
	const yMax = flatY ? maxElevation + 1 : maxElevation;

	// Derive the worst-case reference sizes from the margin scalars.
	// `wRight = refXWidth / 2 + 0.5em` and `h = refHeight + 0.5em` by
	// construction in `computeMargins()` — invert both to recover the
	// reference dimensions the tick-count helper expects.
	const halfEm = 0.5 * em;
	const refXWidth = 2 * ( margins.wRight - halfEm );
	const refHeight = margins.h - halfEm;

	// X-tick generation. Build a nice tick set over `[0, distance]`,
	// filter to Strava-style `value ≤ distance`, format with the
	// distance-driven unit choice, and project to SVG x-coordinates.
	const nx = computeTickCount( availX, refXWidth, em );
	const xRaw = niceTicks( 0, distance, nx );
	const xValues = xRaw.values.filter( ( v ) => v <= distance );
	const xLabels = formatXLabels( xValues, xRaw.step, distance );
	const xTicks: ProjectedTick[] = xValues.map( ( v, i ) => ( {
		position: plotLeft + ( v / distance ) * availX,
		label: xLabels[ i ] as string,
	} ) );

	// Y-tick generation. Build a nice tick set over the (possibly
	// inflated) Y range, then derive the rendering range from the
	// generated values' first/last entries so the lowest tick lands on
	// the X-axis line and the highest on `y = plotTop`.
	const ny = computeTickCount( availY, refHeight, em );
	const yRaw = niceTicks( yMin, yMax, ny );
	const yLabels = formatYLabels( yRaw.values, yRaw.step );
	const firstY = yRaw.values[ 0 ] ?? yMin;
	const lastY = yRaw.values[ yRaw.values.length - 1 ] ?? yMax;
	const niceYMin = firstY;
	const niceYMax = lastY;
	const ySpan = niceYMax - niceYMin;

	// Build the projection functions. `projectY`'s `span === 0` guard
	// keeps a degenerate niceTicks output from emitting `NaN`
	// coordinates — exceedingly rare after Case-B inflation, but a
	// belt-and-braces measure for the unit-test surface.
	const projectX = ( d: number ): number =>
		plotLeft + ( d / distance ) * availX;
	const projectY =
		ySpan <= 0
			? (): number => plotBottom
			: ( e: number ): number =>
					plotBottom - ( ( e - niceYMin ) / ySpan ) * availY;

	const yTicks: ProjectedTick[] = yRaw.values.map( ( v, i ) => ( {
		position: projectY( v ),
		label: yLabels[ i ] as string,
	} ) );

	return {
		distance,
		niceYMin,
		niceYMax,
		plotLeft,
		plotRight,
		plotTop,
		plotBottom,
		availX,
		availY,
		em,
		projectX,
		projectY,
		xTicks,
		yTicks,
	};
}
