<?php
/**
 * Structural assertions over the GPX Elevation block.json file.
 *
 * Step 1 of the rebuild plan (`docs/elevation-rebuild.md`) fixed the
 * block's surface area at 35 attributes (3 behavioural + 8 colour + 24
 * typography) and 6 supports blocks; Step 5 added the `plotFillColor`
 * row, raising the total to 36 (3 + 9 + 24); issue #144 adds three
 * cursor & guides booleans (`showCursor`, `showVerticalGuide`,
 * `showHorizontalGuide`), raising the total to 39 (6 behavioural + 9
 * colour + 24 typography). These tests lock that contract so later
 * steps cannot drift away from it accidentally, and so an editor post
 * saved against the schema continues to round-trip cleanly through
 * subsequent step releases.
 *
 * The supports tests in particular lock two non-obvious facts: the use
 * of the experimental `__experimentalBorder` key (the unprefixed
 * `border` key is silently ignored by Gutenberg, issue #107) and the
 * deliberate `spacing.padding` divergence from the Map block (Elevation
 * draws into the content box, so padding is meaningful — see the doc's
 * *Application scope* section).
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

/**
 * Loads and decodes the GPX Elevation block.json from the source tree.
 *
 * Reads the source-tree file (not the build output) so the test stays
 * authoritative regardless of whether `npm run build` has run since
 * the last edit.
 *
 * @return array<string, mixed>
 */
function elevation_block_json_decoded(): array {

	$path = dirname( __DIR__, 2 ) . '/src/blocks/elevation/block.json';
	$json = file_get_contents( $path );
	expect( $json )->toBeString();

	$decoded = json_decode( (string) $json, true );
	expect( $decoded )->toBeArray();

	/** @var array<string, mixed> $decoded */
	return $decoded;
}

test( 'block.json declares exactly the 39 attributes fixed by Step 1 + Step 5 + issue #144', function (): void {

	$decoded    = elevation_block_json_decoded();
	$attributes = $decoded['attributes'] ?? [];

	expect( $attributes )->toBeArray();

	$expected = [
		// Behavioural (6 — issue #144 adds the three Cursor & guides toggles).
		'mapId',
		'showCursor',
		'showVerticalGuide',
		'showHorizontalGuide',
		'tooltipShowDistance',
		'tooltipShowHeight',
		// Colours (9 — Step 5 adds plotFillColor).
		'backgroundColor',
		'plotLineColor',
		'plotFillColor',
		'cursorColor',
		'axisColor',
		'axisLabelColor',
		'tooltipBackgroundColor',
		'tooltipDistanceColor',
		'tooltipHeightColor',
	];

	// Typography (3 prefixes × 8 suffixes = 24).
	foreach ( [ 'tickLabel', 'tooltipDistance', 'tooltipHeight' ] as $prefix ) {
		foreach (
			[
				'FontFamily',
				'FontSize',
				'FontWeight',
				'FontStyle',
				'LineHeight',
				'LetterSpacing',
				'TextTransform',
				'TextDecoration',
			] as $suffix
		) {
			$expected[] = $prefix . $suffix;
		}
	}

	expect( count( $expected ) )->toBe( 39 );

	sort( $expected );
	$actual = array_keys( $attributes );
	sort( $actual );

	expect( $actual )->toBe( $expected );

} );

test( 'behavioural attributes carry the right defaults', function (): void {

	$decoded    = elevation_block_json_decoded();
	$attributes = $decoded['attributes'] ?? [];

	expect( $attributes['mapId']['default'] ?? null )->toBe( 'auto' );
	expect( $attributes['tooltipShowDistance']['default'] ?? null )->toBe( true );
	expect( $attributes['tooltipShowHeight']['default'] ?? null )->toBe( true );
	expect( $attributes['showCursor']['default'] ?? null )->toBe( true );
	expect( $attributes['showVerticalGuide']['default'] ?? null )->toBe( true );
	expect( $attributes['showHorizontalGuide']['default'] ?? null )->toBe( false );

} );

