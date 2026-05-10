/**
 * GPX Map edit component.
 *
 * Renders the block representation inside the Gutenberg editor. When the
 * block has no GPX attachment yet, a MediaPlaceholder is shown so the user
 * can pick a .gpx file. Once an attachment is selected, MapEditorPreview
 * mounts a native Leaflet map inside the editor iframe by fetching the
 * cached GeoJSON via the plugin's REST endpoint — the Interactivity API
 * runtime does not bootstrap inside ServerSideRender's injected DOM in the
 * editor, so the editor cannot reuse the frontend view.ts mount path.
 *
 * The useEnsureUniqueMapId hook auto-generates the mapId attribute on insert
 * and resolves collisions when a block is duplicated.
 *
 * @since 1.0.0
 */

import {
	useBlockProps,
	InspectorControls,
	BlockControls,
	MediaPlaceholder,
	MediaReplaceFlow,
	PanelColorSettings,
	useSettings,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalFontFamilyControl as FontFamilyControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalFontAppearanceControl as FontAppearanceControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalLetterSpacingControl as LetterSpacingControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalTextDecorationControl as TextDecorationControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalTextTransformControl as TextTransformControl,
	LineHeightControl,
} from '@wordpress/block-editor';
import {
	PanelBody,
	ToggleControl,
	ColorPicker,
	FontSizePicker,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToolsPanel as ToolsPanel,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToolsPanelItem as ToolsPanelItem,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';
import type { BlockEditProps } from '@wordpress/blocks';

import { useEnsureUniqueMapId } from './use-ensure-unique-map-id';
import { MapEditorPreview } from './editor-preview';
import { flattenPresets } from '../shared/flatten-presets';

/**
 * Attributes for the GPX Map block.
 *
 * @since 1.0.0
 */
interface MapAttributes {
	attachmentId: number;
	mapId: string;
	showZoomButtons: boolean;
	showScale: boolean;
	showFullscreen: boolean;
	showDownload: boolean;
	enableDrag: boolean;
	enablePinchZoom: boolean;
	enableDoubleClickZoom: boolean;
	enableKeyboard: boolean;
	trackColor: string;
	trackCursorColor: string;
	waypointColor: string;
	tooltipShowName: boolean;
	tooltipShowDesc: boolean;
	tooltipBackground: string;
	tooltipNameColor: string;
	tooltipNameFontFamily: string;
	tooltipNameFontSize: string;
	tooltipNameFontWeight: string;
	tooltipNameFontStyle: string;
	tooltipNameLineHeight: string;
	tooltipNameLetterSpacing: string;
	tooltipNameTextDecoration: string;
	tooltipNameTextTransform: string;
	tooltipDescColor: string;
	tooltipDescFontFamily: string;
	tooltipDescFontSize: string;
	tooltipDescFontWeight: string;
	tooltipDescFontStyle: string;
	tooltipDescLineHeight: string;
	tooltipDescLetterSpacing: string;
	tooltipDescTextDecoration: string;
	tooltipDescTextTransform: string;
	[ key: string ]: unknown;
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
 * Media object shape returned by MediaPlaceholder's onSelect callback.
 *
 * @since 1.0.0
 */
interface MediaObject {
	id: number;
	url: string;
	[ key: string ]: unknown;
}

/**
 * Per-aspect setter signature passed into the typography panel renderer. The
 * panel writes one or more named aspects per call; unset aspects retain their
 * previous value. The empty string is the canonical "unset" marker.
 *
 * @since 1.0.0
 */
type SetTypography = ( values: {
	fontFamily?: string;
	fontSize?: string;
	fontWeight?: string;
	fontStyle?: string;
	lineHeight?: string;
	letterSpacing?: string;
	textDecoration?: string;
	textTransform?: string;
} ) => void;

/**
 * Renders a unified Typography ToolsPanel matching the surface used by core
 * Paragraph/Group blocks: a per-aspect dropdown menu lets the editor enable or
 * disable each aspect individually, and "Reset all" returns every aspect to
 * the inherited theme default.
 *
 * The panel exposes the seven aspects core's standard Typography panel
 * surfaces — Font (family), Size, Appearance (weight + style combined), Line
 * height, Letter spacing, Decoration, and Letter case — written into the
 * caller-provided attribute group via `setTypography`. Each aspect can be
 * enabled or disabled individually; an unset aspect reads as "Default" and
 * inherits from the surrounding theme.
 *
 * @since 1.0.0
 *
 * @param {Object}             props                Component props.
 * @param {string}             props.label          Localised panel title.
 * @param {string}             props.fontFamily     Current font-family value.
 * @param {string}             props.fontSize       Current font-size value.
 * @param {string}             props.fontWeight     Current font-weight value.
 * @param {string}             props.fontStyle      Current font-style value.
 * @param {string}             props.lineHeight     Current line-height value.
 * @param {string}             props.letterSpacing  Current letter-spacing value.
 * @param {string}             props.textDecoration Current text-decoration value.
 * @param {string}             props.textTransform  Current text-transform value.
 * @param {FontFamilyPreset[]} props.fontFamilies   Theme font-family presets.
 * @param {FontSizePreset[]}   props.fontSizes      Theme font-size presets.
 * @param {SetTypography}      props.setTypography  Setter callback.
 */
function TypographyToolsPanel( {
	label,
	fontFamily,
	fontSize,
	fontWeight,
	fontStyle,
	lineHeight,
	letterSpacing,
	textDecoration,
	textTransform,
	fontFamilies,
	fontSizes,
	setTypography,
}: {
	label: string;
	fontFamily: string;
	fontSize: string;
	fontWeight: string;
	fontStyle: string;
	lineHeight: string;
	letterSpacing: string;
	textDecoration: string;
	textTransform: string;
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
					lineHeight: '',
					letterSpacing: '',
					textDecoration: '',
					textTransform: '',
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
			{ /* @ts-ignore — ToolsPanelItem's typings lag the runtime API. */ }
			<ToolsPanelItem
				hasValue={ () => lineHeight !== '' }
				label={ __( 'Line height', 'kntnt-gpx-blocks' ) }
				onDeselect={ () => setTypography( { lineHeight: '' } ) }
				isShownByDefault={ false }
			>
				<LineHeightControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					__unstableInputWidth="auto"
					value={ lineHeight }
					onChange={ ( value: string | undefined ) =>
						setTypography( { lineHeight: value ?? '' } )
					}
				/>
			</ToolsPanelItem>
			{ /* @ts-ignore — ToolsPanelItem's typings lag the runtime API. */ }
			<ToolsPanelItem
				hasValue={ () => letterSpacing !== '' }
				label={ __( 'Letter spacing', 'kntnt-gpx-blocks' ) }
				onDeselect={ () => setTypography( { letterSpacing: '' } ) }
				isShownByDefault={ false }
			>
				<LetterSpacingControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					value={ letterSpacing }
					onChange={ ( value: string | undefined ) =>
						setTypography( { letterSpacing: value ?? '' } )
					}
				/>
			</ToolsPanelItem>
			{ /* @ts-ignore — ToolsPanelItem's typings lag the runtime API. */ }
			<ToolsPanelItem
				hasValue={ () => textDecoration !== '' }
				label={ __( 'Decoration', 'kntnt-gpx-blocks' ) }
				onDeselect={ () => setTypography( { textDecoration: '' } ) }
				isShownByDefault={ false }
			>
				<TextDecorationControl
					value={ textDecoration }
					onChange={ ( value: string | undefined ) =>
						setTypography( { textDecoration: value ?? '' } )
					}
				/>
			</ToolsPanelItem>
			{ /* @ts-ignore — ToolsPanelItem's typings lag the runtime API. */ }
			<ToolsPanelItem
				hasValue={ () => textTransform !== '' }
				label={ __( 'Letter case', 'kntnt-gpx-blocks' ) }
				onDeselect={ () => setTypography( { textTransform: '' } ) }
				isShownByDefault={ false }
			>
				<TextTransformControl
					value={ textTransform }
					onChange={ ( value: string | undefined ) =>
						setTypography( { textTransform: value ?? '' } )
					}
				/>
			</ToolsPanelItem>
		</ToolsPanel>
	);
}

/**
 * Editor preview for the GPX Map block.
 *
 * Shows a MediaPlaceholder when no attachment is selected; otherwise
 * delegates to MapEditorPreview which mounts Leaflet directly via React.
 * InspectorControls always render regardless of attachment state so the
 * Controls panel is accessible from the moment the block is inserted.
 *
 * @since 1.0.0
 *
 * @param {Object}   props               Standard Gutenberg block edit props.
 * @param {string}   props.clientId      This block's unique client ID.
 * @param {Object}   props.attributes    Current block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 */
export const MapEdit = ( {
	clientId,
	attributes,
	setAttributes,
}: BlockEditProps< MapAttributes > ): JSX.Element => {
	// Ensure this block's mapId is non-empty and unique across the post.
	useEnsureUniqueMapId( clientId, attributes, setAttributes );

	// Destructure all attributes before use so useBlockProps can read colour
	// values when it injects the instant-preview CSS variables. mapId is
	// managed by useEnsureUniqueMapId above and is not consumed here directly.
	const {
		attachmentId,
		showZoomButtons,
		showScale,
		showFullscreen,
		showDownload,
		enableDrag,
		enablePinchZoom,
		enableDoubleClickZoom,
		enableKeyboard,
		trackColor,
		trackCursorColor,
		waypointColor,
		tooltipShowName,
		tooltipShowDesc,
		tooltipBackground,
		tooltipNameColor,
		tooltipNameFontFamily,
		tooltipNameFontSize,
		tooltipNameFontWeight,
		tooltipNameFontStyle,
		tooltipNameLineHeight,
		tooltipNameLetterSpacing,
		tooltipNameTextDecoration,
		tooltipNameTextTransform,
		tooltipDescColor,
		tooltipDescFontFamily,
		tooltipDescFontSize,
		tooltipDescFontWeight,
		tooltipDescFontStyle,
		tooltipDescLineHeight,
		tooltipDescLetterSpacing,
		tooltipDescTextDecoration,
		tooltipDescTextTransform,
	} = attributes;

	// Resolve the attached media's source URL so MediaReplaceFlow can label
	// the toolbar button with the current filename. The media object may be
	// undefined while core/core-data is still resolving the attachment, in
	// which case the toolbar button degrades gracefully to the generic label.
	const mediaURL = useSelect(
		( select ) => {
			if ( attachmentId === 0 ) {
				return undefined;
			}
			const { getMedia } = select( coreStore ) as {
				getMedia: ( id: number ) => { source_url?: string } | undefined;
			};
			return getMedia( attachmentId )?.source_url;
		},
		[ attachmentId ]
	);

	// Pull the merged theme typography presets so the unified Typography
	// panel exposes the same Standard/preset choices as core Paragraph/Group.
	// useSettings returns the origin-keyed `{ default, theme, custom }` shape
	// for multi-origin settings, which the underlying controls iterate with
	// `.map()` — flatten to a plain array before forwarding.
	const [ themeFontFamilies, themeFontSizes ] = useSettings(
		'typography.fontFamilies',
		'typography.fontSizes'
	);
	const fontFamilies =
		flattenPresets< FontFamilyPreset >( themeFontFamilies );
	const fontSizes = flattenPresets< FontSizePreset >( themeFontSizes );

	// Inject the project class so the shared style.scss rules (layout
	// baseline, focus styles, hit-band styling, tooltip styling, …) apply to
	// the editor wrapper exactly as they do to the frontend wrapper that
	// `Render_Map` produces via `get_block_wrapper_attributes()`. Dimensions
	// (`aspect-ratio`, `min-height`) come from core's `dimensions` block
	// supports — `useBlockProps()` already merges them into the inline style
	// it returns. The track and cursor colour custom properties are added
	// here so canvas-painted Leaflet polylines that read them via CSS
	// inheritance see the editor's current colour-picker values.
	const blockProps = useBlockProps( {
		className: 'kntnt-gpx-blocks-map',
		style: {
			...( trackColor
				? { '--kntnt-gpx-blocks-track-color': trackColor }
				: {} ),
			...( trackCursorColor
				? { '--kntnt-gpx-blocks-track-cursor-color': trackCursorColor }
				: {} ),
		} as React.CSSProperties,
	} );

	// Show the media picker until the user selects a .gpx attachment.
	if ( attachmentId === 0 ) {
		return (
			<div { ...blockProps }>
				<MediaPlaceholder
					icon="location-alt"
					labels={ {
						title: __( 'GPX Map', 'kntnt-gpx-blocks' ),
						instructions: __(
							'Upload a GPX file or pick one from the media library.',
							'kntnt-gpx-blocks'
						),
					} }
					accept=".gpx"
					allowedTypes={ [ 'application/gpx+xml' ] }
					onSelect={ ( media: MediaObject ) => {
						setAttributes( { attachmentId: media.id } );
					} }
				/>
			</div>
		);
	}

	// Render the inspector controls and the editor-side React preview once a
	// GPX file is attached. The preview component renders any error notice
	// inline; no separate Notice in the inspector is needed. The toolbar
	// surfaces a Replace flow (Media Library + Upload tabs only — no URL,
	// no Reset) so the editor can swap the .gpx without losing any other
	// attribute; only `attachmentId` is written by the onSelect callback.
	return (
		<>
			<BlockControls group="other">
				<MediaReplaceFlow
					mediaId={ attachmentId }
					mediaURL={ mediaURL }
					accept=".gpx"
					allowedTypes={ [ 'application/gpx+xml' ] }
					onSelect={ ( media: MediaObject ) => {
						setAttributes( { attachmentId: media.id } );
					} }
				/>
			</BlockControls>
			<InspectorControls>
				<PanelBody title={ __( 'Controls', 'kntnt-gpx-blocks' ) }>
					<ToggleControl
						label={ __( 'Zoom buttons', 'kntnt-gpx-blocks' ) }
						checked={ showZoomButtons }
						onChange={ ( value ) =>
							setAttributes( { showZoomButtons: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Scale', 'kntnt-gpx-blocks' ) }
						checked={ showScale }
						onChange={ ( value ) =>
							setAttributes( { showScale: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Fullscreen button', 'kntnt-gpx-blocks' ) }
						checked={ showFullscreen }
						onChange={ ( value ) =>
							setAttributes( { showFullscreen: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Download GPX button',
							'kntnt-gpx-blocks'
						) }
						checked={ showDownload }
						onChange={ ( value ) =>
							setAttributes( { showDownload: value } )
						}
					/>
				</PanelBody>
				<PanelBody title={ __( 'Interactions', 'kntnt-gpx-blocks' ) }>
					<ToggleControl
						label={ __( 'Drag to pan', 'kntnt-gpx-blocks' ) }
						checked={ enableDrag }
						onChange={ ( value ) =>
							setAttributes( { enableDrag: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Pinch zoom', 'kntnt-gpx-blocks' ) }
						checked={ enablePinchZoom }
						onChange={ ( value ) =>
							setAttributes( { enablePinchZoom: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Double-click zoom', 'kntnt-gpx-blocks' ) }
						checked={ enableDoubleClickZoom }
						onChange={ ( value ) =>
							setAttributes( { enableDoubleClickZoom: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Keyboard navigation',
							'kntnt-gpx-blocks'
						) }
						checked={ enableKeyboard }
						onChange={ ( value ) =>
							setAttributes( { enableKeyboard: value } )
						}
					/>
				</PanelBody>
				<PanelBody title={ __( 'Waypoint info', 'kntnt-gpx-blocks' ) }>
					<ToggleControl
						label={ __( 'Show name', 'kntnt-gpx-blocks' ) }
						checked={ tooltipShowName }
						onChange={ ( value ) =>
							setAttributes( { tooltipShowName: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Show description', 'kntnt-gpx-blocks' ) }
						checked={ tooltipShowDesc }
						onChange={ ( value ) =>
							setAttributes( { tooltipShowDesc: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<InspectorControls group="styles">
				{ /* @ts-ignore — PanelColorSettings is exported from @wordpress/block-editor but its typings lag behind. */ }
				<PanelColorSettings
					title={ __( 'Track', 'kntnt-gpx-blocks' ) }
					colorSettings={ [
						{
							value: trackColor,
							onChange: ( value: string | undefined ) =>
								setAttributes( { trackColor: value ?? '' } ),
							label: __( 'Track colour', 'kntnt-gpx-blocks' ),
						},
						{
							value: trackCursorColor,
							onChange: ( value: string | undefined ) =>
								setAttributes( {
									trackCursorColor: value ?? '',
								} ),
							label: __( 'Cursor colour', 'kntnt-gpx-blocks' ),
						},
					] }
				/>
				{ /* @ts-ignore — PanelColorSettings is exported from @wordpress/block-editor but its typings lag behind. */ }
				<PanelColorSettings
					title={ __( 'Waypoints', 'kntnt-gpx-blocks' ) }
					colorSettings={ [
						{
							value: waypointColor,
							onChange: ( value: string | undefined ) =>
								setAttributes( { waypointColor: value ?? '' } ),
							label: __( 'Marker colour', 'kntnt-gpx-blocks' ),
						},
					] }
				/>
				<PanelBody
					title={ __(
						'Waypoint info — Background',
						'kntnt-gpx-blocks'
					) }
					initialOpen={ false }
				>
					{ /* @ts-ignore — ColorPicker's runtime accepts these props but its typings lag. */ }
					<ColorPicker
						color={ tooltipBackground }
						enableAlpha
						copyFormat="hex"
						onChange={ ( value: string ) =>
							setAttributes( {
								tooltipBackground: value ?? '',
							} )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Waypoint info — Name', 'kntnt-gpx-blocks' ) }
					initialOpen={ false }
				>
					{ /* @ts-ignore — ColorPicker's runtime accepts these props but its typings lag. */ }
					<ColorPicker
						color={ tooltipNameColor }
						enableAlpha
						copyFormat="hex"
						onChange={ ( value: string ) =>
							setAttributes( {
								tooltipNameColor: value ?? '',
							} )
						}
					/>
					<TypographyToolsPanel
						label={ __( 'Typography', 'kntnt-gpx-blocks' ) }
						fontFamily={ tooltipNameFontFamily }
						fontSize={ tooltipNameFontSize }
						fontWeight={ tooltipNameFontWeight }
						fontStyle={ tooltipNameFontStyle }
						lineHeight={ tooltipNameLineHeight }
						letterSpacing={ tooltipNameLetterSpacing }
						textDecoration={ tooltipNameTextDecoration }
						textTransform={ tooltipNameTextTransform }
						fontFamilies={ fontFamilies }
						fontSizes={ fontSizes }
						setTypography={ ( values ) => {
							const next: Partial< MapAttributes > = {};
							if ( values.fontFamily !== undefined ) {
								next.tooltipNameFontFamily = values.fontFamily;
							}
							if ( values.fontSize !== undefined ) {
								next.tooltipNameFontSize = values.fontSize;
							}
							if ( values.fontWeight !== undefined ) {
								next.tooltipNameFontWeight = values.fontWeight;
							}
							if ( values.fontStyle !== undefined ) {
								next.tooltipNameFontStyle = values.fontStyle;
							}
							if ( values.lineHeight !== undefined ) {
								next.tooltipNameLineHeight = values.lineHeight;
							}
							if ( values.letterSpacing !== undefined ) {
								next.tooltipNameLetterSpacing =
									values.letterSpacing;
							}
							if ( values.textDecoration !== undefined ) {
								next.tooltipNameTextDecoration =
									values.textDecoration;
							}
							if ( values.textTransform !== undefined ) {
								next.tooltipNameTextTransform =
									values.textTransform;
							}
							setAttributes( next );
						} }
					/>
				</PanelBody>
				<PanelBody
					title={ __(
						'Waypoint info — Description',
						'kntnt-gpx-blocks'
					) }
					initialOpen={ false }
				>
					{ /* @ts-ignore — ColorPicker's runtime accepts these props but its typings lag. */ }
					<ColorPicker
						color={ tooltipDescColor }
						enableAlpha
						copyFormat="hex"
						onChange={ ( value: string ) =>
							setAttributes( {
								tooltipDescColor: value ?? '',
							} )
						}
					/>
					<TypographyToolsPanel
						label={ __( 'Typography', 'kntnt-gpx-blocks' ) }
						fontFamily={ tooltipDescFontFamily }
						fontSize={ tooltipDescFontSize }
						fontWeight={ tooltipDescFontWeight }
						fontStyle={ tooltipDescFontStyle }
						lineHeight={ tooltipDescLineHeight }
						letterSpacing={ tooltipDescLetterSpacing }
						textDecoration={ tooltipDescTextDecoration }
						textTransform={ tooltipDescTextTransform }
						fontFamilies={ fontFamilies }
						fontSizes={ fontSizes }
						setTypography={ ( values ) => {
							const next: Partial< MapAttributes > = {};
							if ( values.fontFamily !== undefined ) {
								next.tooltipDescFontFamily = values.fontFamily;
							}
							if ( values.fontSize !== undefined ) {
								next.tooltipDescFontSize = values.fontSize;
							}
							if ( values.fontWeight !== undefined ) {
								next.tooltipDescFontWeight = values.fontWeight;
							}
							if ( values.fontStyle !== undefined ) {
								next.tooltipDescFontStyle = values.fontStyle;
							}
							if ( values.lineHeight !== undefined ) {
								next.tooltipDescLineHeight = values.lineHeight;
							}
							if ( values.letterSpacing !== undefined ) {
								next.tooltipDescLetterSpacing =
									values.letterSpacing;
							}
							if ( values.textDecoration !== undefined ) {
								next.tooltipDescTextDecoration =
									values.textDecoration;
							}
							if ( values.textTransform !== undefined ) {
								next.tooltipDescTextTransform =
									values.textTransform;
							}
							setAttributes( next );
						} }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<MapEditorPreview
					attributes={ {
						attachmentId,
						trackColor,
						waypointColor,
					} }
				/>
			</div>
		</>
	);
};
