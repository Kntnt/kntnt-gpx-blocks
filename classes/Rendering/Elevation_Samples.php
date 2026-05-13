<?php
/**
 * Extracts and downsamples the (distance, elevation) series for the
 * GPX Elevation block.
 *
 * Pure deterministic logic — calls no WordPress functions. Walks the
 * first `LineString` feature in a cached GeoJSON FeatureCollection,
 * sums Haversine distance over every consecutive pair, and emits a
 * `[distance, elevation]` tuple whenever the coordinate carries a third
 * dimension. Distance continues to accumulate across coordinates that
 * lack elevation — a defensive carry-over for the rare hybrid case
 * where `Geo_Json_Converter`'s interpolation didn't fill every gap.
 *
 * The composition helper {@see self::compute()} passes the extracted
 * series through {@see Lttb::downsample()} to a target point count
 * (default 300, filter-tunable at the call site via the
 * `kntnt_gpx_blocks_elevation_target_points` filter). Both fields in
 * every emitted tuple are rounded to one decimal (10 cm / 0.1 m
 * precision) before emission — sub-decimetre precision survives LTTB
 * but adds no UI-visible detail at chart display precision.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rendering;

use Kntnt\Gpx_Blocks\Conversion\Distance;

/**
 * Computes the (distance, elevation) sample series for the Elevation
 * chart.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Elevation_Samples {

	/**
	 * Default LTTB target point count used when no filter overrides
	 * {@see self::compute()}'s `$target` argument.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const DEFAULT_TARGET = 300;

	/**
	 * Extracts the full-fidelity (distance, elevation) series from a
	 * decoded GeoJSON FeatureCollection.
	 *
	 * Walks the first `LineString` feature's coordinate chain summing
	 * Haversine distance over every consecutive pair, emitting a
	 * `[distance, elevation]` tuple whenever the coordinate carries a
	 * third dimension. Distance accumulates across elevation-less
	 * coordinates so a partial gap does not reset the running total.
	 *
	 * Returns the empty array when:
	 *
	 *   - no `LineString` feature is present;
	 *   - the LineString has fewer than two coordinates;
	 *   - none of the coordinates carry a third element (2D track —
	 *     already caught upstream by `Geo_Json_Converter` dropping
	 *     elevation outright on >50% missing, but defended here too).
	 *
	 * Both fields are rounded to one decimal before emission.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int|string, mixed> $geojson Decoded GeoJSON
	 *                                          FeatureCollection.
	 *
	 * @return array<int, array{0: float, 1: float}>
	 */
	public static function compute_full( array $geojson ): array {

		$coords = self::extract_line_coordinates( $geojson );
		if ( count( $coords ) < 2 ) {
			return [];
		}

		// Walk the coordinate chain, summing Haversine distance over
		// every pair and emitting a (distance, elevation) sample
		// whenever elevation is set. Distance carries forward across
		// gaps so partial-elevation tracks still surface the
		// elevation-bearing portion at its true cumulative position.
		$series   = [];
		$distance = 0.0;
		$prev     = null;
		foreach ( $coords as $coord ) {
			if ( null !== $prev ) {
				$distance += Distance::haversine_meters( $prev[1], $prev[0], $coord[1], $coord[0] );
			}
			if ( isset( $coord[2] ) ) {
				$series[] = [ round( $distance, 1 ), round( $coord[2], 1 ) ];
			}
			$prev = $coord;
		}

		return $series;

	}

	/**
	 * Returns the LTTB-downsampled (distance, elevation) series, ready
	 * for emission into the per-mapId Interactivity state slice.
	 *
	 * Composition of {@see self::compute_full()} and
	 * {@see Lttb::downsample()}. Pass-through when the full series has
	 * `<= $target` points.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int|string, mixed> $geojson Decoded GeoJSON
	 *                                          FeatureCollection.
	 * @param int                      $target  LTTB target point count
	 *                                          (≥ 2).
	 *
	 * @return array<int, array{0: float, 1: float}>
	 */
	public static function compute( array $geojson, int $target ): array {

		$full = self::compute_full( $geojson );
		if ( count( $full ) === 0 ) {
			return [];
		}
		return ( new Lttb() )->downsample( $full, $target );

	}

	/**
	 * Extracts the first LineString feature's coordinate list as a
	 * sanitised array of `[lon, lat]` or `[lon, lat, ele]` float tuples.
	 *
	 * Ignores malformed entries silently — defensive in the rare case
	 * where the cached GeoJSON's shape has drifted; production data
	 * always passes the parser's validation already.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int|string, mixed> $geojson Decoded GeoJSON.
	 *
	 * @return array<int, array<int, float>>
	 */
	private static function extract_line_coordinates( array $geojson ): array {

		$features = $geojson['features'] ?? null;
		if ( ! is_array( $features ) ) {
			return [];
		}

		// Find the first LineString feature and return its sanitised
		// coordinate list. Non-numeric entries are dropped silently.
		foreach ( $features as $feature ) {
			if ( ! is_array( $feature ) ) {
				continue;
			}
			$geometry = $feature['geometry'] ?? null;
			if ( ! is_array( $geometry ) || 'LineString' !== ( $geometry['type'] ?? '' ) ) {
				continue;
			}
			$coords = $geometry['coordinates'] ?? null;
			if ( ! is_array( $coords ) ) {
				return [];
			}

			$out = [];
			foreach ( $coords as $entry ) {
				if ( ! is_array( $entry ) || count( $entry ) < 2 ) {
					continue;
				}
				$lon = $entry[0] ?? null;
				$lat = $entry[1] ?? null;
				if ( ! is_numeric( $lon ) || ! is_numeric( $lat ) ) {
					continue;
				}
				$tuple = [ (float) $lon, (float) $lat ];
				$ele   = $entry[2] ?? null;
				if ( is_numeric( $ele ) ) {
					$tuple[] = (float) $ele;
				}
				$out[] = $tuple;
			}

			return $out;
		}

		return [];

	}

}
