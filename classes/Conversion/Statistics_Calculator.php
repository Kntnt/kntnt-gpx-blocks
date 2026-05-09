<?php
/**
 * Pure-PHP statistics calculator for parsed GPX tracks.
 *
 * Consumes a Track_Data and produces the five summary statistics: total
 * distance via Haversine summation, min and max elevation, and ascent and
 * descent via a hysteresis filter that rejects sub-threshold GPS noise. The
 * algorithm is framework-agnostic: the climb threshold is passed in as a
 * method parameter so the caller (Cache\Attachment_Cache::regenerate) is the
 * only place that touches WordPress filters. See docs/architecture.md
 * § "Performance" and docs/caching.md § "Conversion in five steps".
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Conversion;

/**
 * Computes summary statistics from a Track_Data.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Statistics_Calculator {

	/**
	 * Computes the five summary statistics for a parsed track.
	 *
	 * The returned shape matches the cache contract documented in
	 * docs/caching.md: distance is always a float; the four elevation-derived
	 * fields are null when the track lacks usable elevation data (no point has
	 * <ele>, or fewer than two points have it after dropping nulls).
	 *
	 * @since 1.0.0
	 *
	 * @param Track_Data $track            Parsed track data.
	 * @param float      $climb_threshold  Hysteresis threshold in metres.
	 *                                     Caller reads the
	 *                                     kntnt_gpx_blocks_climb_threshold_meters
	 *                                     filter and passes the value in.
	 *
	 * @return array{
	 *     distance: float,
	 *     min_elevation: float|null,
	 *     max_elevation: float|null,
	 *     ascent: float|null,
	 *     descent: float|null
	 * }
	 */
	public function calculate( Track_Data $track, float $climb_threshold = 3.0 ): array {

		// Haversine-sum distance over every consecutive pair of points.
		$distance = $this->total_distance( $track->points );

		// Pull out the non-null elevation samples for the elevation-derived stats.
		$elevations = $this->collect_elevations( $track->points );

		// With fewer than two known elevations, every elevation stat is null.
		if ( count( $elevations ) < 2 ) {
			return [
				'distance'      => $distance,
				'min_elevation' => null,
				'max_elevation' => null,
				'ascent'        => null,
				'descent'       => null,
			];
		}

		// Run the hysteresis filter for ascent/descent on the same elevations.
		[ $ascent, $descent ] = $this->climb( $elevations, $climb_threshold );

		return [
			'distance'      => $distance,
			'min_elevation' => min( $elevations ),
			'max_elevation' => max( $elevations ),
			'ascent'        => $ascent,
			'descent'       => $descent,
		];

	}

	/**
	 * Hysteresis climb filter — accumulates ascent and descent in metres.
	 *
	 * Walks the elevation series with a moving baseline. A delta is committed
	 * to ascent or descent only when its absolute value clears the threshold,
	 * at which point the baseline jumps to the current point. Each commit is
	 * provisional: if the elevation later returns to within ±threshold of the
	 * baseline that preceded the commit, the commit is undone — that
	 * excursion was sub-threshold wobble around the prior baseline, the
	 * dominant source of GPS noise. With threshold 0 the wobble check never
	 * fires (no value is strictly within ±0 except an exact match), which
	 * makes the filter equivalent to a naive sum of consecutive differences.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, float> $elevations Non-null elevations in source order.
	 * @param float             $threshold  Hysteresis threshold in metres.
	 *
	 * @return array{0: float, 1: float} Tuple of [ ascent, descent ] in metres.
	 */
	private function climb( array $elevations, float $threshold ): array {

		$ascent  = 0.0;
		$descent = 0.0;

		// Stack of provisional commits we may yet undo, with the baseline that
		// preceded each one. The current baseline is the latest element of
		// $baselines; cancellation pops both stacks in lockstep.
		$baselines = [ $elevations[0] ];
		$commits   = [];

		$count = count( $elevations );

		for ( $i = 1; $i < $count; $i++ ) {

			// Skip sub-threshold deltas — they neither commit nor confirm.
			$baseline = $baselines[ count( $baselines ) - 1 ];
			$delta    = $elevations[ $i ] - $baseline;
			if ( abs( $delta ) < $threshold ) {
				continue;
			}

			// Wobble detection: the current point sits within ±threshold of the
			// baseline that preceded the most recent commit, so that commit
			// turned out to be a transient and must be reverted.
			if ( ! empty( $commits ) ) {

				$prior_baseline = $baselines[ count( $baselines ) - 2 ];
				if ( abs( $elevations[ $i ] - $prior_baseline ) < $threshold ) {

					$last = array_pop( $commits );
					if ( 'ascent' === $last['type'] ) {
						$ascent -= $last['amount'];
					} else {
						$descent -= $last['amount'];
					}
					array_pop( $baselines );
					continue;

				}
			}

			// Commit the movement and advance the baseline.
			if ( $delta > 0 ) {
				$ascent   += $delta;
				$commits[] = [
					'type'   => 'ascent',
					'amount' => $delta,
				];
			} else {
				$descent  += -$delta;
				$commits[] = [
					'type'   => 'descent',
					'amount' => -$delta,
				];
			}
			$baselines[] = $elevations[ $i ];

		}

		return [ $ascent, $descent ];

	}

	/**
	 * Returns the non-null elevations from the points, preserving source order.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, Track_Point> $points Trackpoints in source order.
	 *
	 * @return array<int, float>
	 */
	private function collect_elevations( array $points ): array {

		$elevations = [];
		foreach ( $points as $point ) {
			if ( null !== $point->ele ) {
				$elevations[] = $point->ele;
			}
		}

		return $elevations;

	}

	/**
	 * Sums the great-circle distance between consecutive points in metres.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, Track_Point> $points Trackpoints in source order.
	 *
	 * @return float
	 */
	private function total_distance( array $points ): float {

		$total = 0.0;
		$count = count( $points );

		for ( $i = 1; $i < $count; $i++ ) {
			$total += Distance::haversine_meters(
				$points[ $i - 1 ]->lat,
				$points[ $i - 1 ]->lon,
				$points[ $i ]->lat,
				$points[ $i ]->lon,
			);
		}

		return $total;

	}

}
