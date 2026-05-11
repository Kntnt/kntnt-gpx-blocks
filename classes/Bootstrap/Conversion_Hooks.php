<?php
/**
 * Wires attachment lifecycle actions to Cache\Attachment_Cache.
 *
 * Two callbacks run during the WordPress upload lifecycle:
 *   - `add_attachment` fires on every new media item; the callback inspects
 *     the MIME type and triggers conversion when the upload is a `.gpx`.
 *   - `attachment_updated` fires when post-meta changes for an existing
 *     attachment (typically when a plugin like Enable Media Replace swaps
 *     the underlying file). The callback skips work when the on-disk MD5
 *     still matches the cached source hash, so metadata-only updates do not
 *     pay the parsing cost.
 *
 * See docs/caching.md § "When does conversion run" for the full lifecycle.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Bootstrap;

use Kntnt\Gpx_Blocks\Cache\Attachment_Cache;

/**
 * Hook adapter that decides when to call Attachment_Cache::regenerate().
 *
 * Two action callbacks are registered by Plugin::__construct():
 *   - on_added()   on 'add_attachment'      — every new attachment.
 *   - on_updated() on 'attachment_updated'  — post-meta updates only.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Conversion_Hooks {

	/**
	 * Constructs the hook adapter with its cache collaborator.
	 *
	 * @since 1.0.0
	 *
	 * @param Attachment_Cache $cache Cache layer that owns regeneration.
	 */
	public function __construct(
		private readonly Attachment_Cache $cache,
	) {}

	/**
	 * Triggers conversion when a newly uploaded attachment is a GPX file.
	 *
	 * Hooked to 'add_attachment'. Non-GPX attachments are ignored. The MIME
	 * check is paired with a filename suffix check so a file uploaded before
	 * Mime_Registrar's `application/gpx+xml` registration takes effect (e.g.
	 * during plugin activation) is still picked up.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Newly created attachment post ID.
	 */
	public function on_added( int $attachment_id ): void {

		if ( ! $this->is_gpx_attachment( $attachment_id ) ) {
			return;
		}

		$this->cache->regenerate( $attachment_id );

	}

	/**
	 * Triggers conversion when an existing GPX attachment's file has changed.
	 *
	 * Hooked to 'attachment_updated'. WordPress fires this for any post-meta
	 * change on an attachment, so most invocations are unrelated to the file
	 * itself. The handler short-circuits when the on-disk MD5 still matches
	 * the cached `_kntnt_gpx_blocks_source_hash`, leaving the cache untouched
	 * for metadata-only edits.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment post ID.
	 */
	public function on_updated( int $attachment_id ): void {

		// Ignore non-GPX attachments early.
		if ( ! $this->is_gpx_attachment( $attachment_id ) ) {
			return;
		}

		// Skip when the file's bytes have not changed since the last conversion.
		if ( ! $this->file_hash_differs( $attachment_id ) ) {
			return;
		}

		$this->cache->regenerate( $attachment_id );

	}

	/**
	 * Returns true when the attachment looks like a GPX file by MIME or suffix.
	 *
	 * The MIME check covers normal uploads going through Mime_Registrar; the
	 * suffix check covers attachments imported through paths that don't update
	 * `post_mime_type` (CLI tools, S3 importers, etc.).
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment post ID.
	 *
	 * @return bool
	 */
	private function is_gpx_attachment( int $attachment_id ): bool {

		// MIME check first — cheaper than touching the filesystem.
		$mime = get_post_mime_type( $attachment_id );
		if ( is_string( $mime ) && Mime_Registrar::GPX_MIME_TYPE === $mime ) {
			return true;
		}

		// Filename suffix as the fallback — covers attachments whose MIME slot
		// is missing or set to a generic XML type by a third-party importer.
		$file = get_attached_file( $attachment_id );

		return Mime_Registrar::is_gpx_filename( is_string( $file ) ? $file : null );

	}

	/**
	 * Returns true when the on-disk file MD5 differs from the stored hash.
	 *
	 * Treats a missing file or unreadable path as "differs": the cache layer
	 * must be allowed to record a 'file-missing' error rather than silently
	 * keeping a stale payload.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment post ID.
	 *
	 * @return bool
	 */
	private function file_hash_differs( int $attachment_id ): bool {

		// Missing file: regenerate so the cache layer surfaces 'file-missing'.
		$file = get_attached_file( $attachment_id );
		if ( ! is_string( $file ) || '' === $file || ! is_file( $file ) ) {
			return true;
		}

		$current = md5_file( $file );
		if ( false === $current ) {
			return true;
		}

		// Compare against the stored hash; an absent hash counts as "differs".
		$stored = get_post_meta( $attachment_id, '_kntnt_gpx_blocks_source_hash', true );

		return ! is_string( $stored ) || $stored !== $current;

	}

}
