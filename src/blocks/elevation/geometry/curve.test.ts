/**
 * Unit tests for the elevation curve `d`-attribute builders.
 *
 * Both helpers are pure: identity projections plus a 5-sample fixture
 * produce a byte-deterministic `d` string. The tests pin every clause
 * of the Step 5 spec — `toFixed(1)` precision, the `< 2`-sample early
 * return, the closed-vs-open path distinction.
 *
 * @since 1.0.0
 */

import { buildFillPathD, buildStrokePathD } from './curve';

const identityProjectX = ( d: number ): number => d;
const identityProjectY = ( e: number ): number => e;

describe( 'buildStrokePathD', () => {
	it( 'returns "" when samples has fewer than 2 entries', () => {
		expect(
			buildStrokePathD( [], identityProjectX, identityProjectY )
		).toBe( '' );
		expect(
			buildStrokePathD(
				[ [ 0, 100 ] ],
				identityProjectX,
				identityProjectY
			)
		).toBe( '' );
	} );

	it( 'builds the expected "M … L … L …" string for a 5-sample fixture', () => {
		const samples: ReadonlyArray< readonly [ number, number ] > = [
			[ 0, 100 ],
			[ 1, 110 ],
			[ 2, 105 ],
			[ 3, 120 ],
			[ 4, 95 ],
		];
		const d = buildStrokePathD(
			samples,
			identityProjectX,
			identityProjectY
		);
		expect( d ).toBe(
			'M 0.0 100.0 L 1.0 110.0 L 2.0 105.0 L 3.0 120.0 L 4.0 95.0'
		);
	} );

	it( 'does not end with " Z" (open stroke path)', () => {
		const samples: ReadonlyArray< readonly [ number, number ] > = [
			[ 0, 100 ],
			[ 1, 110 ],
		];
		const d = buildStrokePathD(
			samples,
			identityProjectX,
			identityProjectY
		);
		expect( d.endsWith( ' Z' ) ).toBe( false );
	} );

	it( 'emits exactly one decimal place on every coordinate', () => {
		const samples: ReadonlyArray< readonly [ number, number ] > = [
			[ 0.123, 100.456 ],
			[ 1.789, 200.111 ],
		];
		const d = buildStrokePathD(
			samples,
			identityProjectX,
			identityProjectY
		);
		// Every numeric token must match `\d+\.\d` (one digit after the
		// decimal point).
		const numericTokens = d
			.split( /[ML ]+/ )
			.map( ( s ) => s.trim() )
			.filter( ( s ) => s.length > 0 );
		for ( const token of numericTokens ) {
			expect( token ).toMatch( /^-?\d+\.\d$/ );
		}
	} );

	it( 'routes coordinates through the supplied projection callbacks', () => {
		const samples: ReadonlyArray< readonly [ number, number ] > = [
			[ 0, 100 ],
			[ 10, 200 ],
		];
		// Scaling projections: distance → distance × 10; elevation →
		// 500 − elevation × 2 (the typical Y-flip the chart uses).
		const projectX = ( d: number ): number => d * 10;
		const projectY = ( e: number ): number => 500 - e * 2;
		const d = buildStrokePathD( samples, projectX, projectY );
		expect( d ).toBe( 'M 0.0 300.0 L 100.0 100.0' );
	} );
} );

describe( 'buildFillPathD', () => {
	it( 'returns "" when samples has fewer than 2 entries', () => {
		expect(
			buildFillPathD( [], identityProjectX, identityProjectY, 400 )
		).toBe( '' );
		expect(
			buildFillPathD(
				[ [ 0, 100 ] ],
				identityProjectX,
				identityProjectY,
				400
			)
		).toBe( '' );
	} );

	it( 'builds the closed "M x0 plotBottom L x0 y0 … L xn plotBottom Z" string', () => {
		const samples: ReadonlyArray< readonly [ number, number ] > = [
			[ 0, 100 ],
			[ 1, 110 ],
			[ 2, 105 ],
		];
		const d = buildFillPathD(
			samples,
			identityProjectX,
			identityProjectY,
			400
		);
		expect( d ).toBe(
			'M 0.0 400.0 L 0.0 100.0 L 1.0 110.0 L 2.0 105.0 L 2.0 400.0 Z'
		);
	} );

	it( 'ends with " Z" (closed fill path)', () => {
		const samples: ReadonlyArray< readonly [ number, number ] > = [
			[ 0, 100 ],
			[ 1, 110 ],
		];
		const d = buildFillPathD(
			samples,
			identityProjectX,
			identityProjectY,
			400
		);
		expect( d.endsWith( ' Z' ) ).toBe( true );
	} );

	it( 'emits exactly one decimal place on every coordinate including plotBottom', () => {
		const samples: ReadonlyArray< readonly [ number, number ] > = [
			[ 0, 100.5 ],
			[ 1, 110.5 ],
		];
		const d = buildFillPathD(
			samples,
			identityProjectX,
			identityProjectY,
			400.25
		);
		// Strip the leading 'M ', trailing ' Z', and the 'L ' separators.
		const numericTokens = d
			.replace( / Z$/, '' )
			.split( /[ML ]+/ )
			.map( ( s ) => s.trim() )
			.filter( ( s ) => s.length > 0 );
		for ( const token of numericTokens ) {
			expect( token ).toMatch( /^-?\d+\.\d$/ );
		}
	} );

	it( 'closes against the supplied plotBottom value, not against samples[0]', () => {
		const samples: ReadonlyArray< readonly [ number, number ] > = [
			[ 0, 100 ],
			[ 1, 110 ],
		];
		const d = buildFillPathD(
			samples,
			identityProjectX,
			identityProjectY,
			500
		);
		expect( d ).toBe( 'M 0.0 500.0 L 0.0 100.0 L 1.0 110.0 L 1.0 500.0 Z' );
	} );
} );
