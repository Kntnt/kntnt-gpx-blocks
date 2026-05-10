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

	// Reset the per-test attribute capture and install a default
	// get_block_wrapper_attributes mock that mirrors core's behaviour for the
	// fields the production code passes in (class + style) and additionally
	// honours the editor-UI fields the wrapper-contract tests inject via
	// $GLOBALS['kntnt_elev_test_attrs'].
	$GLOBALS['kntnt_elev_test_attrs'] = [];
	Functions\when( 'get_block_wrapper_attributes' )->alias(
		static function ( array $extras = [] ): string {
			$attrs       = is_array( $GLOBALS['kntnt_elev_test_attrs'] ?? null )
				? $GLOBALS['kntnt_elev_test_attrs']
				: [];
			$class_parts = [ 'wp-block-kntnt-gpx-blocks-elevation' ];
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
			if ( isset( $extras['style'] ) && '' !== $extras['style'] ) {
				$out .= sprintf( ' style="%s"', $extras['style'] );
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
// Editor snapshot path: when ServerSideRender forwards the live block tree
// via the __editorBlockSnapshot attribute, the renderer prefers it over the
// saved post content so the preview reflects unsaved edits. The role:local
// flag in block.json keeps the attribute out of persisted markup; the
// edit_posts gate keeps a frontend visitor from being able to influence
// resolution by passing crafted attributes (defence-in-depth — the REST
// block-renderer endpoint already enforces this capability for its callers).
// ---------------------------------------------------------------------------

test( 'editor snapshot resolves a Map even when post_content is empty', function (): void {

	$coords = elev_synthetic_coords_3d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( 90, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 20 );
	// Saved post_content has no Map block — the bug condition this fix targets.
	elev_stub_parse_blocks( [] );
	elev_stub_attached_file( 90, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 20 );
	Functions\when( 'current_user_can' )->justReturn( true );

	$attributes = [
		'mapId'                 => 'auto',
		'__editorBlockSnapshot' => [ elev_map_block( 90, 'map-snap' ) ],
	];

	$html = Render_Elevation::render( $attributes, '', elev_fake_block( 20 ) );

	expect( $html )
		->toContain( '<svg' )
		->toContain( '<polyline' )
		->not->toContain( 'kntnt-gpx-blocks-error' );

} );

test( 'editor snapshot is ignored for users without edit_posts (frontend safety)', function (): void {

	$coords = elev_synthetic_coords_3d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( 91, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 21 );
	// Saved post_content has no Map. The visitor should get the empty render
	// path regardless of whatever the snapshot says, so a hostile attribute
	// payload cannot trigger a render.
	elev_stub_parse_blocks( [] );
	elev_stub_attached_file( 91, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 21 );
	Functions\when( 'current_user_can' )->justReturn( false );

	$attributes = [
		'mapId'                 => 'auto',
		'__editorBlockSnapshot' => [ elev_map_block( 91, 'map-snap' ) ],
	];

	$html = Render_Elevation::render( $attributes, '', elev_fake_block( 21 ) );

	// Visitor with no edit_posts on a post lacking a Map → empty output.
	expect( $html )->toBe( '' );

} );

test( 'logged-in editor on frontend ignores empty default snapshot and resolves via saved post_content', function (): void {

	// Regression guard for v0.4.2: the block.json default for
	// __editorBlockSnapshot is `[]`. Without the count > 0 check,
	// WordPress filling in that default at render time made the snapshot
	// path kick in for any logged-in editor visiting the frontend, which
	// then resolved against the empty default tree → no-map → editor saw
	// an error notice on the published page. The count gate keeps the
	// snapshot path scoped to genuine editor-preview requests.

	$coords = elev_synthetic_coords_3d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( 93, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 23 );
	elev_stub_parse_blocks( [ elev_map_block( 93, 'map-saved' ) ] );
	elev_stub_attached_file( 93, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 23 );
	Functions\when( 'current_user_can' )->justReturn( true );

	$attributes = [
		'mapId'                 => 'auto',
		'__editorBlockSnapshot' => [],
	];

	$html = Render_Elevation::render( $attributes, '', elev_fake_block( 23 ) );

	expect( $html )
		->toContain( '<svg' )
		->toContain( '<polyline' )
		->not->toContain( 'kntnt-gpx-blocks-error' );

} );

test( 'frontend render still resolves via saved post_content when no snapshot is set', function (): void {

	$coords = elev_synthetic_coords_3d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( 92, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 22 );
	elev_stub_parse_blocks( [ elev_map_block( 92, 'map-saved' ) ] );
	elev_stub_attached_file( 92, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 22 );
	Functions\when( 'current_user_can' )->justReturn( false );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 22 ) );

	expect( $html )
		->toContain( '<svg' )
		->toContain( '<polyline' );

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

// ---------------------------------------------------------------------------
// Cursor-sync directives: data-wp-watch must be present on the wrapper
// ---------------------------------------------------------------------------

test( 'wrapper div carries the data-wp-watch cursor-change directive', function (): void {

	$coords = elev_synthetic_coords_3d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( 60, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 10 );
	elev_stub_parse_blocks( [ elev_map_block( 60, 'map-watch' ) ] );
	elev_stub_attached_file( 60, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 10 );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 10 ) );

	expect( $html )->toContain( 'data-wp-watch="callbacks.onElevationCursorChange"' );

} );

test( 'svg contains the server-rendered cursor group with plot data attributes', function (): void {

	$coords = elev_synthetic_coords_3d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( 61, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 11 );
	elev_stub_parse_blocks( [ elev_map_block( 61, 'map-cursor-grp' ) ] );
	elev_stub_attached_file( 61, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 11 );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 11 ) );

	expect( $html )
		->toContain( 'kntnt-gpx-blocks-elevation-cursor' )
		->toContain( 'data-plot-left=' )
		->toContain( 'data-plot-right=' )
		->toContain( 'kntnt-gpx-blocks-elevation-cursor-line' )
		->toContain( 'kntnt-gpx-blocks-elevation-cursor-dot' )
		->toContain( 'kntnt-gpx-blocks-elevation-cursor-tooltip-bg' )
		->toContain( 'kntnt-gpx-blocks-elevation-cursor-tooltip-text' );

} );

