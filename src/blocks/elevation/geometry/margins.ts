/**
 * Margin algorithm for the elevation chart.
 *
 * Computes the four scalars the Step 3 chart geometry needs:
 *
 *   - `wLeft`  — left margin reserving space for the widest Y label.
 *   - `wRight` — right margin reserving the half-width of the last X
 *                label so the centre-aligned label cannot overflow the
 *                container's right edge.
 *   - `h`      — bottom margin reserving the height of the tallest
 *                realistic numeric label (driven by the fixed
 *                reference string `"-0,123456789"`).
 *   - `em`     — the resolved Tick-labels font-size in pixels, returned
 *                so callers do not need to re-measure to convert
 *                `em`-scaled values to user units.
 *
 * The formulas are pinned in Step 3 of `docs/elevation-rebuild.md`
 * § *Margin algorithm*:
 *
 *   wLeft  = widest(niceYLabels).width  + 0.5em
 *   wRight = last(niceXLabels).width / 2 + 0.5em
 *   h      = measure("-0,123456789").height + 0.5em
 *
 * The function is pure with respect to a passed-in measurer — DOM
 * access is delegated entirely to that callable, which makes
 * `computeMargins()` fully unit-testable with a mock measurer.
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

import { formatXLabels, formatYLabels } from './format';
import type { TextMeasurer, TypographyAttributes } from './measure';
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
 * marker length of `0.2em`) without a second measurer round-trip.
 *
 * @since 1.0.0
 */
export interface Margins {
	readonly wLeft: number;
	readonly wRight: number;
	readonly h: number;
	readonly em: number;
}

/**
 * Reference string whose rendered height drives the bottom margin.
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
 * Used as the initial estimate; Step 4 refines the count from
 * `W_avail / (labelWidth × 1.5)` once margins are settled. A count
 * around five is the de-facto convention for legible elevation
 * profiles — small enough to keep labels separated, large enough to
 * cover the range with reasonable resolution.
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
 * @param labels     Label strings to measure.
 * @param typography Typography applied to every measurement.
 * @param measure    Measurer callback.
 * @return The widest measured width in user units.
 */
function widestWidth(
	labels: readonly string[],
	typography: TypographyAttributes,
	measure: TextMeasurer
): number {
	let widest = 0;
	for ( const label of labels ) {
		const m = measure( label, typography );
		if ( m.width > widest ) {
			widest = m.width;
		}
	}
	return widest;
}

/**
 * Computes the four margin scalars for the given data + typography.
 *
 * @since 1.0.0
 *
 * @param data        Raw chart data (elevation range + distance).
 * @param typography  Tick-labels typography bundle (the chart wrapper's
 *                    resolved typography).
 * @param measure     Text measurer (typically returned by
 *                    `createTextMeasurer()`; tests inject a mock).
 * @param targetCount Optional tick-count hint; defaults to
 *                    {@link DEFAULT_TARGET_TICK_COUNT}.
 * @return The {@link Margins} bundle.
 */
export function computeMargins(
	data: MarginsInput,
	typography: TypographyAttributes,
	measure: TextMeasurer,
	targetCount: number = DEFAULT_TARGET_TICK_COUNT
): Margins {
	// Apply the Step 3 Case-B substitution. A flat track still needs
	// renderable axes; inflating the Y range by ±1 metre around the
	// constant value gives the tick generator something to bite on.
	const flatY = data.minElevation === data.maxElevation;
	const yMin = flatY ? data.minElevation - 1 : data.minElevation;
	const yMax = flatY ? data.maxElevation + 1 : data.maxElevation;

	// Generate the nice tick values and format them. The X axis runs
	// `[0, distance]` per the chart's geometry model; the Y axis runs
	// the (possibly inflated) elevation range.
	const yTicks = niceTicks( yMin, yMax, targetCount );
	const xTicks = niceTicks( 0, data.distance, targetCount );
	const yLabels = formatYLabels( yTicks.values, yTicks.step );
	const xLabels = formatXLabels( xTicks.values, xTicks.step );

	// Measure the labels the formulas reference. The widest Y label
	// drives the left margin; the last X label drives the right margin
	// (half-width because the label is centred under its marker).
	const widestY = widestWidth( yLabels, typography, measure );
	const lastXMeasure =
		xLabels.length > 0
			? measure( xLabels[ xLabels.length - 1 ] as string, typography )
			: null;
	const lastXWidth = lastXMeasure?.width ?? 0;

	// Measure the height reference string. The same character height
	// applies to any realistic numeric label, so no per-axis height
	// computation is needed.
	const ref = measure( HEIGHT_REFERENCE, typography );

	// Resolve the `em` base. Every measurement reports the same
	// resolved font-size (the typography is constant across the call),
	// so the reference string's value is the canonical source.
	const em = ref.fontSize;
	const halfEm = 0.5 * em;

	return {
		wLeft: widestY + halfEm,
		wRight: lastXWidth / 2 + halfEm,
		h: ref.height + halfEm,
		em,
	};
}
