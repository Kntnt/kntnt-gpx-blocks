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
 * @param samples
 * @return The rendered SVG element.
 */
async function renderChart(
	data: {
		minElevation: number;
		maxElevation: number;
		distance: number;
	},
	samples: ReadonlyArray< readonly [ number, number ] > = [
		[ 0, 100 ],
		[ 1000, 200 ],
		[ 2500, 150 ],
		[ 5000, 300 ],
	]
): Promise< SVGSVGElement > {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	await act( async () => {
		root.render(
			createElement( Chart, { data, samples, typography: {} } )
		);
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

	it( 'renders the plot-fill and plot-line <path> elements (Step 5)', async () => {
		const svg = await renderChart( {
			minElevation: 0,
			maxElevation: 500,
			distance: 5000,
		} );
		const fill = svg.querySelector(
			'path.kntnt-gpx-blocks-elevation-plot-fill'
		);
		const line = svg.querySelector(
			'path.kntnt-gpx-blocks-elevation-plot-line'
		);
		expect( fill ).not.toBeNull();
		expect( line ).not.toBeNull();
		expect( line!.getAttribute( 'stroke' ) ).toBe(
			'var(--kntnt-gpx-blocks-elevation-plot-line)'
		);
		expect( line!.getAttribute( 'stroke-width' ) ).toBe( '2' );
		expect( line!.getAttribute( 'stroke-linejoin' ) ).toBe( 'round' );
		expect( line!.getAttribute( 'stroke-linecap' ) ).toBe( 'round' );
		// React lowercases `vectorEffect` to `vector-effect` on SVG paths.
		expect( line!.getAttribute( 'vector-effect' ) ).toBe(
			'non-scaling-stroke'
		);
		expect( line!.getAttribute( 'fill' ) ).toBe( 'none' );
		expect( fill!.getAttribute( 'fill' ) ).toBe(
			'var(--kntnt-gpx-blocks-elevation-plot-fill)'
		);
		expect( fill!.getAttribute( 'stroke' ) ).toBe( 'none' );
		// Fill path is always emitted (independent of plotFillColor).
		expect( fill!.getAttribute( 'd' ) ).toMatch( /^M/ );
		expect( fill!.getAttribute( 'd' ) ).toMatch( / Z$/ );
		expect( line!.getAttribute( 'd' ) ).toMatch( /^M/ );
		expect( line!.getAttribute( 'd' ) ).not.toMatch( / Z$/ );
	} );

	it( 'layers axes → fill → line → ticks → labels in document order', async () => {
		const svg = await renderChart( {
			minElevation: 0,
			maxElevation: 500,
			distance: 5000,
		} );
		const order = Array.from( svg.children ).map(
			( el ) => el.classList[ 0 ]
		);
		const idxAxisX = order.indexOf( 'kntnt-gpx-blocks-elevation-axis-x' );
		const idxAxisY = order.indexOf( 'kntnt-gpx-blocks-elevation-axis-y' );
		const idxFill = order.indexOf( 'kntnt-gpx-blocks-elevation-plot-fill' );
		const idxLine = order.indexOf( 'kntnt-gpx-blocks-elevation-plot-line' );
		const idxTicksX = order.indexOf( 'kntnt-gpx-blocks-elevation-ticks-x' );
		const idxLabelsY = order.indexOf(
			'kntnt-gpx-blocks-elevation-tick-labels-y'
		);
		expect( idxAxisX ).toBeGreaterThanOrEqual( 0 );
		expect( idxAxisY ).toBeGreaterThan( idxAxisX );
		expect( idxFill ).toBeGreaterThan( idxAxisY );
		expect( idxLine ).toBeGreaterThan( idxFill );
		expect( idxTicksX ).toBeGreaterThan( idxLine );
		expect( idxLabelsY ).toBeGreaterThan( idxTicksX );
	} );

	it( 'applies typography inline on the <svg> host (visible labels and the measurer inherit from it)', async () => {
		// The chart writes the user's typography choices as inline
		// style declarations on the host <svg>. Both the visible tick
		// <text> labels (under the <g> groups) and the measurer's
		// hidden <text> nodes (direct SVG children) inherit those
		// declarations. Applying typography here rather than relying
		// on a SCSS rule keyed off custom properties on the wrapper
		// is what guarantees the user's choices reach the rendered
		// labels in the Gutenberg editor — an inline style declaration
		// wins against any editor-iframe rule that might otherwise
		// target SVG text under a more specific selector.
		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );
		await act( async () => {
			root.render(
				createElement( Chart, {
					data: {
						minElevation: 0,
						maxElevation: 500,
						distance: 5000,
					},
					samples: [
						[ 0, 100 ],
						[ 5000, 200 ],
					],
					typography: {
						fontFamily: 'Inter',
						fontSize: '20px',
						fontWeight: '700',
					},
				} )
			);
		} );
		await act( async () => {
			await Promise.resolve();
		} );
		const svg = container.querySelector( 'svg' )!;
		const svgStyle = svg.getAttribute( 'style' ) ?? '';
		expect( svgStyle ).toContain( 'font-family' );
		expect( svgStyle ).toContain( 'Inter' );
		expect( svgStyle ).toContain( 'font-size' );
		expect( svgStyle ).toContain( '20px' );
		expect( svgStyle ).toContain( 'font-weight' );
		expect( svgStyle ).toContain( '700' );
	} );

	it( 'does not duplicate font-* inline onto the tick label <text> nodes themselves', async () => {
		// The <svg> carries the typography. The <text> descendants
		// inherit it. Writing typography directly onto each <text>
		// (or onto the parent <g> groups) would be either redundant
		// or — worse — desynchronised with the SVG host, so the
		// chart deliberately leaves the descendants un-styled. This
		// test pins that contract so a future "cleaner" who tries
		// to push typography down the tree gets caught.
		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );
		await act( async () => {
			root.render(
				createElement( Chart, {
					data: {
						minElevation: 0,
						maxElevation: 500,
						distance: 5000,
					},
					samples: [
						[ 0, 100 ],
						[ 5000, 200 ],
					],
					typography: {
						fontFamily: 'Inter',
						fontSize: '20px',
						fontWeight: '700',
					},
				} )
			);
		} );
		await act( async () => {
			await Promise.resolve();
		} );
		const svg = container.querySelector( 'svg' )!;
		const labels = svg.querySelectorAll(
			'g.kntnt-gpx-blocks-elevation-tick-labels-x text, g.kntnt-gpx-blocks-elevation-tick-labels-y text'
		);
		expect( labels.length ).toBeGreaterThan( 0 );
		for ( const t of labels ) {
			expect( t.getAttribute( 'style' ) ?? '' ).not.toContain( 'font-' );
			expect( t.getAttribute( 'font-family' ) ).toBeNull();
			expect( t.getAttribute( 'font-size' ) ).toBeNull();
			expect( t.getAttribute( 'font-weight' ) ).toBeNull();
		}
	} );

	it( 'remeasures when the typography prop changes (Strategy B re-trigger)', async () => {
		// Strategy B: typography is not threaded through computeMargins
		// or the measurer — the SVG's CSS-inherited font-* is the input.
		// The typography prop's only remaining role is to populate the
		// useLayoutEffect dep-list so a new typography choice triggers
		// a fresh round of measurement. This test pins that contract
		// against a future "cleaner" who would otherwise see the prop
		// as unused and remove it.
		const data = { minElevation: 0, maxElevation: 500, distance: 5000 };
		const samples = [
			[ 0, 100 ],
			[ 5000, 200 ],
		] as const;
		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );

		// Count getBBox invocations as a proxy for "a measurement pass
		// happened". The chart calls getBBox at least once per
		// measurement (Y labels + X reference + height reference).
		const originalGetBBox = (
			SVGElement.prototype as unknown as { getBBox: () => DOMRect }
		 ).getBBox;
		let bboxCalls = 0;
		(
			SVGElement.prototype as unknown as { getBBox: () => DOMRect }
		 ).getBBox = function ( this: SVGElement ): DOMRect {
			bboxCalls += 1;
			return originalGetBBox.call( this );
		};

		try {
			await act( async () => {
				root.render(
					createElement( Chart, {
						data,
						samples,
						typography: { fontSize: '14px' },
					} )
				);
			} );
			await act( async () => {
				await Promise.resolve();
			} );
			const callsAfterFirstMount = bboxCalls;
			expect( callsAfterFirstMount ).toBeGreaterThan( 0 );

			await act( async () => {
				root.render(
					createElement( Chart, {
						data,
						samples,
						typography: { fontSize: '20px' },
					} )
				);
			} );
			await act( async () => {
				await Promise.resolve();
			} );
			expect( bboxCalls ).toBeGreaterThan( callsAfterFirstMount );
		} finally {
			(
				SVGElement.prototype as unknown as {
					getBBox: () => DOMRect;
				}
			 ).getBBox = originalGetBBox;
		}
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
