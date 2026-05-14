<?php
/**
 * Tests for Rendering\Render_Map.
 *
 * Brain Monkey stubs all WordPress functions needed by the render path so the
 * class can run without a live WordPress install.
 *
 * The tests verify:
 * - The rendered HTML contains the required Interactivity API attributes.
 * - wp_interactivity_state is called with the expected state shape, including
 *   the four control-overlay flags (showZoomButtons, showScale,
 *   showFullscreen, showDownload) and gpxFileUrl.
 * - The defaults match the spec: zoom + scale ON, fullscreen + download OFF.
 * - Explicit attribute values override the defaults.
 * - The six interaction flags appear in the state with correct defaults
 *   (drag, pinch, dblclick, keyboard ON; scroll-wheel, boxzoom OFF).
 * - Explicit interaction flag values override the defaults.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Cache\Cache_Version;
use Kntnt\Gpx_Blocks\Rendering\Render_Map;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Builds a fake WP_Block object whose only readable property is $context.
 *
 * Render_Map::render() receives a WP_Block but never reads $context — it only
 * reads $attributes. An anonymous object with no extra properties satisfies
 * type-unsafe PHP code that just passes the value through.
 *
 * @return object
 */
function map_fake_block(): object {
	return new class() {

		/**
		 * Block context values (empty — Render_Map does not read context).
		 *
		 * @var array<string, mixed>
		 */
		public array $context = [];
	};
}

/**
 * Wires Brain Monkey stubs for the WordPress meta functions against an
 * in-memory store.
 *
 * @param array<int, array<string, mixed>> $store Meta store keyed by post ID.
 */
function map_bind_meta( array &$store ): void {

	Functions\when( 'get_post_meta' )->alias(
		static function ( int $id, string $key, bool $single ) use ( &$store ): mixed {
			if ( ! $single ) {
				return [];
			}
			return $store[ $id ][ $key ] ?? '';
		}
	);

	Functions\when( 'update_post_meta' )->alias(
		static function ( int $id, string $key, mixed $value ) use ( &$store ): bool {
			$store[ $id ][ $key ] = $value;
			return true;
		}
	);

	Functions\when( 'delete_post_meta' )->alias(
		static function ( int $id, string $key ) use ( &$store ): bool {
			unset( $store[ $id ][ $key ] );
			return true;
		}
	);

}

/**
 * Stubs get_attached_file() to return a valid path for the given ID.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $path          Absolute path to the file.
 */
function map_stub_attached_file( int $attachment_id, string $path ): void {
	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => $id === $attachment_id ? $path : false
	);
}

/**
 * Returns the absolute path to a fixture file.
 *
 * @param string $name Filename inside tests/Unit/fixtures/gpx/.
 *
 * @return string
 */
function map_fixture_path( string $name ): string {
	return __DIR__ . '/fixtures/gpx/' . $name;
}

/**
 * Builds an in-memory meta store pre-seeded with a current-version cache entry.
 *
 * @param int                           $attachment_id Attachment ID.
 * @param array<int, array<int, float>> $coordinates   GeoJSON [lon,lat] array.
 * @param string                        $fixture       Fixture filename.
 *
 * @return array<int, array<string, mixed>>
 */
function map_seeded_store(
	int $attachment_id,
	array $coordinates,
	string $fixture = 'happy-path.gpx',
): array {

	$path = map_fixture_path( $fixture );
	$hash = md5_file( $path );

	$geojson = [
		'type'     => 'FeatureCollection',
		'features' => [
			[
				'type'       => 'Feature',
				'properties' => (object) [],
				'geometry'   => [
					'type'        => 'LineString',
					'coordinates' => $coordinates,
				],
			],
		],
	];

	return [
		$attachment_id => [
			'_kntnt_gpx_blocks_geojson'     => json_encode( $geojson ),
			'_kntnt_gpx_blocks_statistics'  => [
				'distance'      => 5500.0,
				'min_elevation' => 100.0,
				'max_elevation' => 200.0,
				'ascent'        => 100.0,
				'descent'       => 0.0,
			],
			'_kntnt_gpx_blocks_version'     => Cache_Version::CURRENT,
			'_kntnt_gpx_blocks_source_hash' => $hash,
		],
	];

}

/**
 * Builds a minimal 2D coordinate array near Stockholm.
 *
 * @param int $count Number of points.
 *
 * @return array<int, array<int, float>>
 */
function map_synthetic_coords( int $count ): array {
	$out = [];
	for ( $i = 0; $i < $count; $i++ ) {
		$ratio = $count > 1 ? $i / ( $count - 1 ) : 0.0;
		$out[] = [ 18.0 + 0.05 * $ratio, 59.0 + 0.05 * $ratio ];
	}
	return $out;
}

// ---------------------------------------------------------------------------
// Default test setup
// ---------------------------------------------------------------------------

beforeEach( function (): void {

	Functions\when( 'apply_filters' )->alias(
		static function ( string $filter, mixed $fallback ): mixed {
			return $fallback;
		}
	);

	Functions\when( 'esc_html' )->returnArg( 1 );
	Functions\when( 'esc_attr' )->returnArg( 1 );
	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'esc_html__' )->alias(
		static fn ( string $text, string $domain ): string => $text
	);
	Functions\when( 'esc_attr__' )->alias(
		static fn ( string $text, string $domain ): string => $text
	);
	Functions\when( 'wp_json_encode' )->alias(
		static fn ( mixed $v ): string|false => json_encode( $v )
	);
	Functions\when( 'current_user_can' )->justReturn( false );
	Functions\when( 'is_admin' )->justReturn( false );

	// Default get_option returns the test-supplied tile-provider key
	// option or an empty array when the test did not configure one. The
	// $GLOBALS['kntnt_map_test_tile_keys'] entry mirrors what
	// `Settings_Page::sanitize_keys()` would persist; tests that want
	// to exercise the option-layer path set this to a populated map.
	$GLOBALS['kntnt_map_test_tile_keys'] = [];
	Functions\when( 'get_option' )->alias(
		static function ( string $name, mixed $default = false ): mixed {
			if ( $name === 'kntnt_gpx_blocks_tile_provider_keys' ) {
				$store = $GLOBALS['kntnt_map_test_tile_keys'] ?? [];
				return is_array( $store ) ? $store : [];
			}
			return $default;
		}
	);

	// Reset the per-test attribute capture and install a default
	// get_block_wrapper_attributes mock that mirrors core's behaviour for the
	// fields the production code passes in (class + style) and additionally
	// honours the editor-UI fields the wrapper-contract tests inject via
	// $GLOBALS['kntnt_map_test_attrs']. $GLOBALS['kntnt_map_test_core_style']
	// lets a test simulate core's habit of appending block-supports CSS
	// declarations (border, shadow, dimensions, …) onto the supplied `style`
	// attribute with a *space* separator — the concatenation shape that
	// motivates the trailing-semicolon fix in issue #109.
	$GLOBALS['kntnt_map_test_attrs']      = [];
	$GLOBALS['kntnt_map_test_core_style'] = '';
	Functions\when( 'get_block_wrapper_attributes' )->alias(
		static function ( array $extras = [] ): string {
			$attrs       = is_array( $GLOBALS['kntnt_map_test_attrs'] ?? null )
				? $GLOBALS['kntnt_map_test_attrs']
				: [];
			$core_style  = is_string( $GLOBALS['kntnt_map_test_core_style'] ?? null )
				? $GLOBALS['kntnt_map_test_core_style']
				: '';
			$class_parts = [ 'wp-block-kntnt-gpx-blocks-map' ];
			if ( isset( $extras['class'] ) && '' !== $extras['class'] ) {
				$class_parts[] = $extras['class'];
			}
			$align = $attrs['align'] ?? '';
			if ( is_string( $align ) && '' !== $align ) {
				$class_parts[] = 'align' . $align;
			}
			$class_name = $attrs['className'] ?? '';
			if ( is_string( $class_name ) && '' !== $class_name ) {
				$class_parts[] = $class_name;
			}
			$out = sprintf( 'class="%s"', implode( ' ', $class_parts ) );

			// Compose the final style attribute. Core concatenates the
			// supplied `style` with its own declarations using a single
			// space as separator — not a semicolon — so the plugin must
			// terminate its own declarations with `;` or the first core
			// declaration runs into the plugin's last.
			$style_value = $extras['style'] ?? '';
			if ( '' !== $core_style ) {
				$style_value = '' !== $style_value ? $style_value . ' ' . $core_style : $core_style;
			}
			if ( '' !== $style_value ) {
				$out .= sprintf( ' style="%s"', $style_value );
			}

			$anchor = $attrs['anchor'] ?? '';
			if ( is_string( $anchor ) && '' !== $anchor ) {
				$out .= sprintf( ' id="%s"', $anchor );
			}
			return $out;
		}
	);

} );

