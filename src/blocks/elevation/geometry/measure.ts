/**
 * SVG-based text measurer for the elevation chart.
 *
 * The chart's margin algorithm reasons about the *rendered* width and
 * height of the eventual tick labels. Doing that reliably means
 * measuring real text under the same rendering pipeline that will
 * paint the labels — SVG's, not HTML's, because the labels are SVG
 * `<text>` nodes in Step 4. HTML-vs-SVG metrics differ subtly (line-
 * height handling, sub-pixel rounding, fallback-font selection), so
 * sharing the pipeline avoids margin drift.
 *
 * The measurer is created against an existing `<svg>` element and
 * returns a closure that synchronously measures one string + one
 * typography bundle at a time:
 *
 *   1. A hidden `<text>` node is inserted with the typography applied
 *      inline.
 *   2. `getBBox()` reads the width and height.
 *   3. `getComputedStyle(node).fontSize` reads the resolved CSS
 *      font-size in pixels — that value is the `em` base the margin
 *      formulas use.
 *   4. The node is removed.
 *
 * The DOM round-trip is intentional. SVG `getBBox()` is the only
 * cross-browser primitive that returns the exact rendered geometry for
 * an SVG `<text>` node; computing the same metric from font metrics +
 * character widths would require duplicating each font's metrics on
 * the client side, which is exactly the brittleness the Step 3
 * grilling decided against (see *Rendering architecture* in
 * `docs/elevation-rebuild.md`).
 *
 * @since 1.0.0
 */

/**
 * Subset of the Tick-labels typography that affects measurement.
 *
 * Every field is optional; missing fields fall through to the SVG's
 * inherited typography, which is the editor-chosen value on the
 * wrapper. Keys mirror the corresponding CSS properties so the
 * measurer can apply them with a one-to-one `style.X = value`
 * assignment.
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
 * 1:1 viewBox-to-rect mapping). `fontSize` is the resolved CSS
 * `font-size` in pixels and is the `em` base the margin algorithm
 * uses for its `0.5em` padding term.
 *
 * @since 1.0.0
 */
export interface TextMeasurement {
	readonly width: number;
	readonly height: number;
	readonly fontSize: number;
}

/**
 * Synchronous measurement callback returned by
 * {@link createTextMeasurer}.
 *
 * @since 1.0.0
 */
export type TextMeasurer = (
	text: string,
	typography: TypographyAttributes
) => TextMeasurement;

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
 * Applies a {@link TypographyAttributes} bundle to an SVG `<text>`
 * node. Exposed for unit tests so the typography-application code
 * path can be exercised independently of `getBBox`.
 *
 * @since 1.0.0
 *
 * @param node       The SVG text node to mutate.
 * @param typography Typography fields to apply.
 */
export function applyTypography(
	node: SVGTextElement,
	typography: TypographyAttributes
): void {
	if ( typography.fontFamily ) {
		node.style.fontFamily = typography.fontFamily;
	}
	if ( typography.fontSize ) {
		node.style.fontSize = typography.fontSize;
	}
	if ( typography.fontWeight ) {
		node.style.fontWeight = typography.fontWeight;
	}
	if ( typography.fontStyle ) {
		node.style.fontStyle = typography.fontStyle;
	}
	if ( typography.lineHeight ) {
		node.style.lineHeight = typography.lineHeight;
	}
	if ( typography.letterSpacing ) {
		node.style.letterSpacing = typography.letterSpacing;
	}
	if ( typography.textTransform ) {
		node.style.textTransform = typography.textTransform;
	}
	if ( typography.textDecoration ) {
		node.style.textDecoration = typography.textDecoration;
	}
}

/**
 * Creates a measurer bound to the supplied SVG host.
 *
 * The returned closure is safe to call repeatedly; each call inserts a
 * fresh hidden `<text>` node, measures it, and removes it. The host
 * SVG is never permanently mutated.
 *
 * @since 1.0.0
 *
 * @param svg Host SVG element. The measurement node is inserted here
 *            so it inherits the same typography pipeline the eventual
 *            visible labels will use.
 * @return Synchronous measurement callback.
 */
export function createTextMeasurer( svg: SVGSVGElement ): TextMeasurer {
	return (
		text: string,
		typography: TypographyAttributes
	): TextMeasurement => {
		// Build a hidden, off-screen <text> node. Negative coordinates
		// keep it visually out of the chart even if the SVG is briefly
		// painted before removal. `aria-hidden` keeps assistive tech
		// from announcing the measurement string.
		const node = document.createElementNS(
			SVG_NS,
			'text'
		) as SVGTextElement;
		node.setAttribute( 'x', '-10000' );
		node.setAttribute( 'y', '-10000' );
		node.setAttribute( 'aria-hidden', 'true' );
		applyTypography( node, typography );
		node.textContent = text;
		svg.appendChild( node );

		// `getBBox()` is the only cross-browser primitive that reports
		// the *rendered* dimensions of an SVG text node; `getComputedStyle`
		// resolves the inherited font-size into the pixel value the
		// margin algorithm uses as its `em` base.
		const bbox = node.getBBox();
		const resolved = window
			.getComputedStyle( node )
			.getPropertyValue( 'font-size' );
		const fontSize = Number.parseFloat( resolved );

		svg.removeChild( node );

		return {
			width: bbox.width,
			height: bbox.height,
			fontSize:
				Number.isFinite( fontSize ) && fontSize > 0
					? fontSize
					: FALLBACK_FONT_SIZE_PX,
		};
	};
}
