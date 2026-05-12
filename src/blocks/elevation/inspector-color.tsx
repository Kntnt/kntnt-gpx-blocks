/**
 * Custom Color ToolsPanel for the GPX Elevation block.
 *
 * The Elevation block owns the entire colour surface itself instead of
 * delegating to core's Background block-support. The reason is alpha:
 * `supports.color.background` does not expose `enableAlpha`, but every
 * Elevation colour (background, plot line, cursor, axis, axis labels,
 * three tooltip colours) needs alpha support so editors can fade an
 * axis line or sit a tooltip on a translucent backdrop. Routing all
 * eight items through one plugin-owned `ToolsPanel` rendered in
 * `<InspectorControls group="styles">` keeps the surface uniform and
 * the alpha behaviour consistent across every colour the block exposes.
 *
 * Only `Background` is wired to actually affect the rendered output in
 * Step 1 (Step 3 picks up Axis, Step 4 Axis labels, Step 5 Plot line,
 * Step 6 Cursor, Step 7 the three Tooltip colours). The other items
 * persist their values so the editor's UI is functionally complete
 * from this step on, but their values do not yet reach the SVG.
 *
 * Pinned by `docs/elevation-rebuild.md`, Step 1, Rule 2 + the
 * eight-row Color table.
 *
 * @since 1.0.0
 */

import {
	ColorPicker,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToolsPanel as ToolsPanel,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToolsPanelItem as ToolsPanelItem,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * One row in the Color ToolsPanel.
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
 * Returns the eight Color panel rows in the order documented in the
 * Step 1 spec table. Order matters — it pins the ResetAll behaviour
 * and is the contract subsequent steps wire into.
 *
 * The function is exported so the Step 1 block.json shape test can
 * lock the attribute names against the spec table without duplicating
 * the list.
 *
 * @since 1.0.0
 *
 * @return Eight rows, top-to-bottom, in the order the editor displays.
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
	panelId: string;
}

/**
 * Reads a single colour attribute and coerces non-string values to the
 * empty string. The eight Color attributes are all `string` per the
 * Step 1 spec table; this helper keeps the per-row body simple.
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
 * Renders the eight-row Color ToolsPanel.
 *
 * Each row wraps a `ColorPicker` with `enableAlpha` so editors can
 * dial in `#RGBA` / `#RRGGBBAA` values; the `Color_Sanitizer` accepts
 * the same surface area on the render path. Reset (per-row or
 * ResetAll) writes the empty string, which the renderer treats as
 * "use the CSS default".
 *
 * @since 1.0.0
 *
 * @param props               See {@link InspectorColorPanelProps}.
 * @param props.attributes    Saved block attribute bag.
 * @param props.setAttributes Standard Gutenberg attribute setter.
 * @param props.panelId       Stable id used by ToolsPanel to scope its
 *                            per-item ResetAll behaviour.
 */
export function InspectorColorPanel( {
	attributes,
	setAttributes,
	panelId,
}: InspectorColorPanelProps ): JSX.Element {
	const rows = elevationColorRows();

	return (
		// @ts-ignore — ToolsPanel typings lag the runtime API.
		<ToolsPanel
			label={ __( 'Color', 'kntnt-gpx-blocks' ) }
			panelId={ panelId }
			resetAll={ () => {
				const wipe: Record< string, string > = {};
				for ( const row of rows ) {
					wipe[ row.attribute ] = '';
				}
				setAttributes( wipe );
			} }
		>
			{ rows.map( ( row ) => {
				const value = readColor( attributes, row.attribute );
				return (
					// @ts-ignore — ToolsPanelItem typings lag the runtime API.
					<ToolsPanelItem
						key={ row.attribute }
						panelId={ panelId }
						hasValue={ () => value !== '' }
						label={ row.label }
						onDeselect={ () =>
							setAttributes( { [ row.attribute ]: '' } )
						}
						isShownByDefault
					>
						<ColorPicker
							enableAlpha
							copyFormat="hex"
							color={ value || undefined }
							onChange={ ( next: string ) =>
								setAttributes( { [ row.attribute ]: next } )
							}
						/>
					</ToolsPanelItem>
				);
			} ) }
		</ToolsPanel>
	);
}
