<?php
/**
 * Tests for Bootstrap\Editor_Data_Enqueuer.
 *
 * Verifies that the enqueuer composes the editor data payload, encodes it
 * via wp_json_encode, and emits it as a `before` inline script on the GPX
 * Map block's editor handle. Also covers the warning path when JSON
 * encoding fails.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Bootstrap\Editor_Data_Enqueuer;
use Kntnt\Gpx_Blocks\Rendering\Tile_Layer_Registry;

beforeEach( function (): void {

	// __() returns the source string verbatim so the registry can build its
	// validated default set without a live WordPress.
	Functions\when( '__' )->returnArg( 1 );

	// Default apply_filters passthrough — tests that need to override the
	// providers/overlays filter alias() it again locally.
	Functions\when( 'apply_filters' )->alias(
		static function ( string $filter, mixed $value ): mixed {
			return $value;
		}
	);

	// wp_json_encode falls through to PHP's json_encode for capture-and-decode
	// assertions. Tests that want to exercise the encoding-failure branch
	// override this stub locally.
	Functions\when( 'wp_json_encode' )->alias(
		static fn ( mixed $v ): string|false => json_encode( $v )
	);

} );

// ---------------------------------------------------------------------------
// Happy path: payload shape and inline-script wiring
// ---------------------------------------------------------------------------

test( 'enqueues window.kntntGpxBlocks as a before inline script on the map editor handle', function (): void {

	$captured_handle  = null;
	$captured_inline  = null;
	$captured_position = null;

	Functions\when( 'wp_add_inline_script' )->alias(
		static function ( string $handle, string $data, string $position = 'after' ) use (
			&$captured_handle,
			&$captured_inline,
			&$captured_position
		): bool {
			$captured_handle   = $handle;
			$captured_inline   = $data;
			$captured_position = $position;
			return true;
		}
	);

	( new Editor_Data_Enqueuer() )->enqueue();

	expect( $captured_handle )->toBe( 'kntnt-gpx-blocks-map-editor-script' );
	expect( $captured_position )->toBe( 'before' );
	expect( $captured_inline )->toStartWith( 'window.kntntGpxBlocks = ' );

} );

test( 'inline payload carries every default provider id and the wmt-hiking overlay', function (): void {

	$captured_inline = null;
	Functions\when( 'wp_add_inline_script' )->alias(
		static function ( string $handle, string $data ) use ( &$captured_inline ): bool {
			$captured_inline = $data;
			return true;
		}
	);

	( new Editor_Data_Enqueuer() )->enqueue();

	expect( $captured_inline )->not->toBeNull();

	// Strip the prefix and trailing semicolon to recover the JSON payload.
	$json = substr( (string) $captured_inline, strlen( 'window.kntntGpxBlocks = ' ) );
	$json = rtrim( $json, ';' );

	$decoded = json_decode( $json, true );

	expect( $decoded )->toBeArray();
	expect( $decoded )->toHaveKey( 'providers' );
	expect( $decoded )->toHaveKey( 'overlays' );

	// Every default provider id appears, with the registry's `requiresKey`
	// flag preserved so the dropdown can drive the API-key field's enabled
	// state in #79.
	expect( $decoded['providers'] )->toHaveKey( 'osm-standard' );
	expect( $decoded['providers'] )->toHaveKey( 'thunderforest-outdoors' );
	expect( $decoded['providers']['osm-standard']['requiresKey'] )->toBeFalse();
	expect( $decoded['providers']['thunderforest-outdoors']['requiresKey'] )->toBeTrue();

	// The single default overlay is present and carries enough data for the
	// editor preview to mount it via L.tileLayer().
	expect( $decoded['overlays'] )->toHaveKey( 'wmt-hiking' );
	expect( $decoded['overlays']['wmt-hiking']['url'] )->toContain( 'waymarkedtrails.org' );
	expect( $decoded['overlays']['wmt-hiking']['attribution'] )->toContain( 'Waymarked' );
	expect( $decoded['overlays']['wmt-hiking']['maxZoom'] )->toBe( 18 );

} );

test( 'payload omits API keys (per-block tileApiKey is never inlined)', function (): void {

	$captured_inline = null;
	Functions\when( 'wp_add_inline_script' )->alias(
		static function ( string $handle, string $data ) use ( &$captured_inline ): bool {
			$captured_inline = $data;
			return true;
		}
	);

	( new Editor_Data_Enqueuer() )->enqueue();

	$payload = (string) $captured_inline;

	// The registry never carries a key in its provider records, but the
	// assertion is structural: no field named `apiKey` or `tileApiKey` may
	// appear in the inlined string.
	expect( $payload )->not->toContain( 'apiKey' );
	expect( $payload )->not->toContain( 'tileApiKey' );

} );

test( 'overlays surface a custom registry entry added via the filter', function (): void {

	// Replace the default overlay set with the canonical wmt-hiking plus a
	// site-builder-supplied overlay so we exercise the filter-flow integration.
	Functions\when( 'apply_filters' )->alias(
		static function ( string $name, mixed $value ): mixed {
			if ( $name === 'kntnt_gpx_blocks_tile_overlays' ) {
				return [
					'wmt-hiking' => [
						'label'       => 'Waymarked Trails — Hiking',
						'url'         => 'https://tile.waymarkedtrails.org/hiking/{z}/{x}/{y}.png',
						'attribution' => '&copy; Waymarked',
						'maxZoom'     => 18,
					],
					'custom-grid' => [
						'label'       => 'Custom Grid',
						'url'         => 'https://grid.example.com/{z}/{x}/{y}.png',
						'attribution' => '&copy; Example',
						'maxZoom'     => 19,
					],
				];
			}
			return $value;
		}
	);

	$captured_inline = null;
	Functions\when( 'wp_add_inline_script' )->alias(
		static function ( string $handle, string $data ) use ( &$captured_inline ): bool {
			$captured_inline = $data;
			return true;
		}
	);

	( new Editor_Data_Enqueuer() )->enqueue();

	$json    = substr( (string) $captured_inline, strlen( 'window.kntntGpxBlocks = ' ) );
	$json    = rtrim( $json, ';' );
	$decoded = json_decode( $json, true );

	expect( $decoded['overlays'] )->toHaveKey( 'custom-grid' );
	expect( $decoded['overlays']['custom-grid']['label'] )->toBe( 'Custom Grid' );

} );

// ---------------------------------------------------------------------------
// Defensive guard: encoding failure
// ---------------------------------------------------------------------------

test( 'logs a warning and skips wp_add_inline_script when wp_json_encode returns false', function (): void {

	Functions\when( 'wp_json_encode' )->justReturn( false );

	Functions\expect( 'wp_add_inline_script' )->never();

	// Plugin::warning() ultimately calls error_log(); Brain Monkey lets us
	// run the method without asserting on the log body — the never()
	// expectation above is the load-bearing assertion.
	( new Editor_Data_Enqueuer() )->enqueue();

} );

// ---------------------------------------------------------------------------
// Constructor injection lets tests pass a synthetic registry
// ---------------------------------------------------------------------------

test( 'accepts a constructor-injected registry without touching the default filter chain', function (): void {

	$registry = new Tile_Layer_Registry();

	$captured_inline = null;
	Functions\when( 'wp_add_inline_script' )->alias(
		static function ( string $handle, string $data ) use ( &$captured_inline ): bool {
			$captured_inline = $data;
			return true;
		}
	);

	( new Editor_Data_Enqueuer( $registry ) )->enqueue();

	expect( $captured_inline )->not->toBeNull();
	expect( (string) $captured_inline )->toStartWith( 'window.kntntGpxBlocks = ' );

} );
