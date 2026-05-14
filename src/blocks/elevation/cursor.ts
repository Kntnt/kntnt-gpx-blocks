/**
 * SVG-DOM helpers for the elevation chart's cursor.
 *
 * Step 6 of `docs/elevation-rebuild.md` puts the cursor inside the chart
 * SVG so it shares the same coordinate system as the axes, ticks, and
 * curve. Up to four child elements under a single
 * `<g class="kntnt-gpx-blocks-elevation-cursor">` group:
 *
 *   - `<rect>` — invisible hit-target sized to the plot rectangle. Catches
 *     every pointer event and is never display-toggled.
 *   - `<line>` — vertical guide from `(cx, cy)` down to `(cx, plotBottom)`.
 *     Optional — created only when `showVerticalGuide` is on.
 *   - `<line>` — horizontal guide from `(cx, cy)` across to `(plotLeft, cy)`.
 *     Optional — created only when `showHorizontalGuide` is on.
 *   - `<circle>` — anchor on the curve at the interpolated sample.
 *
 * The hit-rect and dot always exist when the cursor `<g>` is built;
 * issue #144 lets the editor opt each guide line in or out independently
 * through the `Cursor & guides` Inspector panel.
 *
 * The visible elements carry the SVG `display="none"` attribute at
 * create time and {@link applyCursorPosition} removes it on the first
 * non-null fraction. The choice of the SVG `display` attribute (rather
 * than CSS `display: none`) keeps every cursor write inside the same
 * `setAttribute` API and immune to stray editor stylesheet rules.
 *
 * Insertion order inside the group is hit-rect → vertical guide →
 * horizontal guide → dot, so the dot visually covers the two guides'
 * shared endpoint at `(cx, cy)`. When a guide is gated off the order
 * of the remaining elements is preserved by simply skipping its
 * insertion.
 *
 * @since 1.0.0
 */
import type { ChartScale } from './geometry/scale';
import type { ProjectedCursor } from './geometry/cursor';

/**
 * SVG namespace constant.
 *
 * @since 1.0.0
 */
const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * The `var()` expression that pipes the `cursorColor` block attribute
 * into every coloured cursor surface. Both the SCSS default
 * (`#d63638`) and the inline custom property emitted by
 * `Render_Elevation::build_inline_style()` and `ElevationEdit`'s
 * `inlineStyle` builder land on this CSS variable.
 *
 * @since 1.0.0
 */
const CURSOR_COLOUR = 'var(--kntnt-gpx-blocks-elevation-cursor)';

/**
 * References to the child elements that compose the cursor. The
 * hit-rect and dot are always present; the two guide lines are present
 * only when their respective toggles were on at creation time. Held
 * by `view.ts` for the lifetime of the mount; the cursor is created
 * once on the first redraw and repositioned forever.
 *
 * @since 1.0.0
 */
export interface CursorElements {
	readonly hitRect: SVGRectElement;
	readonly dot: SVGCircleElement;
	readonly verticalGuide: SVGLineElement | null;
	readonly horizontalGuide: SVGLineElement | null;
}

/**
 * Per-guide toggles consumed by {@link createCursorElements}. The
 * caller threads these from the block attributes via the Interactivity
 * context (frontend) or as props (editor preview).
 *
 * @since 1.0.0
 */
export interface CursorGuideOptions {
	readonly showVerticalGuide: boolean;
	readonly showHorizontalGuide: boolean;
}

/**
 * Creates an SVG element with the supplied attributes.
 *
 * @since 1.0.0
 *
 * @param tag        SVG tag name (`'g'`, `'rect'`, `'line'`, `'circle'`).
 * @param attributes Attribute key/value pairs to set on the element.
 * @return The created element.
 */
function createSvg< T extends SVGElement >(
	tag: string,
	attributes: Record< string, string >
): T {
	const node = document.createElementNS( SVG_NS, tag );
	for ( const [ name, value ] of Object.entries( attributes ) ) {
		node.setAttribute( name, value );
	}
	return node as unknown as T;
}

