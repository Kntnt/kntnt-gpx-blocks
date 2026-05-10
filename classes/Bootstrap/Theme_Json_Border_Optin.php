<?php
/**
 * Opts the plugin's two blocks into the editor's Border panel.
 *
 * Gutenberg gates the Border panel behind two independent flags, and
 * both must be on for the panel to surface. This class is the second
 * half of that two-gate model — see `block.json` `supports.__experimentalBorder`
 * for the first half:
 *
 * 1. **Block-support key** (the block's responsibility). Each block's
 *    `block.json` must declare `supports.__experimentalBorder`. The
 *    key is read by `getBlockSupport( blockName, '__experimentalBorder' )`
 *    in `packages/block-editor/src/hooks/border.js` (constant
 *    `BORDER_SUPPORT_KEY`); the unprefixed `border` key is silently
 *    ignored, so the editor never registers the block's
 *    `style.border` / `borderColor` attributes — issue #107.
 * 2. **Theme.json opt-in** (this class's responsibility). Even with
 *    the block-support key correct, the editor-side
 *    `useHasBorderColorControl()` (and its three siblings for radius,
 *    style, width) reads `settings?.border?.color` etc. via
 *    `useSettings()`. On themes that haven't enabled appearance tools
 *    or per-feature border settings, those reads return `false` and
 *    the panel disappears — issue #87.
 *
 * This class injects a per-block theme.json slice that flips the
 * second gate for the plugin's own blocks, regardless of which theme
 * is active. It writes `settings.blocks["kntnt-gpx-blocks/map"].border
 * = { color, radius, style, width: true }` (and the same for
 * `kntnt-gpx-blocks/elevation`) via the `wp_theme_json_data_theme`
 * filter — the canonical per-block opt-in route in the theme.json
 * reference. The theme.json schema uses the unprefixed `border` key
 * here (this is the public schema, not the editor's internal
 * block-support registry — different surface, different key).
 *
 * The filter is additive: it touches only the four border flags under
 * the plugin's two blocks. No global theme settings are modified.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Bootstrap;

/**
 * Merges per-block border opt-in into the theme.json data layer.
 *
 * Wired to the `wp_theme_json_data_theme` filter (WP 6.1+) by Plugin.
 * The merge is additive: existing theme settings are preserved, and the
 * plugin adds only the four `border.*` flags under its own two blocks.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Theme_Json_Border_Optin {

	/**
	 * Block names this opt-in applies to.
	 *
	 * Both blocks declare the full `supports.__experimentalBorder`
	 * quadruple in their `block.json`, so all four features are enabled
	 * here as well.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const BLOCK_NAMES = [
		'kntnt-gpx-blocks/map',
		'kntnt-gpx-blocks/elevation',
	];

	/**
	 * Injects the per-block border opt-in into the theme data layer.
	 *
	 * The filter passes a `WP_Theme_JSON_Data` whose `update_with()` merges
	 * the given array on top of the current theme.json data, deep-merging
	 * the `settings.blocks.<name>` slice we care about. The `version` key
	 * is required by the merge — `WP_Theme_JSON::LATEST_SCHEMA` would be
	 * the most correct source, but matching the data object's existing
	 * `version` via `get_data()` is the resilient route across WP versions
	 * and keeps the filter free of version coupling.
	 *
	 * The method returns the same `WP_Theme_JSON_Data` instance with the
	 * merged payload, as the filter contract requires.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Theme_JSON_Data $theme_json The theme's theme.json data wrapper.
	 * @return \WP_Theme_JSON_Data
	 */
	public function filter( \WP_Theme_JSON_Data $theme_json ): \WP_Theme_JSON_Data {

		// Resolve the schema version from the existing data so the merge
		// payload matches the wrapper's expectations regardless of which
		// theme.json schema version core is currently on.
		$existing = $theme_json->get_data();
		$version  = is_array( $existing ) && isset( $existing['version'] ) && is_int( $existing['version'] )
			? $existing['version']
			: 2;

		// Build the per-block border opt-in. Each entry enables all four
		// border features so the Border panel exposes the same quadruple
		// the block's `supports.border` declaration promises.
		$blocks = [];
		foreach ( self::BLOCK_NAMES as $name ) {
			$blocks[ $name ] = [
				'border' => [
					'color'  => true,
					'radius' => true,
					'style'  => true,
					'width'  => true,
				],
			];
		}

		// Merge the slice into the theme data layer and return the wrapper
		// for the filter chain.
		return $theme_json->update_with(
			[
				'version'  => $version,
				'settings' => [
					'blocks' => $blocks,
				],
			]
		);

	}

}
