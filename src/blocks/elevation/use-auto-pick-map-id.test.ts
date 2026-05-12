/**
 * Unit tests for {@link useAutoPickMapId} and {@link isAutoMapId}.
 *
 * The hook is a `useEffect` that writes the topmost configured Map's
 * `mapId` into the Elevation block's attribute whenever the current
 * value is empty or `"auto"`. The test harness swaps `useEffect` for
 * an immediate invocation so the effect body runs synchronously inside
 * the test.
 *
 * Coverage:
 *
 * - Empty / `"auto"` triggers the pick when ≥ 1 configured Map exists.
 * - The 0-then-add sequence (no Maps → effect skips; Map appears →
 *   effect fires on the next render) — re-fire-until-successful.
 * - Stickiness: once `mapId` is set, the effect is a no-op forever.
 * - A configured Map with an empty `mapId` (the one-render window) is
 *   not picked.
 * - Non-trivial `mapId` values starting with `"auto"` literal substring
 *   (e.g. `"auto-1"`) are NOT treated as auto.
 *
 * @since 1.0.0
 */

jest.mock(
	'@wordpress/element',
	() => ( {
		__esModule: true,
		useEffect: ( fn: () => void | ( () => void ) ) => fn(),
	} ),
	{ virtual: true }
);

import { isAutoMapId, useAutoPickMapId } from './use-auto-pick-map-id';
import type { EditorBlock } from './use-map-blocks';

function mkMapBlock(
	clientId: string,
	attachmentId: number,
	mapId: string
): EditorBlock {
	return {
		name: 'kntnt-gpx-blocks/map',
		clientId,
		attributes: { attachmentId, mapId },
		innerBlocks: [],
	};
}

describe( 'isAutoMapId', () => {
	it.each( [
		[ '', true ],
		[ 'auto', true ],
		[ 'map-abc', false ],
		[ 'auto-1', false ],
		[ ' auto', false ],
	] )( 'returns %p for input %p', ( input, expected ) => {
		expect( isAutoMapId( input as string ) ).toBe( expected );
	} );
} );

describe( 'useAutoPickMapId', () => {
	it( 'does nothing when no configured Map blocks exist', () => {
		const setAttributes = jest.fn();
		useAutoPickMapId( '', [], setAttributes );
		expect( setAttributes ).not.toHaveBeenCalled();
	} );

	it( "writes the topmost configured Map's mapId when current is empty", () => {
		const setAttributes = jest.fn();
		useAutoPickMapId(
			'',
			[
				mkMapBlock( 'm1', 42, 'map-aaa' ),
				mkMapBlock( 'm2', 7, 'map-bbb' ),
			],
			setAttributes
		);
		expect( setAttributes ).toHaveBeenCalledWith( { mapId: 'map-aaa' } );
	} );

	it( 'writes when current is the literal "auto" sentinel', () => {
		const setAttributes = jest.fn();
		useAutoPickMapId(
			'auto',
			[ mkMapBlock( 'm1', 42, 'map-aaa' ) ],
			setAttributes
		);
		expect( setAttributes ).toHaveBeenCalledWith( { mapId: 'map-aaa' } );
	} );

	it( 'is a no-op once mapId is set (stickiness)', () => {
		const setAttributes = jest.fn();
		useAutoPickMapId(
			'map-explicit',
			[
				mkMapBlock( 'm1', 42, 'map-aaa' ),
				mkMapBlock( 'm2', 7, 'map-bbb' ),
			],
			setAttributes
		);
		expect( setAttributes ).not.toHaveBeenCalled();
	} );

	it( 'fires on the second call when a Map becomes available later', () => {
		const setAttributes = jest.fn();
		// First render: no configured Maps yet → no write.
		useAutoPickMapId( 'auto', [], setAttributes );
		expect( setAttributes ).not.toHaveBeenCalled();
		// Second render: a Map appears on the page → the effect fires.
		useAutoPickMapId(
			'auto',
			[ mkMapBlock( 'm1', 99, 'map-late' ) ],
			setAttributes
		);
		expect( setAttributes ).toHaveBeenCalledWith( { mapId: 'map-late' } );
	} );

	it( 'skips Map blocks whose mapId has not been generated yet', () => {
		const setAttributes = jest.fn();
		useAutoPickMapId(
			'',
			// `mapId === ''` here is meant to represent a configured Map
			// whose `useEnsureUniqueMapId` effect has not yet completed.
			// Real consumers filter such entries out via `isConfigured`,
			// but the hook applies its own defence-in-depth check.
			[ mkMapBlock( 'm1', 99, '' ) ],
			setAttributes
		);
		expect( setAttributes ).not.toHaveBeenCalled();
	} );
} );
