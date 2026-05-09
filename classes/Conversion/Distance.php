<?php
/**
 * Pure-PHP great-circle distance helpers shared across the plugin.
 *
 * Centralises the Haversine formula and the cumulative-distance walk used by
 * Statistics_Calculator (total distance) and the upcoming Render_Map cursor
 * sync (per-vertex distances along the original track). Earth radius is fixed
 * at 6 371 000 m to match the values cached in attachment post-meta.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Conversion;

/**
 * Static helpers for great-circle distance over a track.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Distance {

	/**
	 * Earth radius in metres used for Haversine summation.
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private const EARTH_RADIUS_METERS = 6371000.0;

	/**
	 * Great-circle distance between two lat/lon pairs in metres.
	 *
	 * Standard Haversine on a sphere of radius 6 371 000 m. The plugin treats
	 * this as the canonical distance metric: cached statistics, cumulative
	 * arrays sent to the frontend, and on-the-fly distance walks all use the
	 * same constant so values agree exactly across blocks.
	 *
	 * @since 1.0.0
	 *
	 * @param float $lat1 Latitude of point 1, decimal degrees.
	 * @param float $lon1 Longitude of point 1, decimal degrees.
	 * @param float $lat2 Latitude of point 2, decimal degrees.
	 * @param float $lon2 Longitude of point 2, decimal degrees.
	 *
	 * @return float
	 */
	public static function haversine_meters( float $lat1, float $lon1, float $lat2, float $lon2 ): float {

		$phi1     = deg2rad( $lat1 );
		$phi2     = deg2rad( $lat2 );
		$d_phi    = deg2rad( $lat2 - $lat1 );
		$d_lambda = deg2rad( $lon2 - $lon1 );

		$a = sin( $d_phi / 2 ) ** 2
			+ cos( $phi1 ) * cos( $phi2 ) * sin( $d_lambda / 2 ) ** 2;

		return self::EARTH_RADIUS_METERS * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

	}

	/**
	 * Returns the parallel array of running Haversine sums per point.
	 *
	 * Each input point is `[lat, lon]` with optional trailing dimensions
	 * (elevation etc.) ignored. The output has the same length as the input,
	 * where index 0 is `0.0` and index N is the cumulative Haversine distance
	 * over consecutive pairs `[0..N]`. Empty input returns `[]`; a single-point
	 * input returns `[0.0]`.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array{0: float, 1: float}> $points List of points; each
	 *                                                      a `[lat, lon, ...]`
	 *                                                      tuple with optional
	 *                                                      trailing dimensions.
	 *
	 * @return array<int, float>
	 */
	public static function cumulative( array $points ): array {

		$count = count( $points );
		if ( 0 === $count ) {
			return [];
		}

		$out    = [ 0.0 ];
		$total  = 0.0;
		for ( $i = 1; $i < $count; $i++ ) {
			$total += self::haversine_meters(
				$points[ $i - 1 ][0],
				$points[ $i - 1 ][1],
				$points[ $i ][0],
				$points[ $i ][1],
			);
			$out[] = $total;
		}

		return $out;

	}

}
