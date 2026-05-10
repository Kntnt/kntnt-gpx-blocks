<?php
/**
 * Structural assertions over the GPX Map block.json file.
 *
 * These tests lock the colour-attribute defaults that the editor exposes
 * for the GPX Map block. The empty defaults are what gives the SCSS
 * fallbacks in `src/blocks/map/style.scss` their authority over the
 * rendered visual baseline — without them, every block instance would
 * emit explicit inline custom properties that override the stylesheet.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

/**
 * Loads and decodes the GPX Map block.json from the source tree.
 *
 * Reading the source-tree file (not the build output) keeps the test
 * authoritative regardless of whether `npm run build` has run since
 * the last edit.
 *
 * @return array<string, mixed>
 */
function map_block_json_decoded(): array {

	$path = dirname( __DIR__, 2 ) . '/src/blocks/map/block.json';
	$json = file_get_contents( $path );
	expect( $json )->toBeString();

	$decoded = json_decode( (string) $json, true );
	expect( $decoded )->toBeArray();

	/** @var array<string, mixed> $decoded */
	return $decoded;
}

test( 'colour attribute defaults are all empty (issue #84)', function (): void {

	$decoded    = map_block_json_decoded();
	$attributes = $decoded['attributes'] ?? [];

	expect( $attributes )->toBeArray();

	$colour_attrs = [
		'trackColor',
		'trackCursorColor',
		'waypointColor',
		'tooltipBackground',
		'tooltipNameColor',
		'tooltipDescColor',
	];

	foreach ( $colour_attrs as $key ) {
		expect( $attributes )->toHaveKey( $key );
		expect( $attributes[ $key ] )
			->toBeArray()
			->toHaveKey( 'default' );
		expect( $attributes[ $key ]['default'] )
			->toBe( '', sprintf( 'Expected %s default to be empty string', $key ) );
	}

} );

test( 'tooltipBackground, tooltipNameColor, tooltipDescColor defaults are empty strings (issue #84)', function (): void {

	$decoded    = map_block_json_decoded();
	$attributes = $decoded['attributes'] ?? [];

	expect( $attributes['tooltipBackground']['default'] ?? null )->toBe( '' );
	expect( $attributes['tooltipNameColor']['default'] ?? null )->toBe( '' );
	expect( $attributes['tooltipDescColor']['default'] ?? null )->toBe( '' );

} );

test( 'border support uses the __experimentalBorder key (issue #107)', function (): void {

	$decoded  = map_block_json_decoded();
	$supports = $decoded['supports'] ?? [];

	// Gutenberg reads BORDER_SUPPORT_KEY === '__experimentalBorder' in
	// packages/block-editor/src/hooks/border.js. The unprefixed 'border'
	// key is silently ignored, so the editor never registers the
	// style.border / borderColor attributes and the Border panel never
	// renders. The legacy key must not coexist either; only the
	// experimental key is honoured.
	expect( $supports )
		->toBeArray()
		->toHaveKey( '__experimentalBorder' )
		->not->toHaveKey( 'border' );

	expect( $supports['__experimentalBorder'] )
		->toBe(
			[
				'color'  => true,
				'radius' => true,
				'style'  => true,
				'width'  => true,
			]
		);

} );
