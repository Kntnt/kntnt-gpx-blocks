/**
 * Pure helper that substitutes the `{KEY}` placeholder in a Leaflet tile URL
 * template with the per-block API key.
 *
 * Mirrors the server-side substitution `Tile_Layer_Registry::resolve_provider()`
 * performs before writing the URL into Interactivity state. Extracted as a
 * standalone module so it can be unit-tested via `wp-scripts test-unit-js`
 * without pulling in React or Leaflet.
 *
 * The helper is deliberately permissive: it does not URL-encode the key, does
 * not validate the URL shape, and does not warn on an empty key. A paid
 * provider with an empty key produces a URL that fails to load tiles
 * visually, which is the documented behaviour for the editor preview at this
 * stage of the project (see issue #79). Callers that need a richer contract
 * — missing-key warnings, polyline-only fallback — layer it on top of this
 * helper rather than inside it.
 *
 * @since 1.0.0
 */

/**
 * Replace every occurrence of the literal `{KEY}` placeholder in `url` with
 * `apiKey`.
 *
 * @since 1.0.0
 *
 * @param url    - Tile URL template, possibly containing `{KEY}`.
 * @param apiKey - Per-block API key. Empty string is a valid input — see the
 *               file-level note above.
 * @return URL with `{KEY}` replaced. The input is returned verbatim when no
 *         `{KEY}` placeholder is present.
 */
export function substituteTileApiKey( url: string, apiKey: string ): string {
	return url.split( '{KEY}' ).join( apiKey );
}
