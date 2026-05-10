/**
 * Inspector-shape tests for the GPX Elevation Edit component's data-source
 * picker (issue #98).
 *
 * The picker has three visibility regimes driven by the count of *configured*
 * GPX Map blocks on the page (configured = `attachmentId > 0`):
 *
 * - Zero maps: the `<PanelBody title="Datakälla">` panel is not rendered.
 *   The SSR layer surfaces a "No GPX Map block on this page" error notice
 *   which is unchanged by this issue.
 * - One map: the picker is hidden and an effect writes the single map's
 *   `mapId` into this block's `mapId` attribute (unless already aligned).
 * - Two or more maps: the picker is rendered with one option per configured
 *   map. The legacy "Auto" entry is omitted because it cannot resolve
 *   deterministically with more than one map on the page.
 *
 * The component also pre-binds freshly inserted Elevation blocks to the
 * closest preceding `kntnt-gpx-blocks/map` block in document order via the
 * `useAutoPickMapId` hook (`use-auto-pick-map-id.ts`). Tests for that hook
 * use a separate harness because they exercise the block-editor
 * `getBlockOrder` / `getBlockAttributes` selectors rather than the
 * inspector surface.
 *
 * @since 1.0.0
 */

import { createElement, createRoot, flushSync } from '@wordpress/element';

/**
 * Captured payload for a single `PanelBody` invocation in the current test.
 * The mock pushes one entry per render so the test can assert which panels
 * were rendered and in what order.
 *
 * @since 1.0.0
 */
type CapturedPanelBody = {
	title: string;
	children: unknown;
};
const mockCapturedPanelBodies: CapturedPanelBody[] = [];

/**
 * Captured payload for a single `SelectControl` invocation. The picker is
 * the only SelectControl in the surface so tests look for the entry whose
 * options array carries the per-map labels.
 *
 * @since 1.0.0
 */
type CapturedSelectControl = {
	label: string;
	value: string;
	options: Array< { label: string; value: string } >;
	onChange: ( value: string ) => void;
};
const mockCapturedSelectControls: CapturedSelectControl[] = [];

/**
 * Captured `setAttributes` writes during a single mount. The harness pushes
 * each call's payload so the test can assert the auto-bind effect wrote
 * the expected attribute slice.
 *
 * @since 1.0.0
 */
const capturedSetAttributes: Array< Record< string, unknown > > = [];

/**
 * Block-tree fixture for the current test. The `@wordpress/data` mock
 * reads it via the `useSelect` callback so each test can swap in a
 * different scenario (zero / one / many maps) without re-importing the
 * module under test.
 *
 * @since 1.0.0
 */
type FixtureBlock = {
	clientId: string;
	name: string;
	attributes: Record< string, unknown >;
	innerBlocks: FixtureBlock[];
};
let mockFixtureBlocks: FixtureBlock[] = [];

// Mock @wordpress/server-side-render so the test never reaches REST.
jest.mock(
	'@wordpress/server-side-render',
	() => ( { __esModule: true, default: () => null } ),
	{ virtual: true }
);

// Mock @wordpress/block-editor — only the surfaces the Edit component
// touches need real behaviour. `InspectorControls` passes children through
// so the captured `PanelBody` entries see whatever the inspector renders.
jest.mock(
	'@wordpress/block-editor',
	() => ( {
		__esModule: true,
		InspectorControls: ( { children }: { children: unknown } ) => children,
		PanelColorSettings: () => null,
		useBlockProps: ( props: Record< string, unknown > = {} ) => ( {
			...props,
			className: ( props.className as string ) ?? '',
		} ),
		useSettings: () => [ undefined, undefined ],
		__experimentalFontFamilyControl: () => null,
		__experimentalFontAppearanceControl: () => null,
		store: 'core/block-editor',
	} ),
	{ virtual: true }
);

