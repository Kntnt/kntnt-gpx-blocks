<?php
/**
 * Tests for Format\Value_Formatter.
 *
 * Brain Monkey stubs number_format_i18n() and apply_filters() so the formatter
 * can run without WordPress. Each test targets a distinct formatting rule:
 * sub-threshold metres, threshold boundary, super-threshold kilometres,
 * elevation-always-metres, and filter override.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Format\Value_Formatter;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Stubs number_format_i18n() with a deterministic implementation that
 * formats with a plain decimal point and no thousands separator, matching
 * the behaviour the tests assert against.
 */
function stub_number_format_i18n(): void {
	Functions\when( 'number_format_i18n' )->alias(
		static function ( float|int $number, int $decimals ): string {
			return number_format( (float) $number, $decimals, '.', '' );
		}
	);
}

/**
 * Stubs apply_filters() to pass the first argument (the already-formatted
 * string) through unchanged, unless the test has registered an override.
 *
 * @param array<string, mixed> $overrides Map of filter name => return value.
 */
function stub_filters_formatter( array $overrides = [] ): void {
	Functions\when( 'apply_filters' )->alias(
		static function ( string $filter, mixed $fallback ) use ( $overrides ): mixed {
			return array_key_exists( $filter, $overrides ) ? $overrides[ $filter ] : $fallback;
		}
	);
}

// ---------------------------------------------------------------------------
// format_distance: sub-threshold (< 1000 m)
// ---------------------------------------------------------------------------

test( 'format_distance returns whole metres below threshold', function (): void {

	stub_number_format_i18n();
	stub_filters_formatter();

	$formatter = new Value_Formatter();
	$result    = $formatter->format_distance( 500.0 );

	expect( $result )->toBe( '500 m' );

} );

test( 'format_distance returns whole metres for 999 m', function (): void {

	stub_number_format_i18n();
	stub_filters_formatter();

	$formatter = new Value_Formatter();
	$result    = $formatter->format_distance( 999.0 );

	expect( $result )->toBe( '999 m' );

} );

// ---------------------------------------------------------------------------
// format_distance: at threshold (= 1000 m)
// ---------------------------------------------------------------------------

test( 'format_distance switches to km at exactly 1000 m', function (): void {

	stub_number_format_i18n();
	stub_filters_formatter();

	$formatter = new Value_Formatter();
	$result    = $formatter->format_distance( 1000.0 );

	expect( $result )->toBe( '1.0 km' );

} );

// ---------------------------------------------------------------------------
// format_distance: super-threshold (> 1000 m)
// ---------------------------------------------------------------------------

test( 'format_distance returns one-decimal km above threshold', function (): void {

	stub_number_format_i18n();
	stub_filters_formatter();

	$formatter = new Value_Formatter();
	$result    = $formatter->format_distance( 12345.0 );

	expect( $result )->toBe( '12.3 km' );

} );

// ---------------------------------------------------------------------------
// format_elevation: always metres, no decimals
// ---------------------------------------------------------------------------

test( 'format_elevation returns whole metres', function (): void {

	stub_number_format_i18n();
	stub_filters_formatter();

	$formatter = new Value_Formatter();
	$result    = $formatter->format_elevation( 123.7 );

	// number_format rounds 123.7 to 124 at 0 decimals.
	expect( $result )->toBe( '124 m' );

} );

test( 'format_elevation returns whole metres for negative elevation', function (): void {

	stub_number_format_i18n();
	stub_filters_formatter();

	$formatter = new Value_Formatter();
	$result    = $formatter->format_elevation( -12.4 );

	expect( $result )->toBe( '-12 m' );

} );

// ---------------------------------------------------------------------------
// Filter override: distance
// ---------------------------------------------------------------------------

test( 'format_distance applies kntnt_gpx_blocks_format_distance filter', function (): void {

	stub_number_format_i18n();
	stub_filters_formatter( [ 'kntnt_gpx_blocks_format_distance' => '0.3 mi' ] );

	$formatter = new Value_Formatter();
	$result    = $formatter->format_distance( 500.0 );

	// The filter return value completely replaces the default.
	expect( $result )->toBe( '0.3 mi' );

} );

// ---------------------------------------------------------------------------
// Filter override: elevation
// ---------------------------------------------------------------------------

test( 'format_elevation applies kntnt_gpx_blocks_format_elevation filter', function (): void {

	stub_number_format_i18n();
	stub_filters_formatter( [ 'kntnt_gpx_blocks_format_elevation' => '400 ft' ] );

	$formatter = new Value_Formatter();
	$result    = $formatter->format_elevation( 123.0 );

	expect( $result )->toBe( '400 ft' );

} );
