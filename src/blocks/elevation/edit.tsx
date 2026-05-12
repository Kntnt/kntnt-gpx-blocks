/**
 * GPX Elevation edit component — empty-slate baseline.
 *
 * Renders an empty wrapper with a placeholder label so the block is visible
 * in the editor canvas. This file is the Step 0 reset of the rebuild plan
 * in `docs/elevation-rebuild.md`; subsequent steps reintroduce inspector
 * controls, the data-source binding, and the SVG chart itself.
 *
 * @since 1.0.0
 */

import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

export const ElevationEdit = (): JSX.Element => {
	const blockProps = useBlockProps( {
		className: 'kntnt-gpx-blocks-elevation',
	} );

	return (
		<div { ...blockProps }>
			{ __( 'GPX Elevation', 'kntnt-gpx-blocks' ) }
		</div>
	);
};
