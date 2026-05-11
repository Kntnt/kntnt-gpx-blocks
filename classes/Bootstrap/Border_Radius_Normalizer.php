<?php
/**
 * Normalises `style.border.radius` on the plugin's two blocks so a uniform
 * radius survives the editor → frontend round trip even when the saved
 * attribute is the per-corner object form.
 *
 * Gutenberg's Border panel saves `style.border.radius` either as a string
 * (one value applied to all four corners) or as a per-corner object with
 * `topLeft`, `topRight`, `bottomLeft`, `bottomRight` keys. The editor's
 * preview occasionally renders all four corners rounded while the saved
 * attribute is the object form with one corner stored as the empty string —
 * issue #109. Core's style engine then emits CSS for the three non-empty
 * corners and silently skips the empty one, so the frontend wrapper has a
 * square corner where the preview had a rounded one.
 *
 * This class fixes that inconsistency by collapsing the per-corner object
 * to the unified shorthand when all *non-empty* corners agree on a value,
 * filling the empty corners with that value before the style engine runs.
 * When the non-empty corners genuinely disagree (per-corner intent), the
 * object is left untouched.
 *
 * Wired to `render_block_data` from `Plugin` so the mutation lands in the
 * `$block_to_render['attrs']` static that `get_block_wrapper_attributes()`
 * reads — patching the attributes inside the render callback itself would
 * be too late.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Bootstrap;

/**
 * Normalises `style.border.radius` for `kntnt-gpx-blocks/map` and
 * `kntnt-gpx-blocks/elevation` blocks before WordPress's render pipeline
 * serialises the border block-support to CSS.
 *
 * Hooked to `render_block_data` (priority 10, three parameters).
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Border_Radius_Normalizer {

	/**
	 * Block names the normalizer applies to.
	 *
	 * Listed explicitly rather than read from a registry so the filter
	 * short-circuits for every other block on the page at zero cost.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const BLOCK_NAMES = [
		'kntnt-gpx-blocks/map',
		'kntnt-gpx-blocks/elevation',
	];

	/**
	 * The four per-corner keys Gutenberg writes into `style.border.radius`
	 * when it saves the object form.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const CORNER_KEYS = [
		'topLeft',
		'topRight',
		'bottomLeft',
		'bottomRight',
	];

	/**
	 * Filter callback for `render_block_data`.
	 *
	 * Returns the parsed block array unchanged for blocks outside the
	 * plugin's two blocks. For the plugin's blocks, walks down to
	 * `attrs.style.border.radius` and rewrites the object form to a uniform
	 * string when the user's effective intent is a single value applied to
	 * all four corners (i.e. every non-empty corner carries the same value).
	 *
	 * The mutation is in-place via array assignment on the local copy of
	 * the parsed block; the filter returns the modified array, which
	 * `WP_Block::render()` writes back into the block's own state before
	 * `get_block_wrapper_attributes()` consults the global render context.
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
		if ( ! is_string( $block_name ) || ! in_array( $block_name, self::BLOCK_NAMES, true ) ) {
			return $parsed_block;
		}

		// Walk down to the radius attribute without coercing missing keys
		// into existence — the absence of any path segment is a no-op.
		$attrs = $parsed_block['attrs'] ?? null;
		if ( ! is_array( $attrs ) ) {
			return $parsed_block;
		}
		$style = $attrs['style'] ?? null;
		if ( ! is_array( $style ) ) {
			return $parsed_block;
		}
		$border = $style['border'] ?? null;
		if ( ! is_array( $border ) ) {
			return $parsed_block;
		}
		$radius = $border['radius'] ?? null;

		// Only the per-corner object form can exhibit the issue #109 shape.
		// String values (unified preset or unified length) already produce
		// the correct CSS shorthand.
		if ( ! is_array( $radius ) ) {
			return $parsed_block;
		}

		// Compute the normalised replacement and write it back when the
		// helper actually produced a change. The write rebuilds the three
		// nested arrays explicitly so PHPStan sees array-typed segments at
		// every step of the path — direct deep-write via subscript chains
		// fails offset-access analysis because the loaded values are read
		// as `mixed` from a `mixed`-typed parsed block.
		$normalised = self::normalise_radius_object( $radius );
		if ( $normalised === $radius ) {
			return $parsed_block;
		}

		$border['radius'] = $normalised;
		$style['border'] = $border;
		$attrs['style'] = $style;
		$parsed_block['attrs'] = $attrs;

		return $parsed_block;

	}

	/**
	 * Computes the normalised replacement for a per-corner radius object.
	 *
	 * The replacement is one of:
	 *  - A string — when every non-empty corner carries the same value.
	 *    The string is that uniform value, which the style engine emits as
	 *    the `border-radius` shorthand and applies to all four corners
	 *    regardless of whether the input had three or four non-empty corners.
	 *  - The original array — when corners genuinely disagree, when the
	 *    object is fully empty (nothing to normalise), or when the object
	 *    is already fully populated with the same value on every corner
	 *    (the style engine handles that case correctly).
	 *
	 * Empty values are filtered out using a single rule: `null`, empty
	 * string, and the integer `0` are treated as "not set". The string
	 * `'0'` is preserved because `0px` (CSS literal zero) is a meaningful
	 * value the user may have set explicitly per corner. Non-string,
	 * non-numeric values (arrays, objects) are out of contract for the
	 * radius schema; they are filtered out so a malformed attribute does
	 * not cause the comparison to misbehave.
	 *
	 * Exposed as a public static so the test suite can exercise the pure
	 * logic without going through the filter wiring.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int|string,mixed> $radius The per-corner radius object as
	 *                                        saved by the editor.
	 * @return array<int|string,mixed>|string The normalised value (string
	 *                                        when collapsible, otherwise the
	 *                                        original array).
	 */
	public static function normalise_radius_object( array $radius ): array|string {

		// Collect the non-empty corner values keyed by corner. Anything
		// outside the four documented keys is ignored — the style engine
		// would discard it anyway.
		$values = [];
		foreach ( self::CORNER_KEYS as $corner ) {
			$value = $radius[ $corner ] ?? null;
			if ( self::is_meaningful_corner_value( $value ) ) {
				$values[ $corner ] = $value;
			}
		}

		// Nothing to normalise — no corner carries a meaningful value, so
		// the result is empty either way. Pass through unchanged.
		if ( count( $values ) === 0 ) {
			return $radius;
		}

		// Compare every collected value against the first one. When any
		// differs, the user genuinely wants per-corner radii — leave the
		// object alone so each corner emits its own CSS declaration.
		// `reset()` reads back as `mixed` from the `<mixed>` array; the
		// downstream check that collapses to a string narrows back to a
		// string explicitly.
		$first = reset( $values );
		foreach ( $values as $value ) {
			if ( $value !== $first ) {
				return $radius;
			}
		}

		// Every non-empty corner agrees. When the object is already
		// uniformly populated (all four corners present), the style engine
		// produces the right output natively; return the array unchanged
		// so callers don't see a no-op rewrite. Otherwise collapse to the
		// shorthand string — issue #109's exact fix shape. The
		// `is_meaningful_corner_value()` gate above guarantees `$first` is
		// either a non-empty string or a non-zero numeric, so a non-string
		// is always representable as a numeric string.
		if ( count( $values ) === count( self::CORNER_KEYS ) ) {
			return $radius;
		}
		if ( is_string( $first ) ) {
			return $first;
		}
		if ( is_int( $first ) || is_float( $first ) ) {
			return (string) $first;
		}

		// Unreachable in practice — the meaningful-value gate filters
		// everything that is_string / is_int / is_float would not catch.
		// Returning the original array keeps the contract safe rather
		// than coercing through string() on a value of unknown shape.
		return $radius;

	}

	/**
	 * Decides whether a per-corner value counts as "set" for the purpose of
	 * normalisation.
	 *
	 * The style engine treats the same values as "missing" via
	 * `WP_Style_Engine::is_valid_style_value()` — empty string, integer 0,
	 * and `null` are dropped. The string `'0'` is special-cased as valid
	 * because `0px` is a legitimate corner radius the user may want.
	 * Arrays and objects don't belong in this schema; they are treated as
	 * empty to keep the normalisation deterministic.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw corner value from the saved attribute.
	 * @return bool True when the value is a meaningful, non-empty radius.
	 */
	private static function is_meaningful_corner_value( mixed $value ): bool {

		// Match the style engine's "valid value" rule exactly.
		if ( $value === '0' ) {
			return true;
		}

		// Only strings and numerics survive past this gate — radius values
		// from the editor are always one of those two shapes.
		if ( is_string( $value ) ) {
			return $value !== '';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value !== 0 && $value !== 0.0;
		}

		return false;

	}

}
