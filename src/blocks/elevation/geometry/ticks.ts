/**
 * Nice-tick generator for the elevation chart's two axes.
 *
 * Implements the `[1, 2, 5] × 10^n` nice-step series so the margin
 * algorithm can consume the eventual tick labels rather than raw
 * min/max/distance values. The m/km unit-switch lives in `./format.ts`;
 * this module only deals with numeric tick generation.
 *
 * The algorithm:
 *
 *   1. Compute a target tick count `N` from the available plotting
 *      extent and a reference-label size
 *      (`N = floor((avail + 1em) / (refSize + 1em))`, clamped to
 *      ≥ 2). The additive `1em` is the constant luft both axes use to
 *      keep adjacent labels visually separate.
 *   2. Divide the data range by `N` to get a candidate step.
 *   3. Round the candidate to the nearest value of the form
 *      `[1, 2, 5] × 10^n` so the axis reads on a linear, easy-to-skim
 *      scale.
 *   4. Generate values from `floor(min/step) × step` up to
 *      `ceil(max/step) × step`, inclusive on both ends.
 *
 * The functions are pure: no DOM, no closures over caller state. The
 * margin algorithm consumes them through `geometry/margins.ts`; the
 * chart renderer draws the generated values directly.
 *
 * @since 1.0.0
 */

/**
 * Computes the target tick count `N` from the available plotting
 * extent and a reference-label size.
 *
 * Reserves at least `1em` of breathing room between adjacent labels
 * regardless of label size. Solving
 * `N · refSize + (N − 1) · 1em ≤ avail` for `N` gives the formula
 * encoded here. Constant luft (as opposed to the proportional `× 1.5`
 * variant Step 3 used) is easier to reason about and keeps the
 * visual gap consistent across very different label widths (`"0 m"`
 * vs `"1234 m"`).
 *
 * Returns at least `2` so even an unusably narrow chart still emits a
 * start-and-end tick pair. Non-positive inputs collapse to the
 * minimum — the caller passes the actual SVG dimensions and reference
 * measurement, so either zero would indicate a not-yet-ready chart.
 *
 * @since 1.0.0
 *
 * @param avail   Plotting-area extent in SVG user units (after
 *                subtracting the relevant margins). Width for X,
 *                height for Y.
 * @param refSize Reference-label size in the same units. Width of the
 *                worst-case X label for the X axis; height of the
 *                shared height-reference string for the Y axis.
 * @param em      Resolved font-size in pixels, used to scale the
 *                additive `1em` luft term.
 * @return The target tick count, clamped to `≥ 2`.
 */
export function computeTickCount(
	avail: number,
	refSize: number,
	em: number
): number {
	if ( avail <= 0 || refSize <= 0 ) {
		return 2;
	}
	const padding = em;
	const raw = Math.floor( ( avail + padding ) / ( refSize + padding ) );
	return raw < 2 ? 2 : raw;
}

/**
 * Picks a nice tick step for a range and a target tick count.
 *
 * Rounds the ideal step `range / count` to the nearest entry in the
 * `[1, 2, 5] × 10^n` series. Ties resolve toward the smaller nice
 * value so the resulting axis carries at least the requested number
 * of ticks rather than fewer.
 *
 * Non-positive `range` or `count` returns `1` — a safe sentinel the
 * caller may treat as "draw no ticks" or fall back to a fixed
 * one-unit grid.
 *
 * @since 1.0.0
 *
 * @param range       Magnitude of the data range (`max - min`).
 * @param targetCount Desired tick count (typically from
 *                    {@link computeTickCount}).
 * @return The chosen step size.
 */
export function niceStep( range: number, targetCount: number ): number {
	if ( range <= 0 || targetCount <= 0 ) {
		return 1;
	}

	// `rough` is the unrounded ideal step; `magnitude` is the largest power
	// of ten that fits below it. `norm` is `rough` expressed in units of
	// `magnitude`, so it always lies in `[1, 10)`.
	const rough = range / targetCount;
	const magnitude = Math.pow( 10, Math.floor( Math.log10( rough ) ) );
	const norm = rough / magnitude;

	// Round `norm` to the nearest entry in `[1, 2, 5, 10]`. The breakpoints
	// resolve ties downward so the actual tick count meets or exceeds the
	// target rather than dropping below it.
	let nice: number;
	if ( norm < 1.5 ) {
		nice = 1;
	} else if ( norm < 3 ) {
		nice = 2;
	} else if ( norm < 7 ) {
		nice = 5;
	} else {
		nice = 10;
	}

	return nice * magnitude;
}

/**
 * Generates the nice-tick values from `floor(min/step) × step` up to
 * `ceil(max/step) × step`, inclusive on both ends.
 *
 * Uses an integer counter and multiplicative spacing to avoid the
 * cumulative float drift that an additive loop produces around 0.1-
 * sized steps. The returned array is fresh on every call so callers
 * may mutate it freely.
 *
 * @since 1.0.0
 *
 * @param min  Range start.
 * @param max  Range end. Treated as `max >= min`.
 * @param step Nice step size; non-positive returns the empty array.
 * @return Tick values in ascending order, anchored to the step grid.
 */
export function generateTicks(
	min: number,
	max: number,
	step: number
): number[] {
	if ( step <= 0 ) {
		return [];
	}
	if ( max < min ) {
		return [];
	}

	const first = Math.floor( min / step ) * step;
	const last = Math.ceil( max / step ) * step;
	const count = Math.round( ( last - first ) / step );

	const result: number[] = [];
	for ( let i = 0; i <= count; i++ ) {
		result.push( first + i * step );
	}
	return result;
}

/**
 * Convenience wrapper that picks a step via {@link niceStep} and
 * generates the corresponding values via {@link generateTicks}.
 *
 * Returns both so callers that need the step for label-precision
 * decisions (see `./format.ts`) do not have to recompute it.
 *
 * @since 1.0.0
 *
 * @param min         Range start.
 * @param max         Range end.
 * @param targetCount Desired tick count.
 * @return Step and values bundle; see the inline interface.
 */
export function niceTicks(
	min: number,
	max: number,
	targetCount: number
): { readonly step: number; readonly values: readonly number[] } {
	const step = niceStep( max - min, targetCount );
	return { step, values: generateTicks( min, max, step ) };
}
