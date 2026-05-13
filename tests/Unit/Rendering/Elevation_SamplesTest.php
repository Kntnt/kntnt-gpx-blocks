<?php
/**
 * Tests for Rendering\Elevation_Samples.
 *
 * Pure algorithmic tests — no WordPress functions, no Brain Monkey
 * stubs. The class composes the (distance, elevation) extraction over
 * the cached GeoJSON with `Lttb::downsample()` to produce the
 * LTTB-reduced array the Elevation block emits into its per-mapId
 * Interactivity state slice. Tests pin every documented edge case from
 * Step 5 of `docs/elevation-rebuild.md`.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Kntnt\Gpx_Blocks\Conversion\Distance;
use Kntnt\Gpx_Blocks\Rendering\Elevation_Samples;

// ---------------------------------------------------------------------------
// DEFAULT_TARGET
// ---------------------------------------------------------------------------

test( 'DEFAULT_TARGET is 300', function (): void {
	expect( Elevation_Samples::DEFAULT_TARGET )->toBe( 300 );
} );

// ---------------------------------------------------------------------------
// compute_full() — happy path
// ---------------------------------------------------------------------------

test( '3-point 3D LineString produces exactly 3 samples with cumulative Haversine distance', function (): void {

	$coords = [
		[ 12.0, 57.0, 100.0 ],
		[ 12.001, 57.0, 110.0 ],
		[ 12.002, 57.0, 120.0 ],
	];
	$geojson = [
		'type'     => 'FeatureCollection',
		'features' => [
			[
				'type'     => 'Feature',
				'geometry' => [
					'type'        => 'LineString',
					'coordinates' => $coords,
				],
			],
		],
	];

	$result = Elevation_Samples::compute_full( $geojson );

	expect( $result )->toHaveCount( 3 );

	$d1 = Distance::haversine_meters( 57.0, 12.0, 57.0, 12.001 );
	$d2 = $d1 + Distance::haversine_meters( 57.0, 12.001, 57.0, 12.002 );

	expect( $result[0][0] )->toBe( 0.0 );
	expect( $result[0][1] )->toBe( 100.0 );
	expect( $result[1][0] )->toBe( round( $d1, 1 ) );
	expect( $result[1][1] )->toBe( 110.0 );
	expect( $result[2][0] )->toBe( round( $d2, 1 ) );
	expect( $result[2][1] )->toBe( 120.0 );

} );

// ---------------------------------------------------------------------------
// compute_full() — edge cases
// ---------------------------------------------------------------------------

test( '2D LineString returns the empty array', function (): void {

	$geojson = [
		'features' => [
			[
				'geometry' => [
					'type'        => 'LineString',
					'coordinates' => [
						[ 12.0, 57.0 ],
						[ 12.001, 57.0 ],
						[ 12.002, 57.0 ],
					],
				],
			],
		],
	];

	expect( Elevation_Samples::compute_full( $geojson ) )->toBe( [] );

} );

test( 'FeatureCollection without a LineString returns the empty array', function (): void {

	$geojson = [
		'features' => [
			[
				'geometry' => [
					'type'        => 'Point',
					'coordinates' => [ 12.0, 57.0, 100.0 ],
				],
			],
		],
	];

	expect( Elevation_Samples::compute_full( $geojson ) )->toBe( [] );

} );

test( 'Single-point LineString returns the empty array', function (): void {

	$geojson = [
		'features' => [
			[
				'geometry' => [
					'type'        => 'LineString',
					'coordinates' => [
						[ 12.0, 57.0, 100.0 ],
					],
				],
			],
		],
	];

	expect( Elevation_Samples::compute_full( $geojson ) )->toBe( [] );

} );

test( 'Missing features key returns the empty array', function (): void {
	expect( Elevation_Samples::compute_full( [] ) )->toBe( [] );
} );

// ---------------------------------------------------------------------------
// compute_full() — output precision
// ---------------------------------------------------------------------------

test( 'all emitted values are rounded to one decimal', function (): void {

	$geojson = [
		'features' => [
			[
				'geometry' => [
					'type'        => 'LineString',
					'coordinates' => [
						[ 12.0, 57.0, 100.123456 ],
						[ 12.001, 57.0, 110.789 ],
						[ 12.002, 57.0, 120.5 ],
					],
				],
			],
		],
	];

	$result = Elevation_Samples::compute_full( $geojson );

	foreach ( $result as $sample ) {
		$d_scaled = $sample[0] * 10;
		$e_scaled = $sample[1] * 10;
		expect( abs( $d_scaled - round( $d_scaled ) ) )->toBeLessThan( 1e-9 );
		expect( abs( $e_scaled - round( $e_scaled ) ) )->toBeLessThan( 1e-9 );
	}

} );

// ---------------------------------------------------------------------------
// compute() — LTTB composition
// ---------------------------------------------------------------------------

test( '1000-point 3D LineString with target 50 produces exactly 50 samples, endpoints preserved', function (): void {

	$coords = [];
	for ( $i = 0; $i < 1000; $i++ ) {
		// Walk roughly 1 m per step along a meridian.
		$coords[] = [ 12.0 + $i * 0.00001, 57.0, 100.0 + sin( $i / 50.0 ) * 10.0 ];
	}
	$geojson = [
		'features' => [
			[
				'geometry' => [
					'type'        => 'LineString',
					'coordinates' => $coords,
				],
			],
		],
	];

	$result_a = Elevation_Samples::compute( $geojson, 50 );
	$result_b = Elevation_Samples::compute( $geojson, 50 );

	$full = Elevation_Samples::compute_full( $geojson );

	expect( $result_a )->toHaveCount( 50 );
	expect( $result_a[0] )->toBe( $full[0] );
	expect( $result_a[49] )->toBe( $full[ count( $full ) - 1 ] );
	expect( $result_a )->toBe( $result_b );

} );

test( '50-point 3D LineString with target 300 returns the 50-point series unchanged', function (): void {

	$coords = [];
	for ( $i = 0; $i < 50; $i++ ) {
		$coords[] = [ 12.0 + $i * 0.0001, 57.0, 100.0 + $i ];
	}
	$geojson = [
		'features' => [
			[
				'geometry' => [
					'type'        => 'LineString',
					'coordinates' => $coords,
				],
			],
		],
	];

	$result = Elevation_Samples::compute( $geojson, 300 );

	expect( $result )->toHaveCount( 50 );
	expect( $result )->toBe( Elevation_Samples::compute_full( $geojson ) );

} );

test( 'compute() returns an empty array when the LineString is unusable', function (): void {

	$geojson = [
		'features' => [
			[
				'geometry' => [
					'type'        => 'LineString',
					'coordinates' => [
						[ 12.0, 57.0 ],
						[ 12.001, 57.0 ],
					],
				],
			],
		],
	];

	expect( Elevation_Samples::compute( $geojson, 300 ) )->toBe( [] );

} );
