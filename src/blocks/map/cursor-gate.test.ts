/**
 * Jest tests for the `maybeCreateCursorMarker` gate.
 *
 * The gate decides whether the track cursor marker is mounted at all,
 * driven by the `showTrackCursor` setting hydrated from PHP. When the
 * flag is `false` the helper must return `null` without calling
 * `L.circleMarker`, suppressing the Map-side reflection of the cursor
 * for editors who use the Map without a paired Elevation block.
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
	test( 'returns null and skips L.circleMarker when showTrackCursor is false', () => {
		const blockEl = createBlockEl();

		const cursor = maybeCreateCursorMarker(
			false,
			mapStub,
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

	test( 'returns a CircleMarker and calls L.circleMarker when showTrackCursor is true', () => {
		const blockEl = createBlockEl();

		const cursor = maybeCreateCursorMarker(
			true,
			mapStub,
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
} );
