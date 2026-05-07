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
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $attributes Block attributes from post_content.
	 * @param string              $content    Inner block HTML (always empty for this block).
	 * @param \WP_Block           $block      The block instance carrying block.json metadata.
	 *
	 * @return string Escaped HTML ready for output.
	 */
	public static function render( array $attributes, string $content, \WP_Block $block ): string {

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
			return self::render_error( $payload );
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

		// Register the per-map state slice with the Interactivity API.
		wp_interactivity_state( 'kntnt-gpx-blocks', [
			$map_id => [
				'attachmentId' => $attachment_id,
				'geojson'      => $simplified_geo,
				'waypoints'    => $waypoints,
				'settings'     => [],
				'fraction'     => null,
				'consent'      => 'unknown',
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
		$style = implode( '; ', $style_parts );

		// Encode the data-wp-context payload as a JSON string.
		$context = wp_json_encode( [ 'mapId' => $map_id ] );

		// Return the Interactivity-API-annotated container element.
		return sprintf(
			'<div class="wp-block-kntnt-gpx-blocks-map kntnt-gpx-blocks-map"'
				. ' data-wp-interactive=\'{"namespace":"kntnt-gpx-blocks"}\''
				. ' data-wp-context=\'%s\''
				. ' data-wp-init="callbacks.initMap"'
				. ' style="%s">'
				. '</div>',
			esc_attr( (string) $context ),
			esc_attr( $style ),
		);

	}

	/**
	 * Renders an error notice visible only to users with edit_posts.
	 *
	 * Visitors without the capability receive an empty string; editors see a
	 * .kntnt-gpx-blocks-error notice containing the error code and message.
	 *
	 * @since 1.0.0
	 *
	 * @param Render_Error $error The error to surface.
	 *
	 * @return string
	 */
	private static function render_error( Render_Error $error ): string {

		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		return sprintf(
			'<div class="kntnt-gpx-blocks-error"><strong>%s</strong>: %s</div>',
			esc_html( $error->code ),
			esc_html( $error->message ),
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

}
