/**
 * Block variation that surfaces the GPX Statistics layout in the block inserter.
 *
 * Registers a `kntnt-gpx-blocks-statistics` variation of `core/group` so the
 * statistics layout appears as a discoverable item under the `kntnt` block
 * category — same place visitors find GPX Map and GPX Elevation. Inserting
 * the variation materialises a 2x3 grid of label/value paragraph pairs whose
 * value paragraphs are bound to the `kntnt-gpx-blocks/statistics` Block
 * Bindings source.
 *
 * `scope: ['inserter']` keeps the variation out of the Group block's
 * placeholder picker — it appears only as a standalone inserter entry, so
 * unrelated `core/group` insertions are unaffected.
 *
 * The script is plain ES2022 and reads `window.wp.blocks` and `window.wp.i18n`
 * directly; no `@wordpress/scripts` build step is needed for ~80 lines.
 *
 * @since 1.0.0
 */

( function () {

	'use strict';

	const { registerBlockVariation } = window.wp.blocks;
	const { __ } = window.wp.i18n;

	/**
	 * Builds the markup for one label-and-value row.
	 *
	 * The English `name` strings (the row's English label and the literal
	 * `'Label'` / `'Value'` strings on the inner paragraphs) populate
	 * `metadata.name` so the editor's List View / Document Outline shows
	 * meaningful names instead of the generic *Group* / *Paragraph* labels.
	 * They are deliberately NOT translated through the plugin's text domain:
	 * `metadata.name` is editor-side metadata, and the Gutenberg convention —
	 * which Core itself follows — is to leave it as a fixed English string.
	 * The visitor-facing label content (the `<strong>…</strong>` paragraph)
	 * remains translated via the separate `translatedLabel` argument.
	 *
	 * @param {string}  englishName     Fixed English row name for `metadata.name`.
	 * @param {string}  translatedLabel Translated label text (without trailing colon).
	 * @param {string}  bindingKey      Statistics key passed to the bindings source.
	 * @param {boolean} columnSpan      When true, the row spans both columns of the grid.
	 * @return {Array} A nested innerBlocks tuple suitable for variation.innerBlocks.
	 */
	function row( englishName, translatedLabel, bindingKey, columnSpan ) {

		const groupAttrs = {
			metadata: { name: englishName },
			style: {
				spacing: { blockGap: '0.5em' },
			},
			layout: { type: 'flex', flexWrap: 'nowrap' },
		};

		if ( columnSpan ) {
			groupAttrs.style.layout = { columnSpan: 2 };
		}

		return [
			'core/group',
			groupAttrs,
			[
				[ 'core/paragraph', {
					content: '<strong>' + translatedLabel + ':</strong>',
					metadata: { name: 'Label' },
				} ],
				[ 'core/paragraph', {
					content: '',
					metadata: {
						name: 'Value',
						bindings: {
							content: {
								source: 'kntnt-gpx-blocks/statistics',
								args: { key: bindingKey },
							},
						},
					},
				} ],
			],
		];

	}

	registerBlockVariation( 'core/group', {
		name: 'kntnt-gpx-blocks-statistics',
		title: __( 'GPX Statistics', 'kntnt-gpx-blocks' ),
		description: __(
			'Two-column grid of total distance, min/max elevation, and total ascent and descent for a GPX track on the page.',
			'kntnt-gpx-blocks'
		),
		category: 'kntnt',
		icon: 'chart-bar',
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
			row( 'Lowest elevation',  __( 'Lowest elevation', 'kntnt-gpx-blocks' ), 'min_elevation', false ),
			row( 'Highest elevation', __( 'Highest elevation','kntnt-gpx-blocks' ), 'max_elevation', false ),
			row( 'Total ascent',      __( 'Total ascent',     'kntnt-gpx-blocks' ), 'ascent',        false ),
			row( 'Total descent',     __( 'Total descent',    'kntnt-gpx-blocks' ), 'descent',       false ),
		],
	} );

} )();
