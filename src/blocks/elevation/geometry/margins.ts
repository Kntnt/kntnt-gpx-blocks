/**
 * Margin algorithm for the elevation chart.
 *
 * Computes the five scalars the Step 3 + Step 4 chart geometry needs:
 *
 *   - `wLeft`  — left margin reserving space for the widest Y label.
 *   - `wRight` — right margin reserving the half-width of the
 *                worst-case X label so the centre-aligned label cannot
 *                overflow the container's right edge. Driven by
 *                `xReferenceString(distance)` so the value is stable
 *                across resizes and the chicken-and-egg between
 *                margins and tick count is broken (Step 4).
 *   - `wTop`   — top margin reserving the upper half of the topmost
 *                Y label, which is centred on its tick at `y = wTop`
 *                (Step 4).
 *   - `h`      — bottom margin reserving the height of the tallest
 *                realistic numeric label (driven by the fixed
 *                reference string `"-0,123456789"`).
 *   - `em`     — the resolved Tick-labels font-size in pixels, returned
 *                so callers do not need to re-measure to convert
 *                `em`-scaled values to user units.
 *
 * Updated formulas (Step 4):
 *
 *   wLeft  = widest(niceYLabels).width + 0.5em
 *   wRight = measure(xReferenceString(distance)).width / 2 + 0.5em
 *   wTop   = 0.5 × refHeight + 0.5em
 *   h      = refHeight + 0.5em
 *
 * The function is pure with respect to a passed-in measurer — DOM
 * access is delegated entirely to that callable, which makes
 * `computeMargins()` fully unit-testable with a mock measurer. The
 * measurer reads the active tick-label typography through CSS
 * inheritance from the host SVG (see `measure.ts`), so the algorithm
 * itself does not thread a typography bundle through its API.
 *
 * The Step 3 Case-B substitution lives here: when `minElevation ===
 * maxElevation` the Y range inflates to `[min−1, min+1]` so the
 * tick generator still produces a sensible label set. Cases A
 * (`null` elevation) and C (`distance === 0`) are dispatched
 * upstream — `Render_Elevation` / `preview.tsx` surface them as
 * warnings before the chart ever calls into this function.
 *
 * @since 1.0.0
 */

import { formatYLabels, xReferenceString } from './format';
import type { TextMeasurer } from './measure';
import { niceTicks } from './ticks';

/**
 * Raw chart data the margin algorithm consumes.
 *
 * Elevation is in metres; distance is in metres. Cases A and C
 * (null elevation / zero distance) are filtered upstream — by the
 * time {@link computeMargins} is called, the values are well-formed
 * numbers.
 *
 * @since 1.0.0
 */
export interface MarginsInput {
	readonly minElevation: number;
	readonly maxElevation: number;
	readonly distance: number;
}

/**
 * Margin scalars and the resolved `em` base.
 *
 * `em` is the Tick-labels font-size in pixels, useful for callers that
 * want to express other geometry in `em` units (e.g. Step 4's tick
 * marker length of `0.2em` and the additive luft term in
 * `computeTickCount`) without a second measurer round-trip.
 *
 * @since 1.0.0
 */
export interface Margins {
	readonly wLeft: number;
	readonly wRight: number;
	readonly wTop: number;
	readonly h: number;
	readonly em: number;
}

/**
 * Reference string whose rendered height drives the top and bottom
 * margins.
 *
 * Chosen for the union of extreme glyphs (minus sign, comma decimal,
 * full digit range) so the measurement covers any realistic label the
 * chart will draw. Locked by Step 3's *Margin algorithm*.
 *
 * @since 1.0.0
 */
const HEIGHT_REFERENCE = '-0,123456789';

/**
 * Default target tick count used when sizing margins before the
 * available plot width is known.
 *
 * Only the Y-label set drives a measurement (`wLeft` reads the
 * widest); the X side now uses the deterministic
 * {@link xReferenceString} so it does not need this hint. The actual
 * tick counts on screen are derived per-axis from the post-margin
 * extents in `chart.tsx` / `view.ts` via `computeTickCount`.
 *
 * @since 1.0.0
 */
const DEFAULT_TARGET_TICK_COUNT = 5;

/**
 * Selects the longest measured width across a list of labels.
 *
 * Each label is measured once. Returns `0` for an empty list — a
 * degenerate case the caller treats as "no Y margin needed".
 *
 * @since 1.0.0
 *
 * @param labels  Label strings to measure.
 * @param measure Measurer callback.
 * @return The widest measured width in user units.
 */
function widestWidth(
	labels: readonly string[],
	measure: TextMeasurer
): number {
	let widest = 0;
	for ( const label of labels ) {
		const m = measure( label );
		if ( m.width > widest ) {
			widest = m.width;
		}
	}
	return widest;
}

/**
 * Computes the margin scalars for the given data.
 *
 * @since 1.0.0
 *
 * @param data        Raw chart data (elevation range + distance).
 * @param measure     Text measurer (typically returned by
 *                    `createTextMeasurer()`; tests inject a mock).
 * @param targetCount Optional tick-count hint for the Y-label
 *                    measurement; defaults to
 *                    {@link DEFAULT_TARGET_TICK_COUNT}.
 * @return The {@link Margins} bundle.
 */
export function computeMargins(
	data: MarginsInput,
	measure: TextMeasurer,
	targetCount: number = DEFAULT_TARGET_TICK_COUNT
): Margins {
	// Apply the Step 3 Case-B substitution. A flat track still needs
	// renderable axes; inflating the Y range by ±1 metre around the
	// constant value gives the tick generator something to bite on.
	const flatY = data.minElevation === data.maxElevation;
	const yMin = flatY ? data.minElevation - 1 : data.minElevation;
	const yMax = flatY ? data.maxElevation + 1 : data.maxElevation;

	// Generate the Y nice tick set and format it. The widest Y label
	// drives wLeft; X's wRight is keyed on a worst-case reference
	// string so no X tick generation is needed at this stage.
	const yTicks = niceTicks( yMin, yMax, targetCount );
	const yLabels = formatYLabels( yTicks.values, yTicks.step );

	// Measure the widest Y label and the worst-case X reference string.
	const widestY = widestWidth( yLabels, measure );
	const xRef = measure( xReferenceString( data.distance ) );

	// Measure the height reference string. The same character height
	// applies to any realistic numeric label, so no per-axis height
	// computation is needed.
	const ref = measure( HEIGHT_REFERENCE );

	// Resolve the `em` base. Every measurement reports the same
	// resolved font-size (typography is constant across the call), so
	// the reference string's value is the canonical source.
	const em = ref.fontSize;
	const halfEm = 0.5 * em;

	return {
		wLeft: widestY + halfEm,
		wRight: xRef.width / 2 + halfEm,
		wTop: 0.5 * ref.height + halfEm,
		h: ref.height + halfEm,
		em,
	};
}
