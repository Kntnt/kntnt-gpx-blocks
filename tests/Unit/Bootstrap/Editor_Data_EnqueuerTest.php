<?php
/**
 * Tests for Bootstrap\Editor_Data_Enqueuer.
 *
 * Verifies that the enqueuer composes the editor data payload in the
 * nested provider/style shape, encodes it via wp_json_encode, and emits
 * it as a `before` inline script on the GPX Map block's editor handle.
 * Also covers the warning path when JSON encoding fails.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Bootstrap\Editor_Data_Enqueuer;
use Kntnt\Gpx_Blocks\Rendering\Tile_Layer_Registry;

beforeEach( function (): void {

	// __() returns the source string verbatim so the registry can build its
	// validated default set without a live WordPress.
	Functions\when( '__' )->returnArg( 1 );

	// Default apply_filters passthrough — tests that need to override the
	// providers/overlays filter alias() it again locally.
	Functions\when( 'apply_filters' )->alias(
		static function ( string $filter, mixed $value ): mixed {
			return $value;
		}
	);

	// wp_json_encode falls through to PHP's json_encode for capture-and-decode
	// assertions. Tests that want to exercise the encoding-failure branch
	// override this stub locally.
	Functions\when( 'wp_json_encode' )->alias(
		static fn ( mixed $v ): string|false => json_encode( $v )
	);

} );

// ---------------------------------------------------------------------------
// Happy path: payload shape and inline-script wiring
// ---------------------------------------------------------------------------

test( 'enqueues window.kntntGpxBlocks as a before inline script on the map editor handle', function (): void {

	$captured_handle  = null;
	$captured_inline  = null;
	$captured_position = null;

	Functions\when( 'wp_add_inline_script' )->alias(
		static function ( string $handle, string $data, string $position = 'after' ) use (
			&$captured_handle,
			&$captured_inline,
			&$captured_position
		): bool {
			$captured_handle   = $handle;
			$captured_inline   = $data;
			$captured_position = $position;
			return true;
		}
	);

	( new Editor_Data_Enqueuer() )->enqueue();

	expect( $captured_handle )->toBe( 'kntnt-gpx-blocks-map-editor-script' );
	expect( $captured_position )->toBe( 'before' );
	expect( $captured_inline )->toStartWith( 'window.kntntGpxBlocks = ' );

} );

test( 'inline payload carries every default base provider id and every default overlay provider id', function (): void {

	$captured_inline = null;
	Functions\when( 'wp_add_inline_script' )->alias(
		static function ( string $handle, string $data ) use ( &$captured_inline ): bool {
			$captured_inline = $data;
			return true;
		}
	);

	( new Editor_Data_Enqueuer() )->enqueue();

	expect( $captured_inline )->not->toBeNull();

	// Strip the prefix and trailing semicolon to recover the JSON payload.
	$json = substr( (string) $captured_inline, strlen( 'window.kntntGpxBlocks = ' ) );
	$json = rtrim( $json, ';' );

	$decoded = json_decode( $json, true );

	expect( $decoded )->toBeArray();
	expect( $decoded )->toHaveKey( 'providers' );
	expect( $decoded )->toHaveKey( 'overlays' );

	// Every default base provider id appears.
	foreach ( [ 'carto', 'esri', 'jawg-maps', 'mapbox', 'maptiler', 'openstreetmap', 'opentopomap', 'stadia-maps', 'thunderforest' ] as $id ) {
		expect( $decoded['providers'] )->toHaveKey( $id );
	}

	// requiresKey flag survives the JSON round trip.
	expect( $decoded['providers']['openstreetmap']['requiresKey'] )->toBeFalse();
	expect( $decoded['providers']['mapbox']['requiresKey'] )->toBeTrue();

	// Every default overlay provider id appears with its nested layers map.
	foreach ( [ 'openseamap', 'opensnowmap', 'openweathermap', 'waymarked-trails' ] as $id ) {
		expect( $decoded['overlays'] )->toHaveKey( $id );
		expect( $decoded['overlays'][ $id ] )->toHaveKey( 'layers' );
	}

	// Sanity-check one layer from waymarked-trails carries url/attribution/maxZoom.
	expect( $decoded['overlays']['waymarked-trails']['layers']['hiking']['url'] )->toContain( 'waymarkedtrails.org' );
	expect( $decoded['overlays']['waymarked-trails']['layers']['hiking']['attribution'] )->toContain( 'Waymarked' );
	expect( $decoded['overlays']['waymarked-trails']['layers']['hiking']['maxZoom'] )->toBe( 18 );

} );

test( 'overlay records carry the nested layers map with per-layer url/attribution/maxZoom and {KEY} for paid providers', function (): void {

	$captured_inline = null;
	Functions\when( 'wp_add_inline_script' )->alias(
		static function ( string $handle, string $data ) use ( &$captured_inline ): bool {
			$captured_inline = $data;
			return true;
		}
	);

	( new Editor_Data_Enqueuer() )->enqueue();

	$json    = substr( (string) $captured_inline, strlen( 'window.kntntGpxBlocks = ' ) );
	$json    = rtrim( $json, ';' );
	$decoded = json_decode( $json, true );

	// waymarked-trails has six layers; pick a couple and assert shape.
	$wmt = $decoded['overlays']['waymarked-trails'];
	expect( $wmt['requiresKey'] )->toBeFalse();
	foreach ( [ 'hiking', 'cycling', 'mtb', 'riding', 'skating', 'winter' ] as $layer_id ) {
		expect( $wmt['layers'] )->toHaveKey( $layer_id );
		expect( $wmt['layers'][ $layer_id ]['url'] )->not->toContain( '{KEY}' );
		expect( $wmt['layers'][ $layer_id ]['url'] )->toStartWith( 'https://' );
	}

	// The winter layer points at slopes/ per Waymarked Trails' own naming.
	expect( $wmt['layers']['winter']['url'] )->toContain( 'waymarkedtrails.org/slopes/' );

	// openweathermap is key-required; every layer URL retains {KEY} for client-side substitution.
	$owm = $decoded['overlays']['openweathermap'];
	expect( $owm['requiresKey'] )->toBeTrue();
	expect( $owm )->toHaveKey( 'signupUrl' );
	expect( $owm['signupUrl'] )->toBe( 'https://openweathermap.org/' );
	foreach ( [ 'clouds', 'precipitation', 'pressure', 'temperature', 'wind-speed' ] as $layer_id ) {
		expect( $owm['layers'] )->toHaveKey( $layer_id );
		expect( $owm['layers'][ $layer_id ]['url'] )->toContain( '{KEY}' );
	}

} );

test( 'overlay records expose signupUrl for paid overlay providers, omit it for free ones', function (): void {

	$captured_inline = null;
	Functions\when( 'wp_add_inline_script' )->alias(
		static function ( string $handle, string $data ) use ( &$captured_inline ): bool {
			$captured_inline = $data;
			return true;
		}
	);

	( new Editor_Data_Enqueuer() )->enqueue();

	$json    = substr( (string) $captured_inline, strlen( 'window.kntntGpxBlocks = ' ) );
	$json    = rtrim( $json, ';' );
	$decoded = json_decode( $json, true );

	expect( $decoded['overlays']['openweathermap'] )->toHaveKey( 'signupUrl' );
	expect( array_key_exists( 'signupUrl', $decoded['overlays']['waymarked-trails'] ) )->toBeFalse();
	expect( array_key_exists( 'signupUrl', $decoded['overlays']['openseamap'] ) )->toBeFalse();
	expect( array_key_exists( 'signupUrl', $decoded['overlays']['opensnowmap'] ) )->toBeFalse();

} );

test( 'provider records carry the nested styles map with per-style url/attribution/maxZoom', function (): void {

	$captured_inline = null;
	Functions\when( 'wp_add_inline_script' )->alias(
		static function ( string $handle, string $data ) use ( &$captured_inline ): bool {
			$captured_inline = $data;
			return true;
		}
	);

	( new Editor_Data_Enqueuer() )->enqueue();

	$json    = substr( (string) $captured_inline, strlen( 'window.kntntGpxBlocks = ' ) );
	$json    = rtrim( $json, ';' );
	$decoded = json_decode( $json, true );

	// openstreetmap has both `mapnik` (default) and `cyclosm` styles.
	$osm = $decoded['providers']['openstreetmap'];
	expect( $osm )->toHaveKey( 'styles' );
	expect( $osm['styles'] )->toHaveKey( 'mapnik' );
	expect( $osm['styles'] )->toHaveKey( 'cyclosm' );
	expect( $osm['styles']['mapnik']['url'] )->toContain( 'tile.openstreetmap.org' );
	expect( $osm['styles']['mapnik']['maxZoom'] )->toBe( 19 );
	expect( $osm['default'] )->toBe( 'mapnik' );

	// Provider-level subdomains are emitted at the provider level (inherited by all styles).
	expect( $osm['subdomains'] )->toBe( [ 'a', 'b', 'c' ] );

	// mapbox is key-required; every style URL retains {KEY} for client-side substitution.
	$mapbox = $decoded['providers']['mapbox'];
	expect( $mapbox['requiresKey'] )->toBeTrue();
	expect( $mapbox['default'] )->toBe( 'outdoors' );
	foreach ( [ 'outdoors', 'streets', 'satellite-streets', 'light', 'dark' ] as $style_id ) {
		expect( $mapbox['styles'] )->toHaveKey( $style_id );
		expect( $mapbox['styles'][ $style_id ]['url'] )->toContain( '{KEY}' );
	}

} );

test( 'provider records expose signupUrl for paid providers, omit it for free ones', function (): void {

	$captured_inline = null;
	Functions\when( 'wp_add_inline_script' )->alias(
		static function ( string $handle, string $data ) use ( &$captured_inline ): bool {
			$captured_inline = $data;
			return true;
		}
	);

	( new Editor_Data_Enqueuer() )->enqueue();

	$json    = substr( (string) $captured_inline, strlen( 'window.kntntGpxBlocks = ' ) );
	$json    = rtrim( $json, ';' );
	$decoded = json_decode( $json, true );

	expect( $decoded['providers']['mapbox'] )->toHaveKey( 'signupUrl' );
	expect( $decoded['providers']['mapbox']['signupUrl'] )->toBe( 'https://www.mapbox.com/' );
	expect( $decoded['providers']['stadia-maps']['signupUrl'] )->toBe( 'https://stadiamaps.com/' );
	expect( $decoded['providers']['jawg-maps']['signupUrl'] )->toBe( 'https://www.jawg.io/' );

	// Free providers have no signupUrl.
	expect( array_key_exists( 'signupUrl', $decoded['providers']['openstreetmap'] ) )->toBeFalse();
	expect( array_key_exists( 'signupUrl', $decoded['providers']['carto'] ) )->toBeFalse();

} );

test( 'payload omits API keys (per-block tileApiKeys / tileOverlayApiKeys maps are never inlined)', function (): void {

	$captured_inline = null;
	Functions\when( 'wp_add_inline_script' )->alias(
		static function ( string $handle, string $data ) use ( &$captured_inline ): bool {
			$captured_inline = $data;
			return true;
		}
	);

	( new Editor_Data_Enqueuer() )->enqueue();

	$payload = (string) $captured_inline;

	// The registry never carries a key in its records, but the assertion is
	// structural: no field named `apiKey`, `tileApiKey`, `tileApiKeys`, or
	// `tileOverlayApiKeys` may appear in the inlined string.
	expect( $payload )->not->toContain( 'apiKey' );
	expect( $payload )->not->toContain( 'tileApiKey' );
	expect( $payload )->not->toContain( 'tileApiKeys' );
	expect( $payload )->not->toContain( 'tileOverlayApiKeys' );

} );

test( 'overlays surface a custom overlay-provider entry added via the filter', function (): void {

	// Replace the default overlay set with a site-builder-supplied overlay
	// provider carrying multiple layers so we exercise the nested filter
	// integration end-to-end.
	Functions\when( 'apply_filters' )->alias(
		static function ( string $name, mixed $value ): mixed {
			if ( $name === 'kntnt_gpx_blocks_tile_overlays' ) {
				return [
					'custom-overlay' => [
						'label'       => 'Custom Overlay',
						'requiresKey' => false,
						'layers'      => [
							'grid'    => [
								'label'       => 'Grid',
								'url'         => 'https://grid.example.com/{z}/{x}/{y}.png',
								'attribution' => '&copy; Example',
								'maxZoom'     => 19,
							],
							'shading' => [
								'label'       => 'Shading',
								'url'         => 'https://shading.example.com/{z}/{x}/{y}.png',
								'attribution' => '&copy; Example',
								'maxZoom'     => 19,
							],
						],
					],
				];
			}
			return $value;
		}
	);

	$captured_inline = null;
	Functions\when( 'wp_add_inline_script' )->alias(
		static function ( string $handle, string $data ) use ( &$captured_inline ): bool {
			$captured_inline = $data;
			return true;
		}
	);

	( new Editor_Data_Enqueuer() )->enqueue();

	$json    = substr( (string) $captured_inline, strlen( 'window.kntntGpxBlocks = ' ) );
	$json    = rtrim( $json, ';' );
	$decoded = json_decode( $json, true );

	expect( $decoded['overlays'] )->toHaveKey( 'custom-overlay' );
	expect( $decoded['overlays']['custom-overlay']['label'] )->toBe( 'Custom Overlay' );
	expect( $decoded['overlays']['custom-overlay']['layers'] )->toHaveKey( 'grid' );
	expect( $decoded['overlays']['custom-overlay']['layers'] )->toHaveKey( 'shading' );

} );

// ---------------------------------------------------------------------------
// Defensive guard: encoding failure
// ---------------------------------------------------------------------------

test( 'logs a warning and skips wp_add_inline_script when wp_json_encode returns false', function (): void {

	Functions\when( 'wp_json_encode' )->justReturn( false );

	Functions\expect( 'wp_add_inline_script' )->never();

	// Plugin::warning() ultimately calls error_log(); Brain Monkey lets us
	// run the method without asserting on the log body — the never()
	// expectation above is the load-bearing assertion.
	( new Editor_Data_Enqueuer() )->enqueue();

} );

// ---------------------------------------------------------------------------
// Constructor injection lets tests pass a synthetic registry
// ---------------------------------------------------------------------------

test( 'accepts a constructor-injected registry without touching the default filter chain', function (): void {

	$registry = new Tile_Layer_Registry();

	$captured_inline = null;
	Functions\when( 'wp_add_inline_script' )->alias(
		static function ( string $handle, string $data ) use ( &$captured_inline ): bool {
			$captured_inline = $data;
			return true;
		}
	);

	( new Editor_Data_Enqueuer( $registry ) )->enqueue();

	expect( $captured_inline )->not->toBeNull();
	expect( (string) $captured_inline )->toStartWith( 'window.kntntGpxBlocks = ' );

} );
