/**
 * Unit tests for the usefulValue wrapper layer.
 *
 * The wrapper bridges the three-state attribute shape Gutenberg inspector
 * controls produce (missing, present-but-empty, present-and-non-default)
 * to the two-state contract the renderer needs (a usable value, plus a
 * presence flag that drives the ToolsPanelItem's hasValue/onDeselect).
 *
 * The tests pin the contract codified in `docs/elevation-rebuild.md`
 * (Step 1, *The "useful-value" wrapper layer*): default empty detection,
 * fallback substitution, set/reset behaviour, and the optional isEmpty
 * predicate override.
 *
 * @since 1.0.0
 */

import { usefulValue } from './useful-value';

describe( 'usefulValue', () => {
	let writes: Array< Record< string, unknown > >;
	let setAttributes: ( next: Record< string, unknown > ) => void;

	beforeEach( () => {
		writes = [];
		setAttributes = ( next ) => writes.push( next );
	} );

	describe( 'default empty detection', () => {
		it( 'treats undefined as empty and substitutes the fallback', () => {
			const result = usefulValue< string >(
				{},
				setAttributes,
				'foo',
				'fallback'
			);
			expect( result.raw ).toBeUndefined();
			expect( result.resolved ).toBe( 'fallback' );
			expect( result.hasValue ).toBe( false );
		} );

		it( 'treats empty string as empty and substitutes the fallback', () => {
			const result = usefulValue< string >(
				{ foo: '' },
				setAttributes,
				'foo',
				'fallback'
			);
			expect( result.raw ).toBe( '' );
			expect( result.resolved ).toBe( 'fallback' );
			expect( result.hasValue ).toBe( false );
		} );

		it( 'treats non-empty string as present and returns it verbatim', () => {
			const result = usefulValue< string >(
				{ foo: '#abc' },
				setAttributes,
				'foo',
				'fallback'
			);
			expect( result.raw ).toBe( '#abc' );
			expect( result.resolved ).toBe( '#abc' );
			expect( result.hasValue ).toBe( true );
		} );
	} );

	describe( 'set', () => {
		it( 'writes the given value under the configured key', () => {
			const result = usefulValue< string >(
				{},
				setAttributes,
				'foo',
				'fallback'
			);
			result.set( '#123456' );
			expect( writes ).toEqual( [ { foo: '#123456' } ] );
		} );
	} );

	describe( 'reset', () => {
		it( 'writes the empty string under the configured key', () => {
			const result = usefulValue< string >(
				{ foo: '#123' },
				setAttributes,
				'foo',
				'fallback'
			);
			result.reset();
			expect( writes ).toEqual( [ { foo: '' } ] );
		} );
	} );

	describe( 'custom isEmpty predicate', () => {
		it( 'uses the supplied predicate to detect emptiness', () => {
			const isEmptyZero = ( value: number | undefined ): boolean =>
				value === undefined || value === 0;
			const result = usefulValue< number >(
				{ count: 0 },
				setAttributes,
				'count',
				42,
				isEmptyZero
			);
			expect( result.hasValue ).toBe( false );
			expect( result.resolved ).toBe( 42 );
		} );

		it( 'considers values present that the default predicate would call empty', () => {
			const isEmptyNegative = ( value: number | undefined ): boolean =>
				value === undefined ||
				( typeof value === 'number' && value < 0 );
			const result = usefulValue< number >(
				{ x: 0 },
				setAttributes,
				'x',
				99,
				isEmptyNegative
			);
			expect( result.hasValue ).toBe( true );
			expect( result.resolved ).toBe( 0 );
		} );
	} );
} );
