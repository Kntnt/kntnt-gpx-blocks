/**
 * Inspector-shape test for the GPX Map Edit component's consolidated colour
 * panel (issue #84).
 *
 * Until issue #84 the Map inspector exposed five panels for six colour
 * controls: a `Track` PanelColorSettings with two colours, a `Waypoints`
 * PanelColorSettings with one, and three `Waypoint info — …` PanelBody
 * panels each carrying a bare ColorPicker. This test pins the post-#84
 * shape: exactly one PanelColorSettings titled "Color", `enableAlpha`,
 * with six entries in a fixed order — Track, Cursor, Marker, Waypoint
 * background, Waypoint name, Waypoint description.
 *
 * The test mocks every dependency the component pulls in so it can mount
 * outside a real editor. PanelColorSettings is replaced with a capture
 * stub that records every props payload it receives; everything else is
 * collapsed to a passthrough or noop. Only the props of interest — the
 * panel's title, alpha flag, and colour settings array — are asserted.
 *
 * @since 1.0.0
 */

import { createElement, createRoot, flushSync } from '@wordpress/element';

/**
 * Captured `PanelColorSettings` props payload across every render in the
 * current test. Each entry mirrors the props the component would have
 * received from MapEdit, including the title, the `enableAlpha` flag,
 * and the `colorSettings` array.
 *
 * @since 1.0.0
 */
type CapturedColorPanel = {
	title: string;
	enableAlpha?: boolean;
	colorSettings: Array< {
		value: unknown;
		onChange: ( value: string | undefined ) => void;
		label: string;
	} >;
};
const capturedColorPanels: CapturedColorPanel[] = [];

// Mock @wordpress/block-editor — `PanelColorSettings` is the surface under
// test, so it records its props rather than rendering anything. The rest
// of the surface (InspectorControls, BlockControls, useBlockProps, …) is
// collapsed to whatever the component needs to mount without erroring.
jest.mock(
	'@wordpress/block-editor',
	() => ( {
		__esModule: true,
		useBlockProps: Object.assign(
			( props: Record< string, unknown > = {} ) => ( {
				...props,
				className: ( props.className as string ) ?? '',
			} ),
			{
				save: ( props: Record< string, unknown > = {} ) => props,
			}
		),
		InspectorControls: ( { children }: { children: React.ReactNode } ) =>
			children,
		BlockControls: ( { children }: { children: React.ReactNode } ) =>
			children,
		MediaPlaceholder: () => null,
		MediaReplaceFlow: () => null,
		PanelColorSettings: ( props: CapturedColorPanel ) => {
			capturedColorPanels.push( {
				title: props.title,
				enableAlpha: props.enableAlpha,
				colorSettings: props.colorSettings,
			} );
			return null;
		},
		useSettings: () => [ undefined, undefined ],
		__experimentalFontFamilyControl: () => null,
		__experimentalFontAppearanceControl: () => null,
		__experimentalLetterSpacingControl: () => null,
		__experimentalTextDecorationControl: () => null,
		__experimentalTextTransformControl: () => null,
		LineHeightControl: () => null,
		store: 'core/block-editor',
	} ),
	{ virtual: true }
);

// Mock @wordpress/components — every surface MapEdit reaches collapses to
// either a passthrough wrapper or a noop. The tooltip-info typography
// panels descend from ToolsPanel; passing children through keeps the tree
// renderable but their content is irrelevant to this test.
jest.mock(
	'@wordpress/components',
	() => ( {
		__esModule: true,
		PanelBody: ( { children }: { children: React.ReactNode } ) => children,
		ToggleControl: () => null,
		FontSizePicker: () => null,
		SelectControl: () => null,
		TextControl: () => null,
		ExternalLink: () => null,
		Notice: () => null,
		__experimentalToolsPanel: () => null,
		__experimentalToolsPanelItem: () => null,
	} ),
	{ virtual: true }
);

// Mock @wordpress/data — `useSelect` is the only surface MapEdit reaches.
// The component uses it to resolve the attached media's source URL via
// the core/core-data store and to read the block tree via core/block-editor
// (inside `useEnsureUniqueMapId`). Returning empty arrays/undefined for
// both stores is enough to mount without errors.
jest.mock(
	'@wordpress/data',
	() => ( {
		__esModule: true,
		useSelect: ( fn: ( select: unknown ) => unknown ) => {
			const select = () => ( {
				getBlocks: () => [],
				getMedia: () => undefined,
			} );
			return fn( select );
		},
	} ),
	{ virtual: true }
);

