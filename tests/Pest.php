<?php
/**
 * Pest bootstrap file.
 *
 * Configures the test suite: declares the default test dataset, assigns
 * architectures, and sets up any global helpers shared across all tests.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

/*
 * Unit tests run with Brain Monkey so that WordPress functions are available
 * as mocks without a full WordPress install.
 */
uses( Tests\Unit\TestCase::class )->in( 'Unit' );

// Pull in unit-test-only fixtures (e.g. minimal WP_Error stand-in) that must
// exist for tests to type-hint against, but that PHPStan must not see — its
// WordPress stubs already declare the real classes.
require_once __DIR__ . '/Unit/fixtures/Wp_Error_Stub.php';

// Lift the plugin's log threshold to `warning` for the test process so that
// the no-leak-to-log invariant tests can actually inspect what
// `Plugin::warning()` writes. The constant is defined exactly once per
// process; if a test eventually wants `debug` it can override at the
// invocation point (define()-before-include cannot be undone, so this
// global choice is the right scope).
if ( ! defined( 'KNTNT_GPX_BLOCKS_LOG_LEVEL' ) ) {
	define( 'KNTNT_GPX_BLOCKS_LOG_LEVEL', 'warning' );
}
