/**
 * Inspector-shape tests for the GPX Elevation block's edit component.
 *
 * Pins the issue #143 acceptance criteria for the Elevation surface:
 *
 *   - The `Tooltip info` toggles are labelled `Distance` and `Height`
 *     (the redundant `Show ` prefix is dropped).
 *   - The `Tooltip distance` typography PanelBody is omitted when
 *     `tooltipShowDistance` is false; the `Tooltip height` typography
 *     PanelBody is omitted when `tooltipShowHeight` is false. Hidden
 *     attribute values are not cleared on hide.
 *
 * The Color-panel filtering is exercised in `inspector-color.test.tsx`
 * directly against `InspectorColorPanel`; this file only asserts what
 * `ElevationEdit` itself owns.
 *
 * @since 1.0.0
 */

import { createElement, createRoot, flushSync } from '@wordpress/element';

/**
 * Captured `ToggleControl` label string across every render in the
 * current test. The Elevation `Tooltip info` PanelBody owns exactly two
 * toggles, so this captures both labels in render order.
 *
 * @since 1.0.0
 */
const capturedToggleLabels: string[] = [];

/**
 * Captured `PanelBody` titles across every render in the current test.
 * Used to assert that the typography PanelBodies hide / reappear with
 * their master toggle.
 *
 * @since 1.0.0
 */
const capturedPanelBodyTitles: string[] = [];

/**
 * Captured `setAttributes` calls. The persistence test asserts that
 * turning a master toggle off does not write back to the dependent
 * attribute — the value must survive hide → re-enable round-trips
 * untouched.
 *
 * @since 1.0.0
 */
const capturedSetAttributesCalls: Array< Record< string, unknown > > = [];

jest.mock(
	'@wordpress/block-editor',
	() => ( {
		__esModule: true,
		useBlockProps: ( props: Record< string, unknown > = {} ) => ( {
			...props,
			className: ( props.className as string ) ?? '',
		} ),
		InspectorControls: ( { children }: { children: React.ReactNode } ) =>
			children,
	} ),
	{ virtual: true }
);

jest.mock(
	'@wordpress/components',
	() => ( {
		__esModule: true,
		PanelBody: ( props: {
			title?: string;
			children?: React.ReactNode;
		} ) => {
			capturedPanelBodyTitles.push( props.title ?? '' );
			return props.children;
		},
		ToggleControl: ( props: { label?: string } ) => {
			capturedToggleLabels.push( props.label ?? '' );
			return null;
		},
	} ),
	{ virtual: true }
);

jest.mock(
	'@wordpress/i18n',
	() => ( {
		__esModule: true,
		__: ( s: string ) => s,
		sprintf: ( template: string, ...args: unknown[] ) =>
			template.replace( /%s/g, () => String( args.shift() ?? '' ) ),
	} ),
	{ virtual: true }
);

// `InspectorColorPanel` is exercised by its own dedicated test file; here
// it collapses to a noop so the inspector tree stays renderable.
jest.mock(
	'./inspector-color',
	() => ( {
		__esModule: true,
		InspectorColorPanel: () => null,
	} ),
	{ virtual: true }
);

// The shared TypographyToolsPanel pulls in `@wordpress/block-editor`
// experimentals through its own import path; collapse to a noop so the
// inspector tree stays renderable without those mocks.
jest.mock(
	'../shared/typography-tools-panel',
	() => ( {
		__esModule: true,
		TypographyToolsPanel: () => null,
	} ),
	{ virtual: true }
);

// The data-source picker and the preview both pull in REST / chart
// dependencies that have no business loading in this inspector-shape
// test.
jest.mock(
	'./inspector-data-source',
	() => ( {
		__esModule: true,
		InspectorDataSource: () => null,
		shouldShowDataSourcePanel: () => false,
	} ),
	{ virtual: true }
);
jest.mock(
	'./preview',
	() => ( {
		__esModule: true,
		ElevationPreview: () => null,
	} ),
	{ virtual: true }
);

