<?php
/**
 * Aggregate value object for a fully parsed GPX file.
 *
 * Holds the sanitised output of Gpx_Parser: an ordered list of Track_Point
 * records concatenated across all <trkseg> elements (or all <rtept> elements
 * when the source is a route), and the unordered set of Waypoint records.
 * Downstream consumers — Statistics_Calculator, Geo_Json_Converter — read this
 * structure rather than touching XML or files. See docs/caching.md
 * § "Conversion in five steps" for the pipeline.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Conversion;

/**
 * Immutable container for the result of a successful GPX parse.
 *
 * Both arrays are zero-indexed and contiguous. The points array carries at
 * least two entries — Gpx_Parser throws 'too-few-points' otherwise. The
 * waypoints array may be empty.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final readonly class Track_Data {

	/**
	 * Constructs a parsed-track aggregate.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, Track_Point> $points    Trackpoints in source order.
	 * @param array<int, Waypoint>    $waypoints Waypoints in source order.
	 */
	public function __construct(
		public array $points,
		public array $waypoints,
	) {}

}
