<?php
/**
 * Tests for Bindings\Statistics_Shortcode.
 *
 * Strategy: drive the real Statistics_Shortcode with real Resolve_Map_Id,
 * Attachment_Cache, and Value_Formatter collaborators. The WordPress I/O
 * boundary is replaced by Brain Monkey stubs (parse_blocks, get_post,
 * get_post_meta, get_attached_file, get_the_ID, apply_filters,
 * number_format_i18n).
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
use Kntnt\Gpx_Blocks\Bindings\Statistics_Shortcode;
use Kntnt\Gpx_Blocks\Cache\Cache_Version;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Wires Brain Monkey meta stubs against an in-memory store.
 *
 * @param array<int, array<string, mixed>> $store Meta store keyed by attachment ID.
 */
function shortcode_bind_meta( array &$store ): void {

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
function shortcode_fixture_path(): string {
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
function shortcode_seeded_store( int $attachment_id, array $statistics ): array {

	$path = shortcode_fixture_path();
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
function shortcode_map_block( int $attachment_id, string $map_id = 'map-abc' ): array {
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
function shortcode_stub_get_post( int $post_id ): void {
	$post = new stdClass();
	$post->ID = $post_id;
	$post->post_content = '';

	Functions\when( 'get_post' )->alias(
		static fn ( int $id ): ?object => $id === $post_id ? $post : null
	);
}

/**
 * Stubs `get_the_ID()` to return the supplied post ID for the current loop.
 *
 * @param int|false $post_id Post ID, or `false` when outside the loop.
 */
function shortcode_stub_get_the_id( int|false $post_id ): void {
	Functions\when( 'get_the_ID' )->justReturn( $post_id );
}

/**
 * Standard "all five keys" statistics array.
 *
 * @return array<string, float>
 */
function shortcode_full_stats(): array {
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
function shortcode_no_elev_stats(): array {
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
// Dispatch table: every key returns the expected formatted value
// ---------------------------------------------------------------------------

test( 'returns formatted distance for the "distance" key', function (): void {

	$store = shortcode_seeded_store( 42, shortcode_full_stats() );
	shortcode_bind_meta( $store );
	shortcode_stub_get_post( 1 );
	shortcode_stub_get_the_id( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [ shortcode_map_block( 42 ) ] );
	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => $id === 42 ? shortcode_fixture_path() : false
	);

	$shortcode = new Statistics_Shortcode();

	expect( $shortcode->render( [ 0 => 'distance' ] ) )->toBe( '5.5 km' );

} );

test( 'returns formatted elevation for the four hyphenated elevation keys', function (): void {

	$store = shortcode_seeded_store( 42, shortcode_full_stats() );
	shortcode_bind_meta( $store );
	shortcode_stub_get_post( 1 );
	shortcode_stub_get_the_id( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [ shortcode_map_block( 42 ) ] );
	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => $id === 42 ? shortcode_fixture_path() : false
	);

	$shortcode = new Statistics_Shortcode();

	expect( $shortcode->render( [ 0 => 'min-elevation' ] ) )->toBe( '100 m' );
	expect( $shortcode->render( [ 0 => 'max-elevation' ] ) )->toBe( '200 m' );
	expect( $shortcode->render( [ 0 => 'ascent' ] ) )->toBe( '100 m' );
	expect( $shortcode->render( [ 0 => 'descent' ] ) )->toBe( '0 m' );

} );

// ---------------------------------------------------------------------------
// Explicit map= path
// ---------------------------------------------------------------------------

test( 'accepts an explicit map= attribute and resolves to the matching map', function (): void {

	$store = shortcode_seeded_store( 42, shortcode_full_stats() );
	shortcode_bind_meta( $store );
	shortcode_stub_get_post( 1 );
	shortcode_stub_get_the_id( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [
		shortcode_map_block( 99, 'map-other' ),
		shortcode_map_block( 42, 'map-explicit' ),
	] );
	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => $id === 42 ? shortcode_fixture_path() : false
	);

	$shortcode = new Statistics_Shortcode();

	expect( $shortcode->render( [
		0     => 'distance',
		'map' => 'map-explicit',
	] ) )->toBe( '5.5 km' );

} );

test( 'coerces empty map="" to "auto"', function (): void {

	$store = shortcode_seeded_store( 42, shortcode_full_stats() );
	shortcode_bind_meta( $store );
	shortcode_stub_get_post( 1 );
	shortcode_stub_get_the_id( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [ shortcode_map_block( 42 ) ] );
	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => $id === 42 ? shortcode_fixture_path() : false
	);

	$shortcode = new Statistics_Shortcode();

	expect( $shortcode->render( [
		0     => 'distance',
		'map' => '',
	] ) )->toBe( '5.5 km' );

} );

// ---------------------------------------------------------------------------
// No-elevation track
// ---------------------------------------------------------------------------

test( 'returns empty string for elevation keys when track has no elevation', function (): void {

	$store = shortcode_seeded_store( 42, shortcode_no_elev_stats() );
	shortcode_bind_meta( $store );
	shortcode_stub_get_post( 1 );
	shortcode_stub_get_the_id( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [ shortcode_map_block( 42 ) ] );
	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => $id === 42 ? shortcode_fixture_path() : false
	);

	$shortcode = new Statistics_Shortcode();

	expect( $shortcode->render( [ 0 => 'distance' ] ) )->toBe( '500 m' );
	expect( $shortcode->render( [ 0 => 'min-elevation' ] ) )->toBe( '' );
	expect( $shortcode->render( [ 0 => 'max-elevation' ] ) )->toBe( '' );
	expect( $shortcode->render( [ 0 => 'ascent' ] ) )->toBe( '' );
	expect( $shortcode->render( [ 0 => 'descent' ] ) )->toBe( '' );

} );

// ---------------------------------------------------------------------------
// Resolver errors
// ---------------------------------------------------------------------------

test( 'returns empty string when no map block is present on the post', function (): void {

	shortcode_stub_get_post( 1 );
	shortcode_stub_get_the_id( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [] );

	$shortcode = new Statistics_Shortcode();

	expect( $shortcode->render( [ 0 => 'distance' ] ) )->toBe( '' );

} );

test( 'returns empty string for all keys when multiple maps exist with auto resolve', function (): void {

	shortcode_stub_get_post( 1 );
	shortcode_stub_get_the_id( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [
		shortcode_map_block( 42, 'map-a' ),
		shortcode_map_block( 43, 'map-b' ),
	] );

	$shortcode = new Statistics_Shortcode();

	foreach ( [ 'distance', 'min-elevation', 'max-elevation', 'ascent', 'descent' ] as $key ) {
		expect( $shortcode->render( [ 0 => $key ] ) )->toBe( '' );
	}

} );

test( 'returns empty string when explicit map= is not found', function (): void {

	shortcode_stub_get_post( 1 );
	shortcode_stub_get_the_id( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [ shortcode_map_block( 42, 'map-real' ) ] );

	$shortcode = new Statistics_Shortcode();

	expect( $shortcode->render( [
		0     => 'distance',
		'map' => 'does-not-exist',
	] ) )->toBe( '' );

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
	shortcode_bind_meta( $store );
	shortcode_stub_get_post( 1 );
	shortcode_stub_get_the_id( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [ shortcode_map_block( 42 ) ] );
	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => $id === 42 ? shortcode_fixture_path() : false
	);

	$shortcode = new Statistics_Shortcode();

	expect( $shortcode->render( [ 0 => 'distance' ] ) )->toBe( '' );

} );

