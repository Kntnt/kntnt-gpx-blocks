<?php
/**
 * Value object describing a cache-side error that the render layer must surface.
 *
 * Returned by Cache\Attachment_Cache::get() in place of the cached array when
 * the attachment carries `_kntnt_gpx_blocks_error`. Render functions inspect
 * the code and produce the appropriate placeholder. The vocabulary of codes
 * matches docs/caching.md § "Error states":
 * 'no-track', 'too-few-points', 'too-large', 'parse-failed', 'wrong-mime',
 * 'file-missing', 'no-attachment', 'no-map', 'multiple-maps', 'map-not-found',
 * 'no-elevation'.
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
	 * @param string $code    One of the documented error codes.
	 * @param string $message Human-readable diagnostic for log output and
	 *                        editor-only previews. Already localised when
	 *                        produced by the cache layer.
	 */
	public function __construct(
		public string $code,
		public string $message,
	) {}

	/**
	 * Creates a Render_Error with a standardised, translated user-facing message.
	 *
	 * Maps every documented error code to a message suitable for display in the
	 * editor's error notice. Unknown codes fall back to a generic message that
	 * includes the raw code so the developer can identify it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code Error code from the documented vocabulary.
	 *
	 * @return self
	 */
	public static function from_code( string $code ): self {

		// One match expression covers the full documented vocabulary; the default
		// arm guards against future codes arriving via stored meta.
		$message = match ( $code ) {
			'no-track'       => __( 'The selected GPX file contains no track data.', 'kntnt-gpx-blocks' ),
			'too-few-points' => __( 'The GPX track has too few points to render.', 'kntnt-gpx-blocks' ),
			'too-large'      => __( 'The GPX file is too large to process.', 'kntnt-gpx-blocks' ),
			'file-missing'   => __( 'The GPX file no longer exists in the media library.', 'kntnt-gpx-blocks' ),
			'parse-failed'   => __( 'The GPX file could not be parsed. It may be corrupted.', 'kntnt-gpx-blocks' ),
			'wrong-mime'     => __( 'The selected file is not a valid GPX file.', 'kntnt-gpx-blocks' ),
			'no-attachment'  => __( 'Choose a GPX file to display.', 'kntnt-gpx-blocks' ),
			'no-map'         => __( 'Add a GPX Map block to the page first.', 'kntnt-gpx-blocks' ),
			'multiple-maps'  => __( 'Multiple GPX Map blocks exist. Choose which one to use in the block sidebar.', 'kntnt-gpx-blocks' ), // phpcs:ignore Generic.Files.LineLength.TooLong -- translator string must be a single literal.
			'map-not-found'  => __( 'The selected GPX Map is no longer on this page.', 'kntnt-gpx-blocks' ),
			'no-elevation'   => __( 'No elevation data in this GPX file.', 'kntnt-gpx-blocks' ),
			// translators: %s is the unrecognized error code.
			default => sprintf( __( 'An unknown error occurred. (code: %s)', 'kntnt-gpx-blocks' ), $code ),
		};

		return new self( $code, $message );

	}

}
