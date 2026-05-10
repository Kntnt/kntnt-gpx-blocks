<?php
/**
 * Opts the plugin's two blocks into the editor's Border panel.
 *
 * The Border panel only surfaces in the Design tab when the active theme
 * has declared an opt-in for one of the four border features — either
 * globally via `settings.appearanceTools: true`, or by setting
 * `settings.border.color` / `radius` / `style` / `width` to `true`. Blocks
 * may declare `supports.border = { color, radius, style, width }` in their
 * own `block.json`, but core's editor-side `useHasBorderPanel()` check
 * resolves the per-feature settings via `useSettings()`, which means the
 * theme decides whether the panel is visible. On themes that haven't
 * opted in (most third-party themes today), the GPX Map and GPX Elevation
 * blocks correctly declare full border supports yet the panel is hidden
 * — see issue #87.
 *
 * To keep the editor experience uniform across themes, the plugin emits
 * its own per-block opt-in by injecting a slice of theme.json into the
 * theme data layer through the `wp_theme_json_data_theme` filter. The
 * slice declares `settings.blocks["kntnt-gpx-blocks/map"].border = {…}`
 * (and likewise for the elevation block), which is the canonical
 * per-block opt-in route described in the theme.json reference. The
 * filter runs before the editor reads `useSettings()` for the block, so
 * the panel surfaces regardless of which theme is active. No global
 * settings are touched — the opt-in is scoped to the two blocks this
 * plugin owns.
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
	 * Both blocks declare the full `supports.border` quadruple in their
	 * `block.json`, so all four features are enabled here as well.
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
