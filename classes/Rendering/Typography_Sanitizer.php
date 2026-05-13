<?php
/**
 * Allow-list sanitisers for the eight CSS typography properties the
 * block editor's TypographyToolsPanel surfaces.
 *
 * Sibling of {@see Color_Sanitizer}: a small, dependency-free utility
 * class that any renderer can call to validate raw attribute values
 * before threading them into wrapper-level CSS custom properties.
 *
 * Each method takes the raw attribute value and returns either the
 * value verbatim (when it matches the property's strict allow-list)
 * or an empty string. Callers treat the empty string as "the user
 * has not chosen a value" and omit the corresponding custom property
 * from the wrapper's inline style.
 *
 * The allow-lists mirror the keyword sets and value shapes the
 * TypographyToolsPanel produces. Composite or shorthand values that
 * the panel does not emit are rejected by design — accepting them
 * would broaden the CSS-injection surface without serving any UI
 * affordance.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rendering;

/**
 * Validates raw block-attribute values for the eight CSS typography
 * properties.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Typography_Sanitizer {

	/**
	 * Validates a CSS `font-family` value against a strict whitelist.
	 *
	 * Accepts common font name strings and theme-preset CSS variable
	 * references. Returns empty string on anything that could inject
	 * CSS or HTML.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Raw attribute value.
	 * @return string Validated font-family string or empty string.
	 */
	public static function font_family( mixed $raw ): string {

		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		// Accept a CSS theme-preset font-family reference.
		if ( preg_match( '/^var\(--wp--preset--font-family--[a-z0-9-]+\)$/', $raw ) ) {
			return $raw;
		}

		// Accept font family names composed of letters, digits, spaces, commas,
		// hyphens, quotes, and parentheses — the characters that appear in valid
		// CSS font-family stacks.
		if ( preg_match( "/^[A-Za-z0-9\\s,\\-'\"()]+$/", $raw ) ) {
			return $raw;
		}

		return '';

	}

	/**
	 * Validates a CSS `font-size` value against a strict whitelist.
	 *
	 * Accepts numeric CSS length values (px, em, rem, %) and theme-preset
	 * font-size references. Returns empty string on anything unsafe.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Raw attribute value.
	 * @return string Validated font-size string or empty string.
	 */
	public static function font_size( mixed $raw ): string {

		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		// Accept a CSS theme-preset font-size reference.
		if ( preg_match( '/^var\(--wp--preset--font-size--[a-z0-9-]+\)$/', $raw ) ) {
			return $raw;
		}

		// Accept numeric lengths: optional decimal followed by a CSS length unit.
		if ( preg_match( '/^(\d+(\.\d+)?)(px|em|rem|%)?$/', $raw ) ) {
			return $raw;
		}

		return '';

	}

	/**
	 * Validates a CSS `font-weight` value against the accepted
	 * keyword/numeric whitelist.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Raw attribute value.
	 * @return string Validated font-weight string or empty string.
	 */
	public static function font_weight( mixed $raw ): string {

		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		if ( preg_match( '/^(normal|bold|lighter|bolder|[1-9]00)$/', $raw ) ) {
			return $raw;
		}

		return '';

	}

	/**
	 * Validates a CSS `font-style` value against the accepted keyword
	 * whitelist.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Raw attribute value.
	 * @return string Validated font-style string or empty string.
	 */
	public static function font_style( mixed $raw ): string {

		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		if ( preg_match( '/^(normal|italic|oblique)$/', $raw ) ) {
			return $raw;
		}

		return '';

	}

	/**
	 * Validates a CSS `line-height` value.
	 *
	 * Accepts unitless multipliers (e.g. `1.5`), numeric lengths with
	 * the common length units, and the keyword `normal`. Returns empty
	 * string on anything else so the CSS falls back to the SCSS default.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Raw attribute value.
	 * @return string Validated line-height string or empty string.
	 */
	public static function line_height( mixed $raw ): string {

		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		if ( 'normal' === $raw ) {
			return $raw;
		}

		// Unitless multiplier or a numeric length with px/em/rem/%.
		if ( preg_match( '/^(\d+(\.\d+)?)(px|em|rem|%)?$/', $raw ) ) {
			return $raw;
		}

		return '';

	}

	/**
	 * Validates a CSS `letter-spacing` value.
	 *
	 * Accepts numeric values with `px`, `em`, `rem`, or `%`, an optional
	 * leading minus sign (negative letter-spacing is meaningful), and
	 * the keyword `normal`. Anything else is rejected.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Raw attribute value.
	 * @return string Validated letter-spacing string or empty string.
	 */
	public static function letter_spacing( mixed $raw ): string {

		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		if ( 'normal' === $raw ) {
			return $raw;
		}

		// Optional leading minus, then digits with optional fraction, with px/em/rem/%.
		if ( preg_match( '/^-?\d+(\.\d+)?(px|em|rem|%)$/', $raw ) ) {
			return $raw;
		}

		return '';

	}

	/**
	 * Validates a CSS `text-transform` value against the accepted
	 * keyword whitelist.
	 *
	 * Matches the keyword set core's TypographyToolsPanel offers
	 * (`none`, `uppercase`, `lowercase`, `capitalize`).
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Raw attribute value.
	 * @return string Validated text-transform string or empty string.
	 */
	public static function text_transform( mixed $raw ): string {

		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		if ( preg_match( '/^(none|uppercase|lowercase|capitalize)$/', $raw ) ) {
			return $raw;
		}

		return '';

	}

	/**
	 * Validates a CSS `text-decoration` value against the accepted
	 * keyword whitelist.
	 *
	 * Limited to the four single-keyword values core's
	 * TypographyToolsPanel surfaces (`none`, `underline`, `overline`,
	 * `line-through`). Composite values like `underline dotted red`
	 * are not exposed by the panel and are rejected here for safety.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Raw attribute value.
	 * @return string Validated text-decoration string or empty string.
	 */
	public static function text_decoration( mixed $raw ): string {

		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		if ( preg_match( '/^(none|underline|overline|line-through)$/', $raw ) ) {
			return $raw;
		}

		return '';

	}

}
