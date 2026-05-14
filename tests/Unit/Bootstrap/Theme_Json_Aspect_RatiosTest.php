<?php
/**
 * Tests for Bootstrap\Theme_Json_Aspect_Ratios.
 *
 * Verifies that the filter injects six panorama-friendly aspect-ratio
 * presets into the theme.json data layer for both GPX Map and GPX
 * Elevation, that slugs are uniquely kntnt-prefixed, that pre-existing
 * theme settings under unrelated keys survive the merge, and that the
 * schema version is carried forward from the data layer.
 *
 * Regression coverage for issue #108: core's Dimensions → Aspect ratio
 * dropdown ships only seven defaults, none of them panorama-style; the
 * Map and Elevation (SCSS baseline 4/1) blocks need the extended list.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Bootstrap\Theme_Json_Aspect_Ratios;

beforeEach( function (): void {

	// The class wraps preset names in __(); stub the translation function so
	// it returns the source string unchanged. This is enough for tests that
	// only need to assert structural shape and that the names are present.
	Functions\when( '__' )->returnArg( 1 );

} );

test( 'filter injects six panorama presets for both kntnt blocks', function (): void {

	$wrapper = new WP_Theme_JSON_Data( [ 'version' => 2 ] );

	$result = ( new Theme_Json_Aspect_Ratios() )->filter( $wrapper );

	$data = $result->get_data();
	expect( $data )->toHaveKey( 'settings' );
	expect( $data['settings'] )->toHaveKey( 'blocks' );

	$expected = [
		[ 'slug' => 'kntnt-5-4',   'ratio' => '5/4',   'name' => 'Photo – 5:4' ],
		[ 'slug' => 'kntnt-16-10', 'ratio' => '16/10', 'name' => 'Widescreen – 16:10' ],
		[ 'slug' => 'kntnt-21-9',  'ratio' => '21/9',  'name' => 'Ultrawide – 21:9' ],
		[ 'slug' => 'kntnt-2-1',   'ratio' => '2/1',   'name' => 'Panorama – 2:1' ],
		[ 'slug' => 'kntnt-3-1',   'ratio' => '3/1',   'name' => 'Wide panorama – 3:1' ],
		[ 'slug' => 'kntnt-4-1',   'ratio' => '4/1',   'name' => 'Extra wide panorama – 4:1' ],
	];

	foreach ( [ 'kntnt-gpx-blocks/map', 'kntnt-gpx-blocks/elevation' ] as $block ) {
		expect( $data['settings']['blocks'] )->toHaveKey( $block );
		expect( $data['settings']['blocks'][ $block ] )->toHaveKey( 'dimensions' );
		expect( $data['settings']['blocks'][ $block ]['dimensions'] )->toHaveKey( 'aspectRatios' );
		expect( $data['settings']['blocks'][ $block ]['dimensions']['aspectRatios'] )->toBe( $expected );
	}

} );

test( 'every preset slug is unique and carries the kntnt- prefix', function (): void {

	$wrapper = new WP_Theme_JSON_Data( [ 'version' => 2 ] );

	$data = ( new Theme_Json_Aspect_Ratios() )->filter( $wrapper )->get_data();

	$presets = $data['settings']['blocks']['kntnt-gpx-blocks/map']['dimensions']['aspectRatios'];
	$slugs   = array_column( $presets, 'slug' );

	expect( $slugs )->toHaveCount( 6 );
	expect( array_unique( $slugs ) )->toHaveCount( 6 );

	foreach ( $slugs as $slug ) {
		expect( $slug )->toStartWith( 'kntnt-' );
	}

} );

test( 'every preset ratio parses as a positive W/H string', function (): void {

	$wrapper = new WP_Theme_JSON_Data( [ 'version' => 2 ] );

	$data    = ( new Theme_Json_Aspect_Ratios() )->filter( $wrapper )->get_data();
	$presets = $data['settings']['blocks']['kntnt-gpx-blocks/elevation']['dimensions']['aspectRatios'];

	foreach ( $presets as $preset ) {
		expect( $preset )->toHaveKeys( [ 'slug', 'ratio', 'name' ] );
		expect( $preset['ratio'] )->toMatch( '#^[0-9]+/[0-9]+$#' );

		[ $w, $h ] = array_map( 'floatval', explode( '/', $preset['ratio'] ) );
		expect( $w )->toBeGreaterThan( 0.0 );
		expect( $h )->toBeGreaterThan( 0.0 );
	}

} );

test( 'filter carries the theme.json schema version forward from the data layer', function (): void {

	$wrapper = new WP_Theme_JSON_Data( [ 'version' => 3 ] );

	$data = ( new Theme_Json_Aspect_Ratios() )->filter( $wrapper )->get_data();

	expect( $data['version'] )->toBe( 3 );

} );

test( 'filter preserves pre-existing theme settings under unrelated keys', function (): void {

	$wrapper = new WP_Theme_JSON_Data(
		[
			'version'  => 2,
			'settings' => [
				'color'  => [
					'palette' => [
						[
							'slug'  => 'primary',
							'color' => '#06c',
						],
					],
				],
				'blocks' => [
					'core/paragraph' => [ 'typography' => [ 'fontSize' => true ] ],
				],
			],
		]
	);

	$result = ( new Theme_Json_Aspect_Ratios() )->filter( $wrapper );

	$data = $result->get_data();
	expect( $data['settings'] )->toHaveKey( 'color' );
	expect( $data['settings']['color']['palette'][0]['slug'] )->toBe( 'primary' );
	expect( $data['settings']['blocks'] )->toHaveKey( 'core/paragraph' );
	expect( $data['settings']['blocks']['core/paragraph']['typography']['fontSize'] )->toBeTrue();
	expect( $data['settings']['blocks'] )->toHaveKey( 'kntnt-gpx-blocks/map' );
	expect( $data['settings']['blocks'] )->toHaveKey( 'kntnt-gpx-blocks/elevation' );

} );

test( 'filter injects even when settings.blocks is initially empty', function (): void {

	$wrapper = new WP_Theme_JSON_Data(
		[
			'version'  => 2,
			'settings' => [
				'blocks' => [],
			],
		]
	);

	$data = ( new Theme_Json_Aspect_Ratios() )->filter( $wrapper )->get_data();

	expect( $data['settings']['blocks'] )->toHaveKey( 'kntnt-gpx-blocks/map' );
	expect( $data['settings']['blocks'] )->toHaveKey( 'kntnt-gpx-blocks/elevation' );
	expect( $data['settings']['blocks']['kntnt-gpx-blocks/map']['dimensions']['aspectRatios'] )
		->toHaveCount( 6 );

} );

test( 'filter returns the same wrapper instance the filter received', function (): void {

	$wrapper = new WP_Theme_JSON_Data( [ 'version' => 2 ] );

	$result = ( new Theme_Json_Aspect_Ratios() )->filter( $wrapper );

	expect( $result )->toBe( $wrapper );

} );
