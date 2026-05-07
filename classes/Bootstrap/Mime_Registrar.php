<?php
/**
 * Registers the GPX MIME type with WordPress's upload allowlist and corrects
 * the filetype-detection result for .gpx uploads.
 *
 * WordPress uses finfo to detect the actual MIME type of an uploaded file.
 * Because GPX files are XML, finfo returns 'text/xml' or 'application/xml' —
 * neither of which matches the registered 'application/gpx+xml'. WordPress
 * would then reject the upload as a type mismatch. The override_check()
 * method resolves this by forcing the expected MIME type whenever the
 * filename ends with '.gpx'. See docs/security.md § "File validation" for
 * the full rationale.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Bootstrap;

/**
 * Manages GPX MIME-type registration and the finfo-override for .gpx files.
 *
 * Two filter methods are registered by Plugin::__construct():
 *   - add_gpx()       on 'upload_mimes'                   — adds the mapping.
 *   - override_check() on 'wp_check_filetype_and_ext'     — corrects finfo.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Mime_Registrar {

	/**
	 * The MIME type for GPX files as registered in the upload allowlist.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const GPX_MIME_TYPE = 'application/gpx+xml';

	/**
	 * Adds 'gpx => application/gpx+xml' to WordPress's allowed-upload MIME map.
	 *
	 * Hooked to 'upload_mimes'. Without this, WordPress refuses .gpx uploads
	 * before they even reach the server-side upload handler.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,string> $mimes Existing allowed MIME types, keyed by extension.
	 * @return array<string,string> Mimes with the gpx entry appended.
	 */
	public function add_gpx( array $mimes ): array {

		// Register the GPX vendor MIME type so WordPress permits the upload.
		$mimes['gpx'] = self::GPX_MIME_TYPE;

		return $mimes;

	}

	/**
	 * Forces the filetype result to 'application/gpx+xml' when the filename
	 * ends with '.gpx', overriding what finfo detects.
	 *
	 * Hooked to 'wp_check_filetype_and_ext'. finfo returns 'text/xml' or
	 * 'application/xml' for GPX because GPX is XML and finfo has no
	 * GPX-specific signature. WordPress would reject the upload because the
	 * detected type does not match the registered 'application/gpx+xml'. This
	 * method forces the correct result for .gpx files while leaving every
	 * other extension unchanged.
	 *
	 * The middle three parameters are nullable to match WordPress core's
	 * actual filter contract: $file and $filename are null when the filter
	 * fires from a context that has not resolved them yet (e.g. some sideload
	 * paths), and $mimes is null when the caller did not pass an explicit
	 * mimes allowlist. The previous non-nullable signature caused a fatal
	 * TypeError on every call from such contexts.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,string|bool> $data      Result from wp_check_filetype_and_ext().
	 *                                              Keys: 'ext', 'type', 'proper_filename'.
	 * @param string|null               $file       Temporary file path on disk, or null.
	 * @param string|null               $filename   Original client-supplied filename, or null.
	 * @param array<string,string>|null $mimes      Allowed MIME map, or null.
	 * @param string|false              $real_mime  MIME string returned by finfo, or false.
	 * @return array<string,string|bool> The (possibly overridden) check result.
	 */
	public function override_check(
		array $data,
		?string $file,
		?string $filename,
		?array $mimes,
		string|false $real_mime = false
	): array {

		// Cannot decide without a filename — pass through unchanged.
		if ( $filename === null ) {
			return $data;
		}

		// Only override when the original filename says this is a GPX file.
		if ( ! str_ends_with( strtolower( $filename ), '.gpx' ) ) {
			return $data;
		}

		// Force the GPX MIME type regardless of what finfo detected.
		$data['ext']              = 'gpx';
		$data['type']             = self::GPX_MIME_TYPE;
		$data['proper_filename']  = false;

		return $data;

	}

}
