/**
 * Invisible 400px spacer rendered into the bottom of the block
 * inspector's Design tab. Gives popover-based controls in the last
 * panel — notably FontAppearanceControl, whose legacy CustomSelectControl
 * has flip: false hardcoded — enough vertical room to open without
 * being squashed by the size middleware against the viewport bottom.
 *
 * Used as the last <InspectorControls> child in each block's edit
 * component; Fills render in declaration order, so the spacer lands
 * at the bottom of the Design tab.
 *
 * @since 1.0.0
 */

import { InspectorControls } from '@wordpress/block-editor';

interface InspectorBottomSpacerProps {
	height?: number;
}

/**
 * Renders the bottom-of-inspector headroom spacer.
 *
 * @since 1.0.0
 *
 * @param props        See {@link InspectorBottomSpacerProps}.
 * @param props.height Spacer height in pixels. Defaults to 400, which
 *                     matches the dropdown's own max-height.
 */
export function InspectorBottomSpacer( {
	height = 400,
}: InspectorBottomSpacerProps ): JSX.Element {
	return (
		<InspectorControls group="styles">
			<div
				aria-hidden="true"
				style={ { height: `${ height }px`, flexShrink: 0 } }
			/>
		</InspectorControls>
	);
}
