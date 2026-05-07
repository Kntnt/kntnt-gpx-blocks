<?php
/**
 * Tests for Cache\Attachment_Cache.
 *
 * Brain Monkey stubs WordPress functions (get_post_meta, update_post_meta,
 * delete_post_meta, get_attached_file, apply_filters, wp_json_encode, __)
 * so the cache class can run without WordPress. The conversion collaborators
 * (Gpx_Parser, Geo_Json_Converter, Statistics_Calculator) are real instances
 * that read from the on-disk fixtures shared with the parser tests.
 *
 * Brain Monkey is set up/torn down by the shared TestCase base class.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Cache\Attachment_Cache;
use Kntnt\Gpx_Blocks\Cache\Cache_Version;
use Kntnt\Gpx_Blocks\Rendering\Render_Error;

// ---------------------------------------------------------------------------
// Shared helpers (the global fixture_path() is declared in Gpx_ParserTest.php)
// ---------------------------------------------------------------------------

/**
 * Builds a per-test in-memory meta store that drives the four meta-related
 * Brain Monkey stubs. Returns the array reference so tests can pre-seed it
 * and assert on the post-conditions.
 *
 * @return array<int, array<string, mixed>>
 */
function fresh_meta_store(): array {
	return [];
}

/**
 * Wires Brain Monkey stubs for the four post-meta functions against the given
 * in-memory store. All four follow the standard `single=true` semantics that
 * Attachment_Cache relies on.
 *
 * @param array<int, array<string, mixed>> $store Reference to the meta store.
 */
function bind_meta_store( array &$store ): void {

	// get_post_meta($id, $key, true) returns the stored value, or '' for missing.
	Functions\when( 'get_post_meta' )->alias(
		static function ( int $id, string $key, bool $single ) use ( &$store ): mixed {
			if ( ! $single ) {
				return [];
			}
			return $store[ $id ][ $key ] ?? '';
		}
	);

	// update_post_meta($id, $key, $value) writes into the store.
	Functions\when( 'update_post_meta' )->alias(
		static function ( int $id, string $key, mixed $value ) use ( &$store ): bool {
			$store[ $id ][ $key ] = $value;
			return true;
		}
	);

	// delete_post_meta($id, $key) removes the slot from the store.
	Functions\when( 'delete_post_meta' )->alias(
		static function ( int $id, string $key ) use ( &$store ): bool {
			unset( $store[ $id ][ $key ] );
			return true;
		}
	);
}

/**
 * Stubs apply_filters() to pass the default through unchanged unless the test
 * has registered a per-name override beforehand. Per-name overrides are
 * keyed by filter name in the supplied map.
 *
 * @param array<string, mixed> $overrides Map of filter name => fixed return.
 */
function bind_filters( array $overrides = [] ): void {
	Functions\when( 'apply_filters' )->alias(
		static function ( string $filter, mixed $fallback ) use ( $overrides ): mixed {
			return array_key_exists( $filter, $overrides ) ? $overrides[ $filter ] : $fallback;
		}
	);
}

/**
 * Stubs wp_json_encode() to delegate to PHP's json_encode().
 */
function bind_json_encode(): void {
	Functions\when( 'wp_json_encode' )->alias(
		static fn ( mixed $value ): string|false => json_encode( $value )
	);
}

/**
 * Stubs `__()` to return the source text unchanged so messages stay readable.
 */
function bind_translate(): void {
	Functions\when( '__' )->returnArg( 1 );
}

/**
 * Stubs get_attached_file() to return a fixed path for one attachment ID.
 *
 * @param int    $id   Attachment ID.
 * @param string $path Path to return.
 */
function bind_attached_file( int $id, string $path ): void {
	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $requested ): string|false => $requested === $id ? $path : false
	);
}

// ---------------------------------------------------------------------------
// Read-path: happy path
// ---------------------------------------------------------------------------

test( 'get returns shaped array when version current and hash matches', function (): void {

	$store    = fresh_meta_store();
	$path     = fixture_path( 'happy-path.gpx' );
	$hash     = md5_file( $path );
	$geojson  = [
		'type'     => 'FeatureCollection',
		'features' => [],
	];

	$store[42] = [
		'_kntnt_gpx_blocks_geojson'     => json_encode( $geojson ),
		'_kntnt_gpx_blocks_statistics'  => [
			'distance'      => 100.0,
			'min_elevation' => null,
			'max_elevation' => null,
			'ascent'        => null,
			'descent'       => null,
		],
		'_kntnt_gpx_blocks_version'     => Cache_Version::CURRENT,
		'_kntnt_gpx_blocks_source_hash' => $hash,
	];

	bind_meta_store( $store );
	bind_filters();
	bind_json_encode();
	bind_translate();
	bind_attached_file( 42, $path );

	$cache  = new Attachment_Cache();
	$result = $cache->get( 42 );

	expect( $result )->toBeArray()
		->and( $result )->toHaveKeys( [ 'geojson', 'statistics', 'attachment_id' ] )
		->and( $result['attachment_id'] )->toBe( 42 )
		->and( $result['geojson'] )->toBe( $geojson )
		->and( $result['statistics']['distance'] )->toBe( 100.0 );

} );

