/**
 * GPX Elevation block edit component — Step 1 of the rebuild.
 *
 * Renders the block's wrapper plus the full inspector surface fixed by
 * Step 1 of `docs/elevation-rebuild.md`:
 *
 *   Settings tab
 *     - Data Source        (empty SelectControl — wired in Step 2)
 *     - Tooltip info       (Show distance / Show height toggles)
 *   Styles tab
 *     - Core Dimensions    (declared via supports; no plugin code)
 *     - Core Border        (declared via supports; no plugin code)
 *     - Core Box Shadow    (declared via supports; no plugin code)
 *     - Custom Color       (eight items, alpha-enabled, plugin-owned)
 *     - Typography ×3      (Tick labels, Tooltip distance, Tooltip height)
 *
 * Only `Color → Background` flows to the rendered wrapper in this step;
 * every other control persists its value but produces no visible output
 * yet. The remaining wiring lands incrementally in Steps 3 – 7.
 *
 * The background lights up the editor wrapper via two channels: a CSS
 * custom property (`--kntnt-gpx-blocks-elevation-background`) that
 * matches the contract `render.php` uses on the frontend, and a direct
 * inline `backgroundColor` so the editor preview repaints immediately
 * without a server round-trip. The direct `backgroundColor` collapses
 * into the custom-property route once Step 3 introduces `editor.scss`.
 *
 * @since 1.0.0
 */

import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { BlockEditProps } from '@wordpress/blocks';

import { usefulValue } from './useful-value';
import { InspectorColorPanel } from './inspector-color';
import { TypographyToolsPanel } from '../shared/typography-tools-panel';

/**
 * Renders the inspector controls and the block's editor wrapper.
 *
 * @since 1.0.0
 *
 * @param props               Gutenberg edit-component props.
 * @param props.attributes    Saved block attribute bag (35 entries in
 *                            Step 1; see `block.json`).
 * @param props.setAttributes Standard Gutenberg attribute setter.
 * @param props.clientId      Block client id, used to namespace each
 *                            ToolsPanel's `panelId`.
 */
export function ElevationEdit( {
	attributes,
	setAttributes,
	clientId,
}: BlockEditProps< Record< string, unknown > > ): JSX.Element {
	// Wire the only Color attribute Step 1 actually renders. The wrapper
	// emits the CSS custom property `--kntnt-gpx-blocks-elevation-background`
	// for parity with `render.php`'s contract; the direct inline
	// `backgroundColor` ensures the editor preview repaints without a
	// server round-trip. The fallback is `''` so `resolved` either holds a
	// user-set hex string or the empty string, which means "don't inject".
	const bg = usefulValue< string >(
		attributes,
		setAttributes,
		'backgroundColor',
		''
	);
	const inlineStyle: Record< string, string > = {};
	if ( bg.resolved !== '' ) {
		inlineStyle[ '--kntnt-gpx-blocks-elevation-background' ] = bg.resolved;
		inlineStyle.backgroundColor = bg.resolved;
	}

	// Inject the project class so any future `style.scss` rules attach. The
	// outer wrapper is otherwise managed by core: Dimensions, Border, Box
	// Shadow, and Margin all reach the wrapper through the standard
	// block-supports pipeline merged into `useBlockProps()`.
	const blockProps = useBlockProps( {
		className: 'kntnt-gpx-blocks-elevation',
		style: inlineStyle as React.CSSProperties,
	} );

	const tooltipShowDistance =
		typeof attributes.tooltipShowDistance === 'boolean'
			? attributes.tooltipShowDistance
			: true;
	const tooltipShowHeight =
		typeof attributes.tooltipShowHeight === 'boolean'
			? attributes.tooltipShowHeight
			: true;
	const mapId =
		typeof attributes.mapId === 'string' ? attributes.mapId : 'auto';

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Data Source', 'kntnt-gpx-blocks' ) }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Map', 'kntnt-gpx-blocks' ) }
						value={ mapId }
						options={ [] }
						onChange={ ( value: string ) =>
							setAttributes( { mapId: value } )
						}
					/>
				</PanelBody>
				<PanelBody title={ __( 'Tooltip info', 'kntnt-gpx-blocks' ) }>
					<ToggleControl
						label={ __( 'Show distance', 'kntnt-gpx-blocks' ) }
						checked={ tooltipShowDistance }
						onChange={ ( value: boolean ) =>
							setAttributes( { tooltipShowDistance: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Show height', 'kntnt-gpx-blocks' ) }
						checked={ tooltipShowHeight }
						onChange={ ( value: boolean ) =>
							setAttributes( { tooltipShowHeight: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<InspectorControls group="styles">
				<InspectorColorPanel
					attributes={ attributes }
					setAttributes={ setAttributes }
					panelId={ clientId }
				/>
				<TypographyToolsPanel
					title={ __( 'Tick labels', 'kntnt-gpx-blocks' ) }
					prefix="tickLabel"
					attributes={ attributes }
					setAttributes={ setAttributes }
					defaultVisibility={ {
						size: true,
						appearance: true,
					} }
					panelId={ `${ clientId }-tick-label` }
				/>
				<TypographyToolsPanel
					title={ __( 'Tooltip distance', 'kntnt-gpx-blocks' ) }
					prefix="tooltipDistance"
					attributes={ attributes }
					setAttributes={ setAttributes }
					defaultVisibility={ {
						size: true,
						appearance: true,
					} }
					panelId={ `${ clientId }-tooltip-distance` }
				/>
				<TypographyToolsPanel
					title={ __( 'Tooltip height', 'kntnt-gpx-blocks' ) }
					prefix="tooltipHeight"
					attributes={ attributes }
					setAttributes={ setAttributes }
					defaultVisibility={ {
						size: true,
						appearance: true,
					} }
					panelId={ `${ clientId }-tooltip-height` }
				/>
			</InspectorControls>
			<div { ...blockProps } />
		</>
	);
}
