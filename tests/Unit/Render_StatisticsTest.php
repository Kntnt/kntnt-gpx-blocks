<?php
/**
 * Tests for Rendering\Render_Statistics.
 *
 * Brain Monkey stubs all WordPress functions (get_the_ID, get_post,
 * parse_blocks, get_post_meta, get_attached_file, apply_filters,
 * current_user_can, esc_html, esc_html__, __, wp_json_encode) so the render
 * class can run without a WordPress install.
 *
 * The test drives the full static call chain:
 *   Render_Statistics::render()
 *     → Resolve_Map_Id::resolve()
 *     → Attachment_Cache::get()
 *     → Value_Formatter::format_*()
 *
 * Rather than mocking collaborators at the class level (which would require
 * seam refactoring), the tests supply fully consistent Brain Monkey stubs so
 * each code path exercises real collaborator logic.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Cache\Cache_Version;
use Kntnt\Gpx_Blocks\Rendering\Render_Statistics;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Builds a fake WP_Block-like object that Render_Statistics reads from.
 *
 * WP_Block cannot be instantiated in unit tests (its constructor requires
 * a full block-type registry). Because Render_Statistics only reads
 * $block->context we use anonymous class — it extends nothing but the
 * PHP type system is satisfied via an explicit cast in the call sites.
 *
 * @param int $post_id The post ID to expose via context['postId'].
 *
 * @return object An object with a public $context property shaped like \WP_Block.
 */
