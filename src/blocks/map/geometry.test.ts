/**
 * Jest tests for the GPX Map geometry helpers.
 *
 * The fixtures are deliberately small and exact: any departure from the
 * expected values is a real bug, not floating-point fuzz. Coordinates use
 * `[lat, lng]` order and are sized so a metre-aware Cartesian
 * approximation is exact for the assertions made.
 *
 * @since 0.2.0
 */

import { clickToFraction, fractionToLatLng } from './geometry';

describe( 'fractionToLatLng', () => {
	const vertices: ReadonlyArray< readonly [ number, number ] > = [
		[ 0, 0 ],
		[ 0, 1 ],
		[ 0, 3 ],
	];
	const cumDist = [ 0, 100, 300 ];
	const total = 300;

	it( 'returns null when vertices are empty', () => {
		expect( fractionToLatLng( [], [], 0, 0.5 ) ).toBeNull();
	} );

	it( 'returns the first vertex for fraction 0', () => {
		expect( fractionToLatLng( vertices, cumDist, total, 0 ) ).toEqual( [
			0, 0,
		] );
	} );

	it( 'returns the last vertex for fraction 1', () => {
		expect( fractionToLatLng( vertices, cumDist, total, 1 ) ).toEqual( [
			0, 3,
		] );
	} );

	it( 'interpolates between two vertices', () => {
		// Fraction 0.5 means 150 m which falls 50 m into the [100, 300] segment.
		// That's t = 0.25 across the [0,1]→[0,3] range, so lng = 1 + 0.25*2 = 1.5.
		const out = fractionToLatLng( vertices, cumDist, total, 0.5 );
		expect( out ).not.toBeNull();
		const [ lat, lng ] = out!;
		expect( lat ).toBeCloseTo( 0, 10 );
		expect( lng ).toBeCloseTo( 1.5, 10 );
	} );

	it( 'clamps fractions beyond [0, 1]', () => {
		expect( fractionToLatLng( vertices, cumDist, total, -0.5 ) ).toEqual( [
			0, 0,
		] );
		expect( fractionToLatLng( vertices, cumDist, total, 1.5 ) ).toEqual( [
			0, 3,
		] );
	} );

	it( 'collapses to the vertex on a zero-length segment', () => {
		const v: ReadonlyArray< readonly [ number, number ] > = [
			[ 10, 20 ],
			[ 10, 20 ],
		];
		const cd = [ 0, 0 ];
		expect( fractionToLatLng( v, cd, 0, 0.5 ) ).toEqual( [ 10, 20 ] );
	} );

	it( 'returns the lone vertex for a single-point input', () => {
		expect( fractionToLatLng( [ [ 5, 6 ] ], [ 0 ], 0, 0.7 ) ).toEqual( [
			5, 6,
		] );
	} );
} );

describe( 'clickToFraction', () => {
	// A right-angle corner: south-east edge then north-east edge. Each leg has
	// the same Cartesian length in the test's fictitious coordinate frame.
	const vertices: ReadonlyArray< readonly [ number, number ] > = [
		[ 0, 0 ],
		[ 0, 100 ],
		[ 100, 100 ],
	];
	const cumDist = [ 0, 100, 200 ];
	const total = 200;

	it( 'returns fraction 0 when clicking exactly on the start vertex', () => {
		const out = clickToFraction( vertices, cumDist, total, [ 0, 0 ] );
		expect( out.fraction ).toBeCloseTo( 0, 10 );
	} );

	it( 'returns fraction 1 when clicking exactly on the end vertex', () => {
		const out = clickToFraction( vertices, cumDist, total, [ 100, 100 ] );
		expect( out.fraction ).toBeCloseTo( 1, 10 );
	} );

	it( 'returns 0.5 when clicking exactly on the corner vertex', () => {
		const out = clickToFraction( vertices, cumDist, total, [ 0, 100 ] );
		expect( out.fraction ).toBeCloseTo( 0.5, 10 );
	} );

	it( 'projects a click onto the middle of the first segment', () => {
		const out = clickToFraction( vertices, cumDist, total, [ 5, 50 ] );
		// Closest point on the first segment is [0, 50] — exactly halfway
		// along a 100 m segment whose far end carries cumulative distance 100,
		// so the fraction is 50 / 200 = 0.25.
		expect( out.fraction ).toBeCloseTo( 0.25, 10 );
		expect( out.latLng[ 0 ] ).toBeCloseTo( 0, 10 );
		expect( out.latLng[ 1 ] ).toBeCloseTo( 50, 10 );
	} );

	it( 'clamps t when the click falls on the line extension before the segment', () => {
		// Click far to the left of the first segment's start.
		const out = clickToFraction( vertices, cumDist, total, [ 0, -50 ] );
		expect( out.fraction ).toBeCloseTo( 0, 10 );
		expect( out.latLng ).toEqual( [ 0, 0 ] );
	} );

	it( 'clamps t when the click falls on the line extension past the end', () => {
		// Click far past the second segment's end.
		const out = clickToFraction( vertices, cumDist, total, [ 200, 100 ] );
		expect( out.fraction ).toBeCloseTo( 1, 10 );
		expect( out.latLng ).toEqual( [ 100, 100 ] );
	} );

	it( 'survives a zero-length segment without dividing by zero', () => {
		// First two vertices coincide; the second segment is a real one.
		const v: ReadonlyArray< readonly [ number, number ] > = [
			[ 0, 0 ],
			[ 0, 0 ],
			[ 0, 100 ],
		];
		const cd = [ 0, 0, 100 ];
		const out = clickToFraction( v, cd, 100, [ 0, 50 ] );
		expect( out.fraction ).toBeCloseTo( 0.5, 10 );
		expect( out.latLng[ 1 ] ).toBeCloseTo( 50, 10 );
	} );

	it( 'returns a defensive zero fraction for empty inputs', () => {
		const out = clickToFraction( [], [], 0, [ 0, 0 ] );
		expect( out.fraction ).toBe( 0 );
	} );
} );
