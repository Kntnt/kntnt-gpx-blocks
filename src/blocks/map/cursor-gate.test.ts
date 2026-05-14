/**
 * Jest tests for the `maybeCreateCursorMarker` gate.
 *
 * The gate decides whether the track cursor marker is mounted at all,
 * driven by the `enableTrackPositionCursor` setting hydrated from PHP.
 * When the flag is `false` the helper must return `null` without
 * calling `L.circleMarker`, suppressing the Map-side reflection of the
 * cursor for editors who use the Map without a paired Elevation block.
 *
 * @since 0.13.5
 */

import L from 'leaflet';
import { maybeCreateCursorMarker } from './mount';

// Leaflet is mocked at the module boundary so the test runs without a
// real `L.Map`. Only the surface the helper touches is stubbed.
jest.mock( 'leaflet', () => {
	const circleMarker = jest.fn( ( latlng: unknown, options: unknown ) => ( {
		latlng,
		options,
		addTo: jest.fn().mockReturnThis(),
	} ) );

	return {
		__esModule: true,
		default: { circleMarker },
	};
} );

// Stub SVG renderer; only its presence in the circleMarker options
// matters for the z-order assertion below.
const svgRendererStub = {} as unknown as L.Renderer;

interface LeafletMock {
	circleMarker: jest.Mock;
}

beforeEach( () => {
	const mock = L as unknown as LeafletMock;
	mock.circleMarker.mockClear();
} );

// Stub block element so `getComputedStyle` returns an empty
// CSS-custom-property string and the helper falls back to its default
// cursor colour without dipping into a real iframe.
function createBlockEl(): HTMLElement {
	return document.createElement( 'div' );
}

// Minimal map stub — `createCursorMarker` only needs `addTo` on the
// circleMarker, so the map itself is opaque to the helper.
const mapStub = {} as unknown as L.Map;

describe( 'maybeCreateCursorMarker', () => {
	test( 'returns null and skips L.circleMarker when enableTrackPositionCursor is false', () => {
		const blockEl = createBlockEl();

		const cursor = maybeCreateCursorMarker(
			false,
			mapStub,
			svgRendererStub,
			[
				[ 0, 0 ],
				[ 1, 1 ],
			],
			[ 0, 1 ],
			1,
			blockEl
		);

		expect( cursor ).toBeNull();
		expect(
			( L as unknown as LeafletMock ).circleMarker
		).not.toHaveBeenCalled();
	} );

	test( 'returns a CircleMarker and calls L.circleMarker when enableTrackPositionCursor is true', () => {
		const blockEl = createBlockEl();

		const cursor = maybeCreateCursorMarker(
			true,
			mapStub,
			svgRendererStub,
			[
				[ 0, 0 ],
				[ 1, 1 ],
			],
			[ 0, 1 ],
			1,
			blockEl
		);

		expect( cursor ).not.toBeNull();
		expect(
			( L as unknown as LeafletMock ).circleMarker
		).toHaveBeenCalledTimes( 1 );
	} );

	test( 'forwards the supplied svgRenderer to L.circleMarker so cursor and waypoints share an SVG (Step 6 z-order fix)', () => {
		// The renderer carrying the cursor is what guarantees DOM
		// order = z-order between cursor and waypoints. Without the
		// `renderer` option, Leaflet falls back to the map's default
		// `L.canvas()` and the cursor lands in a separate <canvas>
		// element under <overlayPane>; since SVG paints on top of
		// canvas there, every waypoint marker visually obscures the
		// cursor whenever it scrubs through one. The fix is mechanical:
		// pass the same SVG renderer the waypoints use.
		const blockEl = createBlockEl();

		maybeCreateCursorMarker(
			true,
			mapStub,
			svgRendererStub,
			[
				[ 0, 0 ],
				[ 1, 1 ],
			],
			[ 0, 1 ],
			1,
			blockEl
		);

		const mock = L as unknown as LeafletMock;
		expect( mock.circleMarker ).toHaveBeenCalledTimes( 1 );
		const options = mock.circleMarker.mock.calls[ 0 ]?.[ 1 ] as {
			renderer?: unknown;
		};
		expect( options.renderer ).toBe( svgRendererStub );
	} );
} );