// ---------------------------------------------------------------------------
// Theming: colour attributes emitted as CSS custom properties
// ---------------------------------------------------------------------------

test( 'emits --kntnt-gpx-blocks-line-color when lineColor is a valid hex', function (): void {

	$coords = elev_synthetic_coords_3d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( 70, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 20 );
	elev_stub_parse_blocks( [ elev_map_block( 70, 'map-color' ) ] );
	elev_stub_attached_file( 70, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 20 );

	$html = Render_Elevation::render(
		[
			'mapId'     => 'auto',
			'lineColor' => '#0073aa',
		],
		'',
		elev_fake_block( 20 ),
	);

	expect( $html )->toContain( '--kntnt-gpx-blocks-line-color: #0073aa' );

} );

test( 'does not emit --kntnt-gpx-blocks-line-color when lineColor is invalid', function (): void {

	$coords = elev_synthetic_coords_3d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( 71, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 21 );
	elev_stub_parse_blocks( [ elev_map_block( 71, 'map-bad-color' ) ] );
	elev_stub_attached_file( 71, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 21 );

	$html = Render_Elevation::render(
		[
			'mapId'     => 'auto',
			'lineColor' => 'javascript:alert(1)',
		],
		'',
		elev_fake_block( 21 ),
	);

	expect( $html )->not->toContain( '--kntnt-gpx-blocks-line-color' );

} );

test( 'emits --kntnt-gpx-blocks-tooltip-background with alpha when tooltipBackground is hex8', function (): void {

	$coords = elev_synthetic_coords_3d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( 74, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 24 );
	elev_stub_parse_blocks( [ elev_map_block( 74, 'map-alpha' ) ] );
	elev_stub_attached_file( 74, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 24 );

	$html = Render_Elevation::render(
		[
			'mapId'             => 'auto',
			'tooltipBackground' => '#000000cc',
		],
		'',
		elev_fake_block( 24 ),
	);

	expect( $html )->toContain( '--kntnt-gpx-blocks-tooltip-background: #000000cc' );

} );

test( 'emits --kntnt-gpx-blocks-axis-font-weight when font weight is valid', function (): void {

	$coords = elev_synthetic_coords_3d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( 72, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 22 );
	elev_stub_parse_blocks( [ elev_map_block( 72, 'map-weight' ) ] );
	elev_stub_attached_file( 72, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 22 );

	$html = Render_Elevation::render(
		[
			'mapId'          => 'auto',
			'axisFontWeight' => 'bold',
		],
		'',
		elev_fake_block( 22 ),
	);

	expect( $html )->toContain( '--kntnt-gpx-blocks-axis-font-weight: bold' );

} );

