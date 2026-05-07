<?php
/**
 * Server-side render handler for the GPX Statistics block.
 *
 * The full implementation — reading cached statistics, formatting values with
 * number_format_i18n() and auto-metric unit selection, building the <dl>
 * markup, and handling the no-elevation and error states — arrives in a later
 * issue. At this stub stage the class returns a placeholder string so that the
 * block can be inserted and the build pipeline can be verified.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rendering;

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
	 * Receives the same arguments that WordPress passes to a dynamic block
	 * render callback. At stub stage this returns a labelled placeholder `<dl>`;
	 * the real implementation builds the full statistics summary with formatted
	 * values.
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
		return '<dl class="wp-block-kntnt-gpx-blocks-statistics kntnt-gpx-blocks-statistics">'
			. '<div class="kntnt-gpx-blocks-statistics-item">'
			. '<dt>' . esc_html__( 'GPX Statistics — placeholder', 'kntnt-gpx-blocks' ) . '</dt>'
			. '<dd>—</dd>'
			. '</div>'
			. '</dl>';

	}

}
