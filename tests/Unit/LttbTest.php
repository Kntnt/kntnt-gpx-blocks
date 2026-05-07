<?php
/**
 * Tests for Rendering\Lttb (Largest Triangle Three Buckets downsampling).
 *
 * All tests are purely algorithmic — no WordPress functions involved — so
 * Brain Monkey is present but not needed for stubs here.
 *
 * Point arrays use the [x, y] convention that Lttb::downsample() expects.
 * In the elevation use case x is cumulative distance in metres and y is
 * elevation in metres, but the algorithm itself is unit-agnostic.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Kntnt\Gpx_Blocks\Rendering\Lttb;

// ---------------------------------------------------------------------------
// Trivial cases
// ---------------------------------------------------------------------------

test( 'two points are returned as-is', function (): void {

	$points = [
		[ 0.0, 100.0 ],
		[ 1000.0, 200.0 ],
	];

	$result = ( new Lttb() )->downsample( $points, 300 );

	expect( $result )->toBe( $points );

} );

test( 'input at or below target is returned unchanged', function (): void {

	$points = [
		[ 0.0, 100.0 ],
		[ 100.0, 110.0 ],
		[ 200.0, 120.0 ],
		[ 300.0, 130.0 ],
		[ 400.0, 140.0 ],
	];

	$result = ( new Lttb() )->downsample( $points, 5 );

	expect( $result )->toBe( $points );

} );

// ---------------------------------------------------------------------------
// Bulk downsampling — 5000 points → exactly 300
// ---------------------------------------------------------------------------

test( '5000 input points downsample to exactly 300', function (): void {

	// Build a synthetic input with 5000 points along a cosine wave.
	$points = [];
	for ( $i = 0; $i < 5000; $i++ ) {
		$x        = (float) $i;
		$y        = 100.0 + 50.0 * cos( $i / 100.0 );
		$points[] = [ $x, $y ];
	}

	$result = ( new Lttb() )->downsample( $points, 300 );

	expect( $result )->toHaveCount( 300 );

} );

// ---------------------------------------------------------------------------
// Endpoint preservation
// ---------------------------------------------------------------------------

test( 'first and last points are preserved across downsampling', function (): void {

	// Build a 1000-point sawtooth so the endpoints are visually distinct.
	$points = [];
	for ( $i = 0; $i < 1000; $i++ ) {
		$points[] = [ (float) $i, (float) ( $i % 17 ) ];
	}

	$result = ( new Lttb() )->downsample( $points, 50 );

	expect( $result[0] )->toBe( $points[0] )
		->and( $result[ count( $result ) - 1 ] )->toBe( $points[999] );

} );

// ---------------------------------------------------------------------------
// Peak preservation — a single sharp spike must survive downsampling
// ---------------------------------------------------------------------------

test( 'a sharp peak in the middle is preserved by the bucket containing it', function (): void {

	// Build a 1000-point flat baseline at y=100 with one sharp peak at y=500
	// at index 500. The peak is the largest-area triangle vertex against any
	// sensible anchor and average, so its bucket must select it.
	$points = [];
	for ( $i = 0; $i < 1000; $i++ ) {
		$points[] = [ (float) $i, $i === 500 ? 500.0 : 100.0 ];
	}

	$result = ( new Lttb() )->downsample( $points, 100 );

	// Walk the result and verify the peak point [500, 500] survived.
	$found = false;
	foreach ( $result as $point ) {
		if ( $point[0] === 500.0 && $point[1] === 500.0 ) {
			$found = true;
			break;
		}
	}

	expect( $found )->toBeTrue();

} );

// ---------------------------------------------------------------------------
// Determinism — identical input twice produces identical output
// ---------------------------------------------------------------------------

test( 'downsample is deterministic: same input twice yields identical output', function (): void {

	$points = [];
	for ( $i = 0; $i < 2000; $i++ ) {
		$points[] = [ (float) $i, sin( $i / 50.0 ) * 100.0 + cos( $i / 13.0 ) * 25.0 ];
	}

	$lttb   = new Lttb();
	$first  = $lttb->downsample( $points, 200 );
	$second = $lttb->downsample( $points, 200 );

	expect( $first )->toBe( $second );

} );

// ---------------------------------------------------------------------------
// Target 2 collapses to just first and last
// ---------------------------------------------------------------------------

test( 'target 2 returns only the first and last input points', function (): void {

	$points = [];
	for ( $i = 0; $i < 100; $i++ ) {
		$points[] = [ (float) $i, (float) ( $i * 2 ) ];
	}

	$result = ( new Lttb() )->downsample( $points, 2 );

	expect( $result )->toBe( [ $points[0], $points[99] ] );

} );

// ---------------------------------------------------------------------------
// Target 1 still produces just first and last (defensive lower bound)
// ---------------------------------------------------------------------------

test( 'target 1 also returns first and last input points', function (): void {

	$points = [
		[ 0.0, 0.0 ],
		[ 1.0, 1.0 ],
		[ 2.0, 4.0 ],
		[ 3.0, 9.0 ],
	];

	$result = ( new Lttb() )->downsample( $points, 1 );

	expect( $result )->toBe( [ $points[0], $points[3] ] );

} );

// ---------------------------------------------------------------------------
// Empty array — no iteration to worry about
// ---------------------------------------------------------------------------

test( 'empty array is returned as-is', function (): void {

	$result = ( new Lttb() )->downsample( [], 300 );

	expect( $result )->toBe( [] );

} );

// ---------------------------------------------------------------------------
// One point — fewer than two — returned as-is
// ---------------------------------------------------------------------------

test( 'one point is returned as-is', function (): void {

	$points = [ [ 0.0, 100.0 ] ];

	$result = ( new Lttb() )->downsample( $points, 300 );

	expect( $result )->toBe( $points );

} );

// ---------------------------------------------------------------------------
// Output points are a subset of input — algorithm picks, never synthesises
// ---------------------------------------------------------------------------

test( 'every output point is a real input point (no synthesised averages)', function (): void {

	// 1000 distinct points along a unique sequence; every output must match
	// some input verbatim.
	$points = [];
	for ( $i = 0; $i < 1000; $i++ ) {
		$points[] = [ (float) $i, (float) ( $i * 7 + 13 ) ];
	}

	$result = ( new Lttb() )->downsample( $points, 100 );

	// Index inputs by their (x, y) pair so containment is O(1).
	$by_x = [];
	foreach ( $points as $point ) {
		$by_x[ (string) $point[0] ] = $point[1];
	}

	$all_real = true;
	foreach ( $result as $output ) {
		$key = (string) $output[0];
		if ( ! array_key_exists( $key, $by_x ) || $by_x[ $key ] !== $output[1] ) {
			$all_real = false;
			break;
		}
	}

	expect( $all_real )->toBeTrue();

} );

// ---------------------------------------------------------------------------
// Output preserves source order — x-coordinates strictly non-decreasing when
// input is monotonic
// ---------------------------------------------------------------------------

test( 'output preserves source order for monotonic x', function (): void {

	$points = [];
	for ( $i = 0; $i < 800; $i++ ) {
		$points[] = [ (float) $i, sin( $i / 30.0 ) * 50.0 ];
	}

	$result = ( new Lttb() )->downsample( $points, 80 );

	$ordered = true;
	for ( $i = 1, $n = count( $result ); $i < $n; $i++ ) {
		if ( $result[ $i ][0] <= $result[ $i - 1 ][0] ) {
			$ordered = false;
			break;
		}
	}

	expect( $ordered )->toBeTrue();

} );
