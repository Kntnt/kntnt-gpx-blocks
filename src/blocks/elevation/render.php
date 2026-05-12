<?php
/**
 * GPX Elevation block render — Step 1 of the rebuild.
 *
 * Emits an empty wrapper through `get_block_wrapper_attributes()` plus a
 * one-rule inline `<style>` block that resolves the wrapper's
 * `--kntnt-gpx-blocks-elevation-background` CSS custom property to the
 * `background-color` declaration. Step 1 has no `style.scss` yet (no SVG,
 * no second consumer) so the rule lives inline in PHP; Step 3 introduces
 * a stylesheet and this echo goes away.
 *
 * The Background value is sanitised through `Color_Sanitizer::sanitize()`
 * before reaching the inline style — values outside hex 3/4/6/8 are
 * rejected to the empty string, which the conditional below collapses
 * back to "no custom property emitted" so the `transparent` fallback in
 * the inline stylesheet rule takes over.
 *
 * Pinned by `docs/elevation-rebuild.md`, Step 1, *Background wiring —
 * exact contract*.
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

use Kntnt\Gpx_Blocks\Rendering\Color_Sanitizer;

// Sanitise the editor-supplied background colour. Out-of-spec input
// (anything beyond hex 3/4/6/8) sanitises to the empty string; the inline
// CSS variable is then omitted and the `transparent` fallback in the
// embedded stylesheet rule applies.
$background = Color_Sanitizer::sanitize( $attributes['backgroundColor'] ?? '' );
$inline_style = $background !== ''
	? '--kntnt-gpx-blocks-elevation-background: ' . $background . ';'
	: '';

$wrapper_attributes = get_block_wrapper_attributes(
	[
		'class' => 'kntnt-gpx-blocks-elevation',
		'style' => $inline_style,
	]
);

// One-rule inline stylesheet that consumes the wrapper's custom property.
// The `transparent` fallback ensures an unset Background keeps the wrapper
// see-through, matching every other unstyled core block. Step 3 introduces
// `style.scss` and this inline echo migrates into the stylesheet.
echo '<style>.kntnt-gpx-blocks-elevation { background-color: var( --kntnt-gpx-blocks-elevation-background, transparent ); }</style>';

printf(
	'<div %s></div>',
	$wrapper_attributes // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns pre-escaped HTML attributes.
);
