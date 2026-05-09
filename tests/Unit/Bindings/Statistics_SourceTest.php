<?php
/**
 * Tests for Bindings\Statistics_Source.
 *
 * Strategy: drive the real Statistics_Source with real Resolve_Map_Id,
 * Attachment_Cache, and Value_Formatter collaborators. The WordPress I/O
 * boundary is replaced by Brain Monkey stubs (parse_blocks, get_post,
 * get_post_meta, get_attached_file, apply_filters, number_format_i18n).
 *
 * Mockery cannot mock the collaborators directly because they are marked
 * `final`; the project's convention is to stub at the WordPress-function
 * layer instead. Memoization is verified by counting calls into that
 * layer — `Functions\expect( 'parse_blocks' )->once()`.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Bindings\Statistics_Source;
use Kntnt\Gpx_Blocks\Cache\Cache_Version;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Builds a fake \WP_Block-like object exposing $context['postId'].
 *
 * @param int $post_id Post ID to expose via context['postId'].
 *
 * @return object
 */
function bindings_fake_block( int $post_id ): object {
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
 * Wires Brain Monkey meta stubs against an in-memory store.
 *
 * @param array<int, array<string, mixed>> $store Meta store keyed by attachment ID.
 */
function bindings_bind_meta( array &$store ): void {

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
 * Returns the absolute path to a fixture file used as a stand-in source GPX.
 *
 * @return string
 */
function bindings_fixture_path(): string {
	return __DIR__ . '/../fixtures/gpx/happy-path.gpx';
}

/**
 * Builds an in-memory meta store pre-seeded with a current-version cache entry.
 *
 * @param int                      $attachment_id Attachment ID.
 * @param array<string,float|null> $statistics    Statistics to embed in the cache.
 *
 * @return array<int, array<string, mixed>>
 */
function bindings_seeded_store( int $attachment_id, array $statistics ): array {

	$path = bindings_fixture_path();
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

/**
 * Builds a minimal parsed-block array entry for a GPX Map block.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $map_id        mapId attribute.
 *
 * @return array<string, mixed>
 */
function bindings_map_block( int $attachment_id, string $map_id = 'map-abc' ): array {
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
 * Stubs the get_post() function to return a post for the given ID.
 *
 * @param int $post_id Post ID to match.
 */
function bindings_stub_get_post( int $post_id ): void {
	$post = new stdClass();
	$post->ID = $post_id;
	$post->post_content = '';

	Functions\when( 'get_post' )->alias(
		static fn ( int $id ): ?object => $id === $post_id ? $post : null
	);
}

/**
 * Standard "all five keys" statistics array.
 *
 * @return array<string, float>
 */
function bindings_full_stats(): array {
	return [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];
}

/**
 * Statistics with no elevation data (null on the four elevation keys).
 *
 * @return array<string, float|null>
 */
function bindings_no_elev_stats(): array {
	return [
		'distance'      => 500.0,
		'min_elevation' => null,
		'max_elevation' => null,
		'ascent'        => null,
		'descent'       => null,
	];
}

beforeEach( function (): void {

	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'esc_html' )->returnArg( 1 );
	Functions\when( 'apply_filters' )->alias(
		static function ( string $filter, mixed $fallback ): mixed {
			return $fallback;
		}
	);
	Functions\when( 'number_format_i18n' )->alias(
		static fn ( float|int $n, int $d = 0 ): string => number_format( (float) $n, $d, '.', '' )
	);
	Functions\when( 'wp_json_encode' )->alias(
		static fn ( mixed $v ): string|false => json_encode( $v )
	);

} );

// ---------------------------------------------------------------------------
// Happy path: auto-resolve, all five keys
// ---------------------------------------------------------------------------

test( 'returns formatted distance for the distance key', function (): void {

	$store = bindings_seeded_store( 42, bindings_full_stats() );
	bindings_bind_meta( $store );
	bindings_stub_get_post( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [ bindings_map_block( 42 ) ] );
	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => $id === 42 ? bindings_fixture_path() : false
	);

	$source = new Statistics_Source();
	$value = $source->get_value( [ 'key' => 'distance' ], bindings_fake_block( 1 ), 'content' );

	expect( $value )->toBe( '5.5 km' );

} );

test( 'returns formatted elevation for the four elevation keys', function (): void {

	$store = bindings_seeded_store( 42, bindings_full_stats() );
	bindings_bind_meta( $store );
	bindings_stub_get_post( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [ bindings_map_block( 42 ) ] );
	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => $id === 42 ? bindings_fixture_path() : false
	);

	$source = new Statistics_Source();
	$block  = bindings_fake_block( 1 );

	expect( $source->get_value( [ 'key' => 'min_elevation' ], $block, 'content' ) )->toBe( '100 m' );
	expect( $source->get_value( [ 'key' => 'max_elevation' ], $block, 'content' ) )->toBe( '200 m' );
	expect( $source->get_value( [ 'key' => 'ascent' ], $block, 'content' ) )->toBe( '100 m' );
	expect( $source->get_value( [ 'key' => 'descent' ], $block, 'content' ) )->toBe( '0 m' );

} );

// ---------------------------------------------------------------------------
// Explicit mapId path
// ---------------------------------------------------------------------------

test( 'accepts an explicit mapId arg and resolves to the matching map', function (): void {

	$store = bindings_seeded_store( 42, bindings_full_stats() );
	bindings_bind_meta( $store );
	bindings_stub_get_post( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [
		bindings_map_block( 99, 'map-other' ),
		bindings_map_block( 42, 'map-explicit' ),
	] );
	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => $id === 42 ? bindings_fixture_path() : false
	);

	$source = new Statistics_Source();
	$value = $source->get_value(
		[
			'key'   => 'distance',
			'mapId' => 'map-explicit',
		],
		bindings_fake_block( 1 ),
		'content',
	);

	expect( $value )->toBe( '5.5 km' );

} );

