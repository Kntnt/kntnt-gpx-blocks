<?php
/**
 * Locale-aware metric formatter for GPX statistics values.
 *
 * Formats distance (auto-metric: m below 1000, km above) and elevation (always
 * metres) using WordPress's number_format_i18n() so the decimal separator and
 * thousands separator honour the site's locale. Both formatters apply a filter
 * so downstream code can override the output for imperial units or custom precision.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Format;

/**
 * Formats raw float values for display through the GPX Statistics shortcode.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Value_Formatter {

	/**
	 * Distance threshold above which km is used instead of m.
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private const KILOMETRE_THRESHOLD = 1000.0;

	/**
	 * Formats a raw distance in metres for human display.
	 *
	 * Uses whole metres below the kilometre threshold and one-decimal kilometres
	 * at or above it. The formatted string is passed through the
	 * kntnt_gpx_blocks_format_distance filter so downstream code can substitute
	 * imperial units or alter precision without patching this class.
	 *
	 * @since 1.0.0
	 *
	 * @param float $metres Raw distance in metres.
	 *
	 * @return string Formatted, localised distance string suitable for esc_html().
	 */
	public function format_distance( float $metres ): string {

		// Format as whole metres or one-decimal kilometres depending on magnitude.
		$formatted = $metres < self::KILOMETRE_THRESHOLD
			? number_format_i18n( $metres, 0 ) . ' m'
			: number_format_i18n( $metres / 1000, 1 ) . ' km';

		// Allow the filter to substitute a different unit system or precision.
		$filtered = apply_filters( 'kntnt_gpx_blocks_format_distance', $formatted, $metres );
		return is_string( $filtered ) ? $filtered : $formatted;

	}

	/**
	 * Formats a raw elevation in metres for human display.
	 *
	 * Always displays whole metres with no unit conversion. The formatted string
	 * is passed through the kntnt_gpx_blocks_format_elevation filter.
	 *
	 * @since 1.0.0
	 *
	 * @param float $metres Raw elevation in metres.
	 *
	 * @return string Formatted, localised elevation string suitable for esc_html().
	 */
	public function format_elevation( float $metres ): string {

		// Always whole metres, no km conversion.
		$formatted = number_format_i18n( $metres, 0 ) . ' m';

		// Allow the filter to substitute feet or different precision.
		$filtered = apply_filters( 'kntnt_gpx_blocks_format_elevation', $formatted, $metres );
		return is_string( $filtered ) ? $filtered : $formatted;

	}

}
