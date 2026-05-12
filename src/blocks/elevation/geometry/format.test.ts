/**
 * Unit tests for the locale-aware label formatters.
 *
 * Pins the Step 3 formatting contract:
 *
 *   - Y labels always carry `" m"`.
 *   - X labels stay in metres unless more than half of the non-zero
 *     values are ≥ 1000, in which case the whole axis flips to km, the
 *     last label gains `" km"`, and intermediate labels are unitless.
 *   - Precision follows the nice step: ≥ 1 → 0 decimals,
 *     `[0.1, 1)` → 1 decimal, `< 0.1` → 2 decimals.
 *
 * The locale is pinned to `'sv-SE'` so the assertions read against the
 * canonical Swedish comma decimal separator the project uses.
 *
 * @since 1.0.0
 */

import {
	chooseXUnit,
	formatNumber,
	formatXLabels,
	formatYLabels,
} from './format';

describe( 'formatNumber', () => {
	it( 'uses comma decimal separator under sv-SE', () => {
		expect( formatNumber( 1.5, 1, 'sv-SE' ) ).toBe( '1,5' );
	} );

	it( 'pads the minimum fraction digits', () => {
		expect( formatNumber( 2, 1, 'sv-SE' ) ).toBe( '2,0' );
	} );

	it( 'truncates to the maximum fraction digits', () => {
		// 1.2345 → 1,2 at 1-digit precision.
		expect( formatNumber( 1.2345, 1, 'sv-SE' ) ).toBe( '1,2' );
	} );

	it( 'does not group thousands', () => {
		expect( formatNumber( 12345, 0, 'sv-SE' ) ).toBe( '12345' );
	} );

	it( 'formats negative values with a locale-specific minus sign', () => {
		// Node's ICU build for sv-SE emits the Unicode minus sign
		// (U+2212), not the ASCII hyphen-minus. Both forms are valid
		// minus signs; the test pins the actual output so a future
		// ICU bump that flips back to ASCII is caught.
		expect( formatNumber( -42, 0, 'sv-SE' ) ).toBe( '−42' );
	} );
} );

describe( 'chooseXUnit', () => {
	it( 'returns "m" for an empty list', () => {
		expect( chooseXUnit( [] ) ).toBe( 'm' );
	} );

	it( 'returns "m" when all values are zero', () => {
		expect( chooseXUnit( [ 0, 0, 0 ] ) ).toBe( 'm' );
	} );

	it( 'returns "m" when every non-zero value is below 1000', () => {
		expect( chooseXUnit( [ 0, 200, 400, 600, 800 ] ) ).toBe( 'm' );
	} );

	it( 'returns "m" at exactly half of non-zero values ≥ 1000 (not more than half)', () => {
		// 4 non-zero, 2 ≥ 1000 → exactly half → still "m".
		expect( chooseXUnit( [ 0, 500, 800, 1000, 1500 ] ) ).toBe( 'm' );
	} );

	it( 'flips to "km" once more than half of non-zero values are ≥ 1000', () => {
		// 4 non-zero, 3 ≥ 1000 → > half → "km".
		expect( chooseXUnit( [ 0, 500, 1000, 1500, 2000 ] ) ).toBe( 'km' );
	} );
} );

describe( 'formatYLabels', () => {
	it( 'suffixes every label with " m" at integer precision for step ≥ 1', () => {
		expect( formatYLabels( [ 0, 100, 200, 300 ], 100, 'sv-SE' ) ).toEqual( [
			'0 m',
			'100 m',
			'200 m',
			'300 m',
		] );
	} );

	it( 'uses one decimal when the nice step is in [0.1, 1)', () => {
		expect( formatYLabels( [ 1.0, 1.5, 2.0, 2.5 ], 0.5, 'sv-SE' ) ).toEqual(
			[ '1,0 m', '1,5 m', '2,0 m', '2,5 m' ]
		);
	} );

	it( 'uses two decimals when the nice step is below 0.1', () => {
		expect( formatYLabels( [ 0.0, 0.05, 0.1 ], 0.05, 'sv-SE' ) ).toEqual( [
			'0,00 m',
			'0,05 m',
			'0,10 m',
		] );
	} );
} );

describe( 'formatXLabels', () => {
	it( 'stays in metres when no value crosses the 1000 m threshold', () => {
		expect(
			formatXLabels( [ 0, 200, 400, 600, 800 ], 200, 'sv-SE' )
		).toEqual( [ '0 m', '200 m', '400 m', '600 m', '800 m' ] );
	} );

	it( 'flips to kilometres when more than half of non-zero values are ≥ 1000', () => {
		const labels = formatXLabels(
			[ 0, 500, 1000, 1500, 2000 ],
			500,
			'sv-SE'
		);
		// Intermediate labels are unitless in km mode; only the last one carries " km".
		expect( labels ).toEqual( [ '0,0', '0,5', '1,0', '1,5', '2,0 km' ] );
	} );

	it( 'suffixes the last label only with " km"', () => {
		const labels = formatXLabels(
			[ 0, 1000, 2000, 3000, 4000, 5000 ],
			1000,
			'sv-SE'
		);
		expect( labels[ labels.length - 1 ] ).toBe( '5,0 km' );
		labels
			.slice( 0, -1 )
			.forEach( ( l ) => expect( l ).not.toContain( 'km' ) );
	} );
} );
