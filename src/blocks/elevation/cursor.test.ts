/**
 * Unit tests for the SVG-DOM helpers backing the elevation cursor.
 *
 * The cursor lives inside the chart SVG as a `<g>` group hosting an
 * invisible hit-rect, up to two L-shape guide lines, and a circle
 * anchored to the curve. Issue #144 lets the
 * editor opt each guide line in or out independently through the
 * `Cursor & guides` Inspector panel — the hit-rect and dot always
 * exist when the cursor `<g>` is built. This file pins the imperative
 * DOM construction + position + visibility helpers; the pure math
 * (interpolation, projection) lives in `geometry/cursor.test.ts`.
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
	type CursorGuideOptions,
} from './cursor';

const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * Both guides on — the default for callers that do not care about the
 * per-guide gating in a given test.
 *
 * @since 1.0.0
 */
const BOTH_GUIDES: CursorGuideOptions = {
	showVerticalGuide: true,
	showHorizontalGuide: true,
};

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
		createCursorElements( svg, buildScale(), BOTH_GUIDES );
		const groups = svg.querySelectorAll(
			'g.kntnt-gpx-blocks-elevation-cursor'
		);
		expect( groups ).toHaveLength( 1 );
	} );

	it( 'returns the wrapping <g> as elements.group so view.ts can re-append it after every drawChart', () => {
		// The persistent cursor group has to stay at the END of the
		// SVG's children list, otherwise the curve repainted by
		// `drawChart` paints over the cursor. `view.ts` re-appends
		// `elements.group` after each redraw to push it back; this test
		// pins the reference exposure so a future refactor cannot drop
		// the field silently.
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale(), BOTH_GUIDES );
		expect( elements.group.tagName.toLowerCase() ).toBe( 'g' );
		expect( elements.group.getAttribute( 'class' ) ).toBe(
			'kntnt-gpx-blocks-elevation-cursor'
		);
		expect( elements.group.parentNode ).toBe( svg );
	} );

	it( 'returns references to the four child elements when both guides are enabled', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale(), BOTH_GUIDES );
		expect( elements.hitRect.tagName.toLowerCase() ).toBe( 'rect' );
		expect( elements.dot.tagName.toLowerCase() ).toBe( 'circle' );
		expect( elements.verticalGuide?.tagName.toLowerCase() ).toBe( 'line' );
		expect( elements.horizontalGuide?.tagName.toLowerCase() ).toBe(
			'line'
		);
	} );

	it( 'gives the three visible elements display="none" but leaves the hit-rect visible', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale(), BOTH_GUIDES );
		expect( elements.dot.getAttribute( 'display' ) ).toBe( 'none' );
		expect( elements.verticalGuide?.getAttribute( 'display' ) ).toBe(
			'none'
		);
		expect( elements.horizontalGuide?.getAttribute( 'display' ) ).toBe(
			'none'
		);
		expect( elements.hitRect.getAttribute( 'display' ) ).toBeNull();
	} );

	it( 'sets the hit-rect geometry to the plot rectangle', () => {
		const svg = makeSvg();
		const scale = buildScale();
		const elements = createCursorElements( svg, scale, BOTH_GUIDES );
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
		const elements = createCursorElements( svg, buildScale(), BOTH_GUIDES );
		expect( elements.hitRect.getAttribute( 'fill' ) ).toBe( 'transparent' );
	} );

	it( 'classes the hit-rect, dot, and guides so SCSS can target them', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale(), BOTH_GUIDES );
		expect( elements.hitRect.getAttribute( 'class' ) ).toBe(
			'kntnt-gpx-blocks-elevation-cursor-hitarea'
		);
		expect( elements.dot.getAttribute( 'class' ) ).toBe(
			'kntnt-gpx-blocks-elevation-cursor-dot'
		);
		expect( elements.verticalGuide?.getAttribute( 'class' ) ).toBe(
			'kntnt-gpx-blocks-elevation-cursor-guide-v'
		);
		expect( elements.horizontalGuide?.getAttribute( 'class' ) ).toBe(
			'kntnt-gpx-blocks-elevation-cursor-guide-h'
		);
	} );

	it( 'orders the children hit-rect → vertical guide → horizontal guide → dot inside the group', () => {
		const svg = makeSvg();
		createCursorElements( svg, buildScale(), BOTH_GUIDES );
		const group = svg.querySelector(
			'g.kntnt-gpx-blocks-elevation-cursor'
		)!;
		const order = Array.from( group.children ).map(
			( el ) => el.getAttribute( 'class' ) ?? ''
		);
		expect( order ).toEqual( [
			'kntnt-gpx-blocks-elevation-cursor-hitarea',
			'kntnt-gpx-blocks-elevation-cursor-guide-v',
			'kntnt-gpx-blocks-elevation-cursor-guide-h',
			'kntnt-gpx-blocks-elevation-cursor-dot',
		] );
	} );

	it( 'styles the dot with r=6 and stroke-width=2; the guides with stroke-width=1', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale(), BOTH_GUIDES );
		expect( elements.dot.getAttribute( 'r' ) ).toBe( '6' );
		expect( elements.dot.getAttribute( 'stroke-width' ) ).toBe( '2' );
		expect( elements.verticalGuide?.getAttribute( 'stroke-width' ) ).toBe(
			'1'
		);
		expect( elements.horizontalGuide?.getAttribute( 'stroke-width' ) ).toBe(
			'1'
		);
	} );

	it( 'wires the colour through var(--kntnt-gpx-blocks-elevation-cursor)', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale(), BOTH_GUIDES );
		const colour = 'var(--kntnt-gpx-blocks-elevation-cursor)';
		expect( elements.dot.getAttribute( 'fill' ) ).toBe( colour );
		expect( elements.dot.getAttribute( 'stroke' ) ).toBe( colour );
		expect( elements.verticalGuide?.getAttribute( 'stroke' ) ).toBe(
			colour
		);
		expect( elements.horizontalGuide?.getAttribute( 'stroke' ) ).toBe(
			colour
		);
	} );

	it( 'omits the vertical guide entirely when showVerticalGuide is false (issue #144)', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale(), {
			showVerticalGuide: false,
			showHorizontalGuide: true,
		} );
		expect( elements.verticalGuide ).toBeNull();
		expect(
			svg.querySelectorAll(
				'line.kntnt-gpx-blocks-elevation-cursor-guide-v'
			)
		).toHaveLength( 0 );
		// The horizontal guide still exists, and the dot + hit-rect are
		// always present when the cursor `<g>` is built.
		expect( elements.horizontalGuide ).not.toBeNull();
		expect( elements.dot ).not.toBeNull();
		expect( elements.hitRect ).not.toBeNull();
	} );

	it( 'omits the horizontal guide entirely when showHorizontalGuide is false (issue #144)', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale(), {
			showVerticalGuide: true,
			showHorizontalGuide: false,
		} );
		expect( elements.horizontalGuide ).toBeNull();
		expect(
			svg.querySelectorAll(
				'line.kntnt-gpx-blocks-elevation-cursor-guide-h'
			)
		).toHaveLength( 0 );
		expect( elements.verticalGuide ).not.toBeNull();
	} );

	it( 'creates only the hit-rect and the dot when both guides are off (issue #144)', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale(), {
			showVerticalGuide: false,
			showHorizontalGuide: false,
		} );
		expect( elements.verticalGuide ).toBeNull();
		expect( elements.horizontalGuide ).toBeNull();
		const group = svg.querySelector(
			'g.kntnt-gpx-blocks-elevation-cursor'
		)!;
		const order = Array.from( group.children ).map(
			( el ) => el.getAttribute( 'class' ) ?? ''
		);
		expect( order ).toEqual( [
			'kntnt-gpx-blocks-elevation-cursor-hitarea',
			'kntnt-gpx-blocks-elevation-cursor-dot',
		] );
	} );
} );

