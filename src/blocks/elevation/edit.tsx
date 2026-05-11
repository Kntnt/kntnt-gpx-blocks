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
 * @since 1.0.0
 */

import { useRef, useState, useEffect } from '@wordpress/element';
import {
	InspectorControls,
	PanelColorSettings,
	useBlockProps,
} from '@wordpress/block-editor';
import type { BlockEditProps } from '@wordpress/blocks';
import { Notice, PanelBody, SelectControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';

import { useAutoPickMapId } from './use-auto-pick-map-id';
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
	const configuredMapBlocks = mapBlocks.filter(
		( b ) => ( b.attributes.attachmentId ?? 0 ) > 0
	);
	const mapOptions = configuredMapBlocks.map( ( b, index ) => {
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

	// Build the snapshot of configured Map blocks that ServerSideRender
	// forwards to the PHP renderer. Without it, Render_Elevation falls back
	// to parse_blocks(post_content) which only sees the last save — so a
	// freshly inserted Map (or a freshly auto-generated mapId) would resolve
	// to no-map / map-not-found in the editor preview. The snapshot mirrors
	// the parse_blocks() shape so Resolve_Map_Id::resolve_from_blocks()
	// consumes it without translation. The attribute is registered with
	// role:local in block.json so it never reaches saved post_content.
	const editorBlockSnapshot = configuredMapBlocks.map( ( b ) => ( {
		blockName: 'kntnt-gpx-blocks/map',
		attrs: {
			mapId: ( b.attributes.mapId as string | undefined ) ?? '',
			attachmentId: b.attributes.attachmentId as number,
		},
		innerBlocks: [],
	} ) );

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

	// Auto-bind to the single configured map. When exactly one Map block is
	// configured on the page the picker is hidden and the user has no way to
	// pick anything else, so the attribute is kept aligned with that map's
	// id. The check is symmetric: we only write when the current value would
	// produce a different resolution (different id, or the "auto"/"" sentinel
	// that resolves the same way only by accident of count === 1).
	const singleMapId =
		configuredMapBlocks.length === 1
			? ( configuredMapBlocks[ 0 ].attributes.mapId as
					| string
					| undefined ) ?? ''
			: '';
	useEffect( () => {
		if ( configuredMapBlocks.length !== 1 ) {
			return;
		}
		if ( singleMapId === '' ) {
			return;
		}
		if ( mapId === singleMapId ) {
			return;
		}
		setAttributes( { mapId: singleMapId } );
	}, [ configuredMapBlocks.length, singleMapId, mapId, setAttributes ] );

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
					colorSettings={ [
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
