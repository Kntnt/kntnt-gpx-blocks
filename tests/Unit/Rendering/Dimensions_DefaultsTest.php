<?php
/**
 * Tests for Rendering\Dimensions_Defaults.
 *
 * Issue #117 — the plugin-defined default `min-height` is normalised at the
 * attribute source through a `render_block_data` filter, not per-consumer
 * inline injection in the render callbacks. The filter writes
 * `style.dimensions.minHeight = '30vh'` (Map) or `'15vh'` (Elevation) onto
 * the parsed block's `attrs` when both `minHeight` and `aspectRatio` are
 * blank/missing — and leaves the block untouched in every other case.
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
// A. Elevation block — same matrix with 15vh.
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

	expect( $result['attrs']['style']['dimensions']['minHeight'] ?? null )
		->toBe( '15vh' );

} );

test( "A6b: Elevation with aspectRatio='auto' gets minHeight=15vh and the 'auto' key stripped", function (): void {

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

	expect( $result['attrs']['style']['dimensions']['minHeight'] ?? null )
		->toBe( '15vh' );
	expect( $result['attrs']['style']['dimensions'] )
		->not->toHaveKey( 'aspectRatio' );

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
