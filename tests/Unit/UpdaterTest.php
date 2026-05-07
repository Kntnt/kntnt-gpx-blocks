<?php
/**
 * Tests for the Updater class.
 *
 * Brain Monkey stubs the WordPress HTTP API and helper functions so the
 * Updater can run without WordPress. Plugin's two static helpers
 * (get_plugin_data, get_plugin_file) are seeded via reflection on the
 * private static properties — reaching for reflection is preferred over
 * mocking a final class.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Plugin;
use Kntnt\Gpx_Blocks\Updater;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Seeds Plugin's static state without going through get_instance().
 *
 * Sets both the plugin file path and the cached plugin-data array so that
 * Plugin::get_plugin_file() and Plugin::get_plugin_data() return the supplied
 * values for the rest of the current test.
 *
 * @param string                $plugin_file Absolute path to seed.
 * @param array<string, string> $plugin_data Plugin-header array to seed.
 */
function seed_plugin_state( string $plugin_file, array $plugin_data ): void {

	$reflection = new \ReflectionClass( Plugin::class );

	$file_prop = $reflection->getProperty( 'plugin_file' );
	$file_prop->setValue( null, $plugin_file );

	$data_prop = $reflection->getProperty( 'plugin_data' );
	$data_prop->setValue( null, $plugin_data );
}

/**
 * Restores Plugin's static state to the empty defaults so seeding in one
 * test does not leak into the next.
 */
function reset_plugin_state(): void {

	$reflection = new \ReflectionClass( Plugin::class );

	$file_prop = $reflection->getProperty( 'plugin_file' );
	$file_prop->setValue( null, '' );

	$data_prop = $reflection->getProperty( 'plugin_data' );
	$data_prop->setValue( null, null );
}

/**
 * Wires the Brain Monkey stubs for the WordPress HTTP API helpers that the
 * Updater calls. All four return their canned values from the supplied
 * response payload, mimicking core's behaviour closely enough for the
 * Updater's narrow API surface.
 *
 * @param array{response_code:int, body:string}|\WP_Error $response Canned API response.
 */
function bind_http_api_stubs( array|\WP_Error $response ): void {

	Functions\when( 'wp_remote_get' )->alias(
		static fn ( string $url ) => $response,
	);

	Functions\when( 'is_wp_error' )->alias(
		static fn ( mixed $thing ): bool => $thing instanceof \WP_Error,
	);

	Functions\when( 'wp_remote_retrieve_response_code' )->alias(
		static function ( mixed $r ): int {
			if ( is_array( $r ) && isset( $r['response_code'] ) && is_int( $r['response_code'] ) ) {
				return $r['response_code'];
			}
			return 0;
		},
	);

	Functions\when( 'wp_remote_retrieve_body' )->alias(
		static function ( mixed $r ): string {
			if ( is_array( $r ) && isset( $r['body'] ) && is_string( $r['body'] ) ) {
				return $r['body'];
			}
			return '';
		},
	);
}

/**
 * Wires the helper-function stubs that the Updater uses indirectly.
 */
function bind_misc_helper_stubs(): void {

	Functions\when( 'plugin_basename' )->alias(
		static function ( string $path ): string {
			return 'kntnt-gpx-blocks/' . basename( $path );
		},
	);

	Functions\when( 'get_bloginfo' )->alias(
		static fn ( string $key ): string => $key === 'version' ? '6.5' : '',
	);

	Functions\when( 'wp_parse_url' )->alias(
		static fn ( string $uri, int $component = -1 ): mixed => parse_url( $uri, $component ),
	);
}

/**
 * Builds a minimal $transient->checked array so the Updater's empty-checked
 * guard does not short-circuit it.
 */
function checked_transient(): \stdClass {
	$t          = new \stdClass();
	$t->checked = [ 'kntnt-gpx-blocks/kntnt-gpx-blocks.php' => '1.0.0' ];
	$t->response = [];
	return $t;
}

