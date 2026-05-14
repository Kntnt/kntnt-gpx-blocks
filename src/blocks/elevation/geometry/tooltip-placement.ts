/**
 * Pure placement algorithm for the elevation chart's tooltip.
 *
 * Step 7 of `docs/elevation-rebuild.md` pins the tooltip's vertical
 * anchor to the top of the plot rectangle and its horizontal position
 * to the cursor's `cx`. When the tooltip on the right side of the
 * cursor would otherwise extend past the plot rectangle's right edge,
 * it flips to the left side of the cursor. A 0.5em hysteresis band
 * prevents flip oscillation when the cursor wiggles at the threshold:
 * once on the left, the tooltip flips back to the right only when the
 * cursor has moved 0.5em past the threshold in the opposite direction.
 *
 * The function takes the previous side as input (rather than maintaining
 * its own state) so it stays trivially testable — deterministic in / out.
 * The caller (the frontend's `view.ts`) threads `entry.tooltipSide`
 * through; the editor preview (`chart.tsx`) passes `null` since it
 * renders a single static frame.
 *
 * @since 1.0.0
 */

/**
 * Inputs consumed by {@link computeTooltipPlacement}.
 *
 * @since 1.0.0
 */
export interface TooltipPlacementInput {
	readonly cursor: { readonly cx: number };
	readonly plotRect: {
		readonly x: number;
		readonly y: number;
		readonly w: number;
		readonly h: number;
	};
	readonly tooltipBox: {
		readonly w: number;
		readonly h: number;
	};
	readonly em: number;
	readonly previousSide: 'right' | 'left' | null;
}

/**
 * Result of {@link computeTooltipPlacement}.
 *
 * @since 1.0.0
 */
export interface TooltipPlacementOutput {
	readonly x: number;
	readonly y: number;
	readonly side: 'right' | 'left';
}

/**
 * Resolves the tooltip's `(x, y)` corner and the side of the cursor it
 * sits on. Top-pinned to `plotRect.y + 0.5em`; horizontal side flips
 * when the right-side placement would clip the plot rectangle, with
 * 0.5em of hysteresis around the threshold to prevent oscillation.
 *
 * @since 1.0.0
 *
 * @param input The placement input bundle.
 * @return The placement output.
 */
export function computeTooltipPlacement(
	input: TooltipPlacementInput
): TooltipPlacementOutput {
	const gap = 0.5 * input.em;
	const padTop = 0.5 * input.em;
	const padRight = 0.5 * input.em;
	const hysteresis = 0.5 * input.em;

	// Top-pinned y. Constant for every cursor position so the tooltip
	// stays stable vertically through a scrub on a hilly track.
	const y = input.plotRect.y + padTop;

	// The two candidate x positions: right side of cursor (default) and
	// left side (after a flip).
	const xRight = input.cursor.cx + gap;
	const xLeft = input.cursor.cx - gap - input.tooltipBox.w;

	// The boundary on `xRight` that triggers a flip to the left side.
	// Computed against the plot rectangle (not the SVG host's edges) so
	// the tooltip never visually overlaps the axis-label margins.
	const plotRight = input.plotRect.x + input.plotRect.w;
	const rightOverflowAt = plotRight - padRight - input.tooltipBox.w;

	// Side-selection with asymmetric hysteresis. Coming from the right,
	// flip the moment `xRight` crosses the boundary. Coming from the
	// left, only flip back when `xRight` has cleared the boundary by a
	// full hysteresis band — a wiggle of ±1px cannot trigger oscillation.
	let side: 'right' | 'left';
	if ( input.previousSide === 'left' ) {
		side = xRight <= rightOverflowAt - hysteresis ? 'right' : 'left';
	} else {
		side = xRight > rightOverflowAt ? 'left' : 'right';
	}

	return {
		x: side === 'right' ? xRight : xLeft,
		y,
		side,
	};
}
