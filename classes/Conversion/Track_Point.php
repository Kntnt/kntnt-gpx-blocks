<?php
/**
 * Value object representing a single point along a GPX track.
 *
 * Held by Track_Data after Gpx_Parser sanitises the source. Only points whose
 * latitude and longitude are valid finite numbers in the WGS-84 range survive
 * into a Track_Point — the parser drops malformed points silently. Elevation
 * is optional in GPX and remains nullable here; downstream code interpolates
 * or leaves it as null depending on coverage. See docs/caching.md
 * § "Conversion in five steps" for how this fits into the pipeline.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Conversion;

/**
 * Immutable record for a sanitised GPX trackpoint.
 *
 * Coordinates are guaranteed by Gpx_Parser to be finite floats in the ranges
 * lat ∈ [-90, 90] and lon ∈ [-180, 180]. Elevation is null when the source
 * trackpoint had no <ele> child.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final readonly class Track_Point {

	/**
	 * Constructs a sanitised trackpoint.
	 *
	 * @since 1.0.0
	 *
	 * @param float      $lat Latitude in decimal degrees, [-90, 90].
	 * @param float      $lon Longitude in decimal degrees, [-180, 180].
	 * @param float|null $ele Elevation in metres, or null when unknown.
	 */
	public function __construct(
		public float $lat,
		public float $lon,
		public ?float $ele,
	) {}

}
