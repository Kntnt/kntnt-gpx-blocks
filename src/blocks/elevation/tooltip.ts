/**
 * SVG-DOM helpers for the elevation chart's tooltip.
 *
 * The tooltip lives inside the chart SVG as a sibling `<g>` of the
 * cursor group, so it shares the chart's coordinate system with the
 * cursor and the curve. Up to four child elements under a single
 * `<g class="kntnt-gpx-blocks-elevation-tooltip">` group:
 *
 *   - `<title>` — accessibility label that screen-readers can reach
 *     through hover-traversal or programmatic focus.
 *   - `<rect>` — background of the tooltip; uses `rx="0.25em"` for soft
 *     corners. Visible elements (rect + visible texts) carry
 *     `display="none"` at create time; {@link applyTooltipPosition}
 *     removes the attribute on the first non-null fraction.
 *   - `<text>` — distance row, present only when `showDistance` is on.
 *   - `<text>` — elevation row, present only when `showHeight` is on.
 *
 * Insertion order is `<title>` → `<rect>` → distance `<text>` →
 * height `<text>` so SVG painting order puts the rows on top of the
 * background, with the distance row above the height row in document
 * order (matching the visual top-to-bottom layout).
 *
 * The whole group carries `pointer-events="none"` so the tooltip never
 * blocks the hit-rect that drives the cursor scrub.
 *
 * @since 1.0.0
 */

/**
 * SVG namespace constant.
 *
 * @since 1.0.0
 */
const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * References to the child elements that compose the tooltip. The
 * `<title>` and `<rect>` are always present when the tooltip `<g>` is
 * built; the two `<text>` rows are present only when their respective
 * toggle was on at creation time. Held by `view.ts` for the lifetime
 * of the mount; the tooltip is created once on the first redraw and
 * repositioned + retyped forever.
 *
 * @since 1.0.0
 */
export interface TooltipElements {
	readonly group: SVGGElement;
	readonly title: SVGTitleElement;
	readonly rect: SVGRectElement;
	readonly distance: SVGTextElement | null;
	readonly height: SVGTextElement | null;
}

/**
 * Per-row visibility toggles consumed by {@link createTooltipElements}.
 * The caller threads these from the block attributes via the
 * Interactivity context (frontend) or as props (editor preview).
 *
 * @since 1.0.0
 */
export interface TooltipCreateOptions {
	readonly showDistance: boolean;
	readonly showHeight: boolean;
}

/**
 * Per-frame layout passed to {@link applyTooltipPosition}.
 *
 * The eight coordinate fields plus the two label strings plus the a11y
 * label fully define the rendered tooltip; the helper writes each onto
 * the matching DOM attribute or `textContent` (guarded by `!==` to keep
 * the DOM quiet between identical updates).
 *
 * @since 1.0.0
 */
export interface TooltipLayout {
	readonly rectX: number;
	readonly rectY: number;
	readonly rectWidth: number;
	readonly rectHeight: number;
	readonly distanceTextX: number;
	readonly distanceTextY: number;
	readonly heightTextX: number;
	readonly heightTextY: number;
	readonly distanceLabel: string;
	readonly heightLabel: string;
	readonly a11yLabel: string;
}

/**
 * Creates an SVG element with the supplied attributes set.
 *
 * @since 1.0.0
 *
 * @param tag        SVG tag name (`'g'`, `'rect'`, `'text'`, …).
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
 * Builds the tooltip `<g>` plus its children and appends the group to
 * the SVG host.
 *
 * `<title>` is first so screen-readers traverse into it before the rect
 * and rows; `<rect>` is next so the rows paint on top of it; the two
 * `<text>` rows are last, in distance-then-height order so document
 * order matches their top-to-bottom visual layout. Each row is only
 * created when its toggle is on. Visible elements (rect + visible rows)
 * carry `display="none"` at create time so the tooltip is invisible
 * until {@link applyTooltipPosition} runs.
 *
 * The whole group carries `pointer-events="none"` so the tooltip
 * cannot intercept events that should reach the hit-rect underneath.
 *
 * @since 1.0.0
 *
 * @param svg     The chart's SVG host.
 * @param options Per-row visibility toggles.
 * @return References to the child elements (rows are `null` when their
 *         toggle was off).
 */
