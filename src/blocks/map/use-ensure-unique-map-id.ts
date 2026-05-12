/**
 * Hook that guarantees the current block's mapId is both non-empty and unique
 * within the post's block tree.
 *
 * Regenerates the mapId in two cases:
 *
 *   1. The current mapId is empty (a fresh block insertion). The hook
 *      writes a new 6-character base36 identifier in the `'map-XXXXXX'`
 *      format documented in `docs/blocks.md`.
 *   2. Another Map block at an **earlier position in pre-order document
 *      traversal** already carries this mapId. The duplicate (the later
 *      block in the pair) regenerates; the original (the earlier block)
 *      keeps its mapId. This is what lets Elevation blocks bound to the
 *      original survive a duplication: only the new duplicate gets a
 *      fresh mapId, never the original a downstream consumer might be
 *      bound to. An earlier iteration of this hook checked "any sibling
 *      has the same mapId, regardless of position", which made *both*
 *      blocks regenerate on duplicate and broke every Elevation binding
 *      to the original. The earlier-collision rule restores the natural
 *      expectation that duplicating a Map preserves the original's id.
 *
 * @since 1.0.0
 */

import { useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';

/**
 * Attributes subset this hook cares about.
 *
 * @since 1.0.0
 */
interface MapIdAttributes {
	mapId: string;
	[ key: string ]: unknown;
}

/**
 * Shape of a block-tree node as `getBlocks()` returns it.
 *
 * Only the fields the walk reads are declared; the live editor exposes
 * far more.
 *
 * @since 1.0.0
 */
interface BlockTreeNode {
	readonly clientId: string;
	readonly name: string;
	readonly attributes?: {
		readonly mapId?: string;
		readonly [ key: string ]: unknown;
	};
	readonly innerBlocks?: readonly BlockTreeNode[];
}

/**
 * Walks the block tree in pre-order document traversal, looking for a
 * Map block (other than `selfClientId`) that already carries
 * `targetMapId`. Stops as soon as it either passes the calling block
 * (no earlier collision possible after that point) or finds a match.
 *
 * Exported so the hook's unit tests can drive the walk directly against
 * a constructed block tree without standing up the `useSelect` shim.
 *
 * @since 1.0.0
 *
 * @param blocks       Block tree (top-level from `getBlocks()`).
 * @param selfClientId The calling block's client id.
 * @param targetMapId  The mapId to search for. An empty string is
 *                     never a meaningful collision (the calling block
 *                     handles the empty case via its own `! mapId`
 *                     branch), so the function returns `false`
 *                     immediately.
 * @return Whether an earlier Map block in document order shares
 *         `targetMapId`.
 */
export function findEarlierMapIdCollision(
	blocks: readonly BlockTreeNode[],
	selfClientId: string,
	targetMapId: string
): boolean {
	if ( targetMapId === '' ) {
		return false;
	}

	let collision = false;
	const walk = ( nodes: readonly BlockTreeNode[] ): boolean => {
		for ( const node of nodes ) {
			if ( node.clientId === selfClientId ) {
				return true;
			}
			if ( node.name === 'kntnt-gpx-blocks/map' ) {
				const otherMapId = node.attributes?.mapId;
				if (
					typeof otherMapId === 'string' &&
					otherMapId === targetMapId
				) {
					collision = true;
					return true;
				}
			}
			if ( node.innerBlocks && node.innerBlocks.length > 0 ) {
				if ( walk( node.innerBlocks ) ) {
					return true;
				}
			}
		}
		return false;
	};
	walk( blocks );
	return collision;
}

/**
 * Ensures the calling block has a mapId that is non-empty and does not
 * collide with any **earlier** Map block in the post's block tree.
 *
 * The hook reads the full block tree via the block-editor data store to
 * detect earlier collisions, then calls `setAttributes` once when a new
 * id is needed. It does nothing if the current mapId is already
 * non-empty and either unique or the duplicate-of-a-later block.
 *
 * @since 1.0.0
 *
 * @param clientId      The calling block's client ID.
 * @param attributes    The block's current attributes.
 * @param setAttributes Block attribute setter.
 */
export function useEnsureUniqueMapId(
	clientId: string,
	attributes: MapIdAttributes,
	setAttributes: ( attrs: Partial< MapIdAttributes > ) => void
): void {
	const { mapId } = attributes;

	const earlierCollision = useSelect(
		( select ) => {
			const { getBlocks } = select( blockEditorStore ) as unknown as {
				getBlocks: () => readonly BlockTreeNode[];
			};
			return findEarlierMapIdCollision( getBlocks(), clientId, mapId );
		},
		[ clientId, mapId ]
	);

	useEffect( () => {
		// Generate a new id when the current one is absent or an earlier
		// Map block in document order claims the same value. The
		// position check is what preserves the original's id during a
		// duplication — the original sees itself as the earliest holder
		// of its mapId and does not regenerate.
		const needsNew = ! mapId || earlierCollision;
		if ( ! needsNew ) {
			return;
		}

		const generated = 'map-' + Math.random().toString( 36 ).slice( 2, 8 );
		setAttributes( { mapId: generated } );
	}, [ mapId, earlierCollision, setAttributes ] );
}
