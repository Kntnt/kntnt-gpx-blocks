<?php
/**
 * Shared validator for editor-supplied colour attributes.
 *
 * Centralises the regex used by every block-side colour sanitizer in the
 * plugin so that the Map and Elevation blocks (and any future block) reach
 * for the same contract: hex 3, 4, 6, or 8 digits, or a strict single-arg
 * `var(--ident)` reference with an optional hex fallback — anything else
 * rejected. Returns the validated string verbatim on success and an empty
 * string on failure; callers branch on `'' !== $clean` before emitting the
 * inline CSS custom property, so the upstream SCSS default kicks in for
 * invalid input.
 *
 * Accepted formats here are deliberately minimal — `rgb()`, `rgba()`,
 * `hsl()`, named colours, theme preset slugs, nested `var()`, fallback
 * chains, and non-hex `var()` fallbacks are all rejected. The exhaustive
 * documentation of the final accepted-formats list is tracked in its own
 * follow-up issue.
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
	 * Regex matching `var(--ident)` and `var(--ident, #hex)` with strict bounds.
	 *
	 * Some WordPress versions and theme combinations cause `PanelColorSettings`
	 * to emit a `var(--wp--preset--color--…)` reference through `onChange` rather
	 * than a resolved hex; without this branch those values would silently fall
	 * back to the SCSS default. The grammar is deliberately narrow — single
	 * argument, ident must start with a letter or underscore, optional fallback
	 * is one hex literal in the same shapes the hex regex accepts, no nested
	 * `var()`, no fallback chains, no non-hex fallbacks. The anchors and the
	 * exhaustive character class block `;`, `}`, `"`, `'`, and any stray
	 * whitespace from sneaking into an inline `style=""` declaration.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const VAR_REGEX = '/^var\(--[a-zA-Z][a-zA-Z0-9_-]*(\s*,\s*#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8}))?\s*\)$/i';

	/**
	 * Returns the validated colour string, or an empty string on rejection.
	 *
	 * Non-string inputs (`null`, `int`, `array`, …) are rejected without
	 * attempting coercion. Both regexes are anchored on both ends, so attempts
	 * to smuggle CSS via concatenation (`#fff); color: red`) and URL-injection
	 * shapes (`javascript:alert(1)`) are rejected outright. The accepted forms
	 * are a hex literal (`#rgb` / `#rgba` / `#rrggbb` / `#rrggbbaa`) or a
	 * single-argument `var(--ident)` reference with an optional hex fallback.
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

		if ( preg_match( self::HEX_REGEX, $raw ) === 1 ) {
			return $raw;
		}

		return preg_match( self::VAR_REGEX, $raw ) === 1 ? $raw : '';

	}

}
