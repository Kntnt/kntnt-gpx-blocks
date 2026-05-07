/**
 * GPX Statistics edit component.
 *
 * Renders the block representation inside the Gutenberg editor. At this stub
 * stage the component outputs a labelled placeholder `<dl>` element so that
 * the block can be inserted and identified before the full editor UI is
 * implemented.
 *
 * @since 1.0.0
 */

import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

/**
 * Editor preview for the GPX Statistics block.
 *
 * Returns a `useBlockProps`-wrapped `<dl>` with localised placeholder headings
 * for the five statistics the block will eventually render. The full
 * implementation (ServerSideRender, InspectorControls) arrives in a later
 * issue once block attributes are defined.
 *
 * @since 1.0.0
 */
export const StatisticsEdit = (): JSX.Element => {
	// Merge block-editor props (className, data attributes) into the wrapper.
	const blockProps = useBlockProps();

	return (
		<dl { ...blockProps }>
			<div>
				<dt>{ __( 'Total length', 'kntnt-gpx-blocks' ) }</dt>
				<dd>—</dd>
			</div>
			<div>
				<dt>{ __( 'Lowest elevation', 'kntnt-gpx-blocks' ) }</dt>
				<dd>—</dd>
			</div>
			<div>
				<dt>{ __( 'Highest elevation', 'kntnt-gpx-blocks' ) }</dt>
				<dd>—</dd>
			</div>
			<div>
				<dt>{ __( 'Total ascent', 'kntnt-gpx-blocks' ) }</dt>
				<dd>—</dd>
			</div>
			<div>
				<dt>{ __( 'Total descent', 'kntnt-gpx-blocks' ) }</dt>
				<dd>—</dd>
			</div>
		</dl>
	);
};
