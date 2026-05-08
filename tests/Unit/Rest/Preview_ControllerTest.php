<?php
/**
 * Tests for Rest\Preview_Controller.
 *
 * The cache layer is `final`, so the tests use a real Attachment_Cache instance
 * and stub the WordPress functions it relies on through Brain Monkey — the same
 * pattern Render_MapTest uses for its happy-path coverage.
 *
 * Coverage:
 * - register_routes() registers the documented namespace, route, method,
 *   permission callback, and id arg.
 * - check_permission() returns the result of current_user_can( 'edit_posts' ).
 * - get_preview() returns 400 on a missing/zero id.
 * - get_preview() returns 404 when no attachment exists.
 * - get_preview() returns 400 when the attachment is not a GPX file.
 * - get_preview() returns the GeoJSON on success.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Cache\Attachment_Cache;
use Kntnt\Gpx_Blocks\Cache\Cache_Version;
use Kntnt\Gpx_Blocks\Rest\Preview_Controller;

// ---------------------------------------------------------------------------
// Shared setup
// ---------------------------------------------------------------------------

beforeEach( function (): void {
	// The controller passes user-facing strings through __(); stub it as
	// pass-through so the tests do not need to mock i18n.
	Functions\when( '__' )->returnArg( 1 );
} );

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Builds a minimal stand-in for WP_REST_Request that returns the given id
 * value via offset access. Subclasses the WP_REST_Request stub from
 * tests/Unit/fixtures/Wp_Error_Stub.php.
 *
 * @param mixed $id Value to return for the 'id' offset.
 *
 * @return WP_REST_Request
 */
function preview_fake_request( mixed $id ): WP_REST_Request {
	return new class( $id ) extends WP_REST_Request {

		/**
		 * The id value the test wants to expose under offset 'id'.
		 *
		 * @var mixed
		 */
		private mixed $id;

		/**
		 * Captures the id for later return from offsetGet.
		 *
		 * @param mixed $id The id value to expose.
		 */
		public function __construct( mixed $id ) {
			$this->id = $id;
		}

		/**
		 * Returns the captured id when 'id' is requested, null otherwise.
		 *
		 * @param mixed $offset Offset name.
		 *
		 * @return mixed
		 */
		public function offsetGet( $offset ): mixed {
			return 'id' === $offset ? $this->id : null;
		}

		/**
		 * Reports that only the 'id' offset exists.
		 *
		 * @param mixed $offset Offset name.
		 *
		 * @return bool
		 */
		public function offsetExists( $offset ): bool {
			return 'id' === $offset;
		}
	};
}

/**
 * Builds a fake WP_Post for an attachment with the given mime type.
 *
 * @param int    $id        Post ID.
 * @param string $mime_type Mime type to set on post_mime_type.
 *
 * @return object
 */
function preview_fake_attachment( int $id, string $mime_type ): object {
	$post                 = new stdClass();
	$post->ID             = $id;
	$post->post_type      = 'attachment';
	$post->post_mime_type = $mime_type;
	return $post;
}

/**
 * Writes a tiny temp file and stubs the post-meta + get_attached_file calls
 * Attachment_Cache makes during a happy-path lookup. Returns the temp path so
 * the caller can clean it up.
 *
 * Using a real file (rather than stubbing md5_file via Patchwork) keeps the
 * test honest — Attachment_Cache hashes the file with md5_file() to detect
 * modifications, and our cache hit only happens when the stored hash matches
 * the file's actual md5.
 *
 * @param string $geojson_json Encoded GeoJSON to expose under the geojson key.
 *
 * @throws RuntimeException When tempnam() fails to allocate a file.
 *
 * @return string Absolute path to the temp file (caller deletes when done).
 */
