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
	// $GLOBALS['kntnt_elev_test_attrs']. $GLOBALS['kntnt_elev_test_core_style']
	// lets a test simulate core's habit of appending block-supports CSS
	// declarations (border, shadow, dimensions, …) onto the supplied `style`
	// attribute with a *space* separator — the concatenation shape that
	// motivates the trailing-semicolon fix in issue #109.
	$GLOBALS['kntnt_elev_test_attrs']      = [];
	$GLOBALS['kntnt_elev_test_core_style'] = '';
	Functions\when( 'get_block_wrapper_attributes' )->alias(
		static function ( array $extras = [] ): string {
			$attrs       = is_array( $GLOBALS['kntnt_elev_test_attrs'] ?? null )
				? $GLOBALS['kntnt_elev_test_attrs']
				: [];
			$core_style  = is_string( $GLOBALS['kntnt_elev_test_core_style'] ?? null )
				? $GLOBALS['kntnt_elev_test_core_style']
				: '';
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
	$labels_only = (string) preg_replace( '#<desc>.*?</desc>#s', '', $html );

	// X-axis tick labels (HTML overlay spans, post issue #93) include "X km".
	expect( $labels_only )
		->toContain( 'kntnt-gpx-blocks-elevation-x-labels' )
		->toMatch( '/\d+(\.\d+)?\s+km<\/span>/' );

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
	$labels_only = (string) preg_replace( '#<desc>.*?</desc>#s', '', $html );

	// X-axis ticks have plain metres labels, e.g. "0 m" or "50 m".
	// Post issue #93 the labels live in HTML overlay <span>s, not SVG <text>.
	expect( $labels_only )->not->toMatch( '/\d+(\.\d+)?\s+km<\/span>/' );

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

// ---------------------------------------------------------------------------
// Issue #94 — typography refactor.
//
// The dual-context typography model (separate axis vs tooltip with eight
// attributes and four sanitisers) was replaced with block-level
// `supports.typography`. The renderer must therefore no longer emit any
// `--kntnt-gpx-blocks-{axis,tooltip}-font-*` CSS custom property, even when
// older saves carry the legacy attributes through; the axis-label overlays
// and the cursor-tooltip text now inherit typography from the wrapper
// instead.
// ---------------------------------------------------------------------------

test( 'does not emit axis-font CSS variables on the normal path', function (): void {

	elev_setup_normal_path( 72, 22, 'map-no-axis-font' );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 22 ) );

	expect( $html )
		->not->toContain( '--kntnt-gpx-blocks-axis-font-family' )
		->not->toContain( '--kntnt-gpx-blocks-axis-font-size' )
		->not->toContain( '--kntnt-gpx-blocks-axis-font-weight' )
		->not->toContain( '--kntnt-gpx-blocks-axis-font-style' );

} );

test( 'does not emit tooltip-font CSS variables on the normal path', function (): void {

	elev_setup_normal_path( 73, 23, 'map-no-tip-font' );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 23 ) );

	expect( $html )
		->not->toContain( '--kntnt-gpx-blocks-tooltip-font-family' )
		->not->toContain( '--kntnt-gpx-blocks-tooltip-font-size' )
		->not->toContain( '--kntnt-gpx-blocks-tooltip-font-weight' )
		->not->toContain( '--kntnt-gpx-blocks-tooltip-font-style' );

} );

test( 'ignores legacy axisFont* attributes left over from an older save', function (): void {

	elev_setup_normal_path( 74, 24, 'map-legacy-axis-font' );

	$html = Render_Elevation::render(
		[
			'mapId'          => 'auto',
			'axisFontFamily' => 'Comic Sans MS',
			'axisFontSize'   => '16px',
			'axisFontWeight' => 'bold',
			'axisFontStyle'  => 'italic',
		],
		'',
		elev_fake_block( 24 ),
	);

	// The legacy values must not surface anywhere — neither as CSS variables
	// nor as raw strings in the rendered HTML.
	expect( $html )
		->not->toContain( '--kntnt-gpx-blocks-axis-font-family' )
		->not->toContain( '--kntnt-gpx-blocks-axis-font-size' )
		->not->toContain( '--kntnt-gpx-blocks-axis-font-weight' )
		->not->toContain( '--kntnt-gpx-blocks-axis-font-style' )
		->not->toContain( 'Comic Sans MS' )
		->not->toContain( '16px' );

} );

test( 'ignores legacy tooltipFont* attributes left over from an older save', function (): void {

	elev_setup_normal_path( 75, 25, 'map-legacy-tip-font' );

	$html = Render_Elevation::render(
		[
			'mapId'             => 'auto',
			'tooltipFontFamily' => 'Comic Sans MS',
			'tooltipFontSize'   => '20px',
			'tooltipFontWeight' => 'bold',
			'tooltipFontStyle'  => 'italic',
		],
		'',
		elev_fake_block( 25 ),
	);

	expect( $html )
		->not->toContain( '--kntnt-gpx-blocks-tooltip-font-family' )
		->not->toContain( '--kntnt-gpx-blocks-tooltip-font-size' )
		->not->toContain( '--kntnt-gpx-blocks-tooltip-font-weight' )
		->not->toContain( '--kntnt-gpx-blocks-tooltip-font-style' )
		->not->toContain( 'Comic Sans MS' )
		->not->toContain( '20px' );

} );

