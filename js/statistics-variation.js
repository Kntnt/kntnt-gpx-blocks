/**
 * Block variation that surfaces the GPX Statistics layout in the block inserter.
 *
 * Registers a `kntnt-gpx-blocks-statistics` variation of `core/group` so the
 * statistics layout appears as a discoverable item under the `kntnt` block
 * category — same place visitors find GPX Map and GPX Elevation. Inserting
 * the variation materialises a two-column grid of label/value paragraph rows;
 * each paragraph's `content` contains a `[kntnt-gpx <key>]` shortcode that
 * resolves at render time through `Bindings\Statistics_Shortcode`. The same
 * shortcode is equally usable outside the variation — in any paragraph,
 * heading, list item, classic block, or widget on the page.
 *
 * `scope: ['inserter']` keeps the variation out of the Group block's
 * placeholder picker — it appears only as a standalone inserter entry, so
 * unrelated `core/group` insertions are unaffected.
 *
 * The script is plain ES2022 and reads `window.wp.blocks`,
 * `window.wp.element`, and `window.wp.i18n` directly; no `@wordpress/scripts`
 * build step is needed for ~120 lines. `window.wp.element` is needed to
 * construct the inline SVG icon as React elements at runtime.
 *
 * @since 1.0.0
 */

( function () {

	'use strict';

	const { registerBlockVariation } = window.wp.blocks;
	const { __ } = window.wp.i18n;
	const { createElement } = window.wp.element;

	/**
	 * Inline SVG icon for the GPX Statistics variation.
	 *
	 * Three vertical bars of varying heights sitting on their own baseline,
	 * above a short winding track segment — a "metrics about a track" motif
	 * that distinguishes the variation from the generic `chart-bar` Dashicon.
	 * Drawn as `currentColor` strokes so the icon adapts to the editor's
	 * light/dark chrome and to selected/active states. The 24x24 viewBox,
	 * 1.5 stroke width, round caps/joins, and overall optical density match
	 * the GPX Map and GPX Elevation icons so the three read as one cohesive
	 * family in the inserter, List View, breadcrumb, and Document Outline.
	 *
	 * Authored as `wp.element.createElement` calls (not JSX) because this
	 * file ships through `wp_enqueue_script()` without going through the
	 * `@wordpress/scripts` build pipeline.
	 */
	const statisticsIcon = createElement(
		'svg',
		{
			xmlns: 'http://www.w3.org/2000/svg',
			viewBox: '0 0 24 24',
			width: 24,
			height: 24,
			fill: 'none',
			stroke: 'currentColor',
			strokeWidth: 1.5,
			strokeLinecap: 'round',
			strokeLinejoin: 'round',
			'aria-hidden': 'true',
			focusable: 'false',
		},
		createElement( 'path', { d: 'M4 14 H20' } ),
		createElement( 'path', { d: 'M6 14 V10' } ),
		createElement( 'path', { d: 'M12 14 V4' } ),
		createElement( 'path', { d: 'M18 14 V7' } ),
		createElement( 'path', { d: 'M3 20 C6.5 18.5 9.5 21 13 19 C16 17.5 18.5 19.5 21 18.5' } )
	);

	/**
	 * Builds the markup for one label-and-value row.
	 *
	 * Each row is a single `core/paragraph` whose `content` carries both the
	 * translated label (in `<strong>…</strong>`) and the inline shortcode
	 * `[kntnt-gpx <key>]`. The shortcode is processed by `do_shortcode()` at
	 * render time, so visitors see "Total length: 5.5 km" and editors see the
	 * same after the shortcode runs through the editor preview's content
	 * filter chain.
	 *
	 * The English `name` string populates `metadata.name` so the editor's
	 * List View / Document Outline shows a meaningful name instead of the
	 * generic *Paragraph* label. It is deliberately NOT translated through
	 * the plugin's text domain: `metadata.name` is editor-side metadata, and
	 * the Gutenberg convention — which Core itself follows — is to leave it
	 * as a fixed English string. The visitor-facing label remains translated.
	 *
	 * @param {string}  englishName     Fixed English row name for `metadata.name`.
	 * @param {string}  translatedLabel Translated label text (without trailing colon).
	 * @param {string}  shortcodeKey    Hyphenated key passed to `[kntnt-gpx …]`.
	 * @param {boolean} columnSpan      When true, the row spans both columns of the grid.
	 * @return {Array} A nested innerBlocks tuple suitable for variation.innerBlocks.
	 */
	function row( englishName, translatedLabel, shortcodeKey, columnSpan ) {

		const paragraphAttrs = {
			content:
				'<strong>' + translatedLabel + ':</strong> [kntnt-gpx ' + shortcodeKey + ']',
			metadata: { name: englishName },
		};

		if ( columnSpan ) {
			paragraphAttrs.style = { layout: { columnSpan: 2 } };
		}

		return [ 'core/paragraph', paragraphAttrs ];

	}

	registerBlockVariation( 'core/group', {
		name: 'kntnt-gpx-blocks-statistics',
		title: __( 'GPX Statistics', 'kntnt-gpx-blocks' ),
		description: __(
			'Two-column grid of total distance, min/max elevation, and total ascent and descent for a GPX track on the page.',
			'kntnt-gpx-blocks'
		),
		category: 'kntnt',
		icon: statisticsIcon,
		scope: [ 'inserter' ],
		keywords: [ 'gpx', 'statistics', 'distance', 'elevation', 'ascent', 'descent' ],
		attributes: {
			metadata: { name: 'GPX Statistics' },
			style: {
				spacing: { blockGap: 'var:preset|spacing|small' },
			},
			layout: { type: 'grid', columnCount: 2, minimumColumnWidth: null },
		},
		innerBlocks: [
			row( 'Total length',      __( 'Total length',     'kntnt-gpx-blocks' ), 'distance',      true  ),
			row( 'Lowest elevation',  __( 'Lowest elevation', 'kntnt-gpx-blocks' ), 'min-elevation', false ),
			row( 'Highest elevation', __( 'Highest elevation','kntnt-gpx-blocks' ), 'max-elevation', false ),
			row( 'Total ascent',      __( 'Total ascent',     'kntnt-gpx-blocks' ), 'ascent',        false ),
			row( 'Total descent',     __( 'Total descent',    'kntnt-gpx-blocks' ), 'descent',       false ),
		],
	} );

} )();
