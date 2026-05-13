/**
 * Unit tests for the locale-aware label formatters.
 *
 * Pins the Step 4 formatting contract:
 *
 *   - Y labels always carry `" m"`.
 *   - X labels stay in metres when `distance < 2000`; otherwise the
 *     whole axis flips to km, the last label gains `" km"`, and
 *     intermediate labels are unitless.
 *   - `xReferenceString(distance)` returns a worst-case label whose
 *     rendered width is ≥ the actual widest X label for the given
 *     distance — used by `margins.ts` to compute `wRight` without first
 *     knowing the tick count, and by `chart.tsx` / `view.ts` to compute
 *     `N_x` via the tick-count helper in `ticks.ts`.
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
	xReferenceString,
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
	it( 'returns "m" for distances below 2000', () => {
		expect( chooseXUnit( 1 ) ).toBe( 'm' );
		expect( chooseXUnit( 100 ) ).toBe( 'm' );
		expect( chooseXUnit( 1999 ) ).toBe( 'm' );
	} );

	it( 'returns "km" at exactly 2000 and beyond', () => {
		expect( chooseXUnit( 2000 ) ).toBe( 'km' );
		expect( chooseXUnit( 5000 ) ).toBe( 'km' );
		expect( chooseXUnit( 100000 ) ).toBe( 'km' );
	} );
} );

describe( 'xReferenceString', () => {
	// m-mode verification table (Step 4 spec).
	it.each( [
		[ 99, '888 m' ],
		[ 100, '8888 m' ],
		[ 500, '8888 m' ],
		[ 888, '8888 m' ],
		[ 999, '8888 m' ],
		[ 1500, '8888 m' ],
		[ 1999, '8888 m' ],
	] )( 'm-mode: distance=%i → %s', ( distance, expected ) => {
		expect( xReferenceString( distance, 'sv-SE' ) ).toBe( expected );
	} );

	// km-mode verification table (Step 4 spec).
	it.each( [
		[ 2000, '88,8 km' ],
		[ 9999, '88,8 km' ],
		[ 20000, '888,8 km' ],
		[ 99999, '888,8 km' ],
		[ 100000, '8888,8 km' ],
	] )( 'km-mode: distance=%i → %s', ( distance, expected ) => {
		expect( xReferenceString( distance, 'sv-SE' ) ).toBe( expected );
	} );

	it( 'routes through the locale-aware decimal separator in km-mode', () => {
		// sv-SE uses comma, en-US uses dot. Both forms are well-formed
		// km-mode reference strings; the assertion catches the locale
		// branch.
		expect( xReferenceString( 5000, 'sv-SE' ) ).toBe( '88,8 km' );
		expect( xReferenceString( 5000, 'en-US' ) ).toBe( '88.8 km' );
	} );

	it( 'handles a degenerate distance of 0 in m-mode without crashing', () => {
		// floor(log10(max(0, 1))) + 2 = 0 + 2 = 2 → "88 m".
		expect( xReferenceString( 0, 'sv-SE' ) ).toBe( '88 m' );
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
	it( 'stays in metres when distance < 2000', () => {
		expect(
			formatXLabels( [ 0, 200, 400, 600, 800 ], 200, 1000, 'sv-SE' )
		).toEqual( [ '0 m', '200 m', '400 m', '600 m', '800 m' ] );
	} );

	it( 'flips to kilometres at distance ≥ 2000', () => {
		const labels = formatXLabels(
			[ 0, 500, 1000, 1500, 2000 ],
			500,
			2000,
			'sv-SE'
		);
		// Intermediate labels are unitless in km mode; only the last one carries " km".
		expect( labels ).toEqual( [ '0,0', '0,5', '1,0', '1,5', '2,0 km' ] );
	} );

	it( 'suffixes the last label only with " km" in km-mode', () => {
		const labels = formatXLabels(
			[ 0, 1000, 2000, 3000, 4000, 5000 ],
			1000,
			5000,
			'sv-SE'
		);
		expect( labels[ labels.length - 1 ] ).toBe( '5,0 km' );
		labels
			.slice( 0, -1 )
			.forEach( ( l ) => expect( l ).not.toContain( 'km' ) );
	} );
} );