// Mock @wordpress/components — PanelBody captures its title plus children
// so tests can assert which panels render. SelectControl captures its
// option list so tests can verify the "Auto" entry's presence / absence.
jest.mock(
	'@wordpress/components',
	() => ( {
		__esModule: true,
		PanelBody: ( props: CapturedPanelBody ) => {
			mockCapturedPanelBodies.push( {
				title: props.title,
				children: props.children,
			} );
			return props.children;
		},
		SelectControl: ( props: CapturedSelectControl ) => {
			mockCapturedSelectControls.push( props );
			return null;
		},
		FontSizePicker: () => null,
		Notice: () => null,
		__experimentalToolsPanel: () => null,
		__experimentalToolsPanelItem: () => null,
	} ),
	{ virtual: true }
);

// Mock @wordpress/data — the Edit component drives two stores:
// core/block-editor (for `getBlocks` plus the `getBlockOrder` /
// `getBlockAttributes` / `getBlockName` reads from `useAutoPickMapId`)
// and core/core-data (for media metadata).
jest.mock(
	'@wordpress/data',
	() => ( {
		__esModule: true,
		useSelect: ( fn: ( select: unknown ) => unknown ) => {
			const select = ( storeId: unknown ) => {
				if (
					storeId === 'core/block-editor' ||
					storeId === undefined
				) {
					return {
						getBlocks: () => mockFixtureBlocks,
						getBlockOrder: () =>
							mockFixtureBlocks.map(
								( block ) => block.clientId
							),
						getBlockName: ( clientId: string ) =>
							mockFixtureBlocks.find(
								( block ) => block.clientId === clientId
							)?.name,
						getBlockAttributes: ( clientId: string ) =>
							mockFixtureBlocks.find(
								( block ) => block.clientId === clientId
							)?.attributes,
					};
				}
				return {
					getMedia: () => ( { slug: 'track', source_url: '' } ),
				};
			};
			return fn( select );
		},
	} ),
	{ virtual: true }
);

// Mock @wordpress/core-data — only the store sentinel is read.
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

// Import the component under test AFTER all mocks are registered so jest's
// module factory hooks resolve correctly.
import { ElevationEdit } from './edit';

/**
 * Builds a full ElevationAttributes payload with the supplied overrides.
 *
 * @param overrides Per-test attribute overrides merged on top of defaults.
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

/**
 * Mounts the Edit component, captures the inspector surface, then unmounts.
 *
 * @param attributes Attribute payload to feed into ElevationEdit.
 * @param clientId   Client ID for the mounted block (defaults to a sentinel).
 */
function mountElevation(
	attributes: Record< string, unknown >,
	clientId = 'elevation-self'
): void {
	const container = document.createElement( 'div' );
	const root = createRoot( container );
	flushSync( () => {
		root.render(
			createElement( ElevationEdit, {
				clientId,
				attributes,
				setAttributes: ( next: Record< string, unknown > ) => {
					capturedSetAttributes.push( next );
				},
				isSelected: false,
				name: 'kntnt-gpx-blocks/elevation',
			} as never )
		);
	} );
	root.unmount();
}

