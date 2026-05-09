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
	MediaPlaceholder,
	PanelColorSettings,
	useSettings,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalFontFamilyControl as FontFamilyControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalFontAppearanceControl as FontAppearanceControl,
} from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	ToggleControl,
	FontSizePicker,
	SelectControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToolsPanel as ToolsPanel,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToolsPanelItem as ToolsPanelItem,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { BlockEditProps } from '@wordpress/blocks';

import { useEnsureUniqueMapId } from './use-ensure-unique-map-id';
import { MapEditorPreview } from './editor-preview';

/**
 * Attributes for the GPX Map block.
 *
 * @since 1.0.0
 */
interface MapAttributes {
	attachmentId: number;
	mapId: string;
	aspectRatio: string;
	minHeight: string;
	maxHeight: string;
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
	waypointLabelBackground: string;
	waypointLabelColor: string;
	waypointLabelFontFamily: string;
	waypointLabelFontSize: string;
	waypointLabelFontWeight: string;
	waypointLabelFontStyle: string;
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
 * Preset aspect-ratio options for the Layout panel dropdown.
 *
 * The last entry signals that the user wants to type a custom value; the
 * component renders a TextControl when this option is selected.
 *
 * @since 1.0.0
 */
const ASPECT_RATIO_OPTIONS = [
	{ label: '1 / 1', value: '1/1' },
	{ label: '4 / 3', value: '4/3' },
	{ label: '3 / 2', value: '3/2' },
	{ label: '16 / 9', value: '16/9' },
	{ label: '21 / 9', value: '21/9' },
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
 * Accepts values like `240px`, `12.5em`, `10rem`, `50%`. An empty string is
 * always valid (means "use the PHP-side default").
 *
 * @since 1.0.0
 */
const CSS_LENGTH_RE = /^\d+(\.\d+)?(px|em|rem|%)$/;

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
		aspectRatio,
		minHeight,
		maxHeight,
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
		waypointLabelBackground,
		waypointLabelColor,
		waypointLabelFontFamily,
		waypointLabelFontSize,
		waypointLabelFontWeight,
		waypointLabelFontStyle,
	} = attributes;

	// Pull the merged theme typography presets so the unified Typography
	// panel exposes the same Standard/preset choices as core Paragraph/Group.
	const [ themeFontFamilies, themeFontSizes ] = useSettings(
		'typography.fontFamilies',
		'typography.fontSizes'
	) as [ FontFamilyPreset[] | undefined, FontSizePreset[] | undefined ];
	const fontFamilies = themeFontFamilies ?? [];
	const fontSizes = themeFontSizes ?? [];

	// Inject CSS variables onto the block wrapper. The MapEditorPreview reads
	// trackColor and waypointColor directly from props and applies them to
	// Leaflet's path options, since canvas-rendered shapes cannot be styled
	// via CSS — but propagating the variables here keeps the wrapper element
	// consistent with the frontend's inline style.
	const blockProps = useBlockProps( {
		style: {
			...( trackColor
				? { '--kntnt-gpx-blocks-track-color': trackColor }
				: {} ),
			...( trackCursorColor
				? { '--kntnt-gpx-blocks-track-cursor-color': trackCursorColor }
				: {} ),
		} as React.CSSProperties,
	} );

	// Determine which dropdown value is active. When the stored aspect-ratio
	// matches a preset value, show the preset; otherwise show "Custom".
	const presetValues = ASPECT_RATIO_OPTIONS.filter(
		( o ) => o.value !== CUSTOM_RATIO_SENTINEL
	).map( ( o ) => o.value );
	const aspectRatioDropdown = presetValues.includes( aspectRatio )
		? aspectRatio
		: CUSTOM_RATIO_SENTINEL;

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
	// inline; no separate Notice in the inspector is needed.
	return (
		<>
			<InspectorControls>
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
							placeholder="e.g. 3/1"
							onChange={ ( value ) =>
								setAttributes( { aspectRatio: value } )
							}
						/>
					) }
					<TextControl
						label={ __( 'Minimum height', 'kntnt-gpx-blocks' ) }
						value={ minHeight }
						placeholder="e.g. 240px"
						help={
							minHeight !== '' &&
							! CSS_LENGTH_RE.test( minHeight )
								? __(
										'Enter a valid CSS length, e.g. 240px, 12em, 50%.',
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
						{
							value: waypointLabelBackground,
							onChange: ( value: string | undefined ) =>
								setAttributes( {
									waypointLabelBackground: value ?? '',
								} ),
							label: __( 'Label background', 'kntnt-gpx-blocks' ),
						},
						{
							value: waypointLabelColor,
							onChange: ( value: string | undefined ) =>
								setAttributes( {
									waypointLabelColor: value ?? '',
								} ),
							label: __(
								'Label text colour',
								'kntnt-gpx-blocks'
							),
						},
					] }
				/>
				<TypographyToolsPanel
					label={ __(
						'Waypoint label typography',
						'kntnt-gpx-blocks'
					) }
					fontFamily={ waypointLabelFontFamily }
					fontSize={ waypointLabelFontSize }
					fontWeight={ waypointLabelFontWeight }
					fontStyle={ waypointLabelFontStyle }
					fontFamilies={ fontFamilies }
					fontSizes={ fontSizes }
					setTypography={ ( values ) => {
						const next: Partial< MapAttributes > = {};
						if ( values.fontFamily !== undefined ) {
							next.waypointLabelFontFamily = values.fontFamily;
						}
						if ( values.fontSize !== undefined ) {
							next.waypointLabelFontSize = values.fontSize;
						}
						if ( values.fontWeight !== undefined ) {
							next.waypointLabelFontWeight = values.fontWeight;
						}
						if ( values.fontStyle !== undefined ) {
							next.waypointLabelFontStyle = values.fontStyle;
						}
						setAttributes( next );
					} }
				/>
			</InspectorControls>
			<div { ...blockProps }>
				<MapEditorPreview
					attributes={ {
						attachmentId,
						aspectRatio,
						minHeight,
						maxHeight,
						trackColor,
						trackCursorColor,
						waypointColor,
					} }
				/>
			</div>
		</>
	);
};