// ---------------------------------------------------------------------------
// Wrapper element and Interactivity API directives
// ---------------------------------------------------------------------------

test( 'renders the wrapper element with data-wp-init and data-wp-watch directives', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 42, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 42, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$html = Render_Map::render(
		[
			'attachmentId' => 42,
			'mapId'        => 'map-test',
		],
		'',
		map_fake_block(),
	);

	expect( $html )
		->toContain( 'data-wp-init="callbacks.initMap"' )
		->toContain( 'data-wp-watch--cursor="callbacks.onMapCursorChange"' )
		->toContain( 'kntnt-gpx-blocks-map' )
		->not->toContain( 'data-wp-watch--consent' )
		->not->toContain( 'callbacks.onConsentChange' );

} );

// ---------------------------------------------------------------------------
// Defaults: zoom + scale ON, fullscreen + download OFF
// ---------------------------------------------------------------------------

test( 'wp_interactivity_state receives correct default control flags', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 43, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 43, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	// Capture the state passed to wp_interactivity_state.
	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 43,
			'mapId'        => 'map-defaults',
		],
		'',
		map_fake_block(),
	);

	$settings = $captured_state['map-defaults']['settings'] ?? null;

	expect( $settings )->not->toBeNull();
	expect( $settings['showZoomButtons'] )->toBeTrue();
	expect( $settings['showScale'] )->toBeTrue();
	expect( $settings['showFullscreen'] )->toBeFalse();
	expect( $settings['showDownload'] )->toBeFalse();

} );

// ---------------------------------------------------------------------------
// Explicit attribute values are passed through
// ---------------------------------------------------------------------------

test( 'wp_interactivity_state reflects explicit control flag overrides', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 44, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 44, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId'    => 44,
			'mapId'           => 'map-overrides',
			'showZoomButtons' => false,
			'showScale'       => false,
			'showFullscreen'  => true,
			'showDownload'    => true,
		],
		'',
		map_fake_block(),
	);

	$settings = $captured_state['map-overrides']['settings'] ?? null;

	expect( $settings )->not->toBeNull();
	expect( $settings['showZoomButtons'] )->toBeFalse();
	expect( $settings['showScale'] )->toBeFalse();
	expect( $settings['showFullscreen'] )->toBeTrue();
	expect( $settings['showDownload'] )->toBeTrue();

} );

// ---------------------------------------------------------------------------
// gpxFileUrl is included in the state slice
// ---------------------------------------------------------------------------

test( 'wp_interactivity_state includes gpxFileUrl from wp_get_attachment_url', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 45, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 45, map_fixture_path( 'happy-path.gpx' ) );

	$expected_url = 'https://example.com/uploads/track.gpx';
	Functions\when( 'wp_get_attachment_url' )->justReturn( $expected_url );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 45,
			'mapId'        => 'map-url',
		],
		'',
		map_fake_block(),
	);

	expect( $captured_state['map-url']['gpxFileUrl'] ?? null )->toBe( $expected_url );

} );

test( 'gpxFileUrl is null when wp_get_attachment_url returns false', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 46, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 46, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( false );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 46,
			'mapId'        => 'map-no-url',
		],
		'',
		map_fake_block(),
	);

	expect( array_key_exists( 'gpxFileUrl', $captured_state['map-no-url'] ?? [] ) )->toBeTrue();
	expect( $captured_state['map-no-url']['gpxFileUrl'] )->toBeNull();

} );

// ---------------------------------------------------------------------------
// Interaction flags — defaults (Pan and Zoom ON; box-zoom dropped from state)
// ---------------------------------------------------------------------------

test( 'wp_interactivity_state receives Pan and Zoom as true by default', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 50, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 50, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 50,
			'mapId'        => 'map-interaction-defaults',
		],
		'',
		map_fake_block(),
	);

	$settings = $captured_state['map-interaction-defaults']['settings'] ?? null;

	expect( $settings )->not->toBeNull();
	expect( $settings['enablePan'] )->toBeTrue();
	expect( $settings['enableZoom'] )->toBeTrue();
	expect( $settings )->not->toHaveKey( 'enableBoxZoom' );

} );

// ---------------------------------------------------------------------------
// Interaction flags — explicit overrides on the two result-named toggles
// are propagated verbatim
// ---------------------------------------------------------------------------

test( 'wp_interactivity_state reflects explicit Pan and Zoom overrides', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 51, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 51, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 51,
			'mapId'        => 'map-interaction-overrides',
			'enablePan'    => false,
			'enableZoom'   => false,
		],
		'',
		map_fake_block(),
	);

	$settings = $captured_state['map-interaction-overrides']['settings'] ?? null;

	expect( $settings )->not->toBeNull();
	expect( $settings['enablePan'] )->toBeFalse();
	expect( $settings['enableZoom'] )->toBeFalse();

} );

// ---------------------------------------------------------------------------
// Empty string when no attachment is configured
// ---------------------------------------------------------------------------

