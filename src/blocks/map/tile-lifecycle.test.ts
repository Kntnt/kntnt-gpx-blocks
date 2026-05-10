/**
 * Jest tests for the GPX Map tile-lifecycle helpers.
 *
 * Asserts the idempotence contract documented in `tile-lifecycle.ts`:
 * `addTiles` is a no-op the second time, `removeTiles` is a no-op when no
 * layers are attached, and a usable URL is the gate that decides whether a
 * record produces a tile layer at all (the documented polyline-only state
 * for paid providers without an API key).
 *
 * Leaflet is mocked at the module boundary — `L.tileLayer().addTo()` and
 * `map.removeLayer()` are spies — so the tests run without instantiating a
 * real Leaflet map. The pure-helper extraction makes this practical; the
 * full `view.ts` mount path is exercised separately in the Playground
 * integration smoke test.
 *
 * @since 1.0.0
 */

import {
	addTiles,
	createEmptyTileRefs,
	hasUsableUrl,
	removeTiles,
	type TileLayerRecord,
} from './tile-lifecycle';

// Leaflet mock — only the surface the helpers touch is stubbed. `addTo`
// returns the layer instance so the helpers can chain into it; `removeLayer`
// is a no-op spy that records calls so the tests can assert detachment.
jest.mock( 'leaflet', () => {
	const tileLayerInstances: Array< { url: string; options: unknown } > = [];
	const addToCalls: number[] = [];

	const tileLayer = jest.fn( ( url: string, options: unknown ) => {
		const instance = {
			url,
			options,
			_id: tileLayerInstances.length,
			addTo: jest.fn().mockImplementation( function ( this: {
				_id: number;
			} ) {
				addToCalls.push( this._id );
				return this;
			} ),
		};
		tileLayerInstances.push( instance );
		return instance;
	} );

	return {
		__esModule: true,
		default: {
			tileLayer,
			__getTileLayerInstances: () => tileLayerInstances,
			__resetMock: () => {
				tileLayerInstances.length = 0;
				addToCalls.length = 0;
				tileLayer.mockClear();
			},
		},
	};
} );

// Local typed alias for the leaflet mock so the test body can reach the
// instrumentation without a `as any` everywhere. The mock surface above
// declares these helpers; this interface narrows them for the consumer.
interface LeafletMock {
	tileLayer: jest.Mock;
	__getTileLayerInstances: () => Array< { _id: number } >;
	__resetMock: () => void;
}

// Minimal map stub — only `removeLayer` is consulted by `removeTiles`.
function createMapStub(): { map: L.Map; removeLayer: jest.Mock } {
	const removeLayer = jest.fn();
	const map = { removeLayer } as unknown as L.Map;
	return { map, removeLayer };
}

// Canonical sample records used across the suite. The "with key" record has
// a usable URL — the "no key" record models the runtime state PHP emits
// when `requiresKey && tileApiKey === ''`.
const baseWithKey: TileLayerRecord = {
	id: 'thunderforest-outdoors',
	url: 'https://tile.thunderforest.com/outdoors/{z}/{x}/{y}.png?apikey=ABC',
	attribution: 'Thunderforest',
	maxZoom: 22,
};
const baseNoKey: TileLayerRecord = {
	id: 'thunderforest-outdoors',
	url: null,
	attribution: 'Thunderforest',
	maxZoom: 22,
};
const overlayHiking: TileLayerRecord = {
	id: 'wmt-hiking',
	url: 'https://tile.waymarkedtrails.org/hiking/{z}/{x}/{y}.png',
	attribution: 'Waymarked Trails',
	maxZoom: 18,
};

beforeEach( () => {
	const L = jest.requireMock( 'leaflet' ).default as LeafletMock;
	L.__resetMock();
} );

describe( 'hasUsableUrl', () => {
	it( 'returns false for null records', () => {
		expect( hasUsableUrl( null ) ).toBe( false );
	} );

	it( 'returns false for undefined records', () => {
		expect( hasUsableUrl( undefined ) ).toBe( false );
	} );

	it( 'returns false when url is null', () => {
		expect( hasUsableUrl( baseNoKey ) ).toBe( false );
	} );

	it( 'returns false when url is the empty string', () => {
		expect(
			hasUsableUrl( {
				id: 'x',
				url: '',
				attribution: 'a',
				maxZoom: 19,
			} )
		).toBe( false );
	} );

	it( 'returns true for a non-empty URL string', () => {
		expect( hasUsableUrl( baseWithKey ) ).toBe( true );
	} );
} );

