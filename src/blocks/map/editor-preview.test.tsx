/**
 * Regression tests for the GPX Map editor preview's base-tile attach.
 *
 * Issue #100: on first mount of the editor preview, the base tile layer
 * was never attached to the Leaflet map. The mount effect (deps
 * `[ payload ]`) ran when the REST payload resolved and created the map;
 * the base-tile effect (deps `[ providerKey ]`) had already run on first
 * render with no map and bailed, and `providerKey` did not change after
 * the payload arrived, so the effect never re-fired. Net result: the
 * polyline rendered over an empty tile background until the user nudged
 * the provider dropdown.
 *
 * The fix lists `payload` as an additional dep on the base-tile effect so
 * it re-runs after the map is created. This test asserts the post-fix
 * behaviour by mocking `apiFetch` and `leaflet` and verifying that, after
 * the payload resolves, `L.tileLayer` is called for the provider URL and
 * the resulting layer's `addTo` is invoked with the freshly created map.
 *
 * @since 0.7.1
 */

import { createElement, createRoot } from '@wordpress/element';
// `react` is available transitively through `@wordpress/element`'s peer
// dependency, and `act` lives there in React 18+. Keeping this import
// flagged by the lint rule would just channel us into the deprecated
// `react-dom/test-utils` path instead, whose runtime warning is caught
// by `@wordpress/jest-console` and fails the test. Since this is a
// test-only file, the lint exception is the right scope.
// eslint-disable-next-line import/no-extraneous-dependencies
import { act } from 'react';

// jsdom does not ship `ResizeObserver`, but `MapEditorPreview` registers
// one to drive `invalidateSize()` on layout changes. Stub it as a no-op
// so the component can mount without throwing in the test environment.
class ResizeObserverStub {
	observe(): void {}
	unobserve(): void {}
	disconnect(): void {}
}
( globalThis as { ResizeObserver?: typeof ResizeObserver } ).ResizeObserver =
	ResizeObserverStub as unknown as typeof ResizeObserver;

