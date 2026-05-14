/**
 * Unit tests for the SVG-DOM helpers backing the elevation cursor.
 *
 * Step 6 of `docs/elevation-rebuild.md` puts the cursor inside the chart
 * SVG: a `<g>` group hosting an invisible hit-rect, two L-shape guide
 * lines, and a circle anchored to the curve. This file pins the
 * imperative DOM construction + position + visibility helpers; the pure
 * math (interpolation, projection) lives in `geometry/cursor.test.ts`.
 *
 * @since 1.0.0
 */
import type { ChartScale } from './geometry/scale';
import {
	createCursorElements,
	hideCursor,
	applyCursorPosition,
	showCursor,
	updateHitRect,
} from './cursor';

const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * Builds a `ChartScale` whose plot rectangle is hand-picked so each
 * attribute the helpers write has an obvious expected value.
 *
 * @since 1.0.0
 *
 * @param overrides Partial overrides for individual scale fields.
 * @return The merged scale.
 */
function buildScale( overrides: Partial< ChartScale > = {} ): ChartScale {
	const plotLeft = 50;
	const plotRight = 550;
	const plotTop = 10;
	const plotBottom = 190;
	return {
		distance: 1000,
		niceYMin: 0,
		niceYMax: 100,
		plotLeft,
		plotRight,
		plotTop,
		plotBottom,
		availX: plotRight - plotLeft,
		availY: plotBottom - plotTop,
		em: 16,
		projectX: ( d: number ): number =>
			plotLeft + ( d / 1000 ) * ( plotRight - plotLeft ),
		projectY: ( e: number ): number =>
			plotBottom - ( e / 100 ) * ( plotBottom - plotTop ),
		xTicks: [],
		yTicks: [],
		...overrides,
	};
}

/**
 * Creates a fresh, detached SVG element for each test.
 *
 * @since 1.0.0
 *
 * @return A blank `<svg>` element ready to receive cursor children.
 */
function makeSvg(): SVGSVGElement {
	return document.createElementNS( SVG_NS, 'svg' ) as SVGSVGElement;
}

describe( 'createCursorElements', () => {
	it( 'appends a single <g class="kntnt-gpx-blocks-elevation-cursor"> to the SVG', () => {
		const svg = makeSvg();
		createCursorElements( svg, buildScale() );
		const groups = svg.querySelectorAll(
			'g.kntnt-gpx-blocks-elevation-cursor'
		);
		expect( groups ).toHaveLength( 1 );
	} );

	it( 'returns references to the four child elements', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale() );
		expect( elements.hitRect.tagName.toLowerCase() ).toBe( 'rect' );
		expect( elements.dot.tagName.toLowerCase() ).toBe( 'circle' );
		expect( elements.verticalLine.tagName.toLowerCase() ).toBe( 'line' );
		expect( elements.horizontalLine.tagName.toLowerCase() ).toBe( 'line' );
	} );

	it( 'gives the three visible elements display="none" but leaves the hit-rect visible', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale() );
		expect( elements.dot.getAttribute( 'display' ) ).toBe( 'none' );
		expect( elements.verticalLine.getAttribute( 'display' ) ).toBe(
			'none'
		);
		expect( elements.horizontalLine.getAttribute( 'display' ) ).toBe(
			'none'
		);
		expect( elements.hitRect.getAttribute( 'display' ) ).toBeNull();
	} );

	it( 'sets the hit-rect geometry to the plot rectangle', () => {
		const svg = makeSvg();
		const scale = buildScale();
		const elements = createCursorElements( svg, scale );
		expect( elements.hitRect.getAttribute( 'x' ) ).toBe(
			String( scale.plotLeft )
		);
		expect( elements.hitRect.getAttribute( 'y' ) ).toBe(
			String( scale.plotTop )
		);
		expect( elements.hitRect.getAttribute( 'width' ) ).toBe(
			String( scale.plotRight - scale.plotLeft )
		);
		expect( elements.hitRect.getAttribute( 'height' ) ).toBe(
			String( scale.plotBottom - scale.plotTop )
		);
	} );

	it( 'gives the hit-rect a transparent fill so pointer events still hit', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale() );
		expect( elements.hitRect.getAttribute( 'fill' ) ).toBe( 'transparent' );
	} );

	it( 'classes the hit-rect, dot, and lines so SCSS can target them', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale() );
		expect( elements.hitRect.getAttribute( 'class' ) ).toBe(
			'kntnt-gpx-blocks-elevation-cursor-hitarea'
		);
		expect( elements.dot.getAttribute( 'class' ) ).toBe(
			'kntnt-gpx-blocks-elevation-cursor-dot'
		);
		expect( elements.verticalLine.getAttribute( 'class' ) ).toBe(
			'kntnt-gpx-blocks-elevation-cursor-line-v'
		);
		expect( elements.horizontalLine.getAttribute( 'class' ) ).toBe(
			'kntnt-gpx-blocks-elevation-cursor-line-h'
		);
	} );

	it( 'orders the children hit-rect → vertical line → horizontal line → dot inside the group', () => {
		const svg = makeSvg();
		createCursorElements( svg, buildScale() );
		const group = svg.querySelector(
			'g.kntnt-gpx-blocks-elevation-cursor'
		)!;
		const order = Array.from( group.children ).map(
			( el ) => el.getAttribute( 'class' ) ?? ''
		);
		expect( order ).toEqual( [
			'kntnt-gpx-blocks-elevation-cursor-hitarea',
			'kntnt-gpx-blocks-elevation-cursor-line-v',
			'kntnt-gpx-blocks-elevation-cursor-line-h',
			'kntnt-gpx-blocks-elevation-cursor-dot',
		] );
	} );

	it( 'styles the dot with r=6 and stroke-width=2; the lines with stroke-width=1', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale() );
		expect( elements.dot.getAttribute( 'r' ) ).toBe( '6' );
		expect( elements.dot.getAttribute( 'stroke-width' ) ).toBe( '2' );
		expect( elements.verticalLine.getAttribute( 'stroke-width' ) ).toBe(
			'1'
		);
		expect( elements.horizontalLine.getAttribute( 'stroke-width' ) ).toBe(
			'1'
		);
	} );

	it( 'wires the colour through var(--kntnt-gpx-blocks-elevation-cursor)', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale() );
		const colour = 'var(--kntnt-gpx-blocks-elevation-cursor)';
		expect( elements.dot.getAttribute( 'fill' ) ).toBe( colour );
		expect( elements.dot.getAttribute( 'stroke' ) ).toBe( colour );
		expect( elements.verticalLine.getAttribute( 'stroke' ) ).toBe( colour );
		expect( elements.horizontalLine.getAttribute( 'stroke' ) ).toBe(
			colour
		);
	} );
} );

