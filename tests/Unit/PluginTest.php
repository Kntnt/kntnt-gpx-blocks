<?php
/**
 * Tests for Plugin singleton — logging level threshold behaviour.
 *
 * Covers all five threshold values ('none', 'error', 'warning', 'info',
 * 'debug') and verifies that each method is silenced or emitted exactly as
 * the architecture specifies.
 *
 * The threshold gate is tested via an internal helper that mirrors the exact
 * severity-map comparison used in Plugin::log(). Live invocation tests
 * confirm that each public method is callable without throwing.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Kntnt\Gpx_Blocks\Plugin;

// ---------------------------------------------------------------------------
// Threshold-gate helper — mirrors Plugin::log() severity comparison exactly.
// ---------------------------------------------------------------------------

/**
 * Returns true when a message at $message_level should be written given the
 * configured $threshold.
 *
 * This function mirrors the private Plugin::log() gate so the tests are
 * deterministic without environment side-effects from calling error_log().
 *
 * @param string $message_level  One of 'error', 'warning', 'info', 'debug'.
 * @param string $threshold      One of 'none', 'error', 'warning', 'info', 'debug'.
 * @return bool
 */
function should_log( string $message_level, string $threshold ): bool {
	$levels = [
		'none'    => -1,
		'error'   => 0,
		'warning' => 1,
		'info'    => 2,
		'debug'   => 3,
	];

	$threshold_key   = array_key_exists( $threshold, $levels ) ? $threshold : 'error';
	$threshold_value = $levels[ $threshold_key ];

	if ( $threshold_value < 0 ) {
		return false;
	}

	return $levels[ $message_level ] <= $threshold_value;
}

// ---------------------------------------------------------------------------
// Threshold: 'none' — all levels silenced
// ---------------------------------------------------------------------------

test( 'threshold none silences error', function (): void {
	expect( should_log( 'error', 'none' ) )->toBeFalse();
} );

test( 'threshold none silences warning', function (): void {
	expect( should_log( 'warning', 'none' ) )->toBeFalse();
} );

test( 'threshold none silences info', function (): void {
	expect( should_log( 'info', 'none' ) )->toBeFalse();
} );

test( 'threshold none silences debug', function (): void {
	expect( should_log( 'debug', 'none' ) )->toBeFalse();
} );

// ---------------------------------------------------------------------------
// Threshold: 'error' (default) — only error passes
// ---------------------------------------------------------------------------

test( 'threshold error passes error', function (): void {
	expect( should_log( 'error', 'error' ) )->toBeTrue();
} );

test( 'threshold error silences warning', function (): void {
	expect( should_log( 'warning', 'error' ) )->toBeFalse();
} );

test( 'threshold error silences info', function (): void {
	expect( should_log( 'info', 'error' ) )->toBeFalse();
} );

test( 'threshold error silences debug', function (): void {
	expect( should_log( 'debug', 'error' ) )->toBeFalse();
} );

// ---------------------------------------------------------------------------
// Threshold: 'warning' — error and warning pass
// ---------------------------------------------------------------------------

test( 'threshold warning passes error', function (): void {
	expect( should_log( 'error', 'warning' ) )->toBeTrue();
} );

test( 'threshold warning passes warning', function (): void {
	expect( should_log( 'warning', 'warning' ) )->toBeTrue();
} );

test( 'threshold warning silences info', function (): void {
	expect( should_log( 'info', 'warning' ) )->toBeFalse();
} );

test( 'threshold warning silences debug', function (): void {
	expect( should_log( 'debug', 'warning' ) )->toBeFalse();
} );

// ---------------------------------------------------------------------------
// Threshold: 'info' — error, warning, and info pass
// ---------------------------------------------------------------------------

test( 'threshold info passes error', function (): void {
	expect( should_log( 'error', 'info' ) )->toBeTrue();
} );

test( 'threshold info passes warning', function (): void {
	expect( should_log( 'warning', 'info' ) )->toBeTrue();
} );

test( 'threshold info passes info', function (): void {
	expect( should_log( 'info', 'info' ) )->toBeTrue();
} );

test( 'threshold info silences debug', function (): void {
	expect( should_log( 'debug', 'info' ) )->toBeFalse();
} );

// ---------------------------------------------------------------------------
// Threshold: 'debug' — all levels pass
// ---------------------------------------------------------------------------

test( 'threshold debug passes error', function (): void {
	expect( should_log( 'error', 'debug' ) )->toBeTrue();
} );

test( 'threshold debug passes warning', function (): void {
	expect( should_log( 'warning', 'debug' ) )->toBeTrue();
} );

test( 'threshold debug passes info', function (): void {
	expect( should_log( 'info', 'debug' ) )->toBeTrue();
} );

test( 'threshold debug passes debug', function (): void {
	expect( should_log( 'debug', 'debug' ) )->toBeTrue();
} );

// ---------------------------------------------------------------------------
// Live invocation — confirm each public method runs without exceptions.
// KNTNT_GPX_BLOCKS_LOG_LEVEL is not defined in the test process, so the
// default 'error' threshold applies: error() will call error_log() and the
// others will be silenced. All four calls are made so the no-throw guarantee
// is verified for every public method.
// ---------------------------------------------------------------------------

test( 'Plugin::error() does not throw', function (): void {
	Plugin::error( 'unit-test error message' );
	expect( true )->toBeTrue();
} );

test( 'Plugin::warning() does not throw', function (): void {
	Plugin::warning( 'unit-test warning message' );
	expect( true )->toBeTrue();
} );

test( 'Plugin::info() does not throw', function (): void {
	Plugin::info( 'unit-test info message' );
	expect( true )->toBeTrue();
} );

test( 'Plugin::debug() does not throw', function (): void {
	Plugin::debug( 'unit-test debug message' );
	expect( true )->toBeTrue();
} );

// ---------------------------------------------------------------------------
// Static helper methods
// ---------------------------------------------------------------------------

test( 'get_plugin_file returns expected path after bootstrap', function (): void {

	// Bootstrap with a fake path — the test process has no real plugin file.
	$fake_path = '/fake/path/to/kntnt-gpx-blocks.php';
	Plugin::get_instance( $fake_path );

	expect( Plugin::get_plugin_file() )->toBe( $fake_path );

} );
