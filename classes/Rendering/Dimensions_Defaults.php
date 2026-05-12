<?php
/**
 * Normalises the plugin-defined `min-height` default and strips the
 * blank-equivalent `aspectRatio: 'auto'` keyword on the two plugin blocks
 * at the attribute source.
 *
 * Issue #117 — earlier releases injected the default inline in each
 * consumer (PHP `Render_Map::render()`, editor `MapEdit`). That left the
 * default as a per-consumer special case that drifted out of sync with the
 * standard block-supports pipeline. This filter writes the per-block default
 * `min-height` (currently only Map's `30vh`; the GPX Elevation block carries
 * no default during the rebuild) onto the parsed block's `attrs` before
 * WordPress renders the block, so every downstream consumer —
 * `get_block_wrapper_attributes()`, the SCSS baseline, the editor
 * `useBlockProps()` style merge — sees a concrete value through the same
 * path that an explicit user value would take.
 *
 * The narrowed condition matters: the default is applied only when both
 * `minHeight` *and* `aspectRatio` are blank or missing. When the user
 * has picked a non-Original aspect-ratio, the container has a definite
 * height from that, and adding a min-height would fight the aspect-ratio
 * constraint.
 *
 * The filter still recognises Elevation blocks for the
 * `aspectRatio: 'auto'` strip — without that strip core would emit
 * `min-height: unset` and the wrapper would have no height — but the
 * `min-height` injection is gated on a per-block default being present, and
 * the Elevation block carries none during the rebuild.
 *
 * Wired to `render_block_data` from `Plugin` next to the other rendering
 * filters; this is the standard place WordPress lets a plugin mutate
 * the parsed block before the render pipeline reads it. The editor's
 * equivalent normalisation lives in `src/blocks/shared/dimensions-defaults.ts`.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rendering;

/**
 * Filter callback for `render_block_data` that injects the plugin's
 * default `min-height` onto a Map or Elevation block when both
 * `style.dimensions.minHeight` and `style.dimensions.aspectRatio` are
 * blank or missing.
 *
 * Hooked to `render_block_data` (priority 10, three parameters).
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Dimensions_Defaults {

	/**
	 * Per-block default `min-height` value applied when both
	 * `minHeight` and `aspectRatio` are blank/missing.
	 *
	 * Keyed by block name. Only the Map block carries a default — the
	 * Elevation block's wrapper-as-image layout (issue #135) is sized
	 * by `aspect-ratio` alone and needs no `min-height` baseline.
	 *
	 * @since 1.0.0
	 * @var array<string,string>
	 */
	private const DEFAULTS = [
		'kntnt-gpx-blocks/map' => '30vh',
	];

	/**
	 * Set of block names this filter recognises for the `aspectRatio: 'auto'`
	 * strip, regardless of whether the block carries a `min-height` default.
	 *
	 * Both plugin blocks need the strip because core's
	 * `wp_render_dimensions_support()` emits `min-height: unset` whenever
	 * `aspectRatio` is non-empty, which would override the SCSS baseline.
	 * The Map block additionally appears in {@see DEFAULTS}; the Elevation
	 * block (issue #135) does not.
	 *
	 * @since 1.0.0
	 * @var array<int,string>
	 */
	private const RECOGNISED_BLOCKS = [
		'kntnt-gpx-blocks/map',
		'kntnt-gpx-blocks/elevation',
	];

	/**
	 * Filter callback for `render_block_data`.
	 *
	 * Returns the parsed block array unchanged for every block that is
	 * not one of the plugin's two blocks, and for blocks where the user
	 * has already set `style.dimensions.minHeight` or
	 * `style.dimensions.aspectRatio`. Otherwise rewrites
	 * `attrs.style.dimensions.minHeight` to the per-block default.
	 *
	 * The mutation is applied through local-array reassignment so PHPStan
	 * sees array-typed segments at every step — direct deep-write via
	 * subscript chains fails offset-access analysis because the loaded
	 * values are read as `mixed` from a `mixed`-typed parsed block.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $parsed_block The block being rendered.
	 *                                          Keys include 'blockName',
	 *                                          'attrs', 'innerBlocks',
	 *                                          'innerHTML', 'innerContent'.
	 * @return array<string,mixed> The (possibly normalised) parsed block.
	 */
	public function filter( array $parsed_block ): array {

		// Only normalise blocks we own — every other block is untouched.
		$block_name = $parsed_block['blockName'] ?? null;
		if ( ! is_string( $block_name ) || ! in_array( $block_name, self::RECOGNISED_BLOCKS, true ) ) {
			return $parsed_block;
		}

		// Reach into `attrs.style.dimensions` without coercing missing
		// path segments into existence — a missing segment is treated as
		// both fields being blank, the same as an explicit empty string.
		$attrs      = is_array( $parsed_block['attrs'] ?? null ) ? $parsed_block['attrs'] : [];
		$style      = is_array( $attrs['style'] ?? null ) ? $attrs['style'] : [];
		$dimensions = is_array( $style['dimensions'] ?? null ) ? $style['dimensions'] : [];

		// Classify each field. `aspectRatio` is considered blank when it is
		// `null`, the empty string, or the CSS keyword `'auto'` — the value
		// WordPress writes when the user picks the "Original" option in the
		// aspect-ratio dropdown after having selected another ratio.
		// `'auto'` is semantically "no aspect-ratio constraint", the same
		// end-state as a blank value.
		$min_height_blank   = self::is_blank( $dimensions['minHeight'] ?? null );
		$aspect_ratio_blank = self::is_blank_aspect_ratio( $dimensions['aspectRatio'] ?? null );

		// Strip `aspectRatio` from attrs when it counts as blank but is
		// still present as a stored value (the `'auto'` case is the one
		// we care about; `''` and missing are no-ops here). WordPress core's
		// `wp_render_dimensions_support()` appends `min-height: unset` to
		// the wrapper's inline style whenever this attribute is non-empty
		// regardless of value, which would override any min-height we
		// inject below (and any explicit user-set min-height too). Removing
		// the key keeps core's `! empty()` check false and skips the
		// `min-height: unset` emit. Saved post content is untouched —
		// the strip happens only on the in-memory parsed block.
		$strip_aspect_ratio = $aspect_ratio_blank && array_key_exists( 'aspectRatio', $dimensions );
		if ( $strip_aspect_ratio ) {
			unset( $dimensions['aspectRatio'] );
		}

		// Inject the per-block `min-height` default when both fields end
		// up blank, but only for blocks that carry one. With `aspectRatio`
		// stripped above, the Map block's wrapper has no height
		// constraint of its own — the default keeps it from collapsing
		// to zero pixels. The Elevation block (issue #135) is sized by
		// `aspect-ratio` alone from the SCSS baseline and is intentionally
		// absent from {@see DEFAULTS}, so no `min-height` injection runs
		// on its branch.
		$has_default    = array_key_exists( $block_name, self::DEFAULTS );
		$inject_default = $has_default && $min_height_blank && $aspect_ratio_blank;
		if ( $inject_default ) {
			$dimensions['minHeight'] = self::DEFAULTS[ $block_name ];
		}

		// Skip the write-back when neither mutation applied. Pass through
		// the original `$parsed_block` reference so PHPUnit `===` checks
		// in upstream tests can still assert byte-identity.
		if ( ! $strip_aspect_ratio && ! $inject_default ) {
			return $parsed_block;
		}

		$style['dimensions']   = $dimensions;
		$attrs['style']        = $style;
		$parsed_block['attrs'] = $attrs;

		return $parsed_block;

	}

	/**
	 * Decides whether a `style.dimensions.*` value counts as "not set".
	 *
	 * The block editor stores an unset dimensions field as either the
	 * literal empty string or as the missing-key absence (the field is
	 * dropped from the saved object entirely). Both shapes map to true
	 * here. Any other shape — a non-empty string, a numeric value, an
	 * array — is treated as a real user-chosen value and short-circuits
	 * the default injection.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw value read from the dimensions object.
	 * @return bool True when the value should be treated as blank.
	 */
	private static function is_blank( mixed $value ): bool {
		return $value === null || $value === '';
	}

	/**
	 * Decides whether a `style.dimensions.aspectRatio` value counts as
	 * "no ratio set".
	 *
	 * Extends `is_blank()` with the CSS keyword `'auto'`. WordPress writes
	 * `'auto'` to `style.dimensions.aspectRatio` when the user picks the
	 * "Original" option in the aspect-ratio dropdown after having selected
	 * another ratio first. Semantically `'auto'` means "no aspect-ratio
	 * constraint" — the same end-state as a blank or missing value — so
	 * the per-block default `min-height` should still apply.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw value read from
	 *                     `attrs.style.dimensions.aspectRatio`.
	 * @return bool True when the value should be treated as no ratio set.
	 */
	private static function is_blank_aspect_ratio( mixed $value ): bool {
		return self::is_blank( $value ) || $value === 'auto';
	}

}
