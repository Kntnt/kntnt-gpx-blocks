/**
 * Hook that walks the editor's block tree and surfaces the GPX Map
 * blocks the Elevation block can bind to.
 *
 * Returns three derived views of the page's Map blocks:
 *
 *   - `mapBlocks`           — every `kntnt-gpx-blocks/map` block on the
 *                              page, in pre-order document traversal.
 *                              Includes unconfigured Maps because the
 *                              `GPX Map #N` fallback index counts *all*
 *                              of them (Step 2 spec, tier 3 of the
 *                              picker-label rule).
 *   - `configuredMapBlocks` — the subset with `attachmentId > 0` AND a
 *                              non-empty `mapId`. The mapId-emptiness
 *                              gate matches the derived rule in
 *                              `docs/elevation-rebuild.md` Step 2: a
 *                              Map block whose `useEnsureUniqueMapId`
 *                              effect has not yet fired is invisible
 *                              to Elevation in every counting and
 *                              selection context.
 *   - `mapOptions`          — one `SelectControl` option per entry in
 *                              `configuredMapBlocks`, with the label
 *                              resolved through the three-tier rule in
 *                              `picker-label.ts` and the value being
 *                              the block's own `mapId`.
 *
 * Recursive walk: a Map block nested inside `core/group`, `core/columns`,
 * or any other container is found at any depth, matching the server-side
 * `Resolve_Map_Id::collect_maps()` recursion so editor and frontend
 * eligibility rules cannot diverge.
 *
 * @since 1.0.0
 */

import { useSelect } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';

import { pickerLabel, type PickerLabelAttributes } from './picker-label';

/**
 * Shape of a parsed Gutenberg block returned by `getBlocks()`. Only the
 * fields the hook reads are declared; the live editor exposes more.
 *
 * @since 1.0.0
 */
export interface EditorBlock {
	readonly name: string;
	readonly clientId: string;
	readonly attributes: {
		readonly attachmentId?: number;
		readonly mapId?: string;
		readonly metadata?: { readonly name?: string };
		readonly anchor?: string;
		readonly [ key: string ]: unknown;
	};
	readonly innerBlocks: readonly EditorBlock[];
}

/**
 * One `SelectControl` option entry for a configured GPX Map block.
 *
 * @since 1.0.0
 */
export interface MapPickerOption {
	readonly label: string;
	readonly value: string;
}

/**
 * Aggregate result of {@link useMapBlocks}.
 *
 * @since 1.0.0
 */
export interface UseMapBlocksResult {
	/** All Map blocks on the page in pre-order document traversal. */
	readonly mapBlocks: readonly EditorBlock[];
	/** Subset with `attachmentId > 0` AND `mapId !== ''`. */
	readonly configuredMapBlocks: readonly EditorBlock[];
	/** Ready-to-render picker options for `configuredMapBlocks`. */
	readonly mapOptions: readonly MapPickerOption[];
}

/**
 * Block-editor `select` surface this hook reads. Narrowed to the one
 * method actually consumed.
 *
 * @since 1.0.0
 */
interface BlockEditorSelectShape {
	getBlocks: () => EditorBlock[];
}

/**
 * Recursively collects every GPX Map block from a block tree in
 * pre-order document traversal.
 *
 * Exported so tests can exercise the walk without going through the
 * `useSelect` indirection.
 *
 * @since 1.0.0
 *
 * @param blocks Flat or nested block array from `getBlocks()`.
 * @return All GPX Map blocks, configured or not, in document order.
 */
export function collectMapBlocks(
	blocks: readonly EditorBlock[]
): EditorBlock[] {
	const result: EditorBlock[] = [];

	for ( const block of blocks ) {
		if ( block.name === 'kntnt-gpx-blocks/map' ) {
			result.push( block );
		}
		if ( block.innerBlocks.length > 0 ) {
			result.push( ...collectMapBlocks( block.innerBlocks ) );
		}
	}

	return result;
}

/**
 * Returns `true` when a Map block counts as configured for binding.
 *
 * Mirrors the derived eligibility rule fixed in Step 2: both the
 * `attachmentId` and the `mapId` must be present, because a Map whose
 * `useEnsureUniqueMapId` effect has not yet completed has an empty
 * `mapId` that the resolver cannot use.
 *
 * @since 1.0.0
 *
 * @param block A Map block from the editor block tree.
 * @return Whether the block is eligible to feed an Elevation block.
 */
export function isConfigured( block: EditorBlock ): boolean {
	const attachmentId = block.attributes.attachmentId;
	const mapId = block.attributes.mapId;

	return (
		typeof attachmentId === 'number' &&
		attachmentId > 0 &&
		typeof mapId === 'string' &&
		mapId !== ''
	);
}

/**
 * Derives the three views from a flat list of Map blocks.
 *
 * Exported so tests can drive the derivation directly with a constructed
 * block tree, bypassing the `useSelect` wiring.
 *
 * @since 1.0.0
 *
 * @param allBlocks Full block tree (top-level only — recursion happens
 *                  inside).
 * @return The {@link UseMapBlocksResult} bundle.
 */
export function deriveMapBlocks(
	allBlocks: readonly EditorBlock[]
): UseMapBlocksResult {
	const mapBlocks = collectMapBlocks( allBlocks );
	const configuredMapBlocks = mapBlocks.filter( isConfigured );

	// Build the picker options. The index passed into `pickerLabel` is the
	// 1-based position of *this* block in `mapBlocks` (the list of ALL Map
	// blocks), not in `configuredMapBlocks` — see the Step 2 spec for the
	// rationale.
	const mapOptions: MapPickerOption[] = configuredMapBlocks.map(
		( block ) => {
			const indexInAll = mapBlocks.indexOf( block ) + 1;
			const attrs: PickerLabelAttributes = {
				metadata: block.attributes.metadata,
				anchor: block.attributes.anchor,
			};
			return {
				label: pickerLabel( attrs, indexInAll ),
				value: block.attributes.mapId as string,
			};
		}
	);

	return { mapBlocks, configuredMapBlocks, mapOptions };
}

/**
 * Reads the editor's block tree via the block-editor data store and
 * returns the three derived views described above.
 *
 * Subscribes through `useSelect` so the picker, the snapshot consumers,
 * and any auto-pick effect re-render whenever a Map block is added,
 * removed, configured, or has its `mapId` set.
 *
 * @since 1.0.0
 *
 * @return Aggregate result; see {@link UseMapBlocksResult}.
 */
export function useMapBlocks(): UseMapBlocksResult {
	const allBlocks = useSelect( ( select ) => {
		const editor = select(
			blockEditorStore
		) as unknown as BlockEditorSelectShape;
		return editor.getBlocks();
	}, [] );

	return deriveMapBlocks( allBlocks );
}
