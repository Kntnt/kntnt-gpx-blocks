<?php
/**
 * Tests for Rendering\Typography_Sanitizer.
 *
 * Each of the eight static methods is exercised with a small but
 * representative battery: at least one accepted value per shape the
 * allow-list permits, at least one rejected value that probes the
 * adjacent injection surface, plus the common rejection cases (empty
 * string, non-string scalar). The class has no WordPress dependencies
 * and runs without Brain Monkey.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Kntnt\Gpx_Blocks\Rendering\Typography_Sanitizer;

// ---------------------------------------------------------------------------
// font_family
// ---------------------------------------------------------------------------

test( 'font_family: accepts a CSS theme-preset reference', function (): void {
	expect( Typography_Sanitizer::font_family( 'var(--wp--preset--font-family--system-ui)' ) )
		->toBe( 'var(--wp--preset--font-family--system-ui)' );
} );

test( 'font_family: accepts a quoted family name stack', function (): void {
	expect( Typography_Sanitizer::font_family( '"Helvetica Neue", Arial, sans-serif' ) )
		->toBe( '"Helvetica Neue", Arial, sans-serif' );
} );

test( 'font_family: accepts a single unquoted family name', function (): void {
	expect( Typography_Sanitizer::font_family( 'Inter' ) )->toBe( 'Inter' );
} );

test( 'font_family: rejects empty string', function (): void {
	expect( Typography_Sanitizer::font_family( '' ) )->toBe( '' );
} );

test( 'font_family: rejects non-string input', function (): void {
	expect( Typography_Sanitizer::font_family( 42 ) )->toBe( '' );
	expect( Typography_Sanitizer::font_family( null ) )->toBe( '' );
	expect( Typography_Sanitizer::font_family( [ 'Arial' ] ) )->toBe( '' );
} );

test( 'font_family: rejects a CSS-injection probe with a semicolon', function (): void {
	// The semicolon is the practical injection boundary — anything that
	// could break out of the `--…: <value>;` cell of the wrapper's inline
	// style is rejected by the character allow-list. (An arbitrary
	// `var(--whatever)` happens to satisfy the regex because it uses only
	// letters/hyphens/parens, but an undefined custom property resolves
	// to the property's initial value — no CSS-injection surface there.)
	expect( Typography_Sanitizer::font_family( 'Arial; background: red' ) )->toBe( '' );
} );

// ---------------------------------------------------------------------------
// font_size
// ---------------------------------------------------------------------------

test( 'font_size: accepts a numeric px length', function (): void {
	expect( Typography_Sanitizer::font_size( '14px' ) )->toBe( '14px' );
} );

test( 'font_size: accepts a fractional rem length', function (): void {
	expect( Typography_Sanitizer::font_size( '1.25rem' ) )->toBe( '1.25rem' );
} );

test( 'font_size: accepts a unitless numeric value', function (): void {
	expect( Typography_Sanitizer::font_size( '16' ) )->toBe( '16' );
} );

test( 'font_size: accepts a CSS theme-preset reference', function (): void {
	expect( Typography_Sanitizer::font_size( 'var(--wp--preset--font-size--medium)' ) )
		->toBe( 'var(--wp--preset--font-size--medium)' );
} );

test( 'font_size: rejects an unsupported unit', function (): void {
	expect( Typography_Sanitizer::font_size( '14pt' ) )->toBe( '' );
} );

test( 'font_size: rejects empty string and non-string input', function (): void {
	expect( Typography_Sanitizer::font_size( '' ) )->toBe( '' );
	expect( Typography_Sanitizer::font_size( 14 ) )->toBe( '' );
} );

// ---------------------------------------------------------------------------
// font_weight
// ---------------------------------------------------------------------------

test( 'font_weight: accepts each numeric step', function (): void {
	foreach ( [ '100', '200', '300', '400', '500', '600', '700', '800', '900' ] as $step ) {
		expect( Typography_Sanitizer::font_weight( $step ) )->toBe( $step );
	}
} );

test( 'font_weight: accepts the four keywords', function (): void {
	foreach ( [ 'normal', 'bold', 'lighter', 'bolder' ] as $kw ) {
		expect( Typography_Sanitizer::font_weight( $kw ) )->toBe( $kw );
	}
} );

test( 'font_weight: rejects values outside the numeric step set', function (): void {
	expect( Typography_Sanitizer::font_weight( '350' ) )->toBe( '' );
	expect( Typography_Sanitizer::font_weight( '1000' ) )->toBe( '' );
} );

test( 'font_weight: rejects empty string and non-string input', function (): void {
	expect( Typography_Sanitizer::font_weight( '' ) )->toBe( '' );
	expect( Typography_Sanitizer::font_weight( 700 ) )->toBe( '' );
} );

// ---------------------------------------------------------------------------
// font_style
// ---------------------------------------------------------------------------

test( 'font_style: accepts the three permitted keywords', function (): void {
	expect( Typography_Sanitizer::font_style( 'normal' ) )->toBe( 'normal' );
	expect( Typography_Sanitizer::font_style( 'italic' ) )->toBe( 'italic' );
	expect( Typography_Sanitizer::font_style( 'oblique' ) )->toBe( 'oblique' );
} );

test( 'font_style: rejects oblique-with-angle syntax', function (): void {
	expect( Typography_Sanitizer::font_style( 'oblique 10deg' ) )->toBe( '' );
} );

test( 'font_style: rejects empty string and non-string input', function (): void {
	expect( Typography_Sanitizer::font_style( '' ) )->toBe( '' );
	expect( Typography_Sanitizer::font_style( null ) )->toBe( '' );
} );

// ---------------------------------------------------------------------------
// line_height
// ---------------------------------------------------------------------------

test( 'line_height: accepts a unitless multiplier', function (): void {
	expect( Typography_Sanitizer::line_height( '1.5' ) )->toBe( '1.5' );
} );

test( 'line_height: accepts a numeric length', function (): void {
	expect( Typography_Sanitizer::line_height( '24px' ) )->toBe( '24px' );
} );

test( 'line_height: accepts the keyword "normal"', function (): void {
	expect( Typography_Sanitizer::line_height( 'normal' ) )->toBe( 'normal' );
} );

test( 'line_height: rejects a negative value', function (): void {
	expect( Typography_Sanitizer::line_height( '-1' ) )->toBe( '' );
} );

test( 'line_height: rejects an unsupported unit', function (): void {
	expect( Typography_Sanitizer::line_height( '1.5pt' ) )->toBe( '' );
} );

test( 'line_height: rejects empty string and non-string input', function (): void {
	expect( Typography_Sanitizer::line_height( '' ) )->toBe( '' );
	expect( Typography_Sanitizer::line_height( 1.5 ) )->toBe( '' );
} );

// ---------------------------------------------------------------------------
// letter_spacing
// ---------------------------------------------------------------------------

test( 'letter_spacing: accepts a positive numeric length', function (): void {
	expect( Typography_Sanitizer::letter_spacing( '0.5em' ) )->toBe( '0.5em' );
} );

test( 'letter_spacing: accepts a negative numeric length', function (): void {
	expect( Typography_Sanitizer::letter_spacing( '-1px' ) )->toBe( '-1px' );
} );

test( 'letter_spacing: accepts the keyword "normal"', function (): void {
	expect( Typography_Sanitizer::letter_spacing( 'normal' ) )->toBe( 'normal' );
} );

test( 'letter_spacing: rejects unitless numeric values', function (): void {
	expect( Typography_Sanitizer::letter_spacing( '2' ) )->toBe( '' );
} );

test( 'letter_spacing: rejects an unsupported unit', function (): void {
	expect( Typography_Sanitizer::letter_spacing( '2pt' ) )->toBe( '' );
} );

test( 'letter_spacing: rejects empty string and non-string input', function (): void {
	expect( Typography_Sanitizer::letter_spacing( '' ) )->toBe( '' );
	expect( Typography_Sanitizer::letter_spacing( 2 ) )->toBe( '' );
} );

// ---------------------------------------------------------------------------
// text_transform
// ---------------------------------------------------------------------------

test( 'text_transform: accepts the four permitted keywords', function (): void {
	foreach ( [ 'none', 'uppercase', 'lowercase', 'capitalize' ] as $kw ) {
		expect( Typography_Sanitizer::text_transform( $kw ) )->toBe( $kw );
	}
} );

test( 'text_transform: rejects non-panel keywords', function (): void {
	expect( Typography_Sanitizer::text_transform( 'full-width' ) )->toBe( '' );
	expect( Typography_Sanitizer::text_transform( 'inherit' ) )->toBe( '' );
} );

test( 'text_transform: rejects empty string and non-string input', function (): void {
	expect( Typography_Sanitizer::text_transform( '' ) )->toBe( '' );
	expect( Typography_Sanitizer::text_transform( 0 ) )->toBe( '' );
} );

// ---------------------------------------------------------------------------
// text_decoration
// ---------------------------------------------------------------------------

test( 'text_decoration: accepts the four permitted keywords', function (): void {
	foreach ( [ 'none', 'underline', 'overline', 'line-through' ] as $kw ) {
		expect( Typography_Sanitizer::text_decoration( $kw ) )->toBe( $kw );
	}
} );

test( 'text_decoration: rejects composite shorthand values', function (): void {
	expect( Typography_Sanitizer::text_decoration( 'underline dotted red' ) )->toBe( '' );
} );

test( 'text_decoration: rejects empty string and non-string input', function (): void {
	expect( Typography_Sanitizer::text_decoration( '' ) )->toBe( '' );
	expect( Typography_Sanitizer::text_decoration( false ) )->toBe( '' );
} );
