/**
 * GPX Statistics edit component.
 *
 * Renders a data-source picker in the inspector sidebar and a live
 * ServerSideRender preview in the block canvas. The picker lists "Auto" plus
 * every GPX Map block on the page so the editor can pin the statistics to a
 * specific map when the post has more than one.
 *
 * @since 1.0.0
 */

import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import type { BlockEditProps } from '@wordpress/blocks';
import { PanelBody, SelectControl } from '@wordpress/components';
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
 * Editor preview for the GPX Statistics block.
 *
 * Shows an InspectorControls panel "Datakälla" with a SelectControl listing
 * "Auto (single map on page)" plus one entry per GPX Map block found on the
 * page. The body is a ServerSideRender preview that mirrors the frontend output.
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
	const blockProps = useBlockProps();
	const { mapId } = attributes;

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
			</InspectorControls>

			<div { ...blockProps }>
				<ServerSideRender
					block="kntnt-gpx-blocks/statistics"
					attributes={ { mapId } }
				/>
			</div>
		</>
	);
};