describe( 'ElevationEdit data-source picker visibility (issue #98)', () => {
	beforeEach( () => {
		mockCapturedPanelBodies.length = 0;
		mockCapturedSelectControls.length = 0;
		capturedSetAttributes.length = 0;
		mockFixtureBlocks = [];
	} );

	it( 'hides the Datakälla picker when zero maps are configured', () => {
		mockFixtureBlocks = [
			{
				clientId: 'elevation-self',
				name: 'kntnt-gpx-blocks/elevation',
				attributes: { mapId: 'auto' },
				innerBlocks: [],
			},
		];

		mountElevation( buildAttributes() );

		expect(
			mockCapturedPanelBodies.some( ( p ) => p.title === 'Datakälla' )
		).toBe( false );
	} );

	it( 'hides the picker when exactly one map is configured and effect-writes the single mapId', () => {
		mockFixtureBlocks = [
			{
				clientId: 'map-only',
				name: 'kntnt-gpx-blocks/map',
				attributes: { mapId: 'map-aaa111', attachmentId: 42 },
				innerBlocks: [],
			},
			{
				clientId: 'elevation-self',
				name: 'kntnt-gpx-blocks/elevation',
				attributes: { mapId: 'auto' },
				innerBlocks: [],
			},
		];

		mountElevation( buildAttributes( { mapId: 'auto' } ) );

		expect(
			mockCapturedPanelBodies.some( ( p ) => p.title === 'Datakälla' )
		).toBe( false );
		expect( capturedSetAttributes ).toContainEqual( {
			mapId: 'map-aaa111',
		} );
	} );

	it( 'renders the picker when two or more maps are configured', () => {
		mockFixtureBlocks = [
			{
				clientId: 'map-1',
				name: 'kntnt-gpx-blocks/map',
				attributes: { mapId: 'map-aaa111', attachmentId: 10 },
				innerBlocks: [],
			},
			{
				clientId: 'map-2',
				name: 'kntnt-gpx-blocks/map',
				attributes: { mapId: 'map-bbb222', attachmentId: 20 },
				innerBlocks: [],
			},
			{
				clientId: 'elevation-self',
				name: 'kntnt-gpx-blocks/elevation',
				attributes: { mapId: 'map-aaa111' },
				innerBlocks: [],
			},
		];

		mountElevation( buildAttributes( { mapId: 'map-aaa111' } ) );

		expect(
			mockCapturedPanelBodies.some( ( p ) => p.title === 'Datakälla' )
		).toBe( true );
	} );

	it( 'omits the "Auto" option when two or more maps are configured', () => {
		mockFixtureBlocks = [
			{
				clientId: 'map-1',
				name: 'kntnt-gpx-blocks/map',
				attributes: { mapId: 'map-aaa111', attachmentId: 10 },
				innerBlocks: [],
			},
			{
				clientId: 'map-2',
				name: 'kntnt-gpx-blocks/map',
				attributes: { mapId: 'map-bbb222', attachmentId: 20 },
				innerBlocks: [],
			},
			{
				clientId: 'elevation-self',
				name: 'kntnt-gpx-blocks/elevation',
				attributes: { mapId: 'map-aaa111' },
				innerBlocks: [],
			},
		];

		mountElevation( buildAttributes( { mapId: 'map-aaa111' } ) );

		expect( mockCapturedSelectControls ).toHaveLength( 1 );
		const optionValues = mockCapturedSelectControls[ 0 ].options.map(
			( o ) => o.value
		);
		expect( optionValues ).not.toContain( 'auto' );
		expect( optionValues ).toEqual( [ 'map-aaa111', 'map-bbb222' ] );
	} );

	it( "does not effect-write when the single map's mapId already matches", () => {
		mockFixtureBlocks = [
			{
				clientId: 'map-only',
				name: 'kntnt-gpx-blocks/map',
				attributes: { mapId: 'map-aaa111', attachmentId: 42 },
				innerBlocks: [],
			},
			{
				clientId: 'elevation-self',
				name: 'kntnt-gpx-blocks/elevation',
				attributes: { mapId: 'map-aaa111' },
				innerBlocks: [],
			},
		];

		mountElevation( buildAttributes( { mapId: 'map-aaa111' } ) );

		const mapIdWrites = capturedSetAttributes.filter(
			( w ) => 'mapId' in w
		);
		expect( mapIdWrites ).toEqual( [] );
	} );
} );

