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

		// Read and sanitize the six colour attributes through the shared
		// validator — accepts hex 3/4/6/8 (alpha-aware) and rejects anything else.
		$axis_color        = Color_Sanitizer::sanitize( $attributes['axisColor'] ?? '' );
		$axis_label_color  = Color_Sanitizer::sanitize( $attributes['axisLabelColor'] ?? '' );
		$line_color        = Color_Sanitizer::sanitize( $attributes['lineColor'] ?? '' );
		$cursor_color      = Color_Sanitizer::sanitize( $attributes['cursorColor'] ?? '' );
		$tooltip_bg        = Color_Sanitizer::sanitize( $attributes['tooltipBackground'] ?? '' );
		$tooltip_color     = Color_Sanitizer::sanitize( $attributes['tooltipColor'] ?? '' );

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
		// In the editor, ServerSideRender sends the live block tree as
		// __editorBlockSnapshot — registered with role:local in block.json so
		// it is never serialised to post_content. Prefer it when present so the
		// preview reflects the user's current state instead of the last save.
		// The non-empty check is load-bearing: WordPress fills missing
		// attributes with their block.json default at render time, so a
		// frontend page rendered for a logged-in editor would otherwise see
		// the snapshot as []  — pass the empty array to resolve_from_blocks
		// and a configured Map in saved post_content gets reported as no-map.
		// The current_user_can guard is defence-in-depth on top of the REST
		// block-renderer's own edit_posts check.
		$resolver     = new Resolve_Map_Id();
		$raw_snapshot = $attributes['__editorBlockSnapshot'] ?? null;
		if ( is_array( $raw_snapshot ) && count( $raw_snapshot ) > 0 && current_user_can( 'edit_posts' ) ) {
			$resolved = $resolver->resolve_from_blocks( $map_id, $raw_snapshot );
		} else {
			$resolved = $resolver->resolve( $map_id, $post_id );
		}
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
			return self::render_empty_state();
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

		// Detect the editor render context. The REST block-renderer endpoint
		// invokes the render callback inside a REST request; gating on
		// `edit_posts` excludes anonymous REST callers from the bypass. When
		// true, build_svg() pre-positions the cursor at fraction=0.5 and renders
		// it visible so the user has live feedback for the Cursor / Tooltip
		// controls without having to scrub the chart first.
		$is_editor_preview = self::is_editor_request();

		// Build the SVG and wrap it in the Interactivity-API-annotated container.
		$svg          = self::build_svg(
			$downsampled,
			$payload['statistics'],
			$desc_id,
			$y_min,
			$y_max,
			$is_editor_preview,
		);
		$context_json = wp_json_encode( [ 'mapId' => $resolved_map_id ] );

		// Assemble the inline style with non-empty theming custom properties.
		// Dimensions (`aspect-ratio`, `min-height`) are emitted by core's
		// `dimensions` block supports — the wrapper attributes returned by
		// `get_block_wrapper_attributes()` already carry them. Empty colour /
		// typography values fall back to the SCSS defaults.
		$style_parts = [];

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

		// Build the block wrapper attributes via core's helper so that editor-UI
		// affordances (HTML anchor, additional CSS class, theme-supplied
		// alignwide/alignfull, the dimensions / border / shadow / spacing block
		// supports, third-party render_block_data filters) reach the frontend.
		// The wp-block-kntnt-gpx-blocks-elevation class is supplied by core
		// from block.json and need not be repeated here.
		$wrapper_args = [ 'class' => 'kntnt-gpx-blocks-elevation' ];
		if ( '' !== $style ) {
			$wrapper_args['style'] = $style;
		}
		$wrapper = get_block_wrapper_attributes( $wrapper_args );

		return sprintf(
			'<div %1$s'
				. ' data-wp-interactive=\'{"namespace":"kntnt-gpx-blocks"}\''
				. ' data-wp-context=\'%2$s\''
				. ' data-wp-init="callbacks.initElevation"'
				. ' data-wp-watch="callbacks.onElevationCursorChange">'
				. '%3$s'
				. '<noscript><p class="kntnt-gpx-blocks-elevation-noscript">%4$s</p></noscript>'
				. '</div>',
			$wrapper,
			esc_attr( (string) $context_json ),
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
	 * Detects an editor render context.
	 *
	 * The REST block-renderer endpoint invokes dynamic render callbacks inside
	 * a REST request; gating on `edit_posts` excludes anonymous REST callers
	 * from the bypass. Matches the idiom Render_Map uses for its
	 * `bypassConsent` flag so both blocks detect editor previews identically.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when called from the editor's block-renderer.
	 */
	private static function is_editor_request(): bool {
		return defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_posts' );
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
	 * When `$is_editor_preview` is true, the cursor group is server-rendered
	 * visible at fraction=0.5 with the corresponding (distance, elevation)
	 * sample shown in the tooltip. The editor user can then see live feedback
	 * for the Cursor / Tooltip controls without having to scrub the chart
	 * first. On the frontend (false), the cursor keeps `style="display:none"`
	 * and `view.ts` reveals it on the first pointermove.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array{0: float, 1: float}> $series            Downsampled
	 *                                                                 (distance, elevation)
	 *                                                                 pairs.
	 * @param array<string,float|null>              $statistics        Cached statistics.
	 * @param string                                $desc_id           HTML id for the <desc>
	 *                                                                 element; referenced via
	 *                                                                 aria-labelledby on the svg.
	 * @param float                                 $y_min             Padded y-axis lower bound.
	 * @param float                                 $y_max             Padded y-axis upper bound.
	 * @param bool                                  $is_editor_preview Whether to render the
	 *                                                                 cursor visible at the
	 *                                                                 midpoint sample.
	 *
	 * @return string SVG markup.
	 */
	private static function build_svg(
		array $series,
		array $statistics,
		string $desc_id,
		float $y_min,
		float $y_max,
		bool $is_editor_preview = false,
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

		// Compute the cursor group's initial geometry. On the frontend the group
		// is invisible (display:none) and JS positions it on the first
		// pointermove. In the editor preview the group is rendered visible at
		// the midpoint sample of the LTTB-downsampled series so the editor user
		// sees live feedback for the Cursor / Tooltip controls without having
		// to scrub the chart first; the math mirrors view.ts's
		// `interpolateSample` + `sampleToSvg` so the server-rendered position
		// is identical to what the JS would produce for fraction=0.5.
		$tooltip_width  = 130;
		$tooltip_height = 50;
		[ $preview_cx, $preview_cy, $preview_distance_m, $preview_elevation_m ] =
			self::midpoint_preview_geometry(
				$series,
				$y_min,
				$y_max,
				$plot_left,
				$plot_right,
				$plot_top,
				$plot_bottom,
			);
		$preview_rect_x = max(
			$plot_left,
			min( $plot_right - $tooltip_width, $preview_cx - $tooltip_width / 2 ),
		);
		$preview_text_x = $preview_rect_x + $tooltip_width / 2;

		// Server-render the cursor line. In editor preview mode `x1`/`x2` are
		// anchored at the midpoint sample's x; on the frontend they start at 0
		// and JS updates them on every pointermove.
		$line_x = $is_editor_preview ? sprintf( '%.2f', $preview_cx ) : '0';
		$cursor_line = sprintf(
			'<line class="kntnt-gpx-blocks-elevation-cursor-line"'
			. ' x1="%s" y1="%d" x2="%s" y2="%d" stroke="currentColor" />',
			$line_x,
			$plot_top,
			$line_x,
			$plot_bottom,
		);

		// Server-render the dot at the midpoint sample's (cx, cy) in preview
		// mode; at (0, 0) on the frontend until JS positions it.
		$dot_cx = $is_editor_preview ? sprintf( '%.2f', $preview_cx ) : '0';
		$dot_cy = $is_editor_preview ? sprintf( '%.2f', $preview_cy ) : '0';
		$cursor_dot = sprintf(
			'<circle class="kntnt-gpx-blocks-elevation-cursor-dot" cx="%s" cy="%s" r="5" fill="currentColor" />',
			$dot_cx,
			$dot_cy,
		);

		// Tooltip rect sized for two text rows at the SCSS default font-size
		// (16 viewBox units): ~8 padding-top + ~16 line-height + ~3 line-gap
		// + ~16 line-height + ~7 padding-bottom = 50 units. Width is bumped
		// to 130 units so longer formatted distances ("32.4 km") still fit
		// comfortably with the larger default font.
		$rect_x = $is_editor_preview ? sprintf( '%.2f', $preview_rect_x ) : '0';
		$cursor_tooltip_rect = sprintf(
			'<rect class="kntnt-gpx-blocks-elevation-cursor-tooltip-bg"'
			. ' x="%s" y="%d" width="%d" height="%d" rx="3" />',
			$rect_x,
			$plot_top,
			$tooltip_width,
			$tooltip_height,
		);

		// Two-line tooltip text built as a parent <text> with two <tspan>
		// children: one for the distance row, one for the elevation row.
		// JS sets the textContent of each tspan and re-points their `x`
		// attributes to the rect's horizontal centre on every cursor update.
		// `dominant-baseline="hanging"` anchors the first row to the text
		// element's `y`; `dy="1.2em"` on the second tspan offsets it onto
		// the next line in proportion to the (possibly user-overridden)
		// font-size. In editor preview mode the tspans are pre-filled with
		// the midpoint sample's formatted distance and elevation; the same
		// formatter helpers run in `view.ts::formatDistance` and
		// `formatElevation`, kept in sync verbatim.
		$text_x          = $is_editor_preview ? sprintf( '%.2f', $preview_text_x ) : '0';
		$distance_label  = $is_editor_preview ? self::format_distance_label( $preview_distance_m ) : '';
		$elevation_label = $is_editor_preview ? self::format_elevation_label( $preview_elevation_m ) : '';
		$cursor_tooltip_text = sprintf(
			'<text class="kntnt-gpx-blocks-elevation-cursor-tooltip-text"'
			. ' x="%s" y="%d" text-anchor="middle" dominant-baseline="hanging">'
			. '<tspan class="kntnt-gpx-blocks-elevation-cursor-tooltip-distance"'
			. ' x="%s" dy="0">%s</tspan>'
			. '<tspan class="kntnt-gpx-blocks-elevation-cursor-tooltip-elevation"'
			. ' x="%s" dy="1.2em">%s</tspan>'
			. '</text>',
			$text_x,
			$plot_top + 8,
			$text_x,
			esc_html( $distance_label ),
			$text_x,
			esc_html( $elevation_label ),
		);

		// In editor preview mode the cursor group is rendered visible and
		// flagged with `data-preview="1"` so view.ts's watch callback knows
		// not to hide it on the initial mount-time fire (when `fraction` is
		// undefined). The first real fraction update — from the user scrubbing
		// the chart, or from a sibling Map block — clears the data attribute
		// and the cursor follows live state from that point on.
		$style_attr   = $is_editor_preview ? '' : ' style="display:none"';
		$preview_attr = $is_editor_preview ? ' data-preview="1"' : '';
		$cursor_group = sprintf(
			'<g class="kntnt-gpx-blocks-elevation-cursor"%s%s'
			. ' data-plot-left="%d" data-plot-right="%d" data-plot-top="%d" data-plot-bottom="%d">%s%s%s%s</g>',
			$style_attr,
			$preview_attr,
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
	 * Computes the SVG-space cursor position and tooltip values at fraction=0.5.
	 *
	 * Mirrors `view.ts::interpolateSample` + `sampleToSvg` so the
	 * server-rendered preview matches what JS would produce for fraction=0.5
	 * once the user starts scrubbing. The caller guarantees
	 * `count($series) >= 2`, so the midpoint sample always resolves; the
	 * `count($series) === 1` branch is defensive in case a future refactor
	 * loosens that contract.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array{0: float, 1: float}> $series      Downsampled
	 *                                                           (distance, elevation)
	 *                                                           pairs.
	 * @param float                                 $y_min       Padded y-axis lower bound.
	 * @param float                                 $y_max       Padded y-axis upper bound.
	 * @param int                                   $plot_left   SVG-space plot left.
	 * @param int                                   $plot_right  SVG-space plot right.
	 * @param int                                   $plot_top    SVG-space plot top.
	 * @param int                                   $plot_bottom SVG-space plot bottom.
	 *
	 * @return array{0: float, 1: float, 2: float, 3: float}
	 *         [cx, cy, distance_m, elevation_m] for the midpoint sample.
	 */
	private static function midpoint_preview_geometry(
		array $series,
		float $y_min,
		float $y_max,
		int $plot_left,
		int $plot_right,
		int $plot_top,
		int $plot_bottom,
	): array {

		// Resolve the midpoint sample by linearly interpolating along the
		// LTTB-downsampled distance array — same binary-search-and-lerp shape
		// as view.ts's `interpolateSample` so server and client agree.
		$count = count( $series );
		if ( $count === 1 ) {
			$distance_m  = $series[0][0];
			$elevation_m = $series[0][1];
		} else {
			$total_distance = $series[ $count - 1 ][0];
			$target         = 0.5 * $total_distance;

			$i = self::lower_bound_index( $series, $target );
			$j = min( $i + 1, $count - 1 );
			$a = $series[ $i ];
			$b = $series[ $j ];

			$span         = $b[0] - $a[0];
			$t            = $span > 0.0 ? ( $target - $a[0] ) / $span : 0.0;
			$distance_m   = $a[0] + ( $b[0] - $a[0] ) * $t;
			$elevation_m  = $a[1] + ( $b[1] - $a[1] ) * $t;
		}

		// Project the sample into SVG space using the padded y bounds — the
		// same ones the polyline was drawn with — so the preview cursor sits
		// exactly on the rendered curve.
		$plot_w = $plot_right - $plot_left;
		$plot_h = $plot_bottom - $plot_top;

		$total_distance = $series[ $count - 1 ][0];
		$cx             = $total_distance > 0.0
			? $plot_left + ( $distance_m / $total_distance ) * $plot_w
			: (float) $plot_left;

		$y_span = $y_max > $y_min ? $y_max - $y_min : 0.0;
		$cy     = $y_span > 0.0
			? $plot_bottom - ( ( $elevation_m - $y_min ) / $y_span ) * $plot_h
			: (float) $plot_bottom;

		return [ $cx, $cy, $distance_m, $elevation_m ];

	}

	/**
	 * Largest index `i` such that `$series[i][0] <= $target`.
	 *
	 * Plain binary search over the monotone non-decreasing distance column —
	 * the LTTB output preserves source order. Mirrors view.ts's
	 * `lowerBoundIndex` so the server-rendered preview's bracketing matches
	 * the client at fraction=0.5.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array{0: float, 1: float}> $series Downsampled samples.
	 * @param float                                 $target Distance value to bracket.
	 *
	 * @return int Predecessor index.
	 */
	private static function lower_bound_index( array $series, float $target ): int {

		$count = count( $series );
		if ( $count === 0 ) {
			return 0;
		}

		$lo = 0;
		$hi = $count - 1;
		if ( $target <= $series[0][0] ) {
			return 0;
		}
		if ( $target >= $series[ $hi ][0] ) {
			return $hi;
		}

		while ( $lo + 1 < $hi ) {
			$mid = (int) floor( ( $lo + $hi ) / 2 );
			if ( $series[ $mid ][0] <= $target ) {
				$lo = $mid;
			} else {
				$hi = $mid;
			}
		}

		return $lo;

	}

	/**
	 * Formats a distance value for the editor-preview tooltip's first row.
	 *
	 * Switches from metres to kilometres at the 1000 m threshold so the
	 * server-rendered string matches what view.ts's `formatDistance` would
	 * produce for the same sample. Kilometres carry one decimal; metres are
	 * rounded to the nearest whole number. The label is intentionally locale-
	 * neutral (raw "." decimal) so it agrees with the JS output byte-for-byte
	 * — the same decision view.ts makes.
	 *
	 * @since 1.0.0
	 *
	 * @param float $distance_m Distance in metres.
	 *
	 * @return string Formatted label, e.g. "3.2 km" or "245 m".
	 */
	private static function format_distance_label( float $distance_m ): string {

		if ( $distance_m >= 1000.0 ) {
			return number_format( $distance_m / 1000.0, 1, '.', '' ) . ' km';
		}

		return (string) (int) round( $distance_m ) . ' m';

	}

	/**
	 * Formats an elevation value for the editor-preview tooltip's second row.
	 *
	 * Always rendered in metres rounded to the nearest whole number — matching
	 * view.ts's `formatElevation`.
	 *
	 * @since 1.0.0
	 *
	 * @param float $elevation_m Elevation in metres.
	 *
	 * @return string Formatted label, e.g. "245 m".
	 */
	private static function format_elevation_label( float $elevation_m ): string {
		return (string) (int) round( $elevation_m ) . ' m';
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
	 * Dimensions (`aspect-ratio`, `min-height`) are emitted by core's
	 * `dimensions` block supports and reach the wrapper through
	 * `get_block_wrapper_attributes()`. The empty-state container has no
	 * background of its own — editors who want one wrap the block in a
	 * `core/group` and use that block's standard background-colour control.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private static function render_empty_state(): string {

		// Build the block wrapper attributes via core's helper so that editor-UI
		// affordances and the dimensions/border/shadow/spacing block supports
		// reach the frontend even on the empty-data path.
		$wrapper = get_block_wrapper_attributes(
			[ 'class' => 'kntnt-gpx-blocks-elevation kntnt-gpx-blocks-elevation--empty' ],
		);

		return sprintf(
			'<div %s>%s</div>',
			$wrapper,
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
