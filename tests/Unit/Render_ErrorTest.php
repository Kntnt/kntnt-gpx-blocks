<?php
/**
 * Tests for Rendering\Render_Error::from_code().
 *
 * Brain Monkey stubs __() so translations return the English source string,
 * making the tests deterministic.
 *
 * The tests verify:
 * - Each documented code maps to its specified user-facing message.
 * - An unknown code falls back to the generic message that includes the code.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Rendering\Render_Error;

// ---------------------------------------------------------------------------
// Default test setup
// ---------------------------------------------------------------------------

beforeEach( function (): void {

	Functions\when( '__' )->returnArg( 1 );

} );

// ---------------------------------------------------------------------------
// Known codes produce the documented messages
// ---------------------------------------------------------------------------

test( 'from_code: no-track maps to the expected message', function (): void {

	$error = Render_Error::from_code( 'no-track' );

	expect( $error->code )->toBe( 'no-track' );
	expect( $error->message )->toBe( 'The selected GPX file contains no track data.' );

} );

test( 'from_code: too-few-points maps to the expected message', function (): void {

	$error = Render_Error::from_code( 'too-few-points' );

	expect( $error->code )->toBe( 'too-few-points' );
	expect( $error->message )->toBe( 'The GPX track has too few points to render.' );

} );

test( 'from_code: too-large maps to the expected message', function (): void {

	$error = Render_Error::from_code( 'too-large' );

	expect( $error->code )->toBe( 'too-large' );
	expect( $error->message )->toBe( 'The GPX file is too large to process.' );

} );

test( 'from_code: file-missing maps to the expected message', function (): void {

	$error = Render_Error::from_code( 'file-missing' );

	expect( $error->code )->toBe( 'file-missing' );
	expect( $error->message )->toBe( 'The GPX file no longer exists in the media library.' );

} );

test( 'from_code: parse-failed maps to the expected message', function (): void {

	$error = Render_Error::from_code( 'parse-failed' );

	expect( $error->code )->toBe( 'parse-failed' );
	expect( $error->message )->toBe( 'The GPX file could not be parsed. It may be corrupted.' );

} );

test( 'from_code: wrong-mime maps to the expected message', function (): void {

	$error = Render_Error::from_code( 'wrong-mime' );

	expect( $error->code )->toBe( 'wrong-mime' );
	expect( $error->message )->toBe( 'The selected file is not a valid GPX file.' );

} );

test( 'from_code: no-attachment maps to the expected message', function (): void {

	$error = Render_Error::from_code( 'no-attachment' );

	expect( $error->code )->toBe( 'no-attachment' );
	expect( $error->message )->toBe( 'Choose a GPX file to display.' );

} );

test( 'from_code: no-map maps to the expected message', function (): void {

	$error = Render_Error::from_code( 'no-map' );

	expect( $error->code )->toBe( 'no-map' );
	expect( $error->message )->toBe( 'Add a GPX Map block to the page first.' );

} );

test( 'from_code: multiple-maps maps to the expected message', function (): void {

	$error = Render_Error::from_code( 'multiple-maps' );

	expect( $error->code )->toBe( 'multiple-maps' );
	expect( $error->message )->toBe( 'Multiple GPX Map blocks exist. Choose which one to use in the block sidebar.' );

} );

test( 'from_code: map-not-found maps to the expected message', function (): void {

	$error = Render_Error::from_code( 'map-not-found' );

	expect( $error->code )->toBe( 'map-not-found' );
	expect( $error->message )->toBe( 'The selected GPX Map is no longer on this page.' );

} );

test( 'from_code: no-elevation maps to the expected message', function (): void {

	$error = Render_Error::from_code( 'no-elevation' );

	expect( $error->code )->toBe( 'no-elevation' );
	expect( $error->message )->toBe( 'No elevation data in this GPX file.' );

} );

// ---------------------------------------------------------------------------
// Unknown code falls back to the generic message
// ---------------------------------------------------------------------------

test( 'from_code: unknown code falls back to generic message containing the code', function (): void {

	$error = Render_Error::from_code( 'mystery-code' );

	expect( $error->code )->toBe( 'mystery-code' );
	expect( $error->message )->toContain( 'mystery-code' );

} );

// ---------------------------------------------------------------------------
// from_code returns a Render_Error instance
// ---------------------------------------------------------------------------

test( 'from_code returns a Render_Error instance', function (): void {

	$error = Render_Error::from_code( 'no-track' );

	expect( $error )->toBeInstanceOf( Render_Error::class );

} );
