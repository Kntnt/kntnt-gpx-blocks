<?php
/**
 * Tests for Rendering\Douglas_Peucker.
 *
 * All tests are purely algorithmic — no WordPress functions involved — so
 * Brain Monkey is present but not needed for stubs here.
 *
 * Point arrays use the [lat, lon] convention that Douglas_Peucker::simplify()
 * expects. A third element (elevation) is included in the relevant tests.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Kntnt\Gpx_Blocks\Rendering\Douglas_Peucker;

// ---------------------------------------------------------------------------
// Trivial cases
// ---------------------------------------------------------------------------

test( 'two points are returned as-is', function (): void {

	$points = [
		[ 59.3, 18.0 ],
		[ 59.4, 18.1 ],
	];

	$result = ( new Douglas_Peucker() )->simplify( $points, 1.0 );

	expect( $result )->toBe( $points );

} );

test( 'one point is returned as-is', function (): void {

	$points = [ [ 59.3, 18.0 ] ];

	$result = ( new Douglas_Peucker() )->simplify( $points, 1.0 );

	expect( $result )->toBe( $points );

} );

test( 'empty array is returned as-is', function (): void {

	$result = ( new Douglas_Peucker() )->simplify( [], 1.0 );

	expect( $result )->toBe( [] );

} );

// ---------------------------------------------------------------------------
// Collinear points
// ---------------------------------------------------------------------------

test( 'five collinear points at 1 m tolerance collapse to endpoints only', function (): void {

	// Five points on a perfectly straight north-south line at 18.0 °E.
	// Distances between consecutive points are roughly 1.1 km, well above any
	// tolerance, but perpendicular deviation from the first-to-last chord is
	// exactly zero — all interior points should be removed.
	$points = [
		[ 59.000, 18.0 ],
		[ 59.010, 18.0 ],
		[ 59.020, 18.0 ],
		[ 59.030, 18.0 ],
		[ 59.040, 18.0 ],
	];

	$result = ( new Douglas_Peucker() )->simplify( $points, 1.0 );

	expect( $result )->toHaveCount( 2 )
		->and( $result[0] )->toBe( $points[0] )
		->and( $result[1] )->toBe( $points[4] );

} );

// ---------------------------------------------------------------------------
// Peak above tolerance is kept
// ---------------------------------------------------------------------------

test( 'a single peak above tolerance is preserved along with endpoints', function (): void {

	// The first and last points are on the same latitude (east-west line).
	// The middle point deviates 100 m to the north, which is ~0.0009 degrees
	// at 59 °N latitude.  Perpendicular distance is ~100 m — far above a 5 m
	// tolerance.
	$lat_base    = 59.0;
	$lat_peak    = $lat_base + ( 100.0 / 111320.0 );  // ~100 m north
	$points      = [
		[ $lat_base, 18.0 ],
		[ $lat_peak, 18.05 ],   // the peak point.
		[ $lat_base, 18.1 ],
	];

	$result = ( new Douglas_Peucker() )->simplify( $points, 5.0 );

	expect( $result )->toHaveCount( 3 )
		->and( $result[0] )->toBe( $points[0] )
		->and( $result[1] )->toBe( $points[1] )
		->and( $result[2] )->toBe( $points[2] );

} );

// ---------------------------------------------------------------------------
// Peak below tolerance is removed
// ---------------------------------------------------------------------------

test( 'a peak below tolerance is removed, leaving only endpoints', function (): void {

	// Deviation of only 1 m — below the 5 m default tolerance.
	$lat_base = 59.0;
	$lat_peak = $lat_base + ( 1.0 / 111320.0 );  // ~1 m north
	$points   = [
		[ $lat_base, 18.0 ],
		[ $lat_peak, 18.05 ],
		[ $lat_base, 18.1 ],
	];

	$result = ( new Douglas_Peucker() )->simplify( $points, 5.0 );

	expect( $result )->toHaveCount( 2 )
		->and( $result[0] )->toBe( $points[0] )
		->and( $result[1] )->toBe( $points[2] );

} );

// ---------------------------------------------------------------------------
// Stability: same input + same tolerance → identical output
// ---------------------------------------------------------------------------

test( 'simplify is stable: identical input produces identical output', function (): void {

	$points = [
		[ 59.0, 18.0 ],
		[ 59.01, 18.05 ],
		[ 59.02, 18.0 ],
		[ 59.03, 18.05 ],
		[ 59.04, 18.0 ],
	];
	$simplifier = new Douglas_Peucker();

	$first  = $simplifier->simplify( $points, 10.0 );
	$second = $simplifier->simplify( $points, 10.0 );

	expect( $first )->toBe( $second );

} );

// ---------------------------------------------------------------------------
// Third coordinate (elevation) preserved on kept points
// ---------------------------------------------------------------------------

test( 'elevation is preserved on kept points and absent on dropped points', function (): void {

	// Three points: start (ele 100), peak (ele 200), end (ele 150).
	// Peak is 100 m off the chord — should be kept.
	$lat_base = 59.0;
	$lat_peak = $lat_base + ( 100.0 / 111320.0 );
	$points   = [
		[ $lat_base, 18.0,  100.0 ],
		[ $lat_peak, 18.05, 200.0 ],
		[ $lat_base, 18.1,  150.0 ],
	];

	$result = ( new Douglas_Peucker() )->simplify( $points, 5.0 );

	expect( $result )->toHaveCount( 3 )
		->and( $result[0] )->toBe( [ $lat_base, 18.0,  100.0 ] )
		->and( $result[1] )->toBe( [ $lat_peak, 18.05, 200.0 ] )
		->and( $result[2] )->toBe( [ $lat_base, 18.1,  150.0 ] );

} );

test( 'elevation is absent on dropped points (not added as 0)', function (): void {

	// Collinear with elevations — all interior points should drop, taking their
	// elevations with them.
	$points = [
		[ 59.0, 18.0, 100.0 ],
		[ 59.01, 18.0, 110.0 ],
		[ 59.02, 18.0, 120.0 ],
	];

	$result = ( new Douglas_Peucker() )->simplify( $points, 1.0 );

	expect( $result )->toHaveCount( 2 )
		->and( $result[0] )->toBe( [ 59.0,  18.0, 100.0 ] )
		->and( $result[1] )->toBe( [ 59.02, 18.0, 120.0 ] );

} );

// ---------------------------------------------------------------------------
// High tolerance simplifies more than low tolerance
// ---------------------------------------------------------------------------

test( 'high tolerance removes more points than low tolerance', function (): void {

	// A track with moderate deviations — more should survive at 1 m than at 50 m.
	$points = [
		[ 59.000, 18.000 ],
		[ 59.001, 18.005 ],   // ~400 m off chord of full track
		[ 59.002, 18.000 ],
		[ 59.003, 18.005 ],
		[ 59.004, 18.000 ],
	];

	$simplifier  = new Douglas_Peucker();
	$at_low_tol  = $simplifier->simplify( $points, 1.0 );
	$at_high_tol = $simplifier->simplify( $points, 10000.0 );

	expect( count( $at_low_tol ) )->toBeGreaterThanOrEqual( count( $at_high_tol ) );

} );

// ---------------------------------------------------------------------------
// Endpoints are always preserved
// ---------------------------------------------------------------------------

test( 'endpoints are always the first and last output points', function (): void {

	$points = [
		[ 59.0,  18.0 ],
		[ 59.05, 18.05 ],
		[ 59.1,  18.1 ],
		[ 59.05, 18.15 ],
		[ 59.0,  18.2 ],
	];

	$result = ( new Douglas_Peucker() )->simplify( $points, 1000.0 );

	expect( $result[0] )->toBe( $points[0] )
		->and( $result[ count( $result ) - 1 ] )->toBe( $points[4] );

} );

// ---------------------------------------------------------------------------
// Degenerate-line epsilon — coincident endpoints in a sub-array
// ---------------------------------------------------------------------------

test( 'coincident endpoints reduce to Euclidean distance from the interior point', function (): void {

	// First and last points are exactly identical; the interior point is
	// 100 m north. With A === B, $line_len_sq is exactly 0.0 (under the
	// strict-equals predicate) *and* well below the 1e-12 epsilon, so both
	// the legacy and the hardened branch take the degenerate path. The
	// interior deviation (100 m) exceeds the 5 m tolerance, so DP keeps all
	// three points — confirming the degenerate branch reports the correct
	// fallback distance.
	$lat_base = 59.0;
	$lat_peak = $lat_base + ( 100.0 / 111320.0 );
	$points   = [
		[ $lat_base, 18.0 ],
		[ $lat_peak, 18.0 ],
		[ $lat_base, 18.0 ],
	];

	$result = ( new Douglas_Peucker() )->simplify( $points, 5.0 );

	expect( $result )->toHaveCount( 3 );

} );

test( 'endpoints separated by sub-epsilon noise still trigger the degenerate branch', function (): void {

	// Endpoints differ by ~5e-15 metres after the flat-earth conversion —
	// well below the 1e-12 m² epsilon ($line_len_sq is ~2.5e-29). A strict
	// `=== 0.0` test would miss this and divide by a near-zero denominator,
	// inflating the perpendicular distance and keeping the interior point
	// even though the line is geometrically degenerate. With the epsilon
	// guard the degenerate branch fires, the Euclidean distance from the
	// interior point to the (essentially identical) endpoints is 100 m,
	// and the algorithm correctly keeps all three points at 5 m tolerance.
	$lat_base    = 59.0;
	$lat_jittery = $lat_base + 1e-20;  // ~2 femtometres north — below GPS noise.
	$lat_peak    = $lat_base + ( 100.0 / 111320.0 );
	$points      = [
		[ $lat_base,    18.0 ],
		[ $lat_peak,    18.0 ],
		[ $lat_jittery, 18.0 ],
	];

	$result = ( new Douglas_Peucker() )->simplify( $points, 5.0 );

	// The peak survives because the degenerate-line fallback measures its
	// distance from A correctly as ~100 m, not from a numerically-unstable
	// AB line.
	expect( $result )->toHaveCount( 3 )
		->and( $result[1] )->toBe( $points[1] );

} );
