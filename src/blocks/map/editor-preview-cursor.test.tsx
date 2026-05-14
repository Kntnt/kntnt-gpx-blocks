/**
 * Regression tests for issue #153 — the GPX Map editor preview's track
 * cursor.
 *
 * Before #153 the editor preview had no cursor support at all. The
 * leading doc-comment explicitly listed "no cursor sync" as a deliberate
 * scope decision because the Interactivity API does not bootstrap inside
 * the editor iframe. As a result, hovering the Elevation editor preview
 * could not move the Map's cursor in the editor — even though the
 * frontend exposed the feature in full and the user expected the editor
 * to match.
 *
 * The fix introduces a tiny in-iframe `CustomEvent` bus
 * (`src/blocks/shared/editor-cursor-bridge.ts`) that mirrors the
 * frontend `state[mapId].fraction` contract: publish a fraction keyed by
 * `mapId`, subscribe to fractions keyed by `mapId`. The Map editor
 * preview subscribes when its `Track cursor` toggle is on; the
 * Elevation editor preview publishes on hover. These tests pin the Map
 * side end-to-end against the bus.
 *
 * The tests:
 *
 *   - **Renders a cursor marker on the polyline.** With `trackCursor: true`
 *     and a non-empty `mapId`, the preview mounts a Leaflet `circleMarker`
 *     and attaches it to the map. Counts the `circleMarker` calls before
 *     and after the polyline `circleMarker`s that already exist for
 *     waypoints, so the assertion is robust against pointToLayer behaviour.
 *   - **Mirrors a published fraction onto the cursor.** Publishing through
 *     `publishEditorCursor( mapId, 0.5 )` calls the marker's `setLatLng`
 *     with a value from the polyline and bumps its opacity. Publishing
 *     `null` dismisses the marker.
 *   - **Disabled toggle suppresses the cursor.** With `trackCursor: false`,
 *     no marker is mounted and a publish has nowhere to land.
 *   - **`mapId` mismatch ignores the publish.** A publish on `mapId="other"`
 *     leaves the marker on this preview untouched — the bus filters per
 *     `mapId` and the Map preview's subscription is scoped accordingly.
 *   - **Default colour fallback.** With `trackCursorColor: ''`, the marker
 *     mounts with the documented `#d63638` default (mirrors
 *     `src/blocks/map/style.scss` and `mount.ts`'s frontend fallback).
 *
 * @since 1.0.0
 */

import { createElement, createRoot } from '@wordpress/element';
// `react` is available transitively through `@wordpress/element`'s peer
// dependency, and `act` lives there in React 18+. See the parallel
// `editor-preview.test.tsx` for the same exception.
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

