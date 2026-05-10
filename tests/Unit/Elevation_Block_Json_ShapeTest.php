<?php
/**
 * Structural assertions over the GPX Elevation block.json file.
 *
 * These tests lock the public block-supports surface that the editor
 * exposes for the GPX Elevation block. They exist to prevent silent
 * regressions in the controls a site builder sees in the editor,
 * which `block.json` declares but no other test file inspects.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

/**
 * Loads and decodes the GPX Elevation block.json from the source tree.
 *
 * Reading the source-tree file (not the build output) keeps the test
 * authoritative regardless of whether `npm run build` has run since
 * the last edit.
 *
 * @return array<string, mixed>
 */
function elev_block_json_decoded(): array {

	$path = dirname( __DIR__, 2 ) . '/src/blocks/elevation/block.json';
	$json = file_get_contents( $path );
	expect( $json )->toBeString();

	$decoded = json_decode( (string) $json, true );
	expect( $decoded )->toBeArray();

	/** @var array<string, mixed> $decoded */
	return $decoded;
}

test( 'supports.spacing declares only margin (issue #96)', function (): void {

	$decoded = elev_block_json_decoded();

	expect( $decoded )->toHaveKey( 'supports' );
	expect( $decoded['supports'] )->toBeArray()->toHaveKey( 'spacing' );

	$spacing = $decoded['supports']['spacing'];
	expect( $spacing )
		->toBe( [ 'margin' => true ] );

} );

test( 'supports.spacing does not enable padding', function (): void {

	$decoded = elev_block_json_decoded();
	$spacing = $decoded['supports']['spacing'] ?? [];

	expect( $spacing )
		->toBeArray()
		->not->toHaveKey( 'padding' );

} );

test( 'supports.spacing does not enable blockGap', function (): void {

	$decoded = elev_block_json_decoded();
	$spacing = $decoded['supports']['spacing'] ?? [];

	expect( $spacing )
		->toBeArray()
		->not->toHaveKey( 'blockGap' );

} );
