/**
 * Unit tests for the chart scale helper.
 *
 * `computeChartScale` collapses Step 4's tick generation + projection
 * math into one shared object consumed by `chart.tsx`, `view.ts`, and
 * (from Step 5 onwards) by `buildStrokePathD` / `buildFillPathD` in
 * `curve.ts`. The tests pin the documented contract end-to-end with
 * synthetic margins so projection inputs and outputs are exactly
 * predictable.
 *
 * @since 1.0.0
 */

import type { Margins } from './margins';
import { computeChartScale } from './scale';

/**
 * Builds a {@link Margins} bundle from raw scalars. All margin tests in
 * this file use the same shape; the helper keeps the call sites short
 * and consistent.
 * @param overrides
 */
function makeMargins( overrides: Partial< Margins > = {} ): Margins {
	return {
		wLeft: 80,
		wRight: 40,
		wTop: 20,
		h: 30,
		em: 16,
		...overrides,
	};
}

describe( 'computeChartScale', () => {
	it( 'projects X bounds: projectX(0) = plotLeft, projectX(distance) = plotRight', () => {
		const margins = makeMargins();
		const scale = computeChartScale( {
			distance: 1000,
			minElevation: 100,
			maxElevation: 200,
			margins,
			width: 1000,
			height: 400,
		} );
		expect( scale.projectX( 0 ) ).toBeCloseTo( scale.plotLeft, 6 );
		expect( scale.projectX( 1000 ) ).toBeCloseTo( scale.plotRight, 6 );
	} );

	it( 'projects Y bounds: projectY(niceYMin) = plotBottom, projectY(niceYMax) = plotTop', () => {
		const margins = makeMargins();
		const scale = computeChartScale( {
			distance: 1000,
			minElevation: 100,
			maxElevation: 200,
			margins,
			width: 1000,
			height: 400,
		} );
		expect( scale.projectY( scale.niceYMin ) ).toBeCloseTo(
			scale.plotBottom,
			6
		);
		expect( scale.projectY( scale.niceYMax ) ).toBeCloseTo(
			scale.plotTop,
			6
		);
	} );

	it( 'sets plot rectangle from margins (plotLeft = wLeft, plotRight = width - wRight, plotTop = wTop, plotBottom = height - h)', () => {
		const margins = makeMargins( {
			wLeft: 80,
			wRight: 40,
			wTop: 20,
			h: 30,
			em: 16,
		} );
		const scale = computeChartScale( {
			distance: 1000,
			minElevation: 100,
			maxElevation: 200,
			margins,
			width: 1000,
			height: 400,
		} );
		expect( scale.plotLeft ).toBe( 80 );
		expect( scale.plotRight ).toBe( 1000 - 40 );
		expect( scale.plotTop ).toBe( 20 );
		expect( scale.plotBottom ).toBe( 400 - 30 );
		expect( scale.availX ).toBe( 1000 - 80 - 40 );
		expect( scale.availY ).toBe( 400 - 20 - 30 );
	} );

	it( 'inflates the Y range by ±1 when minElevation === maxElevation (Case B)', () => {
		const margins = makeMargins();
		const scale = computeChartScale( {
			distance: 1000,
			minElevation: 250,
			maxElevation: 250,
			margins,
			width: 1000,
			height: 400,
		} );
		// niceYMin/niceYMax must enclose [249, 251], so the projection
		// of 250 falls strictly inside the plot rectangle.
		expect( scale.niceYMin ).toBeLessThanOrEqual( 249 );
		expect( scale.niceYMax ).toBeGreaterThanOrEqual( 251 );
	} );

	it( 'filters X ticks to value ≤ distance (Strava-style)', () => {
		const margins = makeMargins();
		const scale = computeChartScale( {
			distance: 888,
			minElevation: 0,
			maxElevation: 100,
			margins,
			width: 1000,
			height: 400,
		} );
		// Every X tick must satisfy `value ≤ distance` (projected
		// position ≤ plotRight after Strava-style filtering).
		for ( const tick of scale.xTicks ) {
			expect( tick.position ).toBeLessThanOrEqual(
				scale.plotRight + 1e-6
			);
		}
	} );

	it( 'returns empty tick sets and NaN projections when availX ≤ 0 (sentinel branch)', () => {
		const margins = makeMargins( { wLeft: 600, wRight: 600 } );
		const scale = computeChartScale( {
			distance: 1000,
			minElevation: 0,
			maxElevation: 100,
			margins,
			width: 1000,
			height: 400,
		} );
		expect( scale.xTicks ).toEqual( [] );
		expect( scale.yTicks ).toEqual( [] );
		expect( Number.isNaN( scale.projectX( 0 ) ) ).toBe( true );
		expect( Number.isNaN( scale.projectY( 0 ) ) ).toBe( true );
	} );

	it( 'returns empty tick sets and NaN projections when availY ≤ 0 (sentinel branch)', () => {
		const margins = makeMargins( { wTop: 250, h: 250 } );
		const scale = computeChartScale( {
			distance: 1000,
			minElevation: 0,
			maxElevation: 100,
			margins,
			width: 1000,
			height: 400,
		} );
		expect( scale.xTicks ).toEqual( [] );
		expect( scale.yTicks ).toEqual( [] );
		expect( Number.isNaN( scale.projectX( 0 ) ) ).toBe( true );
		expect( Number.isNaN( scale.projectY( 0 ) ) ).toBe( true );
	} );

	it( 'returns a non-empty Y tick set in a healthy configuration', () => {
		const margins = makeMargins();
		const scale = computeChartScale( {
			distance: 1000,
			minElevation: 0,
			maxElevation: 100,
			margins,
			width: 1000,
			height: 400,
		} );
		expect( scale.yTicks.length ).toBeGreaterThanOrEqual( 2 );
	} );

	it( 'exposes em from the supplied margins bundle', () => {
		const margins = makeMargins( { em: 24 } );
		const scale = computeChartScale( {
			distance: 1000,
			minElevation: 0,
			maxElevation: 100,
			margins,
			width: 1000,
			height: 400,
		} );
		expect( scale.em ).toBe( 24 );
	} );

	it( 'tick labels are strings parallel to tick positions', () => {
		const margins = makeMargins();
		const scale = computeChartScale( {
			distance: 1000,
			minElevation: 0,
			maxElevation: 100,
			margins,
			width: 1000,
			height: 400,
		} );
		for ( const tick of scale.xTicks ) {
			expect( typeof tick.label ).toBe( 'string' );
			expect( typeof tick.position ).toBe( 'number' );
		}
		for ( const tick of scale.yTicks ) {
			expect( typeof tick.label ).toBe( 'string' );
			expect( typeof tick.position ).toBe( 'number' );
		}
	} );
} );
