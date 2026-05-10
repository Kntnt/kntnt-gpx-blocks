<?php
/**
 * Tests for Bootstrap\Theme_Json_Border_Optin.
 *
 * Verifies that the filter injects the per-block border opt-in slice into
 * the theme.json data layer for both GPX Map and GPX Elevation, and that
 * pre-existing theme settings under unrelated keys survive the merge.
 *
 * Regression coverage for issue #87: without this opt-in, themes that
 * don't enable appearanceTools (or per-feature border settings) cause the
 * editor to hide the Border panel even though each block declares full
 * `supports.border` in its `block.json`.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Kntnt\Gpx_Blocks\Bootstrap\Theme_Json_Border_Optin;

test( 'filter enables all four border features for both blocks', function (): void {

	$wrapper = new WP_Theme_JSON_Data( [ 'version' => 2 ] );

	$result = ( new Theme_Json_Border_Optin() )->filter( $wrapper );

	$data = $result->get_data();
	expect( $data )->toHaveKey( 'settings' );
	expect( $data['settings'] )->toHaveKey( 'blocks' );

	foreach ( [ 'kntnt-gpx-blocks/map', 'kntnt-gpx-blocks/elevation' ] as $block ) {
		expect( $data['settings']['blocks'] )->toHaveKey( $block );
		expect( $data['settings']['blocks'][ $block ] )
			->toHaveKey( 'border' )
			->and( $data['settings']['blocks'][ $block ]['border'] )
			->toBe(
				[
					'color'  => true,
					'radius' => true,
					'style'  => true,
					'width'  => true,
				]
			);
	}

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

	$result = ( new Theme_Json_Border_Optin() )->filter( $wrapper );

	$data = $result->get_data();
	expect( $data['settings'] )->toHaveKey( 'color' );
	expect( $data['settings']['color']['palette'][0]['slug'] )->toBe( 'primary' );
	expect( $data['settings']['blocks'] )->toHaveKey( 'core/paragraph' );
	expect( $data['settings']['blocks']['core/paragraph']['typography']['fontSize'] )->toBeTrue();
	expect( $data['settings']['blocks'] )->toHaveKey( 'kntnt-gpx-blocks/map' );
	expect( $data['settings']['blocks'] )->toHaveKey( 'kntnt-gpx-blocks/elevation' );

} );

test( 'filter carries the theme.json schema version forward from the data layer', function (): void {

	$wrapper = new WP_Theme_JSON_Data( [ 'version' => 3 ] );

	$data = ( new Theme_Json_Border_Optin() )->filter( $wrapper )->get_data();

	expect( $data['version'] )->toBe( 3 );

} );

test( 'filter returns the same wrapper instance the filter received', function (): void {

	$wrapper = new WP_Theme_JSON_Data( [ 'version' => 2 ] );

	$result = ( new Theme_Json_Border_Optin() )->filter( $wrapper );

	expect( $result )->toBe( $wrapper );

} );