test( 'returns empty string when attachmentId is 0', function (): void {

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( false );

	$html = Render_Map::render(
		[
			'attachmentId' => 0,
			'mapId'        => 'map-empty',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->toBe( '' );

} );

// ---------------------------------------------------------------------------
// attachmentId — ctype_digit parsing rejects scientific notation and floats
// ---------------------------------------------------------------------------

test( 'attachmentId is rejected when supplied as scientific notation', function (): void {

	// `is_numeric('1e3')` is true and `(int) '1e3'` is 1, which would silently
	// produce a render against attachment 1. `ctype_digit('1e3')` is false, so
	// the value is coerced to 0 and the early-return path fires — empty string.
	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( false );

	$html = Render_Map::render(
		[
			'attachmentId' => '1e3',
			'mapId'        => 'map-sci',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->toBe( '' );

} );

test( 'attachmentId is rejected when supplied as a float string', function (): void {

	// `is_numeric('4.2')` is true and `(int) '4.2'` is 4. The hardened path
	// rejects non-digit-only strings, so the value coerces to 0 and the
	// renderer returns an empty string instead of dispatching to attachment 4.
	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( false );

	$html = Render_Map::render(
		[
			'attachmentId' => '4.2',
			'mapId'        => 'map-float',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->toBe( '' );

} );

test( 'attachmentId is rejected when supplied as a negative integer string', function (): void {

	// The leading minus sign disqualifies the value from `ctype_digit`, which
	// only accepts decimal-digit characters. `is_numeric('-7')` is true and
	// `(int) '-7'` is -7 — the early-return path already filters negative
	// integers via `<= 0`, but rejecting the string up front documents the
	// stricter contract more precisely.
	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( false );

	$html = Render_Map::render(
		[
			'attachmentId' => '-7',
			'mapId'        => 'map-neg',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->toBe( '' );

} );

test( 'attachmentId still accepts a positive digit-only string', function (): void {

	// Documents the contract: the production path arrives as a proper int
	// after block.json's `"type": "integer"` validation, but a digit-only
	// string is still accepted so the editor's REST round-trips and any
	// upstream renderer that hands the renderer a stringified id keep
	// working.
	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 555, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 555, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$html = Render_Map::render(
		[
			'attachmentId' => '555',
			'mapId'        => 'map-string-id',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->toContain( 'data-wp-interactive' );

} );

// ---------------------------------------------------------------------------
// Waypoint CSS variables — valid waypointColor emits the correct CSS var
// ---------------------------------------------------------------------------

test( 'render output includes waypoint-color CSS variable when waypointColor is set', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 60, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 60, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$html = Render_Map::render(
		[
			'attachmentId'  => 60,
			'mapId'         => 'map-wpt-color',
			'waypointColor' => '#ff0000',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->toContain( '--kntnt-gpx-blocks-waypoint-color: #ff0000' );

} );

// ---------------------------------------------------------------------------
// Waypoint CSS variables — invalid waypointColor emits no CSS var
// ---------------------------------------------------------------------------

test( 'render output omits waypoint-color CSS variable when waypointColor is invalid', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 61, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 61, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$html = Render_Map::render(
		[
			'attachmentId'  => 61,
			'mapId'         => 'map-wpt-invalid',
			'waypointColor' => 'javascript:alert(1)',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->not->toContain( '--kntnt-gpx-blocks-waypoint-color' );

} );

// ---------------------------------------------------------------------------
// Track CSS variables — valid trackColor emits the correct CSS var
// ---------------------------------------------------------------------------

test( 'render output includes track-color CSS variable when trackColor is set', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 70, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 70, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$html = Render_Map::render(
		[
			'attachmentId' => 70,
			'mapId'        => 'map-track-color',
			'trackColor'   => '#0073aa',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->toContain( '--kntnt-gpx-blocks-track-color: #0073aa' );

} );

// ---------------------------------------------------------------------------
// Track CSS variables — invalid trackColor emits no CSS var
// ---------------------------------------------------------------------------

test( 'render output omits track-color CSS variable when trackColor is invalid', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 71, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 71, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$html = Render_Map::render(
		[
			'attachmentId' => 71,
			'mapId'        => 'map-track-invalid',
			'trackColor'   => 'javascript:alert(1)',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->not->toContain( '--kntnt-gpx-blocks-track-color' );

} );

// ---------------------------------------------------------------------------
// Track cursor CSS variables — valid trackCursorColor emits the correct CSS var
// ---------------------------------------------------------------------------

test( 'render output includes track-cursor-color CSS variable when trackCursorColor is set', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 72, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 72, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$html = Render_Map::render(
		[
			'attachmentId'     => 72,
			'mapId'            => 'map-cursor-color',
			'trackCursorColor' => '#d63638',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->toContain( '--kntnt-gpx-blocks-track-cursor-color: #d63638' );

} );

// ---------------------------------------------------------------------------
// Track cursor CSS variables — invalid trackCursorColor emits no CSS var
// ---------------------------------------------------------------------------

test( 'render output omits track-cursor-color CSS variable when trackCursorColor is invalid', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 73, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 73, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$html = Render_Map::render(
		[
			'attachmentId'     => 73,
			'mapId'            => 'map-cursor-invalid',
			'trackCursorColor' => 'not-a-color',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->not->toContain( '--kntnt-gpx-blocks-track-cursor-color' );

} );

// ---------------------------------------------------------------------------
// Tooltip CSS variables — invalid tooltipNameFontWeight emits no CSS var
// ---------------------------------------------------------------------------

test( 'render output omits tooltip-name-font-weight CSS variable when weight is unsafe', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 62, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 62, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$html = Render_Map::render(
		[
			'attachmentId'          => 62,
			'mapId'                 => 'map-tooltip-weight',
			'tooltipNameFontWeight' => 'expression(alert(1))',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->not->toContain( '--kntnt-gpx-blocks-tooltip-name-font-weight' );

} );

// ---------------------------------------------------------------------------
// Tooltip CSS variables — valid hex8 tooltipBackground emits the CSS var
// ---------------------------------------------------------------------------

test( 'render output includes tooltip-bg CSS variable for valid hex8 tooltipBackground', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 80, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 80, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$html = Render_Map::render(
		[
			'attachmentId'      => 80,
			'mapId'             => 'map-tt-bg-hex8',
			'tooltipBackground' => '#000000cc',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->toContain( '--kntnt-gpx-blocks-tooltip-bg: #000000cc' );

} );

// ---------------------------------------------------------------------------
// Tooltip CSS variables — rgba(...) tooltipBackground is rejected (hex-only)
// ---------------------------------------------------------------------------

test( 'render output omits tooltip-bg CSS variable for rgba() tooltipBackground', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 81, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 81, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$html = Render_Map::render(
		[
			'attachmentId'      => 81,
			'mapId'             => 'map-tt-bg-rgba',
			'tooltipBackground' => 'rgba(0,0,0,0.8)',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->not->toContain( '--kntnt-gpx-blocks-tooltip-bg' );

} );

// ---------------------------------------------------------------------------
// Tooltip CSS variables — javascript: payload is rejected
// ---------------------------------------------------------------------------

test( 'render output omits tooltip-bg CSS variable for javascript: payload', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 82, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 82, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$html = Render_Map::render(
		[
			'attachmentId'      => 82,
			'mapId'             => 'map-tt-bg-bad',
			'tooltipBackground' => 'javascript:alert(1)',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->not->toContain( '--kntnt-gpx-blocks-tooltip-bg' );

} );

// ---------------------------------------------------------------------------
// Track CSS variable — hex8 trackColor round-trips through Color_Sanitizer
// ---------------------------------------------------------------------------

test( 'render output preserves a hex8 trackColor through the shared Color_Sanitizer', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 84, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 84, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$html = Render_Map::render(
		[
			'attachmentId' => 84,
			'mapId'        => 'map-track-hex8',
			'trackColor'   => '#ff000080',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->toContain( '--kntnt-gpx-blocks-track-color: #ff000080' );

} );

// ---------------------------------------------------------------------------
// Tooltip toggles — both default to true in the hydrated state
// ---------------------------------------------------------------------------

test( 'wp_interactivity_state includes tooltipShowName and tooltipShowDesc as true by default', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 83, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 83, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured ): void {
			$captured = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 83,
			'mapId'        => 'map-tt-defaults',
		],
		'',
		map_fake_block(),
	);

	$settings = $captured['map-tt-defaults']['settings'] ?? null;

	expect( $settings )->not->toBeNull();
	expect( $settings['tooltipShowName'] )->toBeTrue();
	expect( $settings['tooltipShowDesc'] )->toBeTrue();

} );

// ---------------------------------------------------------------------------
// Tooltip toggles — explicit false propagates into the hydrated state
// ---------------------------------------------------------------------------

test( 'wp_interactivity_state propagates tooltipShowName and tooltipShowDesc when set to false', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 84, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 84, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured ): void {
			$captured = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId'    => 84,
			'mapId'           => 'map-tt-toggles-off',
			'tooltipShowName' => false,
			'tooltipShowDesc' => false,
		],
		'',
		map_fake_block(),
	);

	$settings = $captured['map-tt-toggles-off']['settings'] ?? null;

	expect( $settings )->not->toBeNull();
	expect( $settings['tooltipShowName'] )->toBeFalse();
	expect( $settings['tooltipShowDesc'] )->toBeFalse();

} );

// ---------------------------------------------------------------------------
// Track cursor toggle — defaults to true and propagates an explicit false
// ---------------------------------------------------------------------------

test( 'wp_interactivity_state includes enableTrackPositionCursor as true by default', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 85, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 85, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured ): void {
			$captured = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 85,
			'mapId'        => 'map-cursor-default',
		],
		'',
		map_fake_block(),
	);

	$settings = $captured['map-cursor-default']['settings'] ?? null;

	expect( $settings )->not->toBeNull();
	expect( $settings['enableTrackPositionCursor'] )->toBeTrue();

} );

test( 'wp_interactivity_state propagates enableTrackPositionCursor when set to false', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 86, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 86, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured ): void {
			$captured = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId'    => 86,
			'mapId'           => 'map-cursor-off',
			'enableTrackPositionCursor' => false,
		],
		'',
		map_fake_block(),
	);

	$settings = $captured['map-cursor-off']['settings'] ?? null;

	expect( $settings )->not->toBeNull();
	expect( $settings['enableTrackPositionCursor'] )->toBeFalse();

} );

// ---------------------------------------------------------------------------
// Zoom result — defaults to true and propagates an explicit false
// ---------------------------------------------------------------------------

test( 'wp_interactivity_state includes enableZoom as true by default', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 87, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 87, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured ): void {
			$captured = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 87,
			'mapId'        => 'map-zoom-default',
		],
		'',
		map_fake_block(),
	);

	$settings = $captured['map-zoom-default']['settings'] ?? null;

	expect( $settings )->not->toBeNull();
	expect( $settings['enableZoom'] )->toBeTrue();

} );

test( 'wp_interactivity_state propagates enableZoom when set to false', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 88, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 88, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured ): void {
			$captured = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 88,
			'mapId'        => 'map-zoom-off',
			'enableZoom'   => false,
		],
		'',
		map_fake_block(),
	);

	$settings = $captured['map-zoom-off']['settings'] ?? null;

	expect( $settings )->not->toBeNull();
	expect( $settings['enableZoom'] )->toBeFalse();

} );

// ---------------------------------------------------------------------------
// Cursor sync — trackCumDist[] and totalDistance are emitted in state
// ---------------------------------------------------------------------------

test( 'wp_interactivity_state includes trackCumDist[] aligned with simplified vertices', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 90, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 90, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 90,
			'mapId'        => 'map-cursor',
		],
		'',
		map_fake_block(),
	);

	$slice = $captured_state['map-cursor'] ?? null;

	expect( $slice )->not->toBeNull();
	expect( $slice )->toHaveKey( 'trackCumDist' );
	expect( $slice )->toHaveKey( 'totalDistance' );

	$cum_dist = $slice['trackCumDist'];
	expect( $cum_dist )->toBeArray();

	// First entry is 0; sequence is monotonically non-decreasing.
	expect( $cum_dist[0] )->toBe( 0.0 );
	$len = count( $cum_dist );
	for ( $i = 1; $i < $len; $i++ ) {
		expect( $cum_dist[ $i ] )->toBeGreaterThanOrEqual( $cum_dist[ $i - 1 ] );
	}

	// Length aligns with the simplified GeoJSON LineString's coordinate count.
	$features = $slice['geojson']['features'] ?? [];
	$line     = $features[0]['geometry']['coordinates'] ?? [];
	expect( $cum_dist )->toHaveCount( count( $line ) );

} );