/**
 * Builds the cursor `<g>` plus its children and appends the group
 * to the SVG host.
 *
 * The hit-rect, vertical guide (when enabled), horizontal guide (when
 * enabled), and dot are inserted in that order so SVG paints the dot
 * last (covering the two guides' shared endpoint at `(cx, cy)`). Each
 * guide is created only when its respective toggle is on; the dot and
 * hit-rect are always created. The visible elements carry
 * `display="none"` at create time; {@link applyCursorPosition} removes
 * the attribute on the first non-null fraction.
 *
 * @since 1.0.0
 *
 * @param svg     The chart's SVG host.
 * @param scale   Current {@link ChartScale}; supplies the plot rectangle
 *                the hit-rect snaps to.
 * @param options Per-guide visibility toggles.
 * @return References to the child elements (guides are `null` when
 *         their toggle was off).
 */
export function createCursorElements(
	svg: SVGSVGElement,
	scale: ChartScale,
	options: CursorGuideOptions
): CursorElements {
	// Group all elements under a single class-named <g> so SCSS rules
	// and the editor's inspector colour can target them as a unit.
	const group = createSvg< SVGGElement >( 'g', {
		class: 'kntnt-gpx-blocks-elevation-cursor',
	} );

	// Invisible hit-rect sized to the plot rectangle. The transparent
	// fill keeps SVG hit-testing engaged on the rect (default fill on
	// SVG primitives is opaque black, which would visually cover the
	// chart). The hit-rect is never display-toggled — see hideCursor /
	// showCursor below.
	const hitRect = createSvg< SVGRectElement >( 'rect', {
		class: 'kntnt-gpx-blocks-elevation-cursor-hitarea',
		x: String( scale.plotLeft ),
		y: String( scale.plotTop ),
		width: String( scale.plotRight - scale.plotLeft ),
		height: String( scale.plotBottom - scale.plotTop ),
		fill: 'transparent',
	} );

	// Vertical guide from the curve down to the X axis. Coordinates are
	// rewritten on every applyCursorPosition; the create-time defaults
	// place the line at the plot rectangle's bottom-left corner so the
	// element is well-formed even before the first position write. Only
	// instantiated when the editor enabled the `Vertical guide` toggle.
	const verticalGuide = options.showVerticalGuide
		? createSvg< SVGLineElement >( 'line', {
				class: 'kntnt-gpx-blocks-elevation-cursor-guide-v',
				x1: String( scale.plotLeft ),
				y1: String( scale.plotBottom ),
				x2: String( scale.plotLeft ),
				y2: String( scale.plotBottom ),
				stroke: CURSOR_COLOUR,
				'stroke-width': '1',
				display: 'none',
		  } )
		: null;

	// Horizontal guide from the curve across to the Y axis. Same gating
	// as the vertical guide above.
	const horizontalGuide = options.showHorizontalGuide
		? createSvg< SVGLineElement >( 'line', {
				class: 'kntnt-gpx-blocks-elevation-cursor-guide-h',
				x1: String( scale.plotLeft ),
				y1: String( scale.plotBottom ),
				x2: String( scale.plotLeft ),
				y2: String( scale.plotBottom ),
				stroke: CURSOR_COLOUR,
				'stroke-width': '1',
				display: 'none',
		  } )
		: null;

	// Dot anchored to the curve. Stroke + fill share the same colour so
	// the dot reads as a solid disc rather than a ring; stroke-width=2
	// gives the disc a touch of visual weight against the curve.
	const dot = createSvg< SVGCircleElement >( 'circle', {
		class: 'kntnt-gpx-blocks-elevation-cursor-dot',
		cx: String( scale.plotLeft ),
		cy: String( scale.plotBottom ),
		r: '6',
		fill: CURSOR_COLOUR,
		stroke: CURSOR_COLOUR,
		'stroke-width': '2',
		display: 'none',
	} );

	// Insert children in the documented order, skipping any guide that
	// was gated off. The dot always comes last so it paints over the
	// two guides' shared endpoint when both are present.
	group.appendChild( hitRect );
	if ( verticalGuide ) {
		group.appendChild( verticalGuide );
	}
	if ( horizontalGuide ) {
		group.appendChild( horizontalGuide );
	}
	group.appendChild( dot );
	svg.appendChild( group );

	return { hitRect, dot, verticalGuide, horizontalGuide };
}

