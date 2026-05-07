<?php
/**
 * WP-CLI command for manual cache regeneration.
 *
 * Usage:
 *   wp kntnt-gpx regenerate --id=42
 *   wp kntnt-gpx regenerate --all
 *
 * The `--id=N` form regenerates exactly one attachment. The `--all` form
 * iterates every attachment with MIME `application/gpx+xml` and regenerates
 * each one. Use after a deploy that bumps Cache_Version::CURRENT or to
 * force-rebuild a stale entry during support cases. See docs/caching.md
 * § "When does conversion run".
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Cli;

use Kntnt\Gpx_Blocks\Cache\Attachment_Cache;
use WP_CLI;
use WP_Query;

/**
 * Implements the `wp kntnt-gpx regenerate` command.
 *
 * The command is registered by Plugin::__construct() only when WP_CLI is
 * defined, so the file imposes no cost on web requests.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Regenerate_Command {

	/**
	 * Constructs the command with its cache collaborator.
	 *
	 * @since 1.0.0
	 *
	 * @param Attachment_Cache $cache Cache layer that owns regeneration.
	 */
	public function __construct(
		private readonly Attachment_Cache $cache,
	) {}

	/**
	 * Regenerates one or all GPX attachment caches.
	 *
	 * ## OPTIONS
	 *
	 * [--id=<id>]
	 * : Attachment ID to regenerate. Mutually exclusive with --all.
	 *
	 * [--all]
	 * : Regenerate every attachment with MIME application/gpx+xml.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kntnt-gpx regenerate --id=42
	 *     wp kntnt-gpx regenerate --all
	 *
	 * @since 1.0.0
	 *
	 * @param array<int,string>    $args       Positional arguments (unused).
	 * @param array<string,string> $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {

		// Translate the two-flag interface into the two execution modes.
		$has_id  = isset( $assoc_args['id'] );
		$has_all = ! empty( $assoc_args['all'] );

		// Reject ambiguous or empty invocations early so the user gets a clear hint.
		if ( $has_id && $has_all ) {
			WP_CLI::error( 'Pass either --id=<id> or --all, not both.' );
		}
		if ( ! $has_id && ! $has_all ) {
			WP_CLI::error( 'Pass either --id=<id> or --all.' );
		}

		// Single-attachment mode: regenerate exactly the requested ID.
		if ( $has_id ) {
			$this->regenerate_one( (int) $assoc_args['id'] );
			return;
		}

		// Bulk mode: walk every GPX attachment in the media library.
		$this->regenerate_all();

	}

	/**
	 * Regenerates exactly one attachment and reports the outcome.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Target attachment.
	 */
	private function regenerate_one( int $attachment_id ): void {

		// Reject IDs that don't exist or aren't attachments before doing the work.
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			WP_CLI::error( sprintf( 'Attachment %d not found.', $attachment_id ) );
		}

		WP_CLI::log( sprintf( 'Regenerating attachment %d…', $attachment_id ) );
		$this->cache->regenerate( $attachment_id );
		WP_CLI::success( sprintf( 'Regenerated attachment %d.', $attachment_id ) );

	}

	/**
	 * Iterates every GPX attachment and regenerates each.
	 *
	 * Uses WP_Query rather than get_posts() so the result is paginated by
	 * post_id and the memory footprint stays flat regardless of media-library
	 * size. The loop drives the same Attachment_Cache::regenerate() call as
	 * the single-id form, so error handling is centralised in the cache layer.
	 *
	 * @since 1.0.0
	 */
	private function regenerate_all(): void {

		// Collect every GPX attachment in one pass; the IDs are small even for
		// large media libraries, so a posts_per_page=-1 fetch is appropriate.
		$query = new WP_Query(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'application/gpx+xml',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		// WP_Query with fields=ids returns a list of post IDs (int|WP_Post); cast each one for safety.
		$ids = $query->posts;

		// Bail with a friendly message rather than an error code when the library is empty.
		if ( empty( $ids ) ) {
			WP_CLI::log( 'No GPX attachments found.' );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d GPX attachment(s).', count( $ids ) ) );

		// Process each attachment; the cache layer logs per-attachment failures.
		foreach ( $ids as $id ) {
			$attachment_id = is_object( $id ) ? (int) $id->ID : (int) $id;
			WP_CLI::log( sprintf( '  Regenerating %d…', $attachment_id ) );
			$this->cache->regenerate( $attachment_id );
		}

		WP_CLI::success( sprintf( 'Processed %d attachment(s).', count( $ids ) ) );

	}

}