test( 'totalDistance equals the cached statistics distance', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 91, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 91, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 91,
			'mapId'        => 'map-total',
		],
		'',
		map_fake_block(),
	);

	$slice = $captured_state['map-total'] ?? null;
	expect( $slice )->not->toBeNull();

	// map_seeded_store seeds statistics.distance = 5500.0.
	expect( $slice['totalDistance'] )->toBe( 5500.0 );

} );

// ---------------------------------------------------------------------------
// Consent contract — bypassConsent defaults to false on the frontend
// ---------------------------------------------------------------------------

test( 'bypassConsent is false in state on the frontend (no REST request)', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 80, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 80, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	// current_user_can defaults to false via beforeEach; no REST request is
	// in flight unless a previous test defined the constant. Either way the
	// per-render gate must report bypass=false.
	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	$html = Render_Map::render(
		[
			'attachmentId' => 80,
			'mapId'        => 'map-frontend',
		],
		'',
		map_fake_block(),
	);

	$slice = $captured_state['map-frontend'] ?? null;

	expect( $slice )->not->toBeNull();
	expect( $slice['bypassConsent'] )->toBeFalse();

	// State carries no fields from the old WordPress-Consent-API design.
	expect( $slice )->not->toHaveKey( 'consent' );
	expect( $slice )->not->toHaveKey( 'consentCategory' );
	expect( $slice )->not->toHaveKey( 'consentService' );

	// The plugin renders no placeholder, no button, no consent UI.
	expect( $html )->not->toContain( 'kntnt-gpx-blocks-map-placeholder' );
	expect( $html )->not->toContain( 'kntnt-gpx-blocks-map-canvas' );
	expect( $html )->not->toContain( 'actions.grantConsent' );

} );

// ---------------------------------------------------------------------------
// Consent contract — bypassConsent is true in editor (REST + edit_posts)
// ---------------------------------------------------------------------------
//
// REST_REQUEST is defined here for the first time in this file. PHP constants
// cannot be undefined, so this test is intentionally placed last among the
// consent tests; the surrounding tests rely on `current_user_can` returning
// false (the beforeEach default) to keep `bypassConsent` false even after the
// constant has been defined.

test( 'bypassConsent is true in state when REST_REQUEST is true and user can edit_posts', function (): void {

	if ( ! defined( 'REST_REQUEST' ) ) {
		define( 'REST_REQUEST', true );
	}

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 81, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 81, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );
	Functions\when( 'current_user_can' )->alias(
		static fn ( string $cap ): bool => 'edit_posts' === $cap
	);

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 81,
			'mapId'        => 'map-editor',
		],
		'',
		map_fake_block(),
	);

	$slice = $captured_state['map-editor'] ?? null;

	expect( $slice )->not->toBeNull();
	expect( $slice['bypassConsent'] )->toBeTrue();

} );

// ---------------------------------------------------------------------------
// Wrapper contract — get_block_wrapper_attributes propagates editor-UI
// affordances (alignwide / alignfull, HTML anchor, additional className).
// ---------------------------------------------------------------------------

test( 'wrapper carries alignwide when align attribute is "wide"', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 110, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 110, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$GLOBALS['kntnt_map_test_attrs'] = [ 'align' => 'wide' ];

	$html = Render_Map::render(
		[
			'attachmentId' => 110,
			'mapId'        => 'map-wide',
			'align'        => 'wide',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->toContain( 'alignwide' );

} );

test( 'wrapper carries alignfull when align attribute is "full"', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 111, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 111, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$GLOBALS['kntnt_map_test_attrs'] = [ 'align' => 'full' ];

	$html = Render_Map::render(
		[
			'attachmentId' => 111,
			'mapId'        => 'map-full',
			'align'        => 'full',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->toContain( 'alignfull' );

} );

test( 'wrapper carries HTML id when anchor attribute is set', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 112, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 112, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$GLOBALS['kntnt_map_test_attrs'] = [ 'anchor' => 'my-trail' ];

	$html = Render_Map::render(
		[
			'attachmentId' => 112,
			'mapId'        => 'map-anchor',
			'anchor'       => 'my-trail',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->toContain( 'id="my-trail"' );

} );

test( 'wrapper carries the user-supplied additional CSS class', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 113, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 113, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$GLOBALS['kntnt_map_test_attrs'] = [ 'className' => 'is-style-rounded my-extra-class' ];

	$html = Render_Map::render(
		[
			'attachmentId' => 113,
			'mapId'        => 'map-class',
			'className'    => 'is-style-rounded my-extra-class',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->toContain( 'is-style-rounded my-extra-class' );

} );

// ---------------------------------------------------------------------------
// Tile-layer registry — default (no tileProvider) resolves to openstreetmap/mapnik
// ---------------------------------------------------------------------------

test( 'wp_interactivity_state carries openstreetmap/mapnik tile provider when no attribute is saved', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 200, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 200, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 200,
			'mapId'        => 'map-default-tile',
		],
		'',
		map_fake_block(),
	);

	$slice = $captured_state['map-default-tile'] ?? null;

	expect( $slice )->not->toBeNull();
	expect( $slice )->toHaveKey( 'tileProvider' );
	expect( $slice['tileProvider']['url'] )->toContain( 'tile.openstreetmap.org' );
	expect( $slice['tileProvider']['attribution'] )->toContain( 'OpenStreetMap' );
	expect( $slice['tileProvider']['url'] )->not->toContain( '{KEY}' );
	expect( $slice['tileProvider']['subdomains'] )->toBe( [ 'a', 'b', 'c' ] );

	// The resolved record carries only the four Leaflet-facing fields —
	// no embedded id, no requiresKey, no styles map.
	expect( $slice['tileProvider'] )->not->toHaveKey( 'id' );
	expect( $slice['tileProvider'] )->not->toHaveKey( 'requiresKey' );

} );

// ---------------------------------------------------------------------------
// Tile-layer registry — explicit (provider, style) plus API key substitutes {KEY}
// ---------------------------------------------------------------------------

test( 'wp_interactivity_state substitutes the per-provider option-layer key into {KEY}', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 201, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 201, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	// Issue #149 — the per-base-provider key map lives in the WP option
	// `kntnt_gpx_blocks_tile_provider_keys`, no longer in block attributes.
	$GLOBALS['kntnt_map_test_tile_keys'] = [
		'thunderforest' => 'ABC123',
		'maptiler'      => 'OTHER_PROVIDER_KEY',
	];

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 201,
			'mapId'        => 'map-thunderforest',
			'tileProvider' => 'thunderforest',
			'tileStyle'    => 'outdoor',
		],
		'',
		map_fake_block(),
	);

	$slice = $captured_state['map-thunderforest'] ?? null;

	expect( $slice )->not->toBeNull();
	expect( $slice['tileProvider']['url'] )->not->toContain( '{KEY}' );
	expect( $slice['tileProvider']['url'] )->toContain( 'apikey=ABC123' );
	// Keys for other providers must not leak into the rendered URL.
	expect( $slice['tileProvider']['url'] )->not->toContain( 'OTHER_PROVIDER_KEY' );

} );

