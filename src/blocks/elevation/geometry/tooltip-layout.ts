/**
 * Pure layout algorithm for the elevation chart's tooltip.
 *
 * Shared by the two renderers (`view.ts` and `chart.tsx`) so they
 * agree on tooltip box dimensions and per-row text positions; the two
 * call sites previously inlined the same formulas, which made it easy
 * for them to drift apart and hard to write targeted unit tests
 * against the layout.
 *
 * A naive formula expressing the per-row baseline as
 * `rectTop + padY + bboxHeight` would treat `bboxHeight` as if it
 * equalled the full distance from glyph top to baseline. In Chrome
 * and Safari, however, `SVGGraphicsElement.getBBox()` returns a
 * bbox that reaches the font's descent extent below the baseline
 * even when the rendered glyphs (digits, `m`, `km`) have no
 * descenders. That mismatch — a bbox that extends below the
 * visible glyphs — would push digit-only labels visually down
 * inside the tooltip rect, with more whitespace above the text
 * than below it. The algorithm here uses
 * {@link TextMeasurement.topOffset} (signed offset from the text's
 * baseline to the bbox top) to position each row by its bbox top
 * instead, which makes `padY` above the bbox and `padY` below the
 * bbox symmetric regardless of glyph composition.
 *
 * @since 1.0.0
 */
import type { TextMeasurement } from './measure';

/**
 * Inputs consumed by {@link computeTooltipLayout}.
 *
 * `distance`/`height` are `null` when the corresponding row toggle
 * is off — the layout omits the row from the rect dimensions and
 * leaves its `textY`/`textX` fields at `0` so callers can ignore
 * them safely.
 *
 * @since 1.0.0
 */
export interface TooltipLayoutInput {
	readonly placementX: number;
	readonly placementY: number;
	readonly em: number;
	readonly distance: TextMeasurement | null;
	readonly height: TextMeasurement | null;
}

/**
 * Result of {@link computeTooltipLayout}.
 *
 * The two `textY` fields are alphabetic-baseline y coordinates, ready
 * to assign to an SVG `<text>` element's `y` attribute. The two
 * `textX` fields are the start-anchored x coordinates that align with
 * the rect's left padding. `0` substitutes for any row whose toggle
 * was off — callers must read `distanceVisible`/`heightVisible`
 * before consuming those coordinates.
 *
 * @since 1.0.0
 */
export interface TooltipLayoutResult {
	readonly rectWidth: number;
	readonly rectHeight: number;
	readonly distanceTextX: number;
	readonly distanceTextY: number;
	readonly heightTextX: number;
	readonly heightTextY: number;
	readonly distanceVisible: boolean;
	readonly heightVisible: boolean;
}

/**
 * Computes the tooltip's rect dimensions and per-row text positions
 * from the already-resolved placement origin and the measurement
 * bundles for each visible row.
 *
 * Padding contract — `padY = 0.5em` above and below the rect's
 * visible content; `lineGap = 0.25em` between the two rows when both
 * are visible; `padX = 0.5em` on the left and right of the widest
 * row. The per-row text baselines are positioned so that each row's
 * `bbox.top` sits `padY` (and, in the two-row case, `lineGap`) from
 * the relevant rect edge — *not* the row's baseline. That distinction
 * is the entire point of the pl.2 fix (see module header).
 *
 * @since 1.0.0
 *
 * @param input The layout input bundle.
 * @return The resolved rect dimensions and per-row text positions.
 */
export function computeTooltipLayout(
	input: TooltipLayoutInput
): TooltipLayoutResult {
	const padX = 0.5 * input.em;
	const padY = 0.5 * input.em;
	const lineGap = 0.25 * input.em;

	// Per-row visual dimensions and offsets. A row with its toggle off
	// contributes nothing to the rect's height or width.
	const distH = input.distance?.height ?? 0;
	const heightH = input.height?.height ?? 0;
	const distW = input.distance?.width ?? 0;
	const heightW = input.height?.width ?? 0;
	const distTop = input.distance?.topOffset ?? 0;
	const heightTop = input.height?.topOffset ?? 0;

	// Rect dimensions follow from the visible-row bboxes plus padding
	// and (when both rows are visible) the line gap.
	const bothVisible = input.distance !== null && input.height !== null;
	const rowsHeight = distH + heightH + ( bothVisible ? lineGap : 0 );
	const rectWidth = Math.max( distW, heightW ) + 2 * padX;
	const rectHeight = rowsHeight + 2 * padY;

	// Per-row text baseline positions. The text's `y` attribute is the
	// alphabetic baseline; the bbox top sits `topOffset` above that
	// (topOffset is negative for typical glyphs). Solving
	// `bboxTopY === desiredTopY` for `textY` gives
	// `textY = desiredTopY - topOffset`. The distance row's bbox top
	// always sits at `placementY + padY`; the height row's bbox top
	// sits either directly below the distance row (two rows visible)
	// or at the same `placementY + padY` line as a single-row layout.
	let distanceTextY = 0;
	let heightTextY = 0;
	if ( input.distance !== null && input.height !== null ) {
		const distanceBboxTop = input.placementY + padY;
		const heightBboxTop = distanceBboxTop + distH + lineGap;
		distanceTextY = distanceBboxTop - distTop;
		heightTextY = heightBboxTop - heightTop;
	} else if ( input.distance !== null ) {
		const bboxTop = input.placementY + padY;
		distanceTextY = bboxTop - distTop;
	} else if ( input.height !== null ) {
		const bboxTop = input.placementY + padY;
		heightTextY = bboxTop - heightTop;
	}

	// Horizontal start-anchor: each visible row sits `padX` from the
	// rect's left edge. The `<text>` elements use `text-anchor: start`
	// at both call sites so this single value drives both rows.
	const textX = input.placementX + padX;

	return {
		rectWidth,
		rectHeight,
		distanceTextX: input.distance !== null ? textX : 0,
		distanceTextY,
		heightTextX: input.height !== null ? textX : 0,
		heightTextY,
		distanceVisible: input.distance !== null,
		heightVisible: input.height !== null,
	};
}
