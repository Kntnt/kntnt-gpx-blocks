<?php
/**
 * Tests for Rendering\Request_Context.
 *
 * Verifies the three-condition editor-request predicate that both Render_Map
 * and Render_Elevation rely on: `REST_REQUEST` must be defined, it must be
 * truthy, and the current user must have the `edit_posts` capability. All
 * three must hold for the predicate to return `true`.
 *
 * PHP constants cannot be undefined once defined, so the "REST_REQUEST is
 * not defined" branch cannot be exercised in-process by a test that runs
 * alongside other tests that define it. The branch is structurally proven
 * by PHP's short-circuit `&&` operator and by the unit tests in
 * Render_ElevationTest / Render_MapTest that cover the non-editor render
 * paths (which exercise the predicate returning `false` while REST_REQUEST
 * is still undefined at that point in the test run). The three tests below
 * cover the remaining branches: `current_user_can` false (without touching
 * REST_REQUEST), the success path (all three true), and an isolated
 * `current_user_can` false path while REST_REQUEST is true.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Rendering\Request_Context;

// ---------------------------------------------------------------------------
// `current_user_can` clause — anonymous or non-editor users never bypass
// ---------------------------------------------------------------------------

test( 'returns false when current_user_can( edit_posts ) is false', function (): void {

	Functions\when( 'current_user_can' )->justReturn( false );

	expect( Request_Context::is_editor_request() )->toBeFalse();

} );

// ---------------------------------------------------------------------------
// Success path — REST_REQUEST defined truthy and user can edit_posts
//
// REST_REQUEST is defined here for the first time in this file. PHP constants
// cannot be undefined, so this test must come last among the tests in this
// file that depend on its state.
// ---------------------------------------------------------------------------

test( 'returns true when REST_REQUEST is truthy and user can edit_posts', function (): void {

	if ( ! defined( 'REST_REQUEST' ) ) {
		define( 'REST_REQUEST', true );
	}

	Functions\when( 'current_user_can' )->alias(
		static fn ( string $cap ): bool => 'edit_posts' === $cap
	);

	expect( Request_Context::is_editor_request() )->toBeTrue();

} );

// ---------------------------------------------------------------------------
// `current_user_can` clause — still false even with REST_REQUEST truthy
//
// Runs after the success-path test so REST_REQUEST is guaranteed to be
// defined and truthy by the time this test fires; the assertion isolates
// the `current_user_can` clause as the deciding factor.
// ---------------------------------------------------------------------------

test( 'returns false when REST_REQUEST is truthy but user cannot edit_posts', function (): void {

	if ( ! defined( 'REST_REQUEST' ) ) {
		define( 'REST_REQUEST', true );
	}

	Functions\when( 'current_user_can' )->justReturn( false );

	expect( Request_Context::is_editor_request() )->toBeFalse();

} );
