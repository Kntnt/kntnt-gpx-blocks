/**
 * Unit tests for the SVG-DOM helpers backing the elevation tooltip.
 *
 * Step 7 of `docs/elevation-rebuild.md` puts the tooltip inside the chart
 * SVG: a `<g>` group hosting a `<title>`, a `<rect>` background, and up
 * to two `<text>` rows. This file pins the imperative DOM construction
 * + position + visibility helpers; the placement math lives in
 * `geometry/tooltip-placement.test.ts` and the formatting in
 * `geometry/tooltip-format.test.ts`.
 *
 * @since 1.0.0
 */
import {
	applyTooltipPosition,
	createTooltipElements,
	hideTooltip,
	type TooltipLayout,
} from './tooltip';

const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * Creates a fresh, detached SVG element for each test.
 *
 * @since 1.0.0
 *
 * @return A blank `<svg>` element ready to receive a tooltip group.
 */
function makeSvg(): SVGSVGElement {
	return document.createElementNS( SVG_NS, 'svg' ) as SVGSVGElement;
}

/**
 * Returns a fully-populated {@link TooltipLayout} so the per-test
 * boilerplate stays minimal. Tests override only the fields they
 * actually care about.
 *
 * @since 1.0.0
 *
 * @param overrides Per-field overrides.
 * @return The merged layout.
 */
function buildLayout(
	overrides: Partial< TooltipLayout > = {}
): TooltipLayout {
	return {
		rectX: 100,
		rectY: 50,
		rectWidth: 80,
		rectHeight: 40,
		distanceTextX: 108,
		distanceTextY: 70,
		heightTextX: 108,
		heightTextY: 85,
		distanceLabel: '5,2 km',
		heightLabel: '247 m',
		a11yLabel: 'Distance 5,2 km, elevation 247 m',
		...overrides,
	};
}

describe( 'createTooltipElements', () => {
	it( 'appends a single <g class="kntnt-gpx-blocks-elevation-tooltip"> to the SVG', () => {
		const svg = makeSvg();
		createTooltipElements( svg, {
			showDistance: true,
			showHeight: true,
		} );
		expect(
			svg.querySelectorAll( 'g.kntnt-gpx-blocks-elevation-tooltip' )
		).toHaveLength( 1 );
	} );

	it( 'gives the group pointer-events="none" so it does not block the hit-rect', () => {
		const svg = makeSvg();
		const elements = createTooltipElements( svg, {
			showDistance: true,
			showHeight: true,
		} );
		expect( elements.group.getAttribute( 'pointer-events' ) ).toBe(
			'none'
		);
	} );

	it( 'creates <title>, <rect>, distance <text>, and height <text> in that document order', () => {
		const svg = makeSvg();
		createTooltipElements( svg, {
			showDistance: true,
			showHeight: true,
		} );
		const group = svg.querySelector(
			'g.kntnt-gpx-blocks-elevation-tooltip'
		)!;
		const order = Array.from( group.children ).map( ( el ) =>
			el.tagName.toLowerCase()
		);
		expect( order ).toEqual( [ 'title', 'rect', 'text', 'text' ] );
	} );

	it( 'classes the rect and rows so SCSS can target them', () => {
		const svg = makeSvg();
		const elements = createTooltipElements( svg, {
			showDistance: true,
			showHeight: true,
		} );
		expect( elements.rect.getAttribute( 'class' ) ).toBe(
			'kntnt-gpx-blocks-elevation-tooltip-bg'
		);
		expect( elements.distance!.getAttribute( 'class' ) ).toBe(
			'kntnt-gpx-blocks-elevation-tooltip-distance'
		);
		expect( elements.height!.getAttribute( 'class' ) ).toBe(
			'kntnt-gpx-blocks-elevation-tooltip-height'
		);
	} );

	it( 'gives the rect rx="0.25em" for soft corners', () => {
		const svg = makeSvg();
		const elements = createTooltipElements( svg, {
			showDistance: true,
			showHeight: true,
		} );
		expect( elements.rect.getAttribute( 'rx' ) ).toBe( '0.25em' );
	} );

	it( 'wires the rect and rows through their colour custom properties', () => {
		const svg = makeSvg();
		const elements = createTooltipElements( svg, {
			showDistance: true,
			showHeight: true,
		} );
		expect( elements.rect.getAttribute( 'fill' ) ).toBe(
			'var(--kntnt-gpx-blocks-elevation-tooltip-background)'
		);
		expect( elements.distance!.getAttribute( 'fill' ) ).toBe(
			'var(--kntnt-gpx-blocks-elevation-tooltip-distance)'
		);
		expect( elements.height!.getAttribute( 'fill' ) ).toBe(
			'var(--kntnt-gpx-blocks-elevation-tooltip-height)'
		);
	} );

	it( 'gives both <text> rows text-anchor="start"', () => {
		const svg = makeSvg();
		const elements = createTooltipElements( svg, {
			showDistance: true,
			showHeight: true,
		} );
		expect( elements.distance!.getAttribute( 'text-anchor' ) ).toBe(
			'start'
		);
		expect( elements.height!.getAttribute( 'text-anchor' ) ).toBe(
			'start'
		);
	} );

	it( 'gives the rect and visible rows display="none" at create time', () => {
		const svg = makeSvg();
		const elements = createTooltipElements( svg, {
			showDistance: true,
			showHeight: true,
		} );
		expect( elements.rect.getAttribute( 'display' ) ).toBe( 'none' );
		expect( elements.distance!.getAttribute( 'display' ) ).toBe( 'none' );
		expect( elements.height!.getAttribute( 'display' ) ).toBe( 'none' );
	} );

	it( 'omits the distance <text> when showDistance is false', () => {
		const svg = makeSvg();
		const elements = createTooltipElements( svg, {
			showDistance: false,
			showHeight: true,
		} );
		expect( elements.distance ).toBeNull();
		expect(
			svg.querySelectorAll(
				'text.kntnt-gpx-blocks-elevation-tooltip-distance'
			)
		).toHaveLength( 0 );
		expect( elements.height ).not.toBeNull();
	} );

	it( 'omits the height <text> when showHeight is false', () => {
		const svg = makeSvg();
		const elements = createTooltipElements( svg, {
			showDistance: true,
			showHeight: false,
		} );
		expect( elements.height ).toBeNull();
		expect(
			svg.querySelectorAll(
				'text.kntnt-gpx-blocks-elevation-tooltip-height'
			)
		).toHaveLength( 0 );
		expect( elements.distance ).not.toBeNull();
	} );
} );

