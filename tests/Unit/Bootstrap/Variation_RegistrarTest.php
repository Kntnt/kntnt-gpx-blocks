<?php
/**
 * Tests for Bootstrap\Variation_Registrar.
 *
 * Covers the registrar's two responsibilities: enqueuing the variation
 * registration script with the right handle and dependencies, and wiring
 * up `wp_set_script_translations()` so the script's `__()` calls pick up
 * the plugin's text domain. Also covers the defensive guard for a missing
 * script file.
 *
 * The registrar accepts a constructor-injectable plugin root so tests
 * point it at the real `js/statistics-variation.js` (for happy-path
 * coverage) or at a non-existent directory (for the missing-script guard)
 * without touching the Plugin singleton.
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
// Happy path: real bundled script file
// ---------------------------------------------------------------------------

test( 'enqueues the variation script with the right handle and dependencies', function (): void {

	Functions\expect( 'wp_enqueue_script' )
		->once()
		->with(
			'kntnt-gpx-blocks-statistics-variation',
			Mockery::type( 'string' ),
			Mockery::on(
				static fn ( array $deps ): bool => in_array( 'wp-blocks', $deps, true )
					&& in_array( 'wp-i18n', $deps, true )
			),
			Mockery::type( 'string' ),
			true,
		);

	Functions\expect( 'wp_set_script_translations' )
		->once()
		->with( 'kntnt-gpx-blocks-statistics-variation', 'kntnt-gpx-blocks' );

	$registrar = new Variation_Registrar( __DIR__ . '/../../..' );
	$registrar->enqueue();

} );

test( 'script URL points at the js/statistics-variation.js file', function (): void {

	$captured_url = null;

	Functions\when( 'wp_enqueue_script' )->alias(
		static function ( string $handle, string $url ) use ( &$captured_url ): void {
			$captured_url = $url;
		}
	);
	Functions\when( 'wp_set_script_translations' )->justReturn( true );

	$registrar = new Variation_Registrar( __DIR__ . '/../../..' );
	$registrar->enqueue();

	expect( $captured_url )->toContain( '/js/statistics-variation.js' );

} );

// ---------------------------------------------------------------------------
// Defensive guard: missing script file
// ---------------------------------------------------------------------------

test( 'logs a warning and skips enqueue when the script file is missing', function (): void {

	Functions\expect( 'wp_enqueue_script' )->never();
	Functions\expect( 'wp_set_script_translations' )->never();

	$registrar = new Variation_Registrar( '/tmp/kntnt-gpx-blocks-nonexistent-' . uniqid() );
	$registrar->enqueue();

} );
