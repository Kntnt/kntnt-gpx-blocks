<?php
/**
 * Tests for Rendering\Render_Elevation.
 *
 * Brain Monkey stubs all WordPress functions (get_the_ID, get_post,
 * parse_blocks, get_post_meta, get_attached_file, apply_filters,
 * current_user_can, esc_html, esc_html__, esc_attr, __, wp_json_encode,
 * wp_interactivity_state, number_format_i18n) so the render class can run
 * without a WordPress install.
 *
 * The test drives the full static call chain:
 *   Render_Elevation::render()
 *     → Resolve_Map_Id::resolve()
 *     → Attachment_Cache::get()
 *     → Lttb::downsample()
 *     → SVG composition
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Cache\Cache_Version;
use Kntnt\Gpx_Blocks\Rendering\Render_Elevation;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Builds a fake WP_Block-like object that Render_Elevation reads from.
 *
 * WP_Block cannot be instantiated in unit tests (its constructor requires
 * a full block-type registry). Render_Elevation only reads $block->context,
 * so an anonymous class with a public $context property is enough.
 *
 * @param int $post_id The post ID to expose via context['postId'].
 *
 * @return object
 */
function elev_fake_block( int $post_id ): object {
	return new class( $post_id ) {

		/**
		 * Block context values, keyed by context name.
		 *
		 * @var array<string, mixed>
		 */
		public array $context;

		/**
		 * Initialises the context with the supplied post ID.
		 *
		 * @param int $post_id The post ID to expose via context['postId'].
		 */
		public function __construct( int $post_id ) {
			$this->context = [ 'postId' => $post_id ];
		}
	};
}

/**
 * Wires Brain Monkey stubs for the WordPress meta functions against an
 * in-memory store.
 *
 * @param array<int, array<string, mixed>> $store Meta store keyed by post ID.
 */
function elev_bind_meta( array &$store ): void {

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
 * Stubs get_post() to return a minimal post object for the given ID.
 *
 * @param int $post_id Post ID to match.
 */
function elev_stub_get_post( int $post_id ): void {
	$post               = new stdClass();
	$post->ID           = $post_id;
	$post->post_content = '';

	Functions\when( 'get_post' )->alias(
		static fn ( int $id ): ?object => $id === $post_id ? $post : null
	);
}

/**
 * Stubs parse_blocks() to return a fixed block array.
 *
 * @param array<int, array<string, mixed>> $blocks Block tree to return.
 */
function elev_stub_parse_blocks( array $blocks ): void {
	Functions\when( 'parse_blocks' )->justReturn( $blocks );
}

/**
 * Builds a minimal parsed-block array for a GPX Map block.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $map_id        mapId attribute.
 *
 * @return array<string, mixed>
 */
function elev_map_block( int $attachment_id, string $map_id = 'map-abc' ): array {
	return [
		'blockName'   => 'kntnt-gpx-blocks/map',
		'attrs'       => [
			'attachmentId' => $attachment_id,
			'mapId'        => $map_id,
		],
		'innerBlocks' => [],
	];
}

/**
 * Stubs apply_filters() to pass the first argument through unchanged and
 * number_format_i18n() to use plain number_format so values are predictable.
 */
function elev_stub_format(): void {

	Functions\when( 'apply_filters' )->alias(
		static function ( string $filter, mixed $fallback ): mixed {
			return $fallback;
		}
	);

	Functions\when( 'number_format_i18n' )->alias(
		static fn ( float|int $n, int $d = 0 ): string => number_format( (float) $n, $d, '.', '' )
	);

}

/**
 * Stubs the escaping helpers and translation pass-throughs.
 */
function elev_stub_escape(): void {
	Functions\when( 'esc_html' )->returnArg( 1 );
	Functions\when( 'esc_attr' )->returnArg( 1 );
	Functions\when( 'esc_html__' )->returnArg( 1 );
	Functions\when( 'esc_attr__' )->returnArg( 1 );
	Functions\when( '__' )->returnArg( 1 );
}

/**
 * Stubs get_attached_file() to return a valid path (fixture file).
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $path          Absolute path to the file.
 */
function elev_stub_attached_file( int $attachment_id, string $path ): void {
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
function elev_fixture_path( string $name ): string {
	return __DIR__ . '/fixtures/gpx/' . $name;
}

/**
 * Builds an in-memory meta store pre-seeded with a current-version cache entry.
 *
 * @param int                           $attachment_id Attachment ID.
 * @param array<int, array<int, float>> $coordinates   GeoJSON [lon,lat,ele?] array.
 * @param array<string, float|null>     $statistics    Statistics array.
 * @param string                        $fixture       Fixture filename.
 *
 * @return array<int, array<string, mixed>>
 */
function elev_seeded_store(
	int $attachment_id,
	array $coordinates,
	array $statistics,
	string $fixture = 'happy-path.gpx',
): array {

	$path = elev_fixture_path( $fixture );
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
			'_kntnt_gpx_blocks_statistics'  => $statistics,
			'_kntnt_gpx_blocks_version'     => Cache_Version::CURRENT,
			'_kntnt_gpx_blocks_source_hash' => $hash,
		],
	];

}

/**
 * Builds a synthetic 3D coordinate array along a great-circle near Stockholm,
 * with elevations rising linearly from 100 m to 200 m.
 *
 * @param int $count Number of points to generate.
 *
 * @return array<int, array<int, float>>
 */