test( 'wp_interactivity_state falls back to the provider default style when tileStyle is unknown', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 230, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 230, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	// `openstreetmap`'s default is `mapnik`; orphan style id should
	// resolve to the mapnik URL.
	Render_Map::render(
		[
			'attachmentId' => 230,
			'mapId'        => 'map-orphan-style',
			'tileProvider' => 'openstreetmap',
			'tileStyle'    => 'no-such-style',
		],
		'',
		map_fake_block(),
	);

	$slice = $captured_state['map-orphan-style'] ?? null;

	expect( $slice )->not->toBeNull();
	expect( $slice['tileProvider']['url'] )->toContain( 'tile.openstreetmap.org' );
	expect( $slice['tileProvider']['url'] )->not->toContain( 'cyclosm' );

} );

// ---------------------------------------------------------------------------
// Polyline-only state — paid provider with empty key emits null URL
// ---------------------------------------------------------------------------

test( 'wp_interactivity_state emits a null tileProvider URL when requiresKey provider has no option-layer entry', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 210, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 210, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	// Option store is empty (default beforeEach state) — paid provider
	// with no key fails closed.
	Render_Map::render(
		[
			'attachmentId' => 210,
			'mapId'        => 'map-paid-no-key',
			'tileProvider' => 'thunderforest',
			'tileStyle'    => 'outdoor',
		],
		'',
		map_fake_block(),
	);

	$slice = $captured_state['map-paid-no-key'] ?? null;

	// The polyline data and the rest of the tile metadata survive — only the
	// URL is omitted so the view module ships polyline-only.
	expect( $slice )->not->toBeNull();
	expect( $slice )->toHaveKey( 'tileProvider' );
	expect( $slice['tileProvider']['url'] )->toBeNull();
	expect( $slice['tileProvider']['attribution'] )->toContain( 'Thunderforest' );
	expect( $slice['tileProvider']['maxZoom'] )->toBe( 22 );
	// Polyline data is unaffected by the missing key.
	expect( $slice['geojson'] )->toBeArray();
	expect( $slice['totalDistance'] )->toBeFloat();

} );

test( 'wp_interactivity_state emits a null tileProvider URL when requiresKey provider has whitespace-only option-layer entry', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 211, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 211, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$GLOBALS['kntnt_map_test_tile_keys'] = [ 'mapbox' => "  \t\n  " ];

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 211,
			'mapId'        => 'map-paid-whitespace-key',
			'tileProvider' => 'mapbox',
			'tileStyle'    => 'outdoors',
		],
		'',
		map_fake_block(),
	);

	$slice = $captured_state['map-paid-whitespace-key'] ?? null;

	// `trim($option_key) === ''` covers whitespace-only keys, which would
	// otherwise produce a URL with a useless query parameter.
	expect( $slice )->not->toBeNull();
	expect( $slice['tileProvider']['url'] )->toBeNull();

} );

test( 'wp_interactivity_state still emits a null URL when requiresKey provider has an empty key on the bypassConsent path', function (): void {

	// Editor preview path mirrors the frontend gate: missing key produces a
	// null URL even when bypassConsent is true. The editor surface shows a
	// Notice (per issue #82) and renders polyline-only.
	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 212, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 212, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );
	if ( ! defined( 'REST_REQUEST' ) ) {
		define( 'REST_REQUEST', true );
	}
	Functions\when( 'current_user_can' )->alias(
		static function ( string $cap ): bool {
			return $cap === 'edit_posts';
		}
	);

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 212,
			'mapId'        => 'map-editor-paid-no-key',
			'tileProvider' => 'stadia-maps',
			'tileStyle'    => 'outdoors',
		],
		'',
		map_fake_block(),
	);

	$slice = $captured_state['map-editor-paid-no-key'] ?? null;

	expect( $slice )->not->toBeNull();
	expect( $slice['bypassConsent'] )->toBeTrue();
	// The editor render also produces a null URL — the polyline-only contract
	// applies on both paths.
	expect( $slice['tileProvider']['url'] )->toBeNull();

} );

test( 'wp_interactivity_state still substitutes the URL for free providers regardless of option-layer state', function (): void {

	// `requiresKey === false` means the free path: a missing option-layer
	// entry is the normal case and must not null the URL out.
	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 213, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 213, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 213,
			'mapId'        => 'map-free-no-key',
			'tileProvider' => 'openstreetmap',
			'tileStyle'    => 'mapnik',
		],
		'',
		map_fake_block(),
	);

	$slice = $captured_state['map-free-no-key'] ?? null;

	expect( $slice )->not->toBeNull();
	expect( $slice['tileProvider']['url'] )->toBeString();
	expect( $slice['tileProvider']['url'] )->toContain( 'tile.openstreetmap.org' );

} );

// ---------------------------------------------------------------------------
// Tile-layer registry — unknown provider id falls back to openstreetmap
// ---------------------------------------------------------------------------

test( 'wp_interactivity_state falls back to openstreetmap for an unknown tileProvider (orphan provider id)', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 202, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 202, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 202,
			'mapId'        => 'map-unknown-tile',
			'tileProvider' => 'definitely-not-a-real-provider',
			'tileStyle'    => 'whatever',
		],
		'',
		map_fake_block(),
	);

	$slice = $captured_state['map-unknown-tile'] ?? null;

	expect( $slice )->not->toBeNull();
	expect( $slice['tileProvider']['url'] )->toContain( 'tile.openstreetmap.org' );

} );

// ---------------------------------------------------------------------------
// Per-provider option-layer keys (issue #149): the site-wide option
// `kntnt_gpx_blocks_tile_provider_keys` is provider-keyed; the registry
// pulls the entry for the currently-selected provider only, never leaks
// another provider's key, and degrades cleanly when the option is
// missing, malformed, or holds a non-string value.
// ---------------------------------------------------------------------------

test( 'wp_interactivity_state picks the per-provider key from the option-layer map for the selected provider', function (): void {

	// Three paid providers configured at once; only the selected provider's
	// key should reach the rendered URL.
	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 220, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 220, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$GLOBALS['kntnt_map_test_tile_keys'] = [
		'thunderforest' => 'THUNDER_KEY',
		'maptiler'      => 'MAPTILER_KEY',
		'mapbox'        => 'MAPBOX_KEY',
	];

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 220,
			'mapId'        => 'map-multi-provider-keys',
			'tileProvider' => 'maptiler',
			'tileStyle'    => 'outdoor',
		],
		'',
		map_fake_block(),
	);

	$slice = $captured_state['map-multi-provider-keys'] ?? null;

	expect( $slice )->not->toBeNull();
	expect( $slice['tileProvider']['url'] )->toContain( 'key=MAPTILER_KEY' );
	expect( $slice['tileProvider']['url'] )->not->toContain( 'THUNDER_KEY' );
	expect( $slice['tileProvider']['url'] )->not->toContain( 'MAPBOX_KEY' );

} );

