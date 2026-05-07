/**
 * GPX Map edit component.
 *
 * Renders the block representation inside the Gutenberg editor. When the
 * block has no GPX attachment yet, a MediaPlaceholder is shown so the user
 * can pick a .gpx file. Once an attachment is selected, ServerSideRender
 * previews the server-rendered output including the Interactivity API
 * directives, matching the frontend exactly.
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
	ServerSideRender,
} from '@wordpress/block-editor';
import {
	PanelBody,
	ToggleControl,
	FontSizePicker,
	SelectControl,
	ToggleGroupControl,
	ToggleGroupControlOption,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { BlockEditProps } from '@wordpress/blocks';

import { useEnsureUniqueMapId } from './use-ensure-unique-map-id';

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
 * delegates to ServerSideRender so the editor preview matches the frontend.
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

	const blockProps = useBlockProps();
	const {
		attachmentId,
		mapId,
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

	// Render the inspector controls and server-side preview once a GPX file is attached.
	return (
		<>
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
					<ToggleGroupControl
						label={ __( 'Label font weight', 'kntnt-gpx-blocks' ) }
						value={ waypointLabelFontWeight || 'normal' }
						onChange={ ( value ) =>
							setAttributes( {
								waypointLabelFontWeight:
									value === 'normal' ? '' : String( value ),
							} )
						}
						isBlock
					>
						<ToggleGroupControlOption
							value="normal"
							label={ __( 'Normal', 'kntnt-gpx-blocks' ) }
						/>
						<ToggleGroupControlOption
							value="bold"
							label={ __( 'Bold', 'kntnt-gpx-blocks' ) }
						/>
					</ToggleGroupControl>
					<ToggleGroupControl
						label={ __( 'Label font style', 'kntnt-gpx-blocks' ) }
						value={ waypointLabelFontStyle || 'normal' }
						onChange={ ( value ) =>
							setAttributes( {
								waypointLabelFontStyle:
									value === 'normal' ? '' : String( value ),
							} )
						}
						isBlock
					>
						<ToggleGroupControlOption
							value="normal"
							label={ __( 'Normal', 'kntnt-gpx-blocks' ) }
						/>
						<ToggleGroupControlOption
							value="italic"
							label={ __( 'Italic', 'kntnt-gpx-blocks' ) }
						/>
					</ToggleGroupControl>
				</PanelColorSettings>
			</InspectorControls>
			<div { ...blockProps }>
				<ServerSideRender
					block="kntnt-gpx-blocks/map"
					attributes={ {
						attachmentId,
						mapId,
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
					} }
				/>
			</div>
		</>
	);
};
