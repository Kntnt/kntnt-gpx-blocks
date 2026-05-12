/**
 * Unit tests for {@link getDefaultMinHeight}.
 *
 * Pins both per-block rules:
 *
 *   - Map: gate is `minHeight` *and* `aspectRatio` both blank/missing;
 *     value is `'30vh'`. Setting either suppresses the default.
 *   - Elevation (Step 3 of docs/elevation-rebuild.md): gate is
 *     `minHeight` blank alone; value is `'15vh'`. A user-set
 *     `aspectRatio` does not suppress the default — the floor
 *     coexists with the ratio.
 *
 * @since 1.0.0
 */

import { getDefaultMinHeight } from './dimensions-defaults';

describe( 'getDefaultMinHeight (Map)', () => {
	it( 'returns 30vh when both fields are blank/missing', () => {
		expect( getDefaultMinHeight( 'kntnt-gpx-blocks/map', {} ) ).toBe(
			'30vh'
		);
	} );

	it( 'returns 30vh when both fields are present but empty strings', () => {
		expect(
			getDefaultMinHeight( 'kntnt-gpx-blocks/map', {
				style: { dimensions: { minHeight: '', aspectRatio: '' } },
			} )
		).toBe( '30vh' );
	} );

	it( 'returns undefined when minHeight is set', () => {
		expect(
			getDefaultMinHeight( 'kntnt-gpx-blocks/map', {
				style: { dimensions: { minHeight: '500px' } },
			} )
		).toBeUndefined();
	} );

	it( 'returns undefined when aspectRatio is set (other than the auto keyword)', () => {
		expect(
			getDefaultMinHeight( 'kntnt-gpx-blocks/map', {
				style: { dimensions: { aspectRatio: '16/9' } },
			} )
		).toBeUndefined();
	} );

	it( "treats aspectRatio='auto' as blank", () => {
		expect(
			getDefaultMinHeight( 'kntnt-gpx-blocks/map', {
				style: { dimensions: { aspectRatio: 'auto' } },
			} )
		).toBe( '30vh' );
	} );
} );

describe( 'getDefaultMinHeight (Elevation)', () => {
	it( 'returns 15vh when both fields are blank/missing', () => {
		expect( getDefaultMinHeight( 'kntnt-gpx-blocks/elevation', {} ) ).toBe(
			'15vh'
		);
	} );

	it( 'returns 15vh when minHeight is blank and aspectRatio is set', () => {
		// Step 3 rule: aspectRatio does NOT suppress the Elevation default.
		expect(
			getDefaultMinHeight( 'kntnt-gpx-blocks/elevation', {
				style: { dimensions: { aspectRatio: '4/1' } },
			} )
		).toBe( '15vh' );
	} );

	it( 'returns undefined when minHeight is set', () => {
		expect(
			getDefaultMinHeight( 'kntnt-gpx-blocks/elevation', {
				style: { dimensions: { minHeight: '200px' } },
			} )
		).toBeUndefined();
	} );
} );

describe( 'getDefaultMinHeight (unrelated block)', () => {
	it( 'returns undefined for any block not in the per-block table', () => {
		expect( getDefaultMinHeight( 'core/paragraph', {} ) ).toBeUndefined();
		expect(
			getDefaultMinHeight( 'core/cover', {
				style: { dimensions: { minHeight: '500px' } },
			} )
		).toBeUndefined();
	} );
} );