test( 'block.json declares supports.typography with the expected aspects and defaults', function (): void {

	$json = json_decode(
		(string) file_get_contents( __DIR__ . '/../../src/blocks/elevation/block.json' ),
		true,
	);
	expect( $json )->toBeArray();

	$typography = $json['supports']['typography'] ?? null;
	expect( $typography )->toBeArray();

	// Each user-facing aspect is enabled at the block level so the editor
	// surfaces the standard Typography panel.
	foreach (
		[
			'fontFamily',
			'fontSize',
			'fontWeight',
			'fontStyle',
			'lineHeight',
			'letterSpacing',
			'textTransform',
			'textDecoration',
		] as $aspect
	) {
		expect( $typography[ $aspect ] ?? null )->toBeTrue();
	}

	// The defaultControls trio (Font, Size, Appearance) is what the issue
	// fixed as the visible top-level controls in the panel.
	$defaults = $typography['defaultControls'] ?? null;
	expect( $defaults )->toBeArray();
	expect( $defaults['fontFamily'] ?? null )->toBeTrue();
	expect( $defaults['fontSize'] ?? null )->toBeTrue();
	expect( $defaults['fontAppearance'] ?? null )->toBeTrue();

} );

test( 'block.json no longer declares any axisFont* or tooltipFont* attributes', function (): void {

	$json = json_decode(
		(string) file_get_contents( __DIR__ . '/../../src/blocks/elevation/block.json' ),
		true,
	);
	expect( $json )->toBeArray();

	$attributes = $json['attributes'] ?? [];
	expect( $attributes )->toBeArray();

	foreach (
		[
			'axisFontFamily',
			'axisFontSize',
			'axisFontWeight',
			'axisFontStyle',
			'tooltipFontFamily',
			'tooltipFontSize',
			'tooltipFontWeight',
			'tooltipFontStyle',
		] as $removed
	) {
		expect( $attributes )->not->toHaveKey( $removed );
	}

} );

test( 'block-supports typography in style.typography reaches the wrapper inline style', function (): void {

	// The Render_Elevation class itself does not interpret the
	// `style.typography` slot — that is what core's
	// `get_block_wrapper_attributes()` is for. The test exercises the
	// integration: when `style.typography.fontFamily` is set, the mocked
	// wrapper helper picks it up and emits a corresponding inline
	// `font-family` declaration that the axis-label and cursor-tooltip-text
	// rules inherit.
	elev_setup_normal_path( 76, 26, 'map-block-typography' );

	// Override the default get_block_wrapper_attributes mock with one that
	// emits the typography slot into the inline style — mirroring what core
	// does in production.
	Functions\when( 'get_block_wrapper_attributes' )->alias(
		static function ( array $extras = [] ): string {
			$style_parts = [];
			if ( isset( $extras['style'] ) && '' !== $extras['style'] ) {
				$style_parts[] = (string) $extras['style'];
			}
			// In production core reads $attributes['style']['typography'] and
			// emits inline declarations. The test simulates the result.
			$style_parts[] = 'font-family: var(--wp--preset--font-family--system)';
			$style         = implode( '; ', $style_parts );

			return sprintf(
				'class="wp-block-kntnt-gpx-blocks-elevation kntnt-gpx-blocks-elevation" style="%s"',
				$style,
			);
		},
	);

	$html = Render_Elevation::render(
		[
			'mapId' => 'auto',
			'style' => [
				'typography' => [
					'fontFamily' => 'var:preset|font-family|system',
				],
			],
		],
		'',
		elev_fake_block( 26 ),
	);

	// The wrapper carries the block-level font-family; the axis-label and
	// cursor-tooltip-text rules in style.scss use `inherit`, so the choice
	// flows into both surfaces without any per-context CSS variable.
	expect( $html )->toContain( 'font-family: var(--wp--preset--font-family--system)' );

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
		[
			'mapId' => 'auto',
			'align' => 'wide',
		],
		'',
		elev_fake_block( 100 ),
	);

	expect( $html )->toContain( 'alignwide' );

} );

test( 'wrapper carries alignfull on the normal path when align is "full"', function (): void {

	elev_setup_normal_path( 201, 101, 'map-full' );
	$GLOBALS['kntnt_elev_test_attrs'] = [ 'align' => 'full' ];

	$html = Render_Elevation::render(
		[
			'mapId' => 'auto',
			'align' => 'full',
		],
		'',
		elev_fake_block( 101 ),
	);

	expect( $html )->toContain( 'alignfull' );

} );