test( 'every colour attribute defaults to an empty string', function (): void {

	$decoded    = elevation_block_json_decoded();
	$attributes = $decoded['attributes'] ?? [];

	$colour_attrs = [
		'backgroundColor',
		'plotLineColor',
		'plotFillColor',
		'cursorColor',
		'axisColor',
		'axisLabelColor',
		'tooltipBackgroundColor',
		'tooltipDistanceColor',
		'tooltipHeightColor',
	];

	foreach ( $colour_attrs as $key ) {
		expect( $attributes )->toHaveKey( $key );
		expect( $attributes[ $key ]['default'] ?? null )
			->toBe( '', sprintf( 'Expected %s default to be empty string', $key ) );
	}

} );

test( 'every typography attribute defaults to an empty string', function (): void {

	$decoded    = elevation_block_json_decoded();
	$attributes = $decoded['attributes'] ?? [];

	foreach ( [ 'tickLabel', 'tooltipDistance', 'tooltipHeight' ] as $prefix ) {
		foreach (
			[
				'FontFamily',
				'FontSize',
				'FontWeight',
				'FontStyle',
				'LineHeight',
				'LetterSpacing',
				'TextTransform',
				'TextDecoration',
			] as $suffix
		) {
			$key = $prefix . $suffix;
			expect( $attributes )->toHaveKey( $key );
			expect( $attributes[ $key ]['default'] ?? null )
				->toBe( '', sprintf( 'Expected %s default to be empty string', $key ) );
		}
	}

} );

test( 'block.json declares the six supports blocks fixed by Step 1', function (): void {

	$decoded  = elevation_block_json_decoded();
	$supports = $decoded['supports'] ?? [];

	expect( $supports )->toBeArray();

	$expected = [
		'align',
		'anchor',
		'__experimentalBorder',
		'shadow',
		'dimensions',
		'spacing',
	];

	$actual = array_keys( $supports );
	sort( $actual );
	sort( $expected );
	expect( $actual )->toBe( $expected );

} );

test( 'border support uses the __experimentalBorder key (issue #107)', function (): void {

	$decoded  = elevation_block_json_decoded();
	$supports = $decoded['supports'] ?? [];

	// Gutenberg reads BORDER_SUPPORT_KEY === '__experimentalBorder' in
	// packages/block-editor/src/hooks/border.js. The unprefixed `border`
	// key is silently ignored, so the editor never registers the
	// style.border / borderColor attributes and the Border panel never
	// renders.
	expect( $supports )
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

test( 'spacing supports both padding and margin (intentional Map divergence)', function (): void {

	$decoded  = elevation_block_json_decoded();
	$supports = $decoded['supports'] ?? [];

	// Map omits padding because Leaflet absolutely-positions panes
	// against the wrapper's padding box, neutralising the control.
	// Elevation's chart is drawn into the content box (issue tracked
	// in the rebuild plan's *Application scope* section), so padding
	// has visible effect and is included.
	expect( $supports['spacing'] )->toBe(
		[
			'padding' => true,
			'margin'  => true,
		]
	);

} );

test( 'supports.color is not declared — alpha-bearing Background lives in the plugin Color panel', function (): void {

	$decoded  = elevation_block_json_decoded();
	$supports = $decoded['supports'] ?? [];

	// Core's Background block-support cannot enable alpha. The plugin
	// owns the entire colour surface itself so every entry — including
	// Background — supports `#RRGGBBAA`.
	expect( $supports )->not->toHaveKey( 'color' );

} );

test( 'dimensions support exposes both aspectRatio and minHeight', function (): void {

	$decoded  = elevation_block_json_decoded();
	$supports = $decoded['supports'] ?? [];

	expect( $supports['dimensions'] )->toBe(
		[
			'aspectRatio' => true,
			'minHeight'   => true,
		]
	);

} );