describe( 'updateHitRect', () => {
	it( 'rewrites the hit-rect geometry from the supplied scale', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale() );
		const next = buildScale( {
			plotLeft: 80,
			plotRight: 600,
			plotTop: 20,
			plotBottom: 220,
		} );
		updateHitRect( elements, next );
		expect( elements.hitRect.getAttribute( 'x' ) ).toBe( '80' );
		expect( elements.hitRect.getAttribute( 'y' ) ).toBe( '20' );
		expect( elements.hitRect.getAttribute( 'width' ) ).toBe( '520' );
		expect( elements.hitRect.getAttribute( 'height' ) ).toBe( '200' );
	} );
} );

describe( 'applyCursorPosition', () => {
	it( 'sets the dot, vertical line, and horizontal line coordinates', () => {
		const svg = makeSvg();
		const scale = buildScale();
		const elements = createCursorElements( svg, scale );
		applyCursorPosition( elements, { cx: 123, cy: 45 }, scale );
		expect( elements.dot.getAttribute( 'cx' ) ).toBe( '123' );
		expect( elements.dot.getAttribute( 'cy' ) ).toBe( '45' );
		// Vertical line goes from (cx, cy) down to (cx, plotBottom).
		expect( elements.verticalLine.getAttribute( 'x1' ) ).toBe( '123' );
		expect( elements.verticalLine.getAttribute( 'y1' ) ).toBe( '45' );
		expect( elements.verticalLine.getAttribute( 'x2' ) ).toBe( '123' );
		expect( elements.verticalLine.getAttribute( 'y2' ) ).toBe(
			String( scale.plotBottom )
		);
		// Horizontal line goes from (cx, cy) across to (plotLeft, cy).
		expect( elements.horizontalLine.getAttribute( 'x1' ) ).toBe( '123' );
		expect( elements.horizontalLine.getAttribute( 'y1' ) ).toBe( '45' );
		expect( elements.horizontalLine.getAttribute( 'x2' ) ).toBe(
			String( scale.plotLeft )
		);
		expect( elements.horizontalLine.getAttribute( 'y2' ) ).toBe( '45' );
	} );

	it( 'removes the display="none" attribute on the three visible elements', () => {
		const svg = makeSvg();
		const scale = buildScale();
		const elements = createCursorElements( svg, scale );
		applyCursorPosition( elements, { cx: 100, cy: 50 }, scale );
		expect( elements.dot.getAttribute( 'display' ) ).toBeNull();
		expect( elements.verticalLine.getAttribute( 'display' ) ).toBeNull();
		expect( elements.horizontalLine.getAttribute( 'display' ) ).toBeNull();
		// Hit-rect is always visible — the helper must not touch it.
		expect( elements.hitRect.getAttribute( 'display' ) ).toBeNull();
	} );
} );

describe( 'hideCursor', () => {
	it( 'reapplies display="none" to the three visible elements', () => {
		const svg = makeSvg();
		const scale = buildScale();
		const elements = createCursorElements( svg, scale );
		applyCursorPosition( elements, { cx: 100, cy: 50 }, scale );
		hideCursor( elements );
		expect( elements.dot.getAttribute( 'display' ) ).toBe( 'none' );
		expect( elements.verticalLine.getAttribute( 'display' ) ).toBe(
			'none'
		);
		expect( elements.horizontalLine.getAttribute( 'display' ) ).toBe(
			'none'
		);
	} );

	it( 'leaves the hit-rect visible so pointer events keep firing', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale() );
		hideCursor( elements );
		expect( elements.hitRect.getAttribute( 'display' ) ).toBeNull();
	} );
} );

describe( 'showCursor', () => {
	it( 'removes display="none" from the three visible elements', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale() );
		showCursor( elements );
		expect( elements.dot.getAttribute( 'display' ) ).toBeNull();
		expect( elements.verticalLine.getAttribute( 'display' ) ).toBeNull();
		expect( elements.horizontalLine.getAttribute( 'display' ) ).toBeNull();
	} );

	it( 'is a no-op on already-visible elements', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale() );
		showCursor( elements );
		showCursor( elements );
		expect( elements.dot.getAttribute( 'display' ) ).toBeNull();
		expect( elements.verticalLine.getAttribute( 'display' ) ).toBeNull();
		expect( elements.horizontalLine.getAttribute( 'display' ) ).toBeNull();
	} );
} );
