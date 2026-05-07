<?php
/**
 * Douglas-Peucker polyline simplification.
 *
 * Reduces the number of points in a geographic polyline while preserving its
 * visual shape. Applied to the full-fidelity GeoJSON LineString at render time
 * so the browser receives a compact payload without degrading the cached data.
 * See docs/architecture.md § "Track simplification".
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rendering;

/**
 * Reduces a [lat, lon(, ele)] polyline with the Douglas-Peucker algorithm.
 *
 * The tolerance is expressed in metres. A flat-earth approximation converts
 * degree offsets to metres using the centroid latitude, which is accurate
 * to within 0.5 % at latitudes below 70 °N — sufficient for the smoothing
 * use-case here.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Douglas_Peucker {

	/**
	 * Metres per degree of latitude.  Constant across the globe to the
	 * precision needed here.
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private const METERS_PER_LAT_DEG = 111320.0;

	/**
	 * Simplifies a polyline by removing points within the tolerance distance.
	 *
	 * $points is an array of [lat, lon] or [lat, lon, ele] sub-arrays.
	 * Endpoints are always preserved. Third elements (elevation) are carried
	 * through unchanged on the kept points. Fewer than three input points are
	 * returned as-is.
	 *
	 * @since 1.0.0
	 *
	 * @param float[][] $points           Input polyline ([lat, lon] or [lat, lon, ele]).
	 * @param float     $tolerance_meters Max deviation in metres.
	 *
	 * @return float[][] Simplified polyline with the same point shape.
	 */
	public function simplify( array $points, float $tolerance_meters ): array {

		// Trivial cases — nothing to simplify.
		if ( count( $points ) <= 2 ) {
			return $points;
		}

		return $this->rdp( $points, $tolerance_meters );

	}

	/**
	 * Recursive core of the Douglas-Peucker algorithm.
	 *
	 * Finds the point with the maximum perpendicular distance from the line
	 * formed by the first and last points. When the maximum is below the
	 * tolerance, only the endpoints are kept. Otherwise the sequence is split
	 * at the maximum point and each half is recursed independently.
	 *
	 * @since 1.0.0
	 *
	 * @param float[][] $points           Sub-array of the polyline.
	 * @param float     $tolerance_meters Tolerance in metres.
	 *
	 * @return float[][]
	 */
	private function rdp( array $points, float $tolerance_meters ): array {

		$n     = count( $points );
		$first = $points[0];
		$last  = $points[ $n - 1 ];

		// Precompute the scale factors at the centroid latitude once per call
		// to avoid repeating the trig inside the distance loop.
		$centroid_lat    = ( $first[0] + $last[0] ) / 2.0;
		$meters_per_lon  = self::METERS_PER_LAT_DEG * cos( deg2rad( $centroid_lat ) );
		$meters_per_lat  = self::METERS_PER_LAT_DEG;

		// Convert endpoints to flat-earth metres for the distance calculation.
		$ax = $first[1] * $meters_per_lon;
		$ay = $first[0] * $meters_per_lat;
		$bx = $last[1] * $meters_per_lon;
		$by = $last[0] * $meters_per_lat;

		// Find the interior point with the greatest perpendicular distance from the AB line.
		$max_distance = 0.0;
		$max_index    = 0;
		$line_len_sq  = ( $bx - $ax ) ** 2 + ( $by - $ay ) ** 2;

		for ( $i = 1; $i < $n - 1; $i++ ) {
			$px = $points[ $i ][1] * $meters_per_lon;
			$py = $points[ $i ][0] * $meters_per_lat;

			$distance = $this->perpendicular_distance( $px, $py, $ax, $ay, $bx, $by, $line_len_sq );
			if ( $distance > $max_distance ) {
				$max_distance = $distance;
				$max_index    = $i;
			}
		}

		// All interior points are within tolerance — keep only the endpoints.
		if ( $max_distance < $tolerance_meters ) {
			return [ $first, $last ];
		}

		// Split at the furthest point and recurse on both halves, then join without
		// duplicating the shared split point.
		$left  = $this->rdp( array_slice( $points, 0, $max_index + 1 ), $tolerance_meters );
		$right = $this->rdp( array_slice( $points, $max_index ), $tolerance_meters );

		// array_slice of the right half starts at $max_index, so its first element
		// duplicates the last element of $left — drop it before merging.
		return array_merge( $left, array_slice( $right, 1 ) );

	}

	/**
	 * Returns the perpendicular distance (in the same unit as the inputs) from
	 * point P to the line through A and B.
	 *
	 * When A and B coincide (zero-length line), returns the Euclidean distance
	 * from P to A instead.
	 *
	 * @since 1.0.0
	 *
	 * @param float $px         Point x.
	 * @param float $py         Point y.
	 * @param float $ax         Line start x.
	 * @param float $ay         Line start y.
	 * @param float $bx         Line end x.
	 * @param float $by         Line end y.
	 * @param float $line_len_sq Pre-computed squared length of AB.
	 *
	 * @return float
	 */
	private function perpendicular_distance(
		float $px,
		float $py,
		float $ax,
		float $ay,
		float $bx,
		float $by,
		float $line_len_sq,
	): float {

		// Degenerate case: A and B are the same point.
		if ( $line_len_sq === 0.0 ) {
			return sqrt( ( $px - $ax ) ** 2 + ( $py - $ay ) ** 2 );
		}

		// Standard cross-product formula: |AP × AB| / |AB|.
		$cross = ( $px - $ax ) * ( $by - $ay ) - ( $py - $ay ) * ( $bx - $ax );
		return abs( $cross ) / sqrt( $line_len_sq );

	}

}
