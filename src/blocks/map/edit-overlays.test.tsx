/**
 * Inspector-shape test for the GPX Map Edit component's Overlays panels
 * (issues #106 + #112 — overlay provider/layer hierarchy with per-provider
 * API keys, one collapsible PanelBody per provider, key positioned below
 * the layer list).
 *
 * The Overlays surface now renders one collapsible `<PanelBody>` per
 * overlay provider — titled with the provider label — and inside each
 * panel the layer toggles render first, then the conditional API-key
 * TextControl + signup ExternalLink for `requiresKey === true`
 * providers. The list-then-key order reflects the relative interaction
 * frequency: the editor toggles layers often and configures the key
 * once. Toggling adds or removes a `{provider, layer}` pair from
 * `attributes.tileOverlays`, preserving stacking order. The single API
 * key per provider is shared across every layer of that provider the
 * editor enables and lives in
 * `attributes.tileOverlayApiKeys[ providerId ]`.
 *
 * This file mounts `MapEdit` with the Overlays-panel controls captured
 * via mocks, then asserts the matrix of behaviours documented in the
 * issue spec.
 *
 * @since 1.0.0
 */

import { createElement, createRoot, flushSync } from '@wordpress/element';

/**
 * Captured `ToggleControl` props payload. Each entry corresponds to one
 * rendered ToggleControl in the Overlays panel.
 *
 * @since 1.0.0
 */
type CapturedToggle = {
	label: string;
	checked: boolean;
	disabled: boolean | undefined;
	onChange: ( next: boolean ) => void;
};

/**
 * Captured `TextControl` props payload. Each entry corresponds to one
 * rendered API-key field in the Overlays panel.
 *
 * @since 1.0.0
 */
type CapturedTextControl = {
	label: string;
	value: string;
	onChange: ( next: string ) => void;
};

/**
 * Captured `PanelBody` props payload. Each entry corresponds to one
 * rendered PanelBody in the inspector. The `children` reference is
 * preserved so individual tests can re-render a single panel in
 * isolation when they need to assert the local DOM-order of its
 * children, free from cross-panel noise.
 *
 * @since 1.0.0
 */
type CapturedPanel = {
	title: string;
	initialOpen: boolean | undefined;
	className: string | undefined;
	children: React.ReactNode;
};

const capturedToggles: CapturedToggle[] = [];
const capturedTextControls: CapturedTextControl[] = [];
const capturedPanels: CapturedPanel[] = [];

// Mock @wordpress/block-editor — collapses every surface MapEdit reaches.
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
		PanelColorSettings: () => null,
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

// Mock @wordpress/components — capture ToggleControl + TextControl props.
jest.mock(
	'@wordpress/components',
	() => ( {
		__esModule: true,
		PanelBody: ( props: {
			title?: string;
			initialOpen?: boolean;
			className?: string;
			children?: React.ReactNode;
		} ) => {
			capturedPanels.push( {
				title: props.title ?? '',
				initialOpen: props.initialOpen,
				className: props.className,
				children: props.children,
			} );
			return props.children;
		},
		ToggleControl: ( props: {
			label: string;
			checked: boolean;
			disabled?: boolean;
			onChange: ( next: boolean ) => void;
		} ) => {
			capturedToggles.push( {
				label: props.label,
				checked: props.checked,
				disabled: props.disabled,
				onChange: props.onChange,
			} );
			return null;
		},
		FontSizePicker: () => null,
		SelectControl: () => null,
		TextControl: ( props: CapturedTextControl ) => {
			capturedTextControls.push( {
				label: props.label,
				value: props.value,
				onChange: props.onChange,
			} );
			return null;
		},
		ExternalLink: () => null,
		Notice: () => null,
		__experimentalToolsPanel: () => null,
		__experimentalToolsPanelItem: () => null,
	} ),
	{ virtual: true }
);

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

