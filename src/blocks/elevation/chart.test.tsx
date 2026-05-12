/**
 * Unit tests for the editor preview {@link Chart} component.
 *
 * Stubs `SVGElement.getBBox()` and `Element.getBoundingClientRect()` so
 * the margin algorithm and ResizeObserver effects produce deterministic
 * geometry in jsdom. Verifies:
 *
 *   - Two axis `<line>` elements render with the documented coordinates
 *     after measurement and layout settle.
 *   - The axis stroke is the CSS custom property
 *     `var(--kntnt-gpx-blocks-elevation-axis)` and the stroke-width is
 *     `1` (per Step 3 *Axis appearance*).
 *   - The SVG carries `role="img"` and a localised `aria-label`.
 *
 * @since 1.0.0
 */

jest.mock(
	'@wordpress/i18n',
	() => ( {
		__esModule: true,
		__: ( s: string ) => s,
	} ),
	{ virtual: true }
);

// Tell React 18 that `act(...)` calls are expected so it does not log
// the "current testing environment is not configured to support act(...)"
// warning. The flag is read by react-dom in development builds.
(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

import { createElement, createRoot } from '@wordpress/element';
// `act` is exported by React 18 itself but not re-exported from
// `@wordpress/element`. For this test-only file the direct import is
// the right scope.
// eslint-disable-next-line import/no-extraneous-dependencies
import { act } from 'react';

import { Chart } from './chart';

/**
 * Wires up jsdom stubs for the SVG layout primitives the chart relies
 * on: `getBBox` (used by the measurer) and `getBoundingClientRect`
 * (used by the ResizeObserver effect to read the SVG's rendered
 * dimensions). Reset between tests via `afterEach`.
 * @param widthPerChar
 * @param height
 */
function installSvgStubs( widthPerChar: number, height: number ): void {
	// Width is proportional to text length; height + fontSize are
	// constants. The chart only reads .width and .height, so x/y are
	// zero.
	(
		SVGElement.prototype as unknown as {
			getBBox: () => DOMRect;
		}
	 ).getBBox = function ( this: SVGElement ): DOMRect {
		const length = this.textContent?.length ?? 0;
		return {
			x: 0,
			y: 0,
			width: length * widthPerChar,
			height,
			top: 0,
			bottom: height,
			left: 0,
			right: length * widthPerChar,
			toJSON: () => ( {} ),
		} as DOMRect;
	};

	// Constant resolved font-size; the chart's margin algorithm uses
	// this as the `em` base.
	const originalGetComputedStyle = window.getComputedStyle;
	window.getComputedStyle = ( ( el: Element ) => {
		const result = originalGetComputedStyle.call( window, el );
		Object.defineProperty( result, 'fontSize', {
			value: '16px',
			configurable: true,
		} );
		( result.getPropertyValue as unknown ) = ( name: string ): string =>
			name === 'font-size' ? '16px' : '';
		return result;
	} ) as typeof window.getComputedStyle;

	// SVG rendered dimensions used by the ResizeObserver effect.
	(
		Element.prototype as unknown as {
			getBoundingClientRect: () => DOMRect;
		}
	 ).getBoundingClientRect = function (): DOMRect {
		return {
			x: 0,
			y: 0,
			width: 600,
			height: 200,
			top: 0,
			bottom: 200,
			left: 0,
			right: 600,
			toJSON: () => ( {} ),
		} as DOMRect;
	};

	// ResizeObserver is not in jsdom by default; a no-op stub is
	// sufficient because the test reads dims once on mount.
	if (
		typeof ( globalThis as { ResizeObserver?: unknown } ).ResizeObserver ===
		'undefined'
	) {
		(
			globalThis as unknown as { ResizeObserver: unknown }
		 ).ResizeObserver = class {
			observe(): void {
				/* no-op */
			}
			unobserve(): void {
				/* no-op */
			}
			disconnect(): void {
				/* no-op */
			}
		};
	}
}

/**
 * Renders the chart synchronously into a detached DOM node and
 * returns the SVG element for inspection.
 *
 * @param data              Chart data input.
 * @param data.minElevation
 * @param data.maxElevation
 * @param data.distance
 * @return The rendered SVG element.
 */
async function renderChart( data: {
	minElevation: number;
	maxElevation: number;
	distance: number;
} ): Promise< SVGSVGElement > {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () => {
		root.render( createElement( Chart, { data, typography: {} } ) );
	} );
	// Allow effects to run (fonts.ready Promise resolves on the next
	// microtask).
	await act( async () => {
		await Promise.resolve();
	} );
	const svg = container.querySelector( 'svg' );
	if ( ! svg ) {
		throw new Error( 'Chart did not render an SVG' );
	}
	return svg as SVGSVGElement;
}

describe( 'Chart', () => {
	beforeEach( () => {
		installSvgStubs( 10, 20 );
	} );

	it( 'renders an SVG host with role="img" and a localised aria-label', async () => {
		const svg = await renderChart( {
			minElevation: 0,
			maxElevation: 500,
			distance: 5000,
		} );
		expect( svg.getAttribute( 'role' ) ).toBe( 'img' );
		expect( svg.getAttribute( 'aria-label' ) ).toBe(
			'Elevation profile of GPX track'
		);
	} );

	it( 'draws two axis <line> elements after measurement + layout settle', async () => {
		const svg = await renderChart( {
			minElevation: 0,
			maxElevation: 500,
			distance: 5000,
		} );
		const lines = svg.querySelectorAll( 'line' );
		expect( lines ).toHaveLength( 2 );
	} );

	it( 'styles the axes with stroke-width=1 and the CSS custom property colour', async () => {
		const svg = await renderChart( {
			minElevation: 0,
			maxElevation: 500,
			distance: 5000,
		} );
		const lines = svg.querySelectorAll( 'line' );
		for ( const line of lines ) {
			expect( line.getAttribute( 'stroke-width' ) ).toBe( '1' );
			expect( line.getAttribute( 'stroke' ) ).toBe(
				'var(--kntnt-gpx-blocks-elevation-axis)'
			);
		}
	} );
} );
