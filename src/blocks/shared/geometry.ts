/**
 * Shared geometry helpers used by both Map and Elevation cursor-sync paths.
 *
 * Both blocks need the same two primitives when mapping a fraction-of-total
 * distance onto their respective rendered geometry:
 *
 * - `lowerBoundIndex` binary-searches a monotone non-decreasing array for the
 *   predecessor index of a target value. Map calls it on the per-vertex
 *   cumulative-distance array PHP emits (`trackCumDist`); Elevation calls it
 *   on the LTTB downsampled distance series.
 * - `clamp01` constrains a value to the closed interval `[0, 1]`. Used to
 *   defensively guard fraction inputs before they index into the geometry.
 *
 * Issue #128 — previously these two helpers were duplicated word-for-word
 * inside `src/blocks/map/geometry.ts` and `src/blocks/elevation/geometry.ts`.
 * Consolidated here so they are defined exactly once and tested in a single
 * suite.
 *
 * @since 1.0.0
 */

/**
 * Find the largest index `i` such that `arr[i] <= target`.
 *
 * Returns `0` when `target` precedes `arr[0]` (or when the array is empty),
 * and `arr.length - 1` when `target` equals or exceeds the final entry.
 * Assumes `arr` is monotonically non-decreasing — both `trackCumDist` and the
 * LTTB distance series are, by construction.
 *
 * @since 1.0.0
 *
 * @param arr    - Monotone non-decreasing array.
 * @param target - Value to bracket.
 * @return Index of the predecessor entry.
 */
export function lowerBoundIndex(
	arr: readonly number[],
	target: number
): number {
	if ( arr.length === 0 ) {
		return 0;
	}
	let lo = 0;
	let hi = arr.length - 1;
	if ( target <= ( arr[ 0 ] as number ) ) {
		return 0;
	}
	if ( target >= ( arr[ hi ] as number ) ) {
		return hi;
	}
	while ( lo + 1 < hi ) {
		const mid = Math.floor( ( lo + hi ) / 2 );
		if ( ( arr[ mid ] as number ) <= target ) {
			lo = mid;
		} else {
			hi = mid;
		}
	}
	return lo;
}

/**
 * Clamp to the closed interval `[0, 1]`.
 *
 * Both blocks need the same idiom when guarding a fraction-of-total-distance
 * input before mapping it onto their geometry.
 *
 * @since 1.0.0
 *
 * @param v - Value to clamp.
 * @return Clamped value in `[0, 1]`.
 */
export function clamp01( v: number ): number {
	if ( v < 0 ) {
		return 0;
	}
	if ( v > 1 ) {
		return 1;
	}
	return v;
}