jest.mock(
	'@wordpress/core-data',
	() => ( { __esModule: true, store: 'core' } ),
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

jest.mock(
	'./editor-preview',
	() => ( {
		__esModule: true,
		MapEditorPreview: () => null,
	} ),
	{ virtual: true }
);

jest.mock(
	'./use-ensure-unique-map-id',
	() => ( { __esModule: true, useEnsureUniqueMapId: () => undefined } ),
	{ virtual: true }
);

// Editor data globals — two free overlay providers (one multi-layer, one
// single-layer) and one paid overlay provider with multiple layers, so the
// test fixture covers the conditional API-key field, key sharing across
// layers, and a free provider's lack of API-key UI.
(
	globalThis as {
		kntntGpxBlocks?: { providers: unknown; overlays: unknown };
	}
 ).kntntGpxBlocks = {
	providers: {
		openstreetmap: {
			label: 'OpenStreetMap',
			requiresKey: false,
			default: 'mapnik',
			subdomains: [ 'a', 'b', 'c' ],
			styles: {
				mapnik: {
					label: 'Mapnik',
					url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
					attribution: 'OSM',
					maxZoom: 19,
				},
			},
		},
	},
	overlays: {
		'waymarked-trails': {
			label: 'Waymarked Trails',
			requiresKey: false,
			layers: {
				hiking: {
					label: 'Hiking',
					url: 'https://tile.waymarkedtrails.org/hiking/{z}/{x}/{y}.png',
					attribution: 'WT',
					maxZoom: 18,
				},
				cycling: {
					label: 'Cycling',
					url: 'https://tile.waymarkedtrails.org/cycling/{z}/{x}/{y}.png',
					attribution: 'WT',
					maxZoom: 18,
				},
				winter: {
					label: 'Winter',
					url: 'https://tile.waymarkedtrails.org/slopes/{z}/{x}/{y}.png',
					attribution: 'WT',
					maxZoom: 18,
				},
			},
		},
		openseamap: {
			label: 'OpenSeaMap',
			requiresKey: false,
			layers: {
				seamarks: {
					label: 'Sea marks',
					url: 'https://tiles.openseamap.org/seamark/{z}/{x}/{y}.png',
					attribution: 'OSM',
					maxZoom: 18,
				},
			},
		},
		openweathermap: {
			label: 'OpenWeatherMap',
			requiresKey: true,
			signupUrl: 'https://openweathermap.org/',
			layers: {
				clouds: {
					label: 'Clouds',
					url: 'https://tile.openweathermap.org/map/clouds_new/{z}/{x}/{y}.png?appid={KEY}',
					attribution: 'OWM',
					maxZoom: 19,
				},
				precipitation: {
					label: 'Precipitation',
					url: 'https://tile.openweathermap.org/map/precipitation_new/{z}/{x}/{y}.png?appid={KEY}',
					attribution: 'OWM',
					maxZoom: 19,
				},
				temperature: {
					label: 'Temperature',
					url: 'https://tile.openweathermap.org/map/temp_new/{z}/{x}/{y}.png?appid={KEY}',
					attribution: 'OWM',
					maxZoom: 19,
				},
			},
		},
	},
};

// Import AFTER mocks are registered.
import { MapEdit } from './edit';

/**
 * Builds a full MapAttributes payload with overrides applied on top of
 * empty/default values. Mirrors the attribute shape in block.json.
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
		tileProvider: 'openstreetmap',
		tileStyle: 'mapnik',
		tileApiKeys: {},
		tileOverlays: [],
		tileOverlayApiKeys: {},
		...overrides,
	};
}

/**
 * Mounts MapEdit and returns captured toggles, text controls, panels,
 * and writes.
 *
 * @param attributes Attribute payload to feed into MapEdit.
 *
 * @return Captures and writes for assertion.
 */
function mountAndCapture( attributes: Record< string, unknown > ): {
	toggles: CapturedToggle[];
	texts: CapturedTextControl[];
	panels: CapturedPanel[];
	writes: Array< Record< string, unknown > >;
} {
	capturedToggles.length = 0;
	capturedTextControls.length = 0;
	capturedPanels.length = 0;
	const writes: Array< Record< string, unknown > > = [];
	const container = document.createElement( 'div' );
	const root = createRoot( container );
	flushSync( () => {
		root.render(
			createElement( MapEdit, {
				attributes,
				setAttributes: ( next: Record< string, unknown > ) => {
					writes.push( next );
				},
				clientId: 'test-client',
				isSelected: false,
				name: 'kntnt-gpx-blocks/map',
			} as never )
		);
	} );
	root.unmount();
	return {
		toggles: [ ...capturedToggles ],
		texts: [ ...capturedTextControls ],
		panels: [ ...capturedPanels ],
		writes,
	};
}

/**
 * Re-renders a single captured PanelBody's children into an isolated
 * React root so the resulting `capturedToggles` / `capturedTextControls`
 * arrays contain only that panel's controls, in DOM order.
 *
 * The MapEdit top-level mount yields a flat capture of every toggle and
 * text-control across the inspector — useful for cross-panel assertions
 * but unable to verify *within-panel* ordering. Mounting one panel at a
 * time gives us that ordering on a clean slate.
 *
 * @since 1.0.0
 *
 * @param panel Captured PanelBody record from a previous mount.
 *
 * @return The toggles and texts rendered by this panel only, in
 *         document order.
 */
function captureSinglePanel( panel: CapturedPanel ): {
	toggles: CapturedToggle[];
	texts: CapturedTextControl[];
} {
	capturedToggles.length = 0;
	capturedTextControls.length = 0;
	const container = document.createElement( 'div' );
	const root = createRoot( container );
	flushSync( () => {
		root.render( createElement( 'div', null, panel.children ) );
	} );
	root.unmount();
	return {
		toggles: [ ...capturedToggles ],
		texts: [ ...capturedTextControls ],
	};
}

/**
 * Layer labels emitted by the editor-data fixture above. Used to filter
 * the captured ToggleControls down to the overlay-panel subset — the
 * MapEdit inspector renders many other ToggleControls (Controls,
 * Interactions, Waypoint info) that share the same primitive but are
 * not part of the overlays surface under test.
 *
 * @since 1.0.0
 */
const OVERLAY_LAYER_LABELS = new Set( [
	'Hiking',
	'Cycling',
	'Winter',
	'Sea marks',
	'Clouds',
	'Precipitation',
	'Temperature',
] );

/**
 * Filters the captured toggles down to the overlay subset. Orphan
 * labels (passed in via the optional `extras` set) are kept too so
 * orphan-toggle assertions can find them.
 *
 * @since 1.0.0
 *
 * @param toggles Full toggle capture from a render.
 * @param extras  Additional labels to keep (used for orphan assertions).
 *
 * @return Toggles whose label is either a known overlay layer or an
 *         extra orphan label.
 */
function overlayToggles(
	toggles: CapturedToggle[],
	extras: Set< string > = new Set()
): CapturedToggle[] {
	return toggles.filter(
		( t ) => OVERLAY_LAYER_LABELS.has( t.label ) || extras.has( t.label )
	);
}

describe( 'MapEdit Overlays panel — provider/layer hierarchy (issue #106)', () => {
	it( 'renders one ToggleControl per layer across every overlay provider', () => {
		const { toggles } = mountAndCapture( buildAttributes() );

		// 3 (waymarked-trails) + 1 (openseamap) + 3 (openweathermap) = 7.
		const overlay = overlayToggles( toggles );
		expect( overlay ).toHaveLength( 7 );
		const labels = overlay.map( ( t ) => t.label );
		expect( labels ).toEqual( [
			'Hiking',
			'Cycling',
			'Winter',
			'Sea marks',
			'Clouds',
			'Precipitation',
			'Temperature',
		] );
	} );

	it( 'renders the API-key TextControl only for the requiresKey overlay provider', () => {
		const { texts } = mountAndCapture( buildAttributes() );

		// One key field for openweathermap; waymarked-trails and openseamap are
		// free and therefore produce no TextControl.
		const apiKeyFields = texts.filter( ( t ) => t.label === 'API key' );
		expect( apiKeyFields ).toHaveLength( 1 );
		expect( apiKeyFields[ 0 ].value ).toBe( '' );
	} );

	it( 'reads the API-key value from tileOverlayApiKeys[ providerId ]', () => {
		const { texts } = mountAndCapture(
			buildAttributes( {
				tileOverlayApiKeys: { openweathermap: 'OWM-KEY' },
			} )
		);

		const apiKeyField = texts.find( ( t ) => t.label === 'API key' );
		expect( apiKeyField ).toBeDefined();
		expect( apiKeyField?.value ).toBe( 'OWM-KEY' );
	} );

	it( 'reflects checked state from the saved tileOverlays pair list', () => {
		const { toggles } = mountAndCapture(
			buildAttributes( {
				tileOverlays: [
					{ provider: 'waymarked-trails', layer: 'hiking' },
					{ provider: 'openweathermap', layer: 'clouds' },
				],
			} )
		);

		const overlay = overlayToggles( toggles );
		const hikingToggle = overlay.find( ( t ) => t.label === 'Hiking' );
		const cyclingToggle = overlay.find( ( t ) => t.label === 'Cycling' );
		const cloudsToggle = overlay.find( ( t ) => t.label === 'Clouds' );
		const seamarksToggle = overlay.find( ( t ) => t.label === 'Sea marks' );

		expect( hikingToggle?.checked ).toBe( true );
		expect( cloudsToggle?.checked ).toBe( true );
		expect( cyclingToggle?.checked ).toBe( false );
		expect( seamarksToggle?.checked ).toBe( false );
	} );

	it( 'enabling a free-provider layer appends the pair to tileOverlays preserving stacking order', () => {
		const { toggles, writes } = mountAndCapture(
			buildAttributes( {
				tileOverlays: [ { provider: 'openseamap', layer: 'seamarks' } ],
			} )
		);

		const overlay = overlayToggles( toggles );
		const hikingToggle = overlay.find( ( t ) => t.label === 'Hiking' );
		expect( hikingToggle ).toBeDefined();
		hikingToggle?.onChange( true );

		expect( writes ).toEqual( [
			{
				tileOverlays: [
					{ provider: 'openseamap', layer: 'seamarks' },
					{ provider: 'waymarked-trails', layer: 'hiking' },
				],
			},
		] );
	} );

	it( 'disabling a layer removes its pair from tileOverlays', () => {
		const { toggles, writes } = mountAndCapture(
			buildAttributes( {
				tileOverlays: [
					{ provider: 'waymarked-trails', layer: 'hiking' },
					{ provider: 'openseamap', layer: 'seamarks' },
				],
			} )
		);

		const overlay = overlayToggles( toggles );
		const hikingToggle = overlay.find( ( t ) => t.label === 'Hiking' );
		hikingToggle?.onChange( false );

		expect( writes ).toEqual( [
			{
				tileOverlays: [ { provider: 'openseamap', layer: 'seamarks' } ],
			},
		] );
	} );

	it( 'enabling a key-required layer still saves even when the key is empty (toggle state independent of key)', () => {
		const { toggles, writes } = mountAndCapture(
			buildAttributes( {
				tileOverlays: [],
				tileOverlayApiKeys: {},
			} )
		);

		const overlay = overlayToggles( toggles );
		const cloudsToggle = overlay.find( ( t ) => t.label === 'Clouds' );
		expect( cloudsToggle ).toBeDefined();
		cloudsToggle?.onChange( true );

		// The toggle write goes through; the editor preview suppresses just
		// that layer (PHP-side), not the base map or other overlays.
		expect( writes ).toEqual( [
			{
				tileOverlays: [
					{ provider: 'openweathermap', layer: 'clouds' },
				],
			},
		] );
	} );

	it( 'writing the API key for one overlay provider merges the value into tileOverlayApiKeys, preserving other providers entries', () => {
		const { texts, writes } = mountAndCapture(
			buildAttributes( {
				tileOverlayApiKeys: {
					openweathermap: 'OLD_OWM',
					'some-other-provider': 'OTHER_KEY',
				},
			} )
		);

		const apiKeyField = texts.find( ( t ) => t.label === 'API key' );
		apiKeyField?.onChange( 'NEW_OWM' );

		expect( writes ).toEqual( [
			{
				tileOverlayApiKeys: {
					openweathermap: 'NEW_OWM',
					'some-other-provider': 'OTHER_KEY',
				},
			},
		] );
	} );

	it( 'enabling two layers of the same key-required provider shares the single API key (no per-layer key UI)', () => {
		const { toggles, texts, writes } = mountAndCapture(
			buildAttributes( {
				tileOverlays: [
					{ provider: 'openweathermap', layer: 'clouds' },
				],
				tileOverlayApiKeys: { openweathermap: 'OWM' },
			} )
		);

		// Exactly one API-key TextControl exists, regardless of how many
		// openweathermap layers are enabled. The TilesPanel's base
		// provider is openstreetmap (free), so it produces no API-key
		// TextControl — the single match must be the overlay one.
		const apiKeyFields = texts.filter( ( t ) => t.label === 'API key' );
		expect( apiKeyFields ).toHaveLength( 1 );

		// Enabling a second openweathermap layer does not introduce a second
		// key field — the key is provider-level.
		const overlay = overlayToggles( toggles );
		const precipToggle = overlay.find(
			( t ) => t.label === 'Precipitation'
		);
		precipToggle?.onChange( true );

		expect( writes ).toEqual( [
			{
				tileOverlays: [
					{ provider: 'openweathermap', layer: 'clouds' },
					{ provider: 'openweathermap', layer: 'precipitation' },
				],
			},
		] );
	} );

	it( 'surfaces an orphan saved pair (unknown provider) as a disabled toggle at the bottom of the panel', () => {
		const { toggles } = mountAndCapture(
			buildAttributes( {
				tileOverlays: [
					{ provider: 'waymarked-trails', layer: 'hiking' },
					{ provider: 'dropped-by-filter', layer: 'whatever' },
				],
			} )
		);

		// Orphan labels are not part of the canonical overlay set, so they're
		// passed in as `extras` to the filter.
		const overlay = overlayToggles(
			toggles,
			new Set( [ 'dropped-by-filter / whatever' ] )
		);
		const orphan = overlay.find(
			( t ) => t.label === 'dropped-by-filter / whatever'
		);
		expect( orphan ).toBeDefined();
		expect( orphan?.disabled ).toBe( true );
		expect( orphan?.checked ).toBe( true );
	} );

	it( 'surfaces an orphan saved pair (unknown layer within a known provider) as a disabled toggle', () => {
		const { toggles } = mountAndCapture(
			buildAttributes( {
				tileOverlays: [
					{
						provider: 'waymarked-trails',
						layer: 'dropped-layer',
					},
				],
			} )
		);

		const overlay = overlayToggles(
			toggles,
			new Set( [ 'waymarked-trails / dropped-layer' ] )
		);
		const orphan = overlay.find(
			( t ) => t.label === 'waymarked-trails / dropped-layer'
		);
		expect( orphan ).toBeDefined();
		expect( orphan?.disabled ).toBe( true );
		expect( orphan?.checked ).toBe( true );
	} );
} );

describe( 'MapEdit Overlays panel — per-provider PanelBody (issue #112)', () => {
	it( 'renders one PanelBody per overlay provider, titled with the provider label, collapsed by default', () => {
		const { panels } = mountAndCapture( buildAttributes() );

		// Filter the captured panels down to the overlay surface by its
		// dedicated className — the MapEdit inspector also renders other
		// PanelBody instances (Controls, Interactions, Waypoint info,
		// Tiles, etc.) we deliberately do not assert against here.
		const overlayPanels = panels.filter( ( p ) =>
			( p.className ?? '' ).includes(
				'kntnt-gpx-blocks-overlay-provider'
			)
		);
		expect( overlayPanels ).toHaveLength( 3 );

		const titles = overlayPanels.map( ( p ) => p.title );
		expect( titles ).toEqual( [
			'Waymarked Trails',
			'OpenSeaMap',
			'OpenWeatherMap',
		] );

		for ( const panel of overlayPanels ) {
			expect( panel.initialOpen ).toBe( false );
		}
	} );

	it( 'inside a key-required provider panel, the layer toggles render before the API-key TextControl', () => {
		const { panels } = mountAndCapture( buildAttributes() );

		const owmPanel = panels.find( ( p ) => p.title === 'OpenWeatherMap' );
		expect( owmPanel ).toBeDefined();

		const { toggles, texts } = captureSinglePanel(
			owmPanel as CapturedPanel
		);

		// Three layer toggles, one API-key TextControl, list-then-key.
		expect( toggles.map( ( t ) => t.label ) ).toEqual( [
			'Clouds',
			'Precipitation',
			'Temperature',
		] );
		expect( texts.map( ( t ) => t.label ) ).toEqual( [ 'API key' ] );
	} );

	it( 'inside a free provider panel, no API-key TextControl is rendered', () => {
		const { panels } = mountAndCapture( buildAttributes() );

		const seamapPanel = panels.find( ( p ) => p.title === 'OpenSeaMap' );
		expect( seamapPanel ).toBeDefined();

		const { toggles, texts } = captureSinglePanel(
			seamapPanel as CapturedPanel
		);
		expect( toggles.map( ( t ) => t.label ) ).toEqual( [ 'Sea marks' ] );
		expect( texts ).toEqual( [] );
	} );

	it( 'renders no overlay PanelBody at all when the overlay registry is empty', () => {
		const original = ( globalThis as { kntntGpxBlocks?: unknown } )
			.kntntGpxBlocks as {
			providers: unknown;
			overlays: Record< string, unknown >;
		};

		try {
			( globalThis as { kntntGpxBlocks?: unknown } ).kntntGpxBlocks = {
				providers: original.providers,
				overlays: {},
			};
			const { panels } = mountAndCapture( buildAttributes() );
			const overlayPanels = panels.filter( ( p ) =>
				( p.className ?? '' ).includes(
					'kntnt-gpx-blocks-overlay-provider'
				)
			);
			expect( overlayPanels ).toEqual( [] );
		} finally {
			( globalThis as { kntntGpxBlocks?: unknown } ).kntntGpxBlocks =
				original;
		}
	} );

	it( 'gracefully handles an overlay provider that declares no layers — panel renders with only the API-key field', () => {
		const original = ( globalThis as { kntntGpxBlocks?: unknown } )
			.kntntGpxBlocks as {
			providers: unknown;
			overlays: Record< string, unknown >;
		};

		try {
			( globalThis as { kntntGpxBlocks?: unknown } ).kntntGpxBlocks = {
				providers: original.providers,
				overlays: {
					'empty-paid-provider': {
						label: 'Empty Paid Provider',
						requiresKey: true,
						signupUrl: 'https://example.test/signup',
						layers: {},
					},
				},
			};
			const { panels } = mountAndCapture( buildAttributes() );
			const overlayPanels = panels.filter( ( p ) =>
				( p.className ?? '' ).includes(
					'kntnt-gpx-blocks-overlay-provider'
				)
			);
			expect( overlayPanels ).toHaveLength( 1 );
			expect( overlayPanels[ 0 ].title ).toBe( 'Empty Paid Provider' );

			const { toggles, texts } = captureSinglePanel( overlayPanels[ 0 ] );
			expect( toggles ).toEqual( [] );
			expect( texts.map( ( t ) => t.label ) ).toEqual( [ 'API key' ] );
		} finally {
			( globalThis as { kntntGpxBlocks?: unknown } ).kntntGpxBlocks =
				original;
		}
	} );

	it( 'orphans render in their own collapsible PanelBody at the bottom of the overlay panels', () => {
		const { panels } = mountAndCapture(
			buildAttributes( {
				tileOverlays: [
					{ provider: 'dropped-by-filter', layer: 'whatever' },
				],
			} )
		);

		const overlayPanels = panels.filter( ( p ) =>
			( p.className ?? '' ).includes(
				'kntnt-gpx-blocks-overlay-provider'
			)
		);

		// The provider panels still render plus an additional orphan
		// panel at the end with its distinctive className modifier.
		expect(
			overlayPanels[ overlayPanels.length - 1 ].className ?? ''
		).toContain( 'kntnt-gpx-blocks-overlay-orphans' );
		expect( overlayPanels[ overlayPanels.length - 1 ].title ).toBe(
			'Unrecognised overlays'
		);
		expect( overlayPanels[ overlayPanels.length - 1 ].initialOpen ).toBe(
			false
		);
	} );
} );
