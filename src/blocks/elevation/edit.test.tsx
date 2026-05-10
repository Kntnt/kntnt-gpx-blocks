/**
 * Regression tests for the GPX Elevation Edit component's ServerSideRender
 * wiring.
 *
 * The Edit component wraps a ServerSideRender preview in a `useBlockProps()`
 * div. The outer wrapper already carries the editor's chosen dimensions /
 * border / shadow / spacing inline style. If the same `style` attribute is
 * also forwarded to ServerSideRender, the SSR-side
 * `get_block_wrapper_attributes()` re-emits the inline style on the inner
 * wrapper — and the editor renders every dimension at twice the chosen
 * value (issue #97).
 *
 * These tests assert that the `attributes` object passed into
 * `<ServerSideRender>` does not contain a `style` key, even when the block
 * has a populated `style` attribute. They are pure structural assertions
 * over captured props — no real ServerSideRender call, no DOM, no editor.
 *
 * @since 0.7.1
 */

import { createElement, createRoot, flushSync } from '@wordpress/element';

// Capture every `attributes` prop ServerSideRender receives so the tests
// can inspect the final shape after the Edit component composes it.
const capturedAttributes: Array< Record< string, unknown > > = [];

// Mock @wordpress/server-side-render so the test can capture the prop
// payload without the real component performing a REST round-trip.
jest.mock(
	'@wordpress/server-side-render',
	() => {
		const MockServerSideRender = ( props: {
			attributes: Record< string, unknown >;
		} ) => {
			capturedAttributes.push( props.attributes );
			return null;
		};
		return { __esModule: true, default: MockServerSideRender };
	},
	{ virtual: true }
);

// Mock @wordpress/block-editor — the Edit component uses InspectorControls,
// PanelColorSettings, useBlockProps, useSettings, and two unstable
// typography controls. The mocks reproduce just enough surface for the
// component to render without errors.
jest.mock(
	'@wordpress/block-editor',
	() => ( {
		__esModule: true,
		InspectorControls: () => null,
		PanelColorSettings: () => null,
		useBlockProps: ( props: Record< string, unknown > = {} ) => ( {
			...props,
			className: ( props.className as string ) ?? '',
		} ),
		useSettings: () => [ undefined, undefined ],
		__experimentalFontFamilyControl: () => null,
		__experimentalFontAppearanceControl: () => null,
	} ),
	{ virtual: true }
);

// Mock @wordpress/components — the component pulls Notice, PanelBody,
// SelectControl, FontSizePicker, and two unstable ToolsPanel members.
jest.mock(
	'@wordpress/components',
	() => ( {
		__esModule: true,
		FontSizePicker: () => null,
		Notice: () => null,
		PanelBody: () => null,
		SelectControl: () => null,
		__experimentalToolsPanel: () => null,
		__experimentalToolsPanelItem: () => null,
	} ),
	{ virtual: true }
);

// Mock @wordpress/data — useSelect is the only entry point used. The select
// callback returns the merged surface every consumer needs: `getBlocks` and
// `getMedia` for the inspector / preview wiring, plus the `getBlockOrder` /
// `getBlockName` / `getBlockAttributes` trio that `useAutoPickMapId` reads
// when deciding whether to pre-bind this block to a preceding Map.
jest.mock(
	'@wordpress/data',
	() => ( {
		__esModule: true,
		useSelect: ( fn: ( select: unknown ) => unknown ) => {
			const select = () => ( {
				getBlocks: () => [],
				getMedia: () => undefined,
				getBlockOrder: () => [],
				getBlockName: () => undefined,
				getBlockAttributes: () => undefined,
			} );
			return fn( select );
		},
	} ),
	{ virtual: true }
);

// Mock @wordpress/core-data — only the store reference is read.
jest.mock(
	'@wordpress/core-data',
	() => ( { __esModule: true, store: 'core' } ),
	{ virtual: true }
);

// Mock @wordpress/i18n — translation passthrough.
jest.mock(
	'@wordpress/i18n',
	() => ( { __esModule: true, __: ( s: string ) => s } ),
	{ virtual: true }
);

// Import the component under test AFTER all mocks are registered so the
// jest module factory hooks resolve correctly.
import { ElevationEdit } from './edit';

/**
 * Builds a full ElevationAttributes payload with the supplied overrides
 * applied on top of empty/default values.
 *
 * @param overrides Per-test attribute overrides merged on top of the defaults.
 *
 * @return Attribute payload ready to feed into ElevationEdit.
 */
function buildAttributes(
	overrides: Record< string, unknown > = {}
): Record< string, unknown > {
	return {
		mapId: 'auto',
		axisColor: '',
		axisLabelColor: '',
		lineColor: '',
		cursorColor: '',
		tooltipBackground: '',
		tooltipColor: '',
		axisFontFamily: '',
		axisFontSize: '',
		axisFontWeight: '',
		axisFontStyle: '',
		tooltipFontFamily: '',
		tooltipFontSize: '',
		tooltipFontWeight: '',
		tooltipFontStyle: '',
		...overrides,
	};
}

describe( 'ElevationEdit ServerSideRender attributes', () => {
	beforeEach( () => {
		capturedAttributes.length = 0;
	} );

	it( 'does not forward the block-supports `style` attribute to ServerSideRender (issue #97)', () => {
		// Populate `style` exactly as core does when the editor's Dimensions
		// panel writes margin/padding/border values to the block.
		const attributes = buildAttributes( {
			style: {
				spacing: {
					margin: { top: '20px', right: '20px' },
					padding: { left: '10px' },
				},
				border: { width: '2px' },
			},
		} );

		const container = document.createElement( 'div' );
		const root = createRoot( container );
		flushSync( () => {
			root.render(
				createElement( ElevationEdit, {
					attributes,
					setAttributes: () => {},
					clientId: 'test',
					isSelected: false,
					name: 'kntnt-gpx-blocks/elevation',
				} as never )
			);
		} );
		root.unmount();

		expect( capturedAttributes ).toHaveLength( 1 );
		expect( capturedAttributes[ 0 ] ).not.toHaveProperty( 'style' );
	} );

	it( 'still forwards the theming attributes that the SSR pipeline needs', () => {
		const attributes = buildAttributes( {
			axisColor: '#abcdef',
			lineColor: '#123456',
			style: { spacing: { margin: { top: '20px' } } },
		} );

		const container = document.createElement( 'div' );
		const root = createRoot( container );
		flushSync( () => {
			root.render(
				createElement( ElevationEdit, {
					attributes,
					setAttributes: () => {},
					clientId: 'test',
					isSelected: false,
					name: 'kntnt-gpx-blocks/elevation',
				} as never )
			);
		} );
		root.unmount();

		const captured = capturedAttributes[ 0 ];
		expect( captured ).toBeDefined();
		expect( captured?.axisColor ).toBe( '#abcdef' );
		expect( captured?.lineColor ).toBe( '#123456' );
		expect( captured?.mapId ).toBe( 'auto' );
	} );
} );
