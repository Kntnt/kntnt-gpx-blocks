<?php
/**
 * Tests for Conversion\Statistics_Calculator.
 *
 * Covers the pure-PHP statistics algorithm: Haversine distance summation,
 * min/max elevation, and the hysteresis-based ascent/descent filter. The
 * calculator is framework-agnostic by design (the climb threshold is a method
 * parameter, not a filter call), so these tests do not stub any WordPress
 * functions.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Kntnt\Gpx_Blocks\Conversion\Statistics_Calculator;
use Kntnt\Gpx_Blocks\Conversion\Track_Data;
use Kntnt\Gpx_Blocks\Conversion\Track_Point;

/**
 * Builds a Track_Data from two parallel arrays plus an empty waypoint list.
 *
 * @param array<int, array{0: float, 1: float}> $coords List of [lat, lon] pairs.
 * @param array<int, float|null>                $eles   Elevations aligned with $coords.
 * @return Track_Data
 */
function make_track( array $coords, array $eles ): Track_Data {

	$points = [];
	foreach ( $coords as $i => [ $lat, $lon ] ) {
		$points[] = new Track_Point( $lat, $lon, $eles[ $i ] ?? null );
	}

	return new Track_Data( $points, [] );

}

// ---------------------------------------------------------------------------
// Distance
// ---------------------------------------------------------------------------

test( 'distance: 1 km straight-line at the equator is within 0.1% of 1000 m', function (): void {

	// 1 degree of longitude at the equator is roughly 111.32 km. We pick a
	// delta whose great-circle distance is exactly 1000 m on the WGS-84
	// sphere with R = 6 371 000 m: dlon = 1000 / (R * cos(0)) rad.
	$delta_lon_rad = 1000.0 / 6371000.0;
	$delta_lon_deg = rad2deg( $delta_lon_rad );

	$track = make_track(
		[
			[ 0.0, 0.0 ],
			[ 0.0, $delta_lon_deg ],
		],
		[ null, null ],
	);

	$stats = ( new Statistics_Calculator() )->calculate( $track );

	expect( $stats['distance'] )->toBeFloat()
		->and( abs( $stats['distance'] - 1000.0 ) )->toBeLessThan( 1.0 );

} );

// ---------------------------------------------------------------------------
// Min and max elevation
// ---------------------------------------------------------------------------

test( 'min/max elevation: [100, 200, 150, 300, 250] yields 100 and 300', function (): void {

	// Coordinates are arbitrary; only elevations matter for this assertion.
	$track = make_track(
		[
			[ 0.0, 0.0 ],
			[ 0.0, 0.001 ],
			[ 0.0, 0.002 ],
			[ 0.0, 0.003 ],
			[ 0.0, 0.004 ],
		],
		[ 100.0, 200.0, 150.0, 300.0, 250.0 ],
	);

	$stats = ( new Statistics_Calculator() )->calculate( $track );

	expect( $stats['min_elevation'] )->toBe( 100.0 )
		->and( $stats['max_elevation'] )->toBe( 300.0 );

} );

// ---------------------------------------------------------------------------
// Hysteresis ascent/descent
// ---------------------------------------------------------------------------

test( 'hysteresis with threshold 3 m: [100,102,104,101,100,105] yields ascent 5 and descent 0', function (): void {

	// At threshold 3 the small wobbles never clear; only the 100 -> 105 step
	// at the end commits a 5 m climb. The intermediate baseline is never
	// updated, so descent stays at 0.
	$track = make_track(
		[
			[ 0.0, 0.0 ],
			[ 0.0, 0.001 ],
			[ 0.0, 0.002 ],
			[ 0.0, 0.003 ],
			[ 0.0, 0.004 ],
			[ 0.0, 0.005 ],
		],
		[ 100.0, 102.0, 104.0, 101.0, 100.0, 105.0 ],
	);

	$stats = ( new Statistics_Calculator() )->calculate( $track, 3.0 );

	expect( $stats['ascent'] )->toBe( 5.0 )
		->and( $stats['descent'] )->toBe( 0.0 );

} );

test( 'hysteresis with threshold 0: every positive delta is ascent, every negative is descent', function (): void {

	// At threshold 0 the filter degenerates: no wobble check ever fires
	// (nothing is strictly within ±0 of the baseline) so every consecutive
	// delta commits in its sign. The same array yields ascent = sum of
	// positive consecutive deltas (2+2+5) and descent = sum of negative
	// consecutive deltas (3+1).
	$track = make_track(
		[
			[ 0.0, 0.0 ],
			[ 0.0, 0.001 ],
			[ 0.0, 0.002 ],
			[ 0.0, 0.003 ],
			[ 0.0, 0.004 ],
			[ 0.0, 0.005 ],
		],
		[ 100.0, 102.0, 104.0, 101.0, 100.0, 105.0 ],
	);

	$stats = ( new Statistics_Calculator() )->calculate( $track, 0.0 );

	expect( $stats['ascent'] )->toBe( 9.0 )
		->and( $stats['descent'] )->toBe( 4.0 );

} );

test( 'all elevation stats are null when the track has no elevation at all', function (): void {

	$track = make_track(
		[
			[ 59.3293, 18.0686 ],
			[ 59.3300, 18.0700 ],
			[ 59.3310, 18.0720 ],
		],
		[ null, null, null ],
	);

	$stats = ( new Statistics_Calculator() )->calculate( $track );

	expect( $stats['min_elevation'] )->toBeNull()
		->and( $stats['max_elevation'] )->toBeNull()
		->and( $stats['ascent'] )->toBeNull()
		->and( $stats['descent'] )->toBeNull()
		->and( $stats['distance'] )->toBeFloat()
		->and( $stats['distance'] )->toBeGreaterThan( 0.0 );

} );

test( 'mixed elevation: stats are computed from the non-null subset', function (): void {

	// Three of five points have elevation [10, 20, 15]. The calculator skips
	// the null entries and runs the regular hysteresis on what remains.
	$track = make_track(
		[
			[ 0.0, 0.0 ],
			[ 0.0, 0.001 ],
			[ 0.0, 0.002 ],
			[ 0.0, 0.003 ],
			[ 0.0, 0.004 ],
		],
		[ 10.0, null, 20.0, null, 15.0 ],
	);

	$stats = ( new Statistics_Calculator() )->calculate( $track, 3.0 );

	expect( $stats['min_elevation'] )->toBe( 10.0 )
		->and( $stats['max_elevation'] )->toBe( 20.0 );

} );

test( 'elevation stats are null when fewer than two points have elevation', function (): void {

	$track = make_track(
		[
			[ 0.0, 0.0 ],
			[ 0.0, 0.001 ],
			[ 0.0, 0.002 ],
		],
		[ null, 10.0, null ],
	);

	$stats = ( new Statistics_Calculator() )->calculate( $track );

	expect( $stats['min_elevation'] )->toBeNull()
		->and( $stats['max_elevation'] )->toBeNull()
		->and( $stats['ascent'] )->toBeNull()
		->and( $stats['descent'] )->toBeNull();

} );
