/**
 * Unit tests for {@link getDefaultMinHeight}.
 *
 * Issue #146 simplified Map's gate to match Elevation's so both blocks
 * share the same symmetric rule:
 *
 *   - Map: gate is `minHeight` blank alone; value is `'30vh'`. A
 *     user-set `aspectRatio` does not suppress the default — the floor
 *     coexists with the ratio via the normal CSS cascade.
 *   - Elevation (Step 3 of docs/elevation-rebuild.md): gate is
 *     `minHeight` blank alone; value is `'15vh'`. Same shape as Map.
 *
 * @since 1.0.0
 */

import { getDefaultMinHeight } from './dimensions-defaults';

describe( 'getDefaultMinHeight (Map)', () => {
	it( 'returns 30vh when minHeight is missing', () => {
		expect( getDefaultMinHeight( 'kntnt-gpx-blocks/map', {} ) ).toBe(
			'30vh'
		);
	} );

	it( 'returns 30vh when minHeight is present but an empty string', () => {
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

	it( 'returns 30vh when minHeight is blank and aspectRatio is set (issue #146)', () => {
		// Issue #146 lock: aspectRatio no longer suppresses the
		// Map default. The 30vh floor stacks alongside the user's
		// aspect ratio via the normal CSS cascade.
		expect(
			getDefaultMinHeight( 'kntnt-gpx-blocks/map', {
				style: { dimensions: { aspectRatio: '16/9' } },
			} )
		).toBe( '30vh' );
	} );

	it( "returns 30vh when minHeight is blank and aspectRatio='auto'", () => {
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
