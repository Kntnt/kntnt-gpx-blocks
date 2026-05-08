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
		->toContain( 'data-wp-watch--cursor="callbacks.onCursorChange"' )
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
// Interaction flags — defaults (drag, pinch, dblclick, keyboard ON; scroll, box OFF)
// ---------------------------------------------------------------------------

test( 'wp_interactivity_state receives correct default interaction flags', function (): void {

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
	expect( $settings['enableDrag'] )->toBeTrue();
	expect( $settings['enableScrollWheelZoom'] )->toBeFalse();
	expect( $settings['enablePinchZoom'] )->toBeTrue();
	expect( $settings['enableDoubleClickZoom'] )->toBeTrue();
	expect( $settings['enableBoxZoom'] )->toBeFalse();
	expect( $settings['enableKeyboard'] )->toBeTrue();

} );

// ---------------------------------------------------------------------------
// Interaction flags — explicit overrides are passed through
// ---------------------------------------------------------------------------

test( 'wp_interactivity_state reflects explicit interaction flag overrides', function (): void {

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
			'attachmentId'          => 51,
			'mapId'                 => 'map-interaction-overrides',
			'enableDrag'            => false,
			'enableScrollWheelZoom' => true,
			'enablePinchZoom'       => false,
			'enableDoubleClickZoom' => false,
			'enableBoxZoom'         => true,
			'enableKeyboard'        => false,
		],
		'',
		map_fake_block(),
	);

	$settings = $captured_state['map-interaction-overrides']['settings'] ?? null;

	expect( $settings )->not->toBeNull();
	expect( $settings['enableDrag'] )->toBeFalse();
	expect( $settings['enableScrollWheelZoom'] )->toBeTrue();
	expect( $settings['enablePinchZoom'] )->toBeFalse();
	expect( $settings['enableDoubleClickZoom'] )->toBeFalse();
	expect( $settings['enableBoxZoom'] )->toBeTrue();
	expect( $settings['enableKeyboard'] )->toBeFalse();

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
// Waypoint CSS variables — valid waypointColor emits the correct CSS var
// ---------------------------------------------------------------------------

test( 'render output includes waypoint-color CSS variable when waypointColor is set', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 60, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 60, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );
	Functions\when( 'sanitize_hex_color' )->alias(
		static function ( string $color ): ?string {
			return preg_match( '/^#([a-fA-F0-9]{3,4}|[a-fA-F0-9]{6}|[a-fA-F0-9]{8})$/', $color )
				? $color
				: null;
		}
	);

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
	Functions\when( 'sanitize_hex_color' )->alias(
		static function ( string $color ): ?string {
			return preg_match( '/^#([a-fA-F0-9]{3,4}|[a-fA-F0-9]{6}|[a-fA-F0-9]{8})$/', $color )
				? $color
				: null;
		}
	);

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
	Functions\when( 'sanitize_hex_color' )->alias(
		static function ( string $color ): ?string {
			return preg_match( '/^#([a-fA-F0-9]{3,4}|[a-fA-F0-9]{6}|[a-fA-F0-9]{8})$/', $color )
				? $color
				: null;
		}
	);

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
	Functions\when( 'sanitize_hex_color' )->alias(
		static function ( string $color ): ?string {
			return preg_match( '/^#([a-fA-F0-9]{3,4}|[a-fA-F0-9]{6}|[a-fA-F0-9]{8})$/', $color )
				? $color
				: null;
		}
	);

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
	Functions\when( 'sanitize_hex_color' )->alias(
		static function ( string $color ): ?string {
			return preg_match( '/^#([a-fA-F0-9]{3,4}|[a-fA-F0-9]{6}|[a-fA-F0-9]{8})$/', $color )
				? $color
				: null;
		}
	);

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
	Functions\when( 'sanitize_hex_color' )->alias(
		static function ( string $color ): ?string {
			return preg_match( '/^#([a-fA-F0-9]{3,4}|[a-fA-F0-9]{6}|[a-fA-F0-9]{8})$/', $color )
				? $color
				: null;
		}
	);

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
// Waypoint CSS variables — invalid waypointLabelFontWeight emits no CSS var
// ---------------------------------------------------------------------------

test( 'render output omits waypoint-label-font-weight CSS variable when weight is unsafe', function (): void {

	$coords = map_synthetic_coords( 10 );
	$store  = map_seeded_store( 62, $coords );
	map_bind_meta( $store );
	map_stub_attached_file( 62, map_fixture_path( 'happy-path.gpx' ) );

	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );
	Functions\when( 'sanitize_hex_color' )->alias(
		static fn ( string $c ): ?string => null
	);

	$html = Render_Map::render(
		[
			'attachmentId'            => 62,
			'mapId'                   => 'map-wpt-weight',
			'waypointLabelFontWeight' => 'expression(alert(1))',
		],
		'',
		map_fake_block(),
	);

	expect( $html )->not->toContain( '--kntnt-gpx-blocks-waypoint-label-font-weight' );

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
