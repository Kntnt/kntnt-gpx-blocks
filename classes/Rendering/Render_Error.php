<?php
/**
 * Value object describing a cache-side error that the render layer must surface.
 *
 * Returned by Cache\Attachment_Cache::get() in place of the cached array when
 * the attachment carries `_kntnt_gpx_blocks_error`. Render functions inspect
 * the code and produce the appropriate placeholder. The vocabulary of codes
 * matches docs/caching.md § "Error states":
 * 'no-track', 'too-few-points', 'too-large', 'parse-failed', 'wrong-mime',
 * 'file-missing'.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rendering;

/**
 * Immutable error descriptor for the render layer.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final readonly class Render_Error {

	/**
	 * Constructs an error descriptor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code    One of the codes listed in docs/caching.md
	 *                        § "Error states".
	 * @param string $message Human-readable diagnostic for log output and
	 *                        editor-only previews. Already localised when
	 *                        produced by the cache layer.
	 */
	public function __construct(
		public string $code,
		public string $message,
	) {}

}
