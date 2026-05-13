/**
 * Plugin-owned Color panel for the GPX Elevation block.
 *
 * Routes the block's eight colour attributes through a single
 * `PanelColorSettings` rendered inside `<InspectorControls group="styles">`.
 * The surface matches GPX Map byte-for-byte: a compact list of swatch
 * rows that open a popover with the WordPress colour picker on click,
 * with `enableAlpha` switched on so editors can dial in `#RGBA` /
 * `#RRGGBBAA` values for every entry — including Background, which is
 * why this panel owns Background instead of delegating to core's
 * `supports.color.background` (that surface cannot enable alpha).
 *
 * Only `Background` is wired to actually affect the rendered output in
 * Step 1 (Step 3 picks up Axis, Step 4 Axis labels, Step 5 Plot line,
 * Step 6 Cursor, Step 7 the three Tooltip colours). The other items
 * persist their values so the editor surface is functionally complete
 * from this step on, but their values do not yet reach the SVG.
 *
 * Pinned by `docs/elevation-rebuild.md`, Step 1, Rule 2 + the
 * eight-row Color table.
 *
 * @since 1.0.0
 */

import { PanelColorSettings } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

/**
 * One row in the Color panel.
 *
 * `attribute` is the block-attribute key the row reads from and writes
 * to; `label` is the translated row label shown in the editor.
 *
 * @since 1.0.0
 */
interface ColorRow {
	readonly attribute: string;
	readonly label: string;
}

/**
 * Returns the nine Color panel rows in the order documented in the
 * Step 1 spec table — with the `Plot fill` row inserted by Step 5
 * directly after `Plot line` per the Step 5 amendment of the original
 * eight-row contract. Order matters — it is the contract subsequent
 * steps wire into and the order the editor displays.
 *
 * The function is exported so future tests (and the Step 8 Map
 * migration, if it ever consolidates the two surfaces) can lock the
 * attribute names against the spec table without duplicating the
 * list.
 *
 * @since 1.0.0
 *
 * @return Nine rows, top-to-bottom, in display order.
 */
export function elevationColorRows(): readonly ColorRow[] {
	return [
		{
			attribute: 'backgroundColor',
			label: __( 'Background', 'kntnt-gpx-blocks' ),
		},
		{
			attribute: 'plotLineColor',
			label: __( 'Plot line', 'kntnt-gpx-blocks' ),
		},
		{
			attribute: 'plotFillColor',
			label: __( 'Plot fill', 'kntnt-gpx-blocks' ),
		},
		{
			attribute: 'cursorColor',
			label: __( 'Cursor', 'kntnt-gpx-blocks' ),
		},
		{
			attribute: 'axisColor',
			label: __( 'Axis', 'kntnt-gpx-blocks' ),
		},
		{
			attribute: 'axisLabelColor',
			label: __( 'Axis labels', 'kntnt-gpx-blocks' ),
		},
		{
			attribute: 'tooltipBackgroundColor',
			label: __( 'Tooltip background', 'kntnt-gpx-blocks' ),
		},
		{
			attribute: 'tooltipDistanceColor',
			label: __( 'Tooltip distance', 'kntnt-gpx-blocks' ),
		},
		{
			attribute: 'tooltipHeightColor',
			label: __( 'Tooltip height', 'kntnt-gpx-blocks' ),
		},
	];
}

/**
 * Props for {@link InspectorColorPanel}.
 *
 * @since 1.0.0
 */
interface InspectorColorPanelProps {
	attributes: Record< string, unknown >;
	setAttributes: ( next: Record< string, unknown > ) => void;
}

/**
 * Reads a single colour attribute and coerces non-string values to the
 * empty string. The eight Color attributes are all `string` per the
 * Step 1 spec table; this helper keeps the per-row `colorSettings`
 * entry simple.
 *
 * @since 1.0.0
 *
 * @param attributes Saved block attribute bag.
 * @param key        Attribute name to read.
 * @return The attribute value, or `''` for missing / non-string entries.
 */
function readColor(
	attributes: Record< string, unknown >,
	key: string
): string {
	const value = attributes[ key ];
	return typeof value === 'string' ? value : '';
}

/**
 * Renders the eight-row Color panel.
 *
 * @since 1.0.0
 *
 * @param props               See {@link InspectorColorPanelProps}.
 * @param props.attributes    Saved block attribute bag.
 * @param props.setAttributes Standard Gutenberg attribute setter.
 */
export function InspectorColorPanel( {
	attributes,
	setAttributes,
}: InspectorColorPanelProps ): JSX.Element {
	const rows = elevationColorRows();

	return (
		// @ts-ignore — PanelColorSettings is exported from @wordpress/block-editor
		// but its typings lag the runtime API (no enableAlpha in d.ts).
		<PanelColorSettings
			title={ __( 'Color', 'kntnt-gpx-blocks' ) }
			enableAlpha
			colorSettings={ rows.map( ( row ) => ( {
				value: readColor( attributes, row.attribute ),
				onChange: ( value: string | undefined ) =>
					setAttributes( { [ row.attribute ]: value ?? '' } ),
				label: row.label,
			} ) ) }
		/>
	);
}