test( 'falls back to "auto" when mapId arg is empty string', function (): void {

	$store = bindings_seeded_store( 42, bindings_full_stats() );
	bindings_bind_meta( $store );
	bindings_stub_get_post( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [ bindings_map_block( 42 ) ] );
	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => $id === 42 ? bindings_fixture_path() : false
	);

	$source = new Statistics_Source();
	$value = $source->get_value(
		[
			'key'   => 'distance',
			'mapId' => '',
		],
		bindings_fake_block( 1 ),
		'content',
	);

	expect( $value )->toBe( '5.5 km' );

} );

// ---------------------------------------------------------------------------
// No-elevation track
// ---------------------------------------------------------------------------

test( 'returns empty string for elevation keys when track has no elevation', function (): void {

	$store = bindings_seeded_store( 42, bindings_no_elev_stats() );
	bindings_bind_meta( $store );
	bindings_stub_get_post( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [ bindings_map_block( 42 ) ] );
	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => $id === 42 ? bindings_fixture_path() : false
	);

	$source = new Statistics_Source();
	$block  = bindings_fake_block( 1 );

	expect( $source->get_value( [ 'key' => 'distance' ], $block, 'content' ) )->toBe( '500 m' );
	expect( $source->get_value( [ 'key' => 'min_elevation' ], $block, 'content' ) )->toBe( '' );
	expect( $source->get_value( [ 'key' => 'max_elevation' ], $block, 'content' ) )->toBe( '' );
	expect( $source->get_value( [ 'key' => 'ascent' ], $block, 'content' ) )->toBe( '' );
	expect( $source->get_value( [ 'key' => 'descent' ], $block, 'content' ) )->toBe( '' );

} );

// ---------------------------------------------------------------------------
// Resolver errors
// ---------------------------------------------------------------------------

test( 'returns empty string when no map block is present on the post', function (): void {

	bindings_stub_get_post( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [] );

	$source = new Statistics_Source();
	$value = $source->get_value( [ 'key' => 'distance' ], bindings_fake_block( 1 ), 'content' );

	expect( $value )->toBe( '' );

} );

test( 'returns empty string for all keys when multiple maps exist with auto resolve', function (): void {

	bindings_stub_get_post( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [
		bindings_map_block( 42, 'map-a' ),
		bindings_map_block( 43, 'map-b' ),
	] );

	$source = new Statistics_Source();
	$block  = bindings_fake_block( 1 );

	foreach ( [ 'distance', 'min_elevation', 'max_elevation', 'ascent', 'descent' ] as $key ) {
		expect( $source->get_value( [ 'key' => $key ], $block, 'content' ) )->toBe( '' );
	}

} );

test( 'returns empty string when explicit mapId is not found', function (): void {

	bindings_stub_get_post( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [ bindings_map_block( 42, 'map-real' ) ] );

	$source = new Statistics_Source();
	$value = $source->get_value(
		[
			'key'   => 'distance',
			'mapId' => 'does-not-exist',
		],
		bindings_fake_block( 1 ),
		'content',
	);

	expect( $value )->toBe( '' );

} );

// ---------------------------------------------------------------------------
// Cache errors
// ---------------------------------------------------------------------------

