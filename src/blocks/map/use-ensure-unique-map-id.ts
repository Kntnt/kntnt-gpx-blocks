/**
 * Hook that guarantees the current block's mapId is both non-empty and unique
 * within the post's block tree.
 *
 * On mount (and whenever the current mapId is missing or already used by
 * another Map block), a new 6-character base36 identifier is generated and
 * written back via setAttributes. The identifier follows the 'map-XXXXXX'
 * format defined in docs/blocks.md.
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
 * Ensures the calling block has a mapId that is non-empty and unique
 * across all other GPX Map blocks in the current post.
 *
 * The hook reads the full block tree via the block-editor data store to
 * detect collisions, then calls `setAttributes` once when a new ID is
 * needed. It does nothing if the current mapId is already valid and unique.
 *
 * @since 1.0.0
 *
 * @param clientId      The calling block's client ID (from `useBlockProps` /
 *                      block context).
 * @param attributes    The block's current attributes.
 * @param setAttributes Block attribute setter.
 */
export function useEnsureUniqueMapId(
	clientId: string,
	attributes: MapIdAttributes,
	setAttributes: ( attrs: Partial< MapIdAttributes > ) => void
): void {
	// Collect every mapId currently used by other GPX Map blocks in the post.
	const siblingMapIds = useSelect(
		( select ) => {
			const { getBlocks } = select( blockEditorStore );

			// Collect all mapId values from Map blocks other than this one.
			const ids: string[] = [];
			const collectIds = (
				blocks: ReturnType< typeof getBlocks >
			): void => {
				for ( const block of blocks ) {
					if (
						block.name === 'kntnt-gpx-blocks/map' &&
						block.clientId !== clientId
					) {
						const mapId = block.attributes?.mapId as
							| string
							| undefined;
						if ( mapId ) {
							ids.push( mapId );
						}
					}
					if ( block.innerBlocks?.length ) {
						collectIds( block.innerBlocks );
					}
				}
			};

			collectIds( getBlocks() );
			return ids;
		},
		[ clientId ]
	);

	const { mapId } = attributes;

	useEffect( () => {
		// Generate a new ID when the current one is absent or collides.
		const needsNew = ! mapId || siblingMapIds.includes( mapId );
		if ( ! needsNew ) {
			return;
		}

		// Generate a 6-character base36 suffix and prefix with 'map-'.
		const generated = 'map-' + Math.random().toString( 36 ).slice( 2, 8 );
		setAttributes( { mapId: generated } );
	}, [ mapId, siblingMapIds, setAttributes ] );
}