// Tell React 18 that `act(...)` calls are expected so it does not log
// the "current testing environment is not configured to support act(...)"
// warning. The flag is read by react-dom in development builds.
(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

// Mock `@wordpress/api-fetch` so the test resolves the preview payload on
// demand rather than performing a real REST round-trip. The mock exposes
// a deferred resolver so the test can synchronously control when the
// payload arrives — useful for asserting effect-firing order. The state
// holder uses a `mock`-prefixed name so jest's hoist-time scope check
// allows referencing it from inside the factory.
const mockApiFetchState: {
	resolve: ( ( value: unknown ) => void ) | null;
	reject: ( ( reason: unknown ) => void ) | null;
} = { resolve: null, reject: null };
jest.mock(
	'@wordpress/api-fetch',
	() => ( {
		__esModule: true,
		default: jest.fn(
			() =>
				new Promise( ( resolve, reject ) => {
					mockApiFetchState.resolve = resolve;
					mockApiFetchState.reject = reject;
				} )
		),
	} ),
	{ virtual: true }
);

// Mock `@wordpress/components` — only `Notice` is reached, and a simple
// passthrough is enough for these tests.
jest.mock(
	'@wordpress/components',
	() => ( {
		__esModule: true,
		Notice: ( { children }: { children: React.ReactNode } ) => children,
	} ),
	{ virtual: true }
);

// Mock `@wordpress/i18n` — simple passthrough; sprintf swaps `%s` for the
// first argument so the tests do not depend on the real implementation.
jest.mock(
	'@wordpress/i18n',
	() => ( {
		__esModule: true,
		__: ( s: string ) => s,
		sprintf: ( template: string, ...args: unknown[] ) =>
			template.replace( /%s/g, () => String( args.shift() ?? '' ) ),
	} ),
	{ virtual: true }
);

// Mock Leaflet at the module boundary. The mock captures every
// `L.map()` instance and every `L.tileLayer()` call so the tests can
// assert exactly which layers were attached and to which map.
const mockLeafletState: {
	mapInstances: Array< { instance: any; container: HTMLElement } >;
	tileLayerCalls: Array< { url: string; options: any; layer: any } >;
} = { mapInstances: [], tileLayerCalls: [] };

jest.mock( 'leaflet', () => {
	const makeLayer = ( base: Record< string, unknown > ) => {
		const layer: any = {
			...base,
			addTo: jest.fn( function ( this: any ) {
				return this;
			} ),
			bringToBack: jest.fn( function ( this: any ) {
				return this;
			} ),
			setStyle: jest.fn(),
			getBounds: () => ( { isValid: () => false } ),
		};
		return layer;
	};
	const map = jest.fn( ( container: HTMLElement ) => {
		const instance: any = {
			_container: container,
			remove: jest.fn(),
			fitBounds: jest.fn(),
			invalidateSize: jest.fn(),
			removeLayer: jest.fn(),
		};
		mockLeafletState.mapInstances.push( { instance, container } );
		return instance;
	} );
	const canvas = jest.fn( () => ( {} ) );
	const tileLayer = jest.fn( ( url: string, options: any ) => {
		const layer = makeLayer( { _kind: 'tile', url, options } );
		mockLeafletState.tileLayerCalls.push( { url, options, layer } );
		return layer;
	} );
	const geoJSON = jest.fn( ( data: any ) =>
		makeLayer( { _kind: 'geojson', data } )
	);
	const circleMarker = jest.fn( () =>
		makeLayer( { _kind: 'circleMarker' } )
	);
	return {
		__esModule: true,
		default: {
			map,
			canvas,
			tileLayer,
			geoJSON,
			circleMarker,
		},
	};
} );

// Component under test is imported AFTER the mocks register so the
// jest.mock factories resolve the right module references.
import { MapEditorPreview } from './editor-preview';

/**
 * Default attribute payload for the preview. Each test overrides only the
 * fields it cares about; everything else stays at the defaults below.
 *
 * @param overrides Per-test attribute overrides merged on top of defaults.
 *
 * @return PreviewAttributes-shaped object ready to feed into MapEditorPreview.
 */
function buildAttributes( overrides: Record< string, unknown > = {} ) {
	return {
		attachmentId: 42,
		trackColor: '',
		waypointColor: '',
		tooltipShowName: false,
		tooltipShowDesc: false,
		provider: {
			url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
			attribution: 'OSM',
			maxZoom: 19,
			subdomains: [ 'a', 'b', 'c' ],
		},
		overlays: [],
		...overrides,
	};
}

beforeEach( () => {
	mockLeafletState.mapInstances.length = 0;
	mockLeafletState.tileLayerCalls.length = 0;
	mockApiFetchState.resolve = null;
	mockApiFetchState.reject = null;
} );

describe( 'MapEditorPreview base-tile attach (issue #100)', () => {
	it( 'attaches the base tile layer when the payload resolves on first mount, with `providerKey` unchanged across both renders', async () => {
		const attributes = buildAttributes();
		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );

		// Render the preview. The fetch effect fires on commit; the mount
		// effect bails because `payload` is still null. The base-tile
		// effect runs but bails because `mapRef.current` is null.
		await act( async () => {
			root.render(
				createElement( MapEditorPreview, {
					attributes,
				} as never )
			);
		} );

		// Sanity: no Leaflet map yet, the apiFetch promise is parked.
		expect( mockLeafletState.mapInstances ).toHaveLength( 0 );
		expect( mockApiFetchState.resolve ).not.toBeNull();

		// Resolve the REST payload — the mount effect now creates the
		// Leaflet map, after which the base-tile effect must re-fire so
		// the OSM tile layer is attached. `providerKey` is unchanged
		// across both renders (same provider object content), so without
		// the fix the base-tile effect does not re-run and no tile layer
		// is created.
		await act( async () => {
			mockApiFetchState.resolve!( {
				geojson: {
					type: 'FeatureCollection',
					features: [],
				},
			} );
		} );

		expect( mockLeafletState.mapInstances ).toHaveLength( 1 );
		const { instance: map } = mockLeafletState.mapInstances[ 0 ];

		// The base tile layer was created with the provider URL and
		// attached to the freshly created map. `addTo(map)` is the
		// Leaflet primitive that mounts a layer; observing it here is
		// the proof that issue #100 is fixed.
		const baseTileCall = mockLeafletState.tileLayerCalls.find(
			( call ) => call.url === attributes.provider!.url
		);
		expect( baseTileCall ).toBeDefined();
		expect( baseTileCall!.layer.addTo ).toHaveBeenCalledTimes( 1 );
		expect( baseTileCall!.layer.addTo ).toHaveBeenCalledWith( map );
		expect( baseTileCall!.options ).toEqual(
			expect.objectContaining( {
				maxZoom: 19,
				attribution: 'OSM',
				subdomains: [ 'a', 'b', 'c' ],
			} )
		);

		// The base tile layer is brought to the back so that any overlays
		// added by the overlay effect render visually on top — the
		// stacking-order contract called out in the acceptance criteria.
		expect( baseTileCall!.layer.bringToBack ).toHaveBeenCalled();

		await act( async () => {
			root.unmount();
		} );
		container.remove();
	} );
} );