// ---------------------------------------------------------------------------
// Bad input
// ---------------------------------------------------------------------------

test( 'returns empty string for an unknown key without resolving the map', function (): void {

	shortcode_stub_get_the_id( 1 );

	// parse_blocks must NOT be called when the key is rejected up front.
	Functions\expect( 'parse_blocks' )->never();

	$shortcode = new Statistics_Shortcode();

	expect( $shortcode->render( [ 0 => 'unknown' ] ) )->toBe( '' );

} );

test( 'rejects underscored cache-shape keys; only hyphenated keys are public', function (): void {

	shortcode_stub_get_the_id( 1 );
	Functions\expect( 'parse_blocks' )->never();

	$shortcode = new Statistics_Shortcode();

	// The underscore vocabulary lives in the cache shape; the shortcode's
	// public contract is hyphenated only.
	expect( $shortcode->render( [ 0 => 'min_elevation' ] ) )->toBe( '' );

} );

test( 'returns empty string and does not warn for a PHP error when key is absent', function (): void {

	shortcode_stub_get_the_id( 1 );
	Functions\expect( 'parse_blocks' )->never();

	$shortcode = new Statistics_Shortcode();

	// Missing positional key — same shape WordPress hands the callback when
	// the shortcode is written as just `[kntnt-gpx]` with no positional arg.
	expect( $shortcode->render( [] ) )->toBe( '' );

} );