describe( 'updateHitRect', () => {
	it( 'rewrites the hit-rect geometry from the supplied scale', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale(), BOTH_GUIDES );
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
	it( 'sets the dot, vertical guide, and horizontal guide coordinates', () => {
		const svg = makeSvg();
		const scale = buildScale();
		const elements = createCursorElements( svg, scale, BOTH_GUIDES );
		applyCursorPosition( elements, { cx: 123, cy: 45 }, scale );
		expect( elements.dot.getAttribute( 'cx' ) ).toBe( '123' );
		expect( elements.dot.getAttribute( 'cy' ) ).toBe( '45' );
		// Vertical guide goes from (cx, cy) down to (cx, plotBottom).
		expect( elements.verticalGuide?.getAttribute( 'x1' ) ).toBe( '123' );
		expect( elements.verticalGuide?.getAttribute( 'y1' ) ).toBe( '45' );
		expect( elements.verticalGuide?.getAttribute( 'x2' ) ).toBe( '123' );
		expect( elements.verticalGuide?.getAttribute( 'y2' ) ).toBe(
			String( scale.plotBottom )
		);
		// Horizontal guide goes from (cx, cy) across to (plotLeft, cy).
		expect( elements.horizontalGuide?.getAttribute( 'x1' ) ).toBe( '123' );
		expect( elements.horizontalGuide?.getAttribute( 'y1' ) ).toBe( '45' );
		expect( elements.horizontalGuide?.getAttribute( 'x2' ) ).toBe(
			String( scale.plotLeft )
		);
		expect( elements.horizontalGuide?.getAttribute( 'y2' ) ).toBe( '45' );
	} );

	it( 'removes the display="none" attribute on the visible elements', () => {
		const svg = makeSvg();
		const scale = buildScale();
		const elements = createCursorElements( svg, scale, BOTH_GUIDES );
		applyCursorPosition( elements, { cx: 100, cy: 50 }, scale );
		expect( elements.dot.getAttribute( 'display' ) ).toBeNull();
		expect( elements.verticalGuide?.getAttribute( 'display' ) ).toBeNull();
		expect(
			elements.horizontalGuide?.getAttribute( 'display' )
		).toBeNull();
		// Hit-rect is always visible — the helper must not touch it.
		expect( elements.hitRect.getAttribute( 'display' ) ).toBeNull();
	} );

	it( 'writes only to elements that exist (issue #144)', () => {
		// With both guides gated off, the helper must not throw on its
		// null guide references — it should only update the dot.
		const svg = makeSvg();
		const scale = buildScale();
		const elements = createCursorElements( svg, scale, {
			showVerticalGuide: false,
			showHorizontalGuide: false,
		} );
		expect( () =>
			applyCursorPosition( elements, { cx: 100, cy: 50 }, scale )
		).not.toThrow();
		expect( elements.dot.getAttribute( 'cx' ) ).toBe( '100' );
		expect( elements.dot.getAttribute( 'cy' ) ).toBe( '50' );
		expect( elements.dot.getAttribute( 'display' ) ).toBeNull();
	} );
} );

