<?php
/**
 * Server-side render handler for the GPX Map block.
 *
 * Reads the attachment's cached GeoJSON, applies Douglas-Peucker simplification,
 * hydrates the WordPress Interactivity API state, and returns the HTML container
 * the frontend view module will mount the Leaflet map into.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rendering;

use Kntnt\Gpx_Blocks\Cache\Attachment_Cache;
use Kntnt\Gpx_Blocks\Conversion\Distance;
use Kntnt\Gpx_Blocks\Plugin;

/**
 * Produces the frontend HTML for the GPX Map block.
 *
 * Called by src/blocks/map/render.php with the three standard block render
 * arguments.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Render_Map {

	/**
	 * Default CSS aspect-ratio when the attribute is absent or invalid.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const DEFAULT_ASPECT_RATIO = '16/9';

	/**
	 * Default CSS min-height when the attribute is absent.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const DEFAULT_MIN_HEIGHT = '240px';

	/**
	 * Default Douglas-Peucker tolerance in metres when the filter returns
	 * a non-numeric value.
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private const DEFAULT_SIMPLIFICATION_METERS = 5.0;

	/**
	 * Returns the rendered HTML for a single GPX Map block instance.
	 *
	 * Receives the same arguments that WordPress passes to a dynamic block
	 * render callback. Returns an empty string when the block is not yet
	 * configured (attachmentId === 0) — the editor's MediaPlaceholder handles
	 * that state. Returns an error notice to editors on any cache failure.
	 *
	 * The render path performs no consent gating server-side. Rendering the
	 * container, embedding the GeoJSON in wp_interactivity_state(), and drawing
	 * the polyline on a canvas are not third-party requests and do not require
	 * consent. Tile loading is the only consent-requiring action; it is gated
	 * client-side by the contract documented in docs/consent.md. The PHP filter
	 * `kntnt_gpx_blocks_has_consent` exists for builders who want server-side
	 * consent introspection from their own code, but Render_Map itself does not
	 * consult it.
	 *
	 * Editor render context is detected (REST `block-renderer` requests with
	 * `edit_posts` capability) and surfaced as `bypassConsent` in the per-map
	 * state slice so the JS view module mounts Leaflet immediately, regardless
	 * of consent state.
	 *
	 * @since 1.0.0
	 *
	 * The $block parameter is typed as object (not \WP_Block) so unit tests can
	 * pass anonymous objects. WordPress always supplies a genuine \WP_Block at
	 * runtime. Render_Map does not read any property on $block.
	 *
	 * @param array<string,mixed> $attributes Block attributes from post_content.
	 * @param string              $content    Inner block HTML (always empty for this block).
	 * @param object              $block      The block instance carrying block.json metadata.
	 *
	 * @return string Escaped HTML ready for output.
	 */
	public static function render( array $attributes, string $content, object $block ): string {

		// Read the identity attributes and coerce to the expected primitive types.
		$raw_id        = $attributes['attachmentId'] ?? 0;
		$attachment_id = is_numeric( $raw_id ) ? (int) $raw_id : 0;
		$raw_map_id    = $attributes['mapId'] ?? '';
		$map_id        = is_string( $raw_map_id ) && '' !== $raw_map_id ? $raw_map_id : 'map-default';

		// Read and validate layout attributes.
		$raw_ratio    = $attributes['aspectRatio'] ?? '';
		$raw_mh       = $attributes['minHeight'] ?? '';
		$raw_maxh     = $attributes['maxHeight'] ?? '';
		$min_height   = is_string( $raw_mh ) && '' !== $raw_mh ? $raw_mh : self::DEFAULT_MIN_HEIGHT;
		$max_height   = is_string( $raw_maxh ) ? $raw_maxh : '';

		// Read the four control-overlay flags; coerce to bool with documented defaults.
		$show_zoom_buttons = isset( $attributes['showZoomButtons'] ) ? (bool) $attributes['showZoomButtons'] : true;
		$show_scale        = isset( $attributes['showScale'] ) ? (bool) $attributes['showScale'] : true;
		$show_fullscreen   = isset( $attributes['showFullscreen'] ) ? (bool) $attributes['showFullscreen'] : false;
		$show_download     = isset( $attributes['showDownload'] ) ? (bool) $attributes['showDownload'] : false;

		// Read the four interaction flags; coerce to bool with documented defaults.
		// Scroll-wheel and box-zoom flags are intentionally absent — the view
		// module replaces them with a fixed wheel-handler (see view.ts) and
		// drops box zoom altogether.
		$enable_drag  = isset( $attributes['enableDrag'] ) ? (bool) $attributes['enableDrag'] : true;
		$enable_pinch_zoom  = isset( $attributes['enablePinchZoom'] ) ? (bool) $attributes['enablePinchZoom'] : true;
		$raw_dclk           = $attributes['enableDoubleClickZoom'] ?? null;
		$enable_double_click_zoom = isset( $raw_dclk ) ? (bool) $raw_dclk : true;
		$enable_keyboard    = isset( $attributes['enableKeyboard'] ) ? (bool) $attributes['enableKeyboard'] : true;

		// Read and sanitize the two track colour attributes.
		$track_color        = self::sanitize_color( $attributes['trackColor'] ?? '' );
		$track_cursor_color = self::sanitize_color( $attributes['trackCursorColor'] ?? '' );

		// Read and sanitize the seven waypoint styling attributes.
		$waypoint_color          = self::sanitize_color( $attributes['waypointColor'] ?? '' );
		$waypoint_label_bg       = self::sanitize_color( $attributes['waypointLabelBackground'] ?? '' );
		$waypoint_label_color    = self::sanitize_color( $attributes['waypointLabelColor'] ?? '' );
		$waypoint_label_family   = self::sanitize_font_family( $attributes['waypointLabelFontFamily'] ?? '' );
		$waypoint_label_size     = self::sanitize_font_size( $attributes['waypointLabelFontSize'] ?? '' );
		$waypoint_label_weight   = self::sanitize_font_weight( $attributes['waypointLabelFontWeight'] ?? '' );
		$waypoint_label_style    = self::sanitize_font_style( $attributes['waypointLabelFontStyle'] ?? '' );

		// Validate the aspect-ratio string against the whitelist pattern from
		// docs/security.md; fall back to the default on any mismatch.
		$aspect_ratio_input = is_string( $raw_ratio ) ? $raw_ratio : '';
		$aspect_ratio       = preg_match( '/^\d+\s*\/\s*\d+$/', $aspect_ratio_input )
			? $aspect_ratio_input
			: self::DEFAULT_ASPECT_RATIO;

		// No attachment configured yet — the editor shows MediaPlaceholder instead.
		if ( $attachment_id <= 0 ) {
			return '';
		}

		// Fetch the cached payload; cache regenerates automatically when stale.
		$cache   = new Attachment_Cache();
		$payload = $cache->get( $attachment_id );

		// Surface cache errors to editors only; visitors see nothing.
		if ( $payload instanceof Render_Error ) {
			Plugin::error(
				sprintf( 'Render_Map: error rendering for attachment %d, code=%s', $attachment_id, $payload->code )
			);
			return ( new Error_Renderer() )->render( $payload );
		}

		// $payload matches the documented shape; extract the GeoJSON array.
		$geojson_full = $payload['geojson'];

		// Build the simplified track from the full-fidelity GeoJSON LineString.
		$track_points = self::extract_track_points( $geojson_full );
		$waypoints    = self::extract_waypoints( $geojson_full );

		// Read the tolerance filter value once and coerce to float.
		$default_tol   = self::DEFAULT_SIMPLIFICATION_METERS;
		$tolerance_raw = apply_filters( 'kntnt_gpx_blocks_track_simplification_meters', $default_tol );
		$tolerance     = is_numeric( $tolerance_raw ) ? (float) $tolerance_raw : $default_tol;

		// Apply Douglas-Peucker to reduce the number of rendered vertices.
		$simplifier     = new Douglas_Peucker();
		$simplified     = $simplifier->simplify( $track_points, $tolerance );
		$simplified_geo = self::points_to_geojson( $simplified );

		// Compute the cumulative-distance array along the *original* full-fidelity
		// track and pick the value at each surviving simplified vertex. The
		// frontend cursor sync uses this to map a fraction-of-total-distance
		// back to a position between two simplified vertices, so the cursor
		// glides along the rendered polyline at the same physical point that
		// Statistics and Elevation refer to.
		$track_cum_dist = self::cumulative_for_simplified( $track_points, $simplified );

		// Total distance comes from the cache so all three blocks agree on the
		// canonical figure. Falls back to 0.0 only when the cache lacks it,
		// which is impossible given the cache contract.
		$raw_total      = $payload['statistics']['distance'] ?? 0.0;
		$total_distance = is_numeric( $raw_total ) ? (float) $raw_total : 0.0;

		// Resolve the original GPX file URL for the download control; null when unavailable.
		$gpx_file_url = wp_get_attachment_url( $attachment_id );
		$gpx_file_url = $gpx_file_url !== false ? $gpx_file_url : null;

		// Detect the editor render context. The REST block-renderer endpoint
		// invokes the render callback inside a REST request; gating on
		// `edit_posts` excludes anonymous REST callers from the bypass. The JS
		// view module reads this flag to mount Leaflet immediately when the
		// editor preview is being rendered, irrespective of consent state.
		$bypass_consent = defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_posts' );

		// Register the per-map state slice with the Interactivity API. The plugin
		// performs no consent-requiring action server-side; the JS view module
		// gates tile loading client-side via window.kntnt_gpx_blocks (see
		// docs/consent.md). The state carries no consent values — the only
		// consent-related field is bypassConsent for the editor preview.
		wp_interactivity_state( 'kntnt-gpx-blocks', [
			$map_id => [
				'attachmentId'  => $attachment_id,
				'geojson'       => $simplified_geo,
				'trackCumDist'  => $track_cum_dist,
				'totalDistance' => $total_distance,
				'waypoints'     => $waypoints,
				'gpxFileUrl'    => $gpx_file_url,
				'settings'      => [
					'showZoomButtons'       => $show_zoom_buttons,
					'showScale'             => $show_scale,
					'showFullscreen'        => $show_fullscreen,
					'showDownload'          => $show_download,
					'enableDrag'            => $enable_drag,
					'enablePinchZoom'       => $enable_pinch_zoom,
					'enableDoubleClickZoom' => $enable_double_click_zoom,
					'enableKeyboard'        => $enable_keyboard,
				],
				'fraction'      => null,
				'scrollHint'    => [
					// Translators: shown over the map when a mouse user scrolls
					// without holding the modifier key. ⌘ is the Mac Command
					// glyph; the string is rendered verbatim by the view module.
					'apple' => __( 'Hold ⌘ + scroll to zoom the map', 'kntnt-gpx-blocks' ),
					// Translators: shown over the map when a non-Apple user
					// scrolls without holding the Ctrl modifier.
					'other' => __( 'Hold Ctrl + scroll to zoom the map', 'kntnt-gpx-blocks' ),
				],
				'bypassConsent' => $bypass_consent,
			],
		] );

		// Build the inline style string from the validated layout attributes.
		// Dimensions are expressed as CSS custom properties so the SCSS picks them
		// up via var() — keeping the PHP render callback as the single source of
		// truth without duplicating the CSS logic.
		// $min_height is always non-empty (falls back to DEFAULT_MIN_HEIGHT).
		$style_parts = [
			'--kntnt-gpx-blocks-aspect-ratio: ' . esc_attr( $aspect_ratio ),
			'--kntnt-gpx-blocks-min-height: ' . esc_attr( $min_height ),
		];
		if ( '' !== $max_height ) {
			$style_parts[] = 'max-height: ' . esc_attr( $max_height );
		}

		// Append CSS custom properties for track and cursor colours when set.
		// Empty strings fall back to the hardcoded defaults in style.scss.
		if ( '' !== $track_color ) {
			$style_parts[] = '--kntnt-gpx-blocks-track-color: ' . esc_attr( $track_color );
		}
		if ( '' !== $track_cursor_color ) {
			$style_parts[] = '--kntnt-gpx-blocks-track-cursor-color: ' . esc_attr( $track_cursor_color );
		}

		// Append a CSS custom property for each non-empty, validated waypoint
		// styling attribute. Empty strings fall back to the SCSS-defined defaults.
		if ( '' !== $waypoint_color ) {
			$style_parts[] = '--kntnt-gpx-blocks-waypoint-color: ' . esc_attr( $waypoint_color );
		}
		if ( '' !== $waypoint_label_bg ) {
			$style_parts[] = '--kntnt-gpx-blocks-waypoint-label-bg: ' . esc_attr( $waypoint_label_bg );
		}
		if ( '' !== $waypoint_label_color ) {
			$style_parts[] = '--kntnt-gpx-blocks-waypoint-label-color: ' . esc_attr( $waypoint_label_color );
		}
		if ( '' !== $waypoint_label_family ) {
			$style_parts[] = '--kntnt-gpx-blocks-waypoint-label-font-family: ' . esc_attr( $waypoint_label_family );
		}
		if ( '' !== $waypoint_label_size ) {
			$style_parts[] = '--kntnt-gpx-blocks-waypoint-label-font-size: ' . esc_attr( $waypoint_label_size );
		}
		if ( '' !== $waypoint_label_weight ) {
			$style_parts[] = '--kntnt-gpx-blocks-waypoint-label-font-weight: ' . esc_attr( $waypoint_label_weight );
		}
		if ( '' !== $waypoint_label_style ) {
			$style_parts[] = '--kntnt-gpx-blocks-waypoint-label-font-style: ' . esc_attr( $waypoint_label_style );
		}

		$style = implode( '; ', $style_parts );

		// Encode the data-wp-context payload as a JSON string.
		$context = wp_json_encode( [ 'mapId' => $map_id ] );

		// Translate the ARIA label and the noscript fallback string.
		$aria_label = esc_attr__( 'Map of GPX track', 'kntnt-gpx-blocks' );
		// phpcs:ignore Generic.Files.LineLength.TooLong -- Translator strings must be a single literal per WordPress.WP.I18n; splitting is not permitted.
		$noscript_text = esc_html__( 'This map requires JavaScript to display. The track is recorded in the GPX file referenced by this block.', 'kntnt-gpx-blocks' );

		// Return the block element. Leaflet mounts directly into this wrapper —
		// the wrapper has explicit width / aspect-ratio / min-height via inline
		// style, so Leaflet always sees a correctly sized container.
		// role="application" and aria-label expose the interactive map to assistive
		// technology. <noscript> is shown only when JS is disabled.
		// data-wp-init bootstraps the block. The suffixed data-wp-watch directive
		// reacts to cursor-marker updates from sibling Elevation blocks. Consent
		// transitions are handled inside initMap via the JS contract documented
		// in docs/consent.md, not via a Watch directive.
		// No plugin-supplied placeholder, button, or consent UI is rendered —
		// the active consent-management plugin owns the visitor-facing UX.
		return sprintf(
			'<div class="wp-block-kntnt-gpx-blocks-map kntnt-gpx-blocks-map"'
				. ' role="application"'
				. ' aria-label="%1$s"'
				. ' data-wp-interactive=\'{"namespace":"kntnt-gpx-blocks"}\''
				. ' data-wp-context=\'%2$s\''
				. ' data-wp-init="callbacks.initMap"'
				. ' data-wp-watch--cursor="callbacks.onMapCursorChange"'
				. ' style="%3$s">'
				. '<noscript><p class="kntnt-gpx-blocks-map-noscript">%4$s</p></noscript>'
				. '</div>',
			$aria_label,
			esc_attr( (string) $context ),
			esc_attr( $style ),
			$noscript_text,
		);

	}

	/**
	 * Extracts [lat, lon, ele?] working points from a GeoJSON FeatureCollection.
	 *
	 * Finds the first LineString feature and converts its coordinates from
	 * GeoJSON [lon, lat, ele?] order to the [lat, lon, ele?] order that
	 * Douglas_Peucker expects. Returns an empty array when no LineString is found.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int|string,mixed> $geojson Decoded GeoJSON FeatureCollection.
	 *
	 * @return array<int, array{ 0: float, 1: float, 2?: float }>
	 */
	private static function extract_track_points( array $geojson ): array {

		$features = $geojson['features'] ?? null;
		if ( ! is_array( $features ) ) {
			return [];
		}

		// Walk features until the LineString is found.
		foreach ( $features as $feature ) {
			if ( ! is_array( $feature ) ) {
				continue;
			}

			// Narrow geometry to an array before keying into it.
			$geometry = $feature['geometry'] ?? null;
			if ( ! is_array( $geometry ) ) {
				continue;
			}

			$geom_type = $geometry['type'] ?? '';
			if ( 'LineString' !== $geom_type ) {
				continue;
			}

			$raw_coords = $geometry['coordinates'] ?? null;
			if ( ! is_array( $raw_coords ) ) {
				return [];
			}

			// Convert GeoJSON [lon, lat, ele?] → [lat, lon, ele?] for the simplifier.
			$points = [];
			foreach ( $raw_coords as $raw_coord ) {
				if ( ! is_array( $raw_coord ) || count( $raw_coord ) < 2 ) {
					continue;
				}
				$lon = $raw_coord[0] ?? null;
				$lat = $raw_coord[1] ?? null;
				if ( ! is_numeric( $lon ) || ! is_numeric( $lat ) ) {
					continue;
				}
				$point = [ (float) $lat, (float) $lon ];
				$ele   = $raw_coord[2] ?? null;
				if ( is_numeric( $ele ) ) {
					$point[] = (float) $ele;
				}
				$points[] = $point;
			}

			return $points;
		}

		return [];

	}

	/**
	 * Picks the original-track cumulative distance for each surviving simplified vertex.
	 *
	 * Walks the simplified vertices in source order and advances a monotone
	 * cursor through the original track until each vertex's `[lat, lon]`
	 * matches. The output has the same length as `$simplified`: index 0 is
	 * always 0.0 (the simplifier preserves the start point) and the final
	 * index is the total distance walked along the original track.
	 *
	 * Returns an empty array when either input is empty.
	 *
	 * @since 1.0.0
	 *
	 * @param float[][] $original   Original [lat, lon, ele?] track points;
	 *                              trailing dimensions are ignored.
	 * @param float[][] $simplified Simplified [lat, lon, ele?] vertices in
	 *                              source order.
	 *
	 * @return array<int, float>
	 */
	private static function cumulative_for_simplified( array $original, array $simplified ): array {

		if ( count( $original ) === 0 || count( $simplified ) === 0 ) {
			return [];
		}

		// Cumulative Haversine distance over every original-track vertex.
		$original_cum = Distance::cumulative( $original );

		// Walk both arrays in lockstep — Douglas-Peucker preserves source order
		// and references the same lat/lon values, so a monotone cursor matches
		// each surviving vertex against the originals exactly once.
		$out        = [];
		$cursor     = 0;
		$count_orig = count( $original );
		foreach ( $simplified as $vertex ) {
			while ( $cursor < $count_orig
				&& ( $original[ $cursor ][0] !== $vertex[0]
					|| $original[ $cursor ][1] !== $vertex[1] ) ) {
				++$cursor;
			}

			// Should not be reachable when $simplified is a true subsequence of
			// $original; defensive fallback keeps the parallel-array contract.
			if ( $cursor >= $count_orig ) {
				$out[] = $original_cum[ $count_orig - 1 ] ?? 0.0;
				continue;
			}

			$out[] = $original_cum[ $cursor ];
			++$cursor;
		}

		return $out;

	}

	/**
	 * Extracts Point features from a GeoJSON FeatureCollection as a waypoint set.
	 *
	 * Returns a GeoJSON FeatureCollection containing only the Point features,
	 * preserving their properties (name, desc, etc.) intact for the frontend.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int|string,mixed> $geojson Decoded GeoJSON FeatureCollection.
	 *
	 * @return array<string,mixed> GeoJSON FeatureCollection of Point features.
	 */
	private static function extract_waypoints( array $geojson ): array {

		$waypoints = [];
		$features  = $geojson['features'] ?? null;

		if ( is_array( $features ) ) {
			foreach ( $features as $feature ) {
				if ( ! is_array( $feature ) ) {
					continue;
				}

				// Narrow geometry before reading its type.
				$geometry = $feature['geometry'] ?? null;
				if ( ! is_array( $geometry ) ) {
					continue;
				}
				if ( 'Point' === ( $geometry['type'] ?? '' ) ) {
					$waypoints[] = $feature;
				}
			}
		}

		return [
			'type'     => 'FeatureCollection',
			'features' => $waypoints,
		];

	}

	/**
	 * Converts a [lat, lon, ele?] point array back to a GeoJSON FeatureCollection
	 * with a single LineString feature in [lon, lat, ele?] coordinate order.
	 *
	 * @since 1.0.0
	 *
	 * @param float[][] $points [lat, lon, ele?] simplified points.
	 *
	 * @return array<string,mixed> GeoJSON FeatureCollection with one LineString.
	 */
	private static function points_to_geojson( array $points ): array {

		// Convert [lat, lon, ele?] → GeoJSON [lon, lat, ele?].
		$coords = [];
		foreach ( $points as $point ) {
			$coord = [ $point[1], $point[0] ];
			if ( isset( $point[2] ) ) {
				$coord[] = $point[2];
			}
			$coords[] = $coord;
		}

		return [
			'type'     => 'FeatureCollection',
			'features' => [
				[
					'type'       => 'Feature',
					'geometry'   => [
						'type'        => 'LineString',
						'coordinates' => $coords,
					],
					'properties' => null,
				],
			],
		];

	}

	/**
	 * Validates and returns a hex colour string, or empty string on invalid input.
	 *
	 * Accepts hex colours (#rgb, #rrggbb, #rrggbbaa) via sanitize_hex_color.
	 * Returns empty string for blank input so the CSS falls back to the
	 * hardcoded default in style.scss rather than emitting a broken value.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Raw attribute value.
	 *
	 * @return string Validated hex colour or empty string.
	 */
	private static function sanitize_color( mixed $raw ): string {

		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		// sanitize_hex_color returns null on failure; coerce to empty string.
		$clean = sanitize_hex_color( $raw );
		return is_string( $clean ) ? $clean : '';

	}

	/**
	 * Validates a CSS font-family value against a strict whitelist.
	 *
	 * Accepts common font name strings and theme-preset CSS variable references.
	 * Returns empty string on anything that could inject CSS or HTML.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Raw attribute value.
	 *
	 * @return string Validated font-family string or empty string.
	 */
	private static function sanitize_font_family( mixed $raw ): string {

		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		// Accept a CSS theme-preset font-family reference.
		if ( preg_match( '/^var\(--wp--preset--font-family--[a-z0-9-]+\)$/', $raw ) ) {
			return $raw;
		}

		// Accept font family names composed of letters, digits, spaces, commas,
		// hyphens, quotes, and parentheses — the characters that appear in valid
		// CSS font-family stacks.
		if ( preg_match( "/^[A-Za-z0-9\\s,\\-'\"()]+$/", $raw ) ) {
			return $raw;
		}

		return '';

	}

	/**
	 * Validates a CSS font-size value against a strict whitelist.
	 *
	 * Accepts numeric CSS length values (px, em, rem, %) and theme-preset
	 * font-size references. Returns empty string on anything unsafe.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Raw attribute value.
	 *
	 * @return string Validated font-size string or empty string.
	 */
	private static function sanitize_font_size( mixed $raw ): string {

		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		// Accept a CSS theme-preset font-size reference.
		if ( preg_match( '/^var\(--wp--preset--font-size--[a-z0-9-]+\)$/', $raw ) ) {
			return $raw;
		}

		// Accept numeric lengths: optional decimal followed by a CSS length unit.
		if ( preg_match( '/^(\d+(\.\d+)?)(px|em|rem|%)?$/', $raw ) ) {
			return $raw;
		}

		return '';

	}

	/**
	 * Validates a CSS font-weight value against the accepted keyword/numeric whitelist.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Raw attribute value.
	 *
	 * @return string Validated font-weight string or empty string.
	 */
	private static function sanitize_font_weight( mixed $raw ): string {

		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		if ( preg_match( '/^(normal|bold|lighter|bolder|[1-9]00)$/', $raw ) ) {
			return $raw;
		}

		return '';

	}

	/**
	 * Validates a CSS font-style value against the accepted keyword whitelist.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Raw attribute value.
	 *
	 * @return string Validated font-style string or empty string.
	 */
	private static function sanitize_font_style( mixed $raw ): string {

		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		if ( preg_match( '/^(normal|italic|oblique)$/', $raw ) ) {
			return $raw;
		}

		return '';

	}

}
