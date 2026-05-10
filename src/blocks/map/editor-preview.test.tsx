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
		// `handlers` holds every event listener the component registers via
		// `map.on(...)`. The waypoint-anchor projection effect re-fires on
		// every Leaflet `move` / `zoom` event; exposing the registry lets the
		// tests invoke a stored handler manually and assert that the
		// container-point projection is repeated. `latLngToContainerPoint`
		// returns a small deterministic value that varies with the latitude,
		// so the projection assertions can compare to a known number rather
		// than reaching into Leaflet's real projection math.
		const handlers = new Map< string, Array< () => void > >();
		const instance: any = {
			_container: container,
			handlers,
			remove: jest.fn(),
			fitBounds: jest.fn(),
			invalidateSize: jest.fn(),
			removeLayer: jest.fn(),
			on: jest.fn( ( event: string, handler: () => void ) => {
				const list = handlers.get( event ) ?? [];
				list.push( handler );
				handlers.set( event, list );
			} ),
			off: jest.fn( ( event: string, handler: () => void ) => {
				const list = handlers.get( event );
				if ( ! list ) {
					return;
				}
				const idx = list.indexOf( handler );
				if ( idx >= 0 ) {
					list.splice( idx, 1 );
				}
			} ),
			latLngToContainerPoint: jest.fn(
				( latlng: [ number, number ] ) => ( {
					x: latlng[ 0 ] * 10,
					y: latlng[ 1 ] * 10,
				} )
			),
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
import {
	MapEditorPreview,
	computeLineMidpoint,
	pickFirstWaypoint,
	pickPreviewAnchor,
} from './editor-preview';

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

describe( 'MapEditorPreview host fills the wrapper independently of percentage height (issue #86)', () => {
	it( 'positions the inner host absolutely so the Leaflet container does not collapse when the wrapper has both `aspect-ratio` and inline `min-height`', async () => {
		const attributes = buildAttributes();

		// Reproduce the wrapper that `useBlockProps()` produces inside
		// `MapEdit`: the project class brings the SCSS aspect-ratio
		// baseline; the inline `min-height` is what core's `dimensions`
		// block supports emit when the editor sets the field. The
		// combination is the exact shape that triggered #86 — the inner
		// host's percentage height could resolve to zero and Leaflet
		// rendered nothing visible.
		const wrapper = document.createElement( 'div' );
		wrapper.className = 'kntnt-gpx-blocks-map';
		wrapper.style.minHeight = '400px';
		document.body.appendChild( wrapper );
		const root = createRoot( wrapper );

		await act( async () => {
			root.render(
				createElement( MapEditorPreview, {
					attributes,
				} as never )
			);
		} );

		// Resolve the REST payload so MapEditorPreview reaches its
		// payload-rendered branch (the same DOM structure the bug shows
		// up in — loading and error branches use a different host).
		await act( async () => {
			mockApiFetchState.resolve!( {
				geojson: {
					type: 'FeatureCollection',
					features: [],
				},
			} );
		} );

		// The Leaflet container is the element MapEditorPreview hands to
		// `L.map()`; the mock captures it. The bug surfaces on whichever
		// element wraps the Leaflet container — that is the element whose
		// height percentage chain back to the wrapper is broken by the
		// `aspect-ratio` + inline `min-height` combination, so it is the
		// element the fix needs to anchor independently of percentage
		// height resolution.
		expect( mockLeafletState.mapInstances ).toHaveLength( 1 );
		const leafletContainer = mockLeafletState.mapInstances[ 0 ].container;
		const innerHost = leafletContainer.parentElement;
		expect( innerHost ).not.toBeNull();
		expect( innerHost!.parentElement ).toBe( wrapper );

		// The fix: the inner host is absolutely positioned and pinned to
		// all four sides of the wrapper so its used size is determined by
		// the wrapper's padding-box rather than by percentage resolution
		// against the wrapper's computed height. That makes the host's
		// box definite even when the wrapper's height is computed from
		// `aspect-ratio` and then clamped by an inline `min-height` — the
		// shape that broke percentage resolution in #86. The fill-the-
		// wrapper contract can be expressed either as the `inset`
		// shorthand or as the four longhand sides; both are equivalent in
		// a real browser, and JSDOM keeps shorthands and longhands as
		// separate slots so the test accepts either form.
		expect( innerHost!.style.position ).toBe( 'absolute' );
		const isZeroLength = ( value: string ): boolean =>
			value === '0' || value === '0px';
		const hasFourSides =
			isZeroLength( innerHost!.style.top ) &&
			isZeroLength( innerHost!.style.right ) &&
			isZeroLength( innerHost!.style.bottom ) &&
			isZeroLength( innerHost!.style.left );
		const hasInsetShorthand = isZeroLength( innerHost!.style.inset );
		expect( hasFourSides || hasInsetShorthand ).toBe( true );

		await act( async () => {
			root.unmount();
		} );
		wrapper.remove();
	} );
} );

describe( 'pickFirstWaypoint', () => {
	it( 'returns the first Point feature with its coordinates and label strings', () => {
		const geojson: GeoJSON.FeatureCollection = {
			type: 'FeatureCollection',
			features: [
				{
					type: 'Feature',
					geometry: {
						type: 'LineString',
						coordinates: [
							[ 11.0, 57.0 ],
							[ 11.1, 57.1 ],
						],
					},
					properties: {},
				},
				{
					type: 'Feature',
					geometry: { type: 'Point', coordinates: [ 12.0, 58.0 ] },
					properties: { name: 'Lookout', desc: 'Nice view' },
				},
				{
					type: 'Feature',
					geometry: { type: 'Point', coordinates: [ 13.0, 59.0 ] },
					properties: { name: 'Second', desc: 'Ignored' },
				},
			],
		};

		expect( pickFirstWaypoint( geojson ) ).toEqual( {
			lat: 58.0,
			lon: 12.0,
			name: 'Lookout',
			desc: 'Nice view',
		} );
	} );

	it( 'returns null when no Point features are present', () => {
		const geojson: GeoJSON.FeatureCollection = {
			type: 'FeatureCollection',
			features: [
				{
					type: 'Feature',
					geometry: {
						type: 'LineString',
						coordinates: [
							[ 11.0, 57.0 ],
							[ 11.1, 57.1 ],
						],
					},
					properties: {},
				},
			],
		};

		expect( pickFirstWaypoint( geojson ) ).toBeNull();
	} );

	it( 'returns null when the payload is undefined or not a FeatureCollection', () => {
		expect( pickFirstWaypoint( undefined ) ).toBeNull();
		expect(
			pickFirstWaypoint( {
				type: 'Point',
				coordinates: [ 0, 0 ],
			} as GeoJSON.GeoJsonObject )
		).toBeNull();
	} );

	it( 'substitutes empty strings for missing label properties', () => {
		const geojson: GeoJSON.FeatureCollection = {
			type: 'FeatureCollection',
			features: [
				{
					type: 'Feature',
					geometry: { type: 'Point', coordinates: [ 9.0, 47.0 ] },
					properties: {},
				},
			],
		};

		expect( pickFirstWaypoint( geojson ) ).toEqual( {
			lat: 47.0,
			lon: 9.0,
			name: '',
			desc: '',
		} );
	} );
} );

describe( 'computeLineMidpoint', () => {
	it( 'returns null when no LineString feature exists', () => {
		const geojson: GeoJSON.FeatureCollection = {
			type: 'FeatureCollection',
			features: [
				{
					type: 'Feature',
					geometry: { type: 'Point', coordinates: [ 1, 2 ] },
					properties: {},
				},
			],
		};

		expect( computeLineMidpoint( geojson ) ).toBeNull();
	} );

	it( 'returns the lone point for a single-point LineString', () => {
		const geojson: GeoJSON.FeatureCollection = {
			type: 'FeatureCollection',
			features: [
				{
					type: 'Feature',
					geometry: {
						type: 'LineString',
						coordinates: [ [ 12.0, 58.0 ] ],
					},
					properties: {},
				},
			],
		};

		expect( computeLineMidpoint( geojson ) ).toEqual( {
			lat: 58.0,
			lon: 12.0,
		} );
	} );

	it( 'returns the segment midpoint for a two-point LineString', () => {
		// Two points spread over a small east-west span at the same latitude
		// keep the great-circle arc close enough to a straight line that the
		// midpoint in latitude is at the segment's centre. The test compares
		// against a known-good interpolation rather than re-implementing the
		// Haversine math.
		const geojson: GeoJSON.FeatureCollection = {
			type: 'FeatureCollection',
			features: [
				{
					type: 'Feature',
					geometry: {
						type: 'LineString',
						coordinates: [
							[ 11.0, 57.0 ],
							[ 13.0, 57.0 ],
						],
					},
					properties: {},
				},
			],
		};

		const midpoint = computeLineMidpoint( geojson );
		expect( midpoint ).not.toBeNull();
		// East-west segment at the same latitude — midpoint sits on the
		// constant-latitude line at the longitudinal centre. Allow a small
		// epsilon for floating-point accumulation in the Haversine pass.
		expect( midpoint!.lat ).toBeCloseTo( 57.0, 6 );
		expect( midpoint!.lon ).toBeCloseTo( 12.0, 4 );
	} );

	it( 'walks the chain to find the 0.5-fraction segment for many-point LineStrings', () => {
		// A symmetric V-shape: the midpoint by cumulative distance sits at
		// the apex (the geometric midpoint of the second segment). Four
		// equal-length segments make the total distance trivially divisible
		// by two, so the midpoint lands exactly on the third point.
		const geojson: GeoJSON.FeatureCollection = {
			type: 'FeatureCollection',
			features: [
				{
					type: 'Feature',
					geometry: {
						type: 'LineString',
						coordinates: [
							[ 0.0, 0.0 ],
							[ 1.0, 0.0 ],
							[ 2.0, 0.0 ],
							[ 3.0, 0.0 ],
							[ 4.0, 0.0 ],
						],
					},
					properties: {},
				},
			],
		};

		const midpoint = computeLineMidpoint( geojson );
		expect( midpoint ).not.toBeNull();
		expect( midpoint!.lat ).toBeCloseTo( 0.0, 6 );
		expect( midpoint!.lon ).toBeCloseTo( 2.0, 4 );
	} );

	it( 'returns the first point when all coordinates are colocated', () => {
		const geojson: GeoJSON.FeatureCollection = {
			type: 'FeatureCollection',
			features: [
				{
					type: 'Feature',
					geometry: {
						type: 'LineString',
						coordinates: [
							[ 10.0, 50.0 ],
							[ 10.0, 50.0 ],
							[ 10.0, 50.0 ],
						],
					},
					properties: {},
				},
			],
		};

		expect( computeLineMidpoint( geojson ) ).toEqual( {
			lat: 50.0,
			lon: 10.0,
		} );
	} );
} );

describe( 'pickPreviewAnchor', () => {
	it( 'prefers the first waypoint when one exists', () => {
		const geojson: GeoJSON.FeatureCollection = {
			type: 'FeatureCollection',
			features: [
				{
					type: 'Feature',
					geometry: {
						type: 'LineString',
						coordinates: [
							[ 0.0, 0.0 ],
							[ 10.0, 0.0 ],
						],
					},
					properties: {},
				},
				{
					type: 'Feature',
					geometry: { type: 'Point', coordinates: [ 4.0, 2.0 ] },
					properties: { name: 'Wp', desc: '' },
				},
			],
		};

		expect( pickPreviewAnchor( geojson ) ).toEqual( {
			lat: 2.0,
			lon: 4.0,
		} );
	} );

	it( 'falls back to the polyline midpoint when no waypoints exist', () => {
		const geojson: GeoJSON.FeatureCollection = {
			type: 'FeatureCollection',
			features: [
				{
					type: 'Feature',
					geometry: {
						type: 'LineString',
						coordinates: [
							[ 0.0, 0.0 ],
							[ 10.0, 0.0 ],
						],
					},
					properties: {},
				},
			],
		};

		const anchor = pickPreviewAnchor( geojson );
		expect( anchor ).not.toBeNull();
		expect( anchor!.lat ).toBeCloseTo( 0.0, 6 );
		expect( anchor!.lon ).toBeCloseTo( 5.0, 4 );
	} );

	it( 'returns null when neither a waypoint nor a LineString is available', () => {
		expect(
			pickPreviewAnchor( {
				type: 'FeatureCollection',
				features: [],
			} )
		).toBeNull();
	} );
} );

describe( 'MapEditorPreview tooltip preview anchoring (issue #92)', () => {
	it( 'positions the floating tooltip near the first waypoint and re-projects on map move/zoom', async () => {
		const attributes = buildAttributes( {
			tooltipShowName: true,
			tooltipShowDesc: true,
		} );
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

		// Resolve the REST payload with one waypoint at a known position.
		// The mocked Leaflet map projects [lat, lon] to (lat*10, lon*10) so
		// the resulting pixel coordinates are deterministic.
		await act( async () => {
			mockApiFetchState.resolve!( {
				geojson: {
					type: 'FeatureCollection',
					features: [
						{
							type: 'Feature',
							geometry: {
								type: 'LineString',
								coordinates: [
									[ 11.0, 57.0 ],
									[ 11.1, 57.1 ],
								],
							},
							properties: {},
						},
						{
							type: 'Feature',
							geometry: {
								type: 'Point',
								coordinates: [ 12.0, 58.0 ],
							},
							properties: {
								name: 'Lookout',
								desc: 'Nice view',
							},
						},
					],
				},
			} );
		} );

		expect( mockLeafletState.mapInstances ).toHaveLength( 1 );
		const { instance: map } = mockLeafletState.mapInstances[ 0 ];

		// The projection effect calls `latLngToContainerPoint([58, 12])`
		// → (580, 120). The component adds the small anchor offsets (+10
		// horizontal, -8 vertical) so the tooltip sits next to the marker
		// instead of on top of it.
		expect( map.latLngToContainerPoint ).toHaveBeenCalledWith( [
			58.0, 12.0,
		] );

		const tooltipEl = container.querySelector(
			'.kntnt-gpx-blocks-tooltip-preview'
		);
		expect( tooltipEl ).not.toBeNull();
		const styledEl = tooltipEl as HTMLElement;
		expect( styledEl.style.left ).toBe( '590px' );
		expect( styledEl.style.top ).toBe( '112px' );

		// Both rows are present because tooltipShowName / tooltipShowDesc
		// are on; the sample text falls back to the first waypoint's
		// properties.
		expect(
			tooltipEl!.querySelector( '.kntnt-gpx-blocks-tooltip-name' )
				?.textContent
		).toBe( 'Lookout' );
		expect(
			tooltipEl!.querySelector( '.kntnt-gpx-blocks-tooltip-desc' )
				?.textContent
		).toBe( 'Nice view' );

		// The component must register handlers for both `move` and `zoom`
		// so panning or zooming the editor map keeps the tooltip pinned to
		// its geographic anchor.
		const moveHandlers = map.handlers.get( 'move' );
		const zoomHandlers = map.handlers.get( 'zoom' );
		expect( moveHandlers ).toBeDefined();
		expect( zoomHandlers ).toBeDefined();
		expect( moveHandlers!.length ).toBeGreaterThan( 0 );
		expect( zoomHandlers!.length ).toBeGreaterThan( 0 );

		// Simulate a pan that bumps the projected position. Swap the
		// projector for a fresh return value, fire the move handler, and
		// assert the component re-projected.
		map.latLngToContainerPoint.mockReturnValueOnce( { x: 100, y: 50 } );
		await act( async () => {
			for ( const handler of moveHandlers! ) {
				handler();
			}
		} );
		expect( styledEl.style.left ).toBe( '110px' );
		expect( styledEl.style.top ).toBe( '42px' );

		// Simulate a zoom and verify re-projection happens through the same
		// path as a pan — both events feed the same projector.
		map.latLngToContainerPoint.mockReturnValueOnce( { x: 200, y: 80 } );
		await act( async () => {
			for ( const handler of zoomHandlers! ) {
				handler();
			}
		} );
		expect( styledEl.style.left ).toBe( '210px' );
		expect( styledEl.style.top ).toBe( '72px' );

		await act( async () => {
			root.unmount();
		} );
		container.remove();
	} );

	it( 'falls back to the polyline midpoint when no waypoints exist', async () => {
		const attributes = buildAttributes( {
			tooltipShowName: true,
			tooltipShowDesc: false,
		} );
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

		// A LineString from [0, 0] to [10, 0] has its midpoint at lon=5.
		await act( async () => {
			mockApiFetchState.resolve!( {
				geojson: {
					type: 'FeatureCollection',
					features: [
						{
							type: 'Feature',
							geometry: {
								type: 'LineString',
								coordinates: [
									[ 0.0, 0.0 ],
									[ 10.0, 0.0 ],
								],
							},
							properties: {},
						},
					],
				},
			} );
		} );

		const { instance: map } = mockLeafletState.mapInstances[ 0 ];
		const projectionCalls = ( map.latLngToContainerPoint as jest.Mock ).mock
			.calls;
		expect( projectionCalls.length ).toBeGreaterThan( 0 );

		// The first argument to latLngToContainerPoint is the midpoint's
		// [lat, lon] pair. Latitude is essentially 0 (allow a tiny epsilon
		// for floating-point), longitude is essentially 5.
		const [ lat, lon ] = projectionCalls[ 0 ][ 0 ] as [ number, number ];
		expect( lat ).toBeCloseTo( 0.0, 6 );
		expect( lon ).toBeCloseTo( 5.0, 4 );

		await act( async () => {
			root.unmount();
		} );
		container.remove();
	} );

	it( 'hides the description row when tooltipShowDesc is off', async () => {
		const attributes = buildAttributes( {
			tooltipShowName: true,
			tooltipShowDesc: false,
		} );
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
			mockApiFetchState.resolve!( {
				geojson: {
					type: 'FeatureCollection',
					features: [
						{
							type: 'Feature',
							geometry: {
								type: 'Point',
								coordinates: [ 1.0, 2.0 ],
							},
							properties: { name: 'Wp', desc: 'Hidden' },
						},
					],
				},
			} );
		} );

		const tooltipEl = container.querySelector(
			'.kntnt-gpx-blocks-tooltip-preview'
		);
		expect( tooltipEl ).not.toBeNull();
		expect(
			tooltipEl!.querySelector( '.kntnt-gpx-blocks-tooltip-name' )
		).not.toBeNull();
		expect(
			tooltipEl!.querySelector( '.kntnt-gpx-blocks-tooltip-desc' )
		).toBeNull();

		await act( async () => {
			root.unmount();
		} );
		container.remove();
	} );

	it( 'hides the name row when tooltipShowName is off', async () => {
		const attributes = buildAttributes( {
			tooltipShowName: false,
			tooltipShowDesc: true,
		} );
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
			mockApiFetchState.resolve!( {
				geojson: {
					type: 'FeatureCollection',
					features: [
						{
							type: 'Feature',
							geometry: {
								type: 'Point',
								coordinates: [ 1.0, 2.0 ],
							},
							properties: { name: 'Hidden', desc: 'Shown' },
						},
					],
				},
			} );
		} );

		const tooltipEl = container.querySelector(
			'.kntnt-gpx-blocks-tooltip-preview'
		);
		expect( tooltipEl ).not.toBeNull();
		expect(
			tooltipEl!.querySelector( '.kntnt-gpx-blocks-tooltip-name' )
		).toBeNull();
		expect(
			tooltipEl!.querySelector( '.kntnt-gpx-blocks-tooltip-desc' )
		).not.toBeNull();

		await act( async () => {
			root.unmount();
		} );
		container.remove();
	} );
} );
