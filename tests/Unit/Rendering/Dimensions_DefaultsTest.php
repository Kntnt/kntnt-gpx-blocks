<?php
/**
 * Tests for Rendering\Dimensions_Defaults.
 *
 * Issue #117 — the plugin-defined default `min-height` is normalised at the
 * attribute source through a `render_block_data` filter, not per-consumer
 * inline injection in the render callbacks. The filter writes
 * `style.dimensions.minHeight = '30vh'` onto the parsed block's `attrs` for
 * the Map block when both `minHeight` and `aspectRatio` are blank/missing,
 * and strips the `aspectRatio: 'auto'` keyword on both recognised blocks so
 * core does not emit `min-height: unset` and override the SCSS baseline.
 *
 * Issue #135 (wrapper-as-image) — the Elevation block no longer carries a
 * `min-height` default: its sizing is fully driven by `aspect-ratio` from
 * the SCSS baseline plus the typographic padding values emitted by
 * `Render_Elevation::render()`. The filter still recognises Elevation for
 * the `aspectRatio: 'auto'` strip but the `min-height` injection branch is
 * gated on a per-block default being present.
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
// A. Elevation block — wrapper-as-image (issue #135) drops the min-height
// default; the filter still strips `aspectRatio: 'auto'` so the SCSS
// baseline `aspect-ratio: 4 / 1` takes over.
// ---------------------------------------------------------------------------

test( 'A6: Elevation with both blank/missing does NOT get a min-height default (issue #135)', function (): void {

	$filter = new Dimensions_Defaults();

	$parsed = [
		'blockName'    => 'kntnt-gpx-blocks/elevation',
		'attrs'        => [],
		'innerBlocks'  => [],
		'innerHTML'    => '',
		'innerContent' => [],
	];

	$result = $filter->filter( $parsed );

	// Wrapper-as-image: sizing is purely aspect-ratio driven, so the
	// filter intentionally leaves attrs.style.dimensions absent — and
	// the parsed block passes through byte-identical when nothing else
	// needs mutating.
	expect( $result )->toBe( $parsed );

} );

test( "A6b: Elevation with aspectRatio='auto' strips the 'auto' keyword and emits no min-height (issue #135)", function (): void {

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

	// The 'auto' keyword is stripped so core does not emit `min-height:
	// unset`. With wrapper-as-image the SCSS `aspect-ratio: 4 / 1` then
	// takes over without any plugin-injected min-height.
	expect( $result['attrs']['style']['dimensions'] )
		->not->toHaveKey( 'aspectRatio' )
		->not->toHaveKey( 'minHeight' );

} );

test( 'A7a: Elevation with aspectRatio set and minHeight blank stays unchanged', function (): void {

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

	expect( $result['attrs']['style']['dimensions']['minHeight'] )->toBe( '' );

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
