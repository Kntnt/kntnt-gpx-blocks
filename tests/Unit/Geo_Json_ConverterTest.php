<?php
/**
 * Tests for Conversion\Geo_Json_Converter.
 *
 * Covers the GeoJSON FeatureCollection produced from a parsed Track_Data:
 * coordinate dimensionality (2D vs 3D), linear interpolation of missing
 * elevations, the >50% missing fallback, and waypoint property compaction.
 * The converter is framework-agnostic, so these tests do not stub WordPress
 * functions.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Kntnt\Gpx_Blocks\Conversion\Geo_Json_Converter;
use Kntnt\Gpx_Blocks\Conversion\Track_Data;
use Kntnt\Gpx_Blocks\Conversion\Track_Point;
use Kntnt\Gpx_Blocks\Conversion\Waypoint;

/**
 * Builds a Track_Data from parallel coordinate and elevation arrays plus
 * an optional waypoint list.
 *
 * @param array<int, array{0: float, 1: float}> $coords    List of [lat, lon] pairs.
 * @param array<int, float|null>                $eles      Elevations aligned with $coords.
 * @param array<int, Waypoint>                  $waypoints Optional waypoint list.
 * @return Track_Data
 */
function make_geojson_track( array $coords, array $eles, array $waypoints = [] ): Track_Data {

	$points = [];
	foreach ( $coords as $i => [ $lat, $lon ] ) {
		$points[] = new Track_Point( $lat, $lon, $eles[ $i ] ?? null );
	}

	return new Track_Data( $points, $waypoints );

}

/**
 * Returns the LineString feature from a converted FeatureCollection.
 *
 * @param array<string, mixed> $collection FeatureCollection array.
 * @return array<string, mixed>
 *
 * @throws RuntimeException When the collection contains no LineString feature.
 */
function line_string( array $collection ): array {

	foreach ( $collection['features'] as $feature ) {
		if ( 'Feature' === $feature['type'] && 'LineString' === $feature['geometry']['type'] ) {
			return $feature;
		}
	}

	throw new RuntimeException( 'No LineString feature found' );

}

/**
 * Returns all Point features from a converted FeatureCollection.
 *
 * @param array<string, mixed> $collection FeatureCollection array.
 * @return array<int, array<string, mixed>>
 */
function point_features( array $collection ): array {

	$points = [];
	foreach ( $collection['features'] as $feature ) {
		if ( 'Feature' === $feature['type'] && 'Point' === $feature['geometry']['type'] ) {
			$points[] = $feature;
		}
	}

	return $points;

}

// ---------------------------------------------------------------------------
// LineString coordinate dimensionality
// ---------------------------------------------------------------------------

test( 'track with full elevation produces 3-tuple LineString coordinates [lon, lat, ele]', function (): void {

	$track = make_geojson_track(
		[
			[ 59.3293, 18.0686 ],
			[ 59.3300, 18.0700 ],
			[ 59.3310, 18.0720 ],
		],
		[ 10.0, 12.5, 15.0 ],
	);

	$collection = ( new Geo_Json_Converter() )->convert( $track );

	expect( $collection['type'] )->toBe( 'FeatureCollection' );

	$line = line_string( $collection );
	expect( $line['geometry']['coordinates'] )->toEqual(
		[
			[ 18.0686, 59.3293, 10.0 ],
			[ 18.0700, 59.3300, 12.5 ],
			[ 18.0720, 59.3310, 15.0 ],
		],
	);

} );

test( 'track with no elevation at all produces 2-tuple LineString coordinates [lon, lat]', function (): void {

	$track = make_geojson_track(
		[
			[ 59.3293, 18.0686 ],
			[ 59.3300, 18.0700 ],
			[ 59.3310, 18.0720 ],
		],
		[ null, null, null ],
	);

	$collection = ( new Geo_Json_Converter() )->convert( $track );

	$line = line_string( $collection );
	expect( $line['geometry']['coordinates'] )->toEqual(
		[
			[ 18.0686, 59.3293 ],
			[ 18.0700, 59.3300 ],
			[ 18.0720, 59.3310 ],
		],
	);

} );

test( '30% missing elevation: gaps are linearly interpolated', function (): void {

	// Ten points with three nulls (30%). The single internal null at index 3
	// sits between known 13.0 and 17.0 → interpolant 14.0 (one-third of the
	// way from 13 to 17? No: i=3, left=2, right=5 → ratio=(3-2)/(5-2)=1/3,
	// 13 + (17-13)*1/3 = 14.333…). Two endpoint nulls at index 0 and 9 clamp
	// to the nearest known on the inside.
	$track = make_geojson_track(
		[
			[ 0.0, 0.0 ],
			[ 0.0, 0.001 ],
			[ 0.0, 0.002 ],
			[ 0.0, 0.003 ],
			[ 0.0, 0.004 ],
			[ 0.0, 0.005 ],
			[ 0.0, 0.006 ],
			[ 0.0, 0.007 ],
			[ 0.0, 0.008 ],
			[ 0.0, 0.009 ],
		],
		[ null, 11.0, 13.0, null, null, 19.0, 21.0, 23.0, 25.0, null ],
	);

	$collection = ( new Geo_Json_Converter() )->convert( $track );

	$coords = line_string( $collection )['geometry']['coordinates'];

	// Each coordinate is [lon, lat, ele]. Pull out just the elevations.
	$eles = array_column( $coords, 2 );

	expect( count( $eles ) )->toBe( 10 )
		->and( $eles[0] )->toBe( 11.0 )
		->and( $eles[1] )->toBe( 11.0 )
		->and( $eles[2] )->toBe( 13.0 )
		->and( $eles[3] )->toEqualWithDelta( 13.0 + ( 19.0 - 13.0 ) * ( 1 / 3 ), 1e-9 )
		->and( $eles[4] )->toEqualWithDelta( 13.0 + ( 19.0 - 13.0 ) * ( 2 / 3 ), 1e-9 )
		->and( $eles[5] )->toBe( 19.0 )
		->and( $eles[6] )->toBe( 21.0 )
		->and( $eles[7] )->toBe( 23.0 )
		->and( $eles[8] )->toBe( 25.0 )
		->and( $eles[9] )->toBe( 25.0 );

} );