function preview_stub_meta_for_happy_path( string $geojson_json ): string {

	// Write a deterministic temp file so md5_file() returns a stable value.
	$temp = tempnam( sys_get_temp_dir(), 'preview_test_' );
	if ( false === $temp ) {
		throw new RuntimeException( 'Could not create temp file for cache test.' );
	}
	file_put_contents( $temp, '<gpx></gpx>' );
	$hash = md5_file( $temp );

	Functions\when( 'get_post_meta' )->alias(
		static function ( int $object_id, string $key, bool $single ) use ( $geojson_json, $hash ): mixed {
			return match ( $key ) {
				'_kntnt_gpx_blocks_error'       => '',
				'_kntnt_gpx_blocks_version'     => Cache_Version::CURRENT,
				'_kntnt_gpx_blocks_source_hash' => $hash,
				'_kntnt_gpx_blocks_geojson'     => $geojson_json,
				'_kntnt_gpx_blocks_statistics'  => [
					'distance'      => 100.0,
					'min_elevation' => 0.0,
					'max_elevation' => 50.0,
					'ascent'        => 25.0,
					'descent'       => 25.0,
				],
				default                         => '',
			};
		}
	);

	Functions\when( 'get_attached_file' )->justReturn( $temp );

	return $temp;
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

	$controller = new Preview_Controller( new Attachment_Cache() );
	$controller->register_routes();

	expect( $captured )->not->toBeNull();
	expect( $captured['namespace'] )->toBe( 'kntnt-gpx-blocks/v1' );
	expect( $captured['route'] )->toBe( '/preview/(?P<id>\d+)' );
	expect( $captured['args']['methods'] )->toBe( 'GET' );
	expect( $captured['args']['args']['id']['type'] )->toBe( 'integer' );
	expect( $captured['args']['args']['id']['required'] )->toBeTrue();

} );

// ---------------------------------------------------------------------------
// check_permission()
// ---------------------------------------------------------------------------

test( 'check_permission delegates to current_user_can(edit_posts)', function (): void {

	Functions\when( 'current_user_can' )->alias(
		static fn ( string $cap ): bool => 'edit_posts' === $cap
	);

	$controller = new Preview_Controller( new Attachment_Cache() );
	expect( $controller->check_permission() )->toBeTrue();

} );

test( 'check_permission returns false when the user cannot edit posts', function (): void {

	Functions\when( 'current_user_can' )->justReturn( false );

	$controller = new Preview_Controller( new Attachment_Cache() );
	expect( $controller->check_permission() )->toBeFalse();

} );

// ---------------------------------------------------------------------------
// get_preview() — error paths
// ---------------------------------------------------------------------------

test( 'get_preview returns 400 when the id is zero or non-numeric', function (): void {

	$controller = new Preview_Controller( new Attachment_Cache() );
	$response   = $controller->get_preview( preview_fake_request( 0 ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_code() )->toBe( 'no-attachment' );
	expect( $response->get_error_data()['status'] ?? null )->toBe( 400 );

} );

test( 'get_preview returns 404 when the attachment does not exist', function (): void {

	Functions\when( 'get_post' )->justReturn( null );

	$controller = new Preview_Controller( new Attachment_Cache() );
	$response   = $controller->get_preview( preview_fake_request( 42 ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_code() )->toBe( 'no-attachment' );
	expect( $response->get_error_data()['status'] ?? null )->toBe( 404 );

} );

test( 'get_preview returns 400 when the attachment is not a GPX file', function (): void {

	Functions\when( 'get_post' )->justReturn(
		preview_fake_attachment( 42, 'image/jpeg' )
	);

	$controller = new Preview_Controller( new Attachment_Cache() );
	$response   = $controller->get_preview( preview_fake_request( 42 ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_code() )->toBe( 'wrong-mime' );
	expect( $response->get_error_data()['status'] ?? null )->toBe( 400 );

} );

// ---------------------------------------------------------------------------
// get_preview() — success path
// ---------------------------------------------------------------------------

test( 'get_preview returns the GeoJSON payload on success', function (): void {

	// Use integer coordinates so JSON round-trip preserves them exactly.
	// Floating-point literals like 0.0 round-trip back as int 0 through
	// json_encode/json_decode, breaking the strict `toBe` comparison.
	$geojson = [
		'type'     => 'FeatureCollection',
		'features' => [
			[
				'type'       => 'Feature',
				'geometry'   => [
					'type'        => 'LineString',
					'coordinates' => [ [ 18, 59 ], [ 19, 60 ] ],
				],
				'properties' => null,
			],
		],
	];

	Functions\when( 'get_post' )->justReturn(
		preview_fake_attachment( 42, 'application/gpx+xml' )
	);
	$temp = preview_stub_meta_for_happy_path( (string) json_encode( $geojson ) );

	try {
		$controller = new Preview_Controller( new Attachment_Cache() );
		$response   = $controller->get_preview( preview_fake_request( 42 ) );

		expect( $response )->toBeInstanceOf( WP_REST_Response::class );
		$data = $response->get_data();
		expect( $data )->toHaveKey( 'geojson' );
		expect( $data['geojson'] )->toBe( $geojson );
	} finally {
		unlink( $temp );
	}

} );
