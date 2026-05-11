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
use Kntnt\Gpx_Blocks\Conversion\Distance;
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
	 * from the editor-set aspect-ratio (or {@see DEFAULT_ASPECT_RATIO}). Under
	 * the wrapper-as-image layout (issue #135) the SVG carries
	 * `preserveAspectRatio="none"` and is pinned to the wrapper's content box
	 * via `position: absolute` + the per-side padding values, so the polyline
	 * stretches non-uniformly to fill the plot rectangle exactly.
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private const VIEWBOX_WIDTH = 1200.0;

	/**
	 * Default chart aspect ratio (width / height) when the editor has not set
	 * an explicit `style.dimensions.aspectRatio`. Matches the SCSS baseline
	 * `aspect-ratio: 4 / 1` declared on the wrapper.
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private const DEFAULT_ASPECT_RATIO = 4.0;

	/**
	 * Internal plot padding inside the SVG, in viewBox units. Under the
	 * wrapper-as-image layout (issue #135) the polyline fills the plot
	 * rectangle exactly so there is no inset — the chart's outer frame
	 * sits at the SVG's edges and the polyline spans `0 .. viewBox_w`
	 * horizontally and `0 .. viewBox_h` vertically.
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private const PLOT_INSET = 0.0;

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
	private const KM_AXIS_LABEL_THRESHOLD_METERS = 2000.0;

	/**
	 * Padding-top expression (issue #135 — wrapper-as-image layout).
	 *
	 * Half the y-label's line-height so the topmost y-label's top edge tangents
	 * the wrapper's top edge. `0.5lh` resolves against the label's resolved
	 * line-height directly, avoiding any JS measurement loop.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const PADDING_TOP_EXPR = '0.5lh';

	/**
	 * Padding-bottom expression (issue #135 — wrapper-as-image layout).
	 *
	 * `0.5em` gap between the x-axis line and the x-label baseline, plus an
	 * `0.2em` descender-depth approximation for system fonts so the lowest
	 * descender tangents the wrapper's bottom edge. The 0.2em is a
	 * font-agnostic compromise; tighter fits for specific fonts are a future
	 * tuning issue, not this layout's concern.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const PADDING_BOTTOM_EXPR = 'calc(0.5em + 0.2em)';

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

		// Detect the editor render context. When true, build_chart()
		// pre-positions the cursor at fraction=0.5 and renders it visible so
		// the user has live feedback for the Cursor / Tooltip controls without
		// having to scrub the chart first. The predicate lives in
		// Request_Context so it pivots in lockstep with Render_Map.
		$is_editor_preview = Request_Context::is_editor_request();

		// Resolve the chart aspect ratio from the editor's `style.dimensions`
		// slot — it shapes the SVG's viewBox so non-uniform scaling
		// (issue #135 wrapper-as-image) matches the wrapper's rendered
		// aspect-ratio. Falls back to 4/1 when the editor leaves the field
		// empty.
		$aspect_ratio = self::resolve_aspect_ratio( $attributes );

		// Pre-compute the formatted y-tick label strings — both `build_chart()`
		// and the wrapper-padding emit consume them, and the widest one drives
		// the data-driven padding-left / padding-right (issue #135).
		$y_tick_labels = self::build_y_tick_labels( $y_min, $y_max );
		$widest_chars  = self::widest_label_chars( $y_tick_labels );

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
			$y_tick_labels,
		);
		$context_json = wp_json_encode( [ 'mapId' => $resolved_map_id ] );

		// Assemble the inline style with non-empty theming custom properties.
		// Dimensions (`aspect-ratio`, `min-height`) and typography (`font-family`,
		// `font-size`, etc.) are emitted by core's `dimensions` and `typography`
		// block supports — the wrapper attributes returned by
		// `get_block_wrapper_attributes()` already carry them when the editor
		// has set values, or when the `Dimensions_Defaults` filter (issue #117)
		// has normalised `style.dimensions.minHeight` to the per-block default
		// upstream. Empty colour values fall back to the SCSS defaults; the
		// SVG axis labels and cursor-tooltip text inherit typography from the
		// block wrapper.
		$style_parts = [];

		// Emit the data-driven wrapper-padding CSS variables (issue #135 —
		// wrapper-as-image layout). `--kntnt-gpx-blocks-elev-pad-x` carries
		// `calc(<widest>ch + 0.5em)` so padding-left == padding-right reserves
		// exactly the room the widest y-tick label needs plus the gap to the
		// y-axis line, and the SCSS rule applies that variable to both sides.
		// `--kntnt-gpx-blocks-elev-pad-top` and `--kntnt-gpx-blocks-elev-pad-bottom`
		// carry the typographic constants documented on the class constants.
		// Three variables (rather than direct padding declarations) so the
		// SCSS file is also the source of truth for fallback values and so
		// the SVG and the two label overlays can position themselves with the
		// same expressions.
		$style_parts[] = sprintf(
			'--kntnt-gpx-blocks-elev-pad-x: calc(%dch + 0.5em)',
			$widest_chars,
		);
		$style_parts[] = '--kntnt-gpx-blocks-elev-pad-top: ' . self::PADDING_TOP_EXPR;
		$style_parts[] = '--kntnt-gpx-blocks-elev-pad-bottom: ' . self::PADDING_BOTTOM_EXPR;

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
		// per-corner radii (issue #109). The wrapper-as-image layout
		// (issue #135) emits three padding variables unconditionally, so
		// `$style_parts` is guaranteed non-empty at this point.
		$style = implode( '; ', $style_parts ) . ';';

		// Build the noscript summary — same text as the SVG <desc> so non-JS visitors
		// and screen readers both get the same description.
		$noscript_text = self::build_desc( $payload['statistics'], (float) ( end( $downsampled )[0] ?? 0.0 ) );

		// Build the block wrapper attributes via core's helper so that editor-UI
		// affordances (HTML anchor, additional CSS class, theme-supplied
		// alignwide/alignfull, the dimensions / border / shadow / spacing block
		// supports, third-party render_block_data filters) reach the frontend.
		// The wp-block-kntnt-gpx-blocks-elevation class is supplied by core
		// from block.json and need not be repeated here.
		$wrapper = get_block_wrapper_attributes( [
			'class' => 'kntnt-gpx-blocks-elevation',
			'style' => $style,
		] );

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
				$distance += Distance::haversine_meters( $prev[1], $prev[0], $coord[1], $coord[0] );
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
	 * Builds the SVG chart together with its HTML axis-label overlays plus
	 * the HTML cursor-dot and cursor-tooltip overlays.
	 *
	 * The SVG carries the polyline, the left + bottom frame lines, and the
	 * vertical cursor line (a `<line>` element whose stroke stays consistent
	 * under non-uniform stretch via `vector-effect="non-scaling-stroke"`).
	 * Axis tick labels live in two HTML overlay `<div>` siblings of the SVG
	 * so they honour real CSS font-size from the editor's typography controls
	 * and reserve their own layout space outside the SVG's plot area. The
	 * cursor *dot* and the cursor *tooltip* likewise live in HTML overlays
	 * outside the SVG (issue #136) so they are immune to the non-uniform
	 * stretch — a perfectly circular dot stays circular at every aspect
	 * ratio, and the tooltip's text renders at proportional font size.
	 *
	 * Issue #135 — wrapper-as-image layout. The SVG's viewBox dimensions
	 * follow the editor-set aspect ratio (`style.dimensions.aspectRatio`)
	 * exactly. The SVG carries `preserveAspectRatio="none"` and is pinned to
	 * the wrapper's content box via `position: absolute` with insets matching
	 * the wrapper's data-driven padding, so the polyline stretches
	 * non-uniformly and fills the plot rectangle exactly with no letterboxing.
	 * `vector-effect="non-scaling-stroke"` on the polyline, the two axis
	 * frame lines, and the cursor line keeps stroke widths visually
	 * consistent under that non-uniform stretch. `PLOT_INSET = 0` means the
	 * polyline spans `0 .. viewBox_w` horizontally and `0 .. viewBox_h`
	 * vertically.
	 *
	 * The `$desc_id` is set on the SVG's `<desc>` child element so the SVG's
	 * `aria-labelledby` attribute can reference it — preferred over
	 * `aria-label` when the label text is already in the DOM.
	 *
	 * When `$is_editor_preview` is true, the cursor overlays are
	 * server-rendered visible at fraction=0.5 with the corresponding
	 * (distance, elevation) sample shown in the tooltip and inline
	 * `style.left` / `style.top` values pre-computed against the wrapper's
	 * plot rectangle. The editor user can then see live feedback for the
	 * Cursor / Tooltip controls without having to scrub the chart first. On
	 * the frontend (false), the cursor wrapper keeps `style="display:none"`
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
	 * @param list<string>                          $y_tick_labels     Pre-computed y-tick label
	 *                                                                 strings (top-down) shared
	 *                                                                 with the wrapper's padding
	 *                                                                 emit so the widest-label
	 *                                                                 calculation drives the
	 *                                                                 padding without recomputing.
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
		array $y_tick_labels = [],
	): string {

		// The caller guarantees count($series) >= 2; walk the x-domain directly
		// so PHPStan does not have to infer non-emptiness through array_map.
		$x_min = $series[0][0];
		$x_max = $series[ count( $series ) - 1 ][0];

		// Derive the viewBox from the editor-set aspect ratio. Under the
		// wrapper-as-image layout (issue #135) the SVG fills the wrapper's
		// content box exactly with `preserveAspectRatio="none"` and
		// `PLOT_INSET = 0`, so the polyline spans the full viewBox in both
		// dimensions and stretches non-uniformly when the wrapper's rendered
		// aspect-ratio differs from the viewBox aspect.
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
		// `vector-effect="non-scaling-stroke"` keeps the stroke width visually
		// consistent under the wrapper-as-image layout's non-uniform stretch
		// (issue #135). The attribute is required at the SVG-attribute level
		// — some browsers ignore the equivalent CSS property when set on a
		// container.
		$polyline = sprintf(
			'<polyline class="kntnt-gpx-blocks-elevation-line" fill="none" stroke="currentColor"'
			. ' vector-effect="non-scaling-stroke" points="%s" />',
			esc_attr( implode( ' ', $svg_points ) ),
		);

		// Choose km vs m for the x-axis labels based on the total distance.
		$use_km   = $x_max >= self::KM_AXIS_LABEL_THRESHOLD_METERS;
		$x_unit   = $use_km ? __( 'km', 'kntnt-gpx-blocks' ) : __( 'm', 'kntnt-gpx-blocks' );
		$x_factor = $use_km ? 0.001 : 1.0;
		$x_decim  = $use_km ? 1 : 0;

		// Build the HTML axis-label overlays. These live outside the SVG so
		// the labels honour real CSS font-size and reserve their own layout
		// space. Under the wrapper-as-image layout (issue #135) the overlay
		// containers span the plot rectangle's extent exactly — the SCSS
		// positions them with the same padding variables the wrapper uses —
		// so the tick fractions (0/25/50/75/100%) line up with the
		// polyline's tick positions.
		$y_labels = self::build_y_labels( $y_tick_labels );
		$x_labels = self::build_x_labels( $x_min, $x_max, $x_factor, $x_decim, $x_unit );

		// Compose the screen-reader summary text and escape the desc id for HTML.
		$desc    = self::build_desc( $statistics, $x_max );
		$safe_id = esc_attr( $desc_id );

		// Plot-area frame: a faint axis line on the bottom and left edges so
		// the chart has a clear baseline even when the polyline is short.
		// `vector-effect="non-scaling-stroke"` keeps the stroke width
		// consistent under the wrapper-as-image layout's non-uniform stretch
		// (issue #135).
		$frame = sprintf(
			'<line class="kntnt-gpx-blocks-elevation-axis" x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke="currentColor" vector-effect="non-scaling-stroke" />'
			. '<line class="kntnt-gpx-blocks-elevation-axis" x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke="currentColor" vector-effect="non-scaling-stroke" />',
			$plot_left,
			$plot_bottom,
			$plot_right,
			$plot_bottom,
			$plot_left,
			$plot_top,
			$plot_left,
			$plot_bottom,
		);

		// Compute the cursor overlays' initial geometry. On the frontend the
		// wrapping cursor `<div>` is invisible (display:none) and JS positions
		// the cursor on the first pointermove. In the editor preview the
		// overlays are rendered visible at the midpoint sample of the LTTB-
		// downsampled series so the editor user sees live feedback for the
		// Cursor / Tooltip controls without having to scrub the chart first;
		// the math mirrors view.ts's `interpolateSample` + `sampleToSvg` so
		// the server-rendered position is identical to what the JS would
		// produce for fraction=0.5.
		//
		// `$preview_cx` and `$preview_cy` are in SVG viewBox units. The
		// cursor LINE stays in SVG and is positioned in viewBox units; the
		// HTML dot and tooltip overlays are positioned in fraction-of-wrapper
		// units (0..1) because the wrapper's CSS-pixel dimensions are not
		// known at server-render time. View.ts converts the fractions into
		// CSS pixels on every cursor update; on the initial editor render
		// the same fractions live on `style.left` / `style.top` as
		// percentages so the overlays appear at the right spot before any
		// JS has run.
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
		$plot_w_for_preview = max( $plot_right - $plot_left, 0.000001 );
		$plot_h_for_preview = max( $plot_bottom - $plot_top, 0.000001 );
		$preview_fx_pct     = ( $preview_cx - $plot_left ) / $plot_w_for_preview * 100.0;
		$preview_fy_pct     = ( $preview_cy - $plot_top ) / $plot_h_for_preview * 100.0;

		// Server-render the cursor line. In editor preview mode `x1`/`x2` are
		// anchored at the midpoint sample's x; on the frontend they start at 0
		// and JS updates them on every pointermove. When the cursor wrapper
		// is hidden, `display:none` on the line keeps the SVG-side cursor in
		// sync with the HTML-side overlays — `display` is a presentation
		// attribute on SVG line elements and a CSS property in the
		// inline-style fallback used by view.ts.
		$line_x          = $is_editor_preview ? sprintf( '%.2f', $preview_cx ) : '0';
		$line_style_attr = $is_editor_preview ? '' : ' style="display:none"';
		$cursor_line     = sprintf(
			'<line class="kntnt-gpx-blocks-elevation-cursor-line"'
			. ' x1="%s" y1="%.2f" x2="%s" y2="%.2f" stroke="currentColor"'
			. ' vector-effect="non-scaling-stroke"%s />',
			$line_x,
			$plot_top,
			$line_x,
			$plot_bottom,
			$line_style_attr,
		);

		// aria-labelledby references the <desc> child element, which is the
		// recommended pattern when the accessible name is already present in
		// the DOM. `preserveAspectRatio="none"` lets the polyline stretch
		// non-uniformly to fill the plot rectangle exactly under the
		// wrapper-as-image layout (issue #135); `vector-effect="non-scaling-stroke"`
		// on the polyline, the two frame lines, and the cursor line keeps
		// stroke widths visually consistent under that stretch.
		$svg = sprintf(
			'<svg class="kntnt-gpx-blocks-elevation-svg" viewBox="0 0 %.2f %.2f" role="img"'
				. ' aria-labelledby="%s" preserveAspectRatio="none">'
				. '<desc id="%s">%s</desc>%s%s%s</svg>',
			$viewbox_w,
			$viewbox_h,
			$safe_id,
			$safe_id,
			esc_html( $desc ),
			$frame,
			$polyline,
			$cursor_line,
		);

		// Build the HTML cursor overlays (issue #136). The dot is a fixed
		// em-sized `<div>` so it stays perfectly circular at every aspect
		// ratio; the tooltip is a `<div>` whose intrinsic size flows from
		// its text content. Both are absolutely positioned relative to the
		// block wrapper. The wrapping cursor `<div>` carries the visibility
		// toggle (`display:none` on the frontend) and the editor-preview
		// flag (`data-preview="1"`) that view.ts's watch callback reads.
		$wrapper_style_attr   = $is_editor_preview ? '' : ' style="display:none"';
		$preview_attr         = $is_editor_preview ? ' data-preview="1"' : '';
		$dot_style_attr       = $is_editor_preview
			? sprintf( ' style="left:%.4f%%;top:%.4f%%"', $preview_fx_pct, $preview_fy_pct )
			: '';
		$tooltip_style_attr   = $is_editor_preview
			? sprintf( ' style="left:%.4f%%;top:0"', $preview_fx_pct )
			: '';
		$distance_label       = $is_editor_preview ? self::format_distance_label( $preview_distance_m ) : '';
		$elevation_label      = $is_editor_preview ? self::format_elevation_label( $preview_elevation_m ) : '';
		$cursor_overlay       = sprintf(
			'<div class="kntnt-gpx-blocks-elevation-cursor" aria-hidden="true"%s%s>'
				. '<div class="kntnt-gpx-blocks-elevation-cursor-dot"%s></div>'
				. '<div class="kntnt-gpx-blocks-elevation-cursor-tooltip"%s>'
					. '<div class="kntnt-gpx-blocks-elevation-cursor-tooltip-distance">%s</div>'
					. '<div class="kntnt-gpx-blocks-elevation-cursor-tooltip-elevation">%s</div>'
				. '</div>'
			. '</div>',
			$wrapper_style_attr,
			$preview_attr,
			$dot_style_attr,
			$tooltip_style_attr,
			esc_html( $distance_label ),
			esc_html( $elevation_label ),
		);

		// Compose the final chart: SVG (with cursor line inside), the HTML
		// cursor overlay (dot + tooltip), then the two HTML axis-label
		// overlays. The overlays are sibling elements inside the wrapper;
		// their absolute positioning and sizing live in the stylesheet.
		return $svg . $cursor_overlay . $y_labels . $x_labels;

	}

	/**
	 * Builds the formatted y-axis tick label strings, top-down.
	 *
	 * Index 0 is the topmost tick (the max value); index `TICK_COUNT - 1`
	 * is the bottom tick (the min value). The strings are consumed both by
	 * the y-axis HTML overlay and by the wrapper's data-driven `padding-x`
	 * (issue #135), so producing them in one place keeps the two emissions
	 * byte-identical.
	 *
	 * @since 1.0.0
	 *
	 * @param float $y_min Domain min (metres).
	 * @param float $y_max Domain max (metres).
	 *
	 * @return list<string> Five formatted label strings, top-down.
	 */
	private static function build_y_tick_labels( float $y_min, float $y_max ): array {

		$labels = [];
		$div    = self::TICK_COUNT - 1;
		$unit   = __( 'm', 'kntnt-gpx-blocks' );

		// Walk every tick top-down — index 0 is the topmost tick (the max
		// value); index TICK_COUNT-1 is the bottom tick (the min value).
		for ( $i = 0; $i < self::TICK_COUNT; $i++ ) {
			$ratio    = $i / $div;
			$value    = $y_max - ( $y_max - $y_min ) * $ratio;
			$labels[] = number_format_i18n( $value, 0 ) . ' ' . $unit;
		}

		return $labels;

	}

	/**
	 * Builds the HTML overlay for the y-axis tick labels from pre-computed
	 * label strings.
	 *
	 * Each label is a child `<span>` positioned vertically via inline `top`
	 * percentage, with the topmost tick carrying the maximum value (SVG y
	 * grows downwards — the overlay's top corresponds to the chart's top).
	 * Under the wrapper-as-image layout (issue #135) the overlay container
	 * is positioned by the SCSS to span the plot rectangle's vertical
	 * extent exactly, so the tick fractions match the polyline's tick
	 * positions.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $labels Five formatted label strings, top-down.
	 *
	 * @return string HTML fragment for the y-axis overlay container.
	 */
	private static function build_y_labels( array $labels ): string {

		$out = '';
		$div = self::TICK_COUNT - 1;

		// Walk every label top-down — index 0 is the topmost tick at
		// top: 0%; index TICK_COUNT-1 is the bottom tick at top: 100%.
		for ( $i = 0; $i < self::TICK_COUNT; $i++ ) {
			$top_pct = ( $i / $div ) * 100.0;
			$label   = $labels[ $i ] ?? '';
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
	 * Returns the widest character count among a list of formatted labels.
	 *
	 * Used by the wrapper-as-image layout (issue #135) to drive the
	 * `padding-left` / `padding-right` declarations as
	 * `calc(<chars>ch + 0.5em)`. Counts Unicode characters via
	 * `mb_strlen` so multi-byte characters in localised number-format
	 * output count as one each — `1ch` is the width of the "0" glyph in
	 * the resolved font, which is a reasonable lower bound for the per-
	 * character advance of typical digits + sign + decimal-separator
	 * sequences produced by `Value_Formatter`.
	 *
	 * Returns `1` for an empty list as a defensive minimum so the emitted
	 * CSS never collapses to `calc(0ch + 0.5em)` and the y-axis line still
	 * has breathing room.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $labels Formatted label strings.
	 *
	 * @return int Widest character count, always >= 1.
	 */
	private static function widest_label_chars( array $labels ): int {

		$widest = 1;
		foreach ( $labels as $label ) {
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $label ) : strlen( $label );
			if ( $len > $widest ) {
				$widest = $len;
			}
		}

		return $widest;

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

}
