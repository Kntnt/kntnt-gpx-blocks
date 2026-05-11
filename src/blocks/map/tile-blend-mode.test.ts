/**
 * Regression tests for the OpenWeatherMap overlay rendering fix (issue #111).
 *
 * Leaflet 1.9.4 added `.leaflet-container img.leaflet-tile { mix-blend-mode:
 * plus-lighter }` to mask sub-pixel tile seams in Chromium (PR
 * Leaflet/Leaflet#8891). The rule applies cross-layer inside the shared
 * `.leaflet-tile-pane` stacking context, so an overlay tile is additively
 * blended with the base tile underneath it. Over a bright base (e.g.
 * OpenStreetMap) the OpenWeatherMap clouds, precipitation, pressure,
 * temperature, and wind overlays saturate to white and become invisible
 * (issue #111). The plugin restores standard alpha compositing by overriding
 * the blend mode to `normal` on map containers it controls.
 *
 * These tests read the **built** CSS files (`build/blocks/map/style-index.css`
 * for the editor / shared styles and `build/blocks/map/view.css` for the
 * frontend bundle) and assert:
 *
 * 1. Leaflet's bundled `plus-lighter` rule is still present in `view.css` —
 *    a sanity check guarding against a future Leaflet upgrade silently
 *    dropping the workaround and making the override below redundant or
 *    targeted at a stale selector.
 * 2. The plugin's `.kntnt-gpx-blocks-map .leaflet-container img.leaflet-tile`
 *    rule sets `mix-blend-mode: normal` — the actual fix. The selector is
 *    deliberately scoped to the plugin's wrapper class so any other Leaflet
 *    map on the same page keeps Leaflet's default behaviour.
 *
 * Co-located with the rest of the map block tests (per
 * `docs/coding-standards.md`); compiled-CSS reads use Node's `fs` rather
 * than Webpack imports so the test exercises the actual artefact users ship.
 *
 * @since 1.0.0
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Resolves a path relative to the project root.
 *
 * Jest's working directory is the project root when run via
 * `wp-scripts test-unit-js`, but the helper makes the resolution explicit
 * so a future test runner change does not silently break the file lookup.
 *
 * @param relative - Path relative to the project root.
 * @return Absolute path on disk.
 */
function fromProjectRoot( relative: string ): string {
	return join( __dirname, '..', '..', '..', relative );
}

/**
 * Reads a built CSS file from `build/blocks/map/` and returns its contents.
 *
 * @param filename - File name within `build/blocks/map/`.
 * @return UTF-8 text contents of the file.
 */
function readBuiltMapCss( filename: string ): string {
	return readFileSync(
		fromProjectRoot( `build/blocks/map/${ filename }` ),
		'utf8'
	);
}

describe( 'GPX Map block CSS overrides Leaflet plus-lighter blend mode (issue #111)', () => {
	it( 'view.css ships Leaflet 1.9.x plus-lighter rule on .leaflet-container img.leaflet-tile so the override below has a real rule to neutralise', () => {
		const css = readBuiltMapCss( 'view.css' );
		expect( css ).toMatch(
			/\.leaflet-container\s+img\.leaflet-tile\s*\{\s*mix-blend-mode:\s*plus-lighter\s*\}/
		);
	} );

	it( 'style-index.css sets mix-blend-mode: normal on plugin-scoped tile images so OpenWeatherMap overlays composite normally over the base', () => {
		const css = readBuiltMapCss( 'style-index.css' );
		expect( css ).toMatch(
			/\.kntnt-gpx-blocks-map\s+\.leaflet-container\s+img\.leaflet-tile\s*\{\s*mix-blend-mode:\s*normal\s*\}/
		);
	} );
} );
