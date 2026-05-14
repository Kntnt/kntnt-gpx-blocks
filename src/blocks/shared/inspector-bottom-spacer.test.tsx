/**
 * Unit tests for the shared InspectorBottomSpacer component.
 *
 * The component exists to give popover-based controls in the last
 * Design-tab panel (notably `__experimentalFontAppearanceControl`,
 * whose legacy CustomSelectControl has `flip: false` hardcoded) enough
 * scroll-headroom to open downward without being squashed by Floating
 * UI's size middleware against the viewport bottom. The actual
 * squashing is a real-layout, real-popover issue and cannot be
 * reproduced inside JSDOM. These tests therefore lock the component's
 * narrow contract: a single `<div aria-hidden>` of the requested
 * height, slotted into `InspectorControls group="styles"`.
 *
 * Strategy: mock `InspectorControls` as a transparent passthrough that
 * forwards children, then assert on the rendered DOM.
 *
 * @since 1.0.0
 */

import { createElement, createRoot, flushSync } from '@wordpress/element';

jest.mock(
	'@wordpress/block-editor',
	() => {
		const { createElement: ce } =
			jest.requireActual( '@wordpress/element' );
		return {
			__esModule: true,
			InspectorControls: ( props: {
				children?: React.ReactNode;
				group?: string;
			} ) =>
				ce(
					'div',
					{
						'data-testid': 'inspector-controls',
						'data-group': props.group,
					},
					props.children
				),
		};
	},
	{ virtual: true }
);

import { InspectorBottomSpacer } from './inspector-bottom-spacer';

/**
 * Renders the spacer with optional props into a fresh DOM container
 * and returns the rendered spacer `<div>` for assertions.
 *
 * @since 1.0.0
 *
 * @param props        Optional props forwarded to the component.
 * @param props.height Spacer height in pixels.
 * @return The spacer's inner `<div>` element.
 */
function renderSpacer( props: { height?: number } = {} ): HTMLDivElement {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	flushSync( () =>
		root.render( createElement( InspectorBottomSpacer, props ) )
	);
	const slot = container.querySelector(
		'[data-testid="inspector-controls"]'
	);
	const spacer = slot?.firstElementChild;
	if ( ! ( spacer instanceof HTMLDivElement ) ) {
		throw new Error( 'Expected an HTMLDivElement spacer' );
	}
	return spacer;
}

describe( 'InspectorBottomSpacer', () => {
	it( 'renders a 135px-high div by default', () => {
		const spacer = renderSpacer();
		expect( spacer.style.height ).toBe( '135px' );
		expect( spacer.style.flexShrink ).toBe( '0' );
		expect( spacer.getAttribute( 'aria-hidden' ) ).toBe( 'true' );
	} );

	it( 'honours a custom height prop', () => {
		const spacer = renderSpacer( { height: 250 } );
		expect( spacer.style.height ).toBe( '250px' );
	} );
} );
