/**
 * Layout-rule tests for the Elevation block's shared stylesheet.
 *
 * Pins five CSS contracts that together fix the user-reported
 * regressions where (a) the editor wrapper retains its previous
 * aspect-ratio after the user picks "Original" again, (b) a freshly
 * inserted block renders at 150 px instead of 15vh, (c) the chart
 * does not grow when the user enlarges `min-height` in the inspector,
 * and (d) the user's `spacing.padding` band is visually swallowed by
 * the chart on the frontend.
 *
 * The first three symptoms have a single root cause: the chart SVG
 * sits in normal flow with `width: 100%; height: 100%`. Percentage
 * heights against a wrapper that has only `min-height` do not resolve
 * to a concrete pixel value, so the SVG falls back to its `viewBox`-
 * derived intrinsic ratio (300×150 by default, or whatever the
 * previous render's viewBox carried). The SVG's rendered box then
 * drags the wrapper into that shape via the normal in-flow sizing
 * rules.
 *
 * The Step 3 follow-up release fixed those three by absolutely
 * positioning the SVG with `inset: 0`. That introduced the fourth
 * symptom: an absolutely-positioned element's containing block is its
 * nearest positioned ancestor's *padding-box*, so the SVG covered the
 * user's padding band on the frontend. (The editor's
 * `block-editor-block-list__block` wrapper sets `box-sizing: content-
 * box` and incidentally hides the symptom behind the aspect-ratio
 * geometry; the underlying bug is the same.)
 *
 * The fix is structural: make the wrapper a column flex container and
 * the SVG its single flex item with `flex: 1 1 0; min-width: 0;
 * min-height: 0`. A flex item respects the container's padding by
 * construction, and `min-*: 0` overrides the flex algorithm's
 * "do not shrink below intrinsic min-content" rule so the SVG's
 * viewBox-derived intrinsic dimensions can no longer drag the wrapper.
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
	it( 'makes the wrapper a column flex container so the chart SVG can be a flex item that respects padding', () => {
		// `display: flex; flex-direction: column` on the wrapper is what
		// gives the SVG a layout slot inside the wrapper's content box
		// rather than its padding-box. Without these the SVG would
		// either fall back to in-flow percentage-height resolution
		// (which fails on a min-height-only wrapper) or to absolute
		// positioning (which swallows the padding band).
		expect( SCSS ).toMatch(
			/\.kntnt-gpx-blocks-elevation[^{]*\{[\s\S]*?display:\s*flex/
		);
		expect( SCSS ).toMatch(
			/\.kntnt-gpx-blocks-elevation[^{]*\{[\s\S]*?flex-direction:\s*column/
		);
	} );

	it( 'grows the chart SVG to fill the wrapper via flex: 1 1 0', () => {
		// `flex: 1 1 0` (or any equivalent shorthand expanding to
		// flex-grow ≥ 1 with flex-basis 0) is what stretches the SVG
		// to occupy the wrapper's content-box height regardless of
		// whether that height comes from aspect-ratio, an explicit
		// user min-height, or the plugin's 15vh default.
		expect( SCSS ).toMatch(
			/\.kntnt-gpx-blocks-elevation-chart-svg[^{]*\{[\s\S]*?flex:\s*1\s+1\s+0/
		);
	} );

	it( 'overrides the flex min-content floor with min-width: 0 and min-height: 0 on the chart SVG', () => {
		// The crucial declarations that prevent the SVG's viewBox-
		// derived intrinsic dimensions from setting a floor on the
		// wrapper's height. Without these the three Step 3 follow-up
		// symptoms (stale aspect-ratio after Original-reset, freshly
		// inserted block at 300×150, frozen chart when min-height
		// grows) all return.
		expect( SCSS ).toMatch(
			/\.kntnt-gpx-blocks-elevation-chart-svg[^{]*\{[\s\S]*?min-width:\s*0/
		);
		expect( SCSS ).toMatch(
			/\.kntnt-gpx-blocks-elevation-chart-svg[^{]*\{[\s\S]*?min-height:\s*0/
		);
	} );

	it( 'sets the wrapper baseline to min-height: 15vh', () => {
		// The Step 3 wrapper baseline. The PHP filter and the editor
		// helper both inject the same value inline when the user has
		// not set their own minHeight; the SCSS is the cascade
		// fallback for browsers that bypass the inline path for any
		// reason.
		expect( SCSS ).toMatch( /min-height:\s*15vh/ );
	} );

	it( 'declares the Step 6 cursor colour default on the wrapper', () => {
		// `#d63638` is the Gutenberg red and matches Map's
		// `--kntnt-gpx-blocks-track-cursor-color` default in
		// `src/blocks/map/mount.ts`. Same default colour on both blocks
		// gives the synced cursors visual parity — the user perceives
		// them as "the same point on the track" without any inspector
		// configuration.
		expect( SCSS ).toMatch(
			/--kntnt-gpx-blocks-elevation-cursor:\s*#d63638/
		);
	} );

	it( 'gives the Step 6 cursor hit-rect touch-action: none and cursor: crosshair', () => {
		// `touch-action: none` keeps the browser from scrolling the page
		// during a touch-drag on the plot rectangle. `cursor: crosshair`
		// surfaces the scrubbing affordance to desktop users without a
		// custom SVG cursor.
		expect( SCSS ).toMatch(
			/\.kntnt-gpx-blocks-elevation-cursor-hitarea[^{]*\{[\s\S]*?touch-action:\s*none/
		);
		expect( SCSS ).toMatch(
			/\.kntnt-gpx-blocks-elevation-cursor-hitarea[^{]*\{[\s\S]*?cursor:\s*crosshair/
		);
	} );

	it( 'exposes the eight tick-label typography custom properties on the chart SVG with inherit fallback', () => {
		// Tick-label typography reaches both the visible <text>
		// labels and the measurer's hidden <text> nodes through CSS
		// inheritance from the chart SVG. Each of the eight CSS
		// properties is declared on `.kntnt-gpx-blocks-elevation-chart-svg`
		// as `var(--kntnt-gpx-blocks-elevation-tick-label-<prop>,
		// inherit)`. The fallback is `inherit` so an unset attribute
		// falls through to the wrapper's resolved typography rather
		// than to a hardcoded default.
		const props = [
			'font-family',
			'font-size',
			'font-weight',
			'font-style',
			'line-height',
			'letter-spacing',
			'text-transform',
			'text-decoration',
		];
		for ( const prop of props ) {
			const re = new RegExp(
				`\\.kntnt-gpx-blocks-elevation-chart-svg[^{]*\\{[\\s\\S]*?${ prop }:\\s*var\\(\\s*--kntnt-gpx-blocks-elevation-tick-label-${ prop }\\s*,\\s*inherit\\s*\\)`
			);
			expect( SCSS ).toMatch( re );
		}
	} );

	it( 'declares the Step 7 tooltip colour defaults on the wrapper', () => {
		// Tooltip colour defaults mirror Map's tooltip-bg / name-color /
		// desc-color so two synced tooltips read as visually consistent
		// without any inspector configuration.
		expect( SCSS ).toMatch(
			/--kntnt-gpx-blocks-elevation-tooltip-background:\s*#000000cc/
		);
		expect( SCSS ).toMatch(
			/--kntnt-gpx-blocks-elevation-tooltip-distance:\s*#ffffff/
		);
		expect( SCSS ).toMatch(
			/--kntnt-gpx-blocks-elevation-tooltip-height:\s*#dddddd/
		);
	} );

	it( 'gives the tooltip group pointer-events: none (Step 7)', () => {
		// The tooltip lives inside the SVG as a sibling of the cursor
		// group; without `pointer-events: none` it would intercept
		// hit-rect events and break the cursor scrub.
		expect( SCSS ).toMatch(
			/\.kntnt-gpx-blocks-elevation-tooltip[^-{][^{]*\{[\s\S]*?pointer-events:\s*none/
		);
	} );

	it( 'maps the tooltip background <rect> fill to the colour custom property', () => {
		expect( SCSS ).toMatch(
			/\.kntnt-gpx-blocks-elevation-tooltip-bg[^{]*\{[\s\S]*?fill:\s*var\(\s*--kntnt-gpx-blocks-elevation-tooltip-background\s*\)/
		);
	} );

	it( 'exposes the eight Tooltip distance typography custom properties with inherit fallback', () => {
		// Each of the eight CSS properties resolves through a per-row
		// custom property on the wrapper, with `inherit` as the fallback
		// so an unset Typography control falls through to the wrapper's
		// resolved typography.
		const props = [
			'font-family',
			'font-size',
			'font-weight',
			'font-style',
			'line-height',
			'letter-spacing',
			'text-transform',
			'text-decoration',
		];
		for ( const prop of props ) {
			const re = new RegExp(
				`\\.kntnt-gpx-blocks-elevation-tooltip-distance[^{]*\\{[\\s\\S]*?${ prop }:\\s*var\\(\\s*--kntnt-gpx-blocks-elevation-tooltip-distance-${ prop }\\s*,\\s*inherit\\s*\\)`
			);
			expect( SCSS ).toMatch( re );
		}
	} );

	it( 'exposes the eight Tooltip height typography custom properties with inherit fallback', () => {
		const props = [
			'font-family',
			'font-size',
			'font-weight',
			'font-style',
			'line-height',
			'letter-spacing',
			'text-transform',
			'text-decoration',
		];
		for ( const prop of props ) {
			const re = new RegExp(
				`\\.kntnt-gpx-blocks-elevation-tooltip-height[^{]*\\{[\\s\\S]*?${ prop }:\\s*var\\(\\s*--kntnt-gpx-blocks-elevation-tooltip-height-${ prop }\\s*,\\s*inherit\\s*\\)`
			);
			expect( SCSS ).toMatch( re );
		}
	} );
} );
