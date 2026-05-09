<?php
/**
 * Tests for Conversion\Distance.
 *
 * Covers the static great-circle helpers shared by Statistics_Calculator and
 * the cursor-sync rendering path: pairwise Haversine against canonical
 * reference values, and the cumulative walk against fixtures of varying
 * cardinality and shape.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Kntnt\Gpx_Blocks\Conversion\Distance;

// ---------------------------------------------------------------------------
// haversine_meters
// ---------------------------------------------------------------------------

test( 'haversine_meters: identical points yield zero distance', function (): void {

	expect( Distance::haversine_meters( 59.3293, 18.0686, 59.3293, 18.0686 ) )->toBe( 0.0 );

} );

test( 'haversine_meters: 1 km eastward at the equator is within 0.1% of 1000 m', function (): void {

	// 1 degree of longitude at the equator is roughly 111.32 km. We pick a
	// delta whose great-circle distance is exactly 1000 m on the WGS-84
	// sphere with R = 6 371 000 m: dlon = 1000 / (R * cos(0)) rad.
	$delta_lon_rad = 1000.0 / 6371000.0;
	$delta_lon_deg = rad2deg( $delta_lon_rad );

	$d = Distance::haversine_meters( 0.0, 0.0, 0.0, $delta_lon_deg );

	expect( $d )->toBeFloat()
		->and( abs( $d - 1000.0 ) )->toBeLessThan( 1.0 );

} );

test( 'haversine_meters: 1 deg of latitude approximates 111 195 m', function (): void {

	// One degree of latitude on a sphere with R = 6 371 000 m is exactly
	// pi * R / 180 ≈ 111 194.927 m, irrespective of meridian.
	$d        = Distance::haversine_meters( 0.0, 0.0, 1.0, 0.0 );
	$expected = M_PI * 6371000.0 / 180.0;

	expect( abs( $d - $expected ) )->toBeLessThan( 0.001 );

} );

test( 'haversine_meters: symmetric — swapping the endpoints yields the same value', function (): void {

	$forward  = Distance::haversine_meters( 59.3293, 18.0686, 59.3300, 18.0700 );
	$backward = Distance::haversine_meters( 59.3300, 18.0700, 59.3293, 18.0686 );

	expect( $forward )->toBe( $backward );

} );

// ---------------------------------------------------------------------------
// cumulative
// ---------------------------------------------------------------------------

test( 'cumulative: empty input returns an empty array', function (): void {

	expect( Distance::cumulative( [] ) )->toBe( [] );

} );

test( 'cumulative: single-point input returns [0.0]', function (): void {

	expect( Distance::cumulative( [ [ 59.0, 18.0 ] ] ) )->toBe( [ 0.0 ] );

} );

test( 'cumulative: two-point straight line — index 0 is 0, index 1 is the pair distance', function (): void {

	$delta_lon_rad = 1000.0 / 6371000.0;
	$delta_lon_deg = rad2deg( $delta_lon_rad );

	$out = Distance::cumulative( [
		[ 0.0, 0.0 ],
		[ 0.0, $delta_lon_deg ],
	] );

	expect( $out )->toHaveCount( 2 )
		->and( $out[0] )->toBe( 0.0 )
		->and( abs( $out[1] - 1000.0 ) )->toBeLessThan( 1.0 );

} );

test( 'cumulative: three-point right angle — index 2 equals the sum of the two legs', function (): void {

	$delta_lon_rad = 1000.0 / 6371000.0;
	$delta_lon_deg = rad2deg( $delta_lon_rad );
	$delta_lat_deg = 180.0 / ( M_PI * 6371000.0 ) * 1000.0;

	$points = [
		[ 0.0, 0.0 ],
		[ 0.0, $delta_lon_deg ],
		[ $delta_lat_deg, $delta_lon_deg ],
	];

	$out = Distance::cumulative( $points );

	expect( $out )->toHaveCount( 3 )
		->and( $out[0] )->toBe( 0.0 )
		->and( abs( $out[1] - 1000.0 ) )->toBeLessThan( 1.0 )
		->and( abs( $out[2] - 2000.0 ) )->toBeLessThan( 1.0 );

} );

test( 'cumulative: diagonal segment matches a single Haversine call', function (): void {

	$points = [
		[ 59.3293, 18.0686 ],
		[ 59.3500, 18.1000 ],
	];

	$expected = Distance::haversine_meters( 59.3293, 18.0686, 59.3500, 18.1000 );
	$out      = Distance::cumulative( $points );

	expect( $out[1] )->toBe( $expected );

} );

test( 'cumulative: trailing dimensions on each point are ignored', function (): void {

	// Points carry an elevation as the third element; cumulative must read
	// only [lat, lon] and produce the same result as the 2D variant.
	$with_ele = [
		[ 0.0, 0.0, 100.0 ],
		[ 0.0, 0.001, 110.0 ],
		[ 0.0, 0.002, 120.0 ],
	];
	$plain    = [
		[ 0.0, 0.0 ],
		[ 0.0, 0.001 ],
		[ 0.0, 0.002 ],
	];

	expect( Distance::cumulative( $with_ele ) )->toBe( Distance::cumulative( $plain ) );

} );

test( 'cumulative: the result is monotonically non-decreasing', function (): void {

	$points = [
		[ 0.0, 0.0 ],
		[ 0.0, 0.001 ],
		[ 0.0, 0.002 ],
		[ 0.001, 0.002 ],
		[ 0.001, 0.003 ],
	];

	$out   = Distance::cumulative( $points );
	$count = count( $out );
	for ( $i = 1; $i < $count; $i++ ) {
		expect( $out[ $i ] )->toBeGreaterThanOrEqual( $out[ $i - 1 ] );
	}

} );
