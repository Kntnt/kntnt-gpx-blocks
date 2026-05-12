/**
 * Layout-rule tests for the Elevation block's shared stylesheet.
 *
 * Pins three CSS contracts that together fix the user-reported
 * regression where (a) the editor wrapper retains its previous
 * aspect-ratio after the user picks "Original" again, (b) a freshly
 * inserted block renders at 150 px instead of 15vh, and (c) the chart
 * does not grow when the user enlarges `min-height` in the inspector.
 *
 * All three symptoms have a single root cause: the chart SVG sits in
 * normal flow with `width: 100%; height: 100%`. Percentage heights
 * against a wrapper that has only `min-height` do not resolve to a
 * concrete pixel value, so the SVG falls back to its `viewBox`-derived
 * intrinsic ratio (300×150 by default, or whatever the previous
 * render's viewBox carried). The SVG's rendered box then drags the
 * wrapper into that shape via the normal in-flow sizing rules, which
 * is what produces all three symptoms.
 *
 * The fix is structural: position the SVG absolutely inside the
 * relatively-positioned wrapper, with `inset: 0` (and an explicit
 * `width/height: 100%` for SVG-as-replaced-element correctness). The
 * SVG then fills the wrapper's rendered content box without
 * contributing to its intrinsic size, so the wrapper's height is
 * determined purely by its own `min-height` / `aspect-ratio` /
 * user-set values.
 *
 * Reading the SCSS file directly is crude but sufficient — the rules
 * here are simple and brittle-to-regress, and an integration test in
 * WordPress Playground covers the live layout behaviour.
 *
 * @since 1.0.0
 */

import { readFileSync } from 'fs';
import { resolve } from 'path';

const SCSS = readFileSync( resolve( __dirname, 'style.scss' ), 'utf8' );

describe( 'elevation style.scss', () => {
	it( 'gives the wrapper position: relative so the absolutely-positioned chart anchors to it', () => {
		// The wrapper's positioning context is what the absolute SVG
		// resolves its inset against. Without `position: relative` on
		// the wrapper, the SVG would resolve against the nearest
		// positioned ancestor — typically `<body>` — and the chart
		// would render full-viewport.
		expect( SCSS ).toMatch(
			/\.kntnt-gpx-blocks-elevation[^{]*\{[\s\S]*?position:\s*relative/
		);
	} );

	it( 'positions the chart SVG absolutely to fill the wrapper without contributing to its intrinsic size', () => {
		// The crucial rule. Absolute positioning takes the SVG out of
		// normal flow so it no longer drags the wrapper into its
		// viewBox-derived shape after the user resets aspect-ratio to
		// Original.
		expect( SCSS ).toMatch(
			/\.kntnt-gpx-blocks-elevation-chart-svg[^{]*\{[\s\S]*?position:\s*absolute/
		);
	} );

	it( 'anchors the chart SVG with inset: 0 (or the four-edge equivalent)', () => {
		// `inset: 0` makes the SVG fill the wrapper's content box
		// regardless of whether the wrapper's height comes from
		// `min-height`, `aspect-ratio`, or an explicit `height`. The
		// four-edge alternative (`top: 0; right: 0; bottom: 0;
		// left: 0;`) is equivalent.
		const hasInsetShorthand =
			/\.kntnt-gpx-blocks-elevation-chart-svg[^{]*\{[\s\S]*?inset:\s*0/.test(
				SCSS
			);
		const hasFourEdges =
			/\.kntnt-gpx-blocks-elevation-chart-svg[^{]*\{[\s\S]*?top:\s*0[\s\S]*?left:\s*0/.test(
				SCSS
			);
		expect( hasInsetShorthand || hasFourEdges ).toBe( true );
	} );

	it( 'sets the wrapper baseline to min-height: 15vh', () => {
		// The Step 3 wrapper baseline. The PHP filter and the editor
		// helper both inject the same value inline when the user has
		// not set their own minHeight; the SCSS is the cascade
		// fallback for browsers that bypass the inline path for any
		// reason.
		expect( SCSS ).toMatch( /min-height:\s*15vh/ );
	} );
} );
