<?php
/**
 * Tests for Bootstrap\Variation_Registrar.
 *
 * Covers the registrar's responsibilities: enqueuing the variation
 * registration script, wiring up `wp_set_script_translations()` so the
 * script's `__()` calls pick up the plugin's text domain, and the
 * defensive guard for a missing asset.
 *
 * The registrar accepts a constructor-injectable plugin root so tests
 * point it at the real `js/` directory (for happy-path coverage) or at
 * a non-existent directory (for the missing-asset guard) without touching
 * the Plugin singleton.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Bootstrap\Variation_Registrar;

beforeEach( function (): void {

	Functions\when( 'plugins_url' )->alias(
		static function ( string $path, string $plugin = '' ): string {
			return 'https://example.com/wp-content/plugins/kntnt-gpx-blocks' . $path;
		}
	);

} );

// ---------------------------------------------------------------------------
// Happy path: real bundled assets
// ---------------------------------------------------------------------------

test( 'enqueues the variation script with the right handle and dependencies', function (): void {

	$captured_handles = [];

	Functions\when( 'wp_enqueue_script' )->alias(
		static function ( string $handle, string $url, array $deps ) use ( &$captured_handles ): void {
			$captured_handles[ $handle ] = $deps;
		}
	);
	Functions\when( 'wp_set_script_translations' )->justReturn( true );

	$registrar = new Variation_Registrar( __DIR__ . '/../../..' );
	$registrar->enqueue();

	expect( $captured_handles )->toHaveKey( 'kntnt-gpx-blocks-statistics-variation' );
	expect( $captured_handles['kntnt-gpx-blocks-statistics-variation'] )->toContain( 'wp-blocks' );
	expect( $captured_handles['kntnt-gpx-blocks-statistics-variation'] )->toContain( 'wp-i18n' );

} );

test( 'wires script translations for the variation script', function (): void {

	$translated_handles = [];

	Functions\when( 'wp_enqueue_script' )->justReturn( true );
	Functions\when( 'wp_set_script_translations' )->alias(
		static function ( string $handle, string $domain ) use ( &$translated_handles ): bool {
			$translated_handles[ $handle ] = $domain;
			return true;
		}
	);

	$registrar = new Variation_Registrar( __DIR__ . '/../../..' );
	$registrar->enqueue();

	expect( $translated_handles )->toHaveKey( 'kntnt-gpx-blocks-statistics-variation' );
	expect( $translated_handles['kntnt-gpx-blocks-statistics-variation'] )->toBe( 'kntnt-gpx-blocks' );

} );

test( 'script URL points at the js/statistics-variation.js file', function (): void {

	$captured_urls = [];

	Functions\when( 'wp_enqueue_script' )->alias(
		static function ( string $handle, string $url ) use ( &$captured_urls ): void {
			$captured_urls[ $handle ] = $url;
		}
	);
	Functions\when( 'wp_set_script_translations' )->justReturn( true );

	$registrar = new Variation_Registrar( __DIR__ . '/../../..' );
	$registrar->enqueue();

	expect( $captured_urls['kntnt-gpx-blocks-statistics-variation'] ?? '' )->toContain( '/js/statistics-variation.js' );

} );

// ---------------------------------------------------------------------------
// Defensive guard: missing asset
// ---------------------------------------------------------------------------

test( 'logs a warning and skips the enqueue when the script is missing', function (): void {

	Functions\expect( 'wp_enqueue_script' )->never();
	Functions\expect( 'wp_set_script_translations' )->never();

	$registrar = new Variation_Registrar( '/tmp/kntnt-gpx-blocks-nonexistent-' . uniqid() );
	$registrar->enqueue();

} );
