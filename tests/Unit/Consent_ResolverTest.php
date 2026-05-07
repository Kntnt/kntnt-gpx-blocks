<?php
/**
 * Tests for Consent\Consent_Resolver.
 *
 * Brain Monkey stubs `is_admin`, `current_user_can`, and `apply_filters` so the
 * resolver runs without a live WordPress install.
 *
 * Test coverage:
 * - is_required defaults to true on the frontend (is_admin false).
 * - is_required defaults to false in the admin when current user can edit_posts.
 * - is_required follows the filter override in both contexts.
 * - get_category returns 'marketing' by default.
 * - get_category returns the filter value when hooked.
 * - get_service returns 'openstreetmap' by default.
 * - get_service returns the filter value when hooked.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Consent\Consent_Resolver;

// ---------------------------------------------------------------------------
// is_required — frontend default
// ---------------------------------------------------------------------------

test( 'is_required returns true on the frontend by default', function (): void {

	Functions\when( 'is_admin' )->justReturn( false );
	Functions\when( 'current_user_can' )->justReturn( false );
	Functions\when( 'apply_filters' )->alias(
		static fn ( string $hook, mixed $fallback ): mixed => $fallback
	);

	$resolver = new Consent_Resolver();

	expect( $resolver->is_required() )->toBeTrue();

} );

// ---------------------------------------------------------------------------
// is_required — admin default bypasses gate for editors
// ---------------------------------------------------------------------------

test( 'is_required returns false in admin when current user can edit_posts', function (): void {

	Functions\when( 'is_admin' )->justReturn( true );
	Functions\when( 'current_user_can' )->justReturn( true );
	Functions\when( 'apply_filters' )->alias(
		static fn ( string $hook, mixed $fallback ): mixed => $fallback
	);

	$resolver = new Consent_Resolver();

	expect( $resolver->is_required() )->toBeFalse();

} );

// ---------------------------------------------------------------------------
// is_required — filter override on the frontend
// ---------------------------------------------------------------------------

test( 'is_required returns false when filter overrides to false on frontend', function (): void {

	Functions\when( 'is_admin' )->justReturn( false );
	Functions\when( 'current_user_can' )->justReturn( false );
	Functions\when( 'apply_filters' )->alias(
		static function ( string $hook, mixed $fallback ): mixed {
			if ( $hook === 'kntnt_gpx_blocks_consent_required' ) {
				return false;
			}
			return $fallback;
		}
	);

	$resolver = new Consent_Resolver();

	expect( $resolver->is_required() )->toBeFalse();

} );

// ---------------------------------------------------------------------------
// is_required — filter override forces gate on even in admin
// ---------------------------------------------------------------------------

test( 'is_required returns true when filter forces true in admin', function (): void {

	Functions\when( 'is_admin' )->justReturn( true );
	Functions\when( 'current_user_can' )->justReturn( true );
	Functions\when( 'apply_filters' )->alias(
		static function ( string $hook, mixed $fallback ): mixed {
			if ( $hook === 'kntnt_gpx_blocks_consent_required' ) {
				return true;
			}
			return $fallback;
		}
	);

	$resolver = new Consent_Resolver();

	expect( $resolver->is_required() )->toBeTrue();

} );

// ---------------------------------------------------------------------------
// get_category — default
// ---------------------------------------------------------------------------

test( 'get_category returns marketing by default', function (): void {

	Functions\when( 'apply_filters' )->alias(
		static fn ( string $hook, mixed $fallback ): mixed => $fallback
	);

	$resolver = new Consent_Resolver();

	expect( $resolver->get_category() )->toBe( 'marketing' );

} );

// ---------------------------------------------------------------------------
// get_category — filter override
// ---------------------------------------------------------------------------

test( 'get_category returns filter value when hooked', function (): void {

	Functions\when( 'apply_filters' )->alias(
		static function ( string $hook, mixed $fallback ): mixed {
			if ( $hook === 'kntnt_gpx_blocks_consent_category' ) {
				return 'functional';
			}
			return $fallback;
		}
	);

	$resolver = new Consent_Resolver();

	expect( $resolver->get_category() )->toBe( 'functional' );

} );

// ---------------------------------------------------------------------------
// get_service — default
// ---------------------------------------------------------------------------

test( 'get_service returns openstreetmap by default', function (): void {

	Functions\when( 'apply_filters' )->alias(
		static fn ( string $hook, mixed $fallback ): mixed => $fallback
	);

	$resolver = new Consent_Resolver();

	expect( $resolver->get_service() )->toBe( 'openstreetmap' );

} );

// ---------------------------------------------------------------------------
// get_service — filter override
// ---------------------------------------------------------------------------

test( 'get_service returns filter value when hooked', function (): void {

	Functions\when( 'apply_filters' )->alias(
		static function ( string $hook, mixed $fallback ): mixed {
			if ( $hook === 'kntnt_gpx_blocks_consent_service' ) {
				return 'osm-custom';
			}
			return $fallback;
		}
	);

	$resolver = new Consent_Resolver();

	expect( $resolver->get_service() )->toBe( 'osm-custom' );

} );
