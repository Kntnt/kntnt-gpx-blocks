<?php
/**
 * Adds panorama-friendly aspect-ratio presets to the Map and Elevation blocks.
 *
 * Core's Dimensions panel exposes seven default aspect ratios (1:1, 4:3, 3:4,
 * 3:2, 2:3, 16:9, 9:16). For the GPX Map (SCSS baseline `3/1`) and the GPX
 * Elevation block (`4/1`), the useful range is wider — panorama-style ratios
 * are the common case. This class injects six additional presets via the
 * `wp_theme_json_data_theme` filter, scoped to the plugin's two blocks under
 * `settings.blocks.<name>.dimensions.aspectRatios`.
 *
 * The merge is additive: core's `WP_Theme_JSON::merge_lists()` deduplicates by
 * `slug`, so the new entries append to whatever the active theme exposes. The
 * `kntnt-` slug prefix guarantees no collision with core's own slugs
 * (`square`, `wide`, `classic`, …). No other block on the site is affected.
 *
 * The stored value lives in the same `style.dimensions.aspectRatio` slot core
 * uses, so consumers and the SCSS path need no changes — the new ratios pass
 * through as plain `"W/H"` strings.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Bootstrap;

/**
 * Merges per-block aspect-ratio presets into the theme.json data layer.
 *
 * Wired to the `wp_theme_json_data_theme` filter (WP 6.6+ for the
 * `dimensions.aspectRatios` slot) by Plugin. The merge is additive: existing
 * theme settings are preserved, and the plugin only appends six entries to
 * the `dimensions.aspectRatios` list under its own two blocks.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Theme_Json_Aspect_Ratios {

	/**
	 * Block names the additional presets apply to.
	 *
	 * The list is identical to the Border opt-in's: only the plugin's two
	 * blocks. Other blocks on the page continue to see core's default
	 * aspect-ratio dropdown unchanged.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const BLOCK_NAMES = [
		'kntnt-gpx-blocks/map',
		'kntnt-gpx-blocks/elevation',
	];

	/**
	 * Injects the per-block aspect-ratio presets into the theme data layer.
	 *
	 * The filter passes a `WP_Theme_JSON_Data` whose `update_with()` merges the
	 * given array on top of the current theme.json data. The schema version is
	 * read from the existing data so the merge payload matches the wrapper's
	 * expectations regardless of which schema core is currently on.
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

		// Build the per-block presets payload. Both blocks receive the same
		// extended list; the presets are panorama-leaning because that's the
		// practical range for the GPX visualisations.
		$presets = self::presets();
		$blocks  = [];
		foreach ( self::BLOCK_NAMES as $name ) {
			$blocks[ $name ] = [
				'dimensions' => [
					'aspectRatios' => $presets,
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

	/**
	 * Builds the list of additional aspect-ratio presets.
	 *
	 * Each entry follows the theme.json schema for `dimensions.aspectRatios`:
	 * `slug` (kntnt-prefixed to guarantee uniqueness against core's defaults),
	 * `ratio` (the W/H string the dimensions panel writes into
	 * `style.dimensions.aspectRatio`), and `name` (the translatable label
	 * shown in the dropdown). The names are wrapped in `__()` so the .po/.mo
	 * pipeline can localise them.
	 *
	 * @since 1.0.0
	 *
	 * @return list<array{slug: string, ratio: string, name: string}>
	 */
	private static function presets(): array {

		return [
			[
				'slug'  => 'kntnt-5-4',
				'ratio' => '5/4',
				'name'  => __( 'Photo – 5:4', 'kntnt-gpx-blocks' ),
			],
			[
				'slug'  => 'kntnt-16-10',
				'ratio' => '16/10',
				'name'  => __( 'Widescreen – 16:10', 'kntnt-gpx-blocks' ),
			],
			[
				'slug'  => 'kntnt-21-9',
				'ratio' => '21/9',
				'name'  => __( 'Ultrawide – 21:9', 'kntnt-gpx-blocks' ),
			],
			[
				'slug'  => 'kntnt-2-1',
				'ratio' => '2/1',
				'name'  => __( 'Panorama – 2:1', 'kntnt-gpx-blocks' ),
			],
			[
				'slug'  => 'kntnt-3-1',
				'ratio' => '3/1',
				'name'  => __( 'Wide panorama – 3:1', 'kntnt-gpx-blocks' ),
			],
			[
				'slug'  => 'kntnt-4-1',
				'ratio' => '4/1',
				'name'  => __( 'Extra wide panorama – 4:1', 'kntnt-gpx-blocks' ),
			],
		];

	}

}
