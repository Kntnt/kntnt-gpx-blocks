/**
 * Pure geometry helpers for the GPX Map cursor sync.
 *
 * Imported by `view.ts` for production use and by `geometry.test.ts` for
 * Jest coverage. Nothing in this module touches the DOM or Leaflet, so it
 * can be exercised without a full browser environment.
 *
 * Two jobs split along the cursor-sync data flow:
 *
 * - `fractionToLatLng` resolves a fraction-of-total-distance to a `[lat, lng]`
 *   point on the *original-distance* parameterisation by binary-searching the
 *   per-vertex cumulative-distance array PHP emitted (`trackCumDist`) and
 *   linearly interpolating between adjacent simplified vertices. The result
 *   sits on the rendered polyline because both inputs come from the same
 *   simplified vertex list.
 *
 * - `clickToFraction` projects an arbitrary `[lat, lng]` (typically a click
 *   on the hit-layer) onto the nearest simplified segment, computes the
 *   parameter `t ∈ [0, 1]` along that segment, and maps it back to a
 *   fraction-of-total-distance via the cumulative-distance endpoints of the
 *   matched segment. The numeric work is done in a flat Cartesian space —
 *   sufficient for distances under a few hundred kilometres at non-polar
 *   latitudes, which covers every GPX track we care about.
 *
 * The two cross-block primitives — `lowerBoundIndex` and `clamp01` — live in
 * `../shared/geometry.ts` and are imported here. See `docs/architecture.md`
 * for the data-flow context.
 *
 * @since 0.2.0
 */

import { clamp01, lowerBoundIndex } from '../shared/geometry';

/**
 * A `[lat, lng]` tuple in WGS84 decimal degrees.
 *
 * Matches the shape that `view.ts` extracts from the simplified GeoJSON
 * (already converted from GeoJSON's `[lon, lat]` order). Keeping the alias
 * local to this module avoids leaking Leaflet types into pure-geometry code.
 *
 * @since 0.2.0
 */
export type LatLng = readonly [ number, number ];

/**
 * Result of `clickToFraction`.
 *
 * Carries both the fraction (the JS state mutates this) and the projected
 * `[lat, lng]` for callers that want to draw a debugging marker; in
 * production the latter is unused but Jest tests assert on it.
 *
 * @since 0.2.0
 */
interface ProjectedClick {
	readonly fraction: number;
	readonly latLng: LatLng;
}

/**
 * Linearly interpolate between two scalars.
 *
 * @since 0.2.0
 *
 * @param a - Lower bound when `t = 0`.
 * @param b - Upper bound when `t = 1`.
 * @param t - Interpolation parameter; not clamped here.
 * @return Linearly blended value.
 */
function lerp( a: number, b: number, t: number ): number {
	return a + ( b - a ) * t;
}

/**
 * Resolve a fraction-of-total-distance to a `[lat, lng]` on the rendered
 * polyline.
 *
 * Binary-searches `trackCumDist` for `fraction × totalDistance`, picks the
 * adjacent vertex pair, and linearly interpolates. The two inputs are
 * aligned in source order: `vertices[i]` corresponds to `trackCumDist[i]`.
 *
 * Edge cases:
 *
 * - `fraction = 0` returns `vertices[0]`.
 * - `fraction = 1` returns `vertices[N-1]`.
 * - Empty inputs return `null`.
 * - A zero-length segment (consecutive vertices share lat/lng) collapses
 *   to that vertex without dividing by zero.
 *
 * @since 0.2.0
 *
 * @param vertices      - Simplified polyline in `[lat, lng]` order.
 * @param trackCumDist  - Cumulative-distance array aligned 1:1 with `vertices`.
 * @param totalDistance - Total track distance in metres.
 * @param fraction      - Fraction of total distance in `[0, 1]`.
 * @return Interpolated `[lat, lng]` or `null` when inputs are empty.
 */