// ---------------------------------------------------------------------------
// Read-path: error meta short-circuits
// ---------------------------------------------------------------------------

test( 'get returns Render_Error when error meta is set', function (): void {

	$store     = fresh_meta_store();
	$store[7]  = [ '_kntnt_gpx_blocks_error' => 'too-large' ];

	bind_meta_store( $store );
	bind_filters();
	bind_translate();

	$cache  = new Attachment_Cache();
	$result = $cache->get( 7 );

	expect( $result )->toBeInstanceOf( Render_Error::class )
		->and( $result->code )->toBe( 'too-large' )
		->and( $result->message )->toBeString();

} );

// ---------------------------------------------------------------------------
// Read-path: version missing triggers regeneration
// ---------------------------------------------------------------------------

test( 'get triggers regeneration when version meta missing', function (): void {

	$store = fresh_meta_store();
	$path  = fixture_path( 'happy-path.gpx' );

	bind_meta_store( $store );
	bind_filters();
	bind_json_encode();
	bind_translate();
	bind_attached_file( 100, $path );

	$cache  = new Attachment_Cache();
	$result = $cache->get( 100 );

	expect( $result )->toBeArray()
		->and( $store[100]['_kntnt_gpx_blocks_version'] )->toBe( Cache_Version::CURRENT )
		->and( $store[100]['_kntnt_gpx_blocks_source_hash'] )->toBe( md5_file( $path ) )
		->and( $store[100] )->toHaveKey( '_kntnt_gpx_blocks_geojson' )
		->and( $store[100] )->toHaveKey( '_kntnt_gpx_blocks_statistics' );

} );

// ---------------------------------------------------------------------------
// Read-path: stored version older than current triggers regeneration
// ---------------------------------------------------------------------------

test( 'get triggers regeneration when stored version below CURRENT', function (): void {

	$store = fresh_meta_store();
	$path  = fixture_path( 'happy-path.gpx' );

	$store[55] = [
		'_kntnt_gpx_blocks_geojson'     => json_encode( [
			'type'     => 'FeatureCollection',
			'features' => [ 'old' ],
		] ),
		'_kntnt_gpx_blocks_statistics'  => [
			'distance'      => 0.0,
			'min_elevation' => null,
			'max_elevation' => null,
			'ascent'        => null,
			'descent'       => null,
		],
		'_kntnt_gpx_blocks_version'     => 0,
		'_kntnt_gpx_blocks_source_hash' => md5_file( $path ),
	];

	bind_meta_store( $store );
	bind_filters();
	bind_json_encode();
	bind_translate();
	bind_attached_file( 55, $path );

	$cache  = new Attachment_Cache();
	$result = $cache->get( 55 );

	expect( $result )->toBeArray()
		->and( $store[55]['_kntnt_gpx_blocks_version'] )->toBe( Cache_Version::CURRENT );

} );

// ---------------------------------------------------------------------------
// Read-path: hash mismatch triggers regeneration
// ---------------------------------------------------------------------------

test( 'get triggers regeneration when stored hash differs from file', function (): void {

	$store = fresh_meta_store();
	$path  = fixture_path( 'happy-path.gpx' );

	$store[77] = [
		'_kntnt_gpx_blocks_geojson'     => json_encode( [ 'stale' => true ] ),
		'_kntnt_gpx_blocks_statistics'  => [
			'distance'      => 0.0,
			'min_elevation' => null,
			'max_elevation' => null,
			'ascent'        => null,
			'descent'       => null,
		],
		'_kntnt_gpx_blocks_version'     => Cache_Version::CURRENT,
		'_kntnt_gpx_blocks_source_hash' => str_repeat( 'a', 32 ),
	];

	bind_meta_store( $store );
	bind_filters();
	bind_json_encode();
	bind_translate();
	bind_attached_file( 77, $path );

	$cache  = new Attachment_Cache();
	$result = $cache->get( 77 );

	expect( $result )->toBeArray()
		->and( $store[77]['_kntnt_gpx_blocks_source_hash'] )->toBe( md5_file( $path ) )
		->and( $store[77]['_kntnt_gpx_blocks_geojson'] )->not->toBe( json_encode( [ 'stale' => true ] ) );

} );

// ---------------------------------------------------------------------------
// Regenerate: parser exception sets error meta
// ---------------------------------------------------------------------------