// Tell React 18 that `act(...)` calls are expected.
(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

// Mock `@wordpress/api-fetch` with a deferred resolver so each test can
// synchronously control when the payload arrives.
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

// `@wordpress/components` — Notice is the only surface reached.
jest.mock(
	'@wordpress/components',
	() => ( {
		__esModule: true,
		Notice: ( { children }: { children: React.ReactNode } ) => children,
	} ),
	{ virtual: true }
);

// `@wordpress/i18n` — pass-through.
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

/**
 * Captured Leaflet state shared with the mock factory below. Each test
 * resets it in `beforeEach`.
 *
 * @since 1.0.0
 */
const mockLeafletState: {
	mapInstances: Array< { instance: any; container: HTMLElement } >;
	circleMarkers: Array< {
		latlng: [ number, number ];
		options: any;
		instance: any;
	} >;
} = { mapInstances: [], circleMarkers: [] };

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
			setLatLng: jest.fn(),
			getBounds: () => ( {
				isValid: () => true,
				getSouthWest: () => ( { lat: 0, lng: 0 } ),
				getNorthEast: () => ( { lat: 1, lng: 1 } ),
			} ),
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
			on: jest.fn(),
			off: jest.fn(),
		};
		mockLeafletState.mapInstances.push( { instance, container } );
		return instance;
	} );
	const canvas = jest.fn( () => ( {} ) );
	const tileLayer = jest.fn( () => makeLayer( { _kind: 'tile' } ) );
	const geoJSON = jest.fn( ( data: any ) =>
		makeLayer( { _kind: 'geojson', data } )
	);
	const circleMarker = jest.fn(
		( latlng: [ number, number ], options: any ) => {
			const layer = makeLayer( {
				_kind: 'circleMarker',
				latlng,
				options,
			} );
			mockLeafletState.circleMarkers.push( {
				latlng,
				options,
				instance: layer,
			} );
			return layer;
		}
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

// Imports below pull the component under test plus the publish-side of
// the editor cursor bridge — the tests publish via this function and
// assert the Map preview reflects the result.
import { MapEditorPreview } from './editor-preview';
import { publishEditorCursor } from '../shared/editor-cursor-bridge';

/**
 * Build a GeoJSON payload with a multi-vertex LineString. The cursor's
 * fraction → lat/lng resolution walks this array, so the test asserts on
 * an exact midpoint that lands somewhere recognisable along the synthetic
 * track.
 *
 * @since 1.0.0
 */
function buildLineGeojson(): GeoJSON.FeatureCollection {
	return {
		type: 'FeatureCollection',
		features: [
			{
				type: 'Feature',
				geometry: {
					type: 'LineString',
					// 5 evenly-spaced vertices along (lon, lat).
					coordinates: [
						[ 11.0, 57.0 ],
						[ 11.25, 57.0 ],
						[ 11.5, 57.0 ],
						[ 11.75, 57.0 ],
						[ 12.0, 57.0 ],
					],
				},
				properties: {},
			},
		],
	};
}

/**
 * Default attribute payload for the preview. Each test overrides only
 * the fields it cares about; everything else stays at the defaults
 * below. Mirrors `editor-preview.test.tsx`'s helper so the shape stays
 * in sync across both files.
 *
 * @param overrides Per-test attribute overrides merged on top of defaults.
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
		mapId: 'map-1',
		trackCursor: true,
		trackCursorColor: '#22aa22',
		...overrides,
	};
}

/**
 * Render the preview into a fresh document body, resolve the REST
 * payload with the given GeoJSON, and return the root + cleanup
 * function. Encapsulates the boilerplate every test repeats.
 *
 * @param attributes Preview attributes.
 * @param geojson    GeoJSON payload to resolve the apiFetch promise with.
 * @return Mount handles for assertions and cleanup.
 */
async function mountWithPayload(
	attributes: Record< string, unknown >,
	geojson: GeoJSON.FeatureCollection
): Promise< {
	root: ReturnType< typeof createRoot >;
	container: HTMLDivElement;
} > {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );

	await act( async () => {
		root.render(
			createElement( MapEditorPreview, {
				attributes,
			} as never )
		);
	} );

	await act( async () => {
		mockApiFetchState.resolve!( { geojson } );
	} );

	return { root, container };
}

beforeEach( () => {
	mockLeafletState.mapInstances.length = 0;
	mockLeafletState.circleMarkers.length = 0;
	mockApiFetchState.resolve = null;
	mockApiFetchState.reject = null;
} );