test( '70% missing elevation: output drops to 2D and elevation is omitted entirely', function (): void {

	// Seven of ten points lack elevation — above the 50% threshold, so the
	// LineString must be 2D regardless of how interpolatable the gaps are.
	$track = make_geojson_track(
		[
			[ 0.0, 0.0 ],
			[ 0.0, 0.001 ],
			[ 0.0, 0.002 ],
			[ 0.0, 0.003 ],
			[ 0.0, 0.004 ],
			[ 0.0, 0.005 ],
			[ 0.0, 0.006 ],
			[ 0.0, 0.007 ],
			[ 0.0, 0.008 ],
			[ 0.0, 0.009 ],
		],
		[ 10.0, null, null, 13.0, null, null, null, null, 25.0, null ],
	);

	$collection = ( new Geo_Json_Converter() )->convert( $track );

	$coords = line_string( $collection )['geometry']['coordinates'];

	expect( count( $coords ) )->toBe( 10 );
	foreach ( $coords as $tuple ) {
		expect( $tuple )->toHaveCount( 2 );
	}

} );

test( 'exactly 50% missing elevation stays 3D and interpolates the gaps', function (): void {

	// Five of ten — exactly the boundary. The rule is "≤50%", so this case
	// stays 3D.
	$track = make_geojson_track(
		[
			[ 0.0, 0.0 ],
			[ 0.0, 0.001 ],
			[ 0.0, 0.002 ],
			[ 0.0, 0.003 ],
			[ 0.0, 0.004 ],
			[ 0.0, 0.005 ],
			[ 0.0, 0.006 ],
			[ 0.0, 0.007 ],
			[ 0.0, 0.008 ],
			[ 0.0, 0.009 ],
		],
		[ 10.0, null, 12.0, null, 14.0, null, 16.0, null, 18.0, null ],
	);

	$collection = ( new Geo_Json_Converter() )->convert( $track );

	$coords = line_string( $collection )['geometry']['coordinates'];

	foreach ( $coords as $tuple ) {
		expect( $tuple )->toHaveCount( 3 );
	}

} );

// ---------------------------------------------------------------------------
// Waypoints
// ---------------------------------------------------------------------------

test( 'empty waypoint list produces no Point Features', function (): void {

	$track = make_geojson_track(
		[
			[ 59.3293, 18.0686 ],
			[ 59.3300, 18.0700 ],
		],
		[ 10.0, 11.0 ],
	);

	$collection = ( new Geo_Json_Converter() )->convert( $track );

	expect( point_features( $collection ) )->toBe( [] );

} );

test( 'waypoint with all four metadata fields produces a Point Feature with all four properties', function (): void {

	$waypoint = new Waypoint(
		lat: 59.3293,
		lon: 18.0686,
		name: 'Top of hill',
		sym: 'Flag, Blue',
		type: 'summit',
		desc: 'Wide view to the south.',
	);
	$track    = make_geojson_track(
		[
			[ 59.3000, 18.0000 ],
			[ 59.3500, 18.1000 ],
		],
		[ 10.0, 20.0 ],
		[ $waypoint ],
	);

	$collection = ( new Geo_Json_Converter() )->convert( $track );
	$points     = point_features( $collection );

	expect( $points )->toHaveCount( 1 );

	$point = $points[0];
	expect( $point['geometry']['coordinates'] )->toEqual( [ 18.0686, 59.3293 ] )
		->and( $point['properties'] )->toEqual(
			[
				'name' => 'Top of hill',
				'sym'  => 'Flag, Blue',
				'type' => 'summit',
				'desc' => 'Wide view to the south.',
			],
		);

} );

test( 'waypoint with only name produces a Point Feature with only the name property', function (): void {

	$waypoint = new Waypoint(
		lat: 59.3293,
		lon: 18.0686,
		name: 'Junction',
		sym: null,
		type: null,
		desc: null,
	);
	$track    = make_geojson_track(
		[
			[ 59.3000, 18.0000 ],
			[ 59.3500, 18.1000 ],
		],
		[ 10.0, 20.0 ],
		[ $waypoint ],
	);

	$collection = ( new Geo_Json_Converter() )->convert( $track );
	$points     = point_features( $collection );

	expect( $points )->toHaveCount( 1 );
	expect( $points[0]['properties'] )->toEqual( [ 'name' => 'Junction' ] );

} );

test( 'waypoint with empty-string metadata fields drops those fields from the Point properties', function (): void {

	$waypoint = new Waypoint(
		lat: 59.3293,
		lon: 18.0686,
		name: 'Junction',
		sym: '',
		type: '',
		desc: '',
	);
	$track    = make_geojson_track(
		[
			[ 59.3000, 18.0000 ],
			[ 59.3500, 18.1000 ],
		],
		[ 10.0, 20.0 ],
		[ $waypoint ],
	);

	$collection = ( new Geo_Json_Converter() )->convert( $track );

	expect( point_features( $collection )[0]['properties'] )->toEqual( [ 'name' => 'Junction' ] );

} );
