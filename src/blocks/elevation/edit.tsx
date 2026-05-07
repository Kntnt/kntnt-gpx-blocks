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
} from '@wordpress/block-editor';
import type { BlockEditProps } from '@wordpress/blocks';
import {
	FontSizePicker,
	Notice,
	PanelBody,
	SelectControl,
	TextControl,
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
 * Font family options for axis and tooltip typography controls.
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

				<PanelBody
					title={ __( 'Axis typography', 'kntnt-gpx-blocks' ) }
				>
					<SelectControl
						label={ __( 'Font family', 'kntnt-gpx-blocks' ) }
						value={ axisFontFamily }
						options={ FONT_FAMILY_OPTIONS }
						onChange={ ( value ) =>
							setAttributes( { axisFontFamily: value } )
						}
					/>
					<FontSizePicker
						value={ axisFontSize || undefined }
						onChange={ ( value ) =>
							setAttributes( {
								axisFontSize:
									value !== undefined ? String( value ) : '',
							} )
						}
						withReset={ true }
					/>
					<SelectControl
						label={ __( 'Font weight', 'kntnt-gpx-blocks' ) }
						value={ axisFontWeight || 'normal' }
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
								axisFontWeight: value === 'normal' ? '' : value,
							} )
						}
					/>
					<SelectControl
						label={ __( 'Font style', 'kntnt-gpx-blocks' ) }
						value={ axisFontStyle || 'normal' }
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
								axisFontStyle: value === 'normal' ? '' : value,
							} )
						}
					/>
				</PanelBody>

				<PanelBody
					title={ __( 'Tooltip typography', 'kntnt-gpx-blocks' ) }
				>
					<SelectControl
						label={ __( 'Font family', 'kntnt-gpx-blocks' ) }
						value={ tooltipFontFamily }
						options={ FONT_FAMILY_OPTIONS }
						onChange={ ( value ) =>
							setAttributes( { tooltipFontFamily: value } )
						}
					/>
					<FontSizePicker
						value={ tooltipFontSize || undefined }
						onChange={ ( value ) =>
							setAttributes( {
								tooltipFontSize:
									value !== undefined ? String( value ) : '',
							} )
						}
						withReset={ true }
					/>
					<SelectControl
						label={ __( 'Font weight', 'kntnt-gpx-blocks' ) }
						value={ tooltipFontWeight || 'normal' }
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
								tooltipFontWeight:
									value === 'normal' ? '' : value,
							} )
						}
					/>
					<SelectControl
						label={ __( 'Font style', 'kntnt-gpx-blocks' ) }
						value={ tooltipFontStyle || 'normal' }
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
								tooltipFontStyle:
									value === 'normal' ? '' : value,
							} )
						}
					/>
				</PanelBody>
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
