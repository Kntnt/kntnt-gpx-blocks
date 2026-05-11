<?php
/**
 * Enforces the GPX file-size cap before WordPress processes the upload.
 *
 * Large uploads are rejected here — before any parsing — so an attacker
 * cannot trigger memory-intensive XML processing by uploading a gigantic file.
 * The cap defaults to 10 MB and is adjustable via the
 * kntnt_gpx_blocks_max_file_size_bytes filter. See docs/security.md §
 * "File validation" and docs/hooks.md for the full contract.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Bootstrap;

/**
 * Rejects .gpx uploads that exceed the configurable file-size cap.
 *
 * One filter method is registered by Plugin::__construct():
 *   - enforce_size_cap() on 'wp_handle_upload_prefilter' — rejects oversized files.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Upload_Guard {

	/**
	 * Default maximum GPX file size in bytes (10 MB).
	 *
	 * Overridable via the kntnt_gpx_blocks_max_file_size_bytes filter.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const DEFAULT_MAX_BYTES = 10 * 1024 * 1024;

	/**
	 * Rejects a .gpx upload whose size exceeds the configured cap.
	 *
	 * Hooked to 'wp_handle_upload_prefilter'. Non-.gpx files are returned
	 * unchanged. For .gpx files above the cap, a translated error message is
	 * set on $file['error']; WordPress then aborts the upload and surfaces the
	 * message to the editor.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,string|int> $file The $_FILES entry for the upload.
	 *                                        Keys include 'name', 'tmp_name',
	 *                                        'size', and 'error'.
	 * @return array<string,string|int> The original entry, or one with 'error' set.
	 */
	public function enforce_size_cap( array $file ): array {

		// Only inspect .gpx uploads; all other types pass through unchanged.
		$name = $file['name'] ?? null;
		if ( ! Mime_Registrar::is_gpx_filename( is_string( $name ) ? $name : null ) ) {
			return $file;
		}

		// Read the filterable cap; fall back to the default when the filter returns a non-integer.
		$filter_value = apply_filters( 'kntnt_gpx_blocks_max_file_size_bytes', self::DEFAULT_MAX_BYTES );
		$max_bytes    = is_int( $filter_value ) ? $filter_value : self::DEFAULT_MAX_BYTES;

		// Reject the upload when it exceeds the cap.
		if ( (int) ( $file['size'] ?? 0 ) > $max_bytes ) {
			$max_mb         = (int) round( $max_bytes / ( 1024 * 1024 ) );
			$file['error']  = sprintf(
				/* translators: %d: maximum allowed file size in megabytes */
				__( 'GPX file is too large (max %d MB).', 'kntnt-gpx-blocks' ),
				$max_mb
			);
		}

		return $file;

	}

}
