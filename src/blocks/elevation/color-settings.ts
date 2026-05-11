/**
 * Color-panel entries for the Elevation block's Inspector.
 *
 * The PanelColorSettings component reads a flat `colorSettings` array of
 * `{ label, value, onChange }` entries — one per editable colour. The list
 * lives here so `edit.tsx` can stay a thin orchestrator: each row maps a
 * single attribute to its label and setter shape in one place, and the
 * order of entries is the order rendered.
 *
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';

/**
 * One row in the PanelColorSettings list.
 *
 * Mirrors the shape `@wordpress/block-editor`'s `PanelColorSettings`
 * expects. The component's typings lag behind so the surface is described
 * locally rather than re-imported.
 *
 * @since 1.0.0
 */
export interface ColorSettingEntry {
	readonly label: string;
	readonly value: string;
	readonly onChange: ( value: string | undefined ) => void;
}

/**
 * The subset of Elevation attributes the Color panel binds to.
 *
 * @since 1.0.0
 */
export interface ColorAttributes {
	readonly axisColor: string;
	readonly axisLabelColor: string;
	readonly lineColor: string;
	readonly cursorColor: string;
	readonly tooltipBackground: string;
	readonly tooltipColor: string;
}

/**
 * Attribute setter accepting a partial of `ColorAttributes`.
 *
 * @since 1.0.0
 */
export type SetColorAttributes = ( attrs: Partial< ColorAttributes > ) => void;

/**
 * Build the `colorSettings` array for `PanelColorSettings`.
 *
 * Each entry's `onChange` writes the (possibly-undefined) picker value back
 * to its corresponding attribute, coercing `undefined` to the empty string
 * so the attribute stays a stable `string` for downstream consumers.
 *
 * @since 1.0.0
 *
 * @param colors        Current colour attribute values.
 * @param setAttributes Block attribute setter.
 * @return The color-panel entries, in render order.
 */
export function buildColorSettings(
	colors: ColorAttributes,
	setAttributes: SetColorAttributes
): ColorSettingEntry[] {
	return [
		{
			label: __( 'Axis lines', 'kntnt-gpx-blocks' ),
			value: colors.axisColor,
			onChange: ( value ) => setAttributes( { axisColor: value ?? '' } ),
		},
		{
			label: __( 'Axis labels', 'kntnt-gpx-blocks' ),
			value: colors.axisLabelColor,
			onChange: ( value ) =>
				setAttributes( { axisLabelColor: value ?? '' } ),
		},
		{
			label: __( 'Elevation line', 'kntnt-gpx-blocks' ),
			value: colors.lineColor,
			onChange: ( value ) => setAttributes( { lineColor: value ?? '' } ),
		},
		{
			label: __( 'Cursor', 'kntnt-gpx-blocks' ),
			value: colors.cursorColor,
			onChange: ( value ) =>
				setAttributes( { cursorColor: value ?? '' } ),
		},
		{
			label: __( 'Tooltip background', 'kntnt-gpx-blocks' ),
			value: colors.tooltipBackground,
			onChange: ( value ) =>
				setAttributes( { tooltipBackground: value ?? '' } ),
		},
		{
			label: __( 'Tooltip text', 'kntnt-gpx-blocks' ),
			value: colors.tooltipColor,
			onChange: ( value ) =>
				setAttributes( { tooltipColor: value ?? '' } ),
		},
	];
}
