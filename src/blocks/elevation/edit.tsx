/**
 * GPX Elevation edit component.
 *
 * Renders a data-source picker in the Settings tab and the Color panel in
 * the Design tab (`<InspectorControls group="styles">`), plus a live
 * ServerSideRender preview in the block canvas. Sizing is delegated to
 * core's `dimensions` block supports; typography is delegated to core's
 * `typography` block supports — the standard Typography panel surfaces
 * Font, Size, Appearance, and the rest at the block level. Colour changes
 * are injected as inline CSS variables on the wrapper div so the editor
 * preview updates instantly without a round-trip to ServerSideRender.
 *
 * The component is intentionally thin: the page's map-tree walk, the
 * single-map auto-binding, and the SSR-error tracking each live behind a
 * dedicated hook in this folder. `ElevationEdit` itself is the orchestrator
 * — it reads attributes, calls the hooks in order, assembles inline styles,
 * and renders the inspector + preview tree.
 *
 * @since 1.0.0
 */

import {
	InspectorControls,
	PanelColorSettings,
	useBlockProps,
} from '@wordpress/block-editor';
import type { BlockEditProps } from '@wordpress/blocks';
import { Notice, PanelBody, SelectControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

import { useAutoPickMapId } from './use-auto-pick-map-id';
import { useMapBlocks } from './use-map-blocks';
import { useBindSingleMap } from './use-bind-single-map';
import { useSsrErrorMessage } from './use-ssr-error-message';
import { buildColorSettings } from './color-settings';
import { getDefaultMinHeight } from '../shared/dimensions-defaults';

/**
 * Attributes for the GPX Elevation block.
 *
 * @since 1.0.0
 */
interface ElevationAttributes {
	mapId: string;
	axisColor: string;
	axisLabelColor: string;
	lineColor: string;
	cursorColor: string;
	tooltipBackground: string;
	tooltipColor: string;
	[ key: string ]: unknown;
}

/**
 * Editor preview for the GPX Elevation block.
 *
 * Shows two InspectorControls panels: the Settings tab carries the data
 * source picker; the Design tab (`group="styles"`) carries the Color
 * panel. Typography is delegated to core's `supports.typography` block
 * supports, which contributes its own standard Typography panel into the
 * styles group. Colour changes are applied immediately via inline CSS
 * variables on the wrapper div — no ServerSideRender round-trip for
 * cosmetic edits.
 *
 * @since 1.0.0
 *
 * @param {Object}   props               Standard Gutenberg block edit props.
 * @param {string}   props.clientId      This block's unique client ID.
 * @param {Object}   props.attributes    Current block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 */
export const ElevationEdit = ( {
	clientId,
	attributes,
	setAttributes,
}: BlockEditProps< ElevationAttributes > ): JSX.Element => {
	const {
		mapId,
		axisColor,
		axisLabelColor,
		lineColor,
		cursorColor,
		tooltipBackground,
		tooltipColor,
	} = attributes;

	// Pre-bind a freshly inserted block to the closest preceding GPX Map in
	// document order. One-shot — see `useAutoPickMapId` for the guard
	// semantics. When no Map precedes the Elevation, the attribute stays as
	// the default `'auto'` and the existing single-map resolution path takes
	// over downstream.
	useAutoPickMapId( clientId, mapId, ( next ) => setAttributes( next ) );

	// Walk the page's block tree once and expose the configured map list,
	// the picker option entries, and the SSR snapshot in one shot.
	const { configuredMapBlocks, mapOptions, editorBlockSnapshot } =
		useMapBlocks();

	// Keep the attribute aligned with the single configured map on the page
	// (no-op for zero or two-plus configured maps — picker or fallback owns
	// the binding in those cases).
	useBindSingleMap( configuredMapBlocks, mapId, setAttributes );

	// Lift any Render_Elevation-emitted error message into React state so
	// the Inspector sidebar can mirror it as a Notice.
	const { errorMessage, ssrWrapperRef } = useSsrErrorMessage();

	// Build a style object carrying every non-empty colour attribute as a CSS
	// custom property so the editor preview updates instantly.
	const inlineStyle: Record< string, string > = {};
	if ( axisColor ) {
		inlineStyle[ '--kntnt-gpx-blocks-axis-color' ] = axisColor;
	}
	if ( axisLabelColor ) {
		inlineStyle[ '--kntnt-gpx-blocks-axis-label-color' ] = axisLabelColor;
	}
	if ( lineColor ) {
		inlineStyle[ '--kntnt-gpx-blocks-line-color' ] = lineColor;
	}
	if ( cursorColor ) {
		inlineStyle[ '--kntnt-gpx-blocks-cursor-color' ] = cursorColor;
	}
	if ( tooltipBackground ) {
		inlineStyle[ '--kntnt-gpx-blocks-tooltip-background' ] =
			tooltipBackground;
	}
	if ( tooltipColor ) {
		inlineStyle[ '--kntnt-gpx-blocks-tooltip-color' ] = tooltipColor;
	}

	// Resolve the plugin-defined default `min-height` for the wrapper.
	// `getDefaultMinHeight()` returns `'15vh'` when the user has set
	// neither minHeight nor aspectRatio on this block — the same
	// condition the server-side `Dimensions_Defaults` filter checks —
	// and `undefined` in every other case. When the value is
	// `undefined`, no inline minHeight is injected here and core's
	// dimensions block-supports machinery surfaces whatever the user
	// chose. Issue #117 centralises this rule between PHP and JS.
	const defaultMinHeight = getDefaultMinHeight(
		'kntnt-gpx-blocks/elevation',
		attributes
	);
	if ( defaultMinHeight ) {
		inlineStyle.minHeight = defaultMinHeight;
	}

	const blockProps = useBlockProps( {
		className: 'kntnt-gpx-blocks-elevation',
		style: inlineStyle as React.CSSProperties,
	} );

	// Build the picker option list. With two or more configured maps the
	// "Auto" entry is omitted: it cannot resolve, so surfacing it as a
	// selectable value would only invite an error state. With zero or one
	// configured maps the picker is hidden altogether (see below), so the
	// `sourceOptions` array is consumed only when the entries are real maps.
	const showPicker = configuredMapBlocks.length >= 2;
	const sourceOptions = showPicker
		? mapOptions
		: [
				{
					label: __(
						'Auto (single map on page)',
						'kntnt-gpx-blocks'
					),
					value: 'auto',
				},
				...mapOptions,
		  ];

	return (
		<>
			<InspectorControls>
				{ errorMessage && (
					<Notice status="error" isDismissible={ false }>
						{ errorMessage }
					</Notice>
				) }
				{ showPicker && (
					<PanelBody
						title={ __( 'Datakälla', 'kntnt-gpx-blocks' ) }
						initialOpen={ true }
					>
						<SelectControl
							label={ __( 'Map', 'kntnt-gpx-blocks' ) }
							value={ mapId }
							options={ sourceOptions }
							onChange={ ( value: string ) =>
								setAttributes( { mapId: value } )
							}
						/>
					</PanelBody>
				) }
			</InspectorControls>
			<InspectorControls group="styles">
				{ /* @ts-ignore — PanelColorSettings is exported from @wordpress/block-editor but its typings lag behind. */ }
				<PanelColorSettings
					title={ __( 'Color', 'kntnt-gpx-blocks' ) }
					enableAlpha
					colorSettings={ buildColorSettings(
						{
							axisColor,
							axisLabelColor,
							lineColor,
							cursorColor,
							tooltipBackground,
							tooltipColor,
						},
						setAttributes
					) }
				/>
			</InspectorControls>

			<div { ...blockProps }>
				{ /* ref wrapper so useEffect can inspect the rendered SSR output */ }
				<div ref={ ssrWrapperRef }>
					<ServerSideRender
						block="kntnt-gpx-blocks/elevation"
						attributes={ {
							// Do not forward the block-supports-managed
							// `style` attribute. The outer `useBlockProps()`
							// wrapper above already carries the editor's
							// chosen dimensions / border / shadow / spacing /
							// typography; forwarding `style` would make
							// `get_block_wrapper_attributes()` re-emit the
							// same inline style on the SSR-rendered inner
							// wrapper and the editor would render every
							// dimension at twice the chosen value.
							mapId,
							axisColor,
							axisLabelColor,
							lineColor,
							cursorColor,
							tooltipBackground,
							tooltipColor,
							__editorBlockSnapshot: editorBlockSnapshot,
						} }
					/>
				</div>
			</div>
		</>
	);
};