test( 'regenerate sets error meta when parser throws', function (): void {

	$store = fresh_meta_store();

	bind_meta_store( $store );
	bind_filters();
	bind_json_encode();
	bind_translate();
	bind_attached_file( 13, fixture_path( 'wrong-root.gpx' ) );

	$cache = new Attachment_Cache();
	$cache->regenerate( 13 );

	expect( $store[13] )->toHaveKey( '_kntnt_gpx_blocks_error' )
		->and( $store[13]['_kntnt_gpx_blocks_error'] )->toBe( 'wrong-mime' )
		->and( $store[13] )->not->toHaveKey( '_kntnt_gpx_blocks_geojson' );

} );

// ---------------------------------------------------------------------------
// Regenerate: malformed XML produces 'parse-failed'
// ---------------------------------------------------------------------------

test( 'regenerate sets parse-failed on malformed XML', function (): void {

	$store = fresh_meta_store();

	bind_meta_store( $store );
	bind_filters();
	bind_json_encode();
	bind_translate();
	bind_attached_file( 21, fixture_path( 'malformed.gpx' ) );

	$cache = new Attachment_Cache();
	$cache->regenerate( 21 );

	expect( $store[21]['_kntnt_gpx_blocks_error'] )->toBe( 'parse-failed' );

} );

// ---------------------------------------------------------------------------
// Regenerate: writes four data keys and clears prior error
// ---------------------------------------------------------------------------

test( 'regenerate writes four meta keys and deletes prior error', function (): void {

	$store     = fresh_meta_store();
	$path      = fixture_path( 'happy-path.gpx' );
	$store[5]  = [ '_kntnt_gpx_blocks_error' => 'parse-failed' ];

	bind_meta_store( $store );
	bind_filters();
	bind_json_encode();
	bind_translate();
	bind_attached_file( 5, $path );

	$cache = new Attachment_Cache();
	$cache->regenerate( 5 );

	expect( $store[5] )->toHaveKey( '_kntnt_gpx_blocks_geojson' )
		->and( $store[5] )->toHaveKey( '_kntnt_gpx_blocks_statistics' )
		->and( $store[5] )->toHaveKey( '_kntnt_gpx_blocks_version' )
		->and( $store[5] )->toHaveKey( '_kntnt_gpx_blocks_source_hash' )
		->and( $store[5] )->not->toHaveKey( '_kntnt_gpx_blocks_error' )
		->and( $store[5]['_kntnt_gpx_blocks_version'] )->toBe( Cache_Version::CURRENT )
		->and( $store[5]['_kntnt_gpx_blocks_source_hash'] )->toBe( md5_file( $path ) );

} );

// ---------------------------------------------------------------------------
// Regenerate: oversized file is rejected before parsing
// ---------------------------------------------------------------------------

test( 'regenerate sets too-large when file exceeds size cap', function (): void {

	$store = fresh_meta_store();

	bind_meta_store( $store );
	// Force the size cap to one byte so the happy-path fixture overflows it.
	bind_filters( [ 'kntnt_gpx_blocks_max_file_size_bytes' => 1 ] );
	bind_json_encode();
	bind_translate();
	bind_attached_file( 8, fixture_path( 'happy-path.gpx' ) );

	$cache = new Attachment_Cache();
	$cache->regenerate( 8 );

	expect( $store[8]['_kntnt_gpx_blocks_error'] )->toBe( 'too-large' );

} );

// ---------------------------------------------------------------------------
// Regenerate: missing file recorded as 'file-missing'
// ---------------------------------------------------------------------------

test( 'regenerate sets file-missing when get_attached_file fails', function (): void {

	$store = fresh_meta_store();

	bind_meta_store( $store );
	bind_filters();
	bind_json_encode();
	bind_translate();

	// get_attached_file returns false for every ID in this test.
	Functions\when( 'get_attached_file' )->justReturn( false );

	$cache = new Attachment_Cache();
	$cache->regenerate( 99 );

	expect( $store[99]['_kntnt_gpx_blocks_error'] )->toBe( 'file-missing' );

} );

// ---------------------------------------------------------------------------
// Read-path: file-missing returns Render_Error during get()
// ---------------------------------------------------------------------------

test( 'get returns file-missing when file disappeared after a successful conversion', function (): void {

	$store = fresh_meta_store();

	$store[201] = [
		'_kntnt_gpx_blocks_geojson'     => json_encode( [
			'type'     => 'FeatureCollection',
			'features' => [],
		] ),
		'_kntnt_gpx_blocks_statistics'  => [
			'distance'      => 0.0,
			'min_elevation' => null,
			'max_elevation' => null,
			'ascent'        => null,
			'descent'       => null,
		],
		'_kntnt_gpx_blocks_version'     => Cache_Version::CURRENT,
		'_kntnt_gpx_blocks_source_hash' => str_repeat( 'a', 32 ),
	];

	bind_meta_store( $store );
	bind_filters();
	bind_json_encode();
	bind_translate();
	Functions\when( 'get_attached_file' )->justReturn( false );

	$cache  = new Attachment_Cache();
	$result = $cache->get( 201 );

	expect( $result )->toBeInstanceOf( Render_Error::class )
		->and( $result->code )->toBe( 'file-missing' );

} );
