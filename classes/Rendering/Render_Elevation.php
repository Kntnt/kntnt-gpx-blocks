<?php
/**
 * Server-side render handler for the GPX Elevation block.
 *
 * The full implementation — reading cached elevation data, building the SVG
 * chart, hydrating Interactivity state for cursor sync, and emitting a
 * screen-reader summary — arrives in a later issue. At this stub stage the
 * class returns a placeholder string so that the block can be inserted and
 * the build pipeline can be verified.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rendering;

/**
 * Produces the frontend HTML for the GPX Elevation block.
 *
 * Called by src/blocks/elevation/render.php with the three standard block
 * render arguments.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Render_Elevation {

	/**
	 * Returns the rendered HTML for a single GPX Elevation block instance.
	 *
	 * Receives the same arguments that WordPress passes to a dynamic block
	 * render callback. At stub stage this returns a labelled placeholder;
	 * the real implementation builds the SVG elevation profile and hydrates
	 * Interactivity state for cursor synchronisation.
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
		return '<div class="wp-block-kntnt-gpx-blocks-elevation kntnt-gpx-blocks-elevation">'
			. esc_html__( 'GPX Elevation — placeholder', 'kntnt-gpx-blocks' )
			. '</div>';

	}

}
