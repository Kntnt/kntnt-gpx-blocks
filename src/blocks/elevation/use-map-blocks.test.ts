/**
 * Unit tests for {@link deriveMapBlocks} and {@link collectMapBlocks}.
 *
 * The pure derivation surface — `collectMapBlocks`, `isConfigured`, and
 * `deriveMapBlocks` — is exercised directly with constructed block trees,
 * bypassing the `useSelect` wiring. The `useMapBlocks` hook itself is a
 * thin `useSelect( … ) → deriveMapBlocks( … )` shim, so the integration
 * point sits in `edit.tsx` and the editor-level tests there.
 *
 * Coverage:
 *
 * - Document order is preserved through nested containers.
 * - `configuredMapBlocks` requires both `attachmentId > 0` AND a non-empty
 *   `mapId` (the derived rule for the one-render window where Map's
 *   `useEnsureUniqueMapId` has not yet written the id).
 * - Picker entries are one per configured Map, in document order, with
 *   no deduplication by file.
 * - Picker labels use the three-tier rule and the `GPX Map #N` index
 *   counts ALL Map blocks on the page (including unconfigured ones).
 * - An empty tree yields empty arrays.
 *
 * @since 1.0.0
 */

jest.mock(
	'@wordpress/i18n',
	() => ( {
		__esModule: true,
		__: ( s: string ) => s,
		sprintf: ( template: string, ...args: unknown[] ) =>
			template.replace( /%d/g, () => String( args.shift() ?? '' ) ),
	} ),
	{ virtual: true }
);

jest.mock(
	'@wordpress/data',
	() => ( { __esModule: true, useSelect: () => [] } ),
	{ virtual: true }
);

jest.mock(
	'@wordpress/block-editor',
	() => ( { __esModule: true, store: 'core/block-editor' } ),
	{ virtual: true }
);

import {
	collectMapBlocks,
	deriveMapBlocks,
	isConfigured,
	type EditorBlock,
} from './use-map-blocks';

/**
 * Convenience constructor for an editor block. Defaults to a top-level
 * empty paragraph so tests can populate only the fields they care about.
 * @param overrides
 */
function mkBlock( overrides: Partial< EditorBlock > = {} ): EditorBlock {
	return {
		name: overrides.name ?? 'core/paragraph',
		clientId:
			overrides.clientId ??
			'client-' + Math.random().toString( 36 ).slice( 2 ),
		attributes: overrides.attributes ?? {},
		innerBlocks: overrides.innerBlocks ?? [],
	};
}

describe( 'collectMapBlocks', () => {
	it( 'returns an empty array for an empty tree', () => {
		expect( collectMapBlocks( [] ) ).toEqual( [] );
	} );

	it( 'walks top-level Map blocks in document order', () => {
		const first = mkBlock( {
			name: 'kntnt-gpx-blocks/map',
			clientId: 'm1',
		} );
		const second = mkBlock( {
			name: 'kntnt-gpx-blocks/map',
			clientId: 'm2',
		} );
		const result = collectMapBlocks( [ first, mkBlock(), second ] );
		expect( result.map( ( b ) => b.clientId ) ).toEqual( [ 'm1', 'm2' ] );
	} );

	it( 'recurses into nested containers (pre-order)', () => {
		const outerMap = mkBlock( {
			name: 'kntnt-gpx-blocks/map',
			clientId: 'outer',
		} );
		const nestedMap = mkBlock( {
			name: 'kntnt-gpx-blocks/map',
			clientId: 'nested',
		} );
		const deeplyNestedMap = mkBlock( {
			name: 'kntnt-gpx-blocks/map',
			clientId: 'deep',
		} );

		// Tree:
		//   group
		//     columns
		//       nested
		//       cover
		//         deep
		//   outerMap
		const group = mkBlock( {
			name: 'core/group',
			innerBlocks: [
				mkBlock( {
					name: 'core/columns',
					innerBlocks: [
						nestedMap,
						mkBlock( {
							name: 'core/cover',
							innerBlocks: [ deeplyNestedMap ],
						} ),
					],
				} ),
			],
		} );

		const result = collectMapBlocks( [ group, outerMap ] );
		expect( result.map( ( b ) => b.clientId ) ).toEqual( [
			'nested',
			'deep',
			'outer',
		] );
	} );
} );

describe( 'isConfigured', () => {
	it( 'rejects unconfigured Map blocks (attachmentId = 0)', () => {
		expect(
			isConfigured(
				mkBlock( {
					name: 'kntnt-gpx-blocks/map',
					attributes: { attachmentId: 0, mapId: 'map-abc' },
				} )
			)
		).toBe( false );
	} );

	it( 'rejects Map blocks with an empty mapId (one-render window)', () => {
		expect(
			isConfigured(
				mkBlock( {
					name: 'kntnt-gpx-blocks/map',
					attributes: { attachmentId: 42, mapId: '' },
				} )
			)
		).toBe( false );
	} );

	it( 'accepts a Map block with both attachmentId > 0 and a mapId', () => {
		expect(
			isConfigured(
				mkBlock( {
					name: 'kntnt-gpx-blocks/map',
					attributes: { attachmentId: 42, mapId: 'map-abc' },
				} )
			)
		).toBe( true );
	} );
} );

