/**
 * Pure placement helpers for the GPX Map block's waypoint tooltips.
 *
 * Leaflet positions a `direction: 'top'` tooltip centred above the marker by
 * default, and a `direction: 'bottom'` tooltip centred below. With no extra
 * logic, both directions can clip against the map container's edges when the
 * marker sits close to the boundary — the tooltip then disappears partly
 * outside the viewport. The two helpers exported from this module decide,
 * given a pre-measured tooltip box and the marker's container-pixel position,
 * which direction to use and how far to nudge the tooltip horizontally to
 * keep it inside the container.
 *
 * Both functions are pure: they do no DOM access, take no Leaflet dependency,
 * and are exercised by the Jest tests in `tooltip-placement.test.ts` without
 * any browser environment beyond the runner's defaults.
 *
 * @since 0.13.5
 */

/**
 * Inputs to {@link chooseTooltipDirection}.
 *
 * @since 0.13.5
 */
export interface DirectionInput {
	/** Marker's Y coordinate inside the map container, in pixels. */
	readonly markerY: number;
	/** Pre-measured tooltip element height, in pixels. */
	readonly tooltipHeight: number;
	/**
	 * Gap Leaflet leaves between the marker and the tooltip's adjacent edge
	 * for the active direction, in pixels. Constant per marker type; for the
	 * GPX Map's `circleMarker` it amounts to the marker's radius plus a
	 * small Leaflet-internal pad.
	 */
	readonly leafletGap: number;
	/** Minimum allowed distance between the tooltip's edge and the container's edge, in pixels. */
	readonly paddingPx: number;
}

/**
 * Picks the vertical direction for a waypoint tooltip.
 *
 * Returns `'top'` whenever the tooltip's top edge would remain at least
 * `paddingPx` away from the container's top edge when placed above the
 * marker, and `'bottom'` otherwise. The rule is one-way by design: a marker
 * near the container's bottom edge keeps the default `'top'` direction
 * because the tooltip above it is always visible.
 *
 * @since 0.13.5
 *
 * @param input - Placement inputs; see {@link DirectionInput}.
 * @return `'top'` when there is enough room above the marker, `'bottom'` otherwise.
 */
export function chooseTooltipDirection(
	input: DirectionInput
): 'top' | 'bottom' {
	const tooltipTopIfAbove =
		input.markerY - input.leafletGap - input.tooltipHeight;
	return tooltipTopIfAbove < input.paddingPx ? 'bottom' : 'top';
}

/**
 * Inputs to {@link computeTooltipHorizontalOffset}.
 *
 * @since 0.13.5
 */
export interface HorizontalOffsetInput {
	/** Marker's X coordinate inside the map container, in pixels. */
	readonly markerX: number;
	/** Pre-measured tooltip element width, in pixels. */
	readonly tooltipWidth: number;
	/** Map container's width, in pixels. */
	readonly containerWidth: number;
	/** Minimum allowed distance between the tooltip's edge and the container's edge, in pixels. */
	readonly paddingPx: number;
}

/**
 * Returns the horizontal pixel offset to apply to a centred tooltip so it
 * stays at least `paddingPx` away from both the container's left and right
 * edges.
 *
 * A positive return value shifts the tooltip to the right (the marker is
 * near the left edge); a negative value shifts it to the left (near the
 * right edge). Returns `0` when a centred tooltip already fits within the
 * padded interval.
 *
 * @since 0.13.5
 *
 * @param input - Placement inputs; see {@link HorizontalOffsetInput}.
 * @return Pixel offset to add to a centred tooltip's X position.
 */
export function computeTooltipHorizontalOffset(
	input: HorizontalOffsetInput
): number {
	const half = input.tooltipWidth / 2;
	const left = input.markerX - half;
	const right = input.markerX + half;
	const minLeft = input.paddingPx;
	const maxRight = input.containerWidth - input.paddingPx;
	if ( left < minLeft ) {
		return minLeft - left;
	}
	if ( right > maxRight ) {
		return maxRight - right;
	}
	return 0;
}

/**
 * Box dimensions returned by {@link measureTooltipBox}.
 *
 * @since 0.13.5
 */
export interface TooltipBox {
	/** Measured width in pixels. */
	readonly width: number;
	/** Measured height in pixels. */
	readonly height: number;
}

/**
 * Measures the rendered width and height of a tooltip element by briefly
 * inserting a clone into a hidden Leaflet-tooltip wrapper in the supplied
 * tooltip pane.
 *
 * The clone is wrapped in the same `.leaflet-tooltip` class as a runtime
 * tooltip so it inherits the same padding, max-width, and font metrics. The
 * wrapper is positioned off-screen with `visibility: hidden` so the
 * insertion is never visible. The wrapper is removed before the function
 * returns, so the tooltip pane is left in the same state as on entry.
 *
 * @since 0.13.5
 *
 * @param tooltipEl   - Element that will be passed to `marker.bindTooltip`.
 * @param tooltipPane - The map's `tooltipPane` element, used as the
 *                    measurement host so the clone inherits the same CSS
 *                    context as the real tooltip.
 * @return Box dimensions in pixels.
 */
export function measureTooltipBox(
	tooltipEl: HTMLElement,
	tooltipPane: HTMLElement
): TooltipBox {
	const wrapper = document.createElement( 'div' );
	wrapper.className = 'leaflet-tooltip leaflet-tooltip-top';
	wrapper.style.position = 'absolute';
	wrapper.style.left = '-9999px';
	wrapper.style.top = '-9999px';
	wrapper.style.visibility = 'hidden';
	wrapper.appendChild( tooltipEl.cloneNode( true ) );

	tooltipPane.appendChild( wrapper );
	const box: TooltipBox = {
		width: wrapper.offsetWidth,
		height: wrapper.offsetHeight,
	};
	tooltipPane.removeChild( wrapper );

	return box;
}
