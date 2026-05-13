<?php
/**
 * REST endpoint that returns cached preview data for a GPX attachment.
 *
 * Backs the GPX Map block's editor-side React preview. The frontend never
 * touches this endpoint — it reads its data from `wp_interactivity_state()`
 * which is hydrated server-side at render time. The editor cannot rely on
 * the same path because the Interactivity API runtime does not bootstrap
 * inside ServerSideRender's injected DOM, so the editor fetches the cached
 * GeoJSON via REST and mounts Leaflet directly through React.
 *
 * Capability-gated to `edit_posts`. Visitors without the capability get a
 * 403 — they have no business previewing GPX files in the first place, and
 * the public-facing path uses Interactivity hydration.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rest;

use Kntnt\Gpx_Blocks\Cache\Attachment_Cache;
use Kntnt\Gpx_Blocks\Plugin;
use Kntnt\Gpx_Blocks\Rendering\Elevation_Samples;
use Kntnt\Gpx_Blocks\Rendering\Render_Error;

/**
 * Registers and serves the editor-only preview endpoint.
 *
 * Constructed once by Plugin and bound to rest_api_init. Has no per-instance
 * state beyond the injected cache.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Preview_Controller {

	/**
	 * REST namespace under which the route is registered.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const NAMESPACE = 'kntnt-gpx-blocks/v1';

	/**
	 * Constructs the controller with its cache dependency.
	 *
	 * The cache is held as a promoted readonly property so tests can substitute
	 * a mock at construction time and production code cannot mutate the field
	 * after the controller is wired.
	 *
	 * @since 1.0.0
	 *
	 * @param Attachment_Cache $cache The shared attachment cache instance.
	 */
	public function __construct( private readonly Attachment_Cache $cache ) {}

	/**
	 * Registers the `preview/<id>` route on the plugin's REST namespace.
	 *
	 * Hooked on `rest_api_init`. The route accepts a positive integer
	 * attachment id, requires `edit_posts`, and returns the cached
	 * GeoJSON FeatureCollection for the editor to mount into Leaflet.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_routes(): void {

		register_rest_route(
			self::NAMESPACE,
			'/preview/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_preview' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

	}

	/**
	 * Permission check — requires the WordPress `edit_posts` capability.
	 *
	 * The endpoint exposes the cached GeoJSON for an attachment, which is
	 * already public via the rendered map block. The capability gate is
	 * defence-in-depth: only users who can author posts have any reason
	 * to preview GPX attachments in the editor.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the current user may preview attachments.
	 */
	public function check_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Returns the cached preview payload for the requested attachment.
	 *
	 * Validates that the id refers to an existing attachment with the GPX
	 * MIME type, then reads the cache and returns the GeoJSON. Cache errors
	 * (no-track, parse-failed, file-missing, etc.) bubble up as REST errors
	 * with the same code so the editor can render an inline notice.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The REST request carrying the `id` URL param.
	 *
	 * @return \WP_REST_Response|\WP_Error Response payload or a WP_Error on failure.
	 */
	public function get_preview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {

		// Coerce and validate the attachment id. Returned by the REST router
		// as the matched URL param; the route's args sanitizer narrows it to
		// a non-negative integer but its declared static type is mixed.
		$raw_id        = $request['id'];
		$attachment_id = is_numeric( $raw_id ) ? (int) $raw_id : 0;
		if ( $attachment_id <= 0 ) {
			$message = __( 'Invalid attachment id.', 'kntnt-gpx-blocks' );
			return new \WP_Error( 'no-attachment', $message, [ 'status' => 400 ] );
		}

		// Verify the post exists and is an attachment with the GPX MIME type.
		$post = get_post( $attachment_id );
		if ( null === $post || 'attachment' !== $post->post_type ) {
			$message = __( 'Attachment not found.', 'kntnt-gpx-blocks' );
			return new \WP_Error( 'no-attachment', $message, [ 'status' => 404 ] );
		}
		if ( 'application/gpx+xml' !== $post->post_mime_type ) {
			$message = __( 'Attachment is not a GPX file.', 'kntnt-gpx-blocks' );
			return new \WP_Error( 'wrong-mime', $message, [ 'status' => 400 ] );
		}

		// Read the cached payload; the cache regenerates lazily when stale.
		$payload = $this->cache->get( $attachment_id );
		if ( $payload instanceof Render_Error ) {
			Plugin::error(
				sprintf( 'Preview_Controller: cache error for attachment %d, code=%s', $attachment_id, $payload->code )
			);
			return new \WP_Error( $payload->code, $payload->message, [ 'status' => 422 ] );
		}

		// Return the GeoJSON FeatureCollection, the cached statistics
		// array, and the LTTB-downsampled (distance, elevation)
		// samples Step 5 needs for the editor preview's elevation
		// curve. The samples array is computed by the shared
		// `Elevation_Samples` helper so editor and frontend receive a
		// byte-identical payload for the same attachment.
		$target_raw = apply_filters(
			'kntnt_gpx_blocks_elevation_target_points',
			Elevation_Samples::DEFAULT_TARGET
		);
		$target  = is_int( $target_raw ) && $target_raw > 0
			? $target_raw
			: Elevation_Samples::DEFAULT_TARGET;
		$geojson = is_array( $payload['geojson'] ?? null ) ? $payload['geojson'] : [];
		$samples = Elevation_Samples::compute( $geojson, $target );

		return new \WP_REST_Response( [
			'geojson'    => $payload['geojson'],
			'statistics' => $payload['statistics'],
			'samples'    => $samples,
		] );

	}

}
