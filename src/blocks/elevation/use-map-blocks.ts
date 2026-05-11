/**
 * Hook that surfaces the GPX Map blocks on the page for the Elevation block's
 * data-source picker.
 *
 * Walks the editor's block tree, collects every `kntnt-gpx-blocks/map` block at
 * any nesting depth, and returns the configured subset (those with a non-zero
 * `attachmentId`) alongside ready-to-render `<SelectControl>` option entries.
 * The hook also exposes the snapshot that the Elevation block forwards to
 * `ServerSideRender` so the PHP renderer can resolve "which map" without
 * reading the last-saved `post_content` — see `ElevationEdit` for why that
 * snapshot is necessary.
 *
 * @since 1.0.0
 */

import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';

/**
 * Shape of a parsed Gutenberg block returned by `getBlocks()`.
 *
 * Only the fields the Elevation edit component reads are declared.
 *
 * @since 1.0.0
 */
export interface EditorBlock {
	name: string;
	attributes: {
		attachmentId?: number;
		mapId?: string;
		[ key: string ]: unknown;
	};
	innerBlocks: EditorBlock[];
}

/**
 * One `<SelectControl>` option entry for a configured GPX Map block.
 *
 * @since 1.0.0
 */
export interface MapPickerOption {
	readonly label: string;
	readonly value: string;
}

/**
 * Snapshot entry forwarded to `Render_Elevation` via the ServerSideRender
 * `__editorBlockSnapshot` attribute. Mirrors the shape `parse_blocks()`
 * emits on the server so `Resolve_Map_Id::resolve_from_blocks()` consumes
 * the snapshot without translation.
 *
 * @since 1.0.0
 */
export interface EditorBlockSnapshotEntry {
	readonly blockName: 'kntnt-gpx-blocks/map';
	readonly attrs: {
		readonly mapId: string;
		readonly attachmentId: number;
	};
	readonly innerBlocks: readonly never[];
}

/**
 * Aggregate result of `useMapBlocks`.
 *
 * @since 1.0.0
 */
export interface UseMapBlocksResult {
	/** All `kntnt-gpx-blocks/map` blocks on the page, in document order. */
	readonly mapBlocks: readonly EditorBlock[];
	/** Subset of `mapBlocks` whose `attachmentId > 0`. */
	readonly configuredMapBlocks: readonly EditorBlock[];
	/** Ready-to-render picker option entries — one per configured map. */
	readonly mapOptions: readonly MapPickerOption[];
	/** Snapshot in the shape `Resolve_Map_Id::resolve_from_blocks()` accepts. */
	readonly editorBlockSnapshot: readonly EditorBlockSnapshotEntry[];
}

/**
 * Recursively collects all GPX Map blocks from a block tree.
 *
 * Walks the entire tree (including innerBlocks at every depth) so the picker
 * finds maps inside groups, columns, or other container blocks.
 *
 * @since 1.0.0
 *
 * @param blocks Flat or nested block array from `getBlocks()`.
 * @return All blocks whose name is `'kntnt-gpx-blocks/map'`.
 */
export function collectMapBlocks( blocks: EditorBlock[] ): EditorBlock[] {
	const result: EditorBlock[] = [];

	for ( const block of blocks ) {
		// Recurse first so document order is preserved when maps are nested.
		if ( block.innerBlocks.length > 0 ) {
			result.push( ...collectMapBlocks( block.innerBlocks ) );
		}
		if ( block.name === 'kntnt-gpx-blocks/map' ) {
			result.push( block );
		}
	}

	return result;
}

/**
 * Reads every GPX Map block on the page and derives the picker option list
 * plus the ServerSideRender snapshot from it.
 *
 * The hook subscribes to `core/block-editor` and `core` data stores so the
 * picker, the snapshot, and any consumer of `configuredMapBlocks` re-render
 * whenever a Map block is added, removed, configured, or has its attachment
 * media object resolved by `core-data`.
 *
 * @since 1.0.0
 *
 * @return Aggregate result; see {@link UseMapBlocksResult}.
 */
export function useMapBlocks(): UseMapBlocksResult {
	// Collect every block on the page so the recursive walk can find maps
	// nested inside groups, columns, or any other container.
	const allBlocks = useSelect( ( select ) => {
		const { getBlocks } = select( 'core/block-editor' ) as {
			getBlocks: () => EditorBlock[];
		};
		return getBlocks();
	}, [] );

	// Surface the `core-data` media resolver so the picker can show each
	// attachment's filename slug instead of a bare attachment ID.
	const getMedia = useSelect( ( select ) => {
		const { getMedia: coreGetMedia } = select( coreStore ) as ReturnType<
			typeof select
		>;
		return coreGetMedia as (
			id: number
		) => { source_url?: string; slug?: string } | undefined;
	}, [] );

	// Narrow to the configured subset; picker entries and the SSR snapshot
	// both consume only configured maps.
	const mapBlocks = collectMapBlocks( allBlocks );
	const configuredMapBlocks = mapBlocks.filter(
		( b ) => ( b.attributes.attachmentId ?? 0 ) > 0
	);

	// Build one picker option per configured map. The label combines a
	// sequential index with the media slug so each entry is identifiable
	// even when several maps share the same attachment.
	const mapOptions: MapPickerOption[] = configuredMapBlocks.map(
		( b, index ) => {
			const attachmentId = b.attributes.attachmentId as number;
			const blockMapId = b.attributes.mapId as string | undefined;
			const media = getMedia( attachmentId );
			const filename = media?.slug ?? String( attachmentId );
			const label =
				__( 'Karta', 'kntnt-gpx-blocks' ) +
				` ${ index + 1 }: ${ filename }`;
			return { label, value: blockMapId ?? '' };
		}
	);

	// Build the snapshot the editor preview forwards to ServerSideRender so
	// PHP's `Resolve_Map_Id::resolve_from_blocks()` resolves the right map
	// without reading the last-saved `post_content`.
	const editorBlockSnapshot: EditorBlockSnapshotEntry[] =
		configuredMapBlocks.map( ( b ) => ( {
			blockName: 'kntnt-gpx-blocks/map',
			attrs: {
				mapId: ( b.attributes.mapId as string | undefined ) ?? '',
				attachmentId: b.attributes.attachmentId as number,
			},
			innerBlocks: [],
		} ) );

	return { mapBlocks, configuredMapBlocks, mapOptions, editorBlockSnapshot };
}
