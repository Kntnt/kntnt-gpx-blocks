<?php
/**
 * GPX Elevation block render proxy.
 *
 * Thin proxy that delegates the frontend HTML to
 * `Rendering\Render_Elevation`. The render class walks the host post's
 * block tree, resolves the bound `mapId`, reads the cached statistics,
 * and emits either the warning placeholder or the info placeholder
 * documented in Step 2 of `docs/elevation-rebuild.md`.
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

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Render_Elevation is responsible for escaping its output.
echo \Kntnt\Gpx_Blocks\Rendering\Render_Elevation::render( $attributes, $content, $block );
