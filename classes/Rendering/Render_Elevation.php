<?php
/**
 * Server-side render handler for the GPX Elevation block.
 *
 * Step 3 of `docs/elevation-rebuild.md` — the chart geometry (axes,
 * ticks, labels, curve, cursor, tooltip) is rendered client-side via
 * the Interactivity API. This class is responsible for two surfaces:
 *
 *   - The chart wrapper. In the healthy state it emits the
 *     Interactivity-bound `<div>` (the JS view module mounts the SVG
 *     inside) plus the per-`mapId` state slice carrying
 *     `min_elevation`, `max_elevation`, and `distance` for the
 *     margin algorithm.
 *   - The warning placeholders. One of five reasons replaces the
 *     chart wrapper whenever the block cannot render meaningfully:
 *     `no-map`, `bound-deleted`, `bound-unconfigured`,
 *     `no-elevation-data`, `zero-distance`.
 *
 * The class never emits `<svg>` markup itself — see
 * `docs/elevation-rebuild.md` § *Rendering architecture* for the
 * cross-cutting decision that JS owns every part of the chart from
 * Step 3 onward.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rendering;

use Kntnt\Gpx_Blocks\Cache\Attachment_Cache;
use Kntnt\Gpx_Blocks\Plugin;

/**
 * Produces the frontend HTML for the GPX Elevation block.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Render_Elevation {

	/**
	 * Returns the rendered HTML for a single Elevation block instance.
	 *
	 * Resolves the bound `mapId` server-side, reads the cached
	 * statistics, then dispatches to either the chart wrapper or one
	 * of the five warning placeholders. The block wrapper is emitted
	 * through `get_block_wrapper_attributes()` so align, anchor,
	 * custom class, dimensions, border, and shadow block-supports
	 * propagate exactly as in earlier steps.
	 *
	 * @since 1.0.0
	 *
	 * The $block parameter is typed as object (not \WP_Block) so unit
	 * tests can pass anonymous objects.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $content    Inner block HTML (always empty).
	 * @param object              $block      The block instance.
	 *
	 * @return string Escaped HTML ready for output.
	 */
	public static function render( array $attributes, string $content, object $block ): string {

		// Resolve the bound `mapId` against the host post's block tree.
		$map_id  = is_string( $attributes['mapId'] ?? null ) ? (string) $attributes['mapId'] : 'auto';
		$post_id = get_the_ID();
		$resolver = new Resolve_Map_Id();
		$resolved = $resolver->resolve( $map_id, is_int( $post_id ) ? $post_id : 0 );

		// Translate the resolver's error codes into the warning
		// reasons the placeholder system surfaces. `map-not-found`
		// covers both deleted and deconfigured subcases —
		// `Resolve_Map_Id` only sees configured Map blocks, so an
		// unconfigured bound Map looks like map-not-found from there.
		if ( $resolved instanceof Render_Error ) {
			$reason = match ( $resolved->code ) {
				'no-map'        => 'no-map',
				'multiple-maps' => 'no-map',
				'map-not-found' => 'bound-deleted',
				default         => 'no-map',
			};
			return self::wrap_warning( $attributes, self::render_warning( $reason ) );
		}

		// Read the cached statistics for the bound attachment. A
		// cache error is logged but surfaced visually as
		// `bound-deleted` — the user-facing remedy is the same
		// (re-bind to a different Map).
		$cache   = new Attachment_Cache();
		$payload = $cache->get( $resolved['attachment_id'] );
		if ( $payload instanceof Render_Error ) {
			Plugin::error(
				sprintf( 'Render_Elevation: cache error for attachment %d, code=%s', $resolved['attachment_id'], $payload->code )
			);
			return self::wrap_warning( $attributes, self::render_warning( 'bound-deleted' ) );
		}

		// Step 3 Case A — the bound track has no elevation samples
		// (Statistics_Calculator reports null for min/max when no
		// `<ele>` tag appears in the GPX). The chart cannot render
		// meaningfully; surface the dedicated warning instead.
		$statistics   = is_array( $payload['statistics'] ?? null ) ? $payload['statistics'] : [];
		$min_raw      = $statistics['min_elevation'] ?? null;
		$max_raw      = $statistics['max_elevation'] ?? null;
		$distance_raw = $statistics['distance'] ?? null;
		if ( null === $min_raw || null === $max_raw ) {
			return self::wrap_warning( $attributes, self::render_warning( 'no-elevation-data' ) );
		}

		// Step 3 Case C — single-point track or all points at the
		// same coordinate. No track to render.
		if ( null === $distance_raw || (float) $distance_raw <= 0 ) {
			return self::wrap_warning( $attributes, self::render_warning( 'zero-distance' ) );
		}

		// Healthy state. Compute the LTTB-downsampled (distance,
		// elevation) samples once and emit the per-mapId state slice
		// carrying both the statistics and the samples the JS view
		// module reads. The state is merged onto whatever the Map
		// block's own `Render_Map` has already written under the same
		// key, so `geojson` (Map), `statistics` and `samples`
		// (Elevation) co-exist on `state[mapId]` for the JS to consume.
		$min      = (float) $min_raw;
		$max      = (float) $max_raw;
		$distance = (float) $distance_raw;

		$target_raw = apply_filters(
			'kntnt_gpx_blocks_elevation_target_points',
			Elevation_Samples::DEFAULT_TARGET
		);
		$target  = is_int( $target_raw ) && $target_raw > 0
			? $target_raw
			: Elevation_Samples::DEFAULT_TARGET;
		$geojson = is_array( $payload['geojson'] ?? null ) ? $payload['geojson'] : [];
		$samples = Elevation_Samples::compute( $geojson, $target );

		wp_interactivity_state( 'kntnt-gpx-blocks', [
			$resolved['map_id'] => [
				'statistics' => [
					'min_elevation' => $min,
					'max_elevation' => $max,
					'distance'      => $distance,
				],
				'samples'    => $samples,
			],
		] );

		return self::render_chart_wrapper( $attributes, $resolved['map_id'] );

	}

	/**
	 * Returns the warning-box HTML for one of the five warning reasons.
	 *
	 * Unknown reasons fall back to the `no-map` message so a future
	 * extension never produces an empty placeholder.
	 *
	 * @since 1.0.0
	 *
	 * @param string $reason `'no-map'`, `'bound-deleted'`,
	 *                      `'bound-unconfigured'`, `'no-elevation-data'`,
	 *                      or `'zero-distance'`. Any other input
	 *                      falls back to the `no-map` message.
	 * @return string The warning-box HTML, escaped and ready for output.
	 */
	public static function render_warning( string $reason ): string {

		$message = match ( $reason ) {
			'no-map' => __(
				'There is no GPX Map block with a selected GPX file on this page. Add a GPX Map block before this one.',
				'kntnt-gpx-blocks'
			),
			'bound-deleted' => __(
				'The GPX Map block this block was bound to is no longer on the page. Pick another from the dropdown.',
				'kntnt-gpx-blocks'
			),
			'bound-unconfigured' => __(
				'The GPX Map block this block is bound to has no GPX file selected.',
				'kntnt-gpx-blocks'
			),
			'no-elevation-data' => __(
				'The bound GPX track has no elevation data. The elevation profile cannot be rendered.',
				'kntnt-gpx-blocks'
			),
			'zero-distance' => __(
				'The bound GPX track has no distance (all points are at the same location).',
				'kntnt-gpx-blocks'
			),
			default => __(
				'There is no GPX Map block with a selected GPX file on this page. Add a GPX Map block before this one.',
				'kntnt-gpx-blocks'
			),
		};

		return sprintf(
			'<div class="kntnt-gpx-blocks-elevation-preview-warning" style="padding:0.75em 1em;background-color:#fdecea;border-left:4px solid #d93025;color:#5f2120;">%s</div>',
			esc_html( $message )
		);

	}

	/**
	 * Renders the healthy-state chart wrapper.
	 *
	 * Emits the Interactivity-bound `<div>` with the documented
	 * directives + `<noscript>` fallback. The JS view module
	 * (`view.ts`'s `callbacks.initElevation`) creates the chart's SVG
	 * inside this wrapper at runtime.
	 *
	 * The wrapper carries:
	 *
	 *   - `class="kntnt-gpx-blocks-elevation"`
	 *   - `role="img"`
	 *   - localised `aria-label`
	 *   - `data-wp-interactive='{"namespace":"kntnt-gpx-blocks"}'`
	 *   - `data-wp-context='{"mapId":"…","showCursor":…,"showVerticalGuide":…,"showHorizontalGuide":…,"tooltipShowDistance":…,"tooltipShowHeight":…}'`
	 *     The five booleans carry the issue #144 Cursor & guides
	 *     toggles and the Step 7 Tooltip info toggles into the JS view
	 *     module without bloating the shared `state[ mapId ]` slice. The
	 *     per-block context is the right scope: two Elevation blocks
	 *     bound to the same Map may legitimately disagree about cursor
	 *     visibility or which tooltip rows render.
	 *   - `data-wp-init="callbacks.initElevation"`
	 *   - `data-wp-watch--cursor="callbacks.onElevationCursorChange"`
	 *     (Step 6 — fires on every change to `state[ mapId ].fraction`,
	 *     i.e. whenever the user scrubs Map's polyline or the chart's
	 *     own hit-rect; the watch repositions the cursor SVG group.
	 *     When `showCursor` is off, `view.ts` returns silently from this
	 *     callback so the watch costs nothing functionally and the SVG
	 *     never grows a cursor `<g>`.)
	 *
	 * `role="img"` is kept rather than upgraded to `"application"`:
	 * the cursor is mouse/touch-only in Step 6 (no keyboard handler),
	 * so `"application"` would over-promise to assistive tech.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $map_id     Resolved Map ID.
	 * @return string The chart wrapper HTML.
	 */
	public static function render_chart_wrapper( array $attributes, string $map_id ): string {

		$wrapper_attributes = get_block_wrapper_attributes( [
			'class' => 'kntnt-gpx-blocks-elevation',
			'style' => self::build_inline_style( $attributes ),
		] );

		$aria_label = esc_attr__( 'Elevation profile of GPX track', 'kntnt-gpx-blocks' );

		// phpcs:ignore Generic.Files.LineLength.TooLong -- Translator strings must be a single literal per WordPress.WP.I18n.
		$noscript_text = esc_html__( 'This elevation profile requires JavaScript to display. The track is recorded in the GPX file referenced by this block.', 'kntnt-gpx-blocks' );

		// Mirror the three Cursor & guides toggles (issue #144) plus the
		// two Tooltip info toggles (Step 7) into the per-block
		// Interactivity context. Defaults match block.json: cursor on,
		// vertical guide on, horizontal guide off, tooltip distance on,
		// tooltip height on. Two Elevation blocks bound to the same Map
		// may legitimately disagree about which tooltip rows their
		// tooltips show — the per-block context is the right scope.
		$show_cursor             = isset( $attributes['showCursor'] ) ? (bool) $attributes['showCursor'] : true;
		$show_vertical_guide     = isset( $attributes['showVerticalGuide'] ) ? (bool) $attributes['showVerticalGuide'] : true;
		$show_horizontal_guide   = isset( $attributes['showHorizontalGuide'] ) ? (bool) $attributes['showHorizontalGuide'] : false;
		$tooltip_show_distance   = isset( $attributes['tooltipShowDistance'] ) ? (bool) $attributes['tooltipShowDistance'] : true;
		$tooltip_show_height     = isset( $attributes['tooltipShowHeight'] ) ? (bool) $attributes['tooltipShowHeight'] : true;

		$context = wp_json_encode( [
			'mapId'               => $map_id,
			'showCursor'          => $show_cursor,
			'showVerticalGuide'   => $show_vertical_guide,
			'showHorizontalGuide' => $show_horizontal_guide,
			'tooltipShowDistance' => $tooltip_show_distance,
			'tooltipShowHeight'   => $tooltip_show_height,
		] );

		return sprintf(
			'<div %1$s'
				. ' role="img"'
				. ' aria-label="%2$s"'
				. ' data-wp-interactive=\'{"namespace":"kntnt-gpx-blocks"}\''
				. ' data-wp-context=\'%3$s\''
				. ' data-wp-init="callbacks.initElevation"'
				. ' data-wp-watch--cursor="callbacks.onElevationCursorChange">'
				. '<noscript><p class="kntnt-gpx-blocks-elevation-noscript">%4$s</p></noscript>'
				. '</div>',
			$wrapper_attributes,
			$aria_label,
			esc_attr( (string) $context ),
			$noscript_text,
		);

	}

	/**
	 * Wraps a warning fragment in the block's outer `<div>`.
	 *
	 * Warnings do not need the Interactivity directives — they are
	 * static HTML with no client-side mount. They still travel
	 * through `get_block_wrapper_attributes()` so the block-supports
	 * pipeline (align, anchor, custom class, dimensions, border,
	 * shadow, spacing) propagates.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $inner_html Pre-escaped HTML to wrap.
	 * @return string Final HTML.
	 */
	private static function wrap_warning( array $attributes, string $inner_html ): string {

		$wrapper_attributes = get_block_wrapper_attributes( [
			'class' => 'kntnt-gpx-blocks-elevation',
			'style' => self::build_inline_style( $attributes ),
		] );

		return sprintf( '<div %s>%s</div>', $wrapper_attributes, $inner_html );

	}

	/**
	 * Builds the wrapper's inline style string from the block's
	 * sanitised colour and typography attributes.
	 *
	 * Step 7 expands the surface to nine colour custom properties (six
	 * chart surfaces plus the three tooltip rows) and twenty-four
	 * typography custom properties (eight per row × three rows: tick
	 * labels, tooltip distance, tooltip height). Each typography
	 * attribute flows through the matching {@see Typography_Sanitizer}
	 * method; values that fail the allow-list are dropped and the
	 * corresponding custom property is omitted, so the SCSS rule falls
	 * back to `inherit` from the wrapper's resolved typography.
	 *
	 * Colours:
	 *
	 *   - `--kntnt-gpx-blocks-elevation-background`         ← `backgroundColor`
	 *   - `--kntnt-gpx-blocks-elevation-axis`               ← `axisColor`
	 *   - `--kntnt-gpx-blocks-elevation-axis-label`         ← `axisLabelColor`
	 *   - `--kntnt-gpx-blocks-elevation-plot-line`          ← `plotLineColor`
	 *   - `--kntnt-gpx-blocks-elevation-plot-fill`          ← `plotFillColor`
	 *   - `--kntnt-gpx-blocks-elevation-cursor`             ← `cursorColor`
	 *   - `--kntnt-gpx-blocks-elevation-tooltip-background` ← `tooltipBackgroundColor`
	 *   - `--kntnt-gpx-blocks-elevation-tooltip-distance`   ← `tooltipDistanceColor`
	 *   - `--kntnt-gpx-blocks-elevation-tooltip-height`     ← `tooltipHeightColor`
	 *
	 * Typography: each row has eight properties
	 * (`font-family`, `font-size`, `font-weight`, `font-style`,
	 * `line-height`, `letter-spacing`, `text-transform`,
	 * `text-decoration`), each sourced from a `Pascal` attribute named
	 * `<row>Font…` / `<row>LineHeight` / `<row>LetterSpacing` /
	 * `<row>Text…`. The CSS prefix is `tick-label`, `tooltip-distance`,
	 * or `tooltip-height`.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string Semicolon-terminated declaration string or `''`
	 *                when no attribute resolves to a non-empty value.
	 */
	private static function build_inline_style( array $attributes ): string {

		$parts = [];

		$background = Color_Sanitizer::sanitize(
			is_string( $attributes['backgroundColor'] ?? null ) ? (string) $attributes['backgroundColor'] : ''
		);
		if ( '' !== $background ) {
			$parts[] = '--kntnt-gpx-blocks-elevation-background: ' . esc_attr( $background );
		}

		$axis = Color_Sanitizer::sanitize(
			is_string( $attributes['axisColor'] ?? null ) ? (string) $attributes['axisColor'] : ''
		);
		if ( '' !== $axis ) {
			$parts[] = '--kntnt-gpx-blocks-elevation-axis: ' . esc_attr( $axis );
		}

		$axis_label = Color_Sanitizer::sanitize(
			is_string( $attributes['axisLabelColor'] ?? null ) ? (string) $attributes['axisLabelColor'] : ''
		);
		if ( '' !== $axis_label ) {
			$parts[] = '--kntnt-gpx-blocks-elevation-axis-label: ' . esc_attr( $axis_label );
		}

		$plot_line = Color_Sanitizer::sanitize(
			is_string( $attributes['plotLineColor'] ?? null ) ? (string) $attributes['plotLineColor'] : ''
		);
		if ( '' !== $plot_line ) {
			$parts[] = '--kntnt-gpx-blocks-elevation-plot-line: ' . esc_attr( $plot_line );
		}

		$plot_fill = Color_Sanitizer::sanitize(
			is_string( $attributes['plotFillColor'] ?? null ) ? (string) $attributes['plotFillColor'] : ''
		);
		if ( '' !== $plot_fill ) {
			$parts[] = '--kntnt-gpx-blocks-elevation-plot-fill: ' . esc_attr( $plot_fill );
		}

		$cursor = Color_Sanitizer::sanitize(
			is_string( $attributes['cursorColor'] ?? null ) ? (string) $attributes['cursorColor'] : ''
		);
		if ( '' !== $cursor ) {
			$parts[] = '--kntnt-gpx-blocks-elevation-cursor: ' . esc_attr( $cursor );
		}

		// Tooltip colour custom properties (Step 7). Three rows; the
		// same sanitiser path as the colours above, with the same
		// empty-value semantics — an empty result drops the custom
		// property entirely so the SCSS default kicks in.
		$tooltip_background = Color_Sanitizer::sanitize(
			is_string( $attributes['tooltipBackgroundColor'] ?? null ) ? (string) $attributes['tooltipBackgroundColor'] : ''
		);
		if ( '' !== $tooltip_background ) {
			$parts[] = '--kntnt-gpx-blocks-elevation-tooltip-background: ' . esc_attr( $tooltip_background );
		}

		$tooltip_distance_color = Color_Sanitizer::sanitize(
			is_string( $attributes['tooltipDistanceColor'] ?? null ) ? (string) $attributes['tooltipDistanceColor'] : ''
		);
		if ( '' !== $tooltip_distance_color ) {
			$parts[] = '--kntnt-gpx-blocks-elevation-tooltip-distance: ' . esc_attr( $tooltip_distance_color );
		}

		$tooltip_height_color = Color_Sanitizer::sanitize(
			is_string( $attributes['tooltipHeightColor'] ?? null ) ? (string) $attributes['tooltipHeightColor'] : ''
		);
		if ( '' !== $tooltip_height_color ) {
			$parts[] = '--kntnt-gpx-blocks-elevation-tooltip-height: ' . esc_attr( $tooltip_height_color );
		}

		// Tick-label typography. Each row pairs the attribute key with
		// the corresponding Typography_Sanitizer callable (first-class
		// callable syntax keeps the dispatch statically verifiable) and
		// the CSS custom-property suffix.
		$typography_map = [
			[ 'tickLabelFontFamily',     Typography_Sanitizer::font_family(...),     'font-family' ],
			[ 'tickLabelFontSize',       Typography_Sanitizer::font_size(...),       'font-size' ],
			[ 'tickLabelFontWeight',     Typography_Sanitizer::font_weight(...),     'font-weight' ],
			[ 'tickLabelFontStyle',      Typography_Sanitizer::font_style(...),      'font-style' ],
			[ 'tickLabelLineHeight',     Typography_Sanitizer::line_height(...),     'line-height' ],
			[ 'tickLabelLetterSpacing',  Typography_Sanitizer::letter_spacing(...),  'letter-spacing' ],
			[ 'tickLabelTextTransform',  Typography_Sanitizer::text_transform(...),  'text-transform' ],
			[ 'tickLabelTextDecoration', Typography_Sanitizer::text_decoration(...), 'text-decoration' ],
		];
		foreach ( $typography_map as [ $attr_key, $sanitize, $css_suffix ] ) {
			$value = $sanitize( $attributes[ $attr_key ] ?? '' );
			if ( '' !== $value ) {
				$parts[] = sprintf(
					'--kntnt-gpx-blocks-elevation-tick-label-%s: %s',
					$css_suffix,
					esc_attr( $value )
				);
			}
		}

		// Tooltip distance / Tooltip height typography (Step 7). Two
		// parallel 8-row maps, written through the same sanitiser
		// family. Iterated together so the two rows share one code path
		// and the per-row CSS custom-property prefix (`tooltip-distance`
		// or `tooltip-height`) is the only differentiator.
		$tooltip_typography_groups = [
			[ 'tooltipDistance', 'tooltip-distance' ],
			[ 'tooltipHeight',   'tooltip-height' ],
		];
		$tooltip_typography_props = [
			[ 'FontFamily',     Typography_Sanitizer::font_family(...),     'font-family' ],
			[ 'FontSize',       Typography_Sanitizer::font_size(...),       'font-size' ],
			[ 'FontWeight',     Typography_Sanitizer::font_weight(...),     'font-weight' ],
			[ 'FontStyle',      Typography_Sanitizer::font_style(...),      'font-style' ],
			[ 'LineHeight',     Typography_Sanitizer::line_height(...),     'line-height' ],
			[ 'LetterSpacing',  Typography_Sanitizer::letter_spacing(...),  'letter-spacing' ],
			[ 'TextTransform',  Typography_Sanitizer::text_transform(...),  'text-transform' ],
			[ 'TextDecoration', Typography_Sanitizer::text_decoration(...), 'text-decoration' ],
		];
		foreach ( $tooltip_typography_groups as [ $attr_prefix, $css_prefix ] ) {
			foreach ( $tooltip_typography_props as [ $attr_suffix, $sanitize, $css_suffix ] ) {
				$attr_key = $attr_prefix . $attr_suffix;
				$value = $sanitize( $attributes[ $attr_key ] ?? '' );
				if ( '' !== $value ) {
					$parts[] = sprintf(
						'--kntnt-gpx-blocks-elevation-%s-%s: %s',
						$css_prefix,
						$css_suffix,
						esc_attr( $value )
					);
				}
			}
		}

		// Append a trailing `;` so the joined declarations always end
		// on a terminator. `get_block_wrapper_attributes()` joins any
		// core-supplied declarations on with a space rather than a
		// semicolon, so the first core declaration would otherwise run
		// into the last plugin declaration's value.
		return count( $parts ) > 0 ? implode( '; ', $parts ) . ';' : '';

	}

}
