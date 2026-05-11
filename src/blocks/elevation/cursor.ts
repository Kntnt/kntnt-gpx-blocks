/**
 * Pure cursor-update helpers for the GPX Elevation HTML overlays.
 *
 * Issue #136 moved the cursor dot and tooltip out of the SVG into HTML
 * sibling overlays so they are immune to the wrapper-as-image layout's
 * non-uniform stretch. The functions here split the DOM mutations into
 * small testable pieces — the writes themselves stay imperative but the
 * fraction-of-plot-rectangle math is pure.
 *
 * Imported by `view.ts` at runtime and by `cursor.test.ts` for Jest
 * coverage.
 *
 * @since 1.0.0
 */

/**
 * Subset of the elements `updateCursorOverlays` writes to. Defining the
 * shape as an interface (rather than passing each element separately)
 * keeps the call signature short and reads at the call site naturally
 * mirror the `ElevationEntry` shape in `view.ts`.
 *
 * @since 1.0.0
 */
export interface CursorOverlayElements {
	/** The vertical cursor line inside the SVG; writes go to `x1`/`x2`. */
	readonly cursorLine: SVGLineElement;
	/** The HTML overlay wrapper carrying the visibility / preview flags. */
	readonly cursorOverlay: HTMLElement;
	/** The HTML dot whose `style.left` / `style.top` we write. */
	readonly cursorDot: HTMLElement;
	/** The HTML tooltip whose `style.left` / `style.top` we write. */
	readonly tooltip: HTMLElement;
	/** The distance row inside the tooltip — `textContent` target. */
	readonly tooltipDistance: HTMLElement;
	/** The elevation row inside the tooltip — `textContent` target. */
	readonly tooltipElevation: HTMLElement;
}

/**
 * Per-update inputs derived from the interpolated sample.
 *
 * The two cursor positions are split: the SVG-side line takes viewBox-unit
 * `cx`; the HTML overlays take a `(fxPct, fyPct)` fraction of the plot
 * rectangle in 0..100 so they resolve against the overlay container which
 * spans the same plot rectangle in CSS pixels.
 *
 * @since 1.0.0
 */
export interface CursorPosition {
	/** SVG viewBox-unit x for the cursor line's `x1` / `x2`. */
	readonly cx: number;
	/** Fraction of plot rect (0..100) for the HTML dot's `style.left`. */
	readonly fxPct: number;
	/** Fraction of plot rect (0..100) for the HTML dot's `style.top`. */
	readonly fyPct: number;
	/** Formatted distance label, e.g. `"3.2 km"`. */
	readonly distanceLabel: string;
	/** Formatted elevation label, e.g. `"245 m"`. */
	readonly elevationLabel: string;
}

/**
 * Clamp a value to `[min, max]`.
 *
 * @since 1.0.0
 *
 * @param value - Value to clamp.
 * @param min   - Lower bound.
 * @param max   - Upper bound.
 * @return Clamped value.
 */
export function clamp( value: number, min: number, max: number ): number {
	return Math.max( min, Math.min( max, value ) );
}

/**
 * Compute the fraction-of-plot-rectangle position of a `(distance,
 * elevation)` sample as a `(fxPct, fyPct)` pair in 0..100.
 *
 * Distance maps linearly to fxPct; elevation flips around `yMax` since
 * higher elevation should sit at the top of the plot (smaller percentage
 * from the top). Both values are pre-clamped so a sample falling slightly
 * outside the `[0, totalDistance]` × `[yMin, yMax]` envelope (which can
 * happen on the boundary samples) does not produce out-of-range CSS.
 *
 * @since 1.0.0
 *
 * @param distanceM     - Sample distance in metres.
 * @param elevationM    - Sample elevation in metres.
 * @param totalDistance - Total track distance in metres.
 * @param yMin          - Padded y-axis lower bound.
 * @param yMax          - Padded y-axis upper bound.
 * @return Fraction-of-plot-rectangle position in 0..100 along each axis.
 */
export function samplePositionPercent(
	distanceM: number,
	elevationM: number,
	totalDistance: number,
	yMin: number,
	yMax: number
): { fxPct: number; fyPct: number } {
	const fxPct =
		totalDistance > 0 ? clamp( distanceM / totalDistance, 0, 1 ) * 100 : 0;
	const ySpan = yMax > yMin ? yMax - yMin : 0;
	const fyPct =
		ySpan > 0
			? clamp( 1 - ( elevationM - yMin ) / ySpan, 0, 1 ) * 100
			: 100;
	return { fxPct, fyPct };
}

/**
 * Format a distance value for the tooltip's first row.
 *
 * Switches from metres to kilometres at the 1000 m threshold, matching the
 * x-axis tick labels. Kilometres carry one decimal; metres are rounded to
 * the nearest whole number. The label is locale-neutral (raw "." decimal)
 * so it agrees with the server-rendered editor-preview tooltip byte-for-
 * byte — see `Render_Elevation::format_distance_label`.
 *
 * @since 1.0.0
 *
 * @param distanceM - Distance in metres.
 * @return Formatted label, e.g. `"3.2 km"` or `"245 m"`.
 */