test( 'wrapper carries HTML id on the normal path when anchor is set', function (): void {

	elev_setup_normal_path( 202, 102, 'map-anchor' );
	$GLOBALS['kntnt_elev_test_attrs'] = [ 'anchor' => 'profile-section' ];

	$html = Render_Elevation::render(
		[
			'mapId'  => 'auto',
			'anchor' => 'profile-section',
		],
		'',
		elev_fake_block( 102 ),
	);

	expect( $html )->toContain( 'id="profile-section"' );

} );

test( 'wrapper carries the user-supplied additional CSS class on the normal path', function (): void {

	elev_setup_normal_path( 203, 103, 'map-class' );
	$GLOBALS['kntnt_elev_test_attrs'] = [ 'className' => 'is-style-rounded my-extra-class' ];

	$html = Render_Elevation::render(
		[
			'mapId'     => 'auto',
			'className' => 'is-style-rounded my-extra-class',
		],
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
		[
			'mapId' => 'auto',
			'align' => 'wide',
		],
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
		[
			'mapId' => 'auto',
			'align' => 'full',
		],
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
		[
			'mapId'  => 'auto',
			'anchor' => 'no-elevation-here',
		],
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
		[
			'mapId'     => 'auto',
			'className' => 'is-style-rounded my-extra-class',
		],
		'',
		elev_fake_block( 113 ),
	);

	expect( $html )
		->toContain( 'kntnt-gpx-blocks-elevation--empty' )
		->toContain( 'is-style-rounded my-extra-class' );

} );

// ---------------------------------------------------------------------------
// Editor-preview cursor (issue #91)
//
// When Request_Context::is_editor_request() returns true the cursor group is
// server-rendered visible at fraction=0.5 with the midpoint LTTB sample's
// distance/elevation pre-filled into the tooltip. The frontend (non-editor)
// render path keeps style="display:none" so view.ts can reveal the cursor on
// the first pointermove. REST_REQUEST is defined for the first time in this
// file inside the editor-mode tests; PHP constants cannot be undefined, so
// these tests are grouped together at the very end and explicitly stub
// current_user_can per test to keep the implication local.
// ---------------------------------------------------------------------------

test( 'frontend cursor group keeps style="display:none"', function (): void {

	elev_setup_normal_path( 300, 200, 'map-front-cursor' );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 200 ) );

	// Isolate the cursor <g> so the assertion targets its attributes only.
	$matched = preg_match( '#<g class="kntnt-gpx-blocks-elevation-cursor"[^>]*>#', $html, $g_match );
	expect( $matched )->toBe( 1 );

	expect( $g_match[0] )
		->toContain( 'style="display:none"' )
		->not->toContain( 'data-preview' );

} );

test( 'editor preview renders the cursor group visible without display:none', function (): void {

	if ( ! defined( 'REST_REQUEST' ) ) {
		define( 'REST_REQUEST', true );
	}

	elev_setup_normal_path( 301, 201, 'map-edit-cursor' );
	Functions\when( 'current_user_can' )->alias(
		static fn ( string $cap ): bool => 'edit_posts' === $cap
	);

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 201 ) );

	$matched = preg_match( '#<g class="kntnt-gpx-blocks-elevation-cursor"[^>]*>#', $html, $g_match );
	expect( $matched )->toBe( 1 );

	expect( $g_match[0] )
		->not->toContain( 'display:none' )
		->toContain( 'data-preview="1"' );

} );

test( 'editor preview positions the cursor line at the midpoint sample\'s x', function (): void {

	if ( ! defined( 'REST_REQUEST' ) ) {
		define( 'REST_REQUEST', true );
	}

	elev_setup_normal_path( 302, 202, 'map-edit-x' );
	Functions\when( 'current_user_can' )->alias(
		static fn ( string $cap ): bool => 'edit_posts' === $cap
	);

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 202 ) );

	// Issue #135 (wrapper-as-image): the plot rectangle is [0, 1200]
	// horizontally now that PLOT_INSET = 0, so fraction=0.5 lands near the
	// geometric midpoint 600. The synthetic series's distance distribution
	// along the LTTB-downsampled samples is not perfectly linear, so the
	// assertion stays a wide-band check that the x-position lies in the
	// plot interior.
	$matched = preg_match(
		'#<line class="kntnt-gpx-blocks-elevation-cursor-line"[^>]*x1="([0-9.]+)"#',
		$html,
		$line_match,
	);
	expect( $matched )->toBe( 1 );

	$x1 = (float) $line_match[1];
	expect( $x1 )->toBeGreaterThan( 400.0 );
	expect( $x1 )->toBeLessThan( 800.0 );

} );