test( 'returns empty string when cache reports a stored error', function (): void {

	$store = [
		42 => [
			'_kntnt_gpx_blocks_error' => 'parse-failed',
		],
	];
	bindings_bind_meta( $store );
	bindings_stub_get_post( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [ bindings_map_block( 42 ) ] );
	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => $id === 42 ? bindings_fixture_path() : false
	);

	$source = new Statistics_Source();
	$value = $source->get_value( [ 'key' => 'distance' ], bindings_fake_block( 1 ), 'content' );

	expect( $value )->toBe( '' );

} );

// ---------------------------------------------------------------------------
// Bad input
// ---------------------------------------------------------------------------

test( 'returns empty string for an unknown key without resolving the map', function (): void {

	// parse_blocks must NOT be called when the key is rejected up front.
	Functions\expect( 'parse_blocks' )->never();

	$source = new Statistics_Source();
	$value = $source->get_value( [ 'key' => 'unknown' ], bindings_fake_block( 1 ), 'content' );

	expect( $value )->toBe( '' );

} );

test( 'returns empty string when key is missing from args', function (): void {

	Functions\expect( 'parse_blocks' )->never();

	$source = new Statistics_Source();

	expect( $source->get_value( [], bindings_fake_block( 1 ), 'content' ) )->toBe( '' );

} );

test( 'returns empty string when postId context is missing or zero', function (): void {

	Functions\expect( 'parse_blocks' )->never();

	$source = new Statistics_Source();

	expect( $source->get_value( [ 'key' => 'distance' ], bindings_fake_block( 0 ), 'content' ) )->toBe( '' );

} );

// ---------------------------------------------------------------------------
// Memoization: 5 calls = 1 parse_blocks + 1 cache fetch
// ---------------------------------------------------------------------------

test( 'memoizes resolve and fetch across five calls for the same (post, map) pair', function (): void {

	$store = bindings_seeded_store( 42, bindings_full_stats() );
	bindings_bind_meta( $store );
	bindings_stub_get_post( 1 );
	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => $id === 42 ? bindings_fixture_path() : false
	);

	// parse_blocks() is the source's expensive call. Memo means exactly one
	// invocation across five binding-key calls.
	Functions\expect( 'parse_blocks' )->once()->andReturn( [ bindings_map_block( 42 ) ] );

	$source = new Statistics_Source();
	$block  = bindings_fake_block( 1 );

	foreach ( [ 'distance', 'min_elevation', 'max_elevation', 'ascent', 'descent' ] as $key ) {
		$source->get_value( [ 'key' => $key ], $block, 'content' );
	}

	// Mockery's once() expectation is verified at tearDown.
	expect( true )->toBeTrue();

} );

test( 'memoizes resolve errors so subsequent calls do not re-invoke parse_blocks', function (): void {

	bindings_stub_get_post( 1 );
	Functions\expect( 'parse_blocks' )->once()->andReturn( [] );

	$source = new Statistics_Source();
	$block  = bindings_fake_block( 1 );

	foreach ( [ 'distance', 'min_elevation', 'max_elevation', 'ascent', 'descent' ] as $key ) {
		expect( $source->get_value( [ 'key' => $key ], $block, 'content' ) )->toBe( '' );
	}

} );

test( 'separate (post, map) pairs are memoized independently', function (): void {

	$store = array_replace(
		bindings_seeded_store( 42, bindings_full_stats() ),
		bindings_seeded_store( 43, bindings_no_elev_stats() ),
	);
	bindings_bind_meta( $store );
	Functions\when( 'get_attached_file' )->alias(
		static function ( int $id ): string|false {
			return in_array( $id, [ 42, 43 ], true ) ? bindings_fixture_path() : false;
		}
	);
	Functions\when( 'get_post' )->alias(
		static function ( int $id ): ?object {
			$p = new stdClass();
			$p->ID = $id;
			$p->post_content = '';
			return $p;
		}
	);
	// Different post IDs return different block trees.
	Functions\when( 'parse_blocks' )->alias(
		static function ( string $content ): array {
			static $call = 0;
			$call++;
			return 1 === $call
				? [ bindings_map_block( 42, 'map-a' ) ]
				: [ bindings_map_block( 43, 'map-b' ) ];
		}
	);

	$source = new Statistics_Source();

	expect( $source->get_value( [ 'key' => 'distance' ], bindings_fake_block( 1 ), 'content' ) )->toBe( '5.5 km' );
	expect( $source->get_value( [ 'key' => 'distance' ], bindings_fake_block( 2 ), 'content' ) )->toBe( '500 m' );

} );