export function formatDistance( distanceM: number ): string {
	return distanceM >= 1000
		? `${ ( distanceM / 1000 ).toFixed( 1 ) } km`
		: `${ Math.round( distanceM ) } m`;
}

/**
 * Format an elevation value for the tooltip's second row.
 *
 * Always rendered in metres rounded to the nearest whole number — the GPX
 * vertical resolution does not justify decimals here.
 *
 * @since 1.0.0
 *
 * @param elevationM - Elevation in metres.
 * @return Formatted label, e.g. `"245 m"`.
 */
export function formatElevation( elevationM: number ): string {
	return `${ Math.round( elevationM ) } m`;
}

/**
 * Hide both the SVG-side cursor line and the HTML cursor overlay wrapper.
 *
 * Used on null / undefined fraction and on the no-sample edge case. Skipped
 * by the caller while the cursor is in editor-preview mode so the
 * server-rendered preview survives the initial mount-time watch fire.
 *
 * @since 1.0.0
 *
 * @param elements - The cursor elements to hide.
 */
export function hideCursor( elements: CursorOverlayElements ): void {
	elements.cursorOverlay.style.display = 'none';
	elements.cursorLine.style.display = 'none';
}

/**
 * Reveal both the SVG-side cursor line and the HTML cursor overlay
 * wrapper if either is currently hidden.
 *
 * Idempotent — repeated calls with already-visible elements are no-ops.
 * Done before reading the tooltip's `offsetWidth` so the layout has a
 * chance to settle before the read.
 *
 * @since 1.0.0
 *
 * @param elements - The cursor elements to reveal.
 */
export function showCursor( elements: CursorOverlayElements ): void {
	if ( elements.cursorOverlay.style.display === 'none' ) {
		elements.cursorOverlay.style.display = '';
	}
	if ( elements.cursorLine.style.display === 'none' ) {
		elements.cursorLine.style.display = '';
	}
}

/**
 * Apply a `CursorPosition` to the cursor DOM elements.
 *
 * Writes the SVG cursor line's `x1` / `x2` attributes, the HTML dot's
 * `style.left` / `style.top` percentages, the tooltip's text content,
 * and the tooltip's `style.left` (centred on the dot, clamped so the
 * tooltip stays inside the overlay container when its intrinsic width
 * is measurable). The tooltip's `style.top` is always pinned to `"0"`
 * so the tooltip anchors to the top edge of the plot rectangle.
 *
 * Splitting this from `view.ts` keeps the DOM-mutation surface unit-
 * testable: a jsdom-backed test can hand in synthetic elements and assert
 * the writes without needing the Interactivity API store.
 *
 * @since 1.0.0
 *
 * @param elements - The cursor elements to update.
 * @param position - Pre-computed cursor position + labels.
 */
export function applyCursorPosition(
	elements: CursorOverlayElements,
	position: CursorPosition
): void {
	const {
		cursorLine,
		cursorOverlay,
		cursorDot,
		tooltip,
		tooltipDistance,
		tooltipElevation,
	} = elements;
	const { cx, fxPct, fyPct, distanceLabel, elevationLabel } = position;

	// Update the cursor LINE inside the SVG. The line stays in viewBox
	// space; `vector-effect="non-scaling-stroke"` keeps its stroke width
	// visually consistent under the wrapper-as-image stretch.
	cursorLine.setAttribute( 'x1', String( cx ) );
	cursorLine.setAttribute( 'x2', String( cx ) );

	// Update the tooltip text first so its intrinsic width reflects the
	// new label before we measure it for clamping.
	if ( tooltipDistance.textContent !== distanceLabel ) {
		tooltipDistance.textContent = distanceLabel;
	}
	if ( tooltipElevation.textContent !== elevationLabel ) {
		tooltipElevation.textContent = elevationLabel;
	}

	// Position the HTML dot at the fraction-of-plot-rectangle anchor.
	cursorDot.style.left = `${ fxPct }%`;
	cursorDot.style.top = `${ fyPct }%`;

	// Position the HTML tooltip horizontally centred on the dot, clamped
	// inside the overlay container if the tooltip's intrinsic width is
	// measurable. Falls back to the un-clamped percentage when the
	// container has no width (e.g. just-revealed cursor before the
	// browser has laid out).
	const overlayWidthPx = cursorOverlay.clientWidth;
	const tooltipWidthPx = tooltip.offsetWidth;
	if ( overlayWidthPx > 0 && tooltipWidthPx > 0 ) {
		const centrePx = ( fxPct / 100 ) * overlayWidthPx;
		const halfWidth = tooltipWidthPx / 2;
		const clampedPx = clamp(
			centrePx,
			halfWidth,
			overlayWidthPx - halfWidth
		);
		tooltip.style.left = `${ ( clampedPx / overlayWidthPx ) * 100 }%`;
	} else {
		tooltip.style.left = `${ fxPct }%`;
	}
	tooltip.style.top = '0';
}
