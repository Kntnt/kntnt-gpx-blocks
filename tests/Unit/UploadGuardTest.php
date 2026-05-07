<?php
/**
 * Tests for Bootstrap\Upload_Guard.
 *
 * Covers enforce_size_cap(), which gates .gpx uploads against the
 * kntnt_gpx_blocks_max_file_size_bytes filter value. Brain Monkey is used to
 * stub apply_filters() and __() so the class can run without WordPress.
 *
 * Brain Monkey is set up/torn down by the shared TestCase base class.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Bootstrap\Upload_Guard;

// ---------------------------------------------------------------------------
// Non-.gpx files — must pass through untouched
// ---------------------------------------------------------------------------

test( 'enforce_size_cap leaves non-gpx files alone', function (): void {

	$guard = new Upload_Guard();
	$file  = [
		'name' => 'photo.jpg',
		'size' => 20 * 1024 * 1024,
	];
	$result = $guard->enforce_size_cap( $file );

	expect( $result )->toBe( $file );

} );

test( 'enforce_size_cap leaves pdf files alone', function (): void {

	$guard = new Upload_Guard();
	$file  = [
		'name' => 'document.pdf',
		'size' => 50 * 1024 * 1024,
	];
	$result = $guard->enforce_size_cap( $file );

	expect( $result )->toBe( $file );

} );

// ---------------------------------------------------------------------------
// .gpx files at or below the cap — no error set
// ---------------------------------------------------------------------------

test( 'enforce_size_cap leaves gpx file at exact cap alone', function (): void {

	$cap_bytes = 10 * 1024 * 1024;

	Functions\expect( 'apply_filters' )
		->once()
		->with( 'kntnt_gpx_blocks_max_file_size_bytes', $cap_bytes )
		->andReturn( $cap_bytes );

	$guard  = new Upload_Guard();
	$file   = [
		'name' => 'track.gpx',
		'size' => $cap_bytes,
	];
	$result = $guard->enforce_size_cap( $file );

	expect( $result )->not->toHaveKey( 'error' );

} );

test( 'enforce_size_cap leaves gpx file below cap alone', function (): void {

	$cap_bytes = 10 * 1024 * 1024;

	Functions\expect( 'apply_filters' )
		->once()
		->with( 'kntnt_gpx_blocks_max_file_size_bytes', $cap_bytes )
		->andReturn( $cap_bytes );

	$guard  = new Upload_Guard();
	$file   = [
		'name' => 'small-track.gpx',
		'size' => 1024,
	];
	$result = $guard->enforce_size_cap( $file );

	expect( $result )->not->toHaveKey( 'error' );

} );

// ---------------------------------------------------------------------------
// .gpx files exceeding the cap — error message set
// ---------------------------------------------------------------------------

test( 'enforce_size_cap sets error when gpx exceeds default cap', function (): void {

	$cap_bytes = 10 * 1024 * 1024;

	Functions\expect( 'apply_filters' )
		->once()
		->with( 'kntnt_gpx_blocks_max_file_size_bytes', $cap_bytes )
		->andReturn( $cap_bytes );

	Functions\expect( '__' )
		->once()
		->andReturnFirstArg();

	$guard  = new Upload_Guard();
	$file   = [
		'name' => 'huge-track.gpx',
		'size' => $cap_bytes + 1,
	];
	$result = $guard->enforce_size_cap( $file );

	expect( $result )->toHaveKey( 'error' )
		->and( $result['error'] )->toContain( '10' );

} );

test( 'enforce_size_cap honors custom cap from filter', function (): void {

	$default_cap = 10 * 1024 * 1024;
	$custom_cap  = 2 * 1024 * 1024; // 2 MB override.

	Functions\expect( 'apply_filters' )
		->once()
		->with( 'kntnt_gpx_blocks_max_file_size_bytes', $default_cap )
		->andReturn( $custom_cap );

	Functions\expect( '__' )
		->once()
		->andReturnFirstArg();

	$guard  = new Upload_Guard();
	$file   = [
		'name' => 'medium-track.gpx',
		'size' => 5 * 1024 * 1024,
	]; // 5 MB > 2 MB cap.
	$result = $guard->enforce_size_cap( $file );

	expect( $result )->toHaveKey( 'error' )
		->and( $result['error'] )->toContain( '2' );

} );

test( 'enforce_size_cap does not set error when gpx is below custom cap', function (): void {

	$default_cap = 10 * 1024 * 1024;
	$custom_cap  = 20 * 1024 * 1024; // 20 MB override.

	Functions\expect( 'apply_filters' )
		->once()
		->with( 'kntnt_gpx_blocks_max_file_size_bytes', $default_cap )
		->andReturn( $custom_cap );

	$guard  = new Upload_Guard();
	$file   = [
		'name' => 'track.gpx',
		'size' => 15 * 1024 * 1024,
	]; // 15 MB < 20 MB cap.
	$result = $guard->enforce_size_cap( $file );

	expect( $result )->not->toHaveKey( 'error' );

} );

test( 'enforce_size_cap handles uppercase GPX extension', function (): void {

	$cap_bytes = 10 * 1024 * 1024;

	Functions\expect( 'apply_filters' )
		->once()
		->with( 'kntnt_gpx_blocks_max_file_size_bytes', $cap_bytes )
		->andReturn( $cap_bytes );

	Functions\expect( '__' )
		->once()
		->andReturnFirstArg();

	$guard  = new Upload_Guard();
	$file   = [
		'name' => 'TRACK.GPX',
		'size' => $cap_bytes + 1,
	];
	$result = $guard->enforce_size_cap( $file );

	expect( $result )->toHaveKey( 'error' );

} );