// Mock @wordpress/core-data — only the store reference is read for the
// media-resolution useSelect inside MapEdit.
jest.mock(
	'@wordpress/core-data',
	() => ( { __esModule: true, store: 'core' } ),
	{ virtual: true }
);

// Mock @wordpress/i18n — translation passthrough.
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

// Mock the editor preview — its real implementation pulls in Leaflet
// against a live REST endpoint. The inspector-shape test only cares
// about the inspector controls, not the preview body, so a noop is
// sufficient.
jest.mock(
	'./editor-preview',
	() => ( {
		__esModule: true,
		MapEditorPreview: () => null,
	} ),
	{ virtual: true }
);

// Mock the unique-mapId hook — the hook calls into `useSelect` against
// the block-editor store, which is collapsed above. A noop is the
// simplest stub that lets MapEdit run.
jest.mock(
	'./use-ensure-unique-map-id',
	() => ( { __esModule: true, useEnsureUniqueMapId: () => undefined } ),
	{ virtual: true }
);

// Editor data globals — `MapEdit` reads `window.kntntGpxBlocks.providers`
// and `…overlays` to drive the Tiles / Overlays panels. The shapes used
// by the inspector are small enough to construct directly here.
(
	globalThis as {
		kntntGpxBlocks?: { providers: unknown; overlays: unknown };
	}
 ).kntntGpxBlocks = {
	providers: {
		'osm-standard': {
			label: 'OpenStreetMap Standard',
			requiresKey: false,
			url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
			attribution: 'OSM',
			maxZoom: 19,
			subdomains: [ 'a', 'b', 'c' ],
		},
	},
	overlays: {},
};

// Import the component under test AFTER all mocks are registered so the
// jest module factory hooks resolve correctly.
import { MapEdit } from './edit';

/**
 * Builds a full MapAttributes payload with overrides applied on top of
 * empty/default values. Mirrors the attribute shape in block.json so the
 * destructure inside MapEdit reads every key without undefined warnings.
 *
 * @param overrides Per-test attribute overrides merged on top of defaults.
 *
 * @return Attribute payload ready to feed into MapEdit.
 */
function buildAttributes(
	overrides: Record< string, unknown > = {}
): Record< string, unknown > {
	return {
		attachmentId: 42,
		mapId: 'auto',
		showZoomButtons: true,
		showScale: true,
		showFullscreen: false,
		showDownload: false,
		enableDrag: true,
		enablePinchZoom: true,
		enableDoubleClickZoom: true,
		enableKeyboard: true,
		trackColor: '',
		trackCursorColor: '',
		waypointColor: '',
		tooltipShowName: true,
		tooltipShowDesc: true,
		tooltipBackground: '',
		tooltipNameColor: '',
		tooltipNameFontFamily: '',
		tooltipNameFontSize: '',
		tooltipNameFontWeight: '',
		tooltipNameFontStyle: '',
		tooltipNameLineHeight: '',
		tooltipNameLetterSpacing: '',
		tooltipNameTextDecoration: '',
		tooltipNameTextTransform: '',
		tooltipDescColor: '',
		tooltipDescFontFamily: '',
		tooltipDescFontSize: '',
		tooltipDescFontWeight: '',
		tooltipDescFontStyle: '',
		tooltipDescLineHeight: '',
		tooltipDescLetterSpacing: '',
		tooltipDescTextDecoration: '',
		tooltipDescTextTransform: '',
		tileProvider: 'osm-standard',
		tileApiKey: '',
		tileOverlays: [],
		...overrides,
	};
}

/**
 * Mounts MapEdit, captures `PanelColorSettings` props, and unmounts.
 *
 * @param attributes Attribute payload to feed into MapEdit.
 *
 * @return Captured `PanelColorSettings` props (one entry per panel rendered).
 */
function renderAndCapture(
	attributes: Record< string, unknown >
): CapturedColorPanel[] {
	capturedColorPanels.length = 0;
	const container = document.createElement( 'div' );
	const root = createRoot( container );
	flushSync( () => {
		root.render(
			createElement( MapEdit, {
				attributes,
				setAttributes: () => undefined,
				clientId: 'test-client',
				isSelected: false,
				name: 'kntnt-gpx-blocks/map',
			} as never )
		);
	} );
	root.unmount();
	return [ ...capturedColorPanels ];
}

