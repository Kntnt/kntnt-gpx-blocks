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
	 * client-side by the JS contract documented in docs/consent.md
	 * (`window.kntnt_gpx_blocks.mayProceed` plus the `kntnt_gpx_blocks:consent`
	 * event). The plugin exposes no PHP-side consent filter — see the rationale
	 * in docs/consent.md (section "Why no PHP filter").
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

		// Read and sanitize the two track colour attributes through the shared
		// Color_Sanitizer (alpha-aware hex 3/4/6/8). PanelColorSettings on
		// these controls runs with `enableAlpha`, so a non-opaque hex8
		// round-trips into the rendered CSS custom property.
		$track_color        = Color_Sanitizer::sanitize( $attributes['trackColor'] ?? '' );
		$track_cursor_color = Color_Sanitizer::sanitize( $attributes['trackCursorColor'] ?? '' );

		// Read and sanitize the waypoint marker colour.
		$waypoint_color = Color_Sanitizer::sanitize( $attributes['waypointColor'] ?? '' );

		// Read the two waypoint-info tooltip toggles. Both default on; setting
		// either off suppresses the corresponding line in the rendered tooltip.
		// When both are off, view.ts binds no tooltip at all.
		$tooltip_show_name = isset( $attributes['tooltipShowName'] ) ? (bool) $attributes['tooltipShowName'] : true;
		$tooltip_show_desc = isset( $attributes['tooltipShowDesc'] ) ? (bool) $attributes['tooltipShowDesc'] : true;

		// Read and sanitize the tooltip background through the same shared
		// validator. `ColorPicker` with `enableAlpha: true` produces hex8 by
		// default; rgba()/hsl()/named colours are rejected.
		$tooltip_background = Color_Sanitizer::sanitize( $attributes['tooltipBackground'] ?? '' );

		// Read and sanitize the per-line tooltip styling attributes — colour
		// plus the seven aspects of WordPress's standard TypographyToolsPanel.
		$tooltip_name_color           = Color_Sanitizer::sanitize( $attributes['tooltipNameColor'] ?? '' );
		$tooltip_name_family          = self::sanitize_font_family( $attributes['tooltipNameFontFamily'] ?? '' );
		$tooltip_name_size            = self::sanitize_font_size( $attributes['tooltipNameFontSize'] ?? '' );
		$tooltip_name_weight          = self::sanitize_font_weight( $attributes['tooltipNameFontWeight'] ?? '' );
		$tooltip_name_style           = self::sanitize_font_style( $attributes['tooltipNameFontStyle'] ?? '' );
		$tooltip_name_line_height     = self::sanitize_line_height( $attributes['tooltipNameLineHeight'] ?? '' );
		$tooltip_name_letter_spacing  = self::sanitize_length( $attributes['tooltipNameLetterSpacing'] ?? '' );
		$tooltip_name_text_decoration = self::sanitize_text_decoration( $attributes['tooltipNameTextDecoration'] ?? '' );
		$tooltip_name_text_transform  = self::sanitize_text_transform( $attributes['tooltipNameTextTransform'] ?? '' );

		$tooltip_desc_color           = Color_Sanitizer::sanitize( $attributes['tooltipDescColor'] ?? '' );
		$tooltip_desc_family          = self::sanitize_font_family( $attributes['tooltipDescFontFamily'] ?? '' );
		$tooltip_desc_size            = self::sanitize_font_size( $attributes['tooltipDescFontSize'] ?? '' );
		$tooltip_desc_weight          = self::sanitize_font_weight( $attributes['tooltipDescFontWeight'] ?? '' );
		$tooltip_desc_style           = self::sanitize_font_style( $attributes['tooltipDescFontStyle'] ?? '' );
		$tooltip_desc_line_height     = self::sanitize_line_height( $attributes['tooltipDescLineHeight'] ?? '' );
		$tooltip_desc_letter_spacing  = self::sanitize_length( $attributes['tooltipDescLetterSpacing'] ?? '' );
		$tooltip_desc_text_decoration = self::sanitize_text_decoration( $attributes['tooltipDescTextDecoration'] ?? '' );
		$tooltip_desc_text_transform  = self::sanitize_text_transform( $attributes['tooltipDescTextTransform'] ?? '' );

		// Read the saved tile-provider id and per-block API key. The id is
		// resolved against the validated registry below; an unknown id falls
		// back silently to OpenStreetMap with a warning. The key is forwarded
		// verbatim — it is substituted into the URL by the registry, never
		// emitted in the rendered HTML.
		$tile_provider_id = isset( $attributes['tileProvider'] ) && is_string( $attributes['tileProvider'] ) && '' !== $attributes['tileProvider']
			? $attributes['tileProvider']
			: Tile_Layer_Registry::FALLBACK_PROVIDER_ID;
		$tile_api_key     = isset( $attributes['tileApiKey'] ) && is_string( $attributes['tileApiKey'] ) ? $attributes['tileApiKey'] : '';

		// Read the saved overlay-id list. Each entry is validated and resolved
		// against the overlay registry; unknown ids are dropped with a warning.
		$tile_overlay_ids = [];
		if ( isset( $attributes['tileOverlays'] ) && is_array( $attributes['tileOverlays'] ) ) {
			foreach ( $attributes['tileOverlays'] as $overlay_id ) {
				if ( is_string( $overlay_id ) && '' !== $overlay_id ) {
					$tile_overlay_ids[] = $overlay_id;
				}
			}
		}

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
		// Elevation and the Statistics bindings refer to.
		$track_cum_dist = self::cumulative_for_simplified( $track_points, $simplified );

		// Total distance comes from the cache so the Map polyline, the Elevation
		// chart, and the Statistics bindings source all agree on the canonical
		// figure. Falls back to 0.0 only when the cache lacks it, which is
		// impossible given the cache contract.
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

		// Resolve the tile-layer records for the per-block Interactivity state.
		// The registry validates the filtered defaults, substitutes the
		// per-block API key into `{KEY}`, and falls back silently to
		// `osm-standard` on unknown provider ids (with a warning log). Overlay
		// records mirror the provider shape minus the API-key handling. The
		// view module reads url/attribution/maxZoom/subdomains from these
		// records to build its tile layer; the {KEY}-substituted URL is the
		// only place the API key reaches the browser.
		$tile_registry = new Tile_Layer_Registry();
		$tile_provider = $tile_registry->resolve_provider( $tile_provider_id, $tile_api_key );
		$tile_overlays = $tile_registry->resolve_overlays( $tile_overlay_ids );

		// Polyline-only gate: when the resolved provider requires an API key
		// and the per-block `tileApiKey` is empty, null out the URL so the
		// frontend view module ships polyline-only instead of issuing failing
		// tile requests with a bare `apikey=` query parameter. The rest of
		// the record (id, attribution, maxZoom, subdomains) survives so JS
		// keeps the metadata for diagnostics; the documented contract is
		// `url === null` ⇒ no base tile layer. This rule applies to the
		// editor preview path too — `bypassConsent === true` only governs
		// the consent gate, not the missing-key gate. See docs/consent.md
		// "What the plugin renders" and the issue #81 acceptance criteria.
		$resolved_record  = $tile_registry->get_providers()[ $tile_provider['id'] ] ?? null;
		$requires_key     = $resolved_record !== null ? $resolved_record['requiresKey'] : false;
		$key_is_empty     = '' === trim( $tile_api_key );
		if ( $requires_key && $key_is_empty ) {
			$tile_provider['url'] = null;
		}

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
				'tileProvider'  => $tile_provider,
				'tileOverlays'  => $tile_overlays,
				'settings'      => [
					'showZoomButtons'       => $show_zoom_buttons,
					'showScale'             => $show_scale,
					'showFullscreen'        => $show_fullscreen,
					'showDownload'          => $show_download,
					'enableDrag'            => $enable_drag,
					'enablePinchZoom'       => $enable_pinch_zoom,
					'enableDoubleClickZoom' => $enable_double_click_zoom,
					'enableKeyboard'        => $enable_keyboard,
					'tooltipShowName'       => $tooltip_show_name,
					'tooltipShowDesc'       => $tooltip_show_desc,
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

		// Build the inline style string from the validated theming attributes.
		// Dimensions (`aspect-ratio`, `min-height`) are emitted by core's
		// `dimensions` block supports — the wrapper attributes returned by
		// `get_block_wrapper_attributes()` already carry them, so this render
		// callback only needs to contribute the colour and typography custom
		// properties on top.
		$style_parts = [];

		// Append CSS custom properties for track and cursor colours when set.
		// Empty strings fall back to the hardcoded defaults in style.scss.
		if ( '' !== $track_color ) {
			$style_parts[] = '--kntnt-gpx-blocks-track-color: ' . esc_attr( $track_color );
		}
		if ( '' !== $track_cursor_color ) {
			$style_parts[] = '--kntnt-gpx-blocks-track-cursor-color: ' . esc_attr( $track_cursor_color );
		}

		// Waypoint marker colour is independent of the tooltip styling; empty
		// falls back to the SCSS-defined default.
		if ( '' !== $waypoint_color ) {
			$style_parts[] = '--kntnt-gpx-blocks-waypoint-color: ' . esc_attr( $waypoint_color );
		}

		// Tooltip background flows into the .leaflet-tooltip rule and into the
		// arrow-tip pseudo-element so a semi-transparent background colours the
		// arrow consistently.
		if ( '' !== $tooltip_background ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-bg: ' . esc_attr( $tooltip_background );
		}

		// Tooltip name-line custom properties — empty strings fall back to the
		// SCSS-defined defaults so the theme inherits cleanly.
		if ( '' !== $tooltip_name_color ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-name-color: ' . esc_attr( $tooltip_name_color );
		}
		if ( '' !== $tooltip_name_family ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-name-font-family: ' . esc_attr( $tooltip_name_family );
		}
		if ( '' !== $tooltip_name_size ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-name-font-size: ' . esc_attr( $tooltip_name_size );
		}
		if ( '' !== $tooltip_name_weight ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-name-font-weight: ' . esc_attr( $tooltip_name_weight );
		}
		if ( '' !== $tooltip_name_style ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-name-font-style: ' . esc_attr( $tooltip_name_style );
		}
		if ( '' !== $tooltip_name_line_height ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-name-line-height: ' . esc_attr( $tooltip_name_line_height );
		}
		if ( '' !== $tooltip_name_letter_spacing ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-name-letter-spacing: ' . esc_attr( $tooltip_name_letter_spacing );
		}
		if ( '' !== $tooltip_name_text_decoration ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-name-text-decoration: ' . esc_attr( $tooltip_name_text_decoration );
		}
		if ( '' !== $tooltip_name_text_transform ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-name-text-transform: ' . esc_attr( $tooltip_name_text_transform );
		}

		// Tooltip description-line custom properties — same fall-back contract
		// as the name line above.
		if ( '' !== $tooltip_desc_color ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-desc-color: ' . esc_attr( $tooltip_desc_color );
		}
		if ( '' !== $tooltip_desc_family ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-desc-font-family: ' . esc_attr( $tooltip_desc_family );
		}
		if ( '' !== $tooltip_desc_size ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-desc-font-size: ' . esc_attr( $tooltip_desc_size );
		}
		if ( '' !== $tooltip_desc_weight ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-desc-font-weight: ' . esc_attr( $tooltip_desc_weight );
		}
		if ( '' !== $tooltip_desc_style ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-desc-font-style: ' . esc_attr( $tooltip_desc_style );
		}
		if ( '' !== $tooltip_desc_line_height ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-desc-line-height: ' . esc_attr( $tooltip_desc_line_height );
		}
		if ( '' !== $tooltip_desc_letter_spacing ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-desc-letter-spacing: ' . esc_attr( $tooltip_desc_letter_spacing );
		}
		if ( '' !== $tooltip_desc_text_decoration ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-desc-text-decoration: ' . esc_attr( $tooltip_desc_text_decoration );
		}
		if ( '' !== $tooltip_desc_text_transform ) {
			$style_parts[] = '--kntnt-gpx-blocks-tooltip-desc-text-transform: ' . esc_attr( $tooltip_desc_text_transform );
		}

		$style = implode( '; ', $style_parts );

		// Encode the data-wp-context payload as a JSON string.
		$context = wp_json_encode( [ 'mapId' => $map_id ] );

		// Translate the ARIA label and the noscript fallback string.
		$aria_label = esc_attr__( 'Map of GPX track', 'kntnt-gpx-blocks' );
		// phpcs:ignore Generic.Files.LineLength.TooLong -- Translator strings must be a single literal per WordPress.WP.I18n; splitting is not permitted.
		$noscript_text = esc_html__( 'This map requires JavaScript to display. The track is recorded in the GPX file referenced by this block.', 'kntnt-gpx-blocks' );

		// Build the block wrapper attributes via core's helper so that editor-UI
		// affordances (HTML anchor, additional CSS class, theme-supplied
		// alignwide/alignfull, the dimensions / border / shadow / spacing block
		// supports, third-party render_block_data filters) reach the frontend.
		// The wp-block-kntnt-gpx-blocks-map class is supplied by core from
		// block.json and need not be repeated here.
		$wrapper_args = [ 'class' => 'kntnt-gpx-blocks-map' ];
		if ( '' !== $style ) {
			$wrapper_args['style'] = $style;
		}
		$wrapper = get_block_wrapper_attributes( $wrapper_args );

		// Return the block element. Leaflet mounts directly into this wrapper.
		// Width is 100% via the SCSS baseline; aspect-ratio and min-height come
		// from core's `dimensions` block supports — either as the editor's
		// override carried in `$wrapper`'s inline style, or as the SCSS fallback
		// (`3 / 1` and `240px`). Either way Leaflet always sees a correctly
		// sized container.
		// role="application" and aria-label expose the interactive map to assistive
		// technology. <noscript> is shown only when JS is disabled.
		// data-wp-init bootstraps the block. The suffixed data-wp-watch directive
		// reacts to cursor-marker updates from sibling Elevation blocks. Consent
		// transitions are handled inside initMap via the JS contract documented
		// in docs/consent.md, not via a Watch directive.
		// No plugin-supplied placeholder, button, or consent UI is rendered —
		// the active consent-management plugin owns the visitor-facing UX.
		return sprintf(
			'<div %1$s'
				. ' role="application"'
				. ' aria-label="%2$s"'
				. ' data-wp-interactive=\'{"namespace":"kntnt-gpx-blocks"}\''
				. ' data-wp-context=\'%3$s\''
				. ' data-wp-init="callbacks.initMap"'
				. ' data-wp-watch--cursor="callbacks.onMapCursorChange">'
				. '<noscript><p class="kntnt-gpx-blocks-map-noscript">%4$s</p></noscript>'
				. '</div>',
			$wrapper,
			$aria_label,
			esc_attr( (string) $context ),
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

	/**
	 * Validates a CSS line-height value.
	 *
	 * Accepts unitless multipliers (e.g. `1.5`), numeric lengths with the
	 * common length units, and the keyword `normal`. Returns empty string on
	 * anything else so the CSS falls back to the SCSS default.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Raw attribute value.
	 *
	 * @return string Validated line-height string or empty string.
	 */
	private static function sanitize_line_height( mixed $raw ): string {

		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		if ( 'normal' === $raw ) {
			return $raw;
		}

		// Unitless multiplier or a numeric length with px/em/rem/%.
		if ( preg_match( '/^(\d+(\.\d+)?)(px|em|rem|%)?$/', $raw ) ) {
			return $raw;
		}

		return '';

	}

	/**
	 * Validates a generic CSS length used for letter-spacing.
	 *
	 * Accepts numeric values with `px`, `em`, `rem`, or `%`, an optional leading
	 * minus sign (negative letter-spacing is meaningful), and the keyword
	 * `normal`. Anything else is rejected.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Raw attribute value.
	 *
	 * @return string Validated length string or empty string.
	 */
	private static function sanitize_length( mixed $raw ): string {

		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		if ( 'normal' === $raw ) {
			return $raw;
		}

		// Optional leading minus, then digits with optional fraction, with px/em/rem/%.
		if ( preg_match( '/^-?\d+(\.\d+)?(px|em|rem|%)$/', $raw ) ) {
			return $raw;
		}

		return '';

	}

	/**
	 * Validates a CSS text-decoration value against the accepted keyword whitelist.
	 *
	 * Limited to the four single-keyword values core's TypographyToolsPanel
	 * surfaces (`none`, `underline`, `overline`, `line-through`). Composite
	 * values like `underline dotted red` are not exposed by the panel and are
	 * rejected here for safety.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Raw attribute value.
	 *
	 * @return string Validated text-decoration string or empty string.
	 */
	private static function sanitize_text_decoration( mixed $raw ): string {

		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		if ( preg_match( '/^(none|underline|overline|line-through)$/', $raw ) ) {
			return $raw;
		}

		return '';

	}

	/**
	 * Validates a CSS text-transform value against the accepted keyword whitelist.
	 *
	 * Matches the keyword set core's TypographyToolsPanel offers (`none`,
	 * `uppercase`, `lowercase`, `capitalize`).
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Raw attribute value.
	 *
	 * @return string Validated text-transform string or empty string.
	 */
	private static function sanitize_text_transform( mixed $raw ): string {

		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		if ( preg_match( '/^(none|uppercase|lowercase|capitalize)$/', $raw ) ) {
			return $raw;
		}

		return '';

	}

}
