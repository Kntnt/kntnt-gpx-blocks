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

if ( ! class_exists( 'WP_Block' ) ) {
	/**
	 * Minimal stand-in for WordPress's WP_Block class.
	 *
	 * Real WP_Block construction requires a full block-type registry which is
	 * not available in unit tests. The stub exposes the public `$context`
	 * property the renderers read, with the same default value as core.
	 *
	 * @since 1.0.0
	 */
	class WP_Block {

		/**
		 * Block context values, keyed by context name.
		 *
		 * @var array<string, mixed>
		 */
		public array $context = [];
	}
}

if ( ! class_exists( 'WP_Theme_JSON_Data' ) ) {
	/**
	 * Minimal stand-in for WordPress's WP_Theme_JSON_Data class.
	 *
	 * Carries the surface Bootstrap\Theme_Json_Border_Optin uses:
	 * `get_data()` returns the underlying array, and `update_with()` performs
	 * the deep-merge core does, then returns the same instance. The deep
	 * merge here mirrors core's behaviour closely enough for unit tests to
	 * assert on the resulting structure.
	 *
	 * @since 1.0.0
	 */
	class WP_Theme_JSON_Data {

		/**
		 * Underlying theme.json data array.
		 *
		 * @var array<string, mixed>
		 */
		private array $data;

		/**
		 * @param array<string, mixed> $data Initial theme.json data.
		 * @param string               $origin Unused in the stub.
		 */
		public function __construct( array $data = [ 'version' => 2 ], string $origin = 'theme' ) {
			$this->data = $data;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_data(): array {
			return $this->data;
		}

		/**
		 * @param array<string, mixed> $new_data Slice to deep-merge on top of the current data.
		 */
		public function update_with( array $new_data ): self {
			$this->data = self::deep_merge( $this->data, $new_data );
			return $this;
		}

		/**
		 * Recursively merges $b into $a; arrays with string keys are merged,
		 * scalars and list-shaped arrays are overwritten.
		 *
		 * @param array<int|string, mixed> $a
		 * @param array<int|string, mixed> $b
		 * @return array<int|string, mixed>
		 */
		private static function deep_merge( array $a, array $b ): array {
			foreach ( $b as $key => $value ) {
				if ( is_array( $value ) && isset( $a[ $key ] ) && is_array( $a[ $key ] ) ) {
					$a[ $key ] = self::deep_merge( $a[ $key ], $value );
				} else {
					$a[ $key ] = $value;
				}
			}
			return $a;
		}
	}
}
