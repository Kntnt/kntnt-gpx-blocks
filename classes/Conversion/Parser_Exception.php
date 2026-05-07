<?php
/**
 * Exception type thrown by Gpx_Parser to signal a parse failure.
 *
 * The error vocabulary matches the cache error keys documented in
 * docs/caching.md § "Error states": 'no-track', 'too-few-points', 'too-large',
 * 'parse-failed', 'wrong-mime', 'file-missing'. The vocabulary is propagated
 * to the cache layer (issue #7) where it is persisted as
 * _kntnt_gpx_blocks_error and consumed by the render functions.
 *
 * The standard \Throwable::getCode() returns int, but the cache vocabulary is
 * string — so the code lives in a separate property exposed via
 * getErrorCode().
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Conversion;

use RuntimeException;
use Throwable;

/**
 * Runtime exception with a string error code from the GPX-cache vocabulary.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Parser_Exception extends RuntimeException {

	/**
	 * The string error code from the cache vocabulary.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $error_code;

	/**
	 * Constructs a parse-failure exception.
	 *
	 * @since 1.0.0
	 *
	 * @param string         $error_code One of: 'no-track', 'too-few-points',
	 *                                   'too-large', 'parse-failed',
	 *                                   'wrong-mime', 'file-missing'.
	 * @param string         $message    Optional human-readable message.
	 * @param Throwable|null $previous   Optional underlying error.
	 */
	public function __construct( string $error_code, string $message = '', ?Throwable $previous = null ) {

		parent::__construct( '' === $message ? $error_code : $message, 0, $previous );
		$this->error_code = $error_code;

	}

	/**
	 * Returns the string error code from the cache vocabulary.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function getErrorCode(): string {
		return $this->error_code;
	}

}