beforeEach( function (): void {
	reset_plugin_state();
} );

afterEach( function (): void {
	reset_plugin_state();
} );

// ---------------------------------------------------------------------------
// Empty-checked guard
// ---------------------------------------------------------------------------

it( 'returns the transient unchanged when checked is empty', function (): void {

	bind_http_api_stubs( [
		'response_code' => 200,
		'body'          => '{}',
	] );
	bind_misc_helper_stubs();
	seed_plugin_state(
		'/var/www/wp-content/plugins/kntnt-gpx-blocks/kntnt-gpx-blocks.php',
		[
			'Version'    => '1.0.0',
			'PluginURI'  => 'https://github.com/Kntnt/kntnt-gpx-blocks',
			'RequiresWP' => '6.5',
		],
	);

	$transient          = new \stdClass();
	$transient->checked = [];

	$result = ( new Updater() )->check_for_updates( $transient );

	expect( $result )->toBe( $transient )
		->and( $result->response ?? null )->toBeNull();
} );

// ---------------------------------------------------------------------------
// Non-GitHub Plugin URI
// ---------------------------------------------------------------------------

it( 'leaves the transient untouched when the plugin URI is not a GitHub URL', function (): void {

	bind_http_api_stubs( [
		'response_code' => 200,
		'body'          => '{}',
	] );
	bind_misc_helper_stubs();
	seed_plugin_state(
		'/path/to/kntnt-gpx-blocks.php',
		[
			'Version'    => '1.0.0',
			'PluginURI'  => 'https://example.com/plugin',
			'RequiresWP' => '6.5',
		],
	);

	$transient = checked_transient();

	$result = ( new Updater() )->check_for_updates( $transient );

	expect( $result->response )->toBe( [] );
} );

// ---------------------------------------------------------------------------
// Network failure
// ---------------------------------------------------------------------------

it( 'leaves the transient untouched when wp_remote_get returns a WP_Error', function (): void {

	bind_http_api_stubs( new \WP_Error() );
	bind_misc_helper_stubs();
	seed_plugin_state(
		'/path/to/kntnt-gpx-blocks.php',
		[
			'Version'    => '1.0.0',
			'PluginURI'  => 'https://github.com/Kntnt/kntnt-gpx-blocks',
			'RequiresWP' => '6.5',
		],
	);

	$transient = checked_transient();

	$result = ( new Updater() )->check_for_updates( $transient );

	expect( $result->response )->toBe( [] );
} );

// ---------------------------------------------------------------------------
// No ZIP asset attached
// ---------------------------------------------------------------------------

it( 'does not advertise an update when the release has no application/zip asset', function (): void {

	$body = json_encode( [
		'tag_name'    => 'v2.0.0',
		'html_url'    => 'https://github.com/Kntnt/kntnt-gpx-blocks/releases/tag/v2.0.0',
		'zipball_url' => 'https://api.github.com/repos/Kntnt/kntnt-gpx-blocks/zipball/v2.0.0',
		'assets'      => [
			[
				'content_type'         => 'text/plain',
				'browser_download_url' => 'https://example.com/notes.txt',
			],
		],
	] );

	bind_http_api_stubs( [
		'response_code' => 200,
		'body'          => $body,
	] );
	bind_misc_helper_stubs();
	seed_plugin_state(
		'/path/to/kntnt-gpx-blocks.php',
		[
			'Version'    => '1.0.0',
			'PluginURI'  => 'https://github.com/Kntnt/kntnt-gpx-blocks',
			'RequiresWP' => '6.5',
		],
	);

	$transient = checked_transient();

	$result = ( new Updater() )->check_for_updates( $transient );

	expect( $result->response )->toBe( [] );
} );

// ---------------------------------------------------------------------------
// Released version not greater than installed
// ---------------------------------------------------------------------------

