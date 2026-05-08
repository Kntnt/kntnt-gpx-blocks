<?php
/**
 * Minimal WP class stand-ins for unit tests.
 *
 * The real WordPress classes live in core source files that are unavailable
 * in the unit-test runtime. The stubs below define the bare surface the tests
 * need so that `instanceof` checks and REST-related assertions can run
 * without a WordPress install.
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
	 * Carries the surface used in tests: a constructor matching WP_Error's
	 * (code, message, data) signature and the get_error_* accessors.
	 *
	 * @since 1.0.0
	 */
	class WP_Error {

		private mixed $code;

		private string $message;

		private mixed $data;

		public function __construct( mixed $code = '', string $message = '', mixed $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): mixed {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal stand-in for WordPress's WP_REST_Request class.
	 *
	 * Implements ArrayAccess so the controller's `$request['id']` access works.
	 * Tests subclass this and override the offset accessors; this base class
	 * is a no-op shell.
	 *
	 * @since 1.0.0
	 */
	class WP_REST_Request implements ArrayAccess {

		public function offsetExists( $offset ): bool {
			return false;
		}

		public function offsetGet( $offset ): mixed {
			return null;
		}

		public function offsetSet( $offset, $value ): void {
		}

		public function offsetUnset( $offset ): void {
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal stand-in for WordPress's WP_REST_Response class.
	 *
	 * Holds a payload the test can assert against via `get_data()`.
	 *
	 * @since 1.0.0
	 */
	class WP_REST_Response {

		private mixed $data;

		public function __construct( mixed $data = null ) {
			$this->data = $data;
		}

		public function get_data(): mixed {
			return $this->data;
		}
	}
}
