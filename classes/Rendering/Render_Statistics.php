<?php
/**
 * Server-side render handler for the GPX Statistics block.
 *
 * Resolves the linked GPX Map, reads the pre-computed statistics from the
 * attachment cache, and emits a <dl> with up to five localised summary rows.
 * The block has no frontend JavaScript — values are entirely server-rendered.
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

		// Build the <dl> rows from the statistics.
		$formatter = new Value_Formatter();
		$stats     = $payload['statistics'];

		return self::render_list( $stats, $formatter );

	}

	/**
	 * Builds the <dl> element from the statistics array.
	 *
	 * Always emits the "Total length" row. Elevation rows are emitted only when
	 * the corresponding statistic is not null (the track carries elevation data).
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,float|null> $stats     Statistics from the cache payload.
	 * @param Value_Formatter          $formatter Formatter for distance and elevation values.
	 *
	 * @return string
	 */
	private static function render_list( array $stats, Value_Formatter $formatter ): string {

		// Start the definition list with the two required CSS classes.
		$html = '<dl class="wp-block-kntnt-gpx-blocks-statistics kntnt-gpx-blocks-statistics">';

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

}
