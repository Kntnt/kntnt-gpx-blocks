<?php
/**
 * Tests for Rendering\Error_Renderer.
 *
 * Brain Monkey stubs the WordPress functions used by Error_Renderer so the
 * class can run without a live WordPress install.
 *
 * The tests verify:
 * - With current_user_can('edit_posts') = true, returns HTML containing the
 *   message and code.
 * - With current_user_can('edit_posts') = false, returns an empty string.
 * - The HTML contains role="alert".
 * - The HTML contains the .kntnt-gpx-blocks-error class.
 * - The message is HTML-escaped (XSS prevention).
 * - The code is HTML-escaped.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Rendering\Error_Renderer;
use Kntnt\Gpx_Blocks\Rendering\Render_Error;

// ---------------------------------------------------------------------------
// Default test setup
// ---------------------------------------------------------------------------

beforeEach( function (): void {

	Functions\when( 'esc_html' )->alias(
		static fn ( string $text ): string => htmlspecialchars( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' )
	);

} );

// ---------------------------------------------------------------------------
// Editor sees the alert HTML
// ---------------------------------------------------------------------------

test( 'returns alert HTML when user has edit_posts', function (): void {

	Functions\when( 'current_user_can' )->justReturn( true );

	$renderer = new Error_Renderer();
	$error    = new Render_Error( 'no-track', 'The selected GPX file contains no track data.' );
	$html     = $renderer->render( $error );

	expect( $html )
		->toContain( 'kntnt-gpx-blocks-error' )
		->toContain( 'role="alert"' )
		->toContain( 'The selected GPX file contains no track data.' )
		->toContain( 'no-track' );

} );

// ---------------------------------------------------------------------------
// Visitor sees nothing
// ---------------------------------------------------------------------------

test( 'returns empty string when user lacks edit_posts', function (): void {

	Functions\when( 'current_user_can' )->justReturn( false );

	$renderer = new Error_Renderer();
	$error    = new Render_Error( 'too-large', 'The GPX file is too large.' );
	$html     = $renderer->render( $error );

	expect( $html )->toBe( '' );

} );

// ---------------------------------------------------------------------------
// role="alert" is always present for editors
// ---------------------------------------------------------------------------

test( 'HTML contains role=alert for editor', function (): void {

	Functions\when( 'current_user_can' )->justReturn( true );

	$renderer = new Error_Renderer();
	$error    = new Render_Error( 'parse-failed', 'The GPX file could not be parsed.' );
	$html     = $renderer->render( $error );

	expect( $html )->toContain( 'role="alert"' );

} );

// ---------------------------------------------------------------------------
// XSS: message is escaped
// ---------------------------------------------------------------------------

test( 'message containing HTML is escaped in the output', function (): void {

	Functions\when( 'current_user_can' )->justReturn( true );

	$renderer = new Error_Renderer();
	$error    = new Render_Error( 'xss-test', '<script>alert(1)</script>' );
	$html     = $renderer->render( $error );

	// The raw script tag must not appear; the escaped form must.
	expect( $html )
		->not->toContain( '<script>' )
		->toContain( '&lt;script&gt;' );

} );

// ---------------------------------------------------------------------------
// XSS: code is escaped
// ---------------------------------------------------------------------------

test( 'code containing special characters is escaped in the output', function (): void {

	Functions\when( 'current_user_can' )->justReturn( true );

	$renderer = new Error_Renderer();
	$error    = new Render_Error( '<bad>', 'Some message.' );
	$html     = $renderer->render( $error );

	expect( $html )
		->not->toContain( '<bad>' )
		->toContain( '&lt;bad&gt;' );

} );

// ---------------------------------------------------------------------------
// Output structure: contains <strong> prefix and <code> for the code
// ---------------------------------------------------------------------------

test( 'output contains the Kntnt GPX Blocks label and a code element', function (): void {

	Functions\when( 'current_user_can' )->justReturn( true );

	$renderer = new Error_Renderer();
	$error    = new Render_Error( 'file-missing', 'The GPX file no longer exists.' );
	$html     = $renderer->render( $error );

	expect( $html )
		->toContain( '<strong>' )
		->toContain( '<code>' )
		->toContain( 'file-missing' );

} );