test( 'editor preview pre-fills tooltip with formatted distance and elevation', function (): void {

	if ( ! defined( 'REST_REQUEST' ) ) {
		define( 'REST_REQUEST', true );
	}

	elev_setup_normal_path( 303, 203, 'map-edit-tooltip' );
	Functions\when( 'current_user_can' )->alias(
		static fn ( string $cap ): bool => 'edit_posts' === $cap
	);

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 203 ) );

	// The synthetic series has elevation rising linearly from 100 m to 200 m;
	// the midpoint should be in the 130-170 m band (LTTB sampling can shift
	// the exact midpoint slightly off 150 m, so the assertion uses a band
	// instead of a hard equality).
	$elev_matched = preg_match(
		'#<tspan class="kntnt-gpx-blocks-elevation-cursor-tooltip-elevation"[^>]*>([0-9]+) m</tspan>#',
		$html,
		$elev_match,
	);
	expect( $elev_matched )->toBe( 1 );
	$elevation = (int) $elev_match[1];
	expect( $elevation )->toBeGreaterThanOrEqual( 130 );
	expect( $elevation )->toBeLessThanOrEqual( 170 );

	// Distance: the synthetic track is ~5.5 km long, so the midpoint is well
	// above the 1000 m kilometre threshold — expect a "X.Y km" label.
	$dist_matched = preg_match(
		'#<tspan class="kntnt-gpx-blocks-elevation-cursor-tooltip-distance"[^>]*>([^<]+)</tspan>#',
		$html,
		$dist_match,
	);
	expect( $dist_matched )->toBe( 1 );
	expect( $dist_match[1] )->toMatch( '/^\d+\.\d km$/' );

} );

test( 'editor preview survives a very short series — single LTTB sample renders without crash', function (): void {

	if ( ! defined( 'REST_REQUEST' ) ) {
		define( 'REST_REQUEST', true );
	}

	// Build a two-point series — the minimum that survives the `count >= 2`
	// guard in Render_Elevation::render(). The midpoint resolves to a point
	// halfway between the two samples and the cursor must render without
	// dividing by zero or producing NaN coordinates.
	$coords = [
		[ 18.0, 59.0, 100.0 ],
		[ 18.01, 59.01, 110.0 ],
	];
	$stats = [
		'distance'      => 1200.0,
		'min_elevation' => 100.0,
		'max_elevation' => 110.0,
		'ascent'        => 10.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( 304, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 204 );
	elev_stub_parse_blocks( [ elev_map_block( 304, 'map-edit-short' ) ] );
	elev_stub_attached_file( 304, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 204 );
	Functions\when( 'current_user_can' )->alias(
		static fn ( string $cap ): bool => 'edit_posts' === $cap
	);

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 204 ) );

	// The cursor group renders visible with finite coordinates and a tooltip
	// matching the midpoint of a 100 m → 110 m series (expect ~105 m).
	expect( $html )
		->toContain( 'data-preview="1"' )
		->toContain( '<svg' )
		->toMatch( '#<tspan class="kntnt-gpx-blocks-elevation-cursor-tooltip-elevation"[^>]*>105 m</tspan>#' );

	// No NaN slipping through into the rendered coordinates.
	expect( $html )->not->toContain( 'NaN' );

} );

test( 'editor preview is disabled for non-editor users even with REST_REQUEST set', function (): void {

	if ( ! defined( 'REST_REQUEST' ) ) {
		define( 'REST_REQUEST', true );
	}

	elev_setup_normal_path( 305, 205, 'map-rest-anon' );

	// current_user_can stays at the beforeEach default of false — anonymous
	// REST callers must not trigger the editor-preview path.
	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 205 ) );

	$matched = preg_match( '#<g class="kntnt-gpx-blocks-elevation-cursor"[^>]*>#', $html, $g_match );
	expect( $matched )->toBe( 1 );

	expect( $g_match[0] )
		->toContain( 'style="display:none"' )
		->not->toContain( 'data-preview' );

} );

// ---------------------------------------------------------------------------
// Issue #93 — SVG renderer refactor for responsive sizing.
//
// Bundles four bugs sharing one root cause:
//
// - #11 axis font-size has no effect when Appearance is left at Standard.
// - #12 large fonts clip beyond MARGIN_LEFT / MARGIN_BOTTOM.
// - #20 polyline stretching with non-default aspect ratios.
// - #21 increasing min-height leaves empty space below the chart.
//
// Each bug below has a regression test asserting the post-fix behaviour.
// ---------------------------------------------------------------------------

test( 'svg carries preserveAspectRatio="none" under the wrapper-as-image layout (issue #135)', function (): void {

	elev_setup_normal_path( 400, 300, 'map-aspect' );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 300 ) );

	$matched = preg_match( '#<svg\b[^>]*>#', $html, $svg_match );
	expect( $matched )->toBe( 1 );
	$svg_open = $svg_match[0];

	// Issue #135 (wrapper-as-image): the SVG carries `preserveAspectRatio="none"`
	// so the polyline stretches non-uniformly to fill the wrapper's plot
	// rectangle exactly. `vector-effect="non-scaling-stroke"` on the
	// polyline and axis lines keeps the stroke widths visually consistent
	// under that stretch — covered in its own assertion below.
	expect( $svg_open )->toContain( 'preserveAspectRatio="none"' );

} );

