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
} from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	ToggleControl,
	FontSizePicker,
	SelectControl,
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
	enableScrollWheelZoom: boolean;
	enablePinchZoom: boolean;
	enableDoubleClickZoom: boolean;
	enableBoxZoom: boolean;
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
 * Font family options for the waypoint label typography control.
 *
 * Covers the most common system stacks. The editor can store a theme preset
 * reference (var(--wp--preset--font-family--…)) by typing it into the field,
 * but the SelectControl covers the common case without needing an experimental
 * API.
 *
 * @since 1.0.0
 */
const FONT_FAMILY_OPTIONS = [
	{ label: __( 'Default (inherit)', 'kntnt-gpx-blocks' ), value: '' },
	{ label: 'Sans-serif', value: 'sans-serif' },
	{ label: 'Serif', value: 'serif' },
	{ label: 'Monospace', value: 'monospace' },
	{ label: 'Arial', value: 'Arial, sans-serif' },
	{ label: 'Georgia', value: 'Georgia, serif' },
	{
		label: 'Helvetica Neue',
		value: "'Helvetica Neue', Helvetica, sans-serif",
	},
	{ label: 'Times New Roman', value: "'Times New Roman', Times, serif" },
];

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
		enableScrollWheelZoom,
		enablePinchZoom,
		enableDoubleClickZoom,
		enableBoxZoom,
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
						label={ __( 'Scroll wheel zoom', 'kntnt-gpx-blocks' ) }
						checked={ enableScrollWheelZoom }
						onChange={ ( value ) =>
							setAttributes( { enableScrollWheelZoom: value } )
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
						label={ __( 'Box zoom', 'kntnt-gpx-blocks' ) }
						checked={ enableBoxZoom }
						onChange={ ( value ) =>
							setAttributes( { enableBoxZoom: value } )
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
				>
					<SelectControl
						label={ __( 'Label font family', 'kntnt-gpx-blocks' ) }
						value={ waypointLabelFontFamily }
						options={ FONT_FAMILY_OPTIONS }
						onChange={ ( value ) =>
							setAttributes( { waypointLabelFontFamily: value } )
						}
					/>
					<FontSizePicker
						value={ waypointLabelFontSize || undefined }
						onChange={ ( value ) =>
							setAttributes( {
								waypointLabelFontSize:
									value !== undefined ? String( value ) : '',
							} )
						}
						withReset={ true }
					/>
					<SelectControl
						label={ __( 'Label font weight', 'kntnt-gpx-blocks' ) }
						value={ waypointLabelFontWeight || 'normal' }
						options={ [
							{
								label: __( 'Normal', 'kntnt-gpx-blocks' ),
								value: 'normal',
							},
							{
								label: __( 'Bold', 'kntnt-gpx-blocks' ),
								value: 'bold',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( {
								waypointLabelFontWeight:
									value === 'normal' ? '' : value,
							} )
						}
					/>
					<SelectControl
						label={ __( 'Label font style', 'kntnt-gpx-blocks' ) }
						value={ waypointLabelFontStyle || 'normal' }
						options={ [
							{
								label: __( 'Normal', 'kntnt-gpx-blocks' ),
								value: 'normal',
							},
							{
								label: __( 'Italic', 'kntnt-gpx-blocks' ),
								value: 'italic',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( {
								waypointLabelFontStyle:
									value === 'normal' ? '' : value,
							} )
						}
					/>
				</PanelColorSettings>
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
