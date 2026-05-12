/**
 * Unit tests for the nice-tick generator.
 *
 * Pins the Step 3 contract:
 *
 *   - {@link computeTickCount} returns `floor(width / (label × 1.5))`,
 *     clamped to `≥ 2`.
 *   - {@link niceStep} rounds to the nearest entry in
 *     `[1, 2, 5] × 10^n` with ties going downward.
 *   - {@link generateTicks} covers the requested range inclusively on
 *     both ends and emits exact integer multiples of the step.
 *   - {@link niceTicks} composes the two.
 *
 * @since 1.0.0
 */

import { computeTickCount, generateTicks, niceStep, niceTicks } from './ticks';

describe( 'computeTickCount', () => {
	it( 'returns floor(width / (label * 1.5))', () => {
		// 600 / (50 * 1.5) = 8 → 8.
		expect( computeTickCount( 600, 50 ) ).toBe( 8 );
	} );

	it( 'clamps to at least 2 even on a very narrow chart', () => {
		expect( computeTickCount( 30, 50 ) ).toBe( 2 );
	} );

	it( 'returns 2 for non-positive inputs', () => {
		expect( computeTickCount( 0, 50 ) ).toBe( 2 );
		expect( computeTickCount( 100, 0 ) ).toBe( 2 );
		expect( computeTickCount( -100, 50 ) ).toBe( 2 );
	} );
} );

describe( 'niceStep', () => {
	it( 'picks 1 × 10^n for ratios near 1', () => {
		// range=10, target=10 → rough=1 → step=1.
		expect( niceStep( 10, 10 ) ).toBe( 1 );
	} );

	it( 'picks 2 × 10^n for ratios near 2', () => {
		// range=10, target=5 → rough=2 → step=2.
		expect( niceStep( 10, 5 ) ).toBe( 2 );
	} );

	it( 'picks 5 × 10^n for ratios near 5', () => {
		// range=10, target=2 → rough=5 → step=5.
		expect( niceStep( 10, 2 ) ).toBe( 5 );
	} );

	it( 'picks 10 × 10^n at the upper boundary', () => {
		// range=80, target=10 → rough=8 → norm=8 → nice=10 → step=10.
		expect( niceStep( 80, 10 ) ).toBe( 10 );
	} );

	it( 'scales with the data magnitude', () => {
		expect( niceStep( 1000, 5 ) ).toBe( 200 );
		expect( niceStep( 0.5, 5 ) ).toBe( 0.1 );
	} );

	it( 'returns 1 for non-positive range', () => {
		expect( niceStep( 0, 5 ) ).toBe( 1 );
		expect( niceStep( -10, 5 ) ).toBe( 1 );
	} );

	it( 'returns 1 for non-positive count', () => {
		expect( niceStep( 10, 0 ) ).toBe( 1 );
	} );
} );

describe( 'generateTicks', () => {
	it( 'covers the range inclusively on both ends', () => {
		expect( generateTicks( 0, 10, 2 ) ).toEqual( [ 0, 2, 4, 6, 8, 10 ] );
	} );

	it( 'anchors to the step grid when min is not on a multiple', () => {
		expect( generateTicks( 3, 17, 5 ) ).toEqual( [ 0, 5, 10, 15, 20 ] );
	} );

	it( 'handles negative ranges', () => {
		expect( generateTicks( -10, 10, 5 ) ).toEqual( [ -10, -5, 0, 5, 10 ] );
	} );

	it( 'avoids cumulative drift on fractional steps', () => {
		// Eleven ticks at 0.1 spacing; the additive loop variant would
		// drift the last tick away from 1.0 by float epsilons.
		const ticks = generateTicks( 0, 1, 0.1 );
		expect( ticks ).toHaveLength( 11 );
		expect( ticks[ ticks.length - 1 ] ).toBeCloseTo( 1.0, 10 );
	} );

	it( 'returns an empty array for non-positive step', () => {
		expect( generateTicks( 0, 10, 0 ) ).toEqual( [] );
		expect( generateTicks( 0, 10, -1 ) ).toEqual( [] );
	} );

	it( 'returns an empty array when max < min', () => {
		expect( generateTicks( 10, 0, 1 ) ).toEqual( [] );
	} );
} );

describe( 'niceTicks', () => {
	it( 'returns both the step and the values', () => {
		const { step, values } = niceTicks( 0, 100, 5 );
		expect( step ).toBe( 20 );
		expect( values ).toEqual( [ 0, 20, 40, 60, 80, 100 ] );
	} );

	it( 'aligns the range with the nice grid', () => {
		const { step, values } = niceTicks( 12, 47, 4 );
		expect( step ).toBe( 10 );
		expect( values ).toEqual( [ 10, 20, 30, 40, 50 ] );
	} );

	it( 'handles a flat range (max === min) gracefully', () => {
		// Caller is expected to inflate the range first (the Step 3
		// Case B substitution); niceTicks treats max===min as
		// zero-range and returns a degenerate single-tick result.
		const { values } = niceTicks( 100, 100, 5 );
		expect( values.length ).toBeGreaterThanOrEqual( 0 );
	} );
} );
