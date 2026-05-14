/**
 * Data Source inspector panel for the GPX Elevation block.
 *
 * Visible only when the page has ≥ 2 configured GPX Map blocks, OR the
 * current binding is broken AND ≥ 1 configured Map remains. The
 * conditional sits in `edit.tsx`, which decides whether to mount this
 * component at all; the component itself simply renders the panel when
 * invoked.
 *
 * **Broken-binding placeholder option.**
 * When the binding is broken (`bindingBroken === true`), the picker
 * prepends a synthetic empty-value placeholder option labelled
 * "— Select a GPX Map —". Without it, a native `<select>` with a
 * `value` that does not match any option falls back to displaying the
 * **first** option as selected, which (a) misleads the user into
 * thinking the first remaining Map is already bound, and (b) swallows
 * the click on that option because the displayed selection does not
 * change. The placeholder repairs both symptoms — the displayed
 * selection is the empty placeholder, and a click on any real option
 * fires `onChange` because the displayed selection does change.
 *
 * @since 1.0.0
 */

import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import type { MapPickerOption } from './use-map-blocks';

/**
 * Props for {@link InspectorDataSource}.
 *
 * @since 1.0.0
 */
export interface InspectorDataSourceProps {
	readonly mapId: string;
	readonly mapOptions: readonly MapPickerOption[];
	readonly bindingBroken: boolean;
	readonly onChange: ( value: string ) => void;
}

/**
 * Renders the Data Source `PanelBody` containing a `SelectControl`.
 *
 * The component is render-only — the visibility decision lives in
 * `edit.tsx` (see `shouldShowDataSourcePanel`).
 *
 * @since 1.0.0
 *
 * @param props See {@link InspectorDataSourceProps}.
 */
export function InspectorDataSource(
	props: InspectorDataSourceProps
): JSX.Element {
	const { mapId, mapOptions, bindingBroken, onChange } = props;

	// See file-level docblock for why the broken-binding state prepends
	// a synthetic placeholder option. The placeholder's empty value
	// matches the bindingBroken sentinel (`mapId` value is stale and
	// won't match anything else either), so the rendered selection lands
	// on the placeholder and a click on any real option produces a
	// genuine onChange event.
	const optionsForControl = bindingBroken
		? [
				{
					label: __( '— Select a GPX Map —', 'kntnt-gpx-blocks' ),
					value: '',
				},
				...mapOptions,
		  ]
		: mapOptions;

	return (
		<InspectorControls>
			<PanelBody title={ __( 'Data Source', 'kntnt-gpx-blocks' ) }>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Map', 'kntnt-gpx-blocks' ) }
					value={ mapId }
					options={ optionsForControl.map( ( option ) => ( {
						label: option.label,
						value: option.value,
					} ) ) }
					onChange={ onChange }
				/>
			</PanelBody>
		</InspectorControls>
	);
}

/**
 * Determines whether the Data Source panel should be rendered for the
 * given binding state.
 *
 * Picker visibility rule:
 *
 *   - ≥ 2 configured Maps           → show the panel.
 *   - Binding broken AND ≥ 1 Map    → show the panel.
 *   - Exactly 1 Map, healthy        → hide (nothing to choose).
 *   - 0 configured Maps             → hide (nothing to choose from).
 *
 * The function is pure so it can be exercised independently of the
 * React surface.
 *
 * @since 1.0.0
 *
 * @param configuredCount Number of configured Map blocks on the page.
 * @param bindingBroken   Whether the current `mapId` fails to match any
 *                        configured Map (deleted, deconfigured, or its
 *                        `mapId` was changed away).
 * @return Whether the panel should be rendered.
 */
export function shouldShowDataSourcePanel(
	configuredCount: number,
	bindingBroken: boolean
): boolean {
	if ( configuredCount >= 2 ) {
		return true;
	}
	if ( bindingBroken && configuredCount >= 1 ) {
		return true;
	}
	return false;
}