// `useMapBlocks` walks the editor block tree; return an empty list so
// the binding resolves to `no-map` without touching any real selectors.
jest.mock(
	'./use-map-blocks',
	() => ( {
		__esModule: true,
		useMapBlocks: () => ( {
			mapBlocks: [],
			configuredMapBlocks: [],
			mapOptions: [],
		} ),
	} ),
	{ virtual: true }
);
jest.mock(
	'./use-auto-pick-map-id',
	() => ( {
		__esModule: true,
		isAutoMapId: ( v: string ) => v === '' || v === 'auto',
		useAutoPickMapId: () => undefined,
	} ),
	{ virtual: true }
);
jest.mock(
	'./use-bound-map-payload',
	() => ( {
		__esModule: true,
		useBoundMapPayload: () => ( {
			data: null,
			isLoading: false,
			error: null,
		} ),
	} ),
	{ virtual: true }
);
jest.mock(
	'./useful-value',
	() => ( {
		__esModule: true,
		usefulValue: () => ( { resolved: '' } ),
	} ),
	{ virtual: true }
);
jest.mock(
	'../shared/dimensions-defaults',
	() => ( {
		__esModule: true,
		getDefaultMinHeight: () => undefined,
	} ),
	{ virtual: true }
);

import { ElevationEdit } from './edit';

/**
 * Renders the Elevation block's edit component with the given attribute
 * bag, captures every PanelBody title and ToggleControl label, and
 * unmounts. Captured arrays are cleared first so each test sees only
 * its own emissions.
 *
 * @param attributes      Block attribute bag.
 * @param onSetAttributes Optional setAttributes spy.
 */
function renderAndCapture(
	attributes: Record< string, unknown >,
	onSetAttributes?: ( next: Record< string, unknown > ) => void
): void {
	capturedToggleLabels.length = 0;
	capturedPanelBodyTitles.length = 0;
	capturedSetAttributesCalls.length = 0;

	const container = document.createElement( 'div' );
	const root = createRoot( container );
	flushSync( () => {
		root.render(
			createElement( ElevationEdit, {
				attributes,
				setAttributes: ( next: Record< string, unknown > ) => {
					capturedSetAttributesCalls.push( next );
					onSetAttributes?.( next );
				},
				clientId: 'test-client',
				isSelected: false,
				name: 'kntnt-gpx-blocks/elevation',
			} as never )
		);
	} );
	root.unmount();
}

describe( 'ElevationEdit Tooltip info toggles (issue #143)', () => {
	it( 'labels the toggles "Distance" and "Height" (drops the redundant "Show " prefix)', () => {
		renderAndCapture( {} );

		expect( capturedToggleLabels ).toEqual( [ 'Distance', 'Height' ] );
		expect( capturedToggleLabels ).not.toContain( 'Show distance' );
		expect( capturedToggleLabels ).not.toContain( 'Show height' );
	} );
} );

describe( 'ElevationEdit typography PanelBody visibility (issue #143)', () => {
	it( 'renders both typography PanelBodies when both Tooltip info toggles are on', () => {
		renderAndCapture( {
			tooltipShowDistance: true,
			tooltipShowHeight: true,
		} );

		expect( capturedPanelBodyTitles ).toContain( 'Tooltip distance' );
		expect( capturedPanelBodyTitles ).toContain( 'Tooltip height' );
	} );

	it( 'omits the "Tooltip distance" PanelBody when tooltipShowDistance is false', () => {
		renderAndCapture( {
			tooltipShowDistance: false,
			tooltipShowHeight: true,
		} );

		expect( capturedPanelBodyTitles ).not.toContain( 'Tooltip distance' );
		expect( capturedPanelBodyTitles ).toContain( 'Tooltip height' );
	} );

	it( 'omits the "Tooltip height" PanelBody when tooltipShowHeight is false', () => {
		renderAndCapture( {
			tooltipShowDistance: true,
			tooltipShowHeight: false,
		} );

		expect( capturedPanelBodyTitles ).not.toContain( 'Tooltip height' );
		expect( capturedPanelBodyTitles ).toContain( 'Tooltip distance' );
	} );

	it( 'omits BOTH typography PanelBodies when BOTH toggles are off (edge case)', () => {
		renderAndCapture( {
			tooltipShowDistance: false,
			tooltipShowHeight: false,
		} );

		expect( capturedPanelBodyTitles ).not.toContain( 'Tooltip distance' );
		expect( capturedPanelBodyTitles ).not.toContain( 'Tooltip height' );
	} );

	it( 'preserves the saved typography attribute when the master toggle is off (no clear-on-hide)', () => {
		// Render with Distance off and a saved typography aspect — the
		// PanelBody is hidden, but the attribute must not be cleared by
		// the edit component itself.
		renderAndCapture( {
			tooltipShowDistance: false,
			tooltipDistanceFontWeight: '700',
		} );

		// `setAttributes` may legitimately fire from other inspector
		// surfaces (e.g. the auto-pick effect); what must hold is that
		// no call wipes the saved typography attribute.
		const wipes = capturedSetAttributesCalls.filter(
			( call ) => 'tooltipDistanceFontWeight' in call
		);
		expect( wipes ).toEqual( [] );
	} );
} );