describe( 'MapEditorPreview cursor sync (issue #153)', () => {
	it( 'mounts a cursor marker when trackCursor is on and mapId is set', async () => {
		const { root, container } = await mountWithPayload(
			buildAttributes(),
			buildLineGeojson()
		);

		// The cursor is the `circleMarker` mounted with `interactive: false`
		// and `opacity: 0` — distinct from any waypoint markers (which are
		// interactive and fully opaque). Pin on `interactive` so the
		// assertion does not depend on geoJSON pointToLayer side effects.
		const cursorCalls = mockLeafletState.circleMarkers.filter(
			( c ) => c.options?.interactive === false
		);
		expect( cursorCalls ).toHaveLength( 1 );
		expect( cursorCalls[ 0 ].options.opacity ).toBe( 0 );
		expect( cursorCalls[ 0 ].options.color ).toBe( '#22aa22' );

		// Map is single — `addTo(map)` mounts the cursor on it.
		expect( mockLeafletState.mapInstances ).toHaveLength( 1 );
		const { instance: map } = mockLeafletState.mapInstances[ 0 ];
		expect( cursorCalls[ 0 ].instance.addTo ).toHaveBeenCalledWith( map );

		await act( async () => {
			root.unmount();
		} );
		container.remove();
	} );

	it( 'mirrors a published fraction onto the cursor', async () => {
		const { root, container } = await mountWithPayload(
			buildAttributes(),
			buildLineGeojson()
		);

		const cursor = mockLeafletState.circleMarkers.find(
			( c ) => c.options?.interactive === false
		)!.instance;
		( cursor.setLatLng as jest.Mock ).mockClear();
		( cursor.setStyle as jest.Mock ).mockClear();

		// Publish a fraction; the bridge subscription must call setLatLng
		// and bump opacity to make the cursor visible. The exact lat/lng
		// is not asserted (it depends on the cumulative-distance math),
		// but it must be a 2-tuple of finite numbers from somewhere along
		// the track.
		await act( async () => {
			publishEditorCursor( 'map-1', 0.5 );
		} );

		expect( cursor.setLatLng ).toHaveBeenCalledTimes( 1 );
		const arg = ( cursor.setLatLng as jest.Mock ).mock.calls[ 0 ][ 0 ];
		expect( Array.isArray( arg ) ).toBe( true );
		expect( arg ).toHaveLength( 2 );
		expect( Number.isFinite( arg[ 0 ] ) ).toBe( true );
		expect( Number.isFinite( arg[ 1 ] ) ).toBe( true );

		expect( cursor.setStyle ).toHaveBeenCalledWith(
			expect.objectContaining( { opacity: 1, fillOpacity: 1 } )
		);

		// Null fraction → hide the marker.
		( cursor.setStyle as jest.Mock ).mockClear();
		await act( async () => {
			publishEditorCursor( 'map-1', null );
		} );
		expect( cursor.setStyle ).toHaveBeenCalledWith(
			expect.objectContaining( { opacity: 0, fillOpacity: 0 } )
		);

		await act( async () => {
			root.unmount();
		} );
		container.remove();
	} );

	it( 'does not mount a cursor when the Track cursor toggle is off', async () => {
		const { root, container } = await mountWithPayload(
			buildAttributes( { trackCursor: false } ),
			buildLineGeojson()
		);

		const cursorCalls = mockLeafletState.circleMarkers.filter(
			( c ) => c.options?.interactive === false
		);
		expect( cursorCalls ).toHaveLength( 0 );

		await act( async () => {
			root.unmount();
		} );
		container.remove();
	} );

	it( 'ignores publishes on a different mapId', async () => {
		const { root, container } = await mountWithPayload(
			buildAttributes(),
			buildLineGeojson()
		);

		const cursor = mockLeafletState.circleMarkers.find(
			( c ) => c.options?.interactive === false
		)!.instance;
		( cursor.setLatLng as jest.Mock ).mockClear();

		await act( async () => {
			publishEditorCursor( 'other-map', 0.5 );
		} );

		expect( cursor.setLatLng ).not.toHaveBeenCalled();

		await act( async () => {
			root.unmount();
		} );
		container.remove();
	} );

	it( 'falls back to the documented default colour when trackCursorColor is empty', async () => {
		const { root, container } = await mountWithPayload(
			buildAttributes( { trackCursorColor: '' } ),
			buildLineGeojson()
		);

		const cursor = mockLeafletState.circleMarkers.find(
			( c ) => c.options?.interactive === false
		);
		expect( cursor ).toBeDefined();
		// Default mirrors `style.scss`'s
		// `--kntnt-gpx-blocks-track-cursor-color: #d63638` and the
		// frontend fallback in `mount.ts::createCursorMarker`.
		expect( cursor!.options.color ).toBe( '#d63638' );
		expect( cursor!.options.fillColor ).toBe( '#d63638' );

		await act( async () => {
			root.unmount();
		} );
		container.remove();
	} );
} );
