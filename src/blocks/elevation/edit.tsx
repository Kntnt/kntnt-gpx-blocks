/**
 * GPX Elevation edit component.
 *
 * Renders a data-source picker, layout controls, colour settings, and
 * typography panels in the inspector sidebar, and a live ServerSideRender
 * preview in the block canvas. Colour and typography changes are injected as
 * inline CSS variables on the wrapper div so the editor preview updates
 * instantly without a round-trip to ServerSideRender.
 *
 * @since 1.0.0
 */

import { useRef, useState, useEffect } from '@wordpress/element';
import {
	InspectorControls,
	PanelColorSettings,
	useBlockProps,
	useSettings,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalFontFamilyControl as FontFamilyControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalFontAppearanceControl as FontAppearanceControl,
} from '@wordpress/block-editor';
import type { BlockEditProps } from '@wordpress/blocks';
import {
	FontSizePicker,
	Notice,
	PanelBody,
	SelectControl,
	TextControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToolsPanel as ToolsPanel,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToolsPanelItem as ToolsPanelItem,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';

/**
 * Attributes for the GPX Elevation block.
 *
 * @since 1.0.0
 */
interface ElevationAttributes {
	mapId: string;
	aspectRatio: string;
	minHeight: string;
	backgroundColor: string;
	axisColor: string;
	axisLabelColor: string;
	lineColor: string;
	cursorColor: string;
	tooltipBackground: string;
	tooltipColor: string;
	axisFontFamily: string;
	axisFontSize: string;
	axisFontWeight: string;
	axisFontStyle: string;
	tooltipFontFamily: string;
	tooltipFontSize: string;
	tooltipFontWeight: string;
	tooltipFontStyle: string;
	[ key: string ]: unknown;
}

/**
 * Shape of a parsed Gutenberg block returned by getBlocks().
 *
 * Only the fields the edit component reads are declared.
 *
 * @since 1.0.0
 */
interface EditorBlock {
	name: string;
	attributes: {
		attachmentId?: number;
		mapId?: string;
		[ key: string ]: unknown;
	};
	innerBlocks: EditorBlock[];
}

/**
 * Theme typography preset entry shape, as returned by the unified theme
 * settings (`useSettings('typography.fontFamilies')` /
 * `useSettings('typography.fontSizes')`).
 *
 * @since 1.0.0
 */
interface FontFamilyPreset {
	name: string;
	slug: string;
	fontFamily: string;
}
interface FontSizePreset {
	name: string;
	slug: string;
	size: string;
}

/**
 * Preset aspect-ratio options for the Layout panel dropdown.
 *
 * Elevation profiles are wider than tall, so the default 4/1 is the first
 * non-custom entry. The "Custom" sentinel causes a TextControl to appear.
 *
 * @since 1.0.0
 */
const ASPECT_RATIO_OPTIONS = [
	{ label: '4 / 1', value: '4/1' },
	{ label: '6 / 1', value: '6/1' },
	{ label: '3 / 1', value: '3/1' },
	{ label: __( 'Custom', 'kntnt-gpx-blocks' ), value: 'custom' },
];

/**
 * Sentinel value used in the aspect-ratio dropdown to mean "type your own".
 *
 * @since 1.0.0
 */
const CUSTOM_RATIO_SENTINEL = 'custom';

/**
 * Regex for basic CSS length validation in the min-height TextControl.
 *
 * Accepts values like `120px`, `12.5em`, `10rem`, `50%`. An empty string is
 * always valid (means "use the PHP-side default").
 *
 * @since 1.0.0
 */
const CSS_LENGTH_RE = /^\d+(\.\d+)?(px|em|rem|%)$/;

/**
 * Recursively collects all GPX Map blocks from a block tree.
 *
 * Walks the entire tree (including innerBlocks at every depth) so the picker
 * finds maps inside groups, columns, or other container blocks.
 *
 * @since 1.0.0
 *
 * @param {EditorBlock[]} blocks Flat or nested block array from getBlocks().
 *
 * @return {EditorBlock[]} All blocks whose name is 'kntnt-gpx-blocks/map'.
 */
function collectMapBlocks( blocks: EditorBlock[] ): EditorBlock[] {
	const result: EditorBlock[] = [];

	for ( const block of blocks ) {
		// Recurse first so document order is preserved when maps are nested.
		if ( block.innerBlocks.length > 0 ) {
			result.push( ...collectMapBlocks( block.innerBlocks ) );
		}
		if ( block.name === 'kntnt-gpx-blocks/map' ) {
			result.push( block );
		}
	}

	return result;
}

/**
 * Per-aspect setter signature passed into the typography panel renderer.
 *
 * @since 1.0.0
 */
type SetTypography = ( values: {
	fontFamily?: string;
	fontSize?: string;
	fontWeight?: string;
	fontStyle?: string;
} ) => void;

/**
 * Renders a unified Typography ToolsPanel matching the surface used by core
 * Paragraph/Group blocks: a per-aspect dropdown menu lets the editor enable or
 * disable each aspect individually, and "Reset all" returns every aspect to
 * the inherited theme default.
 *
 * The panel exposes three aspects — Font (family), Size, and Appearance
 * (weight + style combined) — because that is the surface persisted in the
 * block's attribute schema. Adding more aspects (line height, letter spacing,
 * etc.) would require new attributes and is out of scope here.
 *
 * @since 1.0.0
 *
 * @param {Object}             props               Component props.
 * @param {string}             props.label         Localised panel title.
 * @param {string}             props.fontFamily    Current font-family value.
 * @param {string}             props.fontSize      Current font-size value.
 * @param {string}             props.fontWeight    Current font-weight value.
 * @param {string}             props.fontStyle     Current font-style value.
 * @param {FontFamilyPreset[]} props.fontFamilies  Theme font-family presets.
 * @param {FontSizePreset[]}   props.fontSizes     Theme font-size presets.
 * @param {SetTypography}      props.setTypography Setter callback.
 */
function TypographyToolsPanel( {
	label,
	fontFamily,
	fontSize,
	fontWeight,
	fontStyle,
	fontFamilies,
	fontSizes,
	setTypography,
}: {
	label: string;
	fontFamily: string;
	fontSize: string;
	fontWeight: string;
	fontStyle: string;
	fontFamilies: FontFamilyPreset[];
	fontSizes: FontSizePreset[];
	setTypography: SetTypography;
} ): JSX.Element {
	const hasAppearance = fontWeight !== '' || fontStyle !== '';

	return (
		// @ts-ignore — ToolsPanel's typings lag the runtime API.
		<ToolsPanel
			label={ label }
			resetAll={ () =>
				setTypography( {
					fontFamily: '',
					fontSize: '',
					fontWeight: '',
					fontStyle: '',
				} )
			}
		>
			{ /* @ts-ignore — ToolsPanelItem's typings lag the runtime API. */ }
			<ToolsPanelItem
				hasValue={ () => fontFamily !== '' }
				label={ __( 'Font', 'kntnt-gpx-blocks' ) }
				onDeselect={ () => setTypography( { fontFamily: '' } ) }
				isShownByDefault
			>
				<FontFamilyControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					value={ fontFamily }
					fontFamilies={ fontFamilies }
					onChange={ ( value: string | undefined ) =>
						setTypography( { fontFamily: value ?? '' } )
					}
				/>
			</ToolsPanelItem>
			{ /* @ts-ignore — ToolsPanelItem's typings lag the runtime API. */ }
			<ToolsPanelItem
				hasValue={ () => fontSize !== '' }
				label={ __( 'Size', 'kntnt-gpx-blocks' ) }
				onDeselect={ () => setTypography( { fontSize: '' } ) }
				isShownByDefault
			>
				<FontSizePicker
					__next40pxDefaultSize
					value={ fontSize || undefined }
					fontSizes={ fontSizes }
					onChange={ ( value: number | string | undefined ) =>
						setTypography( {
							fontSize:
								value !== undefined && value !== ''
									? String( value )
									: '',
						} )
					}
					withReset={ false }
				/>
			</ToolsPanelItem>
			{ /* @ts-ignore — ToolsPanelItem's typings lag the runtime API. */ }
			<ToolsPanelItem
				hasValue={ () => hasAppearance }
				label={ __( 'Appearance', 'kntnt-gpx-blocks' ) }
				onDeselect={ () =>
					setTypography( { fontWeight: '', fontStyle: '' } )
				}
				isShownByDefault
			>
				<FontAppearanceControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					hasFontWeights
					hasFontStyles
					value={ {
						fontWeight: fontWeight || undefined,
						fontStyle: fontStyle || undefined,
					} }
					onChange={ ( value: {
						fontWeight?: string;
						fontStyle?: string;
					} ) =>
						setTypography( {
							fontWeight: value?.fontWeight ?? '',
							fontStyle: value?.fontStyle ?? '',
						} )
					}
				/>
			</ToolsPanelItem>
		</ToolsPanel>
	);
}

/**
 * Editor preview for the GPX Elevation block.
 *
 * Shows an InspectorControls sidebar with panels for data source, layout,
 * colours, axis typography, and tooltip typography. Colour and typography
 * changes are applied immediately via inline CSS variables on the wrapper
 * div — no ServerSideRender round-trip for cosmetic edits.
 *
 * @since 1.0.0
 *
 * @param {Object}   props               Standard Gutenberg block edit props.
 * @param {Object}   props.attributes    Current block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 */
export const ElevationEdit = ( {
	attributes,
	setAttributes,
}: BlockEditProps< ElevationAttributes > ): JSX.Element => {
	const {
		mapId,
		aspectRatio,
		minHeight,
		backgroundColor,
		axisColor,
		axisLabelColor,
		lineColor,
		cursorColor,
		tooltipBackground,
		tooltipColor,
		axisFontFamily,
		axisFontSize,
		axisFontWeight,
		axisFontStyle,
		tooltipFontFamily,
		tooltipFontSize,
		tooltipFontWeight,
		tooltipFontStyle,
	} = attributes;

	// Pull the merged theme typography presets so the unified Typography
	// panels expose the same Standard/preset choices as core Paragraph/Group.
	const [ themeFontFamilies, themeFontSizes ] = useSettings(
		'typography.fontFamilies',
		'typography.fontSizes'
	) as [ FontFamilyPreset[] | undefined, FontSizePreset[] | undefined ];
	const fontFamilies = themeFontFamilies ?? [];
	const fontSizes = themeFontSizes ?? [];

	// Build a style object carrying every non-empty theming attribute as a CSS
	// custom property so the editor preview updates instantly.
	const inlineStyle: Record< string, string > = {};
	if ( backgroundColor ) {
		inlineStyle[ '--kntnt-gpx-blocks-background-color' ] = backgroundColor;
	}
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
	if ( axisFontFamily ) {
		inlineStyle[ '--kntnt-gpx-blocks-axis-font-family' ] = axisFontFamily;
	}
	if ( axisFontSize ) {
		inlineStyle[ '--kntnt-gpx-blocks-axis-font-size' ] = axisFontSize;
	}
	if ( axisFontWeight ) {
		inlineStyle[ '--kntnt-gpx-blocks-axis-font-weight' ] = axisFontWeight;
	}
	if ( axisFontStyle ) {
		inlineStyle[ '--kntnt-gpx-blocks-axis-font-style' ] = axisFontStyle;
	}
	if ( tooltipFontFamily ) {
		inlineStyle[ '--kntnt-gpx-blocks-tooltip-font-family' ] =
			tooltipFontFamily;
	}
	if ( tooltipFontSize ) {
		inlineStyle[ '--kntnt-gpx-blocks-tooltip-font-size' ] = tooltipFontSize;
	}
	if ( tooltipFontWeight ) {
		inlineStyle[ '--kntnt-gpx-blocks-tooltip-font-weight' ] =
			tooltipFontWeight;
	}
	if ( tooltipFontStyle ) {
		inlineStyle[ '--kntnt-gpx-blocks-tooltip-font-style' ] =
			tooltipFontStyle;
	}

	const blockProps = useBlockProps( {
		style: inlineStyle as React.CSSProperties,
	} );

	// Track any error message surfaced by the server-rendered output so the
	// InspectorControls Notice can repeat it for discoverability.
	const [ errorMessage, setErrorMessage ] = useState< string >( '' );
	const ssrWrapperRef = useRef< HTMLDivElement >( null );
	const prevErrorRef = useRef< string >( '' );

	// Inspect the SSR output after each render; look for the error notice.
	// No dependency array: we want to re-read the DOM whenever React renders,
	// because ServerSideRender may have swapped in new HTML. prevErrorRef guards
	// the setErrorMessage call so it only fires when the DOM actually changes,
	// which prevents the infinite update loop the linter is guarding against.
	// eslint-disable-next-line react-hooks/exhaustive-deps
	useEffect( () => {
		if ( ! ssrWrapperRef.current ) {
			return;
		}
		const errorEl = ssrWrapperRef.current.querySelector< HTMLElement >(
			'.kntnt-gpx-blocks-error'
		);
		const next = errorEl ? errorEl.textContent ?? '' : '';
		if ( next !== prevErrorRef.current ) {
			prevErrorRef.current = next;
			setErrorMessage( next );
		}
	} );

	// Collect every GPX Map block from the page's block tree.
	const allBlocks = useSelect( ( select ) => {
		const { getBlocks } = select( 'core/block-editor' ) as {
			getBlocks: () => EditorBlock[];
		};
		return getBlocks();
	}, [] );

	// Collect media objects so the picker can show the GPX filename.
	const getMedia = useSelect( ( select ) => {
		const { getMedia: coreGetMedia } = select( coreStore ) as ReturnType<
			typeof select
		>;
		return coreGetMedia as (
			id: number
		) => { source_url?: string; slug?: string } | undefined;
	}, [] );

	// Build picker options: one entry per configured GPX Map block.
	const mapBlocks = collectMapBlocks( allBlocks );
	const mapOptions = mapBlocks
		.filter( ( b ) => ( b.attributes.attachmentId ?? 0 ) > 0 )
		.map( ( b, index ) => {
			const attachmentId = b.attributes.attachmentId as number;
			const blockMapId = b.attributes.mapId as string | undefined;
			const media = getMedia( attachmentId );

			// Use the media slug (filename without extension) when available.
			const filename = media?.slug ?? String( attachmentId );
			const label =
				__( 'Karta', 'kntnt-gpx-blocks' ) +
				` ${ index + 1 }: ${ filename }`;

			return { label, value: blockMapId ?? '' };
		} );

	// Prepend the auto option so it is always first in the list.
	const sourceOptions = [
		{
			label: __( 'Auto (single map on page)', 'kntnt-gpx-blocks' ),
			value: 'auto',
		},
		...mapOptions,
	];

	// Determine which dropdown value is active. When the stored aspect-ratio
	// matches a preset, show the preset; otherwise show "Custom".
	const presetValues = ASPECT_RATIO_OPTIONS.filter(
		( o ) => o.value !== CUSTOM_RATIO_SENTINEL
	).map( ( o ) => o.value );
	const aspectRatioDropdown = presetValues.includes( aspectRatio )
		? aspectRatio
		: CUSTOM_RATIO_SENTINEL;

	return (
		<>
			<InspectorControls>
				{ errorMessage && (
					<Notice status="error" isDismissible={ false }>
						{ errorMessage }
					</Notice>
				) }
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

				<PanelBody title={ __( 'Layout', 'kntnt-gpx-blocks' ) }>
					<SelectControl
						label={ __( 'Aspect ratio', 'kntnt-gpx-blocks' ) }
						value={ aspectRatioDropdown }
						options={ ASPECT_RATIO_OPTIONS }
						onChange={ ( value ) => {
							if ( value !== CUSTOM_RATIO_SENTINEL ) {
								setAttributes( { aspectRatio: value } );
							} else {
								// Keep the current stored value so the TextControl is
								// pre-filled when the user switches to Custom.
								setAttributes( { aspectRatio: '' } );
							}
						} }
					/>
					{ aspectRatioDropdown === CUSTOM_RATIO_SENTINEL && (
						<TextControl
							label={ __(
								'Custom aspect ratio',
								'kntnt-gpx-blocks'
							) }
							value={ aspectRatio }
							placeholder="e.g. 5/1"
							onChange={ ( value ) =>
								setAttributes( { aspectRatio: value } )
							}
						/>
					) }
					<TextControl
						label={ __( 'Minimum height', 'kntnt-gpx-blocks' ) }
						value={ minHeight }
						placeholder="e.g. 120px"
						help={
							minHeight !== '' &&
							! CSS_LENGTH_RE.test( minHeight )
								? __(
										'Enter a valid CSS length, e.g. 120px, 12em, 50%.',
										'kntnt-gpx-blocks'
								  )
								: ''
						}
						onChange={ ( value ) =>
							setAttributes( {
								minHeight:
									value === '' || CSS_LENGTH_RE.test( value )
										? value
										: minHeight,
							} )
						}
					/>
				</PanelBody>

				{ /* @ts-ignore — PanelColorSettings is exported from @wordpress/block-editor but its typings lag behind. */ }
				<PanelColorSettings
					title={ __( 'Colours', 'kntnt-gpx-blocks' ) }
					colorSettings={ [
						{
							value: backgroundColor,
							onChange: ( value: string | undefined ) =>
								setAttributes( {
									backgroundColor: value ?? '',
								} ),
							label: __( 'Background', 'kntnt-gpx-blocks' ),
						},
						{
							value: axisColor,
							onChange: ( value: string | undefined ) =>
								setAttributes( { axisColor: value ?? '' } ),
							label: __( 'Axis lines', 'kntnt-gpx-blocks' ),
						},
						{
							value: axisLabelColor,
							onChange: ( value: string | undefined ) =>
								setAttributes( {
									axisLabelColor: value ?? '',
								} ),
							label: __( 'Axis labels', 'kntnt-gpx-blocks' ),
						},
						{
							value: lineColor,
							onChange: ( value: string | undefined ) =>
								setAttributes( { lineColor: value ?? '' } ),
							label: __( 'Elevation line', 'kntnt-gpx-blocks' ),
						},
						{
							value: cursorColor,
							onChange: ( value: string | undefined ) =>
								setAttributes( { cursorColor: value ?? '' } ),
							label: __( 'Cursor', 'kntnt-gpx-blocks' ),
						},
						{
							value: tooltipBackground,
							onChange: ( value: string | undefined ) =>
								setAttributes( {
									tooltipBackground: value ?? '',
								} ),
							label: __(
								'Tooltip background',
								'kntnt-gpx-blocks'
							),
						},
						{
							value: tooltipColor,
							onChange: ( value: string | undefined ) =>
								setAttributes( { tooltipColor: value ?? '' } ),
							label: __( 'Tooltip text', 'kntnt-gpx-blocks' ),
						},
					] }
				/>

				<TypographyToolsPanel
					label={ __( 'Axis typography', 'kntnt-gpx-blocks' ) }
					fontFamily={ axisFontFamily }
					fontSize={ axisFontSize }
					fontWeight={ axisFontWeight }
					fontStyle={ axisFontStyle }
					fontFamilies={ fontFamilies }
					fontSizes={ fontSizes }
					setTypography={ ( values ) => {
						const next: Partial< ElevationAttributes > = {};
						if ( values.fontFamily !== undefined ) {
							next.axisFontFamily = values.fontFamily;
						}
						if ( values.fontSize !== undefined ) {
							next.axisFontSize = values.fontSize;
						}
						if ( values.fontWeight !== undefined ) {
							next.axisFontWeight = values.fontWeight;
						}
						if ( values.fontStyle !== undefined ) {
							next.axisFontStyle = values.fontStyle;
						}
						setAttributes( next );
					} }
				/>

				<TypographyToolsPanel
					label={ __( 'Tooltip typography', 'kntnt-gpx-blocks' ) }
					fontFamily={ tooltipFontFamily }
					fontSize={ tooltipFontSize }
					fontWeight={ tooltipFontWeight }
					fontStyle={ tooltipFontStyle }
					fontFamilies={ fontFamilies }
					fontSizes={ fontSizes }
					setTypography={ ( values ) => {
						const next: Partial< ElevationAttributes > = {};
						if ( values.fontFamily !== undefined ) {
							next.tooltipFontFamily = values.fontFamily;
						}
						if ( values.fontSize !== undefined ) {
							next.tooltipFontSize = values.fontSize;
						}
						if ( values.fontWeight !== undefined ) {
							next.tooltipFontWeight = values.fontWeight;
						}
						if ( values.fontStyle !== undefined ) {
							next.tooltipFontStyle = values.fontStyle;
						}
						setAttributes( next );
					} }
				/>
			</InspectorControls>

			<div { ...blockProps }>
				{ /* ref wrapper so useEffect can inspect the rendered SSR output */ }
				<div ref={ ssrWrapperRef }>
					<ServerSideRender
						block="kntnt-gpx-blocks/elevation"
						attributes={ {
							mapId,
							aspectRatio,
							minHeight,
							backgroundColor,
							axisColor,
							axisLabelColor,
							lineColor,
							cursorColor,
							tooltipBackground,
							tooltipColor,
							axisFontFamily,
							axisFontSize,
							axisFontWeight,
							axisFontStyle,
							tooltipFontFamily,
							tooltipFontSize,
							tooltipFontWeight,
							tooltipFontStyle,
						} }
					/>
				</div>
			</div>
		</>
	);
};
