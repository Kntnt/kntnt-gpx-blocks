<?php
/**
 * GPX Elevation block render — empty-slate baseline.
 *
 * WordPress calls this file for every frontend render of the block. During
 * the rebuild driven by `docs/elevation-rebuild.md`, the block renders a
 * minimal wrapper with a placeholder label; subsequent steps reintroduce
 * the data binding, the SVG chart, and the cursor synchronisation.
 *
 * Variables injected by WordPress:
 *   $attributes  array      Block attributes as saved in post_content.
 *   $content     string     Inner block HTML (empty — no inner blocks).
 *   $block       \WP_Block  The block instance, carrying block.json metadata.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'kntnt-gpx-blocks-elevation' ] );

printf(
	'<div %1$s>%2$s</div>',
	$wrapper_attributes, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns pre-escaped HTML attributes.
	esc_html__( 'GPX Elevation', 'kntnt-gpx-blocks' )
);