describe( 'hideCursor', () => {
	it( 'reapplies display="none" to the visible elements', () => {
		const svg = makeSvg();
		const scale = buildScale();
		const elements = createCursorElements( svg, scale, BOTH_GUIDES );
		applyCursorPosition( elements, { cx: 100, cy: 50 }, scale );
		hideCursor( elements );
		expect( elements.dot.getAttribute( 'display' ) ).toBe( 'none' );
		expect( elements.verticalGuide?.getAttribute( 'display' ) ).toBe(
			'none'
		);
		expect( elements.horizontalGuide?.getAttribute( 'display' ) ).toBe(
			'none'
		);
	} );

	it( 'leaves the hit-rect visible so pointer events keep firing', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale(), BOTH_GUIDES );
		hideCursor( elements );
		expect( elements.hitRect.getAttribute( 'display' ) ).toBeNull();
	} );

	it( 'is a no-op on absent guide references (issue #144)', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale(), {
			showVerticalGuide: false,
			showHorizontalGuide: false,
		} );
		expect( () => hideCursor( elements ) ).not.toThrow();
		expect( elements.dot.getAttribute( 'display' ) ).toBe( 'none' );
	} );
} );

describe( 'showCursor', () => {
	it( 'removes display="none" from the visible elements', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale(), BOTH_GUIDES );
		showCursor( elements );
		expect( elements.dot.getAttribute( 'display' ) ).toBeNull();
		expect( elements.verticalGuide?.getAttribute( 'display' ) ).toBeNull();
		expect(
			elements.horizontalGuide?.getAttribute( 'display' )
		).toBeNull();
	} );

	it( 'is a no-op on already-visible elements', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale(), BOTH_GUIDES );
		showCursor( elements );
		showCursor( elements );
		expect( elements.dot.getAttribute( 'display' ) ).toBeNull();
		expect( elements.verticalGuide?.getAttribute( 'display' ) ).toBeNull();
		expect(
			elements.horizontalGuide?.getAttribute( 'display' )
		).toBeNull();
	} );

	it( 'is a no-op on absent guide references (issue #144)', () => {
		const svg = makeSvg();
		const elements = createCursorElements( svg, buildScale(), {
			showVerticalGuide: false,
			showHorizontalGuide: false,
		} );
		expect( () => showCursor( elements ) ).not.toThrow();
		expect( elements.dot.getAttribute( 'display' ) ).toBeNull();
	} );
} );
