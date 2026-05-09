<?php
/**
 * Tests for Rest\Statistics_Preview_Controller.
 *
 * The cache, resolver, and formatter classes are all `final`, so the tests
 * use real instances and stub the WordPress functions they rely on through
 * Brain Monkey — the same pattern Preview_ControllerTest and
 * Statistics_SourceTest use for their happy-path coverage.
 *
 * Coverage:
 * - register_routes() registers the documented namespace, route, method,
 *   permission callback, and arg schema.
 * - check_permission() returns the result of current_user_can( 'edit_posts' ).
 * - get_preview() returns 400 on a missing/zero postId.
 * - get_preview() returns 404 for resolver errors that mean "no map exists".
 * - get_preview() returns 422 for cache errors.
 * - get_preview() returns the full set of formatted values on success.
 * - get_preview() preserves null statistics in the response (no-elevation tracks).
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Cache\Attachment_Cache;
use Kntnt\Gpx_Blocks\Cache\Cache_Version;
use Kntnt\Gpx_Blocks\Rest\Statistics_Preview_Controller;

// ---------------------------------------------------------------------------
// Shared setup
// ---------------------------------------------------------------------------

beforeEach( function (): void {

	// Pass-through stubs for the i18n + escaping calls the controller and
	// its collaborators make. The tests never assert on translated strings.
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
// Helpers
// ---------------------------------------------------------------------------

/**
 * Builds a minimal stand-in for WP_REST_Request that returns the given
 * postId / mapId values via offset access.
 *
 * @param mixed $post_id Value to return for the 'postId' offset.
 * @param mixed $map_id  Value to return for the 'mapId' offset.
 *
 * @return WP_REST_Request
 */
function statistics_preview_fake_request( mixed $post_id, mixed $map_id = 'auto' ): WP_REST_Request {
	return new class( $post_id, $map_id ) extends WP_REST_Request {

		/**
		 * The postId value the test wants to expose under offset 'postId'.
		 *
		 * @var mixed
		 */
		private mixed $post_id;

		/**
		 * The mapId value the test wants to expose under offset 'mapId'.
		 *
		 * @var mixed
		 */
		private mixed $map_id;

		/**
		 * Captures the postId / mapId for later return from offsetGet.
		 *
		 * @param mixed $post_id The postId value to expose.
		 * @param mixed $map_id  The mapId value to expose.
		 */
		public function __construct( mixed $post_id, mixed $map_id ) {
			$this->post_id = $post_id;
			$this->map_id  = $map_id;
		}

		/**
		 * Returns the captured value for the requested offset, null otherwise.
		 *
		 * @param mixed $offset Offset name.
		 *
		 * @return mixed
		 */
		public function offsetGet( $offset ): mixed {
			return match ( $offset ) {
				'postId' => $this->post_id,
				'mapId'  => $this->map_id,
				default  => null,
			};
		}

		/**
		 * Reports that postId and mapId offsets exist.
		 *
		 * @param mixed $offset Offset name.
		 *
		 * @return bool
		 */
		public function offsetExists( $offset ): bool {
			return in_array( $offset, [ 'postId', 'mapId' ], true );
		}
	};
}

/**
 * Returns the absolute path to a fixture file used as a stand-in source GPX.
 *
 * @return string
 */
function statistics_preview_fixture_path(): string {
	return __DIR__ . '/../fixtures/gpx/happy-path.gpx';
}

/**
 * Builds a minimal parsed-block array entry for a GPX Map block.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $map_id        mapId attribute.
 *
 * @return array<string, mixed>
 */
