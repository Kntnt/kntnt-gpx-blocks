/**
 * Unit tests for {@link formatDistance} and {@link formatElevation}.
 *
 * Locale-aware: the assertions use `Intl.NumberFormat` directly to
 * compute the expected separator characters, so they pass regardless of
 * the host's locale data nuances (e.g. sv-SE's non-breaking space vs.
 * the regular space). The contract under test is the m/km threshold and
 * the decimal precision, not the literal separator codepoints.
 *
 * @since 1.0.0
 */
import { formatDistance, formatElevation } from './tooltip-format';

/**
 * Formats a value with the supplied locale and decimal precision so the
 * tests can pin the expected output without hard-coding locale-specific
 * separators.
 *
 * @since 1.0.0
 *
 * @param value          Numeric value to format.
 * @param locale         Locale string.
 * @param fractionDigits Decimal precision.
 * @return The locale-formatted string.
 */
function localeNumber(
	value: number,
	locale: string,
	fractionDigits: number
): string {
	return new Intl.NumberFormat( locale, {
		minimumFractionDigits: fractionDigits,
		maximumFractionDigits: fractionDigits,
	} ).format( value );
}

describe( 'formatDistance', () => {
	it( 'returns metres below 2000 m with 0 decimals and locale grouping (sv-SE)', () => {
		expect( formatDistance( 1234, 'sv-SE' ) ).toBe(
			`${ localeNumber( 1234, 'sv-SE', 0 ) } m`
		);
	} );

	it( 'returns metres at the 1999 m boundary (threshold is inclusive on the m side, en-US)', () => {
		expect( formatDistance( 1999, 'en-US' ) ).toBe(
			`${ localeNumber( 1999, 'en-US', 0 ) } m`
		);
	} );

	it( 'flips to km at the 2000 m threshold (en-US)', () => {
		expect( formatDistance( 2000, 'en-US' ) ).toBe(
			`${ localeNumber( 2, 'en-US', 1 ) } km`
		);
	} );

	it( 'returns km with 1 decimal above the threshold (sv-SE)', () => {
		expect( formatDistance( 2500, 'sv-SE' ) ).toBe(
			`${ localeNumber( 2.5, 'sv-SE', 1 ) } km`
		);
	} );

	it( 'rounds to 1 decimal in km mode (en-US 5234 → 5.2 km)', () => {
		expect( formatDistance( 5234, 'en-US' ) ).toBe(
			`${ localeNumber( 5.234, 'en-US', 1 ) } km`
		);
	} );

	it( 'returns small metres unchanged (no thousand grouping needed)', () => {
		expect( formatDistance( 247, 'sv-SE' ) ).toBe( '247 m' );
		expect( formatDistance( 247, 'en-US' ) ).toBe( '247 m' );
	} );
} );

describe( 'formatElevation', () => {
	it( 'always returns metres with 0 decimals (sv-SE)', () => {
		expect( formatElevation( 247, 'sv-SE' ) ).toBe( '247 m' );
	} );

	it( 'never switches to km on tracks above 2000 m (sv-SE 2800 → 2 800 m)', () => {
		// The deliberate departure from the spec's literal m/km switching
		// on line 2: see tooltip-format.ts module header for the rationale.
		expect( formatElevation( 2800, 'sv-SE' ) ).toBe(
			`${ localeNumber( 2800, 'sv-SE', 0 ) } m`
		);
	} );

	it( 'formats negative elevations correctly (coastal tracks below sea level)', () => {
		expect( formatElevation( -42, 'sv-SE' ) ).toBe(
			`${ localeNumber( -42, 'sv-SE', 0 ) } m`
		);
	} );
} );
