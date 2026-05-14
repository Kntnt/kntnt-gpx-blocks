/**
 * Unit tests for {@link createTextMeasurer}.
 *
 * Pins two contracts:
 *
 *   - The default path (no className supplied) inserts a hidden `<text>`
 *     node into the SVG host, reads `getBBox()` and the resolved
 *     `font-size`, removes the node, and returns the measurement. The
 *     SVG host is left untouched after the call. This is the existing
 *     tick-label measurement path.
 *   - The Step 7 augmentation: when `className` is supplied, the hidden
 *     `<text>` carries the class so class-scoped SCSS rules apply
 *     during measurement.
 *
 * @since 1.0.0
 */
import { createTextMeasurer } from './measure';

const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * Creates a fresh, detached SVG element for each test.
 *
 * @since 1.0.0
 *
 * @return A blank `<svg>` element ready to receive measurement nodes.
 */
function makeSvg(): SVGSVGElement {
	return document.createElementNS( SVG_NS, 'svg' ) as SVGSVGElement;
}

/**
 * Stubs `SVGElement.getBBox()` and `getComputedStyle` so the tests can
 * read deterministic values without depending on jsdom's layout. Width
 * is proportional to text length; `font-size` resolves to 16 px.
 *
 * @since 1.0.0
 *
 * @return A teardown callback that restores the originals.
 */
function installLayoutStubs(): () => void {
	const originalGetBBox = (
		SVGElement.prototype as unknown as { getBBox?: () => DOMRect }
	 ).getBBox;
	( SVGElement.prototype as unknown as { getBBox: () => DOMRect } ).getBBox =
		function ( this: SVGElement ): DOMRect {
			const length = this.textContent?.length ?? 0;
			return {
				x: 0,
				y: 0,
				width: length * 10,
				height: 20,
				top: 0,
				bottom: 20,
				left: 0,
				right: length * 10,
				toJSON: () => ( {} ),
			} as DOMRect;
		};

	const originalGetComputedStyle = window.getComputedStyle;
	window.getComputedStyle = ( ( el: Element ) => {
		const result = originalGetComputedStyle.call( window, el );
		( result.getPropertyValue as unknown ) = ( name: string ): string =>
			name === 'font-size' ? '16px' : '';
		return result;
	} ) as typeof window.getComputedStyle;

	return () => {
		if ( originalGetBBox ) {
			(
				SVGElement.prototype as unknown as { getBBox: () => DOMRect }
			 ).getBBox = originalGetBBox;
		}
		window.getComputedStyle = originalGetComputedStyle;
	};
}

describe( 'createTextMeasurer', () => {
	let restore: () => void;

	beforeEach( () => {
		restore = installLayoutStubs();
	} );

	afterEach( () => {
		restore();
	} );

	it( 'returns width, height, and font-size for a text run', () => {
		const svg = makeSvg();
		const measure = createTextMeasurer( svg );
		const result = measure( '12345' );
		expect( result.width ).toBe( 50 );
		expect( result.height ).toBe( 20 );
		expect( result.fontSize ).toBe( 16 );
	} );

	it( 'removes the hidden <text> node after measurement (no leaks)', () => {
		const svg = makeSvg();
		const measure = createTextMeasurer( svg );
		measure( 'ABC' );
		// SVG must be empty again — the hidden node is gone.
		expect( svg.children ).toHaveLength( 0 );
	} );

	it( 'creates the hidden <text> without a class attribute when none is supplied (existing tick-label path)', () => {
		const svg = makeSvg();
		// Capture the hidden node by intercepting appendChild.
		const originalAppend = svg.appendChild.bind( svg );
		const captured: SVGElement[] = [];
		( svg as unknown as { appendChild: ( n: Node ) => Node } ).appendChild =
			( node: Node ): Node => {
				captured.push( node as SVGElement );
				return originalAppend( node );
			};
		const measure = createTextMeasurer( svg );
		measure( 'ABC' );
		expect( captured.length ).toBeGreaterThan( 0 );
		expect( captured[ 0 ].getAttribute( 'class' ) ).toBeNull();
	} );

	it( 'creates the hidden <text> with the supplied class so class-scoped SCSS applies (Step 7)', () => {
		const svg = makeSvg();
		const originalAppend = svg.appendChild.bind( svg );
		const captured: SVGElement[] = [];
		( svg as unknown as { appendChild: ( n: Node ) => Node } ).appendChild =
			( node: Node ): Node => {
				captured.push( node as SVGElement );
				return originalAppend( node );
			};
		const measure = createTextMeasurer(
			svg,
			'kntnt-gpx-blocks-elevation-tooltip-distance'
		);
		measure( 'ABC' );
		expect( captured.length ).toBeGreaterThan( 0 );
		expect( captured[ 0 ].getAttribute( 'class' ) ).toBe(
			'kntnt-gpx-blocks-elevation-tooltip-distance'
		);
	} );

	it( 'ignores an empty-string className (no class attribute set)', () => {
		const svg = makeSvg();
		const originalAppend = svg.appendChild.bind( svg );
		const captured: SVGElement[] = [];
		( svg as unknown as { appendChild: ( n: Node ) => Node } ).appendChild =
			( node: Node ): Node => {
				captured.push( node as SVGElement );
				return originalAppend( node );
			};
		const measure = createTextMeasurer( svg, '' );
		measure( 'ABC' );
		expect( captured[ 0 ].getAttribute( 'class' ) ).toBeNull();
	} );
} );
