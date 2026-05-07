<?php
/**
 * Minimal WP_Error stand-in for unit tests.
 *
 * The real WP_Error lives in wp-includes/class-wp-error.php and is unavailable
 * in the unit-test runtime. The Updater only needs the class to exist so it
 * can pattern-match via $thing instanceof WP_Error inside the is_wp_error()
 * Brain Monkey stub. No methods need to be replicated.
 *
 * Loaded via composer.json's autoload-dev/files entry.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal stand-in for WordPress's WP_Error class.
	 *
	 * Defined only when the real class is not loaded (i.e. during unit tests).
	 * No fields or methods — the test suite uses it solely as an instanceof
	 * sentinel for the is_wp_error() Brain Monkey stub.
	 *
	 * @since 1.0.0
	 */
	class WP_Error {}
}
