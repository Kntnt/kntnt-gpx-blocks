/**
 * SVG-based text measurer for the elevation chart.
 *
 * The chart's margin algorithm reasons about the *rendered* width and
 * height of the eventual tick labels. Doing that reliably means
 * measuring real text under the same rendering pipeline that will
 * paint the labels — SVG's, not HTML's, because the labels are SVG
 * `<text>` nodes. HTML-vs-SVG metrics differ subtly (line-height
 * handling, sub-pixel rounding, fallback-font selection), so sharing
 * the pipeline avoids margin drift.
 *
 * Typography is applied through CSS inheritance from the host SVG.
 * `style.scss` declares the eight `font-*` / `letter-spacing` /
 * `text-*` properties on `.kntnt-gpx-blocks-elevation-chart-svg`,
 * sourcing them from the eight `--kntnt-gpx-blocks-elevation-tick-label-*`
 * custom properties that PHP `Render_Elevation::build_inline_style()`
 * and the editor's `inlineStyle` builder emit on the wrapper. Both
 * the visible tick `<text>` labels (nested inside `<g>` groups) and
 * the measurer's hidden `<text>` nodes (direct SVG children) inherit
 * from the same declarations, which is what guarantees the margin
 * algorithm measures under exactly the typography the user sees.
 *
 * The measurer therefore takes a single argument — the text to
 * measure — and inserts a hidden `<text>` node whose only role is to
 * give `getBBox()` a real geometry to read:
 *
 *   1. A hidden `<text>` node is inserted as a direct child of the
 *      SVG.
 *   2. `getBBox()` reads the width and height.
 *   3. `getComputedStyle(node).fontSize` reads the resolved CSS
 *      font-size in pixels — that value is the `em` base the margin
 *      formulas use.
 *   4. The node is removed.
 *
 * The DOM round-trip is intentional. SVG `getBBox()` is the only
 * cross-browser primitive that returns the exact rendered geometry
 * for an SVG `<text>` node; computing the same metric from font
 * metrics + character widths would require duplicating each font's
 * metrics on the client side, which is exactly the brittleness this
 * module exists to avoid.
 *
 * @since 1.0.0
 */

/**
 * Subset of the Tick-labels typography that the editor's inspector
 * surfaces. Kept here purely as a structural type for the `<Chart>`
 * component's prop — the measurer itself does not consume it any
 * more (see the module header). Each field corresponds to one of the
 * eight CSS custom properties the wrapper emits and the SVG's SCSS
 * rule consumes.
 *
 * @since 1.0.0
 */
export interface TypographyAttributes {
	readonly fontFamily?: string;
	readonly fontSize?: string;
	readonly fontWeight?: string;
	readonly fontStyle?: string;
	readonly lineHeight?: string;
	readonly letterSpacing?: string;
	readonly textTransform?: string;
	readonly textDecoration?: string;
}

/**
 * One measurement result.
 *
 * `width` and `height` come from `SVGGraphicsElement.getBBox()` and
 * are in SVG user units (which equal CSS pixels under the chart's
 * 1:1 viewBox-to-rect mapping). `topOffset` is the signed offset
 * from the text element's `y` attribute (the alphabetic baseline) to
 * the bbox top — typically negative because the bbox sits above the
 * baseline. Step 7 pl.2 added this field so tooltip rows can be
 * positioned by their *visual* top/bottom rather than by their
 * baseline plus an assumed full-ascent: in Chrome/Blink the bbox
 * height returned for SVG text reaches roughly the font's full
 * ascender/descender extent even when the rendered glyphs (digits,
 * "km", "m") have no descenders, so a formula that infers the visual
 * top as `baseline - height` places digit-only text too low in the
 * tooltip rect. With `topOffset`, callers can position the bbox top
 * directly. `fontSize` is the resolved CSS `font-size` in pixels and
 * is the `em` base the margin algorithm uses for its `0.5em` padding
 * term.
 *
 * @since 1.0.0
 */
export interface TextMeasurement {
	readonly width: number;
	readonly height: number;
	readonly topOffset: number;
	readonly fontSize: number;
}

/**
 * Synchronous measurement callback returned by
 * {@link createTextMeasurer}.
 *
 * @since 1.0.0
 */
export type TextMeasurer = ( text: string ) => TextMeasurement;

/**
 * SVG namespace constant. Inlined here so the module has no other
 * imports.
 *
 * @since 1.0.0
 */
const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * Pixel font-size fallback used when `getComputedStyle` cannot resolve
 * the SVG text node's font-size (typically inside test environments
 * where layout has not run).
 *
 * @since 1.0.0
 */
const FALLBACK_FONT_SIZE_PX = 16;

/**
 * Creates a measurer bound to the supplied SVG host.
 *
 * The returned closure is safe to call repeatedly; each call inserts
 * a fresh hidden `<text>` node, measures it, and removes it. The
 * host SVG is never permanently mutated.
 *
 * When `className` is supplied, the hidden `<text>` node carries that
 * class so SCSS rules scoped to the class apply during measurement.
 * The tooltip uses this to measure its two rows under their
 * class-scoped typography custom properties (`…-tooltip-distance` /
 * `…-tooltip-height`) rather than under the SVG host's inherited
 * tick-label typography. Tick-label call sites pass no class and
 * inherit the SVG host's typography.
 *
 * @since 1.0.0
 *
 * @param svg       Host SVG element. The measurement node is inserted
 *                  here so it inherits the same typography pipeline the
 *                  visible `<text>` labels render under.
 * @param className Optional class name applied to the hidden `<text>`
 *                  node so class-scoped SCSS rules apply during
 *                  measurement. Omit to inherit the SVG host's
 *                  typography (the existing tick-label path).
 * @return Synchronous measurement callback.
 */
export function createTextMeasurer(
	svg: SVGSVGElement,
	className?: string
): TextMeasurer {
	return ( text: string ): TextMeasurement => {
		// Build a hidden, off-screen <text> node as a direct SVG
		// child. Negative coordinates keep it visually out of the
		// chart even if the SVG is briefly painted before removal.
		// `aria-hidden` keeps assistive tech from announcing the
		// measurement string.
		const measurementY = -10000;
		const node = document.createElementNS(
			SVG_NS,
			'text'
		) as SVGTextElement;
		node.setAttribute( 'x', '-10000' );
		node.setAttribute( 'y', String( measurementY ) );
		node.setAttribute( 'aria-hidden', 'true' );
		if ( typeof className === 'string' && className !== '' ) {
			node.setAttribute( 'class', className );
		}
		node.textContent = text;
		svg.appendChild( node );

		// `getBBox()` is the only cross-browser primitive that reports
		// the *rendered* dimensions of an SVG text node; `getComputedStyle`
		// resolves the inherited font-size into the pixel value the
		// margin algorithm uses as its `em` base. `topOffset` records
		// where the bbox top sits relative to the text's baseline so
		// callers can position the bbox precisely (Step 7 pl.2).
		const bbox = node.getBBox();
		const topOffset = bbox.y - measurementY;
		const resolved = window
			.getComputedStyle( node )
			.getPropertyValue( 'font-size' );
		const fontSize = Number.parseFloat( resolved );

		svg.removeChild( node );

		return {
			width: bbox.width,
			height: bbox.height,
			topOffset,
			fontSize:
				Number.isFinite( fontSize ) && fontSize > 0
					? fontSize
					: FALLBACK_FONT_SIZE_PX,
		};
	};
}
