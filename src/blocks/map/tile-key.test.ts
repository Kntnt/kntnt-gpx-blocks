/**
 * Jest tests for the GPX Map editor's `{KEY}` substitution helper.
 *
 * The helper is the editor-side mirror of `Tile_Layer_Registry::resolve_provider()`'s
 * substitution; the same tests would translate to PHP almost line for line.
 *
 * @since 1.0.0
 */

import { substituteTileApiKey } from './tile-key';

describe( 'substituteTileApiKey', () => {
	it( 'replaces a single {KEY} occurrence with the supplied key', () => {
		expect(
			substituteTileApiKey(
				'https://tile.example.com/{z}/{x}/{y}.png?apikey={KEY}',
				'ABC123'
			)
		).toBe( 'https://tile.example.com/{z}/{x}/{y}.png?apikey=ABC123' );
	} );

	it( 'returns the URL verbatim when there is no {KEY} placeholder', () => {
		const free = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
		expect( substituteTileApiKey( free, 'ignored' ) ).toBe( free );
	} );

	it( 'replaces every {KEY} occurrence when the placeholder appears more than once', () => {
		expect(
			substituteTileApiKey(
				'https://example.com/{KEY}/tiles?token={KEY}',
				'X'
			)
		).toBe( 'https://example.com/X/tiles?token=X' );
	} );

	it( 'substitutes an empty key without throwing — the documented behaviour for empty paid keys', () => {
		expect(
			substituteTileApiKey(
				'https://tile.example.com/{z}/{x}/{y}.png?apikey={KEY}',
				''
			)
		).toBe( 'https://tile.example.com/{z}/{x}/{y}.png?apikey=' );
	} );

	it( 'does not URL-encode the key — the helper is intentionally permissive', () => {
		expect(
			substituteTileApiKey( 'https://example.com/?token={KEY}', 'a b/c' )
		).toBe( 'https://example.com/?token=a b/c' );
	} );
} );
