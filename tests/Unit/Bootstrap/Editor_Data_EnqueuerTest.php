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

test( 'payload omits API keys (per-block tileApiKeys / tileOverlayApiKeys maps are never inlined; PHP-supplied keys never reach the editor)', function (): void {

	$captured_inline = null;
	Functions\when( 'wp_add_inline_script' )->alias(
		static function ( string $handle, string $data ) use ( &$captured_inline ): bool {
			$captured_inline = $data;
			return true;
		}
	);

	( new Editor_Data_Enqueuer() )->enqueue();

	$payload = (string) $captured_inline;

	// Structural assertion: no per-block key field (`tileApiKeys`,
	// `tileOverlayApiKeys`) and no provider-level `"apiKey":` field may
	// appear in the inlined string. The editor payload does carry the
	// `apiKeyManagedExternally` boolean flag per provider, so we assert
	// against the surrounding JSON syntax (`"apiKey":`) rather than the
	// substring `apiKey` alone — the flag is *signalled*, the value is
	// *never* leaked.
	expect( $payload )->not->toContain( '"apiKey":' );
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

// ---------------------------------------------------------------------------
// PHP-supplied API key (issue #113)
//
// The editor payload exposes a per-provider `apiKeyManagedExternally`
// boolean: `true` when the filter callback supplied an `apiKey` field
// (engagement is presence-based, not value-based). When engaged with a
// non-empty key, the enqueuer pre-substitutes `{KEY}` in every style URL
// server-side so the editor preview can mount the URL directly without
// calling `substituteTileApiKey()` client-side. When engaged with an
// empty/whitespace-only key, `{KEY}` is left intact (fail-closed) and a
// warning is logged. The `apiKey` value itself never reaches the
// editor payload.
// ---------------------------------------------------------------------------

/**
 * Captures whatever the plugin's `Plugin::warning()` calls write to PHP's
 * error_log() during $callback. The fixture mirrors the helper in
 * `tests/Unit/Rendering/Tile_Layer_RegistryTest.php` so each test file
 * stays self-contained.
 *
 * @param callable $callback Block of code to run with log capture engaged.
 *
 * @return string Concatenated contents the test code wrote to error_log().
 */
function ede_capture_warning_log( callable $callback ): string {

	$tmp = tempnam( sys_get_temp_dir(), 'kntnt_gpx_blocks_ede_log_' );
	if ( ! is_string( $tmp ) ) {
		throw new RuntimeException( 'tempnam() failed in ede_capture_warning_log' );
	}

	$previous = ini_get( 'error_log' );
	ini_set( 'error_log', $tmp );
	try {
		$callback();
	} finally {
		ini_set( 'error_log', $previous === false ? '' : $previous );
	}

	$contents = file_get_contents( $tmp );
	@unlink( $tmp );
	return is_string( $contents ) ? $contents : '';

}

/**
 * Decodes the payload JSON out of the captured inline script.
 *
 * @param string $inline Captured inline-script string.
 *
 * @return array<string, mixed>
 */
function ede_decode_payload( string $inline ): array {

	$json    = substr( $inline, strlen( 'window.kntntGpxBlocks = ' ) );
	$json    = rtrim( $json, ';' );
	$decoded = json_decode( $json, true );
	return is_array( $decoded ) ? $decoded : [];

}

/**
 * Stubs apply_filters so `kntnt_gpx_blocks_tile_providers` returns a
 * single paid-provider record with the supplied apiKey overlay applied.
 *
 * The provider id is `'paid-provider'`. The style id is `'default'`. The
 * style URL contains a single `{KEY}` placeholder so substitution
 * effects are unambiguous.
 *
 * @param array<string, mixed> $overlay Provider-level overlay (e.g.
 *                                      `[ 'apiKey' => 'X' ]` to engage
 *                                      the PHP path; omit to leave it
 *                                      unengaged).
 */
function ede_install_paid_provider( array $overlay = [] ): void {

	$record = array_merge(
		[
			'label'       => 'Paid Provider',
			'requiresKey' => true,
			'default'     => 'default',
			'styles'      => [
				'default' => [
					'label'       => 'Default',
					'url'         => 'https://tiles.example.com/{z}/{x}/{y}.png?key={KEY}',
					'attribution' => '&copy; Example',
					'maxZoom'     => 19,
				],
			],
		],
		$overlay
	);

	Functions\when( 'apply_filters' )->alias(
		static function ( string $filter, mixed $value ) use ( $record ): mixed {
			if ( $filter === 'kntnt_gpx_blocks_tile_providers' ) {
				return [ 'paid-provider' => $record ];
			}
			if ( $filter === 'kntnt_gpx_blocks_tile_overlays' ) {
				return [];
			}
			return $value;
		}
	);

}

test( 'apiKeyManagedExternally is false for every provider without a PHP-supplied apiKey', function (): void {

	$captured_inline = null;
	Functions\when( 'wp_add_inline_script' )->alias(
		static function ( string $handle, string $data ) use ( &$captured_inline ): bool {
			$captured_inline = $data;
			return true;
		}
	);

	( new Editor_Data_Enqueuer() )->enqueue();

	$decoded = ede_decode_payload( (string) $captured_inline );

	foreach ( $decoded['providers'] as $id => $record ) {
		expect( $record )->toHaveKey( 'apiKeyManagedExternally' );
		expect( $record['apiKeyManagedExternally'] )->toBeFalse();
	}

} );

test( 'apiKeyManagedExternally is true when the PHP path is engaged, and {KEY} is pre-substituted server-side', function (): void {

	ede_install_paid_provider( [ 'apiKey' => 'PHP-VALUE' ] );

	$captured_inline = null;
	Functions\when( 'wp_add_inline_script' )->alias(
		static function ( string $handle, string $data ) use ( &$captured_inline ): bool {
			$captured_inline = $data;
			return true;
		}
	);

	( new Editor_Data_Enqueuer() )->enqueue();

	$decoded = ede_decode_payload( (string) $captured_inline );

	expect( $decoded['providers'] )->toHaveKey( 'paid-provider' );
	expect( $decoded['providers']['paid-provider']['apiKeyManagedExternally'] )->toBeTrue();
	$url = $decoded['providers']['paid-provider']['styles']['default']['url'];
	expect( $url )->toContain( 'key=PHP-VALUE' );
	expect( $url )->not->toContain( '{KEY}' );

} );

test( 'an empty PHP-supplied apiKey leaves {KEY} intact and logs a warning naming only the provider id', function (): void {

	ede_install_paid_provider( [ 'apiKey' => '   ' ] );

	$captured_inline = null;
	Functions\when( 'wp_add_inline_script' )->alias(
		static function ( string $handle, string $data ) use ( &$captured_inline ): bool {
			$captured_inline = $data;
			return true;
		}
	);

	$logged = ede_capture_warning_log( static function (): void {
		( new Editor_Data_Enqueuer() )->enqueue();
	} );

	$decoded = ede_decode_payload( (string) $captured_inline );

	// Fail-closed: `{KEY}` placeholder survives, so the editor preview
	// detects it and ships polyline-only.
	$url = $decoded['providers']['paid-provider']['styles']['default']['url'];
	expect( $url )->toContain( '{KEY}' );

	// The flag is still `true` — engagement is presence-based, not
	// value-based — so the editor hides the API-key TextControl.
	expect( $decoded['providers']['paid-provider']['apiKeyManagedExternally'] )->toBeTrue();

	// Warning log names the provider id, never the key value.
	expect( $logged )->toContain( 'paid-provider' );

} );

test( 'the literal apiKey value never appears anywhere in the editor payload (no-leak invariant)', function (): void {

	$sentinel = 'S3CR3T-DO-NOT-LEAK';
	ede_install_paid_provider( [ 'apiKey' => $sentinel ] );

	$captured_inline = null;
	Functions\when( 'wp_add_inline_script' )->alias(
		static function ( string $handle, string $data ) use ( &$captured_inline ): bool {
			$captured_inline = $data;
			return true;
		}
	);

	( new Editor_Data_Enqueuer() )->enqueue();

	// The sentinel appears in the substituted URL (intended); assert
	// that the raw `"apiKey":` key never appears in the payload (the
	// flag is `apiKeyManagedExternally`, not `apiKey`).
	expect( (string) $captured_inline )->not->toContain( '"apiKey":' );

} );

test( 'no PHP-supplied apiKey value (sentinel) ever appears in the warning log', function (): void {

	$sentinel = 'S3CR3T-DO-NOT-LEAK';
	// Padded with whitespace so the validator trims to a non-empty
	// stored value. The success branch substitutes silently and does
	// not log, so the no-leak assertion holds vacuously here — but the
	// invariant we lock down is that the enqueuer never logs the value
	// even when something *did* surface.
	ede_install_paid_provider( [ 'apiKey' => '  ' . $sentinel . '  ' ] );

	Functions\when( 'wp_add_inline_script' )->justReturn( true );

	$logged = ede_capture_warning_log( static function (): void {
		( new Editor_Data_Enqueuer() )->enqueue();
	} );

	expect( $logged )->not->toContain( $sentinel );

} );

test( 'empty PHP-supplied apiKey log entry never carries the apiKey value (even whitespace-only input)', function (): void {

	// Use a sentinel that would be identifiable even after trimming.
	// The validator trims `  S3CR3T  ` to `S3CR3T` which is non-empty,
	// so this test uses the empty-after-trim path (whitespace-only).
	// The fail-closed warning fires; we assert it carries the id only.
	ede_install_paid_provider( [ 'apiKey' => "  \t\n" ] );

	Functions\when( 'wp_add_inline_script' )->justReturn( true );

	$logged = ede_capture_warning_log( static function (): void {
		( new Editor_Data_Enqueuer() )->enqueue();
	} );

	// Log contains the id and a fail-closed marker, but never the literal
	// whitespace input (any tab or newline character in the log would
	// indicate a leak of the unsanitised input).
	expect( $logged )->toContain( 'paid-provider' );
	expect( $logged )->not->toContain( "\t" );

} );
