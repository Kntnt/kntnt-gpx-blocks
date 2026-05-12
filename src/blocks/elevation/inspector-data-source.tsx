/**
 * Data Source inspector panel for the GPX Elevation block.
 *
 * Conditionally rendered per the Step 2 spec
 * (`docs/elevation-rebuild.md`): visible only when the page has ≥ 2
 * configured GPX Map blocks, OR the current binding is broken AND
 * ≥ 1 configured Map remains. The conditional sits in `edit.tsx`,
 * which decides whether to mount this component at all; the
 * component itself simply renders the panel when invoked.
 *
 * The `SelectControl` shows one option per configured GPX Map block in
 * pre-order document traversal. A broken binding (the current `mapId`
 * does not match any picker entry) renders the control with no
 * matching value — the standard `SelectControl` default-behaviour. No
 * synthetic ghost entry is added.
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
	const { mapId, mapOptions, onChange } = props;

	return (
		<InspectorControls>
			<PanelBody title={ __( 'Data Source', 'kntnt-gpx-blocks' ) }>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Map', 'kntnt-gpx-blocks' ) }
					value={ mapId }
					options={ mapOptions.map( ( option ) => ( {
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
 * Pinned by the Step 2 rule
 * (`docs/elevation-rebuild.md`, *Picker visibility*):
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