export function createTooltipElements(
	svg: SVGSVGElement,
	options: TooltipCreateOptions
): TooltipElements {
	// Group every element under a single class-named <g> so SCSS rules
	// and any future inspector tooling can target them as a unit.
	// `pointer-events: none` keeps the tooltip from blocking the
	// hit-rect that drives the cursor scrub.
	const group = createSvg< SVGGElement >( 'g', {
		class: 'kntnt-gpx-blocks-elevation-tooltip',
		'pointer-events': 'none',
	} );

	// Screen-reader access label, rebuilt on every fraction update so
	// hover-traversal sees a fresh value. Empty at create time; the
	// first applyTooltipPosition writes the real string.
	const title = document.createElementNS(
		SVG_NS,
		'title'
	) as SVGTitleElement;
	group.appendChild( title );

	// Background rectangle. `rx="0.25em"` rounds the corners; visible
	// elements carry `display="none"` so the tooltip stays hidden until
	// the first non-null fraction.
	const rect = createSvg< SVGRectElement >( 'rect', {
		class: 'kntnt-gpx-blocks-elevation-tooltip-bg',
		x: '0',
		y: '0',
		width: '0',
		height: '0',
		rx: '0.25em',
		fill: 'var(--kntnt-gpx-blocks-elevation-tooltip-background)',
		display: 'none',
	} );
	group.appendChild( rect );

	// Distance row. Created only when `showDistance` is on; absent rows
	// are `null` in the returned references and the SVG never grows the
	// corresponding `<text>` element.
	const distance = options.showDistance
		? createSvg< SVGTextElement >( 'text', {
				class: 'kntnt-gpx-blocks-elevation-tooltip-distance',
				x: '0',
				y: '0',
				'text-anchor': 'start',
				fill: 'var(--kntnt-gpx-blocks-elevation-tooltip-distance)',
				display: 'none',
		  } )
		: null;
	if ( distance ) {
		group.appendChild( distance );
	}

	// Elevation row. Same per-row gating as the distance row above.
	const height = options.showHeight
		? createSvg< SVGTextElement >( 'text', {
				class: 'kntnt-gpx-blocks-elevation-tooltip-height',
				x: '0',
				y: '0',
				'text-anchor': 'start',
				fill: 'var(--kntnt-gpx-blocks-elevation-tooltip-height)',
				display: 'none',
		  } )
		: null;
	if ( height ) {
		group.appendChild( height );
	}

	svg.appendChild( group );

	return { group, title, rect, distance, height };
}

/**
 * Writes a string attribute onto an element only when the current
 * value differs. SVG attribute setters do not short-circuit by
 * themselves; this guard keeps the DOM quiet on identical writes so
 * Safari can skip layout invalidations during a scrub.
 *
 * @since 1.0.0
 *
 * @param element The element to update.
 * @param name    The attribute name.
 * @param value   The new value.
 */
function setIfChanged( element: Element, name: string, value: string ): void {
	if ( element.getAttribute( name ) !== value ) {
		element.setAttribute( name, value );
	}
}

/**
 * Writes a `textContent` onto a node only when the current value
 * differs. Same rationale as {@link setIfChanged}; the equality guard
 * keeps the DOM quiet on identical updates.
 *
 * @since 1.0.0
 *
 * @param node  The text-bearing node.
 * @param value The new text content.
 */
function setTextIfChanged( node: Node, value: string ): void {
	if ( node.textContent !== value ) {
		node.textContent = value;
	}
}

/**
 * Repositions the tooltip's background and visible rows, retypes their
 * `textContent`, and unhides them.
 *
 * Per-element writes are guarded by `!==` equality checks so an
 * identical update path (the same fraction → the same labels and
 * coordinates) leaves the DOM untouched. Writes only to elements that
 * exist so a row gated off by its toggle stays absent.
 *
 * @since 1.0.0
 *
 * @param elements Tooltip element references.
 * @param layout   The per-frame layout (geometry + labels + a11y).
 */
export function applyTooltipPosition(
	elements: TooltipElements,
	layout: TooltipLayout
): void {
	// Background rectangle: position + size, then unhide.
	setIfChanged( elements.rect, 'x', String( layout.rectX ) );
	setIfChanged( elements.rect, 'y', String( layout.rectY ) );
	setIfChanged( elements.rect, 'width', String( layout.rectWidth ) );
	setIfChanged( elements.rect, 'height', String( layout.rectHeight ) );
	if ( elements.rect.getAttribute( 'display' ) === 'none' ) {
		elements.rect.removeAttribute( 'display' );
	}

	// Distance row: position + text, then unhide. Skipped when the row
	// was gated off at create time.
	if ( elements.distance ) {
		setIfChanged( elements.distance, 'x', String( layout.distanceTextX ) );
		setIfChanged( elements.distance, 'y', String( layout.distanceTextY ) );
		setTextIfChanged( elements.distance, layout.distanceLabel );
		if ( elements.distance.getAttribute( 'display' ) === 'none' ) {
			elements.distance.removeAttribute( 'display' );
		}
	}

	// Elevation row: same shape as the distance row above.
	if ( elements.height ) {
		setIfChanged( elements.height, 'x', String( layout.heightTextX ) );
		setIfChanged( elements.height, 'y', String( layout.heightTextY ) );
		setTextIfChanged( elements.height, layout.heightLabel );
		if ( elements.height.getAttribute( 'display' ) === 'none' ) {
			elements.height.removeAttribute( 'display' );
		}
	}

	// Accessibility label, rebuilt every frame so SR-users who reach the
	// title via hover-traversal see a fresh read.
	setTextIfChanged( elements.title, layout.a11yLabel );
}

/**
 * Re-applies `display="none"` to the tooltip's visible elements.
 *
 * Idempotent — a call against already-hidden elements is a no-op. The
 * `<title>` and the `<g>` itself are not touched: hiding the group via
 * SVG `display` inheritance would also kill the title's discoverability,
 * which is not what the user-facing "no current fraction" state should
 * imply. Writes only to elements that exist.
 *
 * @since 1.0.0
 *
 * @param elements Tooltip element references.
 */
export function hideTooltip( elements: TooltipElements ): void {
	setIfChanged( elements.rect, 'display', 'none' );
	if ( elements.distance ) {
		setIfChanged( elements.distance, 'display', 'none' );
	}
	if ( elements.height ) {
		setIfChanged( elements.height, 'display', 'none' );
	}
}