test( 'svg viewBox dimensions reflect the editor-set aspect ratio (issue #20)', function (): void {

	elev_setup_normal_path( 401, 301, 'map-ratio' );

	// A non-default aspect ratio passed through core's `dimensions` block
	// supports — Gutenberg serialises it under `style.dimensions.aspectRatio`
	// and the wrapper carries it as inline `aspect-ratio: 16/9;`. The SVG's
	// viewBox dimensions must reflect this so that uniform scaling
	// (preserveAspectRatio="xMidYMid meet") fills the wrapper exactly.
	$html = Render_Elevation::render(
		[
			'mapId' => 'auto',
			'style' => [ 'dimensions' => [ 'aspectRatio' => '16/9' ] ],
		],
		'',
		elev_fake_block( 301 ),
	);

	$matched = preg_match( '#<svg\b[^>]*viewBox="0 0 ([0-9.]+) ([0-9.]+)"#', $html, $vb_match );
	expect( $matched )->toBe( 1 );
	$vb_width  = (float) $vb_match[1];
	$vb_height = (float) $vb_match[2];

	expect( $vb_width )->toBeGreaterThan( 0.0 );
	expect( $vb_height )->toBeGreaterThan( 0.0 );

	// The viewBox aspect-ratio must match the requested 16/9 within a small
	// rounding tolerance. A 4/1 viewBox (the unfixed default) would fail
	// this check by ratio = 4.0 instead of ~1.78.
	$vb_ratio       = $vb_width / $vb_height;
	$expected_ratio = 16.0 / 9.0;
	expect( abs( $vb_ratio - $expected_ratio ) )->toBeLessThan( 0.05 );

} );

test( 'axis labels render in HTML overlay, not as SVG <text> (issue #11)', function (): void {

	elev_setup_normal_path( 402, 302, 'map-overlay' );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 302 ) );

	// Pre-fix: every axis label is an SVG `<text class="kntnt-gpx-blocks-elevation-axis-label">` —
	// living inside the stretched SVG coordinate space, so the editor's
	// chosen axis font-size has no visible effect when Appearance is
	// Standard. Post-fix: labels live in HTML overlay containers next to
	// the SVG so they honour real CSS font-size and reserve layout space.
	expect( $html )
		->toContain( 'kntnt-gpx-blocks-elevation-y-labels' )
		->toContain( 'kntnt-gpx-blocks-elevation-x-labels' )
		->not->toMatch( '#<text class="kntnt-gpx-blocks-elevation-axis-label"#' );

} );

test( 'plot label overlays carry the axis-label class for CSS sizing (issue #12)', function (): void {

	elev_setup_normal_path( 403, 303, 'map-margins' );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 303 ) );

	// Pre-fix: MARGIN_LEFT / MARGIN_BOTTOM are baked-in viewBox constants;
	// larger label fonts clip because there is no compensating reservation.
	// Post-fix: the wrapper reserves label space outside the SVG plot area
	// via padding driven by a CSS variable sized in em (or another
	// font-relative unit), so a larger axis font-size grows the reserved
	// space and the polyline draw area shrinks to fit. The renderer's
	// contract is to emit the overlay containers labelled with
	// `kntnt-gpx-blocks-elevation-axis-label` so the SCSS rule applies
	// font-family / font-size from the CSS variables.
	expect( $html )
		->toContain( 'kntnt-gpx-blocks-elevation-y-labels' )
		->toContain( 'kntnt-gpx-blocks-elevation-x-labels' )
		->toContain( 'kntnt-gpx-blocks-elevation-axis-label' );

} );

test( 'svg uses preserveAspectRatio="none" so it fills the wrapper without letterboxing (issue #135)', function (): void {

	elev_setup_normal_path( 404, 304, 'map-fill' );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 304 ) );

	// Under the wrapper-as-image layout (issue #135) the SVG fills the
	// wrapper's plot rectangle exactly via `position: absolute` + the
	// per-side padding values, with `preserveAspectRatio="none"` letting
	// the polyline stretch non-uniformly into whatever rendered aspect
	// ratio the wrapper resolves to. No more letterboxing on tall
	// wrappers.
	$matched = preg_match( '#<svg\b[^>]*>#', $html, $svg_match );
	expect( $matched )->toBe( 1 );

	expect( $svg_match[0] )->toContain( 'preserveAspectRatio="none"' );

} );

// ---------------------------------------------------------------------------
// Issue #109 — the plugin's inline style must terminate with `;` so core's
// appended border-/shadow-/dimensions-supports declarations never run into
// the plugin's last declaration. WordPress concatenates the
// caller-supplied style and its own declarations with a *space*, not a
// semicolon, so the boundary character has to come from the plugin side.
// Mirrors the Map block's regression test for the same bug: with per-corner
// radii (which Border_Radius_Normalizer correctly leaves as four separate
// declarations), core appends them and the first one would otherwise be
// folded into the value of the plugin's last custom property.
// ---------------------------------------------------------------------------

