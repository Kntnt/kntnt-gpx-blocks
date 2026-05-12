<?php
/**
 * Tests for Rendering\Dimensions_Defaults.
 *
 * Issue #117 — the plugin-defined default `min-height` is normalised at the
 * attribute source through a `render_block_data` filter, not per-consumer
 * inline injection in the render callbacks. Per-block rules apply:
 *
 *   - Map: gate is `minHeight` *and* `aspectRatio` both blank/missing; the
 *     injected value is `30vh`. The narrowed gate keeps an explicit user
 *     aspect-ratio from being fought by a hidden min-height.
 *   - Elevation (Step 3 of docs/elevation-rebuild.md): gate is `minHeight`
 *     blank alone; the injected value is `15vh`. The wrapper has no
 *     SCSS aspect-ratio baseline of its own, so the default acts as a
 *     harmless floor that coexists with any user-set aspect ratio.
 *
 * Both blocks have the `aspectRatio: 'auto'` keyword stripped so core does
 * not emit `min-height: unset` and override the SCSS baseline.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Kntnt\Gpx_Blocks\Rendering\Dimensions_Defaults;

// ---------------------------------------------------------------------------
// A. Map block — both fields blank or missing → minHeight = 30vh.
// ---------------------------------------------------------------------------

test( 'A1: Map with no style at all gets minHeight=30vh', function (): void {

	$filter = new Dimensions_Defaults();

	$parsed = [
		'blockName'    => 'kntnt-gpx-blocks/map',
		'attrs'        => [],
		'innerBlocks'  => [],
		'innerHTML'    => '',
		'innerContent' => [],
	];

	$result = $filter->filter( $parsed );

	expect( $result['attrs']['style']['dimensions']['minHeight'] ?? null )
		->toBe( '30vh' );

} );

test( 'A2: Map with both minHeight and aspectRatio as blank strings gets minHeight=30vh', function (): void {

	$filter = new Dimensions_Defaults();

	$parsed = [
		'blockName'    => 'kntnt-gpx-blocks/map',
		'attrs'        => [
			'style' => [
				'dimensions' => [
					'minHeight'   => '',
					'aspectRatio' => '',
				],
			],
		],
		'innerBlocks'  => [],
		'innerHTML'    => '',
		'innerContent' => [],
	];

	$result = $filter->filter( $parsed );

	expect( $result['attrs']['style']['dimensions']['minHeight'] ?? null )
		->toBe( '30vh' );

} );

test( "A2b: Map with aspectRatio='auto' gets minHeight=30vh and the 'auto' key stripped", function (): void {

	$filter = new Dimensions_Defaults();

	$parsed = [
		'blockName'    => 'kntnt-gpx-blocks/map',
		'attrs'        => [
			'style' => [
				'dimensions' => [
					'aspectRatio' => 'auto',
				],
			],
		],
		'innerBlocks'  => [],
		'innerHTML'    => '',
		'innerContent' => [],
	];

	$result = $filter->filter( $parsed );

	expect( $result['attrs']['style']['dimensions']['minHeight'] ?? null )
		->toBe( '30vh' );
	expect( $result['attrs']['style']['dimensions'] )
		->not->toHaveKey( 'aspectRatio' );

} );

test( "A2c: Map with aspectRatio='auto' and explicit minHeight strips 'auto' and keeps the user value", function (): void {

	$filter = new Dimensions_Defaults();

	$parsed = [
		'blockName'    => 'kntnt-gpx-blocks/map',
		'attrs'        => [
			'style' => [
				'dimensions' => [
					'minHeight'   => '500px',
					'aspectRatio' => 'auto',
				],
			],
		],
		'innerBlocks'  => [],
		'innerHTML'    => '',
		'innerContent' => [],
	];

	$result = $filter->filter( $parsed );

	expect( $result['attrs']['style']['dimensions']['minHeight'] ?? null )
		->toBe( '500px' );
	expect( $result['attrs']['style']['dimensions'] )
		->not->toHaveKey( 'aspectRatio' );

} );

test( 'A3: Map with aspectRatio set and minHeight blank stays unchanged', function (): void {

	$filter = new Dimensions_Defaults();

	$parsed = [
		'blockName'    => 'kntnt-gpx-blocks/map',
		'attrs'        => [
			'style' => [
				'dimensions' => [
					'minHeight'   => '',
					'aspectRatio' => '16/9',
				],
			],
		],
		'innerBlocks'  => [],
		'innerHTML'    => '',
		'innerContent' => [],
	];

	$result = $filter->filter( $parsed );

	expect( $result['attrs']['style']['dimensions']['minHeight'] )->toBe( '' );
	expect( $result['attrs']['style']['dimensions']['aspectRatio'] )->toBe( '16/9' );

} );

test( 'A4: Map with explicit minHeight wins over the plugin default', function (): void {

	$filter = new Dimensions_Defaults();

	$parsed = [
		'blockName'    => 'kntnt-gpx-blocks/map',
		'attrs'        => [
			'style' => [
				'dimensions' => [
					'minHeight'   => '500px',
					'aspectRatio' => '',
				],
			],
		],
		'innerBlocks'  => [],
		'innerHTML'    => '',
		'innerContent' => [],
	];

	$result = $filter->filter( $parsed );

	expect( $result['attrs']['style']['dimensions']['minHeight'] )->toBe( '500px' );

} );

test( 'A5: Map with explicit minHeight and aspectRatio stays unchanged', function (): void {

	$filter = new Dimensions_Defaults();

	$parsed = [
		'blockName'    => 'kntnt-gpx-blocks/map',
		'attrs'        => [
			'style' => [
				'dimensions' => [
					'minHeight'   => '500px',
					'aspectRatio' => '16/9',
				],
			],
		],
		'innerBlocks'  => [],
		'innerHTML'    => '',
		'innerContent' => [],
	];

	$result = $filter->filter( $parsed );

	expect( $result['attrs']['style']['dimensions']['minHeight'] )->toBe( '500px' );
	expect( $result['attrs']['style']['dimensions']['aspectRatio'] )->toBe( '16/9' );

} );

// ---------------------------------------------------------------------------
// A. Elevation block — Step 3 of docs/elevation-rebuild.md introduces a
// `min-height: 15vh` default whose gate is `minHeight blank` alone
// (regardless of aspectRatio). The wrapper has no SCSS aspect-ratio
// baseline, so the floor coexists with any user-set aspect ratio.
// ---------------------------------------------------------------------------

test( 'A6: Elevation with both blank/missing gets minHeight=15vh', function (): void {

	$filter = new Dimensions_Defaults();

	$parsed = [
		'blockName'    => 'kntnt-gpx-blocks/elevation',
		'attrs'        => [],
		'innerBlocks'  => [],
		'innerHTML'    => '',
		'innerContent' => [],
	];

	$result = $filter->filter( $parsed );

	expect( $result['attrs']['style']['dimensions']['minHeight'] )
		->toBe( '15vh' );

} );

test( "A6b: Elevation with aspectRatio='auto' strips the 'auto' keyword and gets minHeight=15vh", function (): void {

	$filter = new Dimensions_Defaults();

	$parsed = [
		'blockName'    => 'kntnt-gpx-blocks/elevation',
		'attrs'        => [
			'style' => [
				'dimensions' => [
					'aspectRatio' => 'auto',
				],
			],
		],
		'innerBlocks'  => [],
		'innerHTML'    => '',
		'innerContent' => [],
	];

	$result = $filter->filter( $parsed );

	expect( $result['attrs']['style']['dimensions'] )
		->not->toHaveKey( 'aspectRatio' );
	expect( $result['attrs']['style']['dimensions']['minHeight'] )
		->toBe( '15vh' );

} );

test( 'A7a: Elevation with aspectRatio set and minHeight blank gets minHeight=15vh as a floor', function (): void {

	$filter = new Dimensions_Defaults();

	$parsed = [
		'blockName'    => 'kntnt-gpx-blocks/elevation',
		'attrs'        => [
			'style' => [
				'dimensions' => [
					'minHeight'   => '',
					'aspectRatio' => '16/9',
				],
			],
		],
		'innerBlocks'  => [],
		'innerHTML'    => '',
		'innerContent' => [],
	];

	$result = $filter->filter( $parsed );

	// Step 3 rule: Elevation's default fires whenever minHeight is
	// blank, regardless of aspectRatio. The 15vh value coexists with
	// the user's 16/9 aspect ratio via the normal CSS cascade.
	expect( $result['attrs']['style']['dimensions']['minHeight'] )
		->toBe( '15vh' );
	expect( $result['attrs']['style']['dimensions']['aspectRatio'] )
		->toBe( '16/9' );

} );

test( 'A7b: Elevation with explicit minHeight stays unchanged', function (): void {

	$filter = new Dimensions_Defaults();

	$parsed = [
		'blockName'    => 'kntnt-gpx-blocks/elevation',
		'attrs'        => [
			'style' => [
				'dimensions' => [
					'minHeight'   => '420px',
					'aspectRatio' => '',
				],
			],
		],
		'innerBlocks'  => [],
		'innerHTML'    => '',
		'innerContent' => [],
	];

	$result = $filter->filter( $parsed );

	expect( $result['attrs']['style']['dimensions']['minHeight'] )->toBe( '420px' );

} );

test( 'A7c: Elevation with both fields set stays unchanged', function (): void {

	$filter = new Dimensions_Defaults();

	$parsed = [
		'blockName'    => 'kntnt-gpx-blocks/elevation',
		'attrs'        => [
			'style' => [
				'dimensions' => [
					'minHeight'   => '420px',
					'aspectRatio' => '16/9',
				],
			],
		],
		'innerBlocks'  => [],
		'innerHTML'    => '',
		'innerContent' => [],
	];

	$result = $filter->filter( $parsed );

	expect( $result['attrs']['style']['dimensions']['minHeight'] )->toBe( '420px' );
	expect( $result['attrs']['style']['dimensions']['aspectRatio'] )->toBe( '16/9' );

} );

// ---------------------------------------------------------------------------
// A8. Unrelated block — output byte-identical to input.
// ---------------------------------------------------------------------------

test( 'A8: core/paragraph passes through unchanged', function (): void {

	$filter = new Dimensions_Defaults();

	$parsed = [
		'blockName'    => 'core/paragraph',
		'attrs'        => [],
		'innerBlocks'  => [],
		'innerHTML'    => '<p>hi</p>',
		'innerContent' => [ '<p>hi</p>' ],
	];

	$result = $filter->filter( $parsed );

	expect( $result )->toBe( $parsed );

} );

test( 'A8b: another unrelated block with style.dimensions still passes through unchanged', function (): void {

	$filter = new Dimensions_Defaults();

	$parsed = [
		'blockName'    => 'core/cover',
		'attrs'        => [
			'style' => [
				'dimensions' => [
					'minHeight'   => '',
					'aspectRatio' => '',
				],
			],
		],
		'innerBlocks'  => [],
		'innerHTML'    => '',
		'innerContent' => [],
	];

	$result = $filter->filter( $parsed );

	expect( $result )->toBe( $parsed );

} );
