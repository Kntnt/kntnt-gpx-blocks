<?php
/**
 * Tests for Bootstrap\Variation_Registrar.
 *
 * Covers the registrar's responsibilities: enqueuing the variation
 * registration script, the editor-only preview script + stylesheet, wiring
 * up `wp_set_script_translations()` so each script's `__()` calls pick up
 * the plugin's text domain, and the defensive guards for missing assets.
 *
 * The registrar accepts a constructor-injectable plugin root so tests
 * point it at the real `js/`/`css/` directories (for happy-path coverage)
 * or at a non-existent directory (for the missing-asset guards) without
 * touching the Plugin singleton.
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
	Functions\when( 'wp_enqueue_style' )->justReturn( true );

	$registrar = new Variation_Registrar( __DIR__ . '/../../..' );
	$registrar->enqueue();

	expect( $captured_handles )->toHaveKey( 'kntnt-gpx-blocks-statistics-variation' );
	expect( $captured_handles['kntnt-gpx-blocks-statistics-variation'] )->toContain( 'wp-blocks' );
	expect( $captured_handles['kntnt-gpx-blocks-statistics-variation'] )->toContain( 'wp-i18n' );

} );

test( 'enqueues the editor preview script with the documented dependencies', function (): void {

	$captured_handles = [];

	Functions\when( 'wp_enqueue_script' )->alias(
		static function ( string $handle, string $url, array $deps ) use ( &$captured_handles ): void {
			$captured_handles[ $handle ] = $deps;
		}
	);
	Functions\when( 'wp_set_script_translations' )->justReturn( true );
	Functions\when( 'wp_enqueue_style' )->justReturn( true );

	$registrar = new Variation_Registrar( __DIR__ . '/../../..' );
	$registrar->enqueue();

	expect( $captured_handles )->toHaveKey( 'kntnt-gpx-blocks-statistics-preview' );
	$preview_deps = $captured_handles['kntnt-gpx-blocks-statistics-preview'];

	// The preview HOC needs wp.hooks (addFilter), wp.element (createElement,
	// useState, useEffect), wp.compose (createHigherOrderComponent), wp.data
	// (useSelect), wp.i18n (__), and wp.apiFetch.
	foreach ( [ 'wp-hooks', 'wp-element', 'wp-compose', 'wp-data', 'wp-i18n', 'wp-api-fetch' ] as $dep ) {
		expect( $preview_deps )->toContain( $dep );
	}

} );

test( 'enqueues the editor preview stylesheet alongside the script', function (): void {

	$captured_styles = [];

	Functions\when( 'wp_enqueue_script' )->justReturn( true );
	Functions\when( 'wp_set_script_translations' )->justReturn( true );
	Functions\when( 'wp_enqueue_style' )->alias(
		static function ( string $handle, string $url ) use ( &$captured_styles ): void {
			$captured_styles[ $handle ] = $url;
		}
	);

	$registrar = new Variation_Registrar( __DIR__ . '/../../..' );
	$registrar->enqueue();

	expect( $captured_styles )->toHaveKey( 'kntnt-gpx-blocks-statistics-preview' );
	expect( $captured_styles['kntnt-gpx-blocks-statistics-preview'] )->toContain( '/css/statistics-preview.css' );

} );

test( 'wires script translations for both scripts', function (): void {

	$translated_handles = [];

	Functions\when( 'wp_enqueue_script' )->justReturn( true );
	Functions\when( 'wp_enqueue_style' )->justReturn( true );
	Functions\when( 'wp_set_script_translations' )->alias(
		static function ( string $handle, string $domain ) use ( &$translated_handles ): bool {
			$translated_handles[ $handle ] = $domain;
			return true;
		}
	);

	$registrar = new Variation_Registrar( __DIR__ . '/../../..' );
	$registrar->enqueue();

	expect( $translated_handles )->toHaveKey( 'kntnt-gpx-blocks-statistics-variation' );
	expect( $translated_handles )->toHaveKey( 'kntnt-gpx-blocks-statistics-preview' );
	expect( $translated_handles['kntnt-gpx-blocks-statistics-variation'] )->toBe( 'kntnt-gpx-blocks' );
	expect( $translated_handles['kntnt-gpx-blocks-statistics-preview'] )->toBe( 'kntnt-gpx-blocks' );

} );

test( 'script URL points at the js/statistics-variation.js file', function (): void {

	$captured_urls = [];

	Functions\when( 'wp_enqueue_script' )->alias(
		static function ( string $handle, string $url ) use ( &$captured_urls ): void {
			$captured_urls[ $handle ] = $url;
		}
	);
	Functions\when( 'wp_set_script_translations' )->justReturn( true );
	Functions\when( 'wp_enqueue_style' )->justReturn( true );

	$registrar = new Variation_Registrar( __DIR__ . '/../../..' );
	$registrar->enqueue();

	expect( $captured_urls['kntnt-gpx-blocks-statistics-variation'] ?? '' )->toContain( '/js/statistics-variation.js' );
	expect( $captured_urls['kntnt-gpx-blocks-statistics-preview'] ?? '' )->toContain( '/js/statistics-preview.js' );

} );

// ---------------------------------------------------------------------------
// Defensive guard: missing assets
// ---------------------------------------------------------------------------

test( 'logs a warning and skips every enqueue when the plugin root is missing', function (): void {

	Functions\expect( 'wp_enqueue_script' )->never();
	Functions\expect( 'wp_set_script_translations' )->never();
	Functions\expect( 'wp_enqueue_style' )->never();

	$registrar = new Variation_Registrar( '/tmp/kntnt-gpx-blocks-nonexistent-' . uniqid() );
	$registrar->enqueue();

} );