test( 'wp_interactivity_state treats a non-array option value as empty without crashing', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 221, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 221, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	// Direct DB edit / migration could store a non-array shape; the
	// registry's option_key_for() coerces back to empty silently.
	$GLOBALS['kntnt_map_test_tile_keys'] = 'not-an-array';

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 221,
			'mapId'        => 'map-non-array-keys',
			'tileProvider' => 'thunderforest',
			'tileStyle'    => 'outdoor',
		],
		'',
		map_fake_block(),
	);

	$slice = $captured_state['map-non-array-keys'] ?? null;

	expect( $slice )->not->toBeNull();
	// Missing key → polyline-only fall-back (null URL).
	expect( $slice['tileProvider']['url'] )->toBeNull();

} );

test( 'wp_interactivity_state coerces a non-string option entry to empty', function (): void {

	// A numeric or boolean entry under the provider key is bogus — coerce
	// to the empty string so the polyline-only path engages instead of
	// concatenating something nonsensical into the URL.
	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 223, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 223, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$GLOBALS['kntnt_map_test_tile_keys'] = [ 'thunderforest' => 12345 ];

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 223,
			'mapId'        => 'map-non-string-key',
			'tileProvider' => 'thunderforest',
			'tileStyle'    => 'outdoor',
		],
		'',
		map_fake_block(),
	);

	$slice = $captured_state['map-non-string-key'] ?? null;

	expect( $slice )->not->toBeNull();
	expect( $slice['tileProvider']['url'] )->toBeNull();

} );

// ---------------------------------------------------------------------------
// Tile-layer registry — selected overlays appear; unknown ids are dropped
// ---------------------------------------------------------------------------

test( 'wp_interactivity_state carries selected tileOverlay pairs and drops unknown pairs', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 203, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 203, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 203,
			'mapId'        => 'map-overlays',
			'tileOverlays' => [
				[
					'provider' => 'waymarked-trails',
					'layer'    => 'hiking',
				],
				[
					'provider' => 'definitely-not-real',
					'layer'    => 'whatever',
				],
			],
		],
		'',
		map_fake_block(),
	);

	$slice = $captured_state['map-overlays'] ?? null;

	expect( $slice )->not->toBeNull();
	expect( $slice )->toHaveKey( 'tileOverlays' );
	expect( $slice['tileOverlays'] )->toHaveCount( 1 );
	expect( $slice['tileOverlays'][0]['url'] )->toContain( 'waymarkedtrails.org/hiking' );

} );

test( 'wp_interactivity_state defaults tileOverlays to an empty array', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 204, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 204, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 204,
			'mapId'        => 'map-no-overlays',
		],
		'',
		map_fake_block(),
	);

	$slice = $captured_state['map-no-overlays'] ?? null;

	expect( $slice )->not->toBeNull();
	expect( $slice['tileOverlays'] )->toBe( [] );

} );

test( 'wp_interactivity_state carries multiple selected overlay pairs in editor-configured order', function (): void {

	// Filter the registry to publish two overlay providers so we can verify
	// ordering against the saved attribute array, not against the registry's
	// internal key order.
	Functions\when( 'apply_filters' )->alias(
		static function ( string $name, mixed $value ): mixed {
			if ( $name === 'kntnt_gpx_blocks_tile_overlays' ) {
				return [
					'waymarked-trails' => [
						'label'       => 'Waymarked Trails',
						'requiresKey' => false,
						'layers'      => [
							'hiking' => [
								'label'       => 'Hiking',
								'url'         => 'https://tile.waymarkedtrails.org/hiking/{z}/{x}/{y}.png',
								'attribution' => '&copy; Waymarked',
								'maxZoom'     => 18,
							],
						],
					],
					'custom-overlay'   => [
						'label'       => 'Custom Overlay',
						'requiresKey' => false,
						'layers'      => [
							'grid' => [
								'label'       => 'Grid',
								'url'         => 'https://grid.example.com/{z}/{x}/{y}.png',
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

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 205, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 205, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 205,
			'mapId'        => 'map-multi-overlays',
			'tileOverlays' => [
				[
					'provider' => 'custom-overlay',
					'layer'    => 'grid',
				],
				[
					'provider' => 'waymarked-trails',
					'layer'    => 'hiking',
				],
			],
		],
		'',
		map_fake_block(),
	);

	$slice = $captured_state['map-multi-overlays'] ?? null;

	expect( $slice )->not->toBeNull();
	expect( $slice['tileOverlays'] )->toHaveCount( 2 );
	expect( $slice['tileOverlays'][0]['url'] )->toContain( 'grid.example.com' );
	expect( $slice['tileOverlays'][1]['url'] )->toContain( 'waymarkedtrails.org/hiking' );

} );

test( 'overlay records in state carry url, attribution, and maxZoom (slim, no id)', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 206, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 206, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 206,
			'mapId'        => 'map-overlay-record-shape',
			'tileOverlays' => [
				[
					'provider' => 'waymarked-trails',
					'layer'    => 'hiking',
				],
			],
		],
		'',
		map_fake_block(),
	);

	$record = $captured_state['map-overlay-record-shape']['tileOverlays'][0] ?? null;

	expect( $record )->not->toBeNull();
	expect( $record )->toHaveKey( 'url' );
	expect( $record )->toHaveKey( 'attribution' );
	expect( $record )->toHaveKey( 'maxZoom' );

	// Slim record — symmetric with resolve_provider; no id field.
	expect( $record )->not->toHaveKey( 'id' );

	expect( $record['url'] )->toContain( 'waymarkedtrails.org' );
	expect( $record['attribution'] )->toContain( 'Waymarked' );
	expect( $record['maxZoom'] )->toBe( 18 );

	// waymarked-trails ships without subdomains, so the resolver must omit
	// the subdomains key rather than emitting it as null.
	expect( array_key_exists( 'subdomains', $record ) )->toBeFalse();

} );

test( 'overlay records in state preserve subdomains when present in the overlay provider', function (): void {

	// Inject a custom overlay provider that declares subdomains so the
	// resolver has something to forward verbatim. None of the four default
	// overlay providers declare subdomains.
	Functions\when( 'apply_filters' )->alias(
		static function ( string $name, mixed $value ): mixed {
			if ( $name === 'kntnt_gpx_blocks_tile_overlays' ) {
				return [
					'sd-overlay' => [
						'label'       => 'Subdomains Overlay',
						'requiresKey' => false,
						'subdomains'  => [ 'a', 'b', 'c' ],
						'layers'      => [
							'main' => [
								'label'       => 'Main',
								'url'         => 'https://{s}.overlay.example.com/{z}/{x}/{y}.png',
								'attribution' => '&copy; Example',
								'maxZoom'     => 20,
							],
						],
					],
				];
			}
			return $value;
		}
	);

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 207, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 207, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 207,
			'mapId'        => 'map-overlay-subdomains',
			'tileOverlays' => [
				[
					'provider' => 'sd-overlay',
					'layer'    => 'main',
				],
			],
		],
		'',
		map_fake_block(),
	);

	$record = $captured_state['map-overlay-subdomains']['tileOverlays'][0] ?? null;

	expect( $record )->not->toBeNull();
	expect( $record['subdomains'] )->toBe( [ 'a', 'b', 'c' ] );

} );

test( 'unknown overlay pairs in attributes are silently dropped from state', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 208, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 208, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 208,
			'mapId'        => 'map-overlay-unknown-only',
			'tileOverlays' => [
				[
					'provider' => 'definitely-not-real',
					'layer'    => 'whatever',
				],
				[
					'provider' => 'also-not-real',
					'layer'    => 'whatever',
				],
			],
		],
		'',
		map_fake_block(),
	);

	$slice = $captured_state['map-overlay-unknown-only'] ?? null;

	expect( $slice )->not->toBeNull();
	expect( $slice['tileOverlays'] )->toBe( [] );

} );

