/**
 * Unit tests for the editor-side `dimensions-defaults` normaliser
 * (issue #117).
 *
 * Mirrors the contract of the PHP `Dimensions_Defaults` filter on the
 * editor side: when both `style.dimensions.minHeight` and
 * `style.dimensions.aspectRatio` are blank or missing, return a copy
 * of the attribute object with `style.dimensions.minHeight` set to
 * the per-block default; otherwise return the attribute object unchanged
 * (referentially identical when nothing needs to change so the editor
 * useBlockProps memoisation does not break).
 *
 * @since 1.0.0
 */

import { normaliseDimensionsAttributes } from './dimensions-defaults';

describe( 'normaliseDimensionsAttributes (issue #117)', () => {
	it( 'C1: returns minHeight 30vh for Map when both attributes are blank', () => {
		const attrs = {} as Record< string, unknown >;
		const out = normaliseDimensionsAttributes(
			'kntnt-gpx-blocks/map',
			attrs
		);

		const dims = (
			out as { style?: { dimensions?: { minHeight?: string } } }
		 ).style?.dimensions;
		expect( dims?.minHeight ).toBe( '30vh' );
	} );

	it( 'C2: returns minHeight 15vh for Elevation when both attributes are blank', () => {
		const attrs = {} as Record< string, unknown >;
		const out = normaliseDimensionsAttributes(
			'kntnt-gpx-blocks/elevation',
			attrs
		);

		const dims = (
			out as { style?: { dimensions?: { minHeight?: string } } }
		 ).style?.dimensions;
		expect( dims?.minHeight ).toBe( '15vh' );
	} );

	it( 'C3: leaves an explicit minHeight unchanged', () => {
		const attrs = {
			style: { dimensions: { minHeight: '500px' } },
		} as Record< string, unknown >;

		const out = normaliseDimensionsAttributes(
			'kntnt-gpx-blocks/map',
			attrs
		);

		const dims = (
			out as { style?: { dimensions?: { minHeight?: string } } }
		 ).style?.dimensions;
		expect( dims?.minHeight ).toBe( '500px' );
	} );

	it( 'C4: leaves a non-Original aspectRatio alone and does not inject minHeight', () => {
		const attrs = {
			style: { dimensions: { aspectRatio: '16/9' } },
		} as Record< string, unknown >;

		const out = normaliseDimensionsAttributes(
			'kntnt-gpx-blocks/map',
			attrs
		);

		const dims = (
			out as {
				style?: {
					dimensions?: { minHeight?: string; aspectRatio?: string };
				};
			}
		 ).style?.dimensions;
		expect( dims?.minHeight ?? '' ).toBe( '' );
		expect( dims?.aspectRatio ).toBe( '16/9' );
	} );

	it( "strips aspectRatio='auto' and injects minHeight=30vh for Map", () => {
		const attrs = {
			style: { dimensions: { aspectRatio: 'auto' } },
		} as Record< string, unknown >;

		const out = normaliseDimensionsAttributes(
			'kntnt-gpx-blocks/map',
			attrs
		);

		const dims = (
			out as {
				style?: {
					dimensions?: { minHeight?: string; aspectRatio?: string };
				};
			}
		 ).style?.dimensions;
		expect( dims?.minHeight ).toBe( '30vh' );
		expect( dims ).not.toHaveProperty( 'aspectRatio' );
	} );

	it( "strips aspectRatio='auto' and injects minHeight=15vh for Elevation", () => {
		const attrs = {
			style: { dimensions: { aspectRatio: 'auto' } },
		} as Record< string, unknown >;

		const out = normaliseDimensionsAttributes(
			'kntnt-gpx-blocks/elevation',
			attrs
		);

		const dims = (
			out as {
				style?: {
					dimensions?: { minHeight?: string; aspectRatio?: string };
				};
			}
		 ).style?.dimensions;
		expect( dims?.minHeight ).toBe( '15vh' );
		expect( dims ).not.toHaveProperty( 'aspectRatio' );
	} );

	it( "strips aspectRatio='auto' but preserves an explicit user minHeight", () => {
		const attrs = {
			style: {
				dimensions: { minHeight: '500px', aspectRatio: 'auto' },
			},
		} as Record< string, unknown >;

		const out = normaliseDimensionsAttributes(
			'kntnt-gpx-blocks/map',
			attrs
		);

		const dims = (
			out as {
				style?: {
					dimensions?: { minHeight?: string; aspectRatio?: string };
				};
			}
		 ).style?.dimensions;
		expect( dims?.minHeight ).toBe( '500px' );
		expect( dims ).not.toHaveProperty( 'aspectRatio' );
	} );

	it( 'leaves the Original-after-toggle case (both fields literal "") to inject minHeight=30vh', () => {
		const attrs = {
			style: { dimensions: { minHeight: '', aspectRatio: '' } },
		} as Record< string, unknown >;

		const out = normaliseDimensionsAttributes(
			'kntnt-gpx-blocks/map',
			attrs
		);

		const dims = (
			out as { style?: { dimensions?: { minHeight?: string } } }
		 ).style?.dimensions;
		expect( dims?.minHeight ).toBe( '30vh' );
	} );

	it( 'returns the same reference (===) when nothing needs to change', () => {
		const attrs = {
			style: { dimensions: { minHeight: '500px' } },
		} as Record< string, unknown >;

		const out = normaliseDimensionsAttributes(
			'kntnt-gpx-blocks/map',
			attrs
		);

		// Stable reference is important so React's useBlockProps memo
		// inside the edit component does not see spurious style churn.
		expect( out ).toBe( attrs );
	} );

	it( 'does not mutate the input attribute object when it injects the default', () => {
		const attrs: Record< string, unknown > = {};

		normaliseDimensionsAttributes( 'kntnt-gpx-blocks/map', attrs );

		// The original argument stays untouched — the helper returns a
		// fresh object with the path filled in.
		expect( attrs ).toEqual( {} );
	} );

	it( 'leaves unknown block names untouched', () => {
		const attrs = {} as Record< string, unknown >;
		const out = normaliseDimensionsAttributes( 'core/paragraph', attrs );
		expect( out ).toBe( attrs );
	} );
} );
