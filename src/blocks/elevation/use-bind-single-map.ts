/**
 * Hook that keeps the Elevation block's `mapId` aligned with the single
 * configured Map block on the page.
 *
 * When exactly one `kntnt-gpx-blocks/map` block on the page has a non-zero
 * `attachmentId`, the picker is hidden and the user has no way to choose a
 * different value. The hook then writes that map's `mapId` into this block's
 * attributes whenever the two diverge. With zero or two-plus configured maps
 * the hook is a no-op — the picker (or the default `'auto'` resolution path)
 * owns the binding.
 *
 * @since 1.0.0
 */

import { useEffect } from '@wordpress/element';
import type { EditorBlock } from './use-map-blocks';

/**
 * Auto-bind the Elevation block to the single configured Map block on the page.
 *
 * The check is symmetric: a write fires only when the current `mapId`
 * would resolve differently from the single map's id. With more than one
 * configured map the picker is visible and the user owns the choice; with
 * none, the binding stays as `'auto'` and the existing single-map fallback
 * keeps working unchanged.
 *
 * @since 1.0.0
 *
 * @param configuredMapBlocks Configured map blocks on the page, in document order.
 * @param currentMapId        The block's current `mapId` attribute.
 * @param setAttributes       Block attribute setter.
 */
export function useBindSingleMap(
	configuredMapBlocks: readonly EditorBlock[],
	currentMapId: string,
	setAttributes: ( attrs: { mapId: string } ) => void
): void {
	// Resolve the single configured map's id, or the empty string when the
	// pre-condition (exactly one configured map) does not hold.
	const singleMapId =
		configuredMapBlocks.length === 1
			? ( configuredMapBlocks[ 0 ].attributes.mapId as
					| string
					| undefined ) ?? ''
			: '';

	useEffect( () => {
		if ( configuredMapBlocks.length !== 1 ) {
			return;
		}
		if ( singleMapId === '' ) {
			return;
		}
		if ( currentMapId === singleMapId ) {
			return;
		}
		setAttributes( { mapId: singleMapId } );
	}, [
		configuredMapBlocks.length,
		singleMapId,
		currentMapId,
		setAttributes,
	] );
}