test( 'does not emit --kntnt-gpx-blocks-axis-font-weight when font weight is invalid', function (): void {

	$coords = elev_synthetic_coords_3d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( 73, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 23 );
	elev_stub_parse_blocks( [ elev_map_block( 73, 'map-bad-weight' ) ] );
	elev_stub_attached_file( 73, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 23 );

	$html = Render_Elevation::render(
		[
			'mapId'          => 'auto',
			'axisFontWeight' => 'extra-bold; color: red',
		],
		'',
		elev_fake_block( 23 ),
	);

	expect( $html )->not->toContain( '--kntnt-gpx-blocks-axis-font-weight' );

} );

// ---------------------------------------------------------------------------
// Background-colour control removal (issue #95)
//
// The dedicated background-colour control was removed in favour of the
// standard "wrap in Group" pattern. The renderer must therefore never
// emit the background custom property nor any `background:` declaration,
// regardless of whether a stale `backgroundColor` attribute survives in
// post_content from an older save. The SVG and the empty-state container
// must both render without a background.
// ---------------------------------------------------------------------------

test( 'does not emit --kntnt-gpx-blocks-background-color on the normal path', function (): void {

	$coords = elev_synthetic_coords_3d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( 80, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 30 );
	elev_stub_parse_blocks( [ elev_map_block( 80, 'map-no-bg' ) ] );
	elev_stub_attached_file( 80, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 30 );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 30 ) );

	expect( $html )
		->not->toContain( '--kntnt-gpx-blocks-background-color' )
		->not->toContain( 'background:' )
		->not->toContain( 'background ' );

} );

test( 'ignores a stale backgroundColor attribute carried over from an older save', function (): void {

	$coords = elev_synthetic_coords_3d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( 81, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 31 );
	elev_stub_parse_blocks( [ elev_map_block( 81, 'map-stale-bg' ) ] );
	elev_stub_attached_file( 81, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 31 );

	$html = Render_Elevation::render(
		[
			'mapId'           => 'auto',
			'backgroundColor' => '#abcdef',
		],
		'',
		elev_fake_block( 31 ),
	);

	// Pre-1.0: extra attributes from older saves are silent no-ops. The hex
	// colour must not surface anywhere in the rendered output.
	expect( $html )
		->not->toContain( '--kntnt-gpx-blocks-background-color' )
		->not->toContain( '#abcdef' )
		->not->toContain( 'background:' );

} );

test( 'rendered svg has no background style or attribute', function (): void {

	$coords = elev_synthetic_coords_3d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( 82, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 32 );
	elev_stub_parse_blocks( [ elev_map_block( 82, 'map-svg-bg' ) ] );
	elev_stub_attached_file( 82, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 32 );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 32 ) );

	// Isolate the <svg ...> opening tag so the assertion targets only the SVG
	// element's own attributes/style, not the surrounding wrapper.
	$matched = preg_match( '#<svg\b[^>]*>#', $html, $svg_match );
	expect( $matched )->toBe( 1 );
	$svg_open = $svg_match[0];

	expect( $svg_open )
		->not->toContain( 'background' )
		->not->toContain( 'fill=' );

} );

test( 'empty-state wrapper emits no background custom property and no inline style', function (): void {

	elev_setup_empty_path( 220, 120, 'map-empty-no-bg' );

	$html = Render_Elevation::render(
		[
			'mapId'           => 'auto',
			'backgroundColor' => '#abcdef',
		],
		'',
		elev_fake_block( 120 ),
	);

	// The empty-state wrapper is now style-free unless core's block supports
	// inject something (none of which a stale backgroundColor attribute can
	// trigger). Confirm the marker class is present and the background hooks
	// are absent.
	expect( $html )
		->toContain( 'kntnt-gpx-blocks-elevation--empty' )
		->not->toContain( '--kntnt-gpx-blocks-background-color' )
		->not->toContain( '#abcdef' )
		->not->toContain( 'background:' );

} );

// ---------------------------------------------------------------------------
// Cursor-sync state — yMin / yMax bounds match the rendered polyline
// ---------------------------------------------------------------------------