describe( 'deriveMapBlocks', () => {
	it( 'returns empty arrays for an empty tree', () => {
		const result = deriveMapBlocks( [] );
		expect( result.mapBlocks ).toEqual( [] );
		expect( result.configuredMapBlocks ).toEqual( [] );
		expect( result.mapOptions ).toEqual( [] );
	} );

	it( 'excludes a configured Map whose mapId has not been generated yet', () => {
		const blocks: EditorBlock[] = [
			mkBlock( {
				name: 'kntnt-gpx-blocks/map',
				clientId: 'm1',
				attributes: { attachmentId: 42, mapId: 'map-abc' },
			} ),
			mkBlock( {
				name: 'kntnt-gpx-blocks/map',
				clientId: 'm2',
				attributes: { attachmentId: 7, mapId: '' },
			} ),
		];
		const result = deriveMapBlocks( blocks );
		expect( result.configuredMapBlocks.map( ( b ) => b.clientId ) ).toEqual(
			[ 'm1' ]
		);
		expect( result.mapOptions.map( ( o ) => o.value ) ).toEqual( [
			'map-abc',
		] );
	} );

	it( 'produces one option per configured Map without file-based deduplication', () => {
		const blocks: EditorBlock[] = [
			mkBlock( {
				name: 'kntnt-gpx-blocks/map',
				attributes: { attachmentId: 42, mapId: 'map-aaa' },
			} ),
			mkBlock( {
				name: 'kntnt-gpx-blocks/map',
				attributes: { attachmentId: 42, mapId: 'map-bbb' },
			} ),
		];
		const result = deriveMapBlocks( blocks );
		expect( result.mapOptions ).toHaveLength( 2 );
		expect( result.mapOptions.map( ( o ) => o.value ) ).toEqual( [
			'map-aaa',
			'map-bbb',
		] );
	} );

	it( 'uses the three-tier label rule', () => {
		const blocks: EditorBlock[] = [
			mkBlock( {
				name: 'kntnt-gpx-blocks/map',
				attributes: {
					attachmentId: 1,
					mapId: 'map-1',
					metadata: { name: 'Northern loop' },
				},
			} ),
			mkBlock( {
				name: 'kntnt-gpx-blocks/map',
				attributes: {
					attachmentId: 2,
					mapId: 'map-2',
					anchor: 'route-b',
				},
			} ),
			mkBlock( {
				name: 'kntnt-gpx-blocks/map',
				attributes: { attachmentId: 3, mapId: 'map-3' },
			} ),
		];
		const result = deriveMapBlocks( blocks );
		expect( result.mapOptions.map( ( o ) => o.label ) ).toEqual( [
			'Northern loop',
			'route-b',
			'GPX Map #3',
		] );
	} );

	it( 'counts ALL Map blocks (configured or not) for the fallback index', () => {
		const blocks: EditorBlock[] = [
			// Index 1 — unconfigured, no picker entry but counted.
			mkBlock( {
				name: 'kntnt-gpx-blocks/map',
				attributes: { attachmentId: 0, mapId: '' },
			} ),
			// Index 2 — configured, no metadata or anchor → falls back to #2.
			mkBlock( {
				name: 'kntnt-gpx-blocks/map',
				attributes: { attachmentId: 7, mapId: 'map-bbb' },
			} ),
		];
		const result = deriveMapBlocks( blocks );
		expect( result.mapBlocks ).toHaveLength( 2 );
		expect( result.configuredMapBlocks ).toHaveLength( 1 );
		expect( result.mapOptions[ 0 ]?.label ).toBe( 'GPX Map #2' );
	} );

	it( 'preserves pre-order document order across nested containers', () => {
		const group = mkBlock( {
			name: 'core/group',
			innerBlocks: [
				mkBlock( {
					name: 'kntnt-gpx-blocks/map',
					clientId: 'nested',
					attributes: { attachmentId: 9, mapId: 'map-nested' },
				} ),
			],
		} );
		const top = mkBlock( {
			name: 'kntnt-gpx-blocks/map',
			clientId: 'top',
			attributes: { attachmentId: 10, mapId: 'map-top' },
		} );
		const result = deriveMapBlocks( [ group, top ] );
		expect( result.mapOptions.map( ( o ) => o.value ) ).toEqual( [
			'map-nested',
			'map-top',
		] );
	} );
} );