describe( 'applyTooltipPosition', () => {
	it( 'writes rect geometry, row positions, row text, and the a11y title', () => {
		const svg = makeSvg();
		const elements = createTooltipElements( svg, {
			showDistance: true,
			showHeight: true,
		} );
		applyTooltipPosition( elements, buildLayout() );

		expect( elements.rect.getAttribute( 'x' ) ).toBe( '100' );
		expect( elements.rect.getAttribute( 'y' ) ).toBe( '50' );
		expect( elements.rect.getAttribute( 'width' ) ).toBe( '80' );
		expect( elements.rect.getAttribute( 'height' ) ).toBe( '40' );

		expect( elements.distance!.getAttribute( 'x' ) ).toBe( '108' );
		expect( elements.distance!.getAttribute( 'y' ) ).toBe( '70' );
		expect( elements.distance!.textContent ).toBe( '5,2 km' );

		expect( elements.height!.getAttribute( 'x' ) ).toBe( '108' );
		expect( elements.height!.getAttribute( 'y' ) ).toBe( '85' );
		expect( elements.height!.textContent ).toBe( '247 m' );

		expect( elements.title.textContent ).toBe(
			'Distance 5,2 km, elevation 247 m'
		);
	} );

	it( 'removes display="none" from the rect and visible rows', () => {
		const svg = makeSvg();
		const elements = createTooltipElements( svg, {
			showDistance: true,
			showHeight: true,
		} );
		applyTooltipPosition( elements, buildLayout() );
		expect( elements.rect.getAttribute( 'display' ) ).toBeNull();
		expect( elements.distance!.getAttribute( 'display' ) ).toBeNull();
		expect( elements.height!.getAttribute( 'display' ) ).toBeNull();
	} );

	it( 'leaves elements that exist alone when their counterpart is gated off (showDistance=false)', () => {
		const svg = makeSvg();
		const elements = createTooltipElements( svg, {
			showDistance: false,
			showHeight: true,
		} );
		expect( () =>
			applyTooltipPosition( elements, buildLayout() )
		).not.toThrow();
		expect( elements.height!.textContent ).toBe( '247 m' );
	} );

	it( 'is a no-op on textContent when the new label equals the old one (DOM-quiet guard)', () => {
		const svg = makeSvg();
		const elements = createTooltipElements( svg, {
			showDistance: true,
			showHeight: true,
		} );
		applyTooltipPosition( elements, buildLayout() );

		// Tag the text node so a second `setText` would replace it.
		const originalDistanceTextNode = elements.distance!.firstChild;
		applyTooltipPosition( elements, buildLayout() );
		expect( elements.distance!.firstChild ).toBe(
			originalDistanceTextNode
		);
	} );

	it( 'updates textContent when the label changes', () => {
		const svg = makeSvg();
		const elements = createTooltipElements( svg, {
			showDistance: true,
			showHeight: true,
		} );
		applyTooltipPosition( elements, buildLayout() );
		applyTooltipPosition(
			elements,
			buildLayout( { distanceLabel: '6,0 km' } )
		);
		expect( elements.distance!.textContent ).toBe( '6,0 km' );
	} );
} );

describe( 'hideTooltip', () => {
	it( 'reapplies display="none" to the rect and visible rows', () => {
		const svg = makeSvg();
		const elements = createTooltipElements( svg, {
			showDistance: true,
			showHeight: true,
		} );
		applyTooltipPosition( elements, buildLayout() );
		hideTooltip( elements );
		expect( elements.rect.getAttribute( 'display' ) ).toBe( 'none' );
		expect( elements.distance!.getAttribute( 'display' ) ).toBe( 'none' );
		expect( elements.height!.getAttribute( 'display' ) ).toBe( 'none' );
	} );

	it( 'leaves the <title> and the <g> alone (SR-discoverability survives hiding)', () => {
		const svg = makeSvg();
		const elements = createTooltipElements( svg, {
			showDistance: true,
			showHeight: true,
		} );
		applyTooltipPosition( elements, buildLayout() );
		hideTooltip( elements );
		expect( elements.title.getAttribute( 'display' ) ).toBeNull();
		expect( elements.group.getAttribute( 'display' ) ).toBeNull();
	} );

	it( 'is a no-op on absent rows (showDistance=false, showHeight=false)', () => {
		const svg = makeSvg();
		const elements = createTooltipElements( svg, {
			showDistance: false,
			showHeight: false,
		} );
		expect( () => hideTooltip( elements ) ).not.toThrow();
		expect( elements.rect.getAttribute( 'display' ) ).toBe( 'none' );
	} );
} );