it( 'does not advertise an update when the released version is not newer', function (): void {

	$body = json_encode( [
		'tag_name'    => 'v1.0.0',
		'html_url'    => 'https://github.com/Kntnt/kntnt-gpx-blocks/releases/tag/v1.0.0',
		'zipball_url' => 'https://api.github.com/repos/Kntnt/kntnt-gpx-blocks/zipball/v1.0.0',
		'assets'      => [
			[
				'content_type'         => 'application/zip',
				'browser_download_url' => 'https://github.com/Kntnt/kntnt-gpx-blocks/releases/download/v1.0.0/'
					. 'kntnt-gpx-blocks-v1.0.0.zip',
			],
		],
	] );

	bind_http_api_stubs( [
		'response_code' => 200,
		'body'          => $body,
	] );
	bind_misc_helper_stubs();
	seed_plugin_state(
		'/path/to/kntnt-gpx-blocks.php',
		[
			'Version'    => '1.0.0',
			'PluginURI'  => 'https://github.com/Kntnt/kntnt-gpx-blocks',
			'RequiresWP' => '6.5',
		],
	);

	$transient = checked_transient();

	$result = ( new Updater() )->check_for_updates( $transient );

	expect( $result->response )->toBe( [] );
} );

// ---------------------------------------------------------------------------
// Newer version + ZIP asset → injects update
// ---------------------------------------------------------------------------

it( 'injects an update record when a newer release with a ZIP asset is available', function (): void {

	$package = 'https://github.com/Kntnt/kntnt-gpx-blocks/releases/download/v2.5.0/kntnt-gpx-blocks-v2.5.0.zip';
	$body    = json_encode( [
		'tag_name'    => 'v2.5.0',
		'html_url'    => 'https://github.com/Kntnt/kntnt-gpx-blocks/releases/tag/v2.5.0',
		'zipball_url' => 'https://api.github.com/repos/Kntnt/kntnt-gpx-blocks/zipball/v2.5.0',
		'assets'      => [
			[
				'content_type'         => 'application/zip',
				'browser_download_url' => $package,
			],
		],
	] );

	bind_http_api_stubs( [
		'response_code' => 200,
		'body'          => $body,
	] );
	bind_misc_helper_stubs();
	seed_plugin_state(
		'/var/www/wp-content/plugins/kntnt-gpx-blocks/kntnt-gpx-blocks.php',
		[
			'Version'    => '1.0.0',
			'PluginURI'  => 'https://github.com/Kntnt/kntnt-gpx-blocks',
			'RequiresWP' => '6.5',
		],
	);

	$transient = checked_transient();

	$result = ( new Updater() )->check_for_updates( $transient );

	$plugin_key = 'kntnt-gpx-blocks/kntnt-gpx-blocks.php';
	expect( $result->response )->toHaveKey( $plugin_key );

	$update = $result->response[ $plugin_key ];
	expect( $update )->toBeInstanceOf( \stdClass::class )
		->and( $update->slug )->toBe( 'kntnt-gpx-blocks' )
		->and( $update->plugin )->toBe( $plugin_key )
		->and( $update->new_version )->toBe( '2.5.0' )
		->and( $update->package )->toBe( $package )
		->and( $update->url )->toBe( 'https://github.com/Kntnt/kntnt-gpx-blocks/releases/tag/v2.5.0' )
		->and( $update->tested )->toBe( '6.5' );
} );

// ---------------------------------------------------------------------------
// Non-200 response
// ---------------------------------------------------------------------------

it( 'does not advertise an update when the GitHub API returns a non-200 status', function (): void {

	bind_http_api_stubs( [
		'response_code' => 404,
		'body'          => '{}',
	] );
	bind_misc_helper_stubs();
	seed_plugin_state(
		'/path/to/kntnt-gpx-blocks.php',
		[
			'Version'    => '1.0.0',
			'PluginURI'  => 'https://github.com/Kntnt/kntnt-gpx-blocks',
			'RequiresWP' => '6.5',
		],
	);

	$transient = checked_transient();

	$result = ( new Updater() )->check_for_updates( $transient );

	expect( $result->response )->toBe( [] );
} );
