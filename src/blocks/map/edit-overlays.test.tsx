/**
 * Inspector-shape test for the GPX Map Edit component's Overlays panel
 * (issue #106 — overlay provider/layer hierarchy with per-provider API keys).
 *
 * The Overlays panel now renders one sub-section per overlay provider:
 * provider label header, conditional API-key TextControl + signup
 * ExternalLink for `requiresKey === true` providers, and one
 * ToggleControl per layer. Toggling adds or removes a `{provider, layer}`
 * pair from `attributes.tileOverlays`, preserving stacking order. The
 * single API key per provider is shared across every layer of that
 * provider that the editor enables and lives in
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

const capturedToggles: CapturedToggle[] = [];
const capturedTextControls: CapturedTextControl[] = [];

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
		PanelBody: ( { children }: { children: React.ReactNode } ) => children,
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
 * Mounts MapEdit and returns captured toggles, text controls, and writes.
 *
 * @param attributes Attribute payload to feed into MapEdit.
 *
 * @return Captures and writes for assertion.
 */
function mountAndCapture( attributes: Record< string, unknown > ): {
	toggles: CapturedToggle[];
	texts: CapturedTextControl[];
	writes: Array< Record< string, unknown > >;
} {
	capturedToggles.length = 0;
	capturedTextControls.length = 0;
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
		writes,
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
