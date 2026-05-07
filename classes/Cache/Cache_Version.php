<?php
/**
 * Holds the cache-format version constant.
 *
 * The constant is read by Cache\Attachment_Cache to decide whether a stored
 * conversion is current. Bumping the value invalidates every cached
 * conversion across the site at the next render — see docs/caching.md
 * § "Cache version" for the contract.
 *
 * The value lives on a final class rather than as a top-level define() so
 * PSR-4 autoloading and PHPStan/PHPCS treat it cleanly. The constant is
 * typed (PHP 8.3+) to make the read site explicit.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Cache;

/**
 * Carrier for the cache-format version constant.
 *
 * Bump CURRENT whenever a change to the conversion contract makes existing
 * cached payloads obsolete (a new GPX field captured, a bug fix in the
 * distance summation, a different default climb threshold). Lazy fallback in
 * Attachment_Cache::get() then regenerates each cache on first read.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Cache_Version {

	/**
	 * Current cache-format version.
	 *
	 * Stored alongside every cached conversion in
	 * `_kntnt_gpx_blocks_version`. Compared via `<` against the stored value
	 * during reads; when the stored value is missing or older, the cache is
	 * regenerated.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const int CURRENT = 1;

}
