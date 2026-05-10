/**
 * Inspector-tab placement tests for the GPX Elevation Edit component
 * (issue #89, updated for #94).
 *
 * Pins WordPress's standard Settings/Styles split for the inspector
 * sidebar: behaviour-shaping controls (data source) live in the default
 * `<InspectorControls>` slot (Settings tab); appearance controls (the
 * Color panel) live in `<InspectorControls group="styles">` (Design
 * tab). Typography is delegated to core's block-level
 * `supports.typography` — the standard Typography panel is contributed
 * automatically by core into the styles group and the plugin's
 * `edit.tsx` renders no `TypographyToolsPanel` of its own (issue #94).
 *
 * The test mocks `@wordpress/block-editor`'s `InspectorControls` so the
 * `group` prop and the children rendered into each slot can be inspected
 * directly. PanelColorSettings and PanelBody are mocked as named capture
 * stubs so we can detect them by their displayName when walking the
 * slot's children.
 *
 * @since 1.0.0
 */

import { createElement, createRoot, flushSync } from '@wordpress/element';

/**
 * Captured `<InspectorControls>` render payload. Each entry corresponds
 * to one `InspectorControls` slot mounted during a render — typically two
 * (one default Settings slot and one `group="styles"` slot).
 *
 * @since 1.0.0
 */
type CapturedInspector = {
	group: string | undefined;
	children: React.ReactNode;
};
const capturedInspectors: CapturedInspector[] = [];

// Mock @wordpress/block-editor — `InspectorControls` is the surface under
// test, so it records its `group` prop and its `children` instead of
// rendering them. The other surfaces collapse to identifiable named
// component stubs so structural walks over the captured children can
// recognise them.
jest.mock(
	'@wordpress/block-editor',
	() => ( {
		__esModule: true,
		InspectorControls: ( {
			group,
			children,
		}: {
			group?: string;
			children: React.ReactNode;
		} ) => {
			capturedInspectors.push( { group, children } );
			return null;
		},
		PanelColorSettings: Object.assign( () => null, {
			displayName: 'PanelColorSettings',
		} ),
		useBlockProps: ( props: Record< string, unknown > = {} ) => ( {
			...props,
			className: ( props.className as string ) ?? '',
		} ),
	} ),
	{ virtual: true }
);

// Mock @wordpress/components — PanelBody is surfaced as a named component
// stub so we can detect it when walking InspectorControls children. The
// custom TypographyToolsPanel helper has been removed (issue #94), so the
// unstable ToolsPanel members are no longer mocked.
jest.mock(
	'@wordpress/components',
	() => ( {
		__esModule: true,
		Notice: () => null,
		PanelBody: Object.assign( () => null, { displayName: 'PanelBody' } ),
		SelectControl: () => null,
	} ),
	{ virtual: true }
);

// Mock @wordpress/server-side-render — the inspector-tab test does not
// care about the preview body, only about the inspector controls.
jest.mock(
	'@wordpress/server-side-render',
	() => ( { __esModule: true, default: () => null } ),
	{ virtual: true }
);

// Mock @wordpress/data — the Edit component reaches `useSelect` once for
// block-tree traversal and once for media resolution. An empty-store
// passthrough is enough for the inspector to render.
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
 * Recognised component-stub identifiers used by the children walker.
 * `displayName` is set on every mocked component that the inspector-tab
 * tests need to identify; the walker falls back to `name` for any helper
 * component defined inline inside `edit.tsx`.
 *
 * @since 1.0.0
 *
 * @param {React.ReactNode} child Candidate React node from a children walk.
 *
 * @return {string} Identifier for the child, or empty string for nodes
 *                  that don't carry a component type.
 */
function identify( child: React.ReactNode ): string {
	if ( ! child || typeof child !== 'object' ) {
		return '';
	}
	if ( ! ( 'type' in ( child as { type?: unknown } ) ) ) {
		return '';
	}
	const type = ( child as { type: unknown } ).type;
	if ( typeof type !== 'function' ) {
		return '';
	}
	const named = type as { displayName?: string; name?: string };
	return named.displayName ?? named.name ?? '';
}

/**
 * Walks an `InspectorControls` slot's children and returns the list of
 * top-level component identifiers it carries. The walk is shallow — it
 * inspects direct children only, which is sufficient because the
 * Elevation inspector composes the slot from a small, flat set of panels.
 *
 * `false` / `null` children (the JSX short-circuits the Edit component
 * uses for conditional panels) are filtered out so the assertions can
 * focus on the panels that actually render.
 *
 * @since 1.0.0
 *
 * @param {React.ReactNode} children Children captured from an `InspectorControls` slot.
 *
 * @return {string[]} Identifiers of the rendered top-level children.
 */
