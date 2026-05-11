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
	 * Reference viewBox width in logical units. The viewBox height is derived
	 * from the editor-set aspect-ratio (or {@see DEFAULT_ASPECT_RATIO}) so that
	 * `preserveAspectRatio="xMidYMid meet"` scales the polyline uniformly into
	 * any rendered container size. Issue #93.
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private const VIEWBOX_WIDTH = 1200.0;

	/**
	 * Default chart aspect ratio (width / height) when the editor has not set
	 * an explicit `style.dimensions.aspectRatio`. Matches the SCSS baseline
	 * `aspect-ratio: 4 / 1`.
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private const DEFAULT_ASPECT_RATIO = 4.0;

	/**
	 * Internal plot padding inside the SVG, in viewBox units. The actual axis
	 * tick labels live in HTML overlay containers outside the SVG (issue #93),
	 * so the SVG itself reserves only a small inset so the polyline never
	 * sits flush against the SVG's edges. The cursor's tooltip uses these
	 * bounds too.
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private const PLOT_INSET = 8.0;

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
		// true, build_chart() pre-positions the cursor at fraction=0.5 and
		// renders it visible so the user has live feedback for the Cursor /
		// Tooltip controls without having to scrub the chart first.
		$is_editor_preview = self::is_editor_request();

		// Resolve the chart aspect ratio from the editor's `style.dimensions`
		// slot — it shapes the SVG's viewBox so uniform scaling (issue #20)
		// matches the wrapper's rendered aspect-ratio. Falls back to 4/1 when
		// the editor leaves the field empty.
		$aspect_ratio = self::resolve_aspect_ratio( $attributes );

		// Build the SVG plus its HTML axis-label overlays, wrapped in the
		// Interactivity-API-annotated container.
		$chart        = self::build_chart(
			$downsampled,
			$payload['statistics'],
			$desc_id,
			$y_min,
			$y_max,
			$aspect_ratio,
			$is_editor_preview,
		);
		$context_json = wp_json_encode( [ 'mapId' => $resolved_map_id ] );

		// Assemble the inline style with non-empty theming custom properties.
		// Dimensions (`aspect-ratio`, `min-height`) and typography (`font-family`,
		// `font-size`, etc.) are emitted by core's `dimensions` and `typography`
		// block supports — the wrapper attributes returned by
		// `get_block_wrapper_attributes()` already carry them. Empty colour
		// values fall back to the SCSS defaults; the SVG axis labels and
		// cursor-tooltip text inherit typography from the block wrapper.
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

		// Append a trailing `;` so the joined declarations always end on a
		// terminator. `get_block_wrapper_attributes()` concatenates any
		// core-supplied declarations (border, shadow, dimensions, …) onto the
		// end with a space rather than a semicolon, so the first core
		// declaration would otherwise run into this string's last declaration
		// and the CSS parser would fold it into the preceding value — the
		// canonical symptom is a square `border-top-left-radius` corner with
		// per-corner radii (issue #109).
		$style = count( $style_parts ) > 0 ? implode( '; ', $style_parts ) . ';' : '';

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
			$chart,
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
	 * Builds the SVG chart together with its HTML axis-label overlays.
	 *
	 * The SVG carries the polyline, the bottom + left frame lines, and the
	 * server-rendered cursor group; axis tick labels live in two HTML overlay
	 * `<div>` siblings of the SVG so they honour real CSS font-size from the
	 * editor's typography controls and reserve their own layout space outside
	 * the SVG's plot area (issue #93 / bugs #11, #12).
	 *
	 * The SVG's viewBox dimensions follow the editor-set aspect ratio
	 * (`style.dimensions.aspectRatio`) so that `preserveAspectRatio="xMidYMid meet"`
	 * scales the polyline uniformly into the wrapper (issue #93 / bug #20).
	 * The wrapper's stylesheet pins the SVG with `position: absolute; inset: …;`
	 * to the wrapper's plot rectangle (issue #93 / bug #21), mirroring the Map
	 * block's #86 idiom.
	 *
	 * The `$desc_id` is set on the SVG's `<desc>` child element so the SVG's
	 * `aria-labelledby` attribute can reference it — preferred over
	 * `aria-label` when the label text is already in the DOM.
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
	 * @param float                                 $aspect_ratio      Chart aspect ratio
	 *                                                                 (width / height) used
	 *                                                                 to size the viewBox.
	 * @param bool                                  $is_editor_preview Whether to render the
	 *                                                                 cursor visible at the
	 *                                                                 midpoint sample.
	 *
	 * @return string SVG markup plus its HTML axis-label overlays.
	 */
	private static function build_chart(
		array $series,
		array $statistics,
		string $desc_id,
		float $y_min,
		float $y_max,
		float $aspect_ratio,
		bool $is_editor_preview = false,
	): string {

		// The caller guarantees count($series) >= 2; walk the x-domain directly
		// so PHPStan does not have to infer non-emptiness through array_map.
		$x_min = $series[0][0];
		$x_max = $series[ count( $series ) - 1 ][0];

		// Derive the viewBox from the editor-set aspect ratio so uniform
		// scaling (preserveAspectRatio="xMidYMid meet") fills the rendered
		// box without stretching the polyline. The chart area lives outside
		// the SVG (the wrapper reserves space for HTML axis-label overlays
		// via padding-left / padding-bottom), so the plot rectangle here
		// is the full viewBox minus a small inset that keeps the line from
		// painting flush against the SVG edges.
		$viewbox_w   = self::VIEWBOX_WIDTH;
		$viewbox_h   = $aspect_ratio > 0.0 ? self::VIEWBOX_WIDTH / $aspect_ratio : self::VIEWBOX_WIDTH / self::DEFAULT_ASPECT_RATIO;
		$plot_left   = self::PLOT_INSET;
		$plot_right  = $viewbox_w - self::PLOT_INSET;
		$plot_top    = self::PLOT_INSET;
		$plot_bottom = $viewbox_h - self::PLOT_INSET;
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

		// Build the HTML axis-label overlays. These live outside the SVG so
		// the labels honour real CSS font-size and reserve their own layout
		// space (issue #93 / bugs #11, #12).
		$y_labels = self::build_y_labels( $y_min, $y_max );
		$x_labels = self::build_x_labels( $x_min, $x_max, $x_factor, $x_decim, $x_unit );

		// Compose the screen-reader summary text and escape the desc id for HTML.
		$desc    = self::build_desc( $statistics, $x_max );
		$safe_id = esc_attr( $desc_id );

		// Plot-area frame: a faint axis line on the bottom and left edges so
		// the chart has a clear baseline even when the polyline is short.
		$frame = sprintf(
			'<line class="kntnt-gpx-blocks-elevation-axis" x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke="currentColor" />'
			. '<line class="kntnt-gpx-blocks-elevation-axis" x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke="currentColor" />',
			$plot_left,
			$plot_bottom,
			$plot_right,
			$plot_bottom,
			$plot_left,
			$plot_top,
			$plot_left,
			$plot_bottom,
		);

		// Compute the cursor group's initial geometry. On the frontend the
		// group is invisible (display:none) and JS positions it on the first
		// pointermove. In the editor preview the group is rendered visible at
		// the midpoint sample of the LTTB-downsampled series so the editor
		// user sees live feedback for the Cursor / Tooltip controls without
		// having to scrub the chart first; the math mirrors view.ts's
		// `interpolateSample` + `sampleToSvg` so the server-rendered position
		// is identical to what the JS would produce for fraction=0.5.
		//
		// Tooltip size scales with the viewBox height so the tooltip stays
		// proportionate to the chart regardless of aspect ratio.
		$tooltip_width  = min( $viewbox_w * 0.15, 200.0 );
		$tooltip_height = min( $viewbox_h * 0.22, 60.0 );
		$tooltip_pad    = $viewbox_h * 0.025;
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
		$line_x      = $is_editor_preview ? sprintf( '%.2f', $preview_cx ) : '0';
		$cursor_line = sprintf(
			'<line class="kntnt-gpx-blocks-elevation-cursor-line"'
			. ' x1="%s" y1="%.2f" x2="%s" y2="%.2f" stroke="currentColor" />',
			$line_x,
			$plot_top,
			$line_x,
			$plot_bottom,
		);

		// Server-render the dot at the midpoint sample's (cx, cy) in preview
		// mode; at (0, 0) on the frontend until JS positions it.
		$dot_cx     = $is_editor_preview ? sprintf( '%.2f', $preview_cx ) : '0';
		$dot_cy     = $is_editor_preview ? sprintf( '%.2f', $preview_cy ) : '0';
		$dot_radius = max( 3.0, $viewbox_h * 0.018 );
		$cursor_dot = sprintf(
			'<circle class="kntnt-gpx-blocks-elevation-cursor-dot" cx="%s" cy="%s" r="%.2f" fill="currentColor" />',
			$dot_cx,
			$dot_cy,
			$dot_radius,
		);

		// Tooltip rect — the tooltip lives inside the SVG and scales
		// uniformly with the polyline. The tooltip <text> inherits typography
		// from the block wrapper via block-level supports.typography (issue
		// #94); editors who want differentiated styling wrap the block in a
		// Group with overridden controls.
		$rect_x              = $is_editor_preview ? sprintf( '%.2f', $preview_rect_x ) : '0';
		$cursor_tooltip_rect = sprintf(
			'<rect class="kntnt-gpx-blocks-elevation-cursor-tooltip-bg"'
			. ' x="%s" y="%.2f" width="%.2f" height="%.2f" rx="3" />',
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
		// font-size.
		$text_x              = $is_editor_preview ? sprintf( '%.2f', $preview_text_x ) : '0';
		$distance_label      = $is_editor_preview ? self::format_distance_label( $preview_distance_m ) : '';
		$elevation_label     = $is_editor_preview ? self::format_elevation_label( $preview_elevation_m ) : '';
		$cursor_tooltip_text = sprintf(
			'<text class="kntnt-gpx-blocks-elevation-cursor-tooltip-text"'
			. ' x="%s" y="%.2f" text-anchor="middle" dominant-baseline="hanging">'
			. '<tspan class="kntnt-gpx-blocks-elevation-cursor-tooltip-distance"'
			. ' x="%s" dy="0">%s</tspan>'
			. '<tspan class="kntnt-gpx-blocks-elevation-cursor-tooltip-elevation"'
			. ' x="%s" dy="1.2em">%s</tspan>'
			. '</text>',
			$text_x,
			$plot_top + $tooltip_pad,
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
		//
		// The data-plot-* attributes carry the (now dynamic) plot rectangle
		// so view.ts's pointer math agrees with the server-rendered geometry.
		// view.ts parses these via parseFloat semantics (parseInt truncates
		// the fractional part for non-default aspect ratios — acceptable
		// because the inset is small relative to the chart width).
		$style_attr   = $is_editor_preview ? '' : ' style="display:none"';
		$preview_attr = $is_editor_preview ? ' data-preview="1"' : '';
		$cursor_group = sprintf(
			'<g class="kntnt-gpx-blocks-elevation-cursor"%s%s'
			. ' data-plot-left="%.2f" data-plot-right="%.2f" data-plot-top="%.2f" data-plot-bottom="%.2f"'
			. ' data-tooltip-width="%.2f" data-tooltip-height="%.2f">%s%s%s%s</g>',
			$style_attr,
			$preview_attr,
			$plot_left,
			$plot_right,
			$plot_top,
			$plot_bottom,
			$tooltip_width,
			$tooltip_height,
			$cursor_line,
			$cursor_dot,
			$cursor_tooltip_rect,
			$cursor_tooltip_text,
		);

		// aria-labelledby references the <desc> child element, which is the
		// recommended pattern when the accessible name is already present in
		// the DOM. `preserveAspectRatio="xMidYMid meet"` keeps the polyline
		// uniformly scaled regardless of how the wrapper resolves its size
		// (issue #93 / bug #20).
		$svg = sprintf(
			'<svg class="kntnt-gpx-blocks-elevation-svg" viewBox="0 0 %.2f %.2f" role="img"'
				. ' aria-labelledby="%s" preserveAspectRatio="xMidYMid meet">'
				. '<desc id="%s">%s</desc>%s%s%s</svg>',
			$viewbox_w,
			$viewbox_h,
			$safe_id,
			$safe_id,
			esc_html( $desc ),
			$frame,
			$polyline,
			$cursor_group,
		);

		// Compose the final chart: SVG plus the two HTML axis-label overlays.
		// The overlays are sibling elements inside the wrapper; their
		// absolute positioning and sizing live in the stylesheet.
		return $svg . $y_labels . $x_labels;

	}

	/**
	 * Builds the HTML overlay for the y-axis tick labels.
	 *
	 * Each label is a child `<span>` positioned vertically via inline `top`
	 * percentage, with the topmost tick carrying the maximum value (SVG y
	 * grows downwards — the overlay's top corresponds to the chart's top).
	 * The container's stylesheet pins it next to the SVG along the left
	 * edge of the wrapper so the labels honour real CSS font-size and
	 * reserve their own layout space outside the SVG plot area.
	 *
	 * @since 1.0.0
	 *
	 * @param float $y_min Domain min (metres).
	 * @param float $y_max Domain max (metres).
	 *
	 * @return string HTML fragment for the y-axis overlay container.
	 */
	private static function build_y_labels( float $y_min, float $y_max ): string {

		$out = '';
		$div = self::TICK_COUNT - 1;

		// Walk every tick top-down — index 0 is the topmost tick (the max
		// value), placed at top: 0%; index TICK_COUNT-1 is the bottom tick
		// (the min value) at top: 100%.
		for ( $i = 0; $i < self::TICK_COUNT; $i++ ) {
			$ratio   = $i / $div;
			$value   = $y_max - ( $y_max - $y_min ) * $ratio;
			$top_pct = $ratio * 100.0;
			$label   = number_format_i18n( $value, 0 ) . ' ' . __( 'm', 'kntnt-gpx-blocks' );
			$out    .= sprintf(
				'<span class="kntnt-gpx-blocks-elevation-axis-label" style="top:%.2f%%">%s</span>',
				$top_pct,
				esc_html( $label ),
			);
		}

		return sprintf(
			'<div class="kntnt-gpx-blocks-elevation-y-labels" aria-hidden="true">%s</div>',
			$out,
		);

	}

	/**
	 * Builds the HTML overlay for the x-axis tick labels.
	 *
	 * Each label is a child `<span>` positioned horizontally via inline `left`
	 * percentage; ticks span the entire chart width with index 0 at left: 0%
	 * and the rightmost tick at left: 100%. The container's stylesheet pins
	 * it below the SVG along the bottom of the wrapper so the labels honour
	 * real CSS font-size and reserve their own layout space outside the SVG.
	 *
	 * @since 1.0.0
	 *
	 * @param float  $x_min    Domain min (metres).
	 * @param float  $x_max    Domain max (metres).
	 * @param float  $factor   Multiplier converting metres to display unit
	 *                         (1.0 or 0.001).
	 * @param int    $decimals Number of decimals for the formatted label.
	 * @param string $unit     Translated unit suffix.
	 *
	 * @return string HTML fragment for the x-axis overlay container.
	 */
	private static function build_x_labels(
		float $x_min,
		float $x_max,
		float $factor,
		int $decimals,
		string $unit,
	): string {

		$out = '';
		$div = self::TICK_COUNT - 1;
		for ( $i = 0; $i < self::TICK_COUNT; $i++ ) {
			$ratio    = $i / $div;
			$value    = $x_min + ( $x_max - $x_min ) * $ratio;
			$left_pct = $ratio * 100.0;
			$label    = number_format_i18n( $value * $factor, $decimals ) . ' ' . $unit;
			$out     .= sprintf(
				'<span class="kntnt-gpx-blocks-elevation-axis-label" style="left:%.2f%%">%s</span>',
				$left_pct,
				esc_html( $label ),
			);
		}

		return sprintf(
			'<div class="kntnt-gpx-blocks-elevation-x-labels" aria-hidden="true">%s</div>',
			$out,
		);

	}

	/**
	 * Resolves the chart aspect ratio (width / height) from block attributes.
	 *
	 * Reads `style.dimensions.aspectRatio` — the slot core's `dimensions`
	 * block supports persists into — and parses the two shapes Gutenberg
	 * emits: a CSS string like `"3/1"`, `"16/9"`, or a numeric value
	 * directly. Returns the default `4/1` baseline (matching the SCSS rule)
	 * when the attribute is missing or unparseable.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $attributes Block attributes from post_content.
	 *
	 * @return float Chart aspect ratio, always strictly positive.
	 */
	private static function resolve_aspect_ratio( array $attributes ): float {

		// Reach into the standard block-supports `style.dimensions.aspectRatio`
		// slot — the only persistence shape the dimensions panel emits.
		$style      = $attributes['style'] ?? null;
		$dimensions = is_array( $style ) ? ( $style['dimensions'] ?? null ) : null;
		$raw        = is_array( $dimensions ) ? ( $dimensions['aspectRatio'] ?? null ) : null;

		// Accept a numeric value directly. Theme JSON sometimes carries
		// aspect ratios this way for built-in tokens.
		if ( is_int( $raw ) || is_float( $raw ) ) {
			$value = (float) $raw;
			return $value > 0.0 ? $value : self::DEFAULT_ASPECT_RATIO;
		}

		// Accept the "W/H" string Gutenberg emits from the editor UI.
		if ( is_string( $raw ) && '' !== $raw ) {
			if ( preg_match( '#^\s*([0-9]+(?:\.[0-9]+)?)\s*/\s*([0-9]+(?:\.[0-9]+)?)\s*$#', $raw, $m ) ) {
				$w = (float) $m[1];
				$h = (float) $m[2];
				if ( $w > 0.0 && $h > 0.0 ) {
					return $w / $h;
				}
			}

			// Plain decimal string fallback (e.g. "1.78").
			if ( is_numeric( $raw ) ) {
				$value = (float) $raw;
				if ( $value > 0.0 ) {
					return $value;
				}
			}
		}

		return self::DEFAULT_ASPECT_RATIO;

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
	 * @param float                                 $plot_left   SVG-space plot left.
	 * @param float                                 $plot_right  SVG-space plot right.
	 * @param float                                 $plot_top    SVG-space plot top.
	 * @param float                                 $plot_bottom SVG-space plot bottom.
	 *
	 * @return array{0: float, 1: float, 2: float, 3: float}
	 *         [cx, cy, distance_m, elevation_m] for the midpoint sample.
	 */
	private static function midpoint_preview_geometry(
		array $series,
		float $y_min,
		float $y_max,
		float $plot_left,
		float $plot_right,
		float $plot_top,
		float $plot_bottom,
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
			: $plot_left;

		$y_span = $y_max > $y_min ? $y_max - $y_min : 0.0;
		$cy     = $y_span > 0.0
			? $plot_bottom - ( ( $elevation_m - $y_min ) / $y_span ) * $plot_h
			: $plot_bottom;

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

}
