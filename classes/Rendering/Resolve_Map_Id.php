<?php
/**
 * Resolves a mapId (or 'auto') to the concrete attachment ID and map ID of
 * the single GPX Map block on the post.
 *
 * Used by Render_Elevation and the `kntnt-gpx-blocks/statistics` Block
 * Bindings source to locate the upstream GPX Map when the consumer's
 * mapId is '' or 'auto', and to validate explicit mapId values. The
 * algorithm parses post_content via parse_blocks() so it works for any
 * post type and does not require the map block to be at the top level
 * of the block tree.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rendering;

/**
 * Locates a GPX Map block within the parsed block tree of a post.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Resolve_Map_Id {

	/**
	 * Resolves a mapId to the attachment ID and canonical mapId of a Map block.
	 *
	 * When $map_id is '' or 'auto', the post's block tree is searched for all
	 * GPX Map blocks that have a non-zero attachmentId. Exactly one must exist;
	 * zero or two or more are error conditions. When $map_id is an explicit
	 * string, the tree is searched for the Map whose mapId matches.
	 *
	 * Returns an array with 'attachment_id' (int) and 'map_id' (string) on
	 * success, or a Render_Error on any failure.
	 *
	 * @since 1.0.0
	 *
	 * @param string $map_id  The mapId attribute from the consumer block, or ''
	 *                        or 'auto' to auto-resolve.
	 * @param int    $post_id The post whose block tree to search.
	 *
	 * @return array{ attachment_id: int, map_id: string }|Render_Error
	 */
	public function resolve( string $map_id, int $post_id ): array|Render_Error {

		// A non-existent post can never contain a Map block.
		if ( $post_id <= 0 ) {
			return new Render_Error( 'no-map', __( 'No GPX Map block on this page.', 'kntnt-gpx-blocks' ) );
		}
		$post = get_post( $post_id );
		if ( null === $post ) {
			return new Render_Error( 'no-map', __( 'No GPX Map block on this page.', 'kntnt-gpx-blocks' ) );
		}

		// Parse the block tree and collect every configured Map block.
		// collect_maps() accepts array<mixed, mixed> and narrows via is_array()
		// guards; it is safe to pass the raw parse_blocks() return directly.
		$blocks = parse_blocks( $post->post_content );
		$maps   = $this->collect_maps( $blocks );

		// When the caller supplies '' or 'auto', the only valid state is exactly one Map.
		if ( '' === $map_id || 'auto' === $map_id ) {
			$multiple_msg = __( 'Multiple GPX Map blocks on this page. Set an explicit mapId.', 'kntnt-gpx-blocks' );
			return match ( count( $maps ) ) {
				0 => new Render_Error( 'no-map', __( 'No GPX Map block on this page.', 'kntnt-gpx-blocks' ) ),
				1 => $maps[0],
				default => new Render_Error( 'multiple-maps', $multiple_msg ),
			};
		}

		// For an explicit mapId, find the matching entry.
		foreach ( $maps as $entry ) {
			if ( $entry['map_id'] === $map_id ) {
				return $entry;
			}
		}

		$not_found_msg = __( 'No GPX Map block with the specified mapId.', 'kntnt-gpx-blocks' );
		return new Render_Error( 'map-not-found', $not_found_msg );

	}

	/**
	 * Recursively walks a parsed block tree and collects configured Map blocks.
	 *
	 * A block is considered "configured" when its attachmentId attribute is
	 * a non-zero integer. Blocks without an attachment have not been set up yet
	 * and are skipped; they cannot serve as a data source.
	 *
	 * @since 1.0.0
	 *
	 * @param array<mixed, mixed> $blocks Parsed block array from parse_blocks().
	 *
	 * @return array<int, array{ attachment_id: int, map_id: string }>
	 */
	private function collect_maps( array $blocks ): array {

		$result = [];

		foreach ( $blocks as $block ) {

			if ( ! is_array( $block ) ) {
				continue;
			}

			// Recurse into inner blocks first so map order reflects document order.
			$inner = $block['innerBlocks'] ?? null;
			if ( is_array( $inner ) && count( $inner ) > 0 ) {
				$result = array_merge( $result, $this->collect_maps( $inner ) );
			}

			// Collect this block only when it is a configured GPX Map.
			$block_name = $block['blockName'] ?? null;
			if ( ! is_string( $block_name ) || 'kntnt-gpx-blocks/map' !== $block_name ) {
				continue;
			}
			$attrs_raw     = $block['attrs'] ?? null;
			$attrs         = is_array( $attrs_raw ) ? $attrs_raw : [];
			$raw_id        = $attrs['attachmentId'] ?? null;
			$attachment_id = is_numeric( $raw_id ) ? (int) $raw_id : 0;
			if ( $attachment_id <= 0 ) {
				continue;
			}
			$raw_map_id = $attrs['mapId'] ?? null;
			$result[]   = [
				'attachment_id' => $attachment_id,
				'map_id'        => is_string( $raw_map_id ) ? $raw_map_id : '',
			];

		}

		return $result;

	}

}
