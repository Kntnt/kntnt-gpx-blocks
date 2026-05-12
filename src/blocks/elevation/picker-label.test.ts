/**
 * Unit tests for {@link pickerLabel}.
 *
 * Pins the three-tier label-resolution contract from Step 2 of
 * `docs/elevation-rebuild.md`:
 *
 *   1. `attributes.metadata.name` wins when present and non-whitespace.
 *   2. `attributes.anchor` is consulted when tier 1 is unset.
 *   3. `GPX Map #N` (translated) is the fallback.
 *
 * Edge cases:
 * - Whitespace-only names fall through to tier 2.
 * - Empty-string anchors fall through to tier 3.
 * - The index passed in surfaces verbatim in the fallback string.
 *
 * @since 1.0.0
 */

jest.mock(
	'@wordpress/i18n',
	() => ( {
		__esModule: true,
		__: ( s: string ) => s,
		sprintf: ( template: string, ...args: unknown[] ) =>
			template.replace( /%d/g, () => String( args.shift() ?? '' ) ),
	} ),
	{ virtual: true }
);

import { pickerLabel } from './picker-label';

describe( 'pickerLabel', () => {
	describe( 'tier 1 — metadata.name', () => {
		it( 'returns the name when present and non-empty', () => {
			expect(
				pickerLabel( { metadata: { name: 'Northern loop' } }, 1 )
			).toBe( 'Northern loop' );
		} );

		it( 'wins over a non-empty anchor', () => {
			expect(
				pickerLabel(
					{ metadata: { name: 'Northern loop' }, anchor: 'route-a' },
					3
				)
			).toBe( 'Northern loop' );
		} );

		it( 'falls through when the name is whitespace-only', () => {
			expect(
				pickerLabel(
					{ metadata: { name: '   ' }, anchor: 'route-a' },
					2
				)
			).toBe( 'route-a' );
		} );
	} );

	describe( 'tier 2 — anchor', () => {
		it( 'returns the anchor when the name is missing', () => {
			expect( pickerLabel( { anchor: 'route-a' }, 7 ) ).toBe( 'route-a' );
		} );

		it( 'falls through when the anchor is the empty string', () => {
			expect( pickerLabel( { anchor: '' }, 4 ) ).toBe( 'GPX Map #4' );
		} );
	} );

	describe( 'tier 3 — generic fallback', () => {
		it( 'returns "GPX Map #N" when neither name nor anchor is set', () => {
			expect( pickerLabel( {}, 1 ) ).toBe( 'GPX Map #1' );
			expect( pickerLabel( {}, 12 ) ).toBe( 'GPX Map #12' );
		} );

		it( 'uses the 1-based index verbatim', () => {
			expect( pickerLabel( {}, 99 ) ).toBe( 'GPX Map #99' );
		} );
	} );
} );
