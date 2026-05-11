/**
 * Jest tests for the shared geometry primitives.
 *
 * Consolidates what used to be two parallel suites — one in
 * `src/blocks/map/geometry.test.ts` and one in
 * `src/blocks/elevation/geometry.test.ts` — into a single canonical suite
 * since both helpers are now defined exactly once in
 * `src/blocks/shared/geometry.ts`.
 *
 * Issue #128.
 *
 * @since 1.0.0
 */

import { clamp01, lowerBoundIndex } from './geometry';

describe( 'lowerBoundIndex', () => {
	it( 'returns 0 for an empty array', () => {
		expect( lowerBoundIndex( [], 5 ) ).toBe( 0 );
	} );

	it( 'returns 0 when the target is below the first entry', () => {
		expect( lowerBoundIndex( [ 10, 20, 30 ], 5 ) ).toBe( 0 );
	} );

	it( 'returns 0 for a negative target below the first entry', () => {
		expect( lowerBoundIndex( [ 0, 100, 200 ], -5 ) ).toBe( 0 );
	} );

	it( 'returns the last index when the target equals the final entry', () => {
		expect( lowerBoundIndex( [ 10, 20, 30 ], 30 ) ).toBe( 2 );
		expect( lowerBoundIndex( [ 0, 100, 200 ], 200 ) ).toBe( 2 );
	} );

	it( 'returns the last index when the target exceeds the final entry', () => {
		expect( lowerBoundIndex( [ 10, 20, 30 ], 99 ) ).toBe( 2 );
		expect( lowerBoundIndex( [ 0, 100, 200 ], 999 ) ).toBe( 2 );
	} );

	it( 'returns the predecessor index for a value strictly between two entries', () => {
		expect( lowerBoundIndex( [ 10, 20, 30 ], 15 ) ).toBe( 0 );
		expect( lowerBoundIndex( [ 10, 20, 30 ], 25 ) ).toBe( 1 );
		expect( lowerBoundIndex( [ 0, 100, 200 ], 50 ) ).toBe( 0 );
		expect( lowerBoundIndex( [ 0, 100, 200 ], 150 ) ).toBe( 1 );
	} );

	it( 'returns the matching index when the target equals an interior entry', () => {
		expect( lowerBoundIndex( [ 10, 20, 30 ], 20 ) ).toBe( 1 );
	} );
} );

describe( 'clamp01', () => {
	it( 'returns 0 for negative values', () => {
		expect( clamp01( -0.5 ) ).toBe( 0 );
		expect( clamp01( -1 ) ).toBe( 0 );
		expect( clamp01( Number.NEGATIVE_INFINITY ) ).toBe( 0 );
	} );

	it( 'returns 1 for values greater than 1', () => {
		expect( clamp01( 1.5 ) ).toBe( 1 );
		expect( clamp01( 2 ) ).toBe( 1 );
		expect( clamp01( Number.POSITIVE_INFINITY ) ).toBe( 1 );
	} );

	it( 'returns the value unchanged for values in [0, 1]', () => {
		expect( clamp01( 0 ) ).toBe( 0 );
		expect( clamp01( 0.5 ) ).toBe( 0.5 );
		expect( clamp01( 1 ) ).toBe( 1 );
	} );
} );
