/**
 * Pure geometry helpers for the GPX Elevation cursor sync.
 *
 * Imported by `view.ts` for production use and by `geometry.test.ts` for
 * Jest coverage. The helpers map a fraction-of-total-distance to a
 * `(distance, elevation)` sample interpolated between adjacent LTTB
 * downsampled points, then to an SVG `(cx, cy)` pair using the padded
 * `[yMin, yMax]` bounds PHP also rendered the polyline with.
 *
 * The two cross-block primitives — `lowerBoundIndex` and `clamp01` — live in
 * `../shared/geometry.ts` and are imported here. See `docs/architecture.md`
 * for the data-flow context.
 *
 * @since 0.2.0
 */

import { clamp01, lowerBoundIndex } from '../shared/geometry';

/**
 * A `(distance, elevation)` pair as emitted by `Render_Elevation` in metres.
 *
 * @since 0.2.0
 */
export type DistanceElevation = readonly [ number, number ];

/**
 * Plot rectangle inside the SVG viewBox in logical units. Matches the
 * `MARGIN_*` constants in `Render_Elevation.php` and the `data-plot-*`
 * attributes on the cursor group.
 *
 * @since 0.2.0
 */
export interface ChartBounds {
	readonly left: number;
	readonly right: number;
	readonly top: number;
	readonly bottom: number;
}

/**
 * Linearly interpolate a `(distance, elevation)` sample at the given
 * fraction-of-total-distance.
 *
 * Binary-searches the LTTB downsampled distance array, picks the adjacent
 * sample pair, and lerps both components. The first and last points of the
 * series are always preserved by LTTB, so `fraction = 0` returns the start
 * and `fraction = 1` returns the end exactly.
 *
 * Edge cases:
 *
 * - Empty input returns `null`.
 * - Single-point input returns that point regardless of fraction.
 * - A zero-length distance segment (consecutive samples coincide on x)
 *   collapses to the start sample without dividing by zero.
 *
 * @since 0.2.0
 *
 * @param series        - LTTB downsampled `(distance, elevation)` pairs.
 * @param totalDistance - Total track distance in metres.
 * @param fraction      - Fraction of total distance in `[0, 1]`.
 * @return Interpolated sample or `null` for empty input.
 */
export function interpolateSample(
	series: readonly DistanceElevation[],
	totalDistance: number,
	fraction: number
): DistanceElevation | null {
	if ( series.length === 0 ) {
		return null;
	}
	if ( series.length === 1 ) {
		return series[ 0 ] ?? null;
	}

	const target = clamp01( fraction ) * totalDistance;
	const distances = series.map( ( s ) => s[ 0 ] );
	const i = lowerBoundIndex( distances, target );
	const j = Math.min( i + 1, series.length - 1 );
	const a = series[ i ] as DistanceElevation;
	const b = series[ j ] as DistanceElevation;
	const span = b[ 0 ] - a[ 0 ];
	const t = span > 0 ? ( target - a[ 0 ] ) / span : 0;
	return [
		a[ 0 ] + ( b[ 0 ] - a[ 0 ] ) * t,
		a[ 1 ] + ( b[ 1 ] - a[ 1 ] ) * t,
	];
}

/**
 * Project a `(distance, elevation)` sample into SVG-space `(cx, cy)`.
 *
 * Distance maps linearly into the chart's horizontal range; elevation maps
 * into the vertical range with the SVG y-axis growing downwards, hence the
 * `bottom - ratio * height` form. The padded `[yMin, yMax]` bounds are the
 * exact ones the PHP renderer used to draw the polyline, so the cursor sits
 * on the rendered curve rather than on the raw LTTB min/max range.
 *
 * Edge cases:
 *
 * - Zero or negative `totalDistance` snaps `cx` to the chart's left edge.
 * - A zero-span y range (`yMax <= yMin`) snaps `cy` to the chart's bottom
 *   edge, where a flat polyline would also end up rendered.
 *
 * @since 0.2.0
 *
 * @param sample        - Interpolated `(distance, elevation)` sample.
 * @param totalDistance - Total track distance in metres.
 * @param yMin          - Padded y-axis lower bound.
 * @param yMax          - Padded y-axis upper bound.
 * @param chart         - Plot rectangle in viewBox logical units.
 * @return SVG-space `(cx, cy)` for the cursor dot.
 */
export function sampleToSvg(
	sample: DistanceElevation,
	totalDistance: number,
	yMin: number,
	yMax: number,
	chart: ChartBounds
): { cx: number; cy: number } {
	const chartWidth = chart.right - chart.left;
	const chartHeight = chart.bottom - chart.top;

	const cx =
		totalDistance > 0
			? chart.left + ( sample[ 0 ] / totalDistance ) * chartWidth
			: chart.left;

	const ySpan = yMax > yMin ? yMax - yMin : 0;
	const cy =
		ySpan > 0
			? chart.bottom - ( ( sample[ 1 ] - yMin ) / ySpan ) * chartHeight
			: chart.bottom;

	return { cx, cy };
}