export function fractionToLatLng(
	vertices: readonly LatLng[],
	trackCumDist: readonly number[],
	totalDistance: number,
	fraction: number
): LatLng | null {
	if ( vertices.length === 0 || trackCumDist.length === 0 ) {
		return null;
	}
	if ( vertices.length === 1 ) {
		return vertices[ 0 ] ?? null;
	}

	const target = clamp01( fraction ) * totalDistance;
	const i = lowerBoundIndex( trackCumDist, target );
	const j = Math.min( i + 1, vertices.length - 1 );

	const a = vertices[ i ] as LatLng;
	const b = vertices[ j ] as LatLng;
	const da = trackCumDist[ i ] as number;
	const db = trackCumDist[ j ] as number;
	const span = db - da;

	// Zero-length segment — the cumulative-distance values coincide. Snap to
	// the vertex without dividing by zero. Happens at the tail when fraction
	// is exactly 1 and the last segment has length 0, or on duplicate input.
	const t = span > 0 ? ( target - da ) / span : 0;
	return [ lerp( a[ 0 ], b[ 0 ], t ), lerp( a[ 1 ], b[ 1 ], t ) ];
}

/**
 * Project a click onto the nearest segment of the simplified polyline and
 * return the fraction-of-total-distance of the projection.
 *
 * For each segment, the projection parameter
 * `t = clamp((P-A)·(B-A) / |B-A|²)` gives the closest point on the segment.
 * The squared distance from the click to that closest point picks the best
 * segment; the matching `t` is then mapped to original cumulative distance
 * via the segment's endpoint `trackCumDist` values. Dividing by
 * `totalDistance` produces the fraction.
 *
 * The arithmetic runs in a local flat-Cartesian frame so a single segment's
 * direction and length are computed without trigonometry. Distortion is
 * negligible for nearest-segment selection at the scales involved.
 *
 * Edge cases:
 *
 * - Empty inputs return `{ fraction: 0, latLng: [ 0, 0 ] }` defensively.
 * - A zero-length segment is treated as a single point — `t = 0` collapses
 *   the projection back to the segment start, with no division by zero.
 * - A click on the line's *extension* — beyond either endpoint of the
 *   nearest segment — clamps `t` to `[0, 1]` and returns that endpoint's
 *   fraction.
 *
 * @since 0.2.0
 *
 * @param vertices      - Simplified polyline in `[lat, lng]` order.
 * @param trackCumDist  - Cumulative-distance array aligned 1:1 with `vertices`.
 * @param totalDistance - Total track distance in metres.
 * @param click         - The click position in `[lat, lng]`.
 * @return The fraction (already clamped) and the projected point.
 */
export function clickToFraction(
	vertices: readonly LatLng[],
	trackCumDist: readonly number[],
	totalDistance: number,
	click: LatLng
): ProjectedClick {
	if ( vertices.length < 2 || totalDistance <= 0 ) {
		return { fraction: 0, latLng: [ 0, 0 ] };
	}

	let bestI = 0;
	let bestT = 0;
	let bestDistSq = Infinity;
	let bestProj: LatLng = vertices[ 0 ] as LatLng;

	for ( let i = 0; i < vertices.length - 1; i++ ) {
		const a = vertices[ i ] as LatLng;
		const b = vertices[ i + 1 ] as LatLng;

		const ax = a[ 0 ];
		const ay = a[ 1 ];
		const bx = b[ 0 ];
		const by = b[ 1 ];
		const px = click[ 0 ];
		const py = click[ 1 ];

		const dx = bx - ax;
		const dy = by - ay;
		const lenSq = dx * dx + dy * dy;

		let t: number;
		let projX: number;
		let projY: number;
		if ( lenSq === 0 ) {
			// Zero-length segment — t = 0 places the projection at A. Skip
			// the dot product to avoid 0/0; the squared distance is still
			// meaningful and lets the equality-degenerate case lose to a
			// neighbouring real segment.
			t = 0;
			projX = ax;
			projY = ay;
		} else {
			const dot = ( px - ax ) * dx + ( py - ay ) * dy;
			t = clamp01( dot / lenSq );
			projX = ax + t * dx;
			projY = ay + t * dy;
		}

		const ex = px - projX;
		const ey = py - projY;
		const distSq = ex * ex + ey * ey;
		if ( distSq < bestDistSq ) {
			bestDistSq = distSq;
			bestI = i;
			bestT = t;
			bestProj = [ projX, projY ];
		}
	}

	const da = trackCumDist[ bestI ] as number;
	const db = trackCumDist[ bestI + 1 ] as number;
	const distance = lerp( da, db, bestT );
	const fraction = clamp01( distance / totalDistance );

	return { fraction, latLng: bestProj };
}