test( 'wp_interactivity_state substitutes the per-overlay-provider tileOverlayApiKeys entry into {KEY}', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 220, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 220, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId'       => 220,
			'mapId'              => 'map-overlay-key-sub',
			'tileOverlays'       => [
				[
					'provider' => 'openweathermap',
					'layer'    => 'clouds',
				],
			],
			'tileOverlayApiKeys' => [ 'openweathermap' => 'OWM-XYZ' ],
		],
		'',
		map_fake_block(),
	);

	$record = $captured_state['map-overlay-key-sub']['tileOverlays'][0] ?? null;

	expect( $record )->not->toBeNull();
	expect( $record['url'] )->not->toContain( '{KEY}' );
	expect( $record['url'] )->toContain( 'appid=OWM-XYZ' );

} );

test( 'wp_interactivity_state drops the overlay pair when the key-required provider has no key', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 221, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 221, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId'       => 221,
			'mapId'              => 'map-overlay-no-key',
			'tileOverlays'       => [
				[
					'provider' => 'openweathermap',
					'layer'    => 'clouds',
				],
				[
					'provider' => 'waymarked-trails',
					'layer'    => 'hiking',
				],
			],
			'tileOverlayApiKeys' => [],
		],
		'',
		map_fake_block(),
	);

	$slice = $captured_state['map-overlay-no-key'] ?? null;

	expect( $slice )->not->toBeNull();

	// The openweathermap pair is dropped; waymarked-trails survives so the
	// base map and other overlays still render.
	expect( $slice['tileOverlays'] )->toHaveCount( 1 );
	expect( $slice['tileOverlays'][0]['url'] )->toContain( 'waymarkedtrails.org/hiking' );

} );

test( 'wp_interactivity_state treats a non-array tileOverlays attribute as empty', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 222, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 222, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 222,
			'mapId'        => 'map-overlay-bad-attr',
			'tileOverlays' => 'not-an-array',
		],
		'',
		map_fake_block(),
	);

	expect( $captured_state['map-overlay-bad-attr']['tileOverlays'] )->toBe( [] );

} );

test( 'wp_interactivity_state coerces malformed overlay pair entries out', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 223, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 223, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Map::render(
		[
			'attachmentId' => 223,
			'mapId'        => 'map-overlay-coerce',
			'tileOverlays' => [
				[
					'provider' => 'waymarked-trails',
					'layer'    => 'hiking',
				],
				'not-an-array',
				[ 'provider' => 'waymarked-trails' ],
				[ 'layer' => 'hiking' ],
				[
					'provider' => 123,
					'layer'    => 'hiking',
				],
				[
					'provider' => 'waymarked-trails',
					'layer'    => '',
				],
			],
		],
		'',
		map_fake_block(),
	);

	$slice = $captured_state['map-overlay-coerce'] ?? null;

	expect( $slice )->not->toBeNull();
	expect( $slice['tileOverlays'] )->toHaveCount( 1 );
	expect( $slice['tileOverlays'][0]['url'] )->toContain( 'waymarkedtrails.org/hiking' );

} );

// ---------------------------------------------------------------------------
// Empty colour defaults — every colour-attribute is skipped in the rendered
// inline `style` when no value is supplied. After issue #84 every colour
// attribute defaults to the empty string; the SCSS fallbacks in style.scss
// own the visual baseline. This test guards that contract for all six
// colours consolidated into the single "Color" PanelColorSettings panel.
// ---------------------------------------------------------------------------

test( 'render output emits no colour CSS variables when every colour attribute is empty (issue #84)', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 300, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 300, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$html = Render_Map::render(
		[
			'attachmentId'      => 300,
			'mapId'             => 'map-no-colours',
			'trackColor'        => '',
			'trackCursorColor'  => '',
			'waypointColor'     => '',
			'tooltipBackground' => '',
			'tooltipNameColor'  => '',
			'tooltipDescColor'  => '',
		],
		'',
		map_fake_block(),
	);

	$colour_vars = [
		'--kntnt-gpx-blocks-track-color',
		'--kntnt-gpx-blocks-track-cursor-color',
		'--kntnt-gpx-blocks-waypoint-color',
		'--kntnt-gpx-blocks-tooltip-bg',
		'--kntnt-gpx-blocks-tooltip-name-color',
		'--kntnt-gpx-blocks-tooltip-desc-color',
	];

	foreach ( $colour_vars as $var ) {
		expect( $html )->not->toContain( $var, sprintf( 'Expected %s to be omitted for empty value', $var ) );
	}

} );

// ---------------------------------------------------------------------------
// Edge case — an explicit alpha-bearing hex8 colour (#000000cc) round-trips
// through the inspector → attribute store → render path into the emitted
// CSS variable verbatim. This protects the "Waypoint background" entry in
// the consolidated PanelColorSettings panel, which is alpha-aware via the
// panel-level `enableAlpha` flag.
// ---------------------------------------------------------------------------

