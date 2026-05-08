<?php
/**
 * Tests for Consent\Consent_Stub.
 *
 * Brain Monkey stubs the WordPress functions called by `enqueue()` so the
 * class can be exercised without a live WordPress install. The stub source
 * is read from the real `js/consent-stub.js` shipped with the plugin.
 *
 * Coverage:
 * - enqueue() registers the synthetic script handle in <head> (not footer).
 * - enqueue() inlines the stub source via wp_add_inline_script.
 * - The handle name and category constant match the documented contract.
 *
 * The test seeds the Plugin singleton's `$plugin_file` field via reflection
 * rather than calling `Plugin::get_instance()`, which would (a) execute the
 * full component-wiring constructor and (b) interfere with PluginTest's own
 * idempotent bootstrap expectation.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Consent\Consent_Stub;
use Kntnt\Gpx_Blocks\Plugin;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Sets the Plugin singleton's static $plugin_file property without invoking
 * the full bootstrap. Returns the previous value so the test can restore it.
 *
 * @param string $path Absolute path to seed.
 *
 * @return string Previous value of the property.
 */
function consent_stub_seed_plugin_file( string $path ): string {

	$ref      = new ReflectionClass( Plugin::class );
	$property = $ref->getProperty( 'plugin_file' );
	$previous = (string) $property->getValue();
	$property->setValue( null, $path );

	// Invalidate the cached parsed-header so our stubs for get_plugin_data
	// are consulted on the next call.
	$cache = $ref->getProperty( 'plugin_data' );
	$cache->setValue( null, null );

	return $previous;

}

// ---------------------------------------------------------------------------
// enqueue() registers the handle and inlines the stub source
// ---------------------------------------------------------------------------

test( 'enqueue registers the synthetic handle in <head> and inlines the stub', function (): void {

	// Seed the singleton's $plugin_file with the real plugin path so
	// Consent_Stub can locate js/consent-stub.js on disk.
	$plugin_file = dirname( __DIR__, 2 ) . '/kntnt-gpx-blocks.php';
	$previous    = consent_stub_seed_plugin_file( $plugin_file );

	// Stub the plugin-data resolver. Plugin::get_plugin_data() prefers
	// get_plugin_data (the WP function) when available; we stub that to
	// return a parsed header with the version field.
	Functions\when( 'get_plugin_data' )->alias(
		static fn ( string $file, bool $markup, bool $translate ): array => [
			'Version' => '0.2.0-test',
		]
	);
	Functions\when( 'get_file_data' )->alias(
		static fn ( string $file, array $headers ): array => [
			'Version' => '0.2.0-test',
		]
	);

	$captured_register = null;
	$captured_enqueue  = null;
	$captured_inline   = null;

	Functions\when( 'wp_register_script' )->alias(
		static function (
			string $handle,
			mixed $src,
			array $deps,
			mixed $version,
			bool $in_footer
		) use ( &$captured_register ): bool {
			$captured_register = compact( 'handle', 'src', 'deps', 'version', 'in_footer' );
			return true;
		}
	);

	Functions\when( 'wp_enqueue_script' )->alias(
		static function ( string $handle ) use ( &$captured_enqueue ): void {
			$captured_enqueue = $handle;
		}
	);

	Functions\when( 'wp_add_inline_script' )->alias(
		static function ( string $handle, string $data ) use ( &$captured_inline ): bool {
			$captured_inline = compact( 'handle', 'data' );
			return true;
		}
	);

	try {
		( new Consent_Stub() )->enqueue();
	} finally {
		// Restore the previous static value so other tests are unaffected.
		consent_stub_seed_plugin_file( $previous );
	}

	// The handle is the documented one and the source is `false` (synthetic).
	expect( $captured_register )->not->toBeNull();
	expect( $captured_register['handle'] )->toBe( 'kntnt-gpx-blocks-consent-stub' );
	expect( $captured_register['src'] )->toBeFalse();
	expect( $captured_register['deps'] )->toBe( [] );

	// The fifth argument MUST be false so the script is printed in <head>.
	expect( $captured_register['in_footer'] )->toBeFalse();

	// wp_enqueue_script is called with the same handle.
	expect( $captured_enqueue )->toBe( 'kntnt-gpx-blocks-consent-stub' );

	// The inline script is attached to the same handle and contains the IIFE
	// markers documented in docs/consent.md (the unique fragments that
	// optimisation plugins should match if they cannot target by handle).
	expect( $captured_inline )->not->toBeNull();
	expect( $captured_inline['handle'] )->toBe( 'kntnt-gpx-blocks-consent-stub' );
	expect( $captured_inline['data'] )->toContain( 'kntnt_gpx_blocks' );
	expect( $captured_inline['data'] )->toContain( '_setConsent' );

} );
