<?php
/**
 * Server-side render handler for the GPX Elevation block.
 *
 * Resolves the linked GPX Map, walks the cached LineString coordinates summing
 * Haversine distances and pairing them with the elevation values, downsamples
 * the result via LTTB, and builds the inline SVG chart with axes, the
 * elevation polyline, and a screen-reader summary <desc>. Hydrates the
 * Interactivity state with the downsampled (distance, elevation) pairs so the
 * frontend cursor-sync watch (issue #12) can read them. Cursor wiring itself
 * is out of scope for this slice.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rendering;

use Kntnt\Gpx_Blocks\Cache\Attachment_Cache;
use Kntnt\Gpx_Blocks\Format\Value_Formatter;

/**
 * Produces the frontend HTML for the GPX Elevation block.
 *
 * Called by src/blocks/elevation/render.php with the three standard block
 * render arguments. The $block parameter is typed as object (not \WP_Block)
 * so unit tests can pass lightweight test doubles without a full WordPress
 * bootstrap; WordPress always supplies a genuine \WP_Block at runtime.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Render_Elevation {

	/**
	 * Default CSS aspect-ratio when the attribute is absent or invalid.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const DEFAULT_ASPECT_RATIO = '4/1';

	/**
	 * Default CSS min-height when the attribute is absent.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const DEFAULT_MIN_HEIGHT = '120px';

	/**
	 * Default LTTB target point count when the filter returns a non-positive
	 * value.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const DEFAULT_TARGET_POINTS = 300;

	/**
	 * SVG view-box width in logical units. CSS scales the SVG to its container.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const VIEWBOX_WIDTH = 1200;

	/**
	 * SVG view-box height in logical units.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const VIEWBOX_HEIGHT = 300;

	/**
	 * Left margin (logical units) reserved for y-axis tick labels.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const MARGIN_LEFT = 56;

	/**
	 * Right margin (logical units) — small padding so the line never touches the
	 * SVG's right edge.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const MARGIN_RIGHT = 16;

	/**
	 * Top margin (logical units) — keeps the line clear of the SVG's top edge.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const MARGIN_TOP = 16;

	/**
	 * Bottom margin (logical units) reserved for x-axis tick labels.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const MARGIN_BOTTOM = 28;

	/**
	 * Number of evenly spaced tick labels on each axis.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const TICK_COUNT = 5;

	/**
	 * Distance in metres at which the x-axis switches from m to km.
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private const KILOMETRE_THRESHOLD = 2000.0;

	/**
	 * Earth radius in metres for Haversine summation. Matches Statistics_Calculator.
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private const EARTH_RADIUS_METERS = 6371000.0;

	/**
	 * Returns the rendered HTML for a single GPX Elevation block instance.
	 *
	 * Receives the same arguments WordPress passes to a dynamic render
	 * callback. Returns an empty string for visitors on any error; returns an
	 * editor-only error notice for users with edit_posts. When the track has
	 * no elevation data, returns a translated empty-state div.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $attributes Block attributes from post_content.
	 * @param string              $content    Inner block HTML (always empty).
	 * @param object              $block      Block instance carrying block.json metadata.
	 *
	 * @return string Escaped HTML ready for output.
	 */
	public static function render( array $attributes, string $content, object $block ): string {

		// Read the mapId attribute (defaults to 'auto').
		$raw_map_id = $attributes['mapId'] ?? 'auto';
		$map_id     = is_string( $raw_map_id ) && '' !== $raw_map_id ? $raw_map_id : 'auto';

		// Read and validate the layout attributes.
		$raw_ratio = $attributes['aspectRatio'] ?? '';
		$raw_mh    = $attributes['minHeight'] ?? '';

		// Validate the aspect-ratio string against the whitelist pattern from
		// docs/security.md; fall back to the default on any mismatch.
		$aspect_ratio_input = is_string( $raw_ratio ) ? $raw_ratio : '';
		$aspect_ratio       = preg_match( '/^\d+\s*\/\s*\d+$/', $aspect_ratio_input )
			? $aspect_ratio_input
			: self::DEFAULT_ASPECT_RATIO;
		$min_height = is_string( $raw_mh ) && '' !== $raw_mh ? $raw_mh : self::DEFAULT_MIN_HEIGHT;

		// Determine the host post ID for block-tree discovery.
		$context     = property_exists( $block, 'context' ) && is_array( $block->context ) ? $block->context : [];
		$raw_post_id = $context['postId'] ?? null;
		$post_id     = is_numeric( $raw_post_id ) ? (int) $raw_post_id : (int) get_the_ID();

		// Resolve the mapId to a concrete GPX Map block with an attachment.
		$resolver = new Resolve_Map_Id();
		$resolved = $resolver->resolve( $map_id, $post_id );
		if ( $resolved instanceof Render_Error ) {
			return self::render_error( $resolved );
		}

		// Fetch the cached payload for the resolved attachment.
		$cache   = new Attachment_Cache();
		$payload = $cache->get( $resolved['attachment_id'] );
		if ( $payload instanceof Render_Error ) {
			return self::render_error( $payload );
		}

		// Extract the (distance, elevation) series from the GeoJSON LineString;
		// returns an empty array when no point has elevation.
		$coordinates = self::extract_line_coordinates( $payload['geojson'] );
		$series      = self::build_distance_elevation_series( $coordinates );

		// No usable elevation in the source — render the translated empty state
		// in place of the chart.
		if ( count( $series ) < 2 ) {
			return self::render_empty_state( $aspect_ratio, $min_height );
		}

		// Downsample the series via LTTB to a configurable target point count.
		$default_target = self::DEFAULT_TARGET_POINTS;
		$target_raw     = apply_filters( 'kntnt_gpx_blocks_elevation_target_points', $default_target );
		$target         = is_int( $target_raw ) && $target_raw > 0 ? $target_raw : $default_target;
		$lttb           = new Lttb();
		$downsampled    = $lttb->downsample( $series, $target );

		// Hydrate the per-map state slice. Render_Map and Render_Elevation may
		// both target the same mapId; wp_interactivity_state merges the
		// per-call payload into the namespaced store, so we send only the
		// 'elevation' key here and leave the Map's keys alone.
		$resolved_map_id = '' !== $resolved['map_id'] ? $resolved['map_id'] : $map_id;
		wp_interactivity_state( 'kntnt-gpx-blocks', [
			$resolved_map_id => [
				'elevation' => $downsampled,
			],
		] );

		// Build the SVG and wrap it in the Interactivity-API-annotated container.
		$svg          = self::build_svg( $downsampled, $payload['statistics'] );
		$style        = sprintf(
			'--kntnt-gpx-blocks-aspect-ratio: %s; --kntnt-gpx-blocks-min-height: %s',
			$aspect_ratio,
			$min_height,
		);
		$context_json = wp_json_encode( [ 'mapId' => $resolved_map_id ] );

		return sprintf(
			'<div class="wp-block-kntnt-gpx-blocks-elevation kntnt-gpx-blocks-elevation"'
				. ' data-wp-interactive=\'{"namespace":"kntnt-gpx-blocks"}\''
				. ' data-wp-context=\'%s\''
				. ' data-wp-init="callbacks.initElevation"'
				. ' style="%s">'
				. '%s'
				. '</div>',
			esc_attr( (string) $context_json ),
			esc_attr( $style ),
			$svg,
		);

	}

	/**
	 * Returns the LineString coordinates from a cached GeoJSON FeatureCollection.
	 *
	 * Walks the features list until the first LineString is found. Returns an
	 * empty array when no LineString is present or the coordinates list is
	 * malformed.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int|string,mixed> $geojson Decoded GeoJSON FeatureCollection.
	 *
	 * @return array<int, array<int, float>>
	 */
	private static function extract_line_coordinates( array $geojson ): array {

		$features = $geojson['features'] ?? null;
		if ( ! is_array( $features ) ) {
			return [];
		}

		// Find the first LineString feature and return its coordinates list.
		foreach ( $features as $feature ) {
			if ( ! is_array( $feature ) ) {
				continue;
			}
			$geometry = $feature['geometry'] ?? null;
			if ( ! is_array( $geometry ) || 'LineString' !== ( $geometry['type'] ?? '' ) ) {
				continue;
			}
			$coords = $geometry['coordinates'] ?? null;
			if ( ! is_array( $coords ) ) {
				return [];
			}

			// Narrow each entry to a list of floats so the downstream walker
			// does not need to revalidate the shape.
			$out = [];
			foreach ( $coords as $entry ) {
				if ( ! is_array( $entry ) || count( $entry ) < 2 ) {
					continue;
				}
				$lon = $entry[0] ?? null;
				$lat = $entry[1] ?? null;
				if ( ! is_numeric( $lon ) || ! is_numeric( $lat ) ) {
					continue;
				}
				$tuple = [ (float) $lon, (float) $lat ];
				$ele   = $entry[2] ?? null;
				if ( is_numeric( $ele ) ) {
					$tuple[] = (float) $ele;
				}
				$out[] = $tuple;
			}

			return $out;
		}

		return [];

	}

	/**
	 * Walks the [lon, lat, ele?] coordinates and produces [[distance_m, ele_m], ...].
	 *
	 * Cumulative distance is summed via Haversine across every consecutive pair
	 * regardless of whether elevation is present. Only points that carry the
	 * third dimension are included in the output series — points with missing
	 * elevation are skipped while distance keeps walking the chain. Returns an
	 * empty array when no point has elevation.
	 *
	 * The pragmatic alternative (drop entries lacking elevation outright) was
	 * rejected: the GeoJSON converter linearly interpolates missing elevation
	 * when at most half the points lack it, so the in-middle missing case is
	 * rare; when it does occur (>50% missing → 2D LineString outright), this
	 * function returns an empty series and the caller renders the empty state.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<int, float>> $coords [lon, lat] or [lon, lat, ele] tuples.
	 *
	 * @return array<int, array{0: float, 1: float}>
	 */
	private static function build_distance_elevation_series( array $coords ): array {

		$series   = [];
		$distance = 0.0;
		$prev     = null;

		// Walk the coordinate chain, summing Haversine distance over every pair
		// and emitting a (distance, elevation) sample whenever elevation is set.
		foreach ( $coords as $coord ) {
			if ( null !== $prev ) {
				$distance += self::haversine_meters( $prev[1], $prev[0], $coord[1], $coord[0] );
			}
			if ( isset( $coord[2] ) ) {
				$series[] = [ $distance, $coord[2] ];
			}
			$prev = $coord;
		}

		return $series;

	}

	/**
	 * Builds the inline SVG chart with axes, polyline, and screen-reader desc.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array{0: float, 1: float}> $series     Downsampled
	 *                                                          (distance, elevation)
	 *                                                          pairs.
	 * @param array<string,float|null>              $statistics Cached statistics.
	 *
	 * @return string SVG markup.
	 */
	private static function build_svg( array $series, array $statistics ): string {

		// Compute the data domain and pad the y-range with 5% of its span so
		// the polyline never sits flush against the top or bottom of the chart.
		// The caller guarantees count($series) >= 2; walk it directly so
		// PHPStan does not have to infer non-emptiness through array_map.
		$x_min     = $series[0][0];
		$x_max     = $series[ count( $series ) - 1 ][0];
		$y_min_raw = $series[0][1];
		$y_max_raw = $series[0][1];
		foreach ( $series as $point ) {
			if ( $point[1] < $y_min_raw ) {
				$y_min_raw = $point[1];
			}
			if ( $point[1] > $y_max_raw ) {
				$y_max_raw = $point[1];
			}
		}

		$y_span_raw = $y_max_raw - $y_min_raw;
		$pad        = $y_span_raw > 0.0 ? $y_span_raw * 0.05 : 1.0;
		$y_min      = floor( $y_min_raw - $pad );
		$y_max      = ceil( $y_max_raw + $pad );
		if ( $y_max <= $y_min ) {
			$y_max = $y_min + 1.0;
		}

		// Compute the SVG plot rectangle (the inner box the line actually maps into).
		$plot_left   = self::MARGIN_LEFT;
		$plot_right  = self::VIEWBOX_WIDTH - self::MARGIN_RIGHT;
		$plot_top    = self::MARGIN_TOP;
		$plot_bottom = self::VIEWBOX_HEIGHT - self::MARGIN_BOTTOM;
		$plot_w      = $plot_right - $plot_left;
		$plot_h      = $plot_bottom - $plot_top;

		// Map every series point to SVG-space and join the resulting list as
		// the points attribute of a single <polyline>.
		$x_span = $x_max - $x_min;
		if ( $x_span <= 0.0 ) {
			$x_span = 1.0;
		}
		$y_span_svg = $y_max - $y_min;

		$svg_points = [];
		foreach ( $series as $point ) {
			$sx           = $plot_left + ( ( $point[0] - $x_min ) / $x_span ) * $plot_w;
			$sy           = $plot_bottom - ( ( $point[1] - $y_min ) / $y_span_svg ) * $plot_h;
			$svg_points[] = sprintf( '%.2f,%.2f', $sx, $sy );
		}
		$polyline = sprintf(
			'<polyline class="kntnt-gpx-blocks-elevation-line" fill="none" stroke="currentColor" points="%s" />',
			esc_attr( implode( ' ', $svg_points ) ),
		);

		// Choose km vs m for the x-axis labels based on the total distance.
		$use_km   = $x_max >= self::KILOMETRE_THRESHOLD;
		$x_unit   = $use_km ? __( 'km', 'kntnt-gpx-blocks' ) : __( 'm', 'kntnt-gpx-blocks' );
		$x_factor = $use_km ? 0.001 : 1.0;
		$x_decim  = $use_km ? 1 : 0;

		// Build axis tick groups for both axes.
		$y_ticks = self::build_y_ticks( $y_min, $y_max, $plot_top, $plot_bottom, $plot_left );
		$x_ticks = self::build_x_ticks(
			$x_min,
			$x_max,
			$plot_left,
			$plot_right,
			$plot_bottom,
			$x_factor,
			$x_decim,
			$x_unit,
		);

		// Compose the screen-reader summary.
		$desc       = self::build_desc( $statistics, $x_max );
		$aria_label = esc_attr__( 'Elevation profile', 'kntnt-gpx-blocks' );

		// Plot-area frame: a faint axis line on the bottom and left edges so
		// the chart has a clear baseline even when the polyline is short.
		$frame = sprintf(
			'<line class="kntnt-gpx-blocks-elevation-axis" x1="%d" y1="%d" x2="%d" y2="%d" stroke="currentColor" />'
			. '<line class="kntnt-gpx-blocks-elevation-axis" x1="%d" y1="%d" x2="%d" y2="%d" stroke="currentColor" />',
			$plot_left,
			$plot_bottom,
			$plot_right,
			$plot_bottom,
			$plot_left,
			$plot_top,
			$plot_left,
			$plot_bottom,
		);

		return sprintf(
			'<svg viewBox="0 0 %d %d" role="img" aria-label="%s" preserveAspectRatio="none">'
				. '<desc>%s</desc>%s%s%s%s</svg>',
			self::VIEWBOX_WIDTH,
			self::VIEWBOX_HEIGHT,
			$aria_label,
			esc_html( $desc ),
			$frame,
			$y_ticks,
			$x_ticks,
			$polyline,
		);

	}

	/**
	 * Builds the y-axis tick group: TICK_COUNT evenly spaced labels and lines.
	 *
	 * @since 1.0.0
	 *
	 * @param float $y_min       Domain min (metres).
	 * @param float $y_max       Domain max (metres).
	 * @param int   $plot_top    SVG-space top of the plot rectangle.
	 * @param int   $plot_bottom SVG-space bottom of the plot rectangle.
	 * @param int   $plot_left   SVG-space left of the plot rectangle.
	 *
	 * @return string SVG fragment.
	 */
	private static function build_y_ticks(
		float $y_min,
		float $y_max,
		int $plot_top,
		int $plot_bottom,
		int $plot_left,
	): string {

		$out = '';
		$div = self::TICK_COUNT - 1;
		for ( $i = 0; $i < self::TICK_COUNT; $i++ ) {
			$ratio = $i / $div;
			$value = $y_min + ( $y_max - $y_min ) * $ratio;

			// SVG y grows downward; the top tick has the largest data value.
			$sy = $plot_bottom - ( $plot_bottom - $plot_top ) * $ratio;

			$label = number_format_i18n( $value, 0 ) . ' ' . __( 'm', 'kntnt-gpx-blocks' );
			$out  .= sprintf(
				'<text class="kntnt-gpx-blocks-elevation-axis-label" x="%d" y="%.2f"'
				. ' text-anchor="end" dominant-baseline="middle">%s</text>',
				$plot_left - 6,
				$sy,
				esc_html( $label ),
			);
		}

		return $out;

	}

	/**
	 * Builds the x-axis tick group: TICK_COUNT evenly spaced labels.
	 *
	 * @since 1.0.0
	 *
	 * @param float  $x_min       Domain min (metres).
	 * @param float  $x_max       Domain max (metres).
	 * @param int    $plot_left   SVG-space left of the plot rectangle.
	 * @param int    $plot_right  SVG-space right of the plot rectangle.
	 * @param int    $plot_bottom SVG-space bottom of the plot rectangle.
	 * @param float  $factor      Multiplier converting metres to display unit (1.0 or 0.001).
	 * @param int    $decimals    Number of decimals for the formatted label.
	 * @param string $unit        Translated unit suffix.
	 *
	 * @return string SVG fragment.
	 */
	private static function build_x_ticks(
		float $x_min,
		float $x_max,
		int $plot_left,
		int $plot_right,
		int $plot_bottom,
		float $factor,
		int $decimals,
		string $unit,
	): string {

		$out = '';
		$div = self::TICK_COUNT - 1;
		for ( $i = 0; $i < self::TICK_COUNT; $i++ ) {
			$ratio = $i / $div;
			$value = $x_min + ( $x_max - $x_min ) * $ratio;
			$sx    = $plot_left + ( $plot_right - $plot_left ) * $ratio;

			$label = number_format_i18n( $value * $factor, $decimals ) . ' ' . $unit;
			$out  .= sprintf(
				'<text class="kntnt-gpx-blocks-elevation-axis-label" x="%.2f" y="%d"'
				. ' text-anchor="middle" dominant-baseline="hanging">%s</text>',
				$sx,
				$plot_bottom + 8,
				esc_html( $label ),
			);
		}

		return $out;

	}

	/**
	 * Builds the screen-reader summary string.
	 *
	 * Shape: "Elevation profile from {min} m at the start to {max} m after
	 * {distance}, with total ascent {ascent} m and descent {descent} m." The
	 * placeholders are filled from the cached statistics; the distance string
	 * is delegated to Value_Formatter for the m/km switch.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,float|null> $statistics Cached statistics.
	 * @param float                    $total_dist Total distance (m) from the series.
	 *
	 * @return string Plain-text summary suitable for esc_html().
	 */
	private static function build_desc( array $statistics, float $total_dist ): string {

		$formatter = new Value_Formatter();

		$min      = $formatter->format_elevation( (float) ( $statistics['min_elevation'] ?? 0.0 ) );
		$max      = $formatter->format_elevation( (float) ( $statistics['max_elevation'] ?? 0.0 ) );
		$ascent   = $formatter->format_elevation( (float) ( $statistics['ascent'] ?? 0.0 ) );
		$descent  = $formatter->format_elevation( (float) ( $statistics['descent'] ?? 0.0 ) );
		$distance = $formatter->format_distance( (float) ( $statistics['distance'] ?? $total_dist ) );

		// translators: 1: min elevation, 2: max elevation, 3: distance, 4: ascent, 5: descent.
		$template = __( 'Elevation profile from %1$s at the start to %2$s after %3$s, with total ascent %4$s and descent %5$s.', 'kntnt-gpx-blocks' ); // phpcs:ignore Generic.Files.LineLength.TooLong -- Translator strings must be a single literal per WordPress.WP.I18n; splitting is not permitted.

		return sprintf( $template, $min, $max, $distance, $ascent, $descent );

	}

	/**
	 * Renders the empty-state container shown when the track has no elevation.
	 *
	 * @since 1.0.0
	 *
	 * @param string $aspect_ratio Validated aspect ratio.
	 * @param string $min_height   Validated min-height.
	 *
	 * @return string
	 */
	private static function render_empty_state( string $aspect_ratio, string $min_height ): string {

		$style = sprintf(
			'--kntnt-gpx-blocks-aspect-ratio: %s; --kntnt-gpx-blocks-min-height: %s',
			$aspect_ratio,
			$min_height,
		);

		return sprintf(
			'<div class="wp-block-kntnt-gpx-blocks-elevation kntnt-gpx-blocks-elevation'
				. ' kntnt-gpx-blocks-elevation--empty" style="%s">%s</div>',
			esc_attr( $style ),
			esc_html__( 'No elevation data in this GPX file.', 'kntnt-gpx-blocks' ),
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
	 * Great-circle distance between two lat/lon pairs in metres.
	 *
	 * Matches Statistics_Calculator's Haversine formula (Earth radius
	 * 6371000 m) so the cumulative distances on the chart agree exactly with
	 * the total distance reported by the Statistics block.
	 *
	 * @since 1.0.0
	 *
	 * @param float $lat1 Latitude of point 1, decimal degrees.
	 * @param float $lon1 Longitude of point 1, decimal degrees.
	 * @param float $lat2 Latitude of point 2, decimal degrees.
	 * @param float $lon2 Longitude of point 2, decimal degrees.
	 *
	 * @return float
	 */
	private static function haversine_meters( float $lat1, float $lon1, float $lat2, float $lon2 ): float {

		$phi1     = deg2rad( $lat1 );
		$phi2     = deg2rad( $lat2 );
		$d_phi    = deg2rad( $lat2 - $lat1 );
		$d_lambda = deg2rad( $lon2 - $lon1 );

		$a = sin( $d_phi / 2 ) ** 2
			+ cos( $phi1 ) * cos( $phi2 ) * sin( $d_lambda / 2 ) ** 2;

		return self::EARTH_RADIUS_METERS * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

	}

}
