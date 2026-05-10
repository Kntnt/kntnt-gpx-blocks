<?php
/**
 * Tests for Rendering\Color_Sanitizer.
 *
 * Covers every accepted hex shape (3, 4, 6, 8 digits in mixed case) and a
 * battery of rejected inputs: empty string, non-string scalars, structured
 * values, URL-injection probes, hex-but-wrong-length, missing leading `#`,
 * out-of-alphabet characters, and every shape of `var(--…)` reference (which
 * the editor never legitimately produces — the picker UI emits only resolved
 * hex). The validator is pure, has no WordPress dependencies, and can be
 * tested without Brain Monkey.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Kntnt\Gpx_Blocks\Rendering\Color_Sanitizer;

// ---------------------------------------------------------------------------
// Accepted hex shapes — round-trip the input verbatim
// ---------------------------------------------------------------------------

test( 'sanitize: accepts 3-digit hex (#rgb)', function (): void {
	expect( Color_Sanitizer::sanitize( '#abc' ) )->toBe( '#abc' );
} );

test( 'sanitize: accepts 4-digit hex with alpha (#rgba)', function (): void {
	expect( Color_Sanitizer::sanitize( '#abcd' ) )->toBe( '#abcd' );
} );

test( 'sanitize: accepts 6-digit hex (#rrggbb)', function (): void {
	expect( Color_Sanitizer::sanitize( '#0073aa' ) )->toBe( '#0073aa' );
} );

test( 'sanitize: accepts 8-digit hex with alpha (#rrggbbaa)', function (): void {
	expect( Color_Sanitizer::sanitize( '#0073aacc' ) )->toBe( '#0073aacc' );
} );

test( 'sanitize: accepts uppercase hex digits', function (): void {
	expect( Color_Sanitizer::sanitize( '#ABCDEF' ) )->toBe( '#ABCDEF' );
} );

test( 'sanitize: accepts mixed-case hex digits', function (): void {
	expect( Color_Sanitizer::sanitize( '#aBcDeF12' ) )->toBe( '#aBcDeF12' );
} );

// ---------------------------------------------------------------------------
// Rejection battery — empty / non-string / out-of-alphabet / wrong-length
// ---------------------------------------------------------------------------

test( 'sanitize: rejects empty string', function (): void {
	expect( Color_Sanitizer::sanitize( '' ) )->toBe( '' );
} );

test( 'sanitize: rejects null', function (): void {
	expect( Color_Sanitizer::sanitize( null ) )->toBe( '' );
} );

test( 'sanitize: rejects integer', function (): void {
	expect( Color_Sanitizer::sanitize( 0 ) )->toBe( '' );
} );

test( 'sanitize: rejects float', function (): void {
	expect( Color_Sanitizer::sanitize( 1.5 ) )->toBe( '' );
} );

test( 'sanitize: rejects boolean', function (): void {
	expect( Color_Sanitizer::sanitize( true ) )->toBe( '' );
} );

test( 'sanitize: rejects array', function (): void {
	expect( Color_Sanitizer::sanitize( [ '#fff' ] ) )->toBe( '' );
} );

test( 'sanitize: rejects javascript: URL injection probe', function (): void {
	expect( Color_Sanitizer::sanitize( 'javascript:alert(1)' ) )->toBe( '' );
} );

test( 'sanitize: rejects CSS-injection probe with closing paren', function (): void {
	expect( Color_Sanitizer::sanitize( 'red); color:evil' ) )->toBe( '' );
} );

test( 'sanitize: rejects out-of-alphabet hex characters', function (): void {
	expect( Color_Sanitizer::sanitize( '#gggggg' ) )->toBe( '' );
} );

test( 'sanitize: rejects hex of length 1', function (): void {
	expect( Color_Sanitizer::sanitize( '#a' ) )->toBe( '' );
} );

test( 'sanitize: rejects hex of length 2', function (): void {
	expect( Color_Sanitizer::sanitize( '#ab' ) )->toBe( '' );
} );

test( 'sanitize: rejects hex of length 5', function (): void {
	expect( Color_Sanitizer::sanitize( '#abcde' ) )->toBe( '' );
} );

test( 'sanitize: rejects hex of length 7', function (): void {
	expect( Color_Sanitizer::sanitize( '#abcdef0' ) )->toBe( '' );
} );

test( 'sanitize: rejects hex of length 9', function (): void {
	expect( Color_Sanitizer::sanitize( '#abcdef012' ) )->toBe( '' );
} );

test( 'sanitize: rejects hex without leading hash', function (): void {
	expect( Color_Sanitizer::sanitize( '0073aa' ) )->toBe( '' );
} );

test( 'sanitize: rejects rgb() functional notation', function (): void {
	expect( Color_Sanitizer::sanitize( 'rgb(255, 0, 0)' ) )->toBe( '' );
} );

test( 'sanitize: rejects rgba() functional notation', function (): void {
	expect( Color_Sanitizer::sanitize( 'rgba(255, 0, 0, 0.5)' ) )->toBe( '' );
} );

test( 'sanitize: rejects named CSS colour', function (): void {
	expect( Color_Sanitizer::sanitize( 'red' ) )->toBe( '' );
} );

// ---------------------------------------------------------------------------
// Rejected var() shapes — the picker UI never emits these, so they are not
// part of the accepted contract
// ---------------------------------------------------------------------------

test( 'sanitize: rejects var(--ident) preset reference', function (): void {
	expect( Color_Sanitizer::sanitize( 'var(--wp--preset--color--primary)' ) )->toBe( '' );
} );

test( 'sanitize: rejects var(--ident, #rgb) fallback', function (): void {
	expect( Color_Sanitizer::sanitize( 'var(--my-token, #abc)' ) )->toBe( '' );
} );

test( 'sanitize: rejects var(--ident, #rrggbbaa) fallback', function (): void {
	expect( Color_Sanitizer::sanitize( 'var(--my-token, #aabbccdd)' ) )->toBe( '' );
} );

test( 'sanitize: rejects var(--ident,#hex) without whitespace around comma', function (): void {
	expect( Color_Sanitizer::sanitize( 'var(--x,#fff)' ) )->toBe( '' );
} );

test( 'sanitize: rejects var(--ident , #hex) with padded comma', function (): void {
	expect( Color_Sanitizer::sanitize( 'var(--x , #fff)' ) )->toBe( '' );
} );
