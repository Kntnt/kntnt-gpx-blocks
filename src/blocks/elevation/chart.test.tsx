/**
 * Unit tests for the editor preview {@link Chart} component.
 *
 * Stubs `SVGElement.getBBox()` and `Element.getBoundingClientRect()` so
 * the margin algorithm and ResizeObserver effects produce deterministic
 * geometry in jsdom. Verifies:
 *
 *   - Two axis `<line>` elements render with the documented coordinates
 *     after measurement and layout settle. The Y axis starts at
 *     `y = margins.wTop` (Step 4) rather than at `y = 0`.
 *   - The axis stroke is the CSS custom property
 *     `var(--kntnt-gpx-blocks-elevation-axis)` and the stroke-width is
 *     `1` (per Step 3 *Axis appearance*).
 *   - The SVG carries `role="img"` and a localised `aria-label`.
 *   - Step 4: two tick-mark groups + two tick-label groups appear inside
 *     the SVG. The first X tick sits at `x = margins.wLeft`. X tick
 *     values are filtered to `value ≤ distance` (Strava-style bounds).
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
		const lines = svg.querySelectorAll(
			'line.kntnt-gpx-blocks-elevation-axis-x, line.kntnt-gpx-blocks-elevation-axis-y'
		);
		expect( lines ).toHaveLength( 2 );
	} );

	it( 'styles the axes with stroke-width=1 and the CSS custom property colour', async () => {
		const svg = await renderChart( {
			minElevation: 0,
			maxElevation: 500,
			distance: 5000,
		} );
		const axisLines = svg.querySelectorAll(
			'line.kntnt-gpx-blocks-elevation-axis-x, line.kntnt-gpx-blocks-elevation-axis-y'
		);
		for ( const line of axisLines ) {
			expect( line.getAttribute( 'stroke-width' ) ).toBe( '1' );
			expect( line.getAttribute( 'stroke' ) ).toBe(
				'var(--kntnt-gpx-blocks-elevation-axis)'
			);
		}
	} );

	it( 'starts the Y axis line at y1 = margins.wTop (not 0)', async () => {
		const svg = await renderChart( {
			minElevation: 0,
			maxElevation: 500,
			distance: 5000,
		} );
		const yAxis = svg.querySelector(
			'line.kntnt-gpx-blocks-elevation-axis-y'
		);
		expect( yAxis ).not.toBeNull();
		const y1 = Number.parseFloat( yAxis!.getAttribute( 'y2' ) ?? '0' );
		// Step 4: Y axis line endpoints are (margins.wLeft, H-margins.h) →
		// (margins.wLeft, margins.wTop). wTop is strictly positive because
		// 0.5 × refHeight + 0.5em > 0 under the stubbed measurer.
		expect( y1 ).toBeGreaterThan( 0 );
	} );

	it( 'renders the four Step 4 tick groups (marks + labels for both axes)', async () => {
		const svg = await renderChart( {
			minElevation: 0,
			maxElevation: 500,
			distance: 5000,
		} );
		expect(
			svg.querySelector( 'g.kntnt-gpx-blocks-elevation-ticks-x' )
		).not.toBeNull();
		expect(
			svg.querySelector( 'g.kntnt-gpx-blocks-elevation-ticks-y' )
		).not.toBeNull();
		expect(
			svg.querySelector( 'g.kntnt-gpx-blocks-elevation-tick-labels-x' )
		).not.toBeNull();
		expect(
			svg.querySelector( 'g.kntnt-gpx-blocks-elevation-tick-labels-y' )
		).not.toBeNull();
	} );

	it( 'positions tick labels with the documented anchor + baseline attributes', async () => {
		const svg = await renderChart( {
			minElevation: 0,
			maxElevation: 500,
			distance: 5000,
		} );
		const xLabels = svg.querySelectorAll(
			'g.kntnt-gpx-blocks-elevation-tick-labels-x text'
		);
		const yLabels = svg.querySelectorAll(
			'g.kntnt-gpx-blocks-elevation-tick-labels-y text'
		);
		expect( xLabels.length ).toBeGreaterThan( 0 );
		expect( yLabels.length ).toBeGreaterThan( 0 );
		for ( const t of xLabels ) {
			expect( t.getAttribute( 'text-anchor' ) ).toBe( 'middle' );
			expect( t.getAttribute( 'dominant-baseline' ) ).toBe( 'hanging' );
		}
		for ( const t of yLabels ) {
			expect( t.getAttribute( 'text-anchor' ) ).toBe( 'end' );
			expect( t.getAttribute( 'dominant-baseline' ) ).toBe( 'central' );
		}
	} );

	it( 'places the first X tick at x = margins.wLeft (the origin)', async () => {
		const svg = await renderChart( {
			minElevation: 0,
			maxElevation: 500,
			distance: 5000,
		} );
		const yAxis = svg.querySelector(
			'line.kntnt-gpx-blocks-elevation-axis-y'
		);
		const wLeft = Number.parseFloat( yAxis!.getAttribute( 'x1' ) ?? '0' );

		const firstXTickMark = svg.querySelector(
			'g.kntnt-gpx-blocks-elevation-ticks-x line'
		);
		expect( firstXTickMark ).not.toBeNull();
		// Tick marks for X are vertical lines anchored to the X axis Y
		// coordinate; the first tick's x1 == margins.wLeft.
		expect(
			Number.parseFloat( firstXTickMark!.getAttribute( 'x1' ) ?? 'NaN' )
		).toBeCloseTo( wLeft, 5 );
	} );

	it( 'never plots an X tick whose value exceeds distance', async () => {
		// distance = 4500 → niceTicks(0, 4500, N) can produce 5000 if
		// the nice step is 1000 (since ceil(4500/1000)*1000 = 5000). The
		// filter in chart.tsx should drop any value > distance so the
		// last rendered X tick value is at most 4500.
		const svg = await renderChart( {
			minElevation: 0,
			maxElevation: 500,
			distance: 4500,
		} );
		const labels = Array.from(
			svg.querySelectorAll(
				'g.kntnt-gpx-blocks-elevation-tick-labels-x text'
			)
		).map( ( t ) => t.textContent ?? '' );
		// In km-mode the last label is "4,5 km" (or smaller); never the
		// "5,0 km" that the unfiltered nice-tick set would have produced.
		const last = labels[ labels.length - 1 ];
		expect( last ).not.toMatch( /^5[.,]0 km$/ );
	} );
} );
