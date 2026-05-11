<?php
/**
 * Tests for Bootstrap\Border_Radius_Normalizer.
 *
 * Regression coverage for issue #109: when Gutenberg saves the
 * per-corner border-radius object form with one corner stored as an empty
 * string (the symptom the issue describes), core's style engine emits CSS
 * for the three non-empty corners and silently drops the empty one. The
 * editor preview still shows all four corners rounded, so the frontend
 * wrapper ends up inconsistent with the editor.
 *
 * The normaliser collapses the per-corner object to the unified shorthand
 * string when every non-empty corner agrees, so the style engine produces
 * a `border-radius: <value>` declaration covering all four corners. When
 * the user genuinely sets different radii per corner, the object is left
 * alone and the per-corner declarations survive.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Kntnt\Gpx_Blocks\Bootstrap\Border_Radius_Normalizer;

// ---------------------------------------------------------------------------
// Pure-logic coverage of normalise_radius_object()
// ---------------------------------------------------------------------------

test( 'one empty corner + three matching preset values collapse to a uniform string', function (): void {

	// Reproduces the exact saved-attribute shape that issue #109 describes:
	// the editor shows all four corners rounded with a preset, but the
	// stored attribute has topLeft as the empty string. The remaining three
	// corners share the same preset value, so the user's effective intent
	// is "apply this preset to every corner".
	$input = [
		'topLeft'     => '',
		'topRight'    => 'var:preset|border-radius|xl',
		'bottomLeft'  => 'var:preset|border-radius|xl',
		'bottomRight' => 'var:preset|border-radius|xl',
	];

	$result = Border_Radius_Normalizer::normalise_radius_object( $input );

	expect( $result )->toBe( 'var:preset|border-radius|xl' );

} );

test( 'one empty corner + three matching pixel values collapse to a uniform string', function (): void {

	// The same bug shape but with a numeric pixel value instead of a
	// preset reference — the normaliser must treat both branches alike.
	$input = [
		'topLeft'     => '',
		'topRight'    => '32px',
		'bottomLeft'  => '32px',
		'bottomRight' => '32px',
	];

	$result = Border_Radius_Normalizer::normalise_radius_object( $input );

	expect( $result )->toBe( '32px' );

} );

test( 'missing corner key + three matching values collapse to a uniform string', function (): void {

	// Some editor states save only the non-empty corners and omit the
	// empty ones outright. The normaliser must treat a missing key the
	// same way as an empty-string value.
	$input = [
		'topRight'    => '24px',
		'bottomLeft'  => '24px',
		'bottomRight' => '24px',
	];

	$result = Border_Radius_Normalizer::normalise_radius_object( $input );

	expect( $result )->toBe( '24px' );

} );

test( 'numeric-zero corner + three matching values collapse to a uniform string', function (): void {

	// Integer `0` is treated as "not set" — the style engine's
	// `is_valid_style_value()` would drop it, so the normaliser must
	// agree. String `'0'` (a literal CSS zero) is a different case and is
	// covered separately below.
	$input = [
		'topLeft'     => 0,
		'topRight'    => '16px',
		'bottomLeft'  => '16px',
		'bottomRight' => '16px',
	];

	$result = Border_Radius_Normalizer::normalise_radius_object( $input );

	expect( $result )->toBe( '16px' );

} );

test( 'object with all four corners set to the same value is left unchanged', function (): void {

	// A fully populated uniform object is a no-op for normalisation —
	// the style engine emits four matching per-corner declarations that
	// render identically on all four corners regardless. Returning the
	// input unchanged keeps the filter's "no real change" path cheap.
	$input = [
		'topLeft'     => 'var:preset|border-radius|xl',
		'topRight'    => 'var:preset|border-radius|xl',
		'bottomLeft'  => 'var:preset|border-radius|xl',
		'bottomRight' => 'var:preset|border-radius|xl',
	];

	$result = Border_Radius_Normalizer::normalise_radius_object( $input );

	expect( $result )->toBe( $input );

} );

test( 'object with genuinely different per-corner values is left unchanged', function (): void {

	// The user has used the per-corner UI to set different radii on
	// different corners. The normaliser must not collapse this — the
	// per-corner declarations carry the user's actual intent.
	$input = [
		'topLeft'     => '4px',
		'topRight'    => '12px',
		'bottomLeft'  => '4px',
		'bottomRight' => '12px',
	];

	$result = Border_Radius_Normalizer::normalise_radius_object( $input );

	expect( $result )->toBe( $input );

} );

test( 'fully empty object is left unchanged', function (): void {

	// Nothing to normalise — no corner carries a meaningful value.
	// Pass-through avoids inventing data the user did not save.
	$input = [
		'topLeft'     => '',
		'topRight'    => '',
		'bottomLeft'  => '',
		'bottomRight' => '',
	];

	$result = Border_Radius_Normalizer::normalise_radius_object( $input );

	expect( $result )->toBe( $input );

} );

test( 'object with a literal zero corner preserves it', function (): void {

	// `'0'` is a meaningful CSS zero — the user wants that corner square
	// and the others rounded. The normaliser must keep this object form
	// because collapsing would lie about the corner the user explicitly
	// chose to keep square.
	$input = [
		'topLeft'     => '0',
		'topRight'    => '32px',
		'bottomLeft'  => '32px',
		'bottomRight' => '32px',
	];

	$result = Border_Radius_Normalizer::normalise_radius_object( $input );

	expect( $result )->toBe( $input );

} );

// ---------------------------------------------------------------------------
// Filter wiring — the parsed block carries the normalised attribute back
// ---------------------------------------------------------------------------

test( 'filter rewrites style.border.radius for the Map block', function (): void {

	$parsed_block = [
		'blockName' => 'kntnt-gpx-blocks/map',
		'attrs'     => [
			'attachmentId' => 42,
			'style'        => [
				'border' => [
					'radius' => [
						'topLeft'     => '',
						'topRight'    => 'var:preset|border-radius|xl',
						'bottomLeft'  => 'var:preset|border-radius|xl',
						'bottomRight' => 'var:preset|border-radius|xl',
					],
				],
			],
		],
	];

	$result = ( new Border_Radius_Normalizer() )->filter( $parsed_block );

	expect( $result['attrs']['style']['border']['radius'] )
		->toBe( 'var:preset|border-radius|xl' );

} );

test( 'filter rewrites style.border.radius for the Elevation block', function (): void {

	$parsed_block = [
		'blockName' => 'kntnt-gpx-blocks/elevation',
		'attrs'     => [
			'mapId' => 'auto',
			'style' => [
				'border' => [
					'radius' => [
						'topLeft'     => '',
						'topRight'    => '32px',
						'bottomLeft'  => '32px',
						'bottomRight' => '32px',
					],
				],
			],
		],
	];

	$result = ( new Border_Radius_Normalizer() )->filter( $parsed_block );

	expect( $result['attrs']['style']['border']['radius'] )->toBe( '32px' );

} );

test( 'filter leaves non-plugin blocks untouched even when their radius matches the bug shape', function (): void {

	// Core's blocks may legitimately rely on their own pipeline; we only
	// normalise our own two blocks. A `core/group` carrying the same
	// problematic shape must pass through unchanged.
	$parsed_block = [
		'blockName' => 'core/group',
		'attrs'     => [
			'style' => [
				'border' => [
					'radius' => [
						'topLeft'     => '',
						'topRight'    => '32px',
						'bottomLeft'  => '32px',
						'bottomRight' => '32px',
					],
				],
			],
		],
	];

	$result = ( new Border_Radius_Normalizer() )->filter( $parsed_block );

	expect( $result['attrs']['style']['border']['radius'] )
		->toBe(
			[
				'topLeft'     => '',
				'topRight'    => '32px',
				'bottomLeft'  => '32px',
				'bottomRight' => '32px',
			]
		);

} );

test( 'filter is a no-op when the block has no style.border attribute', function (): void {

	$parsed_block = [
		'blockName' => 'kntnt-gpx-blocks/map',
		'attrs'     => [
			'attachmentId' => 42,
		],
	];

	$result = ( new Border_Radius_Normalizer() )->filter( $parsed_block );

	expect( $result )->toBe( $parsed_block );

} );

test( 'filter is a no-op when style.border.radius is already a string', function (): void {

	// String form already produces the correct CSS shorthand — the
	// normaliser must not touch it.
	$parsed_block = [
		'blockName' => 'kntnt-gpx-blocks/map',
		'attrs'     => [
			'style' => [
				'border' => [
					'radius' => 'var:preset|border-radius|xl',
				],
			],
		],
	];

	$result = ( new Border_Radius_Normalizer() )->filter( $parsed_block );

	expect( $result['attrs']['style']['border']['radius'] )
		->toBe( 'var:preset|border-radius|xl' );

} );

test( 'filter preserves attrs unrelated to the radius rewrite', function (): void {

	// The mutation must touch only `style.border.radius`. Sibling keys
	// under `style`, the rest of `border`, and unrelated top-level attrs
	// must survive intact.
	$parsed_block = [
		'blockName' => 'kntnt-gpx-blocks/map',
		'attrs'     => [
			'attachmentId' => 42,
			'mapId'        => 'map-1',
			'style'        => [
				'border'  => [
					'radius' => [
						'topLeft'     => '',
						'topRight'    => '32px',
						'bottomLeft'  => '32px',
						'bottomRight' => '32px',
					],
					'color'  => '#000000',
					'width'  => '2px',
				],
				'spacing' => [ 'margin' => [ 'top' => '20px' ] ],
			],
		],
	];

	$result = ( new Border_Radius_Normalizer() )->filter( $parsed_block );

	expect( $result['attrs']['attachmentId'] )->toBe( 42 );
	expect( $result['attrs']['mapId'] )->toBe( 'map-1' );
	expect( $result['attrs']['style']['border']['color'] )->toBe( '#000000' );
	expect( $result['attrs']['style']['border']['width'] )->toBe( '2px' );
	expect( $result['attrs']['style']['spacing']['margin']['top'] )->toBe( '20px' );
	expect( $result['attrs']['style']['border']['radius'] )->toBe( '32px' );

} );
