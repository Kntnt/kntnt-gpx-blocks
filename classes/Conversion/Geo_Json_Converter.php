<?php
/**
 * Pure-PHP GeoJSON converter for parsed GPX tracks.
 *
 * Produces a GeoJSON FeatureCollection from a Track_Data: one LineString
 * Feature carrying the track polyline, plus zero or more Point Features for
 * waypoints. The converter linearly interpolates missing elevation values in
 * the LineString when at most 50% of points lack <ele>; above that threshold
 * it drops the third coordinate dimension entirely. Waypoint property bags
 * carry only the metadata fields that are present and non-empty in the
 * source. The converter never calls a WordPress function. See
 * docs/architecture.md § "Performance" and docs/caching.md
 * § "Conversion in five steps".
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Conversion;

/**
 * Converts a Track_Data into a GeoJSON FeatureCollection (PHP array).
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Geo_Json_Converter {

	/**
	 * Maximum fraction of trackpoints that may lack elevation while still
	 * producing 3D LineString coordinates. Above this fraction the third
	 * coordinate dimension is dropped from the entire LineString.
	 */
	private const MAX_MISSING_ELEVATION_FRACTION = 0.5;

	/**
	 * Builds the GeoJSON FeatureCollection for a parsed track.
	 *
	 * @since 1.0.0
	 *
	 * @param Track_Data $track Parsed track data.
	 *
	 * @return array{
	 *     type: string,
	 *     features: array<int, array<string, mixed>>
	 * }
	 */
	public function convert( Track_Data $track ): array {

		// Build the polyline feature first; it is always present.
		$features = [ $this->line_string_feature( $track->points ) ];

		// Then append a Point feature per waypoint, in source order.
		foreach ( $track->waypoints as $waypoint ) {
			$features[] = $this->point_feature( $waypoint );
		}

		return [
			'type'     => 'FeatureCollection',
			'features' => $features,
		];

	}

	/**
	 * Produces the LineString Feature for the trackpoints.
	 *
	 * Coordinates are 3D ([lon, lat, ele]) when at most half of the points
	 * lack <ele> in the source; the missing elevations are linearly
	 * interpolated between the nearest known neighbours. When more than half
	 * lack <ele>, the LineString is 2D ([lon, lat]) and elevation is omitted
	 * outright.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, Track_Point> $points Trackpoints in source order.
	 *
	 * @return array<string, mixed>
	 */
	private function line_string_feature( array $points ): array {

		// Decide between 3D and 2D output based on the missing-ele fraction.
		$total   = count( $points );
		$missing = $this->count_missing_elevations( $points );
		$is_3d   = $total > 0 && $missing / $total <= self::MAX_MISSING_ELEVATION_FRACTION;

		// In 3D mode, fill the gaps with linear interpolation.
		$coordinates = $is_3d
			? $this->coordinates_3d( $points )
			: $this->coordinates_2d( $points );

		return [
			'type'       => 'Feature',
			'properties' => (object) [],
			'geometry'   => [
				'type'        => 'LineString',
				'coordinates' => $coordinates,
			],
		];

	}

	/**
	 * Counts trackpoints with no elevation in the source.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, Track_Point> $points Trackpoints in source order.
	 *
	 * @return int
	 */
	private function count_missing_elevations( array $points ): int {

		$count = 0;
		foreach ( $points as $point ) {
			if ( null === $point->ele ) {
				++$count;
			}
		}

		return $count;

	}

	/**
	 * Produces a 2D coordinate list — [lon, lat] tuples in source order.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, Track_Point> $points Trackpoints in source order.
	 *
	 * @return array<int, array<int, float>>
	 */
	private function coordinates_2d( array $points ): array {

		$out = [];
		foreach ( $points as $point ) {
			$out[] = [ $point->lon, $point->lat ];
		}

		return $out;

	}

	/**
	 * Produces a 3D coordinate list — [lon, lat, ele] tuples — with
	 * missing elevations linearly interpolated between the nearest known
	 * neighbours. Endpoints whose closest neighbour is one-sided clamp to
	 * that neighbour's value.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, Track_Point> $points Trackpoints in source order.
	 *
	 * @return array<int, array<int, float>>
	 */
	private function coordinates_3d( array $points ): array {

		$elevations = $this->interpolate_elevations( $points );

		$out = [];
		foreach ( $points as $i => $point ) {
			$out[] = [ $point->lon, $point->lat, $elevations[ $i ] ];
		}

		return $out;

	}

	/**
	 * Linearly interpolates the elevation series.
	 *
	 * For every index whose source elevation is null, the value is the linear
	 * interpolation between the nearest known elevation to the left and the
	 * nearest known elevation to the right, weighted by index distance. When
	 * one side is missing entirely (the gap touches an endpoint), the value
	 * clamps to the elevation on the other side. This pre-condition cannot
	 * fail when called from coordinates_3d(): the caller has verified that at
	 * least one point has elevation via the ≤50% rule.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, Track_Point> $points Trackpoints in source order.
	 *
	 * @return array<int, float>
	 */
	private function interpolate_elevations( array $points ): array {

		// Project source elevations into a flat list so the indices line up.
		$source = [];
		foreach ( $points as $point ) {
			$source[] = $point->ele;
		}

		// Pre-compute, for every index, the nearest known neighbour on each
		// side. Two linear sweeps keep the whole pass O(n).
		[ $left_neighbours, $right_neighbours ] = $this->nearest_known_neighbours( $source );

		// Fill every gap from its pre-computed neighbours; known values pass through.
		$values = [];
		foreach ( $source as $i => $ele ) {

			if ( null !== $ele ) {
				$values[] = $ele;
				continue;
			}

			$values[] = $this->fill_gap( $source, $i, $left_neighbours[ $i ], $right_neighbours[ $i ] );

		}

		return $values;

	}

	/**
	 * Returns parallel arrays of nearest-known-neighbour indices.
	 *
	 * For every index in $source, $left[$i] holds the index of the nearest
	 * known elevation at a position strictly less than $i, or -1 when none
	 * exists; $right[$i] holds the same for positions strictly greater than
	 * $i, or -1 when none exists. Sentinel -1 keeps the return type a plain
	 * list of ints so PHPStan can prove array access at the call site.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, float|null> $source Elevation array.
	 *
	 * @return array{0: array<int, int>, 1: array<int, int>}
	 */
	private function nearest_known_neighbours( array $source ): array {

		$count = count( $source );
		$left  = array_fill( 0, $count, -1 );
		$right = array_fill( 0, $count, -1 );

		// Forward sweep: each cell carries the most-recent known index seen
		// to its left, or the prior cell's value when the current cell itself
		// is null.
		$seen = -1;
		for ( $i = 0; $i < $count; $i++ ) {
			$left[ $i ] = $seen;
			if ( null !== $source[ $i ] ) {
				$seen = $i;
			}
		}

		// Backward sweep: mirror image, looking right.
		$seen = -1;
		for ( $i = $count - 1; $i >= 0; $i-- ) {
			$right[ $i ] = $seen;
			if ( null !== $source[ $i ] ) {
				$seen = $i;
			}
		}

		return [ $left, $right ];

	}

	/**
	 * Fills a single null gap given the indices of its nearest known
	 * neighbours on either side.
	 *
	 * The pre-condition (at least one neighbour exists) is guaranteed by the
	 * ≤50% rule applied in the caller: the elevation series passed in here
	 * always has at least one non-null entry.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, float|null> $source Source elevation array (the
	 *                                       indexed neighbours are guaranteed
	 *                                       non-null floats).
	 * @param int                    $i      Gap index.
	 * @param int                    $left   Index of the left neighbour, or
	 *                                       -1 when none exists.
	 * @param int                    $right  Index of the right neighbour, or
	 *                                       -1 when none exists.
	 *
	 * @return float
	 */
	private function fill_gap( array $source, int $i, int $left, int $right ): float {

		// One-sided gaps clamp to the neighbour that does exist.
		if ( -1 === $left ) {
			return (float) $source[ $right ];
		}
		if ( -1 === $right ) {
			return (float) $source[ $left ];
		}

		// Two-sided: linear interpolation by index distance.
		$left_value  = (float) $source[ $left ];
		$right_value = (float) $source[ $right ];
		$ratio       = ( $i - $left ) / ( $right - $left );

		return $left_value + ( $right_value - $left_value ) * $ratio;

	}

	/**
	 * Produces the Point Feature for a single waypoint.
	 *
	 * Properties are compacted: only fields with a non-null, non-empty
	 * string value are included. The empty-string check protects callers
	 * from waypoints whose <name>/<sym>/<type>/<desc> children were
	 * present-but-empty in the source.
	 *
	 * @since 1.0.0
	 *
	 * @param Waypoint $waypoint Source waypoint.
	 *
	 * @return array<string, mixed>
	 */
	private function point_feature( Waypoint $waypoint ): array {

		// Compact the four metadata slots into a property bag.
		$properties = [];
		foreach ( [ 'name', 'sym', 'type', 'desc' ] as $field ) {
			$value = $waypoint->{$field};
			if ( null !== $value && '' !== $value ) {
				$properties[ $field ] = $value;
			}
		}

		return [
			'type'       => 'Feature',
			'properties' => empty( $properties ) ? (object) [] : $properties,
			'geometry'   => [
				'type'        => 'Point',
				'coordinates' => [ $waypoint->lon, $waypoint->lat ],
			],
		];

	}

}