function statistics_preview_map_block( int $attachment_id, string $map_id = 'map-abc' ): array {
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
function statistics_preview_stub_get_post( int $post_id ): void {
	$post = new stdClass();
	$post->ID = $post_id;
	$post->post_content = '';

	Functions\when( 'get_post' )->alias(
		static fn ( int $id ): ?object => $id === $post_id ? $post : null
	);
}

/**
 * Wires Brain Monkey post-meta stubs against an in-memory store.
 *
 * @param array<int, array<string, mixed>> $store Meta store keyed by attachment ID.
 */
function statistics_preview_bind_meta( array &$store ): void {

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
 * Builds an in-memory meta store pre-seeded with a current-version cache entry.
 *
 * @param int                      $attachment_id Attachment ID.
 * @param array<string,float|null> $statistics    Statistics to embed in the cache.
 *
 * @return array<int, array<string, mixed>>
 */
function statistics_preview_seeded_store( int $attachment_id, array $statistics ): array {

	$path = statistics_preview_fixture_path();
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
 * Standard "all five keys" statistics array.
 *
 * @return array<string, float>
 */
function statistics_preview_full_stats(): array {
	return [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 842.0,
		'descent'       => 815.0,
	];
}

// ---------------------------------------------------------------------------
// register_routes()
// ---------------------------------------------------------------------------

test( 'register_routes registers the documented endpoint', function (): void {

	$captured = null;
	Functions\when( 'register_rest_route' )->alias(
		static function ( string $route_namespace, string $route, array $args ) use ( &$captured ): bool {
			$captured = [
				'namespace' => $route_namespace,
				'route'     => $route,
				'args'      => $args,
			];
			return true;
		}
	);

	$controller = new Statistics_Preview_Controller( new Attachment_Cache() );
	$controller->register_routes();

	expect( $captured )->not->toBeNull();
	expect( $captured['namespace'] )->toBe( 'kntnt-gpx-blocks/v1' );
	expect( $captured['route'] )->toBe( '/statistics-preview' );
	expect( $captured['args']['methods'] )->toBe( 'GET' );

	// postId is required and typed as integer.
	expect( $captured['args']['args']['postId']['type'] )->toBe( 'integer' );
	expect( $captured['args']['args']['postId']['required'] )->toBeTrue();

	// mapId is optional with a default of 'auto'.
	expect( $captured['args']['args']['mapId']['type'] )->toBe( 'string' );
	expect( $captured['args']['args']['mapId']['default'] )->toBe( 'auto' );

} );

// ---------------------------------------------------------------------------
// check_permission()
// ---------------------------------------------------------------------------

test( 'check_permission delegates to current_user_can(edit_posts)', function (): void {

	Functions\when( 'current_user_can' )->alias(
		static fn ( string $cap ): bool => 'edit_posts' === $cap
	);

	$controller = new Statistics_Preview_Controller( new Attachment_Cache() );
	expect( $controller->check_permission() )->toBeTrue();

} );

test( 'check_permission returns false when the user cannot edit posts', function (): void {

	Functions\when( 'current_user_can' )->justReturn( false );

	$controller = new Statistics_Preview_Controller( new Attachment_Cache() );
	expect( $controller->check_permission() )->toBeFalse();

} );

// ---------------------------------------------------------------------------
// get_preview() — error paths
// ---------------------------------------------------------------------------

test( 'get_preview returns 400 when the postId is zero', function (): void {

	$controller = new Statistics_Preview_Controller( new Attachment_Cache() );
	$response   = $controller->get_preview( statistics_preview_fake_request( 0 ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_code() )->toBe( 'invalid-post' );
	expect( $response->get_error_data()['status'] ?? null )->toBe( 400 );

} );

test( 'get_preview returns 404 when no map block exists on the post', function (): void {

	statistics_preview_stub_get_post( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [] );

	$controller = new Statistics_Preview_Controller( new Attachment_Cache() );
	$response   = $controller->get_preview( statistics_preview_fake_request( 1 ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_code() )->toBe( 'no-map' );
	expect( $response->get_error_data()['status'] ?? null )->toBe( 404 );

} );

test( 'get_preview returns 422 for multiple-maps with auto resolve', function (): void {

	statistics_preview_stub_get_post( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [
		statistics_preview_map_block( 42, 'map-a' ),
		statistics_preview_map_block( 43, 'map-b' ),
	] );

	$controller = new Statistics_Preview_Controller( new Attachment_Cache() );
	$response   = $controller->get_preview( statistics_preview_fake_request( 1 ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_code() )->toBe( 'multiple-maps' );
	expect( $response->get_error_data()['status'] ?? null )->toBe( 422 );

} );

test( 'get_preview returns 404 when explicit mapId is not found', function (): void {

	statistics_preview_stub_get_post( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [ statistics_preview_map_block( 42, 'map-real' ) ] );

	$controller = new Statistics_Preview_Controller( new Attachment_Cache() );
	$response   = $controller->get_preview( statistics_preview_fake_request( 1, 'does-not-exist' ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_code() )->toBe( 'map-not-found' );
	expect( $response->get_error_data()['status'] ?? null )->toBe( 404 );

} );

test( 'get_preview returns 422 when the cache has a stored error', function (): void {

	$store = [
		42 => [
			'_kntnt_gpx_blocks_error' => 'parse-failed',
		],
	];
	statistics_preview_bind_meta( $store );
	statistics_preview_stub_get_post( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [ statistics_preview_map_block( 42 ) ] );

	$controller = new Statistics_Preview_Controller( new Attachment_Cache() );
	$response   = $controller->get_preview( statistics_preview_fake_request( 1 ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_code() )->toBe( 'parse-failed' );
	expect( $response->get_error_data()['status'] ?? null )->toBe( 422 );

} );

// ---------------------------------------------------------------------------
// get_preview() — success path
// ---------------------------------------------------------------------------

test( 'get_preview returns the full set of formatted values on success', function (): void {

	$store = statistics_preview_seeded_store( 42, statistics_preview_full_stats() );
	statistics_preview_bind_meta( $store );
	statistics_preview_stub_get_post( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [ statistics_preview_map_block( 42, 'map-only' ) ] );
	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => 42 === $id ? statistics_preview_fixture_path() : false
	);

	$controller = new Statistics_Preview_Controller( new Attachment_Cache() );
	$response   = $controller->get_preview( statistics_preview_fake_request( 1 ) );

	expect( $response )->toBeInstanceOf( WP_REST_Response::class );
	$data = $response->get_data();
	expect( $data )->toHaveKeys( [ 'attachmentId', 'mapId', 'values' ] );
	expect( $data['attachmentId'] )->toBe( 42 );
	expect( $data['mapId'] )->toBe( 'map-only' );
	expect( $data['values'] )->toBe( [
		'distance'      => '5.5 km',
		'min_elevation' => '100 m',
		'max_elevation' => '200 m',
		'ascent'        => '842 m',
		'descent'       => '815 m',
	] );

} );

test( 'get_preview preserves nulls for tracks with no elevation', function (): void {

	$store = statistics_preview_seeded_store( 42, [
		'distance'      => 500.0,
		'min_elevation' => null,
		'max_elevation' => null,
		'ascent'        => null,
		'descent'       => null,
	] );
	statistics_preview_bind_meta( $store );
	statistics_preview_stub_get_post( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [ statistics_preview_map_block( 42 ) ] );
	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => 42 === $id ? statistics_preview_fixture_path() : false
	);

	$controller = new Statistics_Preview_Controller( new Attachment_Cache() );
	$response   = $controller->get_preview( statistics_preview_fake_request( 1 ) );

	expect( $response )->toBeInstanceOf( WP_REST_Response::class );
	$data = $response->get_data();
	expect( $data['values']['distance'] )->toBe( '500 m' );
	expect( $data['values']['min_elevation'] )->toBeNull();
	expect( $data['values']['max_elevation'] )->toBeNull();
	expect( $data['values']['ascent'] )->toBeNull();
	expect( $data['values']['descent'] )->toBeNull();

} );

test( 'get_preview accepts an explicit mapId arg', function (): void {

	$store = statistics_preview_seeded_store( 42, statistics_preview_full_stats() );
	statistics_preview_bind_meta( $store );
	statistics_preview_stub_get_post( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [
		statistics_preview_map_block( 99, 'map-other' ),
		statistics_preview_map_block( 42, 'map-explicit' ),
	] );
	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => 42 === $id ? statistics_preview_fixture_path() : false
	);

	$controller = new Statistics_Preview_Controller( new Attachment_Cache() );
	$response   = $controller->get_preview( statistics_preview_fake_request( 1, 'map-explicit' ) );

	expect( $response )->toBeInstanceOf( WP_REST_Response::class );
	$data = $response->get_data();
	expect( $data['attachmentId'] )->toBe( 42 );
	expect( $data['mapId'] )->toBe( 'map-explicit' );
	expect( $data['values']['distance'] )->toBe( '5.5 km' );

} );

test( 'get_preview falls back to auto when mapId arg is empty', function (): void {

	$store = statistics_preview_seeded_store( 42, statistics_preview_full_stats() );
	statistics_preview_bind_meta( $store );
	statistics_preview_stub_get_post( 1 );
	Functions\when( 'parse_blocks' )->justReturn( [ statistics_preview_map_block( 42, 'map-only' ) ] );
	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => 42 === $id ? statistics_preview_fixture_path() : false
	);

	$controller = new Statistics_Preview_Controller( new Attachment_Cache() );
	$response   = $controller->get_preview( statistics_preview_fake_request( 1, '' ) );

	expect( $response )->toBeInstanceOf( WP_REST_Response::class );
	$data = $response->get_data();
	expect( $data['mapId'] )->toBe( 'map-only' );
	expect( $data['values']['distance'] )->toBe( '5.5 km' );

} );
