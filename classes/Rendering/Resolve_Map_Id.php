<?php
/**
 * Resolves a mapId (or 'auto') to the concrete attachment ID and map ID of
 * the single GPX Map block on the post.
 *
 * Used by Render_Elevation and the `[kntnt-gpx <key>]` shortcode handler
 * (Bindings\Statistics_Shortcode) to locate the upstream GPX Map when the
 * consumer's mapId is '' or 'auto', and to validate explicit mapId values. The
 * algorithm walks a parsed block tree so it works for any post type and
 * does not require the map block to be at the top level. Two public
 * entry points are exposed: `resolve()` looks the tree up by post ID
 * (the frontend path), `resolve_from_blocks()` accepts an already-parsed
 * tree (the editor path, where the live block tree from the editor is
 * the source of truth — see `docs/architecture.md`).
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rendering;

/**
 * Locates a GPX Map block within a parsed block tree.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Resolve_Map_Id {

	/**
	 * Resolves a mapId by parsing the post's saved content.
	 *
	 * Thin facade that retrieves the post, runs `parse_blocks()` over its
	 * content, and delegates to `resolve_from_blocks()`. Used on the frontend,
	 * where saved content is the only available source of truth. Returns a
	 * `Render_Error` with code `'no-map'` for non-existent posts so the editor
	 * gets a clear notice and the frontend renders nothing for visitors.
	 *
	 * @since 1.0.0
	 *
	 * @param string $map_id  Map ID from the consumer block, or `''` / `'auto'`
	 *                        to auto-resolve.
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

		// Delegate the search itself to the block-tree-aware variant so both
		// the frontend (saved content) and the editor (live snapshot) follow
		// exactly the same matching logic.
		return $this->resolve_from_blocks( $map_id, parse_blocks( $post->post_content ) );

	}

	/**
	 * Resolves a mapId against an already-parsed block tree.
	 *
	 * The editor path uses this directly with a snapshot of the current React
	 * block tree (forwarded over the SSR REST request), since the editor's
	 * live state can diverge from saved post content while the user is still
	 * editing. The frontend path reaches the same logic via `resolve()`.
	 *
	 * Recurses into innerBlocks so a Map nested inside a `core/group` (or any
	 * other container) is found at any depth. Auto-resolution requires exactly
	 * one configured Map; zero and ≥ 2 are explicit error states with distinct
	 * codes so the consumer can render a precise message.
	 *
	 * @since 1.0.0
	 *
	 * @param string              $map_id Map ID, or `''` / `'auto'` to auto-resolve.
	 * @param array<mixed, mixed> $blocks Parsed block tree (from `parse_blocks()`
	 *                                    on the frontend, or the editor snapshot
	 *                                    in the same shape).
	 *
	 * @return array{ attachment_id: int, map_id: string }|Render_Error
	 */
	public function resolve_from_blocks( string $map_id, array $blocks ): array|Render_Error {

		$maps = $this->collect_maps( $blocks );

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
