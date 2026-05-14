/**
 * Hook that auto-picks the topmost configured GPX Map block as the
 * Elevation block's binding source.
 *
 * Behaviour:
 *
 *   - The effect runs on every render where the current `mapId` is
 *     empty or the literal sentinel `"auto"`.
 *   - When at least one **configured** GPX Map block exists on the
 *     page (in the form returned by `useMapBlocks().configuredMapBlocks`),
 *     the effect writes that map's `mapId` into the Elevation block's
 *     attribute. Otherwise the effect does nothing this render — and
 *     keeps re-firing on subsequent renders until a candidate becomes
 *     available.
 *   - Stickiness comes for free: as soon as `mapId` is non-empty and
 *     non-`"auto"`, the guard fails and the effect stops writing. No
 *     `useRef` one-shot flag is needed; the live attribute value is
 *     the guard.
 *
 * The re-fire-until-successful behaviour is what lets the user insert
 * Elevation on a page with no Maps yet, then *later* configure a Map,
 * and have the binding land at the moment the candidate becomes
 * available — without requiring any user action.
 *
 * Stickiness preserves the user's choice: deleting the bound Map after
 * the auto-pick has fired puts the block into the broken-binding state
 * (the warning placeholder), it does not silently re-resolve to a
 * different Map.
 *
 * @since 1.0.0
 */

import { useEffect } from '@wordpress/element';

import type { EditorBlock } from './use-map-blocks';

/**
 * Returns `true` when the `mapId` attribute is in its empty / sentinel
 * state — the precondition for the auto-pick effect to fire.
 *
 * Exported so the inspector logic ("show the Data Source panel when the
 * binding is broken") can share the same emptiness predicate without
 * importing the hook itself.
 *
 * @since 1.0.0
 *
 * @param mapId The current `mapId` attribute value.
 * @return Whether the attribute is empty or the literal `"auto"`.
 */
export function isAutoMapId( mapId: string ): boolean {
	return mapId === '' || mapId === 'auto';
}

/**
 * Auto-pick effect for the Elevation block's `mapId` attribute.
 *
 * Writes the topmost configured Map's `mapId` into the Elevation block
 * on every render where the current attribute is empty or `"auto"` and
 * at least one configured Map is on the page. Does nothing once the
 * attribute is set.
 *
 * @since 1.0.0
 *
 * @param mapId               Current value of the Elevation block's
 *                            `mapId` attribute.
 * @param configuredMapBlocks Configured Map blocks on the page, in
 *                            pre-order document traversal (from
 *                            `useMapBlocks().configuredMapBlocks`).
 * @param setAttributes       Block attribute setter from the Edit
 *                            component's props.
 */
export function useAutoPickMapId(
	mapId: string,
	configuredMapBlocks: readonly EditorBlock[],
	setAttributes: ( attrs: { mapId: string } ) => void
): void {
	useEffect( () => {
		if ( ! isAutoMapId( mapId ) ) {
			return;
		}
		if ( configuredMapBlocks.length === 0 ) {
			return;
		}
		const candidate = configuredMapBlocks[ 0 ];
		const candidateMapId = candidate.attributes.mapId;
		if ( typeof candidateMapId !== 'string' || candidateMapId === '' ) {
			return;
		}
		setAttributes( { mapId: candidateMapId } );
	}, [ mapId, configuredMapBlocks, setAttributes ] );
}