test( 'render output preserves a hex8 tooltipBackground end-to-end (issue #84 alpha edge case)', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 301, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 301, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$html = Render_Map::render(
		[
			'attachmentId'      => 301,
			'mapId'             => 'map-alpha-bg',
			'tooltipBackground' => '#000000cc',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->toContain( '--kntnt-gpx-blocks-tooltip-bg: #000000cc' );

} );

// ---------------------------------------------------------------------------
// Issue #109 — the plugin's inline style must terminate with `;` so core's
// appended border-/shadow-/dimensions-supports declarations never run into
// the plugin's last declaration. WordPress concatenates the
// caller-supplied style and its own declarations with a *space*, not a
// semicolon, so the boundary character has to come from the plugin side.
// The user-reported symptom is `border-top-left-radius:3rem` being folded
// into the value of the preceding custom property (`tooltipDescFontStyle:
// italic`) and the top-left corner rendering square.
// ---------------------------------------------------------------------------

test( 'inline style is terminated so core-appended per-corner border-radius survives (issue #109)', function (): void {

	// Seed a renderable Map with the user-reported attribute shape: a
	// tooltipDescFontStyle attribute on top of per-corner radii. The
	// font-style value sits at the end of the joined declaration string,
	// so any missing terminator absorbs the next declaration.
	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 400, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 400, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	// Simulate core's per-corner border-radius emission. With non-equal
	// per-corner values, Border_Radius_Normalizer correctly leaves the
	// four declarations as-is, and core's style engine appends them onto
	// the wrapper's `style` attribute with a leading space.
	$GLOBALS['kntnt_map_test_core_style'] =
		'border-top-left-radius:3rem;'
		. 'border-top-right-radius:var(--wp--preset--border-radius--md);'
		. 'border-bottom-left-radius:0.75rem;'
		. 'border-bottom-right-radius:3rem;';

	$html = Render_Map::render(
		[
			'attachmentId'          => 400,
			'mapId'                 => 'map-issue-109',
			'tooltipNameFontWeight' => 'bold',
			'tooltipDescFontStyle'  => 'italic',
		],
		'',
		map_fake_block(),
	);

	// Extract the wrapper's style attribute. The pattern is greedy-safe
	// because the style attribute is double-quoted and the source never
	// embeds a literal double-quote inside it.
	$matched = preg_match( '/\sstyle="([^"]*)"/', $html, $style_match );
	expect( $matched )->toBe( 1 );
	$style_attr = $style_match[1];

	// Parse the style attribute into a (property → value) declaration
	// list the way a CSS parser would: split on `;`, trim each piece,
	// drop the empty trailing slot, then split each non-empty piece on
	// the first `:`.
	$declarations = [];
	foreach ( explode( ';', $style_attr ) as $piece ) {
		$piece = trim( $piece );
		if ( '' === $piece ) {
			continue;
		}
		[ $name, $value ] = array_pad( explode( ':', $piece, 2 ), 2, '' );
		$declarations[ trim( $name ) ] = trim( $value );
	}

	// border-top-left-radius must survive as a standalone declaration
	// with its expected value — not absorbed into a preceding custom
	// property.
	expect( $declarations )->toHaveKey( 'border-top-left-radius' );
	expect( $declarations['border-top-left-radius'] )->toBe( '3rem' );

	// And the plugin's last custom property must not have absorbed the
	// border declaration into its value.
	expect( $declarations )->toHaveKey( '--kntnt-gpx-blocks-tooltip-desc-font-style' );
	expect( $declarations['--kntnt-gpx-blocks-tooltip-desc-font-style'] )->toBe( 'italic' );

	// Defence in depth: no plugin custom property must contain a
	// border-*-radius substring in its value (the malformed-decl shape
	// the bug produces).
	foreach ( $declarations as $name => $value ) {
		if ( str_starts_with( (string) $name, '--kntnt-gpx-blocks-' ) ) {
			expect( $value )->not->toContain(
				'border-top-left-radius',
				sprintf( 'Expected %s value not to absorb a border-radius declaration', $name ),
			);
			expect( $value )->not->toContain(
				'border-top-right-radius',
				sprintf( 'Expected %s value not to absorb a border-radius declaration', $name ),
			);
			expect( $value )->not->toContain(
				'border-bottom-left-radius',
				sprintf( 'Expected %s value not to absorb a border-radius declaration', $name ),
			);
			expect( $value )->not->toContain(
				'border-bottom-right-radius',
				sprintf( 'Expected %s value not to absorb a border-radius declaration', $name ),
			);
		}
	}

} );

// ---------------------------------------------------------------------------
// Issue #117 — the plugin-defined default `min-height` is normalised at
// the attribute source through the `Dimensions_Defaults` filter, not
// per-consumer inline injection inside `Render_Map::render()`. The B-tests
// here invoke the filter on a parsed block, hand its output to render,
// and assert that the wrapper inline style carries the value through
// core's `get_block_wrapper_attributes()` pipeline (simulated in the
// test harness) instead of plugin-side string concatenation.
// ---------------------------------------------------------------------------

/**
 * Simulates core's dimensions block-supports CSS emission from a
 * `style.dimensions` slot.
 *
 * Real WordPress walks the parsed `attrs.style.dimensions` and appends
 * `min-height: <value>` and `aspect-ratio: <value>` to the wrapper's
 * `style` attribute. The plugin's render code stopped touching that
 * slot with issue #117 — the value must therefore reach the wrapper
 * through this path, which the test harness simulates by writing into
 * `$GLOBALS['kntnt_map_test_core_style']`.
 *
 * @param array<string,mixed> $attrs Parsed-block attrs after the filter.
 */
function map_simulate_dimensions_core_style( array $attrs ): void {
	$dimensions = is_array( $attrs['style'] ?? null )
		? ( is_array( $attrs['style']['dimensions'] ?? null )
			? $attrs['style']['dimensions']
			: [] )
		: [];
	$parts = [];
	$min   = $dimensions['minHeight'] ?? '';
	if ( is_string( $min ) && '' !== $min ) {
		$parts[] = 'min-height:' . $min;
	}
	$ar = $dimensions['aspectRatio'] ?? '';
	if ( is_string( $ar ) && '' !== $ar ) {
		$parts[] = 'aspect-ratio:' . $ar;
	}
	$GLOBALS['kntnt_map_test_core_style'] = count( $parts ) > 0
		? implode( ';', $parts ) . ';'
		: '';
}

test( 'B1: render emits min-height:30vh via filter-normalised attrs when both minHeight and aspectRatio are blank', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 700, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 700, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	// Run the filter on a parsed block whose attrs carry neither
	// minHeight nor aspectRatio, then hand the normalised attrs to
	// render. The test harness's get_block_wrapper_attributes mock
	// surfaces the dimensions block-supports CSS the same way real
	// core would.
	$filter = new \Kntnt\Gpx_Blocks\Rendering\Dimensions_Defaults();
	$parsed = $filter->filter(
		[
			'blockName'    => 'kntnt-gpx-blocks/map',
			'attrs'        => [
				'attachmentId' => 700,
				'mapId'        => 'map-default-min-height',
			],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		],
	);
	map_simulate_dimensions_core_style( $parsed['attrs'] );

	$html = Render_Map::render( $parsed['attrs'], '', map_fake_block() );

	$matched = preg_match( '/<div\b[^>]*\sstyle="([^"]*)"/', $html, $style_match );
	expect( $matched )->toBe( 1 );
	expect( $style_match[1] )->toContain( 'min-height:30vh' );

} );

test( 'B1 (Elevation): filter injects min-height=15vh (Step 3 of docs/elevation-rebuild.md)', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 760, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 760, map_fixture_path( 'happy-path.gpx' ) );

	$filter = new \Kntnt\Gpx_Blocks\Rendering\Dimensions_Defaults();
	$parsed = $filter->filter(
		[
			'blockName'    => 'kntnt-gpx-blocks/elevation',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		],
	);

	// Step 3 of docs/elevation-rebuild.md: Elevation's wrapper has a
	// `min-height: 15vh` default, gated on `minHeight` blank alone
	// (regardless of `aspectRatio`). The filter writes the default
	// onto the parsed block's attrs at the attribute source.
	expect( $parsed['attrs']['style']['dimensions']['minHeight'] ?? null )
		->toBe( '15vh' );

} );

test( 'B2: Render_Map no longer concatenates min-height into its own style_parts', function (): void {

	// When the parsed block carries neither minHeight nor aspectRatio
	// AND the filter has NOT run, Render_Map must not emit any
	// min-height of its own — the responsibility moved to
	// Dimensions_Defaults. The harness's core-style global stays empty
	// here, so any min-height surfacing in the wrapper would have to
	// come from plugin-side concatenation, which is what we want to
	// see has gone away.
	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 710, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 710, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$html = Render_Map::render(
		[
			'attachmentId' => 710,
			'mapId'        => 'map-plain',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->not->toContain( 'min-height: 30vh' );
	expect( $html )->not->toContain( 'min-height:30vh' );

} );

test( 'B3: with aspectRatio set and minHeight blank, the filter injects min-height=30vh alongside (issue #146)', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 720, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 720, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	// Issue #146 simplified Map's gate to match Elevation's. With
	// `aspectRatio` set and `minHeight` blank, the filter now injects
	// the 30vh floor at the attribute source; the user-set aspect
	// ratio stacks alongside via the normal CSS cascade rather than
	// being fought by a hidden min-height. Both values surface on
	// the wrapper through the standard dimensions block-supports
	// pipeline simulated below.
	$filter = new \Kntnt\Gpx_Blocks\Rendering\Dimensions_Defaults();
	$parsed = $filter->filter(
		[
			'blockName'    => 'kntnt-gpx-blocks/map',
			'attrs'        => [
				'attachmentId' => 720,
				'mapId'        => 'map-aspect-only',
				'style'        => [ 'dimensions' => [ 'aspectRatio' => '16/9' ] ],
			],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		],
	);
	map_simulate_dimensions_core_style( $parsed['attrs'] );

	$html = Render_Map::render( $parsed['attrs'], '', map_fake_block() );

	$matched = preg_match( '/<div\b[^>]*\sstyle="([^"]*)"/', $html, $style_match );
	expect( $matched )->toBe( 1 );
	expect( $style_match[1] )->toContain( 'aspect-ratio:16/9' );
	expect( $style_match[1] )->toContain( 'min-height:30vh' );

} );

test( 'B-explicit: user-set min-height passes through unchanged and the plugin does not double-emit', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 730, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 730, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );

	$filter = new \Kntnt\Gpx_Blocks\Rendering\Dimensions_Defaults();
	$parsed = $filter->filter(
		[
			'blockName'    => 'kntnt-gpx-blocks/map',
			'attrs'        => [
				'attachmentId' => 730,
				'mapId'        => 'map-explicit',
				'style'        => [ 'dimensions' => [ 'minHeight' => '500px' ] ],
			],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		],
	);
	map_simulate_dimensions_core_style( $parsed['attrs'] );

	$html = Render_Map::render( $parsed['attrs'], '', map_fake_block() );

	$matched = preg_match( '/<div\b[^>]*\sstyle="([^"]*)"/', $html, $style_match );
	expect( $matched )->toBe( 1 );
	expect( $style_match[1] )->toContain( 'min-height:500px' );
	expect( $style_match[1] )->not->toContain( '30vh' );

} );
