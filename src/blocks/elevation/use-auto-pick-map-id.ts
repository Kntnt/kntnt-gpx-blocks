/**
 * Hook that pre-binds a freshly inserted GPX Elevation block to the closest
 * preceding GPX Map block in document order.
 *
 * On the first render where the block's `mapId` is still the default `'auto'`
 * (or the empty string), this hook walks the top-level block order, finds the
 * closest preceding `kntnt-gpx-blocks/map` block, reads its `mapId` attribute,
 * and writes that value into this Elevation block's `mapId`. When no Map
 * block precedes the Elevation, the attribute stays as `'auto'` so the
 * existing single-map resolution path keeps working unchanged.
 *
 * The hook is one-shot: a `useRef` guard prevents repeated writes after the
 * initial pre-bind, so subsequent edits to the Map block (or insertion of
 * additional Map blocks) do not re-trigger the auto-pick.
 *
 * @since 1.0.0
 */

import { useEffect, useRef } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';

/**
 * Shape of the block-editor `select` surface this hook reads.
 *
 * Only the two methods this hook needs are declared so the typing stays
 * narrow; the real surface exposes far more.
 *
 * @since 1.0.0
 */
interface BlockEditorSelectShape {
	getBlockOrder: ( rootClientId?: string ) => string[];
	getBlockName: ( clientId: string ) => string | undefined;
	getBlockAttributes: (
		clientId: string
	) => Record< string, unknown > | undefined;
}

/**
 * Pre-binds a freshly inserted GPX Elevation block to the closest preceding
 * GPX Map block in document order.
 *
 * Runs at most once per block instance. When the current `mapId` is `'auto'`
 * or empty, the hook resolves the closest preceding `kntnt-gpx-blocks/map`
 * at the top level of the block tree and writes its `mapId` into this
 * block's attributes. With no preceding Map, the attribute stays as
 * `'auto'`.
 *
 * @since 1.0.0
 *
 * @param clientId      The calling block's client ID.
 * @param mapId         The block's current `mapId` attribute.
 * @param setAttributes Block attribute setter.
 */
export function useAutoPickMapId(
	clientId: string,
	mapId: string,
	setAttributes: ( attrs: { mapId: string } ) => void
): void {
	// Resolve the closest preceding kntnt-gpx-blocks/map mapId at the top level.
	// Returning `null` covers both "no preceding map" and "preceding map has no
	// mapId yet" — the effect treats both as "do nothing".
	const precedingMapId = useSelect(
		( select ) => {
			const blockEditor = select(
				blockEditorStore
			) as unknown as BlockEditorSelectShape;
			const order = blockEditor.getBlockOrder();
			const selfIndex = order.indexOf( clientId );
			if ( selfIndex <= 0 ) {
				return null;
			}
			for ( let i = selfIndex - 1; i >= 0; i-- ) {
				const candidateId = order[ i ];
				if (
					blockEditor.getBlockName( candidateId ) !==
					'kntnt-gpx-blocks/map'
				) {
					continue;
				}
				const attrs = blockEditor.getBlockAttributes( candidateId );
				const precedingId = attrs?.mapId;
				if ( typeof precedingId === 'string' && precedingId !== '' ) {
					return precedingId;
				}
				return null;
			}
			return null;
		},
		[ clientId ]
	);

	// One-shot guard. The auto-pick must run exactly once per inserted block;
	// after the first write (or the first decision to skip), subsequent edits
	// to surrounding Map blocks must not rewrite this block's mapId.
	const hasPickedRef = useRef< boolean >( false );

	useEffect( () => {
		if ( hasPickedRef.current ) {
			return;
		}
		hasPickedRef.current = true;
		const isDefault = mapId === '' || mapId === 'auto';
		if ( ! isDefault ) {
			return;
		}
		if ( precedingMapId === null ) {
			return;
		}
		setAttributes( { mapId: precedingMapId } );
	}, [ mapId, precedingMapId, setAttributes ] );
}
