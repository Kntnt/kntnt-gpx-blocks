/**
 * Unit tests for the flattenPresets helper.
 *
 * Covers the three input shapes the helper must handle: nullish (no
 * setting), already-flat array, and origin-keyed object — plus the
 * priority order (custom → theme → default) when more than one origin
 * is populated.
 *
 * @since 0.4.1
 */

import { flattenPresets } from './flatten-presets';

interface TestPreset {
	slug: string;
}

describe( 'flattenPresets', () => {
	it( 'returns an empty array for undefined', () => {
		expect( flattenPresets< TestPreset >( undefined ) ).toEqual( [] );
	} );

	it( 'returns an empty array for null', () => {
		expect( flattenPresets< TestPreset >( null ) ).toEqual( [] );
	} );

	it( 'returns the input unchanged when it is already an array', () => {
		const presets: TestPreset[] = [ { slug: 'a' }, { slug: 'b' } ];
		expect( flattenPresets( presets ) ).toBe( presets );
	} );

	it( 'returns an empty array when the origin-keyed object has no buckets', () => {
		expect( flattenPresets< TestPreset >( {} ) ).toEqual( [] );
	} );

	it( 'flattens an origin-keyed object in custom → theme → default order', () => {
		const result = flattenPresets< TestPreset >( {
			default: [ { slug: 'd1' }, { slug: 'd2' } ],
			theme: [ { slug: 't1' } ],
			custom: [ { slug: 'c1' } ],
		} );

		expect( result.map( ( p ) => p.slug ) ).toEqual( [
			'c1',
			't1',
			'd1',
			'd2',
		] );
	} );

	it( 'tolerates partial origin-keyed objects', () => {
		expect(
			flattenPresets< TestPreset >( {
				theme: [ { slug: 't1' } ],
			} ).map( ( p ) => p.slug )
		).toEqual( [ 't1' ] );

		expect(
			flattenPresets< TestPreset >( {
				custom: [ { slug: 'c1' } ],
				default: [ { slug: 'd1' } ],
			} ).map( ( p ) => p.slug )
		).toEqual( [ 'c1', 'd1' ] );
	} );

	it( 'returns an empty array for unrelated values', () => {
		expect( flattenPresets< TestPreset >( 42 ) ).toEqual( [] );
		expect( flattenPresets< TestPreset >( 'string' ) ).toEqual( [] );
		expect( flattenPresets< TestPreset >( { unrelated: true } ) ).toEqual(
			[]
		);
	} );
} );
