<?php
/**
 * Largest Triangle Three Buckets downsampling.
 *
 * Reduces a 2D series to a target point count while preserving visually
 * significant peaks and valleys. Used by Render_Elevation to reduce the
 * full-fidelity (distance, elevation) array to ~300 points before serialising
 * the SVG polyline. See docs/architecture.md § "Elevation downsampling" and
 * Sveinn Steinarsson's 2013 thesis "Downsampling Time Series for Visual
 * Representation".
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rendering;

/**
 * Reduces a [[x, y], ...] series with the LTTB algorithm.
 *
 * Endpoints are always preserved. The algorithm is deterministic — ties on
 * triangle area are broken by the lowest source index, so identical input
 * yields identical output regardless of platform.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Lttb {

	/**
	 * Downsamples a 2D point series to the target point count.
	 *
	 * When $target is 2 or less, returns just the first and last points
	 * (or the entire array unchanged if it has fewer than two points). When
	 * the input is already at or below the target, it is returned unchanged.
	 * Otherwise the middle points are bucketed into ($target - 2) equal-size
	 * buckets and the point with the largest triangle area against the
	 * previously selected anchor and the next bucket's average is picked
	 * from each bucket.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array{0: float, 1: float}> $points Input 2D series.
	 * @param int                                   $target Desired output point count.
	 *
	 * @return array<int, array{0: float, 1: float}>
	 */
	public function downsample( array $points, int $target ): array {

		$count = count( $points );

		// Trivial cases — fewer points than the target, or 2-or-less target.
		if ( $count <= 2 ) {
			return $points;
		}
		if ( $target <= 2 ) {
			return [ $points[0], $points[ $count - 1 ] ];
		}
		if ( $count <= $target ) {
			return $points;
		}

		// First and last are always preserved; the middle goes through buckets.
		$result    = [ $points[0] ];
		$bucket_sz = ( $count - 2 ) / ( $target - 2 );

		// Walk one output bucket at a time. Each bucket picks the point with
		// the largest triangle area against the previously selected anchor and
		// the average point of the next bucket.
		$anchor = $points[0];
		for ( $i = 0; $i < $target - 2; $i++ ) {

			// Compute the inclusive index range of the current bucket and the
			// next bucket. The middle slice covers indices 1..($count - 2).
			$bucket_start = (int) floor( $i * $bucket_sz ) + 1;
			$bucket_end   = (int) floor( ( $i + 1 ) * $bucket_sz ) + 1;
			$bucket_end   = min( $bucket_end, $count - 1 );

			// Compute the average of the next bucket — used as the third
			// triangle vertex. The last bucket has no successor; fall back to
			// the final point.
			$next_start = $bucket_end;
			$next_end   = (int) floor( ( $i + 2 ) * $bucket_sz ) + 1;
			$next_end   = min( $next_end, $count - 1 );

			if ( $i === $target - 3 ) {
				$avg_x = $points[ $count - 1 ][0];
				$avg_y = $points[ $count - 1 ][1];
			} else {
				$avg_x = 0.0;
				$avg_y = 0.0;
				$len   = max( 1, $next_end - $next_start );
				for ( $j = $next_start; $j < $next_end; $j++ ) {
					$avg_x += $points[ $j ][0];
					$avg_y += $points[ $j ][1];
				}
				$avg_x /= $len;
				$avg_y /= $len;
			}

			// Pick the point in the current bucket whose triangle with the
			// anchor and the average point has the largest area. Ties are
			// broken by lowest source index (strict-greater preserves the
			// first-seen winner).
			$best_index = $bucket_start;
			$best_area  = -1.0;
			for ( $j = $bucket_start; $j < $bucket_end; $j++ ) {
				$area = abs(
					( $anchor[0] - $avg_x ) * ( $points[ $j ][1] - $anchor[1] )
					- ( $anchor[0] - $points[ $j ][0] ) * ( $avg_y - $anchor[1] )
				) * 0.5;
				if ( $area > $best_area ) {
					$best_area  = $area;
					$best_index = $j;
				}
			}

			$result[] = $points[ $best_index ];
			$anchor   = $points[ $best_index ];

		}

		// Always close with the last input point.
		$result[] = $points[ $count - 1 ];

		return $result;

	}

}