function collectChildren( children: React.ReactNode ): string[] {
	const list = Array.isArray( children ) ? children : [ children ];
	const result: string[] = [];
	for ( const child of list ) {
		const id = identify( child );
		if ( id !== '' ) {
			result.push( id );
		}
	}
	return result;
}

/**
 * Builds a full ElevationAttributes payload with the supplied overrides
 * applied on top of empty/default values.
 *
 * @since 1.0.0
 *
 * @param {Record<string, unknown>} overrides Per-test attribute overrides.
 *
 * @return {Record<string, unknown>} Attribute payload ready to feed into ElevationEdit.
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
		...overrides,
	};
}

/**
 * Mounts ElevationEdit, captures every `InspectorControls` render, and
 * unmounts. Returns the captured payloads in mount order so callers can
 * assert which slot carries which panel.
 *
 * @since 1.0.0
 *
 * @param {Record<string, unknown>} attributes Attribute payload to feed into ElevationEdit.
 *
 * @return {CapturedInspector[]} Captured `InspectorControls` payloads.
 */
function renderAndCapture(
	attributes: Record< string, unknown >
): CapturedInspector[] {
	capturedInspectors.length = 0;
	const container = document.createElement( 'div' );
	const root = createRoot( container );
	flushSync( () => {
		root.render(
			createElement( ElevationEdit, {
				attributes,
				setAttributes: () => undefined,
				clientId: 'test-client',
				isSelected: false,
				name: 'kntnt-gpx-blocks/elevation',
			} as never )
		);
	} );
	root.unmount();
	return [ ...capturedInspectors ];
}

describe( 'ElevationEdit InspectorControls tab placement (issue #89)', () => {
	it( 'renders one default InspectorControls slot and one styles slot', () => {
		const inspectors = renderAndCapture( buildAttributes() );

		const groups = inspectors.map( ( i ) => i.group );
		expect( groups ).toContain( undefined );
		expect( groups ).toContain( 'styles' );
		expect( inspectors ).toHaveLength( 2 );
	} );

	it( 'places the Color PanelColorSettings inside the styles slot', () => {
		const inspectors = renderAndCapture( buildAttributes() );

		const stylesSlot = inspectors.find( ( i ) => i.group === 'styles' );
		expect( stylesSlot ).toBeDefined();
		const children = collectChildren( stylesSlot!.children );
		expect( children ).toContain( 'PanelColorSettings' );
	} );

	it( 'renders no TypographyToolsPanel instance in any inspector slot (issue #94)', () => {
		// Typography is delegated to core's block-level `supports.typography`
		// (issue #94). The plugin's own edit.tsx therefore must not render
		// any custom TypographyToolsPanel — neither in the Settings slot nor
		// in the Styles slot.
		const inspectors = renderAndCapture( buildAttributes() );

		for ( const inspector of inspectors ) {
			const children = collectChildren( inspector.children );
			expect( children ).not.toContain( 'TypographyToolsPanel' );
		}
	} );

	it( 'keeps the Datakälla PanelBody inside the default (Settings) slot', () => {
		// The Datakälla PanelBody is conditional on having two or more
		// configured maps; the default-store mock returns no blocks, so we
		// build a Map-block snapshot via a custom select callback. Easier
		// path: render twice — once with the picker hidden (zero blocks)
		// and once with the picker shown (two configured maps). The "shown"
		// path is the one that proves placement; the "hidden" path proves
		// no Color/Typography panels leak into the Settings slot regardless.
		const settingsModule = require( '@wordpress/data' );
		const originalUseSelect = settingsModule.useSelect;

		// Force the Edit component to see two configured GPX Map blocks
		// so the Datakälla picker renders into the Settings slot.
		settingsModule.useSelect = ( fn: ( select: unknown ) => unknown ) => {
			const select = () => ( {
				getBlocks: () => [
					{
						name: 'kntnt-gpx-blocks/map',
						attributes: { attachmentId: 1, mapId: 'm-1' },
						innerBlocks: [],
					},
					{
						name: 'kntnt-gpx-blocks/map',
						attributes: { attachmentId: 2, mapId: 'm-2' },
						innerBlocks: [],
					},
				],
				getMedia: () => undefined,
				getBlockOrder: () => [],
				getBlockName: () => undefined,
				getBlockAttributes: () => undefined,
			} );
			return fn( select );
		};

		try {
			const inspectors = renderAndCapture( buildAttributes() );

			const settingsSlot = inspectors.find(
				( i ) => i.group === undefined
			);
			expect( settingsSlot ).toBeDefined();
			const settingsChildren = collectChildren( settingsSlot!.children );
			expect( settingsChildren ).toContain( 'PanelBody' );
			expect( settingsChildren ).not.toContain( 'PanelColorSettings' );
			expect( settingsChildren ).not.toContain( 'TypographyToolsPanel' );
		} finally {
			settingsModule.useSelect = originalUseSelect;
		}
	} );
} );