describe( 'MapEdit consolidated colour panel (issue #84)', () => {
	it( 'renders exactly one PanelColorSettings titled "Color"', () => {
		const panels = renderAndCapture( buildAttributes() );

		expect( panels ).toHaveLength( 1 );
		expect( panels[ 0 ].title ).toBe( 'Color' );
	} );

	it( 'enables alpha on the consolidated panel', () => {
		const panels = renderAndCapture( buildAttributes() );

		expect( panels[ 0 ].enableAlpha ).toBe( true );
	} );

	it( 'exposes the six colour entries in the order specified in issue #84', () => {
		const panels = renderAndCapture( buildAttributes() );

		const labels = panels[ 0 ].colorSettings.map(
			( entry ) => entry.label
		);
		expect( labels ).toEqual( [
			'Track',
			'Cursor',
			'Marker',
			'Waypoint background',
			'Waypoint name',
			'Waypoint description',
		] );
	} );

	it( 'wires each entry to the matching attribute value', () => {
		const panels = renderAndCapture(
			buildAttributes( {
				trackColor: '#aaaaaa',
				trackCursorColor: '#bbbbbb',
				waypointColor: '#cccccc',
				tooltipBackground: '#000000cc',
				tooltipNameColor: '#dddddd',
				tooltipDescColor: '#eeeeee',
			} )
		);

		const values = panels[ 0 ].colorSettings.map(
			( entry ) => entry.value
		);
		expect( values ).toEqual( [
			'#aaaaaa',
			'#bbbbbb',
			'#cccccc',
			'#000000cc',
			'#dddddd',
			'#eeeeee',
		] );
	} );

	it( "wires each entry's onChange to the matching attribute (round-trip via setAttributes)", () => {
		// Capture every setAttributes call so the test can assert each
		// colour entry's onChange writes into the expected attribute key.
		const writes: Array< Record< string, unknown > > = [];
		capturedColorPanels.length = 0;

		const container = document.createElement( 'div' );
		const root = createRoot( container );
		flushSync( () => {
			root.render(
				createElement( MapEdit, {
					attributes: buildAttributes(),
					setAttributes: ( next: Record< string, unknown > ) => {
						writes.push( next );
					},
					clientId: 'test-client',
					isSelected: false,
					name: 'kntnt-gpx-blocks/map',
				} as never )
			);
		} );

		const settings = capturedColorPanels[ 0 ].colorSettings;
		settings[ 0 ].onChange( '#111111' );
		settings[ 1 ].onChange( '#222222' );
		settings[ 2 ].onChange( '#333333' );
		settings[ 3 ].onChange( '#44444480' );
		settings[ 4 ].onChange( '#555555' );
		settings[ 5 ].onChange( '#666666' );

		root.unmount();

		expect( writes ).toEqual( [
			{ trackColor: '#111111' },
			{ trackCursorColor: '#222222' },
			{ waypointColor: '#333333' },
			{ tooltipBackground: '#44444480' },
			{ tooltipNameColor: '#555555' },
			{ tooltipDescColor: '#666666' },
		] );
	} );

	it( 'maps an undefined onChange value back to the empty-string sentinel (clear-to-default)', () => {
		// Clearing a colour in the WordPress colour picker invokes onChange
		// with `undefined`; the inspector must translate that to `""` so the
		// SCSS fallbacks regain control of the visual baseline.
		const writes: Array< Record< string, unknown > > = [];
		capturedColorPanels.length = 0;

		const container = document.createElement( 'div' );
		const root = createRoot( container );
		flushSync( () => {
			root.render(
				createElement( MapEdit, {
					attributes: buildAttributes( {
						tooltipBackground: '#000000cc',
					} ),
					setAttributes: ( next: Record< string, unknown > ) => {
						writes.push( next );
					},
					clientId: 'test-client',
					isSelected: false,
					name: 'kntnt-gpx-blocks/map',
				} as never )
			);
		} );

		const backgroundEntry = capturedColorPanels[ 0 ].colorSettings[ 3 ];
		backgroundEntry.onChange( undefined );

		root.unmount();

		expect( writes ).toEqual( [ { tooltipBackground: '' } ] );
	} );
} );
