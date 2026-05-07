<?php
/**
 * Value object representing a single GPX waypoint.
 *
 * Distinct from Track_Point: waypoints are standalone points of interest that
 * carry user-authored metadata (name, symbol, type, description), where
 * trackpoints are samples along a recorded path. Coordinates are validated by
 * Gpx_Parser; the four metadata fields remain raw strings (never HTML-escaped)
 * because escaping happens at the point of output per the WordPress Coding
 * Standards.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Conversion;

/**
 * Immutable record for a sanitised GPX waypoint.
 *
 * Latitude and longitude are guaranteed by Gpx_Parser to be finite floats in
 * the WGS-84 range. The metadata fields are null when absent in the source;
 * they hold raw strings otherwise — escape on output, never on storage.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final readonly class Waypoint {

	/**
	 * Constructs a sanitised waypoint.
	 *
	 * @since 1.0.0
	 *
	 * @param float       $lat  Latitude in decimal degrees, [-90, 90].
	 * @param float       $lon  Longitude in decimal degrees, [-180, 180].
	 * @param string|null $name Human-readable label, or null when absent.
	 * @param string|null $sym  Symbol identifier (e.g. 'Flag, Blue'), or null.
	 * @param string|null $type Free-form classification, or null.
	 * @param string|null $desc Long-form description, or null.
	 */
	public function __construct(
		public float $lat,
		public float $lon,
		public ?string $name,
		public ?string $sym,
		public ?string $type,
		public ?string $desc,
	) {}

}
