/**
 * Unit tests for the elevation chart's pure sample-interpolation and
 * projection helpers.
 *
 * Step 6 of `docs/elevation-rebuild.md` introduced these helpers under
 * `geometry/cursor.ts` because the cursor was their only consumer; Step 7
 * adds the tooltip as a second consumer and migrates them to their own
 * SRP-clean module. This file pins the same behavioural contract as
 * `geometry/cursor.test.ts` did — only the import path changes.
 *
 * The DOM-bound pieces (SVG element construction, attribute writes,
 * pointer handlers) live in their own test files; this file is pure
 * math and runs without jsdom assumptions.
 *
 * @since 1.0.0
 */
import type { ChartScale } from './scale';
import { interpolateSample, projectCursor } from './sample-interpolation';

/**
 * Builds a {@link ChartScale} with identity-scaled projections so the
 * tests can assert against exact values. The plot rectangle is
 * placed at `[ 0, distance ] × [ 0, elevation ]`; the projection
 * functions reflect Y so a higher elevation maps to a smaller `cy`,
 * matching the production scale's coordinate convention.
 *
 * @since 1.0.0
 *
 * @param distance Total track distance.
 * @param yMin     Bottom of the elevation range.
 * @param yMax     Top of the elevation range.
 * @return A `ChartScale` whose projections are direct identities.
 */
function identityScale(
	distance: number,
	yMin: number,
	yMax: number
): ChartScale {
	const plotLeft = 0;
	const plotRight = distance;
	const plotTop = 0;
	const plotBottom = yMax - yMin;
	return {
		distance,
		niceYMin: yMin,
		niceYMax: yMax,
		plotLeft,
		plotRight,
		plotTop,
		plotBottom,
		availX: plotRight - plotLeft,
		availY: plotBottom - plotTop,
		em: 16,
		projectX: ( d: number ): number => d,
		projectY: ( e: number ): number => plotBottom - ( e - yMin ),
		xTicks: [],
		yTicks: [],
	};
}

describe( 'interpolateSample', () => {
	it( 'returns null when the samples array is empty', () => {
		expect( interpolateSample( [], 0 ) ).toBeNull();
	} );

	it( 'returns null when the samples array has a single point', () => {
		expect( interpolateSample( [ [ 0, 100 ] ], 0 ) ).toBeNull();
	} );

	it( 'returns the first sample when distance is at the start', () => {
		const samples: ReadonlyArray< readonly [ number, number ] > = [
			[ 0, 100 ],
			[ 1000, 200 ],
			[ 2500, 150 ],
			[ 5000, 300 ],
		];
		const result = interpolateSample( samples, 0 );
		expect( result ).not.toBeNull();
		expect( result!.distance ).toBeCloseTo( 0, 6 );
		expect( result!.elevation ).toBeCloseTo( 100, 6 );
	} );

	it( 'returns the last sample when distance is at the end', () => {
		const samples: ReadonlyArray< readonly [ number, number ] > = [
			[ 0, 100 ],
			[ 1000, 200 ],
			[ 2500, 150 ],
			[ 5000, 300 ],
		];
		const result = interpolateSample( samples, 5000 );
		expect( result ).not.toBeNull();
		expect( result!.distance ).toBeCloseTo( 5000, 6 );
		expect( result!.elevation ).toBeCloseTo( 300, 6 );
	} );

	it( 'linearly interpolates elevation between the two bracketing samples', () => {
		// Bracket [(1000, 200), (2500, 150)]: a 50-metre drop spread over
		// a 1500-metre run. At distance = 1500 (a third of the way through
		// the bracket) the elevation drops by 50 × 1/3, so 200 - 50/3.
		const samples: ReadonlyArray< readonly [ number, number ] > = [
			[ 0, 100 ],
			[ 1000, 200 ],
			[ 2500, 150 ],
			[ 5000, 300 ],
		];
		const result = interpolateSample( samples, 1500 );
		expect( result ).not.toBeNull();
		expect( result!.distance ).toBeCloseTo( 1500, 6 );
		expect( result!.elevation ).toBeCloseTo( 200 - 50 / 3, 6 );
	} );

	it( 'clamps to the first endpoint when distance is below the first sample', () => {
		const samples: ReadonlyArray< readonly [ number, number ] > = [
			[ 0, 100 ],
			[ 1000, 200 ],
		];
		const result = interpolateSample( samples, -1 );
		expect( result ).not.toBeNull();
		expect( result!.distance ).toBeCloseTo( 0, 6 );
		expect( result!.elevation ).toBeCloseTo( 100, 6 );
	} );

	it( 'clamps to the last endpoint when distance is above the last sample', () => {
		const samples: ReadonlyArray< readonly [ number, number ] > = [
			[ 0, 100 ],
			[ 1000, 200 ],
		];
		const result = interpolateSample( samples, 9999 );
		expect( result ).not.toBeNull();
		expect( result!.distance ).toBeCloseTo( 1000, 6 );
		expect( result!.elevation ).toBeCloseTo( 200, 6 );
	} );

	it( 'handles a tight bracket where two adjacent samples share a distance', () => {
		// Defensive: a degenerate bracket of zero width must not divide
		// by zero. Picking either endpoint is acceptable; not crashing
		// is the contract.
		const samples: ReadonlyArray< readonly [ number, number ] > = [
			[ 0, 100 ],
			[ 1000, 200 ],
			[ 1000, 300 ],
			[ 2000, 400 ],
		];
		const result = interpolateSample( samples, 1000 );
		expect( result ).not.toBeNull();
		expect( Number.isFinite( result!.elevation ) ).toBe( true );
	} );
} );

describe( 'projectCursor', () => {
	it( 'composes scale.projectX and scale.projectY', () => {
		const scale = identityScale( 5000, 0, 500 );
		const cursor = projectCursor(
			{ distance: 2500, elevation: 100 },
			scale
		);
		expect( cursor.cx ).toBeCloseTo( scale.projectX( 2500 ), 6 );
		expect( cursor.cy ).toBeCloseTo( scale.projectY( 100 ), 6 );
	} );

	it( 'returns the bottom-left corner for the start of the track', () => {
		const scale = identityScale( 5000, 0, 500 );
		const cursor = projectCursor( { distance: 0, elevation: 0 }, scale );
		expect( cursor.cx ).toBeCloseTo( scale.plotLeft, 6 );
		expect( cursor.cy ).toBeCloseTo( scale.plotBottom, 6 );
	} );

	it( 'returns the top-right corner for the end of the track at maximum elevation', () => {
		const scale = identityScale( 5000, 0, 500 );
		const cursor = projectCursor(
			{ distance: 5000, elevation: 500 },
			scale
		);
		expect( cursor.cx ).toBeCloseTo( scale.plotRight, 6 );
		expect( cursor.cy ).toBeCloseTo( scale.plotTop, 6 );
	} );
} );
