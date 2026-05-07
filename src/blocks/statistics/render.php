<?php
/**
 * GPX Statistics block render proxy.
 *
 * WordPress calls this file for every frontend render of the block, passing
 * the three standard render-callback arguments as variables. The file is a
 * thin proxy — it delegates all logic to the autoloaded Render_Statistics
 * class so that the render.php files stay trivial and easy to reason about.
 *
 * Variables injected by WordPress:
 *   $attributes  array      Block attributes as saved in post_content.
 *   $content     string     Inner block HTML (empty — this block has no inner blocks).
 *   $block       \WP_Block  The block instance, carrying block.json metadata.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Render_Statistics is responsible for escaping its output.
echo \Kntnt\Gpx_Blocks\Rendering\Render_Statistics::render( $attributes, $content, $block );
