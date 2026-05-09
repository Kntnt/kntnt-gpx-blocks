<?php
/**
 * REST endpoint that returns formatted GPX statistics for the editor preview.
 *
 * Backs the editor-side preview of the GPX Statistics block-variation. The
 * front-end render path uses Bindings\Statistics_Source directly — visitors
 * never touch this endpoint. The editor needs it because the bindings system
 * shows the source's `label` ("GPX statistics") whenever the bound value is
 * empty in the editor, which is unhelpful when what the builder wants to see
 * is the resolved values themselves.
 *
 * The endpoint accepts the host post id and optional mapId (defaulting to
 * 'auto'), reuses the same Resolve_Map_Id + Attachment_Cache + Value_Formatter
 * chain as Statistics_Source, and returns the five formatted values keyed by
 * binding key. The editor JS injects them into each bound paragraph as a
 * coloured preview span. The wire format also surfaces the resolved
 * attachment id and map id so the editor can label its loading/error states.
 *
 * Capability-gated to `edit_posts`. Visitors without the capability get a
 * 403 — only post authors have any reason to preview statistics.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rest;

use Kntnt\Gpx_Blocks\Cache\Attachment_Cache;
use Kntnt\Gpx_Blocks\Format\Value_Formatter;
use Kntnt\Gpx_Blocks\Plugin;
use Kntnt\Gpx_Blocks\Rendering\Render_Error;
use Kntnt\Gpx_Blocks\Rendering\Resolve_Map_Id;

/**
 * Registers and serves the editor-only statistics preview endpoint.
 *
 * Constructed once by Plugin and held there as a strong reference so the
 * array callable passed to add_action() survives the request. Has no
 * per-instance state; collaborators are constructor-injectable so the
 * controller can be unit-tested without touching register_rest_route().
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Statistics_Preview_Controller {

	/**
	 * REST namespace under which the route is registered.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const NAMESPACE = 'kntnt-gpx-blocks/v1';

	/**
	 * Allow-list of binding keys mirrored from Statistics_Source.
	 *
	 * Kept duplicated rather than imported to keep the controller free of any
	 * non-essential coupling to the bindings source. The two lists are tiny
	 * and the cache-shape contract is documented in caching.md.
	 *
	 * @since 1.0.0
	 * @var array<int, string>
	 */
	private const ALLOWED_KEYS = [
		'distance',
		'min_elevation',
		'max_elevation',
		'ascent',
		'descent',
	];

	/**
	 * Constructs the controller with its three injectable collaborators.
	 *
	 * Defaults provide ergonomic production wiring; tests inject doubles or
	 * pre-seeded instances via the same constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Attachment_Cache $cache     Cache reader used after a successful map resolution.
	 * @param Resolve_Map_Id   $resolver  Resolver that maps `'auto'` or an explicit mapId to an attachment ID.
	 * @param Value_Formatter  $formatter Locale-aware metric formatter.
	 */
	public function __construct(
		private readonly Attachment_Cache $cache = new Attachment_Cache(),
		private readonly Resolve_Map_Id $resolver = new Resolve_Map_Id(),
		private readonly Value_Formatter $formatter = new Value_Formatter(),
	) {}

	/**
	 * Registers the `statistics-preview` route on the plugin's REST namespace.
	 *
	 * Hooked on `rest_api_init`. The route accepts a positive integer postId and
	 * an optional string mapId (defaults to `'auto'`). Requires `edit_posts`.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_routes(): void {

		register_rest_route(
			self::NAMESPACE,
			'/statistics-preview',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_preview' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'postId' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'mapId'  => [
						'type'              => 'string',
						'required'          => false,
						'default'           => 'auto',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

	}

	/**
	 * Permission check — requires the WordPress `edit_posts` capability.
	 *
	 * Only post authors have any reason to preview the editor-side statistics.
	 * Defence-in-depth: the formatted values are derived from the same data
	 * that visitors already see on the rendered page, but exposing them to
	 * anonymous users would let them probe arbitrary post-id + map-id pairs.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the current user may preview statistics.
	 */
	public function check_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Returns the formatted statistics for the requested (postId, mapId) pair.
	 *
	 * Mirrors Statistics_Source's resolution chain so editor and front-end
	 * never disagree about which map answers and which numbers come back. On
	 * any error path returns a WP_Error whose code matches the Render_Error
	 * code so the editor can render an actionable hint inline.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The REST request carrying postId and optional mapId.
	 *
	 * @return \WP_REST_Response|\WP_Error Response payload or a WP_Error on failure.
	 */
	public function get_preview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {

		// Coerce and validate the post id. The route's args sanitiser narrows it
		// to a non-negative integer but its declared static type is mixed, so
		// the runtime check stays defensive.
		$raw_post_id = $request['postId'];
		$post_id     = is_numeric( $raw_post_id ) ? (int) $raw_post_id : 0;
		if ( $post_id <= 0 ) {
			$message = __( 'Invalid post id.', 'kntnt-gpx-blocks' );
			return new \WP_Error( 'invalid-post', $message, [ 'status' => 400 ] );
		}

		// Default the mapId to 'auto' for the same reasons Statistics_Source does:
		// the bindings args may omit the key entirely, and an empty string is the
		// JS-side equivalent of "no explicit choice".
		$raw_map_id = $request['mapId'];
		$map_id     = is_string( $raw_map_id ) && '' !== $raw_map_id ? $raw_map_id : 'auto';

		// Resolve which map the (postId, mapId) pair refers to. Surface the
		// resolver's error code verbatim so the editor can match it against
		// the same vocabulary the render layer uses.
		$resolved = $this->resolver->resolve( $map_id, $post_id );
		if ( $resolved instanceof Render_Error ) {
			return new \WP_Error(
				$resolved->code,
				$resolved->message,
				[ 'status' => 'no-map' === $resolved->code || 'map-not-found' === $resolved->code ? 404 : 422 ],
			);
		}

		// Read the cached statistics; cache may lazy-regenerate on stale version/hash.
		$payload = $this->cache->get( $resolved['attachment_id'] );
		if ( $payload instanceof Render_Error ) {
			Plugin::error(
				sprintf(
					'Statistics_Preview_Controller: cache error for attachment %d, code=%s',
					$resolved['attachment_id'],
					$payload->code,
				)
			);
			return new \WP_Error( $payload->code, $payload->message, [ 'status' => 422 ] );
		}

		// Format every allowed key once so the editor receives the full set
		// in a single round-trip. Null statistics (no elevation data) are
		// preserved as null so the editor can render the agreed em-dash.
		$values = [];
		foreach ( self::ALLOWED_KEYS as $key ) {
			$raw_value = $payload['statistics'][ $key ] ?? null;
			if ( null === $raw_value ) {
				$values[ $key ] = null;
				continue;
			}
			$values[ $key ] = 'distance' === $key
				? $this->formatter->format_distance( $raw_value )
				: $this->formatter->format_elevation( $raw_value );
		}

		return new \WP_REST_Response( [
			'attachmentId' => $resolved['attachment_id'],
			'mapId'        => $resolved['map_id'],
			'values'       => $values,
		] );

	}

}