test( 'inline style is terminated so core-appended per-corner border-radius survives (issue #109)', function (): void {

	$coords = elev_synthetic_coords_3d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( 500, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 500 );
	elev_stub_parse_blocks( [ elev_map_block( 500, 'map-issue-109' ) ] );
	elev_stub_attached_file( 500, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 500 );

	// Simulate core's per-corner border-radius emission. With non-equal
	// per-corner values, Border_Radius_Normalizer correctly leaves the
	// four declarations as-is, and core's style engine appends them onto
	// the wrapper's `style` attribute with a leading space.
	$GLOBALS['kntnt_elev_test_core_style'] =
		'border-top-left-radius:3rem;'
		. 'border-top-right-radius:var(--wp--preset--border-radius--md);'
		. 'border-bottom-left-radius:0.75rem;'
		. 'border-bottom-right-radius:3rem;';

	$html = Render_Elevation::render(
		[
			'mapId'        => 'auto',
			'axisColor'    => '#111111',
			'lineColor'    => '#0073aa',
			'tooltipColor' => '#222222',
		],
		'',
		elev_fake_block( 500 ),
	);

	// Extract the wrapper's style attribute. The block emits the wrapper
	// element as the first element in the output; restricting the regex
	// to the first `<div ...>` keeps it from matching inline-style
	// attributes inside SVG children (e.g. the cursor group's
	// `style="display:none"`).
	$matched = preg_match( '#<div\b[^>]*\sstyle="([^"]*)"#', $html, $style_match );
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
	// border declaration into its value. tooltipColor is the last item
	// in the renderer's `$style_parts` list and therefore the one whose
	// trailing value would otherwise glue to the first appended border
	// declaration.
	expect( $declarations )->toHaveKey( '--kntnt-gpx-blocks-tooltip-color' );
	expect( $declarations['--kntnt-gpx-blocks-tooltip-color'] )->toBe( '#222222' );

} );

// ---------------------------------------------------------------------------
// Issue #117 — the plugin-defined default `min-height` is normalised at
// the attribute source through the `Dimensions_Defaults` filter, not
// per-consumer inline injection inside `Render_Elevation::render()`. The
// tests here invoke the filter on a parsed block, hand its output to
// render, and assert that the wrapper inline style carries the value
// through core's `get_block_wrapper_attributes()` pipeline (simulated
// in the test harness) instead of plugin-side string concatenation.
// ---------------------------------------------------------------------------

/**
 * Simulates core's dimensions block-supports CSS emission from a
 * `style.dimensions` slot for the Elevation tests' harness.
 *
 * @param array<string,mixed> $attrs Parsed-block attrs after the filter.
 */
function elev_simulate_dimensions_core_style( array $attrs ): void {
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
	$GLOBALS['kntnt_elev_test_core_style'] = count( $parts ) > 0
		? implode( ';', $parts ) . ';'
		: '';
}

test( 'B1 (Elevation): wrapper-as-image — filter does not inject any min-height when both fields are blank (issue #135)', function (): void {

	elev_setup_normal_path( 700, 600, 'map-default-min-height' );

	$filter = new \Kntnt\Gpx_Blocks\Rendering\Dimensions_Defaults();
	$parsed = $filter->filter(
		[
			'blockName'    => 'kntnt-gpx-blocks/elevation',
			'attrs'        => [ 'mapId' => 'auto' ],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		],
	);
	elev_simulate_dimensions_core_style( $parsed['attrs'] );

	$html = Render_Elevation::render( $parsed['attrs'], '', elev_fake_block( 600 ) );

	$matched = preg_match( '#<div\b[^>]*\sstyle="([^"]*)"#', $html, $style_match );
	expect( $matched )->toBe( 1 );

	// Wrapper-as-image (issue #135): the wrapper carries no `min-height`
	// when both Dimensions fields are blank — sizing is fully driven by
	// `aspect-ratio` from the SCSS baseline plus the data-driven
	// typographic padding values.
	expect( $style_match[1] )->not->toContain( 'min-height' );

} );

test( 'B2 (Elevation): Render_Elevation does not concatenate min-height into its own style_parts (issue #135)', function (): void {

	// Under wrapper-as-image (issue #135) Render_Elevation must not emit
	// any min-height of its own — and there is no per-block default left
	// in `Dimensions_Defaults::DEFAULTS` for Elevation, so the filter
	// does not inject one either. The wrapper inline style should not
	// carry a `min-height` declaration in any branch.
	elev_setup_normal_path( 710, 610, 'map-plain' );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 610 ) );

	expect( $html )->not->toContain( 'min-height: 15vh' );
	expect( $html )->not->toContain( 'min-height:15vh' );

} );

test( 'B3 (Elevation): with aspectRatio set, the filter does not inject and no plugin min-height appears', function (): void {

	elev_setup_normal_path( 720, 620, 'map-aspect-only' );

	$filter = new \Kntnt\Gpx_Blocks\Rendering\Dimensions_Defaults();
	$parsed = $filter->filter(
		[
			'blockName'    => 'kntnt-gpx-blocks/elevation',
			'attrs'        => [
				'mapId' => 'auto',
				'style' => [ 'dimensions' => [ 'aspectRatio' => '16/9' ] ],
			],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		],
	);
	elev_simulate_dimensions_core_style( $parsed['attrs'] );

	$html = Render_Elevation::render( $parsed['attrs'], '', elev_fake_block( 620 ) );

	$matched = preg_match( '#<div\b[^>]*\sstyle="([^"]*)"#', $html, $style_match );
	expect( $matched )->toBe( 1 );
	expect( $style_match[1] )->toContain( 'aspect-ratio:16/9' );
	expect( $style_match[1] )->not->toContain( 'min-height' );

} );

