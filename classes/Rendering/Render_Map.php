<?php
/**
 * Server-side render handler for the GPX Map block.
 *
 * The full implementation — reading cached GeoJSON, hydrating Interactivity
 * state, building the Leaflet container, and gating on consent — arrives in a
 * later issue. At this stub stage the class returns a placeholder string so
 * that the block can be inserted and the build pipeline can be verified.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rendering;

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
	 * Returns the rendered HTML for a single GPX Map block instance.
	 *
	 * Receives the same arguments that WordPress passes to a dynamic block
	 * render callback. At stub stage this returns a labelled placeholder;
	 * the real implementation builds the Leaflet container and hydrates
	 * Interactivity state.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $attributes Block attributes from post_content.
	 * @param string              $content    Inner block HTML (always empty for this block).
	 * @param \WP_Block           $block      The block instance carrying block.json metadata.
	 * @return string Escaped HTML ready for output.
	 */
	public static function render( array $attributes, string $content, \WP_Block $block ): string {

		// Return a placeholder until the full render is implemented.
		return '<div class="wp-block-kntnt-gpx-blocks-map kntnt-gpx-blocks-map">'
			. esc_html__( 'GPX Map — placeholder', 'kntnt-gpx-blocks' )
			. '</div>';

	}

}
