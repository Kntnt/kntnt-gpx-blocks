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
use Kntnt\Gpx_Blocks\Plugin;

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

		// Read and sanitize the seven colour attributes.
		$background_color  = self::sanitize_color( $attributes['backgroundColor'] ?? '' );
		$axis_color        = self::sanitize_color( $attributes['axisColor'] ?? '' );
		$axis_label_color  = self::sanitize_color( $attributes['axisLabelColor'] ?? '' );
		$line_color        = self::sanitize_color( $attributes['lineColor'] ?? '' );
		$cursor_color      = self::sanitize_color( $attributes['cursorColor'] ?? '' );
		$tooltip_bg        = self::sanitize_color( $attributes['tooltipBackground'] ?? '' );
		$tooltip_color     = self::sanitize_color( $attributes['tooltipColor'] ?? '' );

		// Read and sanitize the eight typography attributes (axis and tooltip).
		$axis_font_family   = self::sanitize_font_family( $attributes['axisFontFamily'] ?? '' );
		$axis_font_size     = self::sanitize_font_size( $attributes['axisFontSize'] ?? '' );
		$axis_font_weight   = self::sanitize_font_weight( $attributes['axisFontWeight'] ?? '' );
		$axis_font_style    = self::sanitize_font_style( $attributes['axisFontStyle'] ?? '' );
		$tooltip_font_family = self::sanitize_font_family( $attributes['tooltipFontFamily'] ?? '' );
		$tooltip_font_size   = self::sanitize_font_size( $attributes['tooltipFontSize'] ?? '' );
		$tooltip_font_weight = self::sanitize_font_weight( $attributes['tooltipFontWeight'] ?? '' );
		$tooltip_font_style  = self::sanitize_font_style( $attributes['tooltipFontStyle'] ?? '' );

		// Determine the host post ID for block-tree discovery.
		$context     = property_exists( $block, 'context' ) && is_array( $block->context ) ? $block->context : [];
		$raw_post_id = $context['postId'] ?? null;
		$post_id     = is_numeric( $raw_post_id ) ? (int) $raw_post_id : (int) get_the_ID();

		// Resolve the mapId to a concrete GPX Map block with an attachment.
		$resolver = new Resolve_Map_Id();
		$resolved = $resolver->resolve( $map_id, $post_id );
		if ( $resolved instanceof Render_Error ) {
			Plugin::error(
				sprintf( 'Render_Elevation: error resolving map (post %d), code=%s', $post_id, $resolved->code )
			);
			return ( new Error_Renderer() )->render( $resolved );
		}

		// Fetch the cached payload for the resolved attachment.
		$cache   = new Attachment_Cache();
		$payload = $cache->get( $resolved['attachment_id'] );
		if ( $payload instanceof Render_Error ) {
			Plugin::error( sprintf(
				'Render_Elevation: error rendering for attachment %d, code=%s',
				$resolved['attachment_id'],
				$payload->code,
			) );
			return ( new Error_Renderer() )->render( $payload );
		}

		// Extract the (distance, elevation) series from the GeoJSON LineString;
		// returns an empty array when no point has elevation.
		$coordinates = self::extract_line_coordinates( $payload['geojson'] );
		$series      = self::build_distance_elevation_series( $coordinates );

		// No usable elevation in the source — render the translated empty state
		// in place of the chart.
		if ( count( $series ) < 2 ) {
			return self::render_empty_state(
				$aspect_ratio,
				$min_height,
				$background_color,
			);
		}

		// Downsample the series via LTTB to a configurable target point count.
		$default_target = self::DEFAULT_TARGET_POINTS;
		$target_raw     = apply_filters( 'kntnt_gpx_blocks_elevation_target_points', $default_target );
		$target         = is_int( $target_raw ) && $target_raw > 0 ? $target_raw : $default_target;
		$lttb           = new Lttb();
		$downsampled    = $lttb->downsample( $series, $target );

		// Compute the padded y-range used for the polyline. The same bounds
		// are sent to JS so the cursor sits exactly on the rendered polyline
		// rather than on the LTTB raw min/max range.
		[ $y_min, $y_max ] = self::padded_y_bounds( $downsampled );

		// Hydrate the per-map state slice. Render_Map and Render_Elevation may
		// both target the same mapId; wp_interactivity_state merges the
		// per-call payload into the namespaced store, so we send only the
		// 'elevation' key here and leave the Map's keys alone.
		$resolved_map_id = '' !== $resolved['map_id'] ? $resolved['map_id'] : $map_id;
		wp_interactivity_state( 'kntnt-gpx-blocks', [
			$resolved_map_id => [
				'elevation' => $downsampled,
				'yMin'      => $y_min,
				'yMax'      => $y_max,
			],
		] );

		// Generate a unique id for the SVG <desc> so aria-labelledby can reference it.
		// The id is scoped to this render call; multiple elevation blocks on one page
		// each get their own distinct suffix derived from the resolved map id.
		$desc_id = 'kntnt-gpx-blocks-elevation-desc-' . esc_attr( $resolved_map_id );

		// Build the SVG and wrap it in the Interactivity-API-annotated container.
		$svg          = self::build_svg( $downsampled, $payload['statistics'], $desc_id, $y_min, $y_max );
		$context_json = wp_json_encode( [ 'mapId' => $resolved_map_id ] );

		// Assemble the inline style: layout dimensions first, then any non-empty
		// theming custom properties. Empty values fall back to the SCSS defaults.
		$style_parts = [
			'--kntnt-gpx-blocks-aspect-ratio: ' . $aspect_ratio,
			'--kntnt-gpx-blocks-min-height: ' . $min_height,
		];

		if ( '' !== $background_color ) {
			$style_parts[] = '--kntnt-gpx-blocks-background-color: ' . $background_color;
		}
		if ( '' !== $axis_color ) {
			$style_parts[] = '--kntnt-gpx-blocks-axis-color: ' . $axis_color;
		}
		if ( '' !== $axis_label_color ) {
			$style_parts[] = '--kntnt-gpx-blocks-axis-label-color: ' . $axis_label_color;
		}
		if ( '' !== $line_color ) {
			$style_parts[] = '--kntnt-gpx-blocks-line-color: ' . $line_color;
		}
		if ( '' !== $cursor_color ) {
			$style_parts[] = '--kntnt-gpx-blocks-cursor-color: ' . $cursor_color;
		}
		if ( '' !== $tooltip_bg ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-background: ' . $tooltip_bg;
		}
		if ( '' !== $tooltip_color ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-color: ' . $tooltip_color;
		}
		if ( '' !== $axis_font_family ) {
			$style_parts[] = '--kntnt-gpx-blocks-axis-font-family: ' . $axis_font_family;
		}
		if ( '' !== $axis_font_size ) {
			$style_parts[] = '--kntnt-gpx-blocks-axis-font-size: ' . $axis_font_size;
		}
		if ( '' !== $axis_font_weight ) {
			$style_parts[] = '--kntnt-gpx-blocks-axis-font-weight: ' . $axis_font_weight;
		}
		if ( '' !== $axis_font_style ) {
			$style_parts[] = '--kntnt-gpx-blocks-axis-font-style: ' . $axis_font_style;
		}
		if ( '' !== $tooltip_font_family ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-font-family: ' . $tooltip_font_family;
		}
		if ( '' !== $tooltip_font_size ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-font-size: ' . $tooltip_font_size;
		}
		if ( '' !== $tooltip_font_weight ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-font-weight: ' . $tooltip_font_weight;
		}
		if ( '' !== $tooltip_font_style ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-font-style: ' . $tooltip_font_style;
		}

		$style = implode( '; ', $style_parts );

		// Build the noscript summary — same text as the SVG <desc> so non-JS visitors
		// and screen readers both get the same description.
		$noscript_text = self::build_desc( $payload['statistics'], (float) ( end( $downsampled )[0] ?? 0.0 ) );

		return sprintf(
			'<div class="wp-block-kntnt-gpx-blocks-elevation kntnt-gpx-blocks-elevation"'
				. ' data-wp-interactive=\'{"namespace":"kntnt-gpx-blocks"}\''
				. ' data-wp-context=\'%1$s\''
				. ' data-wp-init="callbacks.initElevation"'
				. ' data-wp-watch="callbacks.onElevationCursorChange"'
				. ' style="%2$s">'
				. '%3$s'
				. '<noscript><p class="kntnt-gpx-blocks-elevation-noscript">%4$s</p></noscript>'
				. '</div>',
			esc_attr( (string) $context_json ),
			esc_attr( $style ),
			$svg,
			esc_html( $noscript_text ),
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
	 * Returns the padded `[y_min, y_max]` bounds used to render the polyline.
	 *
	 * Pads the raw min/max by 5 % of the span (1 m when the span is zero) so
	 * the line never sits flush against the chart edges, then snaps the bounds
	 * outward via `floor` / `ceil` so the y-axis ticks land on whole metres.
	 * The same bounds are sent to JS so the cursor placement uses an identical
	 * scale.
	 *
	 * The caller must pass at least two points; an empty input returns
	 * `[ 0.0, 1.0 ]` defensively.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array{0: float, 1: float}> $series Downsampled
	 *                                                      (distance, elevation)
	 *                                                      pairs.
	 *
	 * @return array{0: float, 1: float}
	 */
	private static function padded_y_bounds( array $series ): array {

		if ( count( $series ) === 0 ) {
			return [ 0.0, 1.0 ];
		}

		// Find the raw min and max via a direct walk; cheaper than array_map
		// and easier for PHPStan to type.
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

		// Pad by 5 % of the span (1 m for a flat track) and snap outward to
		// whole metres so the rendered tick labels are integer-valued.
		$y_span_raw = $y_max_raw - $y_min_raw;
		$pad        = $y_span_raw > 0.0 ? $y_span_raw * 0.05 : 1.0;
		$y_min      = floor( $y_min_raw - $pad );
		$y_max      = ceil( $y_max_raw + $pad );
		if ( $y_max <= $y_min ) {
			$y_max = $y_min + 1.0;
		}

		return [ $y_min, $y_max ];

	}

	/**
	 * Builds the inline SVG chart with axes, polyline, and screen-reader desc.
	 *
	 * The `$desc_id` is set on the `<desc>` element so the SVG's `aria-labelledby`
	 * attribute can reference it. Using `aria-labelledby` is preferred over
	 * `aria-label` when the label text is already present in the document as a
	 * child element — it avoids duplication and keeps the source of truth in one
	 * place.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array{0: float, 1: float}> $series     Downsampled
	 *                                                          (distance, elevation)
	 *                                                          pairs.
	 * @param array<string,float|null>              $statistics Cached statistics.
	 * @param string                                $desc_id    HTML id for the <desc>
	 *                                                          element; referenced via
	 *                                                          aria-labelledby on the svg.
	 * @param float                                 $y_min      Padded y-axis lower bound.
	 * @param float                                 $y_max      Padded y-axis upper bound.
	 *
	 * @return string SVG markup.
	 */
	private static function build_svg(
		array $series,
		array $statistics,
		string $desc_id,
		float $y_min,
		float $y_max,
	): string {

		// The caller guarantees count($series) >= 2; walk the x-domain directly
		// so PHPStan does not have to infer non-emptiness through array_map.
		$x_min = $series[0][0];
		$x_max = $series[ count( $series ) - 1 ][0];

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

		// Compose the screen-reader summary text and escape the desc id for HTML.
		$desc    = self::build_desc( $statistics, $x_max );
		$safe_id = esc_attr( $desc_id );

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

		// Server-render the cursor group so view.ts only needs to toggle visibility
		// and update attributes — no element creation at runtime.
		$cursor_line = sprintf(
			'<line class="kntnt-gpx-blocks-elevation-cursor-line"'
			. ' x1="0" y1="%d" x2="0" y2="%d" stroke="currentColor" />',
			$plot_top,
			$plot_bottom,
		);
		$cursor_dot = sprintf(
			'<circle class="kntnt-gpx-blocks-elevation-cursor-dot" cx="0" cy="0" r="5" fill="currentColor" />',
		);
		// Tooltip rect sized for two text rows at the SCSS default font-size
		// (16 viewBox units): ~8 padding-top + ~16 line-height + ~3 line-gap
		// + ~16 line-height + ~7 padding-bottom = 50 units. Width is bumped
		// to 130 units so longer formatted distances ("32.4 km") still fit
		// comfortably with the larger default font.
		$cursor_tooltip_rect = sprintf(
			'<rect class="kntnt-gpx-blocks-elevation-cursor-tooltip-bg"'
			. ' x="0" y="%d" width="130" height="50" rx="3" />',
			$plot_top,
		);

		// Two-line tooltip text built as a parent <text> with two <tspan>
		// children: one for the distance row, one for the elevation row.
		// JS sets the textContent of each tspan and re-points their `x`
		// attributes to the rect's horizontal centre on every cursor update.
		// `dominant-baseline="hanging"` anchors the first row to the text
		// element's `y`; `dy="1.2em"` on the second tspan offsets it onto
		// the next line in proportion to the (possibly user-overridden)
		// font-size.
		$cursor_tooltip_text = sprintf(
			'<text class="kntnt-gpx-blocks-elevation-cursor-tooltip-text"'
			. ' x="0" y="%d" text-anchor="middle" dominant-baseline="hanging">'
			. '<tspan class="kntnt-gpx-blocks-elevation-cursor-tooltip-distance"'
			. ' x="0" dy="0"></tspan>'
			. '<tspan class="kntnt-gpx-blocks-elevation-cursor-tooltip-elevation"'
			. ' x="0" dy="1.2em"></tspan>'
			. '</text>',
			$plot_top + 8,
		);
		$cursor_group = sprintf(
			'<g class="kntnt-gpx-blocks-elevation-cursor" style="display:none"'
			. ' data-plot-left="%d" data-plot-right="%d" data-plot-top="%d" data-plot-bottom="%d">%s%s%s%s</g>',
			$plot_left,
			$plot_right,
			$plot_top,
			$plot_bottom,
			$cursor_line,
			$cursor_dot,
			$cursor_tooltip_rect,
			$cursor_tooltip_text,
		);

		// aria-labelledby references the <desc> child element, which is the
		// recommended pattern when the accessible name is already present in the DOM.
		return sprintf(
			'<svg viewBox="0 0 %d %d" role="img" aria-labelledby="%s" preserveAspectRatio="none">'
				. '<desc id="%s">%s</desc>%s%s%s%s%s</svg>',
			self::VIEWBOX_WIDTH,
			self::VIEWBOX_HEIGHT,
			$safe_id,
			$safe_id,
			esc_html( $desc ),
			$frame,
			$y_ticks,
			$x_ticks,
			$polyline,
			$cursor_group,
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
	 * @param string $aspect_ratio      Validated aspect ratio.
	 * @param string $min_height        Validated min-height.
	 * @param string $background_color  Validated background colour (may be empty).
	 *
	 * @return string
	 */
	private static function render_empty_state(
		string $aspect_ratio,
		string $min_height,
		string $background_color = '',
	): string {

		$style_parts = [
			'--kntnt-gpx-blocks-aspect-ratio: ' . $aspect_ratio,
			'--kntnt-gpx-blocks-min-height: ' . $min_height,
		];
		if ( '' !== $background_color ) {
			$style_parts[] = '--kntnt-gpx-blocks-background-color: ' . $background_color;
		}
		$style = implode( '; ', $style_parts );

		return sprintf(
			'<div class="wp-block-kntnt-gpx-blocks-elevation kntnt-gpx-blocks-elevation'
				. ' kntnt-gpx-blocks-elevation--empty" style="%s">%s</div>',
			esc_attr( $style ),
			esc_html__( 'No elevation data in this GPX file.', 'kntnt-gpx-blocks' ),
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

	// ─── Attribute sanitizers ────────────────────────────────────────────────

	/**
	 * Validates and returns a hex colour string, or empty string on invalid input.
	 *
	 * Accepts hex colours (#rgb, #rrggbb) via sanitize_hex_color. Returns empty
	 * string for blank input so the CSS falls back to the hardcoded default in
	 * style.scss rather than emitting a broken value.
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