test( 'wp_interactivity_state includes padded yMin and yMax matching the polyline', function (): void {

	$coords = elev_synthetic_coords_3d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( 70, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 20 );
	elev_stub_parse_blocks( [ elev_map_block( 70, 'map-bounds' ) ] );
	elev_stub_attached_file( 70, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 20 );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 20 ) );

	$slice = $captured_state['map-bounds'] ?? null;

	expect( $slice )->not->toBeNull();
	expect( $slice )->toHaveKey( 'yMin' );
	expect( $slice )->toHaveKey( 'yMax' );

	// The synthetic series spans 100 m → 200 m; the padded bounds must enclose
	// that range strictly so the polyline never sits flush against the chart edges.
	expect( $slice['yMin'] )->toBeFloat();
	expect( $slice['yMax'] )->toBeFloat();
	expect( $slice['yMin'] )->toBeLessThan( 100.0 );
	expect( $slice['yMax'] )->toBeGreaterThan( 200.0 );
	expect( $slice['yMax'] )->toBeGreaterThan( $slice['yMin'] );

} );

test( 'yMin and yMax are present even when the elevation series is flat', function (): void {

	// A perfectly flat track exercises the zero-span fallback in padded_y_bounds.
	$coords = [];
	for ( $i = 0; $i < 100; $i++ ) {
		$ratio = $i / 99.0;
		$coords[] = [ 18.0 + 0.05 * $ratio, 59.0 + 0.05 * $ratio, 150.0 ];
	}
	$stats = [
		'distance'      => 5500.0,
		'min_elevation' => 150.0,
		'max_elevation' => 150.0,
		'ascent'        => 0.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( 71, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 21 );
	elev_stub_parse_blocks( [ elev_map_block( 71, 'map-flat' ) ] );
	elev_stub_attached_file( 71, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 21 );

	$captured_state = null;
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $ns, array $state ) use ( &$captured_state ): void {
			$captured_state = $state;
		}
	);

	Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 21 ) );

	$slice = $captured_state['map-flat'] ?? null;

	expect( $slice )->not->toBeNull();
	expect( $slice['yMax'] )->toBeGreaterThan( $slice['yMin'] );

} );

// ---------------------------------------------------------------------------
// Wrapper contract — normal path. get_block_wrapper_attributes propagates
// editor-UI affordances (alignwide / alignfull, HTML anchor, additional
// className) when the chart actually renders.
// ---------------------------------------------------------------------------

/**
 * Stubs the full set of WP / cache helpers needed for a normal-path render
 * with elevation data. Returns the attachment ID the seeded payload uses.
 *
 * @param int    $attachment_id Attachment ID to seed.
 * @param int    $post_id       Host post ID.
 * @param string $map_id        mapId on the parsed GPX Map block.
 *
 * @return void
 */
