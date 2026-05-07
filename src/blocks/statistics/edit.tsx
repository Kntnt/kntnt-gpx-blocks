/**
 * GPX Statistics edit component.
 *
 * Renders a data-source picker, a Headers panel, and a Values panel in the
 * inspector sidebar, and a live ServerSideRender preview in the block canvas.
 * Colour and typography changes are injected as inline CSS variables on the
 * wrapper div so the editor preview updates instantly without a round-trip to
 * ServerSideRender.
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
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';

/**
 * Attributes for the GPX Statistics block.
 *
 * @since 1.0.0
 */
interface StatisticsAttributes {
	mapId: string;
	headerBackground: string;
	headerColor: string;
	headerFontFamily: string;
	headerFontSize: string;
	headerFontWeight: string;
	headerFontStyle: string;
	valueBackground: string;
	valueColor: string;
	valueFontFamily: string;
	valueFontSize: string;
	valueFontWeight: string;
	valueFontStyle: string;
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
 * Font family options for header and value typography controls.
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
 * Editor preview for the GPX Statistics block.
 *
 * Shows an InspectorControls sidebar with panels for data source, header
 * theming, and value theming. Colour and typography changes are applied
 * immediately via inline CSS variables on the wrapper div — no
 * ServerSideRender round-trip for cosmetic edits.
 *
 * @since 1.0.0
 *
 * @param {Object}   props               Standard Gutenberg block edit props.
 * @param {Object}   props.attributes    Current block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 */
export const StatisticsEdit = ( {
	attributes,
	setAttributes,
}: BlockEditProps< StatisticsAttributes > ): JSX.Element => {
	const {
		mapId,
		headerBackground,
		headerColor,
		headerFontFamily,
		headerFontSize,
		headerFontWeight,
		headerFontStyle,
		valueBackground,
		valueColor,
		valueFontFamily,
		valueFontSize,
		valueFontWeight,
		valueFontStyle,
	} = attributes;

	// Build a style object carrying every non-empty theming attribute as a CSS
	// custom property so the editor preview updates instantly.
	const inlineStyle: Record< string, string > = {};
	if ( headerBackground ) {
		inlineStyle[ '--kntnt-gpx-blocks-header-background' ] =
			headerBackground;
	}
	if ( headerColor ) {
		inlineStyle[ '--kntnt-gpx-blocks-header-color' ] = headerColor;
	}
	if ( headerFontFamily ) {
		inlineStyle[ '--kntnt-gpx-blocks-header-font-family' ] =
			headerFontFamily;
	}
	if ( headerFontSize ) {
		inlineStyle[ '--kntnt-gpx-blocks-header-font-size' ] = headerFontSize;
	}
	if ( headerFontWeight ) {
		inlineStyle[ '--kntnt-gpx-blocks-header-font-weight' ] =
			headerFontWeight;
	}
	if ( headerFontStyle ) {
		inlineStyle[ '--kntnt-gpx-blocks-header-font-style' ] = headerFontStyle;
	}
	if ( valueBackground ) {
		inlineStyle[ '--kntnt-gpx-blocks-value-background' ] = valueBackground;
	}
	if ( valueColor ) {
		inlineStyle[ '--kntnt-gpx-blocks-value-color' ] = valueColor;
	}
	if ( valueFontFamily ) {
		inlineStyle[ '--kntnt-gpx-blocks-value-font-family' ] = valueFontFamily;
	}
	if ( valueFontSize ) {
		inlineStyle[ '--kntnt-gpx-blocks-value-font-size' ] = valueFontSize;
	}
	if ( valueFontWeight ) {
		inlineStyle[ '--kntnt-gpx-blocks-value-font-weight' ] = valueFontWeight;
	}
	if ( valueFontStyle ) {
		inlineStyle[ '--kntnt-gpx-blocks-value-font-style' ] = valueFontStyle;
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
	const options = [
		{
			label: __( 'Auto (single map on page)', 'kntnt-gpx-blocks' ),
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
				<PanelBody
					title={ __( 'Datakälla', 'kntnt-gpx-blocks' ) }
					initialOpen={ true }
				>
					<SelectControl
						label={ __( 'Map', 'kntnt-gpx-blocks' ) }
						value={ mapId }
						options={ options }
						onChange={ ( value: string ) =>
							setAttributes( { mapId: value } )
						}
					/>
				</PanelBody>

				{ /* @ts-ignore — PanelColorSettings is exported from @wordpress/block-editor but its typings lag behind. */ }
				<PanelColorSettings
					title={ __( 'Headers', 'kntnt-gpx-blocks' ) }
					colorSettings={ [
						{
							value: headerBackground,
							onChange: ( value: string | undefined ) =>
								setAttributes( {
									headerBackground: value ?? '',
								} ),
							label: __( 'Background', 'kntnt-gpx-blocks' ),
						},
						{
							value: headerColor,
							onChange: ( value: string | undefined ) =>
								setAttributes( { headerColor: value ?? '' } ),
							label: __( 'Text colour', 'kntnt-gpx-blocks' ),
						},
					] }
				>
					<SelectControl
						label={ __( 'Font family', 'kntnt-gpx-blocks' ) }
						value={ headerFontFamily }
						options={ FONT_FAMILY_OPTIONS }
						onChange={ ( value ) =>
							setAttributes( { headerFontFamily: value } )
						}
					/>
					<FontSizePicker
						value={ headerFontSize || undefined }
						onChange={ ( value ) =>
							setAttributes( {
								headerFontSize:
									value !== undefined ? String( value ) : '',
							} )
						}
						withReset={ true }
					/>
					<SelectControl
						label={ __( 'Font weight', 'kntnt-gpx-blocks' ) }
						value={ headerFontWeight || 'normal' }
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
								headerFontWeight:
									value === 'normal' ? '' : value,
							} )
						}
					/>
					<SelectControl
						label={ __( 'Font style', 'kntnt-gpx-blocks' ) }
						value={ headerFontStyle || 'normal' }
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
								headerFontStyle:
									value === 'normal' ? '' : value,
							} )
						}
					/>
				</PanelColorSettings>

				{ /* @ts-ignore — PanelColorSettings is exported from @wordpress/block-editor but its typings lag behind. */ }
				<PanelColorSettings
					title={ __( 'Values', 'kntnt-gpx-blocks' ) }
					colorSettings={ [
						{
							value: valueBackground,
							onChange: ( value: string | undefined ) =>
								setAttributes( {
									valueBackground: value ?? '',
								} ),
							label: __( 'Background', 'kntnt-gpx-blocks' ),
						},
						{
							value: valueColor,
							onChange: ( value: string | undefined ) =>
								setAttributes( { valueColor: value ?? '' } ),
							label: __( 'Text colour', 'kntnt-gpx-blocks' ),
						},
					] }
				>
					<SelectControl
						label={ __( 'Font family', 'kntnt-gpx-blocks' ) }
						value={ valueFontFamily }
						options={ FONT_FAMILY_OPTIONS }
						onChange={ ( value ) =>
							setAttributes( { valueFontFamily: value } )
						}
					/>
					<FontSizePicker
						value={ valueFontSize || undefined }
						onChange={ ( value ) =>
							setAttributes( {
								valueFontSize:
									value !== undefined ? String( value ) : '',
							} )
						}
						withReset={ true }
					/>
					<SelectControl
						label={ __( 'Font weight', 'kntnt-gpx-blocks' ) }
						value={ valueFontWeight || 'normal' }
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
								valueFontWeight:
									value === 'normal' ? '' : value,
							} )
						}
					/>
					<SelectControl
						label={ __( 'Font style', 'kntnt-gpx-blocks' ) }
						value={ valueFontStyle || 'normal' }
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
								valueFontStyle: value === 'normal' ? '' : value,
							} )
						}
					/>
				</PanelColorSettings>
			</InspectorControls>

			<div { ...blockProps }>
				{ /* ref wrapper so useEffect can inspect the rendered SSR output */ }
				<div ref={ ssrWrapperRef }>
					<ServerSideRender
						block="kntnt-gpx-blocks/statistics"
						attributes={ {
							mapId,
							headerBackground,
							headerColor,
							headerFontFamily,
							headerFontSize,
							headerFontWeight,
							headerFontStyle,
							valueBackground,
							valueColor,
							valueFontFamily,
							valueFontSize,
							valueFontWeight,
							valueFontStyle,
						} }
					/>
				</div>
			</div>
		</>
	);
};