describe( 'ElevationEdit auto-pick on insert (issue #98)', () => {
	beforeEach( () => {
		mockCapturedPanelBodies.length = 0;
		mockCapturedSelectControls.length = 0;
		capturedSetAttributes.length = 0;
		mockFixtureBlocks = [];
	} );

	it( 'pre-sets mapId to the closest preceding Map block on insert', () => {
		mockFixtureBlocks = [
			{
				clientId: 'map-1',
				name: 'kntnt-gpx-blocks/map',
				attributes: { mapId: 'map-first', attachmentId: 10 },
				innerBlocks: [],
			},
			{
				clientId: 'map-2',
				name: 'kntnt-gpx-blocks/map',
				attributes: { mapId: 'map-second', attachmentId: 20 },
				innerBlocks: [],
			},
			{
				clientId: 'elevation-self',
				name: 'kntnt-gpx-blocks/elevation',
				attributes: { mapId: 'auto' },
				innerBlocks: [],
			},
		];

		mountElevation( buildAttributes( { mapId: 'auto' } ) );

		expect( capturedSetAttributes ).toContainEqual( {
			mapId: 'map-second',
		} );
	} );

	it( 'leaves mapId at the default when no Map precedes the Elevation block', () => {
		// Two trailing maps so the single-map auto-bind (which would
		// otherwise fire when configuredMapBlocks.length === 1) does not
		// shadow what we are pinning here: the auto-pick path must not
		// write when no Map precedes the Elevation in document order.
		mockFixtureBlocks = [
			{
				clientId: 'elevation-self',
				name: 'kntnt-gpx-blocks/elevation',
				attributes: { mapId: 'auto' },
				innerBlocks: [],
			},
			{
				clientId: 'map-after-1',
				name: 'kntnt-gpx-blocks/map',
				attributes: { mapId: 'map-trailing-1', attachmentId: 10 },
				innerBlocks: [],
			},
			{
				clientId: 'map-after-2',
				name: 'kntnt-gpx-blocks/map',
				attributes: { mapId: 'map-trailing-2', attachmentId: 20 },
				innerBlocks: [],
			},
		];

		mountElevation( buildAttributes( { mapId: 'auto' } ) );

		const mapIdWrites = capturedSetAttributes.filter(
			( w ) => 'mapId' in w
		);
		expect( mapIdWrites ).toEqual( [] );
	} );

	it( 'does not run again after the initial pre-bind (useRef guard)', async () => {
		mockFixtureBlocks = [
			{
				clientId: 'map-1',
				name: 'kntnt-gpx-blocks/map',
				attributes: { mapId: 'map-first', attachmentId: 10 },
				innerBlocks: [],
			},
			{
				clientId: 'map-2',
				name: 'kntnt-gpx-blocks/map',
				attributes: { mapId: 'map-second', attachmentId: 20 },
				innerBlocks: [],
			},
			{
				clientId: 'elevation-self',
				name: 'kntnt-gpx-blocks/elevation',
				attributes: { mapId: 'auto' },
				innerBlocks: [],
			},
		];

		const container = document.createElement( 'div' );
		const root = createRoot( container );

		flushSync( () => {
			root.render(
				createElement( ElevationEdit, {
					clientId: 'elevation-self',
					attributes: buildAttributes( { mapId: 'auto' } ),
					setAttributes: ( next: Record< string, unknown > ) => {
						capturedSetAttributes.push( next );
					},
					isSelected: false,
					name: 'kntnt-gpx-blocks/elevation',
				} as never )
			);
		} );

		const writesAfterFirstRender = capturedSetAttributes.length;

		// Re-render with the *same* attributes (simulating an unrelated
		// editor state change). The guard ref must prevent another auto-pick
		// write.
		flushSync( () => {
			root.render(
				createElement( ElevationEdit, {
					clientId: 'elevation-self',
					attributes: buildAttributes( { mapId: 'auto' } ),
					setAttributes: ( next: Record< string, unknown > ) => {
						capturedSetAttributes.push( next );
					},
					isSelected: false,
					name: 'kntnt-gpx-blocks/elevation',
				} as never )
			);
		} );

		root.unmount();

		// The auto-pick hook ran exactly once. Subsequent renders must not
		// write again. The single-map auto-bind effect is also one-shot in
		// practice because the second render sees the same mapId (still
		// "auto" because the test feeds the same attributes), but its guard
		// is the attribute value not a ref — what we pin here is the
		// useRef-protected auto-pick. Any second-render write must NOT have
		// the value 'map-second'.
		const secondRenderWrites = capturedSetAttributes.slice(
			writesAfterFirstRender
		);
		expect( secondRenderWrites ).not.toContainEqual( {
			mapId: 'map-second',
		} );
	} );
} );