function elev_setup_normal_path( int $attachment_id, int $post_id, string $map_id ): void {

	$coords = elev_synthetic_coords_3d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( $attachment_id, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( $post_id );
	elev_stub_parse_blocks( [ elev_map_block( $attachment_id, $map_id ) ] );
	elev_stub_attached_file( $attachment_id, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( $post_id );

}

/**
 * Stubs the WP / cache helpers needed for an empty-data render — a 2D track
 * (no elevation) so render_empty_state() runs.
 *
 * @param int    $attachment_id Attachment ID to seed.
 * @param int    $post_id       Host post ID.
 * @param string $map_id        mapId on the parsed GPX Map block.
 *
 * @return void
 */
function elev_setup_empty_path( int $attachment_id, int $post_id, string $map_id ): void {

	$coords = elev_synthetic_coords_2d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => null,
		'max_elevation' => null,
		'ascent'        => null,
		'descent'       => null,
	];

	$store = elev_seeded_store( $attachment_id, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( $post_id );
	elev_stub_parse_blocks( [ elev_map_block( $attachment_id, $map_id ) ] );
	elev_stub_attached_file( $attachment_id, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( $post_id );

}

test( 'wrapper carries alignwide on the normal path when align is "wide"', function (): void {

	elev_setup_normal_path( 200, 100, 'map-wide' );
	$GLOBALS['kntnt_elev_test_attrs'] = [ 'align' => 'wide' ];

	$html = Render_Elevation::render(
		[ 'mapId' => 'auto', 'align' => 'wide' ],
		'',
		elev_fake_block( 100 ),
	);

	expect( $html )->toContain( 'alignwide' );

} );

test( 'wrapper carries alignfull on the normal path when align is "full"', function (): void {

	elev_setup_normal_path( 201, 101, 'map-full' );
	$GLOBALS['kntnt_elev_test_attrs'] = [ 'align' => 'full' ];

	$html = Render_Elevation::render(
		[ 'mapId' => 'auto', 'align' => 'full' ],
		'',
		elev_fake_block( 101 ),
	);

	expect( $html )->toContain( 'alignfull' );

} );

test( 'wrapper carries HTML id on the normal path when anchor is set', function (): void {

	elev_setup_normal_path( 202, 102, 'map-anchor' );
	$GLOBALS['kntnt_elev_test_attrs'] = [ 'anchor' => 'profile-section' ];

	$html = Render_Elevation::render(
		[ 'mapId' => 'auto', 'anchor' => 'profile-section' ],
		'',
		elev_fake_block( 102 ),
	);

	expect( $html )->toContain( 'id="profile-section"' );

} );

test( 'wrapper carries the user-supplied additional CSS class on the normal path', function (): void {

	elev_setup_normal_path( 203, 103, 'map-class' );
	$GLOBALS['kntnt_elev_test_attrs'] = [ 'className' => 'is-style-rounded my-extra-class' ];

	$html = Render_Elevation::render(
		[ 'mapId' => 'auto', 'className' => 'is-style-rounded my-extra-class' ],
		'',
		elev_fake_block( 103 ),
	);

	expect( $html )->toContain( 'is-style-rounded my-extra-class' );

} );

// ---------------------------------------------------------------------------
// Wrapper contract — empty-data path (render_empty_state). The same four
// editor-UI affordances must reach the empty-state wrapper as well, so a
// theme-aligned/anchored Elevation that happens to lack elevation data
// keeps its anchor and alignment.
// ---------------------------------------------------------------------------

test( 'empty-state wrapper carries alignwide when align is "wide"', function (): void {

	elev_setup_empty_path( 210, 110, 'map-empty-wide' );
	$GLOBALS['kntnt_elev_test_attrs'] = [ 'align' => 'wide' ];

	$html = Render_Elevation::render(
		[ 'mapId' => 'auto', 'align' => 'wide' ],
		'',
		elev_fake_block( 110 ),
	);

	expect( $html )
		->toContain( 'kntnt-gpx-blocks-elevation--empty' )
		->toContain( 'alignwide' );

} );

test( 'empty-state wrapper carries alignfull when align is "full"', function (): void {

	elev_setup_empty_path( 211, 111, 'map-empty-full' );
	$GLOBALS['kntnt_elev_test_attrs'] = [ 'align' => 'full' ];

	$html = Render_Elevation::render(
		[ 'mapId' => 'auto', 'align' => 'full' ],
		'',
		elev_fake_block( 111 ),
	);

	expect( $html )
		->toContain( 'kntnt-gpx-blocks-elevation--empty' )
		->toContain( 'alignfull' );

} );

test( 'empty-state wrapper carries HTML id when anchor is set', function (): void {

	elev_setup_empty_path( 212, 112, 'map-empty-anchor' );
	$GLOBALS['kntnt_elev_test_attrs'] = [ 'anchor' => 'no-elevation-here' ];

	$html = Render_Elevation::render(
		[ 'mapId' => 'auto', 'anchor' => 'no-elevation-here' ],
		'',
		elev_fake_block( 112 ),
	);

	expect( $html )
		->toContain( 'kntnt-gpx-blocks-elevation--empty' )
		->toContain( 'id="no-elevation-here"' );

} );

test( 'empty-state wrapper carries the user-supplied additional CSS class', function (): void {

	elev_setup_empty_path( 213, 113, 'map-empty-class' );
	$GLOBALS['kntnt_elev_test_attrs'] = [ 'className' => 'is-style-rounded my-extra-class' ];

	$html = Render_Elevation::render(
		[ 'mapId' => 'auto', 'className' => 'is-style-rounded my-extra-class' ],
		'',
		elev_fake_block( 113 ),
	);

	expect( $html )
		->toContain( 'kntnt-gpx-blocks-elevation--empty' )
		->toContain( 'is-style-rounded my-extra-class' );

} );