test( 'B-explicit (Elevation): user-set min-height passes through unchanged and the plugin does not double-emit', function (): void {

	elev_setup_normal_path( 730, 630, 'map-explicit' );

	$filter = new \Kntnt\Gpx_Blocks\Rendering\Dimensions_Defaults();
	$parsed = $filter->filter(
		[
			'blockName'    => 'kntnt-gpx-blocks/elevation',
			'attrs'        => [
				'mapId' => 'auto',
				'style' => [ 'dimensions' => [ 'minHeight' => '500px' ] ],
			],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		],
	);
	elev_simulate_dimensions_core_style( $parsed['attrs'] );

	$html = Render_Elevation::render( $parsed['attrs'], '', elev_fake_block( 630 ) );

	$matched = preg_match( '#<div\b[^>]*\sstyle="([^"]*)"#', $html, $style_match );
	expect( $matched )->toBe( 1 );
	expect( $style_match[1] )->toContain( 'min-height:500px' );
	expect( $style_match[1] )->not->toContain( '15vh' );

} );

// ---------------------------------------------------------------------------
// Issue #135 — wrapper-as-image layout.
//
// The wrapper carries three data-driven CSS custom properties that the
// SCSS uses to position both the SVG plot rectangle and the two HTML
// axis-label overlay containers. `padding-x` derives from the widest
// formatted y-tick label; `padding-top` is `0.5lh` (half the y-label's
// resolved line-height); `padding-bottom` is `calc(0.5em + 0.2em)`
// (gap + descender approximation). The SVG fills the wrapper's content
// box via `preserveAspectRatio="none"` and `vector-effect="non-scaling-stroke"`
// on the polyline + axis lines + cursor line keeps stroke widths
// consistent under the non-uniform stretch.
// ---------------------------------------------------------------------------

test( 'wrapper emits the three data-driven padding CSS variables (issue #135)', function (): void {

	elev_setup_normal_path( 800, 700, 'map-pad-vars' );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 700 ) );

	$matched = preg_match( '#<div\b[^>]*\sstyle="([^"]*)"#', $html, $style_match );
	expect( $matched )->toBe( 1 );
	$style_attr = $style_match[1];

	// padding-left / padding-right share one variable (--elev-pad-x) so the
	// chart centres visually inside the wrapper; padding-top is `0.5lh` so
	// the topmost y-label tangents the wrapper's top edge; padding-bottom
	// is `calc(0.5em + 0.2em)` so the x-label descenders tangent the
	// wrapper's bottom edge.
	expect( $style_attr )->toContain( '--kntnt-gpx-blocks-elev-pad-x:' );
	expect( $style_attr )->toContain( 'ch + 0.5em' );
	expect( $style_attr )->toContain( '--kntnt-gpx-blocks-elev-pad-top: 0.5lh' );
	expect( $style_attr )->toContain( '--kntnt-gpx-blocks-elev-pad-bottom: calc(0.5em + 0.2em)' );

} );

test( 'padding-x width matches the widest formatted y-tick label (issue #135)', function (): void {

	// Synthetic elevations 100..200 m with the test's pass-through
	// `number_format_i18n` produce y-tick labels like "208 m", "182 m",
	// "156 m", "131 m", "105 m" — widest is 5 characters. The widest count
	// drives `calc(<chars>ch + 0.5em)`.
	elev_setup_normal_path( 810, 710, 'map-pad-width' );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 710 ) );

	$matched = preg_match(
		'#--kntnt-gpx-blocks-elev-pad-x:\s*calc\((\d+)ch\s*\+\s*0\.5em\)#',
		$html,
		$pad_match,
	);
	expect( $matched )->toBe( 1 );

	// The widest label "208 m" is 5 chars. Be lenient by one in case
	// rounding/padding shifts the labels — the assertion's purpose is to
	// confirm the value is data-driven (not a constant 1 or some fallback)
	// and falls in the right ballpark.
	$widest = (int) $pad_match[1];
	expect( $widest )->toBeGreaterThanOrEqual( 5 );
	expect( $widest )->toBeLessThanOrEqual( 7 );

} );

