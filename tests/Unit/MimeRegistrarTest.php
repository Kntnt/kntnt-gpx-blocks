<?php
/**
 * Tests for Bootstrap\Mime_Registrar.
 *
 * Covers the two filter callbacks:
 *   - add_gpx()        — the upload_mimes filter handler.
 *   - override_check() — the wp_check_filetype_and_ext filter handler.
 *
 * Brain Monkey is set up/torn down by the shared TestCase base class.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Kntnt\Gpx_Blocks\Bootstrap\Mime_Registrar;

// ---------------------------------------------------------------------------
// is_gpx_filename() — shared helper used by Upload_Guard, Conversion_Hooks,
// and override_check(). Covers null, empty, mixed case, no-suffix, and suffix.
// ---------------------------------------------------------------------------

test( 'is_gpx_filename returns false for null', function (): void {
	expect( Mime_Registrar::is_gpx_filename( null ) )->toBeFalse();
} );

test( 'is_gpx_filename returns false for empty string', function (): void {
	expect( Mime_Registrar::is_gpx_filename( '' ) )->toBeFalse();
} );

test( 'is_gpx_filename returns false for a name without the suffix', function (): void {
	expect( Mime_Registrar::is_gpx_filename( 'photo.jpg' ) )->toBeFalse();
} );

test( 'is_gpx_filename returns false for the bare suffix in the middle', function (): void {
	expect( Mime_Registrar::is_gpx_filename( 'track.gpx.bak' ) )->toBeFalse();
} );

test( 'is_gpx_filename returns true for lowercase .gpx', function (): void {
	expect( Mime_Registrar::is_gpx_filename( 'track.gpx' ) )->toBeTrue();
} );

test( 'is_gpx_filename returns true for uppercase .GPX', function (): void {
	expect( Mime_Registrar::is_gpx_filename( 'TRACK.GPX' ) )->toBeTrue();
} );

test( 'is_gpx_filename returns true for mixed-case .Gpx', function (): void {
	expect( Mime_Registrar::is_gpx_filename( 'Ride.Gpx' ) )->toBeTrue();
} );

test( 'is_gpx_filename returns true for an absolute path ending in .gpx', function (): void {
	expect( Mime_Registrar::is_gpx_filename( '/var/www/uploads/2026/05/route.gpx' ) )->toBeTrue();
} );

// ---------------------------------------------------------------------------
// add_gpx() — upload_mimes filter
// ---------------------------------------------------------------------------

test( 'add_gpx adds gpx key to mimes array', function (): void {

	$registrar = new Mime_Registrar();
	$result    = $registrar->add_gpx( [] );

	expect( $result )->toHaveKey( 'gpx' )
		->and( $result['gpx'] )->toBe( 'application/gpx+xml' );

} );

test( 'add_gpx preserves existing mimes', function (): void {

	$registrar = new Mime_Registrar();
	$existing  = [
		'jpg' => 'image/jpeg',
		'png' => 'image/png',
	];
	$result    = $registrar->add_gpx( $existing );

	expect( $result )->toHaveKey( 'jpg' )
		->and( $result )->toHaveKey( 'png' )
		->and( $result )->toHaveKey( 'gpx' );

} );

// ---------------------------------------------------------------------------
// override_check() — wp_check_filetype_and_ext filter, .gpx filenames
// ---------------------------------------------------------------------------

/**
 * Returns a baseline $data array representing an unresolved filetype check.
 *
 * @return array<string,string|bool>
 */
function baseline_data(): array {
	return [
		'ext'             => false,
		'type'            => false,
		'proper_filename' => false,
	];
}

test( 'override_check forces gpx type for lowercase .gpx filename', function (): void {

	$registrar = new Mime_Registrar();
	$result    = $registrar->override_check( baseline_data(), '/tmp/upload', 'track.gpx', [], 'text/xml' );

	expect( $result['ext'] )->toBe( 'gpx' )
		->and( $result['type'] )->toBe( 'application/gpx+xml' )
		->and( $result['proper_filename'] )->toBeFalse();

} );

test( 'override_check forces gpx type for uppercase .GPX filename', function (): void {

	$registrar = new Mime_Registrar();
	$result    = $registrar->override_check( baseline_data(), '/tmp/upload', 'track.GPX', [], 'text/xml' );

	expect( $result['ext'] )->toBe( 'gpx' )
		->and( $result['type'] )->toBe( 'application/gpx+xml' );

} );

test( 'override_check forces gpx type for mixed-case .Gpx filename', function (): void {

	$registrar = new Mime_Registrar();
	$result    = $registrar->override_check( baseline_data(), '/tmp/upload', 'track.Gpx', [], 'application/xml' );

	expect( $result['ext'] )->toBe( 'gpx' )
		->and( $result['type'] )->toBe( 'application/gpx+xml' );

} );

// ---------------------------------------------------------------------------
// override_check() — non-.gpx filenames pass through unchanged
// ---------------------------------------------------------------------------

test( 'override_check leaves jpg entry unchanged', function (): void {

	$registrar = new Mime_Registrar();
	$original  = [
		'ext'             => 'jpg',
		'type'            => 'image/jpeg',
		'proper_filename' => false,
	];
	$result    = $registrar->override_check( $original, '/tmp/upload', 'photo.jpg', [], 'image/jpeg' );

	expect( $result )->toBe( $original );

} );

test( 'override_check leaves pdf entry unchanged', function (): void {

	$registrar = new Mime_Registrar();
	$original  = [
		'ext'             => 'pdf',
		'type'            => 'application/pdf',
		'proper_filename' => false,
	];
	$result    = $registrar->override_check( $original, '/tmp/upload', 'document.pdf', [], 'application/pdf' );

	expect( $result )->toBe( $original );

} );

test( 'override_check leaves png entry unchanged', function (): void {

	$registrar = new Mime_Registrar();
	$original  = [
		'ext'             => 'png',
		'type'            => 'image/png',
		'proper_filename' => false,
	];
	$result    = $registrar->override_check( $original, '/tmp/upload', 'image.png', [], 'image/png' );

	expect( $result )->toBe( $original );

} );

// ---------------------------------------------------------------------------
// override_check() — nullable arguments per WordPress core's filter contract
// ---------------------------------------------------------------------------

test( 'override_check accepts null mimes without throwing', function (): void {

	$registrar = new Mime_Registrar();
	$result    = $registrar->override_check( baseline_data(), '/tmp/upload', 'track.gpx', null, 'text/xml' );

	expect( $result['ext'] )->toBe( 'gpx' )
		->and( $result['type'] )->toBe( 'application/gpx+xml' );

} );

test( 'override_check accepts null file path without throwing', function (): void {

	$registrar = new Mime_Registrar();
	$result    = $registrar->override_check( baseline_data(), null, 'track.gpx', [], 'text/xml' );

	expect( $result['ext'] )->toBe( 'gpx' );

} );

test( 'override_check passes through unchanged when filename is null', function (): void {

	$registrar = new Mime_Registrar();
	$baseline  = baseline_data();
	$result    = $registrar->override_check( $baseline, '/tmp/upload', null, [], 'text/xml' );

	expect( $result )->toBe( $baseline );

} );