function fake_block( int $post_id ): object {
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
function bind_meta_render( array &$store ): void {

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
 * @param int    $post_id      Post ID to match.
 * @param string $post_content Post content string (unused by Statistics; block
 *                             tree is provided by parse_blocks stub).
 */
function stub_get_post_render( int $post_id, string $post_content = '' ): void {
	$post               = new stdClass();
	$post->ID           = $post_id;
	$post->post_content = $post_content;

	Functions\when( 'get_post' )->alias(
		static fn ( int $id ): ?object => $id === $post_id ? $post : null
	);
}

/**
 * Stubs parse_blocks() to return a fixed block array.
 *
 * @param array<int, array<string, mixed>> $blocks Block tree to return.
 */
function stub_parse_blocks_render( array $blocks ): void {
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
function stat_map_block( int $attachment_id, string $map_id = 'map-abc' ): array {
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
function stub_format_render(): void {

	Functions\when( 'apply_filters' )->alias(
		static function ( string $filter, mixed $fallback ): mixed {
			return $fallback;
		}
	);

	Functions\when( 'number_format_i18n' )->alias(
		static fn ( float|int $n, int $d ): string => number_format( (float) $n, $d, '.', '' )
	);

}

/**
 * Stubs esc_html() and __() for predictable, readable output in assertions.
 */
function stub_escape_render(): void {
	Functions\when( 'esc_html' )->returnArg( 1 );
	Functions\when( '__' )->returnArg( 1 );
}

/**
 * Stubs get_attached_file() to return a valid path (fixture file).
 *
 * Attachment_Cache::get() needs an on-disk file when it checks the hash. We
 * point it at a fixture that is guaranteed to exist. The actual content does
 * not matter here because the meta store is pre-seeded with a hash that
 * matches the file.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $path          Absolute path to the file.
 */
function stub_attached_file_render( int $attachment_id, string $path ): void {
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
function stat_fixture_path( string $name ): string {
	return __DIR__ . '/fixtures/gpx/' . $name;
}

/**
 * Builds an in-memory meta store pre-seeded with a current-version cache entry
 * for a given attachment ID, using the supplied statistics and a real MD5
 * computed from the happy-path GPX fixture.
 *
 * @param int                      $attachment_id Attachment ID.
 * @param array<string,float|null> $statistics    Statistics to embed in the cache.
 * @param string                   $fixture       Fixture filename (default: happy-path.gpx).
 *
 * @return array<int, array<string, mixed>>
 */
function seeded_meta_store( int $attachment_id, array $statistics, string $fixture = 'happy-path.gpx' ): array {

	$path = stat_fixture_path( $fixture );
	$hash = md5_file( $path );

	$geojson = [
		'type'     => 'FeatureCollection',
		'features' => [],
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

// ---------------------------------------------------------------------------
// Full stats (5 rows)
// ---------------------------------------------------------------------------

test( 'renders five rows when track has all elevation data', function (): void {

	$stats = [
		'distance'      => 12345.0,
		'min_elevation' => 100.0,
		'max_elevation' => 500.0,
		'ascent'        => 450.0,
		'descent'       => 430.0,
	];

	$store = seeded_meta_store( 42, $stats );
	bind_meta_render( $store );
	stub_get_post_render( 1 );
	stub_parse_blocks_render( [ stat_map_block( 42, 'map-abc' ) ] );
	stub_attached_file_render( 42, stat_fixture_path( 'happy-path.gpx' ) );
	stub_format_render();
	stub_escape_render();
	Functions\when( 'get_the_ID' )->justReturn( 1 );
	Functions\when( 'wp_json_encode' )->alias( static fn ( mixed $v ): string|false => json_encode( $v ) );

	$html = Render_Statistics::render( [ 'mapId' => 'auto' ], '', fake_block( 1 ) );

	expect( $html )
		->toContain( '<dt>Total length</dt>' )
		->toContain( '<dt>Lowest elevation</dt>' )
		->toContain( '<dt>Highest elevation</dt>' )
		->toContain( '<dt>Total ascent</dt>' )
		->toContain( '<dt>Total descent</dt>' )
		->toContain( '<dd>12.3 km</dd>' )
		->toContain( '<dd>100 m</dd>' )
		->toContain( '<dd>500 m</dd>' )
		->toContain( '<dd>450 m</dd>' )
		->toContain( '<dd>430 m</dd>' );

} );

// ---------------------------------------------------------------------------
// No elevation data (only total length row)
// ---------------------------------------------------------------------------

test( 'renders only total-length row when track has no elevation data', function (): void {

	$stats = [
		'distance'      => 500.0,
		'min_elevation' => null,
		'max_elevation' => null,
		'ascent'        => null,
		'descent'       => null,
	];

	$store = seeded_meta_store( 43, $stats );
	bind_meta_render( $store );
	stub_get_post_render( 2 );
	stub_parse_blocks_render( [ stat_map_block( 43, 'map-def' ) ] );
	stub_attached_file_render( 43, stat_fixture_path( 'happy-path.gpx' ) );
	stub_format_render();
	stub_escape_render();
	Functions\when( 'get_the_ID' )->justReturn( 2 );
	Functions\when( 'wp_json_encode' )->alias( static fn ( mixed $v ): string|false => json_encode( $v ) );

	$html = Render_Statistics::render( [ 'mapId' => 'auto' ], '', fake_block( 2 ) );

	expect( $html )
		->toContain( '<dt>Total length</dt>' )
		->not->toContain( '<dt>Lowest elevation</dt>' )
		->not->toContain( '<dt>Highest elevation</dt>' )
		->not->toContain( '<dt>Total ascent</dt>' )
		->not->toContain( '<dt>Total descent</dt>' );

} );

// ---------------------------------------------------------------------------
// Resolve_Map_Id error: no map — editor user sees error notice
// ---------------------------------------------------------------------------

test( 'returns error notice for editor when no map block is present', function (): void {

	stub_get_post_render( 3 );
	stub_parse_blocks_render( [] );
	stub_format_render();
	stub_escape_render();
	Functions\when( 'get_the_ID' )->justReturn( 3 );
	Functions\when( 'current_user_can' )->justReturn( true );

	$html = Render_Statistics::render( [ 'mapId' => 'auto' ], '', fake_block( 3 ) );

	expect( $html )
		->toContain( 'kntnt-gpx-blocks-error' )
		->toContain( 'no-map' );

} );

// ---------------------------------------------------------------------------
// Resolve_Map_Id error: no map — visitor sees empty string
// ---------------------------------------------------------------------------

test( 'returns empty string for visitor when no map block is present', function (): void {

	stub_get_post_render( 4 );
	stub_parse_blocks_render( [] );
	stub_format_render();
	stub_escape_render();
	Functions\when( 'get_the_ID' )->justReturn( 4 );
	Functions\when( 'current_user_can' )->justReturn( false );

	$html = Render_Statistics::render( [ 'mapId' => 'auto' ], '', fake_block( 4 ) );

	expect( $html )->toBe( '' );

} );

// ---------------------------------------------------------------------------
// Resolve_Map_Id error: multiple maps — editor sees error notice
// ---------------------------------------------------------------------------

test( 'returns multiple-maps error notice for editor when page has two maps', function (): void {

	stub_get_post_render( 5 );
	stub_parse_blocks_render( [
		stat_map_block( 10, 'map-aaa' ),
		stat_map_block( 11, 'map-bbb' ),
	] );
	stub_format_render();
	stub_escape_render();
	Functions\when( 'get_the_ID' )->justReturn( 5 );
	Functions\when( 'current_user_can' )->justReturn( true );

	$html = Render_Statistics::render( [ 'mapId' => 'auto' ], '', fake_block( 5 ) );

	expect( $html )
		->toContain( 'kntnt-gpx-blocks-error' )
		->toContain( 'multiple-maps' );

} );

// ---------------------------------------------------------------------------
// Attachment_Cache error surfaces correctly
// ---------------------------------------------------------------------------

test( 'returns cache error notice for editor when attachment has an error', function (): void {

	// Pre-seed with an error meta so Attachment_Cache::get() returns Render_Error.
	$store = [
		99 => [ '_kntnt_gpx_blocks_error' => 'too-large' ],
	];
	bind_meta_render( $store );
	stub_get_post_render( 6 );
	stub_parse_blocks_render( [ stat_map_block( 99, 'map-err' ) ] );
	stub_attached_file_render( 99, stat_fixture_path( 'happy-path.gpx' ) );
	stub_format_render();
	stub_escape_render();
	Functions\when( 'get_the_ID' )->justReturn( 6 );
	Functions\when( 'current_user_can' )->justReturn( true );

	$html = Render_Statistics::render( [ 'mapId' => 'auto' ], '', fake_block( 6 ) );

	expect( $html )
		->toContain( 'kntnt-gpx-blocks-error' )
		->toContain( 'too-large' );

} );
