<?php
/**
 * Shared validator for editor-supplied colour attributes.
 *
 * Centralises the regex used by every block-side colour sanitizer in the
 * plugin so that the Map and Elevation blocks (and any future block) reach
 * for the same contract: hex 3, 4, 6, or 8 digits — anything else rejected.
 * Returns the validated string verbatim on success and an empty string on
 * failure; callers branch on `'' !== $clean` before emitting the inline CSS
 * custom property, so the upstream SCSS default kicks in for invalid input.
 *
 * Accepted formats here are deliberately minimal — `rgb()`, `rgba()`,
 * `hsl()`, named colours, theme preset slugs, and `var()` references are all
 * rejected. The `ColorPicker` surfaces the editor exposes (with
 * `enableAlpha: true`) emit only resolved hex values, so accepting exactly
 * the four hex shapes covers every value that legitimately reaches the
 * sanitiser.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rendering;

/**
 * Validates colour-attribute values supplied by the block editor.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Color_Sanitizer {

	/**
	 * Regex matching `#rgb`, `#rgba`, `#rrggbb`, and `#rrggbbaa` (case-insensitive).
	 *
	 * The three-and-four-digit shorthand and the six-and-eight-digit longhand
	 * forms are the only colour spellings WordPress's `ColorPicker`
	 * (`enableAlpha: true`) writes, so accepting exactly these four shapes is
	 * sufficient for every colour control the plugin currently exposes.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const HEX_REGEX = '/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i';

	/**
	 * Returns the validated colour string, or an empty string on rejection.
	 *
	 * Non-string inputs (`null`, `int`, `array`, …) are rejected without
	 * attempting coercion. The regex is anchored on both ends, so attempts to
	 * smuggle CSS via concatenation (`#fff); color: red`) and URL-injection
	 * shapes (`javascript:alert(1)`) are rejected outright. The only accepted
	 * forms are the four hex literals (`#rgb` / `#rgba` / `#rrggbb` /
	 * `#rrggbbaa`).
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Raw attribute value as supplied by the block editor.
	 *
	 * @return string Validated colour, or `''` on invalid / non-string input.
	 */
	public static function sanitize( mixed $raw ): string {

		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		return preg_match( self::HEX_REGEX, $raw ) === 1 ? $raw : '';

	}

}