/**
 * Updates the hit-rect's geometry from the supplied scale.
 *
 * Called after every redraw so the rect tracks the current plot
 * rectangle (the rectangle moves with resize and with margin-recompute
 * triggers). The visible elements are not touched here — their
 * positions are driven by the watch callback through
 * {@link applyCursorPosition}.
 *
 * @since 1.0.0
 *
 * @param elements Cursor element references.
 * @param scale    Current {@link ChartScale}.
 */
export function updateHitRect(
	elements: CursorElements,
	scale: ChartScale
): void {
	elements.hitRect.setAttribute( 'x', String( scale.plotLeft ) );
	elements.hitRect.setAttribute( 'y', String( scale.plotTop ) );
	elements.hitRect.setAttribute(
		'width',
		String( scale.plotRight - scale.plotLeft )
	);
	elements.hitRect.setAttribute(
		'height',
		String( scale.plotBottom - scale.plotTop )
	);
}

/**
 * Repositions the cursor's dot and any present guide lines, and
 * unhides them.
 *
 * Vertical guide (when present) goes from `(cx, cy)` down to
 * `(cx, scale.plotBottom)`; horizontal guide (when present) goes from
 * `(cx, cy)` across to `(scale.plotLeft, cy)`; the dot's centre is
 * `(cx, cy)`. The visible elements have any pre-existing
 * `display="none"` attribute removed so the cursor becomes visible —
 * `hideCursor` reapplies it when the fraction transitions to `null`.
 * Writes only to elements that exist so a guide gated off by its
 * toggle stays absent.
 *
 * @since 1.0.0
 *
 * @param elements  Cursor element references.
 * @param projected SVG-space coordinates of the cursor anchor.
 * @param scale     Current {@link ChartScale}; supplies the plot
 *                  rectangle's left and bottom edges that the guide
 *                  lines anchor on.
 */
export function applyCursorPosition(
	elements: CursorElements,
	projected: ProjectedCursor,
	scale: ChartScale
): void {
	const { cx, cy } = projected;
	const cxStr = String( cx );
	const cyStr = String( cy );

	// Dot centre.
	elements.dot.setAttribute( 'cx', cxStr );
	elements.dot.setAttribute( 'cy', cyStr );

	// Vertical guide: (cx, cy) → (cx, plotBottom). Skipped when the
	// editor turned the toggle off.
	if ( elements.verticalGuide ) {
		elements.verticalGuide.setAttribute( 'x1', cxStr );
		elements.verticalGuide.setAttribute( 'y1', cyStr );
		elements.verticalGuide.setAttribute( 'x2', cxStr );
		elements.verticalGuide.setAttribute( 'y2', String( scale.plotBottom ) );
	}

	// Horizontal guide: (cx, cy) → (plotLeft, cy). Same gating as the
	// vertical guide above.
	if ( elements.horizontalGuide ) {
		elements.horizontalGuide.setAttribute( 'x1', cxStr );
		elements.horizontalGuide.setAttribute( 'y1', cyStr );
		elements.horizontalGuide.setAttribute( 'x2', String( scale.plotLeft ) );
		elements.horizontalGuide.setAttribute( 'y2', cyStr );
	}

	showCursor( elements );
}

/**
 * Hides the cursor's visible elements by reapplying `display="none"`.
 *
 * The hit-rect is never display-toggled — toggling it would suppress
 * pointer events and the cursor would stop being draggable. Writes
 * only to elements that exist, so a guide gated off by its toggle is
 * silently skipped.
 *
 * @since 1.0.0
 *
 * @param elements Cursor element references.
 */
export function hideCursor( elements: CursorElements ): void {
	elements.dot.setAttribute( 'display', 'none' );
	elements.verticalGuide?.setAttribute( 'display', 'none' );
	elements.horizontalGuide?.setAttribute( 'display', 'none' );
}

/**
 * Removes `display="none"` from the visible elements. Idempotent: a
 * call against already-visible elements is a no-op. Writes only to
 * elements that exist, so a guide gated off by its toggle is silently
 * skipped.
 *
 * @since 1.0.0
 *
 * @param elements Cursor element references.
 */
export function showCursor( elements: CursorElements ): void {
	elements.dot.removeAttribute( 'display' );
	elements.verticalGuide?.removeAttribute( 'display' );
	elements.horizontalGuide?.removeAttribute( 'display' );
}