function elev_synthetic_coords_3d( int $count ): array {
	$out = [];
	for ( $i = 0; $i < $count; $i++ ) {
		$ratio = $count > 1 ? $i / ( $count - 1 ) : 0.0;
		$lon   = 18.0 + 0.05 * $ratio;
		$lat   = 59.0 + 0.05 * $ratio;
		$ele   = 100.0 + 100.0 * $ratio;
		$out[] = [ $lon, $lat, $ele ];
	}
	return $out;
}

/**
 * Builds a synthetic 2D coordinate array (no elevation).
 *
 * @param int $count Number of points to generate.
 *
 * @return array<int, array<int, float>>
 */
function elev_synthetic_coords_2d( int $count ): array {
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
	elev_stub_format();
	elev_stub_escape();
	Functions\when( 'wp_json_encode' )->alias(
		static fn ( mixed $v ): string|false => json_encode( $v )
	);
	Functions\when( 'wp_interactivity_state' )->justReturn( null );
	Functions\when( 'current_user_can' )->justReturn( false );
} );

// ---------------------------------------------------------------------------
// Track with elevation: SVG, polyline, desc all present
// ---------------------------------------------------------------------------

test( 'renders an svg, polyline and desc when track has elevation', function (): void {

	$coords = elev_synthetic_coords_3d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( 42, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 1 );
	elev_stub_parse_blocks( [ elev_map_block( 42, 'map-abc' ) ] );
	elev_stub_attached_file( 42, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 1 );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 1 ) );

	expect( $html )
		->toContain( '<svg' )
		->toContain( '<polyline' )
		->toContain( '<desc' );

} );

// ---------------------------------------------------------------------------
// Track without elevation: empty state
// ---------------------------------------------------------------------------

test( 'renders the no-elevation empty state when track has 2D coordinates only', function (): void {

	$coords = elev_synthetic_coords_2d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => null,
		'max_elevation' => null,
		'ascent'        => null,
		'descent'       => null,
	];

	$store = elev_seeded_store( 43, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 2 );
	elev_stub_parse_blocks( [ elev_map_block( 43, 'map-flat' ) ] );
	elev_stub_attached_file( 43, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 2 );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 2 ) );

	expect( $html )
		->toContain( 'No elevation data in this GPX file.' )
		->toContain( 'kntnt-gpx-blocks-elevation--empty' )
		->not->toContain( '<svg' );

} );

// ---------------------------------------------------------------------------
// Resolve_Map_Id error: editor sees error notice, visitor sees empty string
// ---------------------------------------------------------------------------

test( 'returns error notice for editor when no map block is present', function (): void {

	elev_stub_get_post( 3 );
	elev_stub_parse_blocks( [] );
	Functions\when( 'get_the_ID' )->justReturn( 3 );
	Functions\when( 'current_user_can' )->justReturn( true );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 3 ) );

	expect( $html )
		->toContain( 'kntnt-gpx-blocks-error' )
		->toContain( 'no-map' );

} );

test( 'returns empty string for visitor when no map block is present', function (): void {

	elev_stub_get_post( 4 );
	elev_stub_parse_blocks( [] );
	Functions\when( 'get_the_ID' )->justReturn( 4 );
	Functions\when( 'current_user_can' )->justReturn( false );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 4 ) );

	expect( $html )->toBe( '' );

} );

// ---------------------------------------------------------------------------
// X-axis unit selection: km when total >= 2000 m, m otherwise
// ---------------------------------------------------------------------------

test( 'x-axis labels use km when total distance is 2 km or more', function (): void {

	// 200 points spread across a track that runs ~5.5 km — well above the
	// 2 km threshold, so the x-axis should switch to km.
	$coords = elev_synthetic_coords_3d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( 50, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 7 );
	elev_stub_parse_blocks( [ elev_map_block( 50, 'map-long' ) ] );
	elev_stub_attached_file( 50, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 7 );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 7 ) );

	// Strip everything inside <desc>...</desc> so the assertion only inspects
	// tick labels — Value_Formatter::format_distance() also produces "5.5 km"
	// inside the screen-reader summary, which would otherwise satisfy the
	// pattern even if the axis ticks were wrong.
	$svg_only = (string) preg_replace( '#<desc>.*?</desc>#s', '', $html );

	// X-axis tick labels include "X km".
	expect( $svg_only )->toMatch( '/\d+(\.\d+)?\s+km<\/text>/' );

} );

test( 'x-axis labels use m when total distance is less than 2 km', function (): void {

	// Build a tight track (~110 m, well below the 2 km threshold) by sampling
	// only a short ratio of the synthetic generator's range.
	$coords = [];
	for ( $i = 0; $i < 100; $i++ ) {
		$ratio = $i / 99.0 * 0.001;
		$lon   = 18.0 + 0.05 * $ratio;
		$lat   = 59.0 + 0.05 * $ratio;
		$ele   = 100.0 + 50.0 * $ratio;
		$coords[] = [ $lon, $lat, $ele ];
	}
	$stats = [
		'distance'      => 50.0,
		'min_elevation' => 100.0,
		'max_elevation' => 100.05,
		'ascent'        => 0.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( 51, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 8 );
	elev_stub_parse_blocks( [ elev_map_block( 51, 'map-short' ) ] );
	elev_stub_attached_file( 51, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 8 );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 8 ) );

	// Strip <desc> so distance text inside the screen-reader summary doesn't
	// poison the negative match — the assertion targets axis ticks only.
	$svg_only = (string) preg_replace( '#<desc>.*?</desc>#s', '', $html );

	// X-axis ticks have plain metres labels, e.g. "0 m" or "50 m".
	expect( $svg_only )->not->toMatch( '/\d+(\.\d+)?\s+km<\/text>/' );

} );