test( 'returns empty string when WordPress passes the empty-string no-atts shape', function (): void {

	shortcode_stub_get_the_id( 1 );
	Functions\expect( 'parse_blocks' )->never();

	$shortcode = new Statistics_Shortcode();

	// WordPress hands a literal '' string to the callback when the shortcode
	// is invoked with no attributes at all. The handler must handle that.
	expect( $shortcode->render( '' ) )->toBe( '' );

} );

test( 'returns empty string when get_the_ID() is unavailable or returns false', function (): void {

	shortcode_stub_get_the_id( false );
	Functions\expect( 'parse_blocks' )->never();

	$shortcode = new Statistics_Shortcode();

	expect( $shortcode->render( [ 0 => 'distance' ] ) )->toBe( '' );

} );

// ---------------------------------------------------------------------------
// Memoization: 5 inline shortcodes = 1 parse_blocks + 1 cache fetch
// ---------------------------------------------------------------------------

test( 'memoizes resolve and fetch across five calls for the same (post, map) pair', function (): void {

	$store = shortcode_seeded_store( 42, shortcode_full_stats() );
	shortcode_bind_meta( $store );
	shortcode_stub_get_post( 1 );
	shortcode_stub_get_the_id( 1 );
	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => $id === 42 ? shortcode_fixture_path() : false
	);

	// parse_blocks() is the expensive call. Memo means exactly one invocation
	// across five inline shortcodes.
	Functions\expect( 'parse_blocks' )->once()->andReturn( [ shortcode_map_block( 42 ) ] );

	$shortcode = new Statistics_Shortcode();

	foreach ( [ 'distance', 'min-elevation', 'max-elevation', 'ascent', 'descent' ] as $key ) {
		$shortcode->render( [ 0 => $key ] );
	}

	// Mockery's once() expectation is verified at tearDown.
	expect( true )->toBeTrue();

} );

test( 'memoizes resolve errors so subsequent calls do not re-invoke parse_blocks', function (): void {

	shortcode_stub_get_post( 1 );
	shortcode_stub_get_the_id( 1 );
	Functions\expect( 'parse_blocks' )->once()->andReturn( [] );

	$shortcode = new Statistics_Shortcode();

	foreach ( [ 'distance', 'min-elevation', 'max-elevation', 'ascent', 'descent' ] as $key ) {
		expect( $shortcode->render( [ 0 => $key ] ) )->toBe( '' );
	}

} );

test( 'separate (post, map) pairs are memoized independently', function (): void {

	$store = array_replace(
		shortcode_seeded_store( 42, shortcode_full_stats() ),
		shortcode_seeded_store( 43, shortcode_no_elev_stats() ),
	);
	shortcode_bind_meta( $store );
	Functions\when( 'get_attached_file' )->alias(
		static function ( int $id ): string|false {
			return in_array( $id, [ 42, 43 ], true ) ? shortcode_fixture_path() : false;
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
				? [ shortcode_map_block( 42, 'map-a' ) ]
				: [ shortcode_map_block( 43, 'map-b' ) ];
		}
	);

	$shortcode = new Statistics_Shortcode();

	shortcode_stub_get_the_id( 1 );
	expect( $shortcode->render( [ 0 => 'distance' ] ) )->toBe( '5.5 km' );

	shortcode_stub_get_the_id( 2 );
	expect( $shortcode->render( [ 0 => 'distance' ] ) )->toBe( '500 m' );

} );
