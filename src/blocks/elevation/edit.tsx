/**
 * GPX Elevation edit component.
 *
 * Renders the block representation inside the Gutenberg editor. At this stub
 * stage the component outputs a labelled placeholder div so that the block can
 * be inserted and identified before the full editor UI is implemented.
 *
 * @since 1.0.0
 */

import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

/**
 * Editor preview for the GPX Elevation block.
 *
 * Returns a `useBlockProps`-wrapped div with a localised placeholder string.
 * The full implementation (ServerSideRender, InspectorControls) arrives in a
 * later issue once block attributes are defined.
 *
 * @since 1.0.0
 */
export const ElevationEdit = (): JSX.Element => {
	// Merge block-editor props (className, data attributes) into the wrapper.
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			{ __( 'GPX Elevation — placeholder', 'kntnt-gpx-blocks' ) }
		</div>
	);
};
