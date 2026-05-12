/**
 * Unit tests for {@link findEarlierMapIdCollision}.
 *
 * The pure walk is exercised directly with constructed block trees; the
 * surrounding `useEnsureUniqueMapId` hook is a thin
 * `useSelect( … ) → findEarlierMapIdCollision( … )` shim driven by the
 * block-editor data store, and its end-to-end behaviour is covered by
 * the editor-level integration tests in `edit.test.tsx`.
 *
 * Pins the duplicate-preserves-original behaviour fixed as the
 * Step 2 follow-up patch:
 *
 *   - When two Map blocks carry the same mapId, only the *later* block
 *     in pre-order document traversal reports a collision; the earlier
 *     block reports no collision and keeps its mapId. This is what
 *     lets Elevation blocks bound to the original survive duplication.
 *   - An empty mapId is never a collision — the empty-case branch
 *     lives in `useEnsureUniqueMapId` itself (`needsNew = ! mapId`).
 *   - The walk recurses into nested containers, so a duplicate inside
 *     a `core/group` is still detected.
 *
 * @since 1.0.0
 */

jest.mock(
	'@wordpress/element',
	() => ( { __esModule: true, useEffect: () => undefined } ),
	{ virtual: true }
);

jest.mock(
	'@wordpress/data',
	() => ( { __esModule: true, useSelect: () => false } ),
	{ virtual: true }
);

jest.mock(
	'@wordpress/block-editor',
	() => ( { __esModule: true, store: 'core/block-editor' } ),
	{ virtual: true }
);

import { findEarlierMapIdCollision } from './use-ensure-unique-map-id';

interface BlockNode {
	readonly clientId: string;
	readonly name: string;
	readonly attributes?: { readonly mapId?: string };
	readonly innerBlocks?: readonly BlockNode[];
}

function mkMap(
	clientId: string,
	mapId: string,
	innerBlocks: readonly BlockNode[] = []
): BlockNode {
	return {
		clientId,
		name: 'kntnt-gpx-blocks/map',
		attributes: { mapId },
		innerBlocks,
	};
}

function mkContainer(
	clientId: string,
	innerBlocks: readonly BlockNode[]
): BlockNode {
	return {
		clientId,
		name: 'core/group',
		attributes: {},
		innerBlocks,
	};
}

describe( 'findEarlierMapIdCollision', () => {
	it( 'returns false for an empty target mapId regardless of tree state', () => {
		const tree: BlockNode[] = [
			mkMap( 'a', 'map-aaa' ),
			mkMap( 'self', '' ),
		];
		expect( findEarlierMapIdCollision( tree, 'self', '' ) ).toBe( false );
	} );

	it( 'returns false when no other Map block shares the mapId', () => {
		const tree: BlockNode[] = [
			mkMap( 'a', 'map-aaa' ),
			mkMap( 'self', 'map-zzz' ),
		];
		expect( findEarlierMapIdCollision( tree, 'self', 'map-zzz' ) ).toBe(
			false
		);
	} );

	it( 'returns false when the caller is the original (earlier) of a duplicate pair', () => {
		// Caller is at position 0; the duplicate is at position 1. The
		// original sees itself first in document order and returns false
		// — it keeps its mapId.
		const tree: BlockNode[] = [
			mkMap( 'original', 'map-aaa' ),
			mkMap( 'duplicate', 'map-aaa' ),
		];
		expect( findEarlierMapIdCollision( tree, 'original', 'map-aaa' ) ).toBe(
			false
		);
	} );

	it( 'returns true when the caller is the later (duplicate) of a duplicate pair', () => {
		const tree: BlockNode[] = [
			mkMap( 'original', 'map-aaa' ),
			mkMap( 'duplicate', 'map-aaa' ),
		];
		expect(
			findEarlierMapIdCollision( tree, 'duplicate', 'map-aaa' )
		).toBe( true );
	} );

	it( 'walks into nested containers (the duplicate may live inside a group)', () => {
		const tree: BlockNode[] = [
			mkMap( 'original', 'map-aaa' ),
			mkContainer( 'g1', [
				mkContainer( 'g2', [ mkMap( 'duplicate', 'map-aaa' ) ] ),
			] ),
		];
		expect(
			findEarlierMapIdCollision( tree, 'duplicate', 'map-aaa' )
		).toBe( true );
	} );

	it( 'walks into nested containers to find the earlier original', () => {
		const tree: BlockNode[] = [
			mkContainer( 'g1', [ mkMap( 'original', 'map-aaa' ) ] ),
			mkMap( 'duplicate', 'map-aaa' ),
		];
		expect(
			findEarlierMapIdCollision( tree, 'duplicate', 'map-aaa' )
		).toBe( true );
	} );

	it( 'stops the walk once the caller is reached (later collisions do not count)', () => {
		// The collision is *after* the caller in document order, so the
		// caller still appears to be the earliest. Returning false here
		// preserves the caller's mapId; the later block will detect the
		// collision and regenerate when its own effect runs.
		const tree: BlockNode[] = [
			mkMap( 'self', 'map-aaa' ),
			mkMap( 'later', 'map-aaa' ),
		];
		expect( findEarlierMapIdCollision( tree, 'self', 'map-aaa' ) ).toBe(
			false
		);
	} );

	it( 'three duplicates: only the third and second report collisions', () => {
		// Pre-order: A (original) → B (first duplicate) → C (second duplicate).
		// A keeps "map-aaa"; B and C each see an earlier holder and
		// regenerate. The third block (C) sees BOTH A and B as earlier
		// holders — finding either is enough to trigger regeneration.
		const tree: BlockNode[] = [
			mkMap( 'A', 'map-aaa' ),
			mkMap( 'B', 'map-aaa' ),
			mkMap( 'C', 'map-aaa' ),
		];
		expect( findEarlierMapIdCollision( tree, 'A', 'map-aaa' ) ).toBe(
			false
		);
		expect( findEarlierMapIdCollision( tree, 'B', 'map-aaa' ) ).toBe(
			true
		);
		expect( findEarlierMapIdCollision( tree, 'C', 'map-aaa' ) ).toBe(
			true
		);
	} );

	it( 'ignores non-Map blocks during the walk', () => {
		const tree: BlockNode[] = [
			{
				clientId: 'p1',
				name: 'core/paragraph',
				attributes: { mapId: 'map-aaa' },
				innerBlocks: [],
			},
			mkMap( 'self', 'map-aaa' ),
		];
		// The paragraph happens to carry a `mapId` attribute (contrived,
		// but possible in user-pasted markup). The walk filters by block
		// name so it ignores the paragraph and the caller sees no
		// earlier Map collision.
		expect( findEarlierMapIdCollision( tree, 'self', 'map-aaa' ) ).toBe(
			false
		);
	} );
} );
