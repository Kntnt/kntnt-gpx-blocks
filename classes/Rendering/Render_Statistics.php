<?php
/**
 * Server-side render handler for the GPX Statistics block.
 *
 * Resolves the linked GPX Map, reads the pre-computed statistics from the
 * attachment cache, and emits a <dl> with up to five localised summary rows.
 * Theming attributes (header and value colours and typography) are emitted as
 * CSS custom properties on the <dl> wrapper. The block has no frontend
 * JavaScript — values are entirely server-rendered.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rendering;

use Kntnt\Gpx_Blocks\Cache\Attachment_Cache;
use Kntnt\Gpx_Blocks\Format\Value_Formatter;

/**
 * Produces the frontend HTML for the GPX Statistics block.
 *
 * Called by src/blocks/statistics/render.php with the three standard block
 * render arguments.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Render_Statistics {

	/**
	 * Returns the rendered HTML for a single GPX Statistics block instance.
	 *
	 * Resolves the mapId to a concrete attachment, reads the cached statistics,
	 * and builds a <dl> with up to five rows. Elevation rows are omitted when
	 * the track has no elevation data. Returns an empty string for visitors on
	 * any error; returns an editor-only error notice for users with edit_posts.
	 *
	 * The $block parameter is typed as object (not \WP_Block) so unit tests can
	 * pass lightweight test doubles without a full WordPress bootstrap. WordPress
	 * always supplies a genuine \WP_Block at runtime.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $attributes Block attributes from post_content.
	 * @param string              $content    Inner block HTML (always empty for this block).
	 * @param object              $block      The block instance carrying block.json metadata.
	 *
	 * @return string Escaped HTML ready for output.
	 */
	public static function render( array $attributes, string $content, object $block ): string {

		// Read the mapId attribute (defaults to 'auto').
		$raw_map_id = $attributes['mapId'] ?? 'auto';
		$map_id     = is_string( $raw_map_id ) && '' !== $raw_map_id ? $raw_map_id : 'auto';

		// Read and sanitize the six header theming attributes.
		$header_background  = self::sanitize_color( $attributes['headerBackground'] ?? '' );
		$header_color       = self::sanitize_color( $attributes['headerColor'] ?? '' );
		$header_font_family = self::sanitize_font_family( $attributes['headerFontFamily'] ?? '' );
		$header_font_size   = self::sanitize_font_size( $attributes['headerFontSize'] ?? '' );
		$header_font_weight = self::sanitize_font_weight( $attributes['headerFontWeight'] ?? '' );
		$header_font_style  = self::sanitize_font_style( $attributes['headerFontStyle'] ?? '' );

		// Read and sanitize the six value theming attributes.
		$value_background  = self::sanitize_color( $attributes['valueBackground'] ?? '' );
		$value_color       = self::sanitize_color( $attributes['valueColor'] ?? '' );
		$value_font_family = self::sanitize_font_family( $attributes['valueFontFamily'] ?? '' );
		$value_font_size   = self::sanitize_font_size( $attributes['valueFontSize'] ?? '' );
		$value_font_weight = self::sanitize_font_weight( $attributes['valueFontWeight'] ?? '' );
		$value_font_style  = self::sanitize_font_style( $attributes['valueFontStyle'] ?? '' );

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

		// Fetch the cached statistics payload for the resolved attachment.
		$cache   = new Attachment_Cache();
		$payload = $cache->get( $resolved['attachment_id'] );
		if ( $payload instanceof Render_Error ) {
			return self::render_error( $payload );
		}

		// Assemble the non-empty theming values as CSS custom properties.
		$style_parts = [];

		if ( '' !== $header_background ) {
			$style_parts[] = '--kntnt-gpx-blocks-header-background: ' . $header_background;
		}
		if ( '' !== $header_color ) {
			$style_parts[] = '--kntnt-gpx-blocks-header-color: ' . $header_color;
		}
		if ( '' !== $header_font_family ) {
			$style_parts[] = '--kntnt-gpx-blocks-header-font-family: ' . $header_font_family;
		}
		if ( '' !== $header_font_size ) {
			$style_parts[] = '--kntnt-gpx-blocks-header-font-size: ' . $header_font_size;
		}
		if ( '' !== $header_font_weight ) {
			$style_parts[] = '--kntnt-gpx-blocks-header-font-weight: ' . $header_font_weight;
		}
		if ( '' !== $header_font_style ) {
			$style_parts[] = '--kntnt-gpx-blocks-header-font-style: ' . $header_font_style;
		}
		if ( '' !== $value_background ) {
			$style_parts[] = '--kntnt-gpx-blocks-value-background: ' . $value_background;
		}
		if ( '' !== $value_color ) {
			$style_parts[] = '--kntnt-gpx-blocks-value-color: ' . $value_color;
		}
		if ( '' !== $value_font_family ) {
			$style_parts[] = '--kntnt-gpx-blocks-value-font-family: ' . $value_font_family;
		}
		if ( '' !== $value_font_size ) {
			$style_parts[] = '--kntnt-gpx-blocks-value-font-size: ' . $value_font_size;
		}
		if ( '' !== $value_font_weight ) {
			$style_parts[] = '--kntnt-gpx-blocks-value-font-weight: ' . $value_font_weight;
		}
		if ( '' !== $value_font_style ) {
			$style_parts[] = '--kntnt-gpx-blocks-value-font-style: ' . $value_font_style;
		}

		// Build the <dl> rows from the statistics.
		$formatter = new Value_Formatter();
		$stats     = $payload['statistics'];

		return self::render_list( $stats, $formatter, $style_parts );

	}

	/**
	 * Builds the <dl> element from the statistics array.
	 *
	 * Always emits the "Total length" row. Elevation rows are emitted only when
	 * the corresponding statistic is not null (the track carries elevation data).
	 * Non-empty $style_parts are joined as inline style on the <dl> wrapper.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,float|null> $stats       Statistics from the cache payload.
	 * @param Value_Formatter          $formatter   Formatter for distance and elevation values.
	 * @param string[]                 $style_parts Non-empty CSS custom property declarations.
	 *
	 * @return string
	 */
	private static function render_list( array $stats, Value_Formatter $formatter, array $style_parts = [] ): string {

		// Build the inline style attribute when any theming property is set.
		$style_attr = '';
		if ( [] !== $style_parts ) {
			$style_attr = ' style="' . esc_attr( implode( '; ', $style_parts ) ) . '"';
		}

		// Start the definition list with the two required CSS classes.
		$html = '<dl class="wp-block-kntnt-gpx-blocks-statistics kntnt-gpx-blocks-statistics"' . $style_attr . '>';

		// "Total length" is always present regardless of elevation availability.
		$html .= self::render_item(
			__( 'Total length', 'kntnt-gpx-blocks' ),
			$formatter->format_distance( (float) ( $stats['distance'] ?? 0.0 ) ),
		);

		// Elevation rows are omitted when the track has no elevation data.
		if ( null !== ( $stats['min_elevation'] ?? null ) ) {
			$html .= self::render_item(
				__( 'Lowest elevation', 'kntnt-gpx-blocks' ),
				$formatter->format_elevation( (float) $stats['min_elevation'] ),
			);
		}
		if ( null !== ( $stats['max_elevation'] ?? null ) ) {
			$html .= self::render_item(
				__( 'Highest elevation', 'kntnt-gpx-blocks' ),
				$formatter->format_elevation( (float) $stats['max_elevation'] ),
			);
		}
		if ( null !== ( $stats['ascent'] ?? null ) ) {
			$html .= self::render_item(
				__( 'Total ascent', 'kntnt-gpx-blocks' ),
				$formatter->format_elevation( (float) $stats['ascent'] ),
			);
		}
		if ( null !== ( $stats['descent'] ?? null ) ) {
			$html .= self::render_item(
				__( 'Total descent', 'kntnt-gpx-blocks' ),
				$formatter->format_elevation( (float) $stats['descent'] ),
			);
		}

		$html .= '</dl>';

		return $html;

	}

	/**
	 * Builds a single <div><dt>…</dt><dd>…</dd></div> row.
	 *
	 * Both label and value are escaped at the point of output; the formatter
	 * already produces safe strings but the extra esc_html() is defense-in-depth.
	 *
	 * @since 1.0.0
	 *
	 * @param string $label Translated row label.
	 * @param string $value Formatted, localised value string.
	 *
	 * @return string
	 */
	private static function render_item( string $label, string $value ): string {

		return sprintf(
			'<div class="kntnt-gpx-blocks-statistics-item"><dt>%s</dt><dd>%s</dd></div>',
			esc_html( $label ),
			esc_html( $value ),
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