test( 'widest-y-label calculation handles negative-to-large span (issue #135)', function (): void {

	// A track whose elevation spans negative numbers to large positive
	// numbers — exercises the widest-label measurement under the
	// `Value_Formatter`-style output that the production code uses.
	// padded_y_bounds rounds outward with floor/ceil, so the rendered
	// labels are integers like "-13" through "1000". Widest label is
	// "1000 m" (6 chars), which sets the lower bound for the assertion.
	$coords = [];
	$ele_min = -12.3;
	$ele_max = 999.5;
	for ( $i = 0; $i < 100; $i++ ) {
		$ratio = $i / 99.0;
		$lon   = 18.0 + 0.05 * $ratio;
		$lat   = 59.0 + 0.05 * $ratio;
		$ele   = $ele_min + ( $ele_max - $ele_min ) * $ratio;
		$coords[] = [ $lon, $lat, $ele ];
	}
	$stats = [
		'distance'      => 5500.0,
		'min_elevation' => $ele_min,
		'max_elevation' => $ele_max,
		'ascent'        => 1011.0,
		'descent'       => 0.0,
	];

	$store = elev_seeded_store( 820, $coords, $stats );
	elev_bind_meta( $store );
	elev_stub_get_post( 720 );
	elev_stub_parse_blocks( [ elev_map_block( 820, 'map-widest' ) ] );
	elev_stub_attached_file( 820, elev_fixture_path( 'happy-path.gpx' ) );
	Functions\when( 'get_the_ID' )->justReturn( 720 );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 720 ) );

	$matched = preg_match(
		'#--kntnt-gpx-blocks-elev-pad-x:\s*calc\((\d+)ch\s*\+\s*0\.5em\)#',
		$html,
		$pad_match,
	);
	expect( $matched )->toBe( 1 );

	// "1000 m" → 6 chars; padded_y_bounds may snap slightly outward, so
	// allow a small upper range. Lower bound stays at 6 to confirm the
	// 4-digit label is being measured correctly.
	$widest = (int) $pad_match[1];
	expect( $widest )->toBeGreaterThanOrEqual( 6 );
	expect( $widest )->toBeLessThanOrEqual( 8 );

} );

test( 'polyline carries vector-effect="non-scaling-stroke" (issue #135)', function (): void {

	elev_setup_normal_path( 830, 730, 'map-vec-effect-poly' );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 730 ) );

	// Stroke widths must stay visually consistent under the wrapper-as-
	// image layout's non-uniform stretch — the attribute on the polyline
	// is the guarantee.
	expect( $html )->toMatch( '#<polyline[^>]*vector-effect="non-scaling-stroke"#' );

} );

test( 'axis frame lines carry vector-effect="non-scaling-stroke" (issue #135)', function (): void {

	elev_setup_normal_path( 831, 731, 'map-vec-effect-axis' );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 731 ) );

	// Both axis frame lines (bottom + left) must carry the attribute.
	$matched = preg_match_all(
		'#<line class="kntnt-gpx-blocks-elevation-axis"[^>]*vector-effect="non-scaling-stroke"#',
		$html,
	);
	expect( $matched )->toBe( 2 );

} );

test( 'cursor line carries vector-effect="non-scaling-stroke" (issue #135)', function (): void {

	elev_setup_normal_path( 832, 732, 'map-vec-effect-cursor' );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 732 ) );

	expect( $html )->toMatch( '#<line class="kntnt-gpx-blocks-elevation-cursor-line"[^>]*vector-effect="non-scaling-stroke"#' );

} );

test( 'plot rectangle spans the full viewBox under wrapper-as-image (issue #135)', function (): void {

	elev_setup_normal_path( 840, 740, 'map-plot-spans' );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 740 ) );

	// PLOT_INSET = 0 → plot rectangle = [0, viewbox_w] × [0, viewbox_h].
	// data-plot-left == 0 and data-plot-top == 0; data-plot-right and
	// data-plot-bottom match the viewBox dimensions byte-for-byte.
	$matched = preg_match( '#<svg\b[^>]*viewBox="0 0 ([0-9.]+) ([0-9.]+)"#', $html, $vb_match );
	expect( $matched )->toBe( 1 );
	$vb_w = (float) $vb_match[1];
	$vb_h = (float) $vb_match[2];

	$matched = preg_match(
		'#<g class="kntnt-gpx-blocks-elevation-cursor"[^>]*data-plot-left="([0-9.]+)"[^>]*data-plot-right="([0-9.]+)"[^>]*data-plot-top="([0-9.]+)"[^>]*data-plot-bottom="([0-9.]+)"#',
		$html,
		$plot_match,
	);
	expect( $matched )->toBe( 1 );

	expect( (float) $plot_match[1] )->toBe( 0.0 );
	expect( (float) $plot_match[2] )->toBe( $vb_w );
	expect( (float) $plot_match[3] )->toBe( 0.0 );
	expect( (float) $plot_match[4] )->toBe( $vb_h );

} );

test( 'wrapper does not carry the obsolete axis-label CSS variables (issue #135)', function (): void {

	elev_setup_normal_path( 850, 750, 'map-no-old-vars' );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', elev_fake_block( 750 ) );

	// The pre-fix variables that controlled the layout via the SVG's
	// pinning insets have been removed in favour of the three
	// `--kntnt-gpx-blocks-elev-pad-*` variables.
	expect( $html )
		->not->toContain( '--kntnt-gpx-blocks-axis-label-y-width' )
		->not->toContain( '--kntnt-gpx-blocks-axis-label-x-height' );

} );