describe( 'addTiles', () => {
	it( 'mounts the base layer when the record has a usable URL', () => {
		const { map } = createMapStub();
		const refs = createEmptyTileRefs();

		addTiles( map, baseWithKey, [], refs );

		const L = jest.requireMock( 'leaflet' ).default as LeafletMock;
		expect( L.tileLayer ).toHaveBeenCalledTimes( 1 );
		expect( L.tileLayer ).toHaveBeenCalledWith(
			baseWithKey.url,
			expect.objectContaining( {
				maxZoom: 22,
				attribution: 'Thunderforest',
			} )
		);
		expect( refs.base ).not.toBeNull();
	} );

	it( 'skips the base layer when the record url is null (polyline-only path)', () => {
		const { map } = createMapStub();
		const refs = createEmptyTileRefs();

		addTiles( map, baseNoKey, [], refs );

		const L = jest.requireMock( 'leaflet' ).default as LeafletMock;
		expect( L.tileLayer ).not.toHaveBeenCalled();
		expect( refs.base ).toBeNull();
	} );

	it( 'mounts overlay layers in input order', () => {
		const { map } = createMapStub();
		const refs = createEmptyTileRefs();
		const second: TileLayerRecord = {
			id: 'second-overlay',
			url: 'https://example.test/overlay/{z}/{x}/{y}.png',
			attribution: 'Example',
			maxZoom: 18,
		};

		addTiles( map, baseWithKey, [ overlayHiking, second ], refs );

		const L = jest.requireMock( 'leaflet' ).default as LeafletMock;
		// 1 base + 2 overlays = 3 calls total; calls fired in the listed order.
		expect( L.tileLayer ).toHaveBeenCalledTimes( 3 );
		expect( L.tileLayer.mock.calls[ 1 ][ 0 ] ).toBe( overlayHiking.url );
		expect( L.tileLayer.mock.calls[ 2 ][ 0 ] ).toBe( second.url );
		expect( refs.overlays ).toHaveLength( 2 );
	} );

	it( 'forwards subdomains when present', () => {
		const { map } = createMapStub();
		const refs = createEmptyTileRefs();
		const withSubs: TileLayerRecord = {
			id: 'osm-standard',
			url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
			attribution: 'OSM',
			maxZoom: 19,
			subdomains: [ 'a', 'b', 'c' ],
		};

		addTiles( map, withSubs, [], refs );

		const L = jest.requireMock( 'leaflet' ).default as LeafletMock;
		expect( L.tileLayer.mock.calls[ 0 ][ 1 ] ).toEqual(
			expect.objectContaining( { subdomains: [ 'a', 'b', 'c' ] } )
		);
	} );

	it( 'is idempotent when called twice with the same arguments', () => {
		const { map } = createMapStub();
		const refs = createEmptyTileRefs();

		addTiles( map, baseWithKey, [ overlayHiking ], refs );
		addTiles( map, baseWithKey, [ overlayHiking ], refs );

		const L = jest.requireMock( 'leaflet' ).default as LeafletMock;
		// 1 base + 1 overlay = 2 — second call short-circuits on `refs.base`.
		expect( L.tileLayer ).toHaveBeenCalledTimes( 2 );
		expect( refs.overlays ).toHaveLength( 1 );
	} );

	it( 'is a no-op when called with a null base and no overlays', () => {
		const { map } = createMapStub();
		const refs = createEmptyTileRefs();

		addTiles( map, null, [], refs );

		const L = jest.requireMock( 'leaflet' ).default as LeafletMock;
		expect( L.tileLayer ).not.toHaveBeenCalled();
		expect( refs.base ).toBeNull();
		expect( refs.overlays ).toHaveLength( 0 );
	} );

	it( 'mounts overlays even when the base url is null', () => {
		const { map } = createMapStub();
		const refs = createEmptyTileRefs();

		addTiles( map, baseNoKey, [ overlayHiking ], refs );

		const L = jest.requireMock( 'leaflet' ).default as LeafletMock;
		// No base call, but the overlay still mounts.
		expect( L.tileLayer ).toHaveBeenCalledTimes( 1 );
		expect( L.tileLayer.mock.calls[ 0 ][ 0 ] ).toBe( overlayHiking.url );
		expect( refs.base ).toBeNull();
		expect( refs.overlays ).toHaveLength( 1 );
	} );
} );

describe( 'removeTiles', () => {
	it( 'detaches the base layer and clears the ref', () => {
		const { map, removeLayer } = createMapStub();
		const refs = createEmptyTileRefs();

		addTiles( map, baseWithKey, [], refs );
		const previousBase = refs.base;

		removeTiles( map, refs );

		expect( removeLayer ).toHaveBeenCalledTimes( 1 );
		expect( removeLayer ).toHaveBeenCalledWith( previousBase );
		expect( refs.base ).toBeNull();
	} );

	it( 'detaches every overlay and empties the array', () => {
		const { map, removeLayer } = createMapStub();
		const refs = createEmptyTileRefs();
		const second: TileLayerRecord = {
			id: 'second-overlay',
			url: 'https://example.test/overlay/{z}/{x}/{y}.png',
			attribution: 'Example',
			maxZoom: 18,
		};

		addTiles( map, baseWithKey, [ overlayHiking, second ], refs );
		removeTiles( map, refs );

		// 1 base + 2 overlays = 3 detachments.
		expect( removeLayer ).toHaveBeenCalledTimes( 3 );
		expect( refs.overlays ).toHaveLength( 0 );
	} );

	it( 'is a no-op when nothing was added', () => {
		const { map, removeLayer } = createMapStub();
		const refs = createEmptyTileRefs();

		removeTiles( map, refs );

		expect( removeLayer ).not.toHaveBeenCalled();
		expect( refs.base ).toBeNull();
		expect( refs.overlays ).toHaveLength( 0 );
	} );

	it( 'allows addTiles to remount cleanly after removal', () => {
		const { map } = createMapStub();
		const refs = createEmptyTileRefs();

		addTiles( map, baseWithKey, [ overlayHiking ], refs );
		removeTiles( map, refs );
		addTiles( map, baseWithKey, [ overlayHiking ], refs );

		const L = jest.requireMock( 'leaflet' ).default as LeafletMock;
		// Two full add-passes, each producing 1 base + 1 overlay = 4 tileLayer
		// calls overall. The second `addTiles` is not blocked by the
		// idempotence guard because `removeTiles` cleared the refs.
		expect( L.tileLayer ).toHaveBeenCalledTimes( 4 );
		expect( refs.base ).not.toBeNull();
		expect( refs.overlays ).toHaveLength( 1 );
	} );
} );
