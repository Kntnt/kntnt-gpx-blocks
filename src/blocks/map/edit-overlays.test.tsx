/**
 * Inspector-shape test for the GPX Map Edit component's Overlays panels
 * (issues #106 + #112 — overlay provider/layer hierarchy, one collapsible
 * PanelBody per provider, key-required Notice positioned below the layer
 * list — and issue #150 which moved the per-block API-key TextControl
 * out of this surface and into the site-wide settings page).
 *
 * The Overlays surface renders one collapsible `<PanelBody>` per overlay
 * provider — titled with the provider label — and inside each panel the
 * layer toggles render first, then a conditional `Notice` pointing the
 * user at the site-wide settings page for `requiresKey === true`
 * providers that are not PHP-engaged. Toggling adds or removes a
 * `{provider, layer}` pair from `attributes.tileOverlays`, preserving
 * stacking order. Per-overlay-provider API keys live in the
 * `kntnt_gpx_blocks_tile_overlay_keys` option, administered through
 * *Settings → Kntnt GPX Blocks* (issue #150).
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
 * Captured `Notice` props payload. Each entry corresponds to one
 * rendered Notice in the inspector. The `className` is captured so
 * cross-panel tests can filter to the overlay-only key-required
 * notices.
 *
 * @since 1.0.0
 */
type CapturedNotice = {
	className: string | undefined;
	children: React.ReactNode;
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
const capturedNotices: CapturedNotice[] = [];
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

// Mock @wordpress/components — capture ToggleControl + Notice + PanelBody props.
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
		ToolbarButton: () => null,
		FontSizePicker: () => null,
		SelectControl: () => null,
		ExternalLink: () => null,
		Notice: ( props: {
			className?: string;
			children?: React.ReactNode;
		} ) => {
			capturedNotices.push( {
				className: props.className,
				children: props.children,
			} );
			return null;
		},
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
		dispatch: () => ( { createNotice: () => undefined } ),
	} ),
	{ virtual: true }
);

jest.mock(
	'@wordpress/core-data',
	() => ( { __esModule: true, store: 'core' } ),
	{ virtual: true }
);
jest.mock(
	'@wordpress/notices',
	() => ( { __esModule: true, store: 'core/notices' } ),
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

// Capture every props payload `MapEditorPreview` receives so the PHP-
// supplied / option-layer pre-substitution tests can assert against the
// resolved `overlays` array.
const capturedPreviewProps: Array< Record< string, unknown > > = [];

jest.mock(
	'./editor-preview',
	() => ( {
		__esModule: true,
		MapEditorPreview: ( props: Record< string, unknown > ) => {
			capturedPreviewProps.push( props );
			return null;
		},
	} ),
	{ virtual: true }
);

jest.mock(
	'./use-ensure-unique-map-id',
	() => ( { __esModule: true, useEnsureUniqueMapId: () => undefined } ),
	{ virtual: true }
);

// Editor data globals — two free overlay providers (one multi-layer, one
// single-layer) and one paid overlay provider with multiple layers, so
// the test fixture covers the conditional key-required Notice, the
// notice's absence on free providers, and the per-provider PanelBody
// structure. The site-wide settings URL is included so the link branch
// of the Notice is exercised; the `canManageSettings` flag controls
// whether it renders as an anchor.
(
	globalThis as {
		kntntGpxBlocks?: {
			providers: unknown;
			overlays: unknown;
			settingsUrl?: string;
			canManageSettings?: boolean;
		};
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
	settingsUrl:
		'https://example.test/wp-admin/options-general.php?page=kntnt-gpx-blocks',
	canManageSettings: true,
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
		enablePan: true,
		enableZoom: true,
		enableTrackPositionCursor: true,
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
		tileOverlays: [],
		...overrides,
	};
}

/**
 * Mounts MapEdit and returns captured toggles, notices, panels, and writes.
 *
 * @param attributes Attribute payload to feed into MapEdit.
 *
 * @return Captures and writes for assertion.
 */
function mountAndCapture( attributes: Record< string, unknown > ): {
	toggles: CapturedToggle[];
	notices: CapturedNotice[];
	panels: CapturedPanel[];
	writes: Array< Record< string, unknown > >;
	previewProps: Array< Record< string, unknown > >;
} {
	capturedToggles.length = 0;
	capturedNotices.length = 0;
	capturedPanels.length = 0;
	capturedPreviewProps.length = 0;
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
		notices: [ ...capturedNotices ],
		panels: [ ...capturedPanels ],
		writes,
		previewProps: [ ...capturedPreviewProps ],
	};
}

/**
 * Re-renders a single captured PanelBody's children into an isolated
 * React root so the resulting `capturedToggles` / `capturedNotices`
 * arrays contain only that panel's controls, in DOM order.
 *
 * The MapEdit top-level mount yields a flat capture of every toggle and
 * notice across the inspector — useful for cross-panel assertions but
 * unable to verify *within-panel* ordering. Mounting one panel at a
 * time gives us that ordering on a clean slate.
 *
 * @since 1.0.0
 *
 * @param panel Captured PanelBody record from a previous mount.
 *
 * @return The toggles and notices rendered by this panel only, in
 *         document order.
 */
function captureSinglePanel( panel: CapturedPanel ): {
	toggles: CapturedToggle[];
	notices: CapturedNotice[];
} {
	capturedToggles.length = 0;
	capturedNotices.length = 0;
	const container = document.createElement( 'div' );
	const root = createRoot( container );
	flushSync( () => {
		root.render( createElement( 'div', null, panel.children ) );
	} );
	root.unmount();
	return {
		toggles: [ ...capturedToggles ],
		notices: [ ...capturedNotices ],
	};
}

/**
 * Recursively flattens a React-element tree to its rendered string
 * content. The walker descends into `props.children` regardless of the
 * element's `type` (so mocked components like `ExternalLink: () => null`
 * are still walked at the captured-tree level, before rendering).
 *
 * Used by the issue-#152 regression assertion to check that the
 * key-required Notice no longer carries the "Get one" sign-up link.
 *
 * @param node React element, array of elements, primitive, or null.
 *
 * @return Flattened string content.
 */
function flattenChildren( node: unknown ): string {
	if ( node === null || node === undefined || typeof node === 'boolean' ) {
		return '';
	}
	if ( typeof node === 'string' || typeof node === 'number' ) {
		return String( node );
	}
	if ( Array.isArray( node ) ) {
		return node.map( ( child ) => flattenChildren( child ) ).join( '' );
	}
	if ( typeof node === 'object' ) {
		const element = node as {
			props?: { children?: unknown };
		};
		if ( ! element.props ) {
			return '';
		}
		return flattenChildren( element.props.children );
	}
	return '';
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

	it( 'enabling a key-required layer still saves the toggle (the runtime drop happens later)', () => {
		const { toggles, writes } = mountAndCapture(
			buildAttributes( {
				tileOverlays: [],
			} )
		);

		const overlay = overlayToggles( toggles );
		const cloudsToggle = overlay.find( ( t ) => t.label === 'Clouds' );
		expect( cloudsToggle ).toBeDefined();
		cloudsToggle?.onChange( true );

		// The toggle write goes through; the editor preview suppresses
		// just that layer when no usable option-layer key is configured.
		// Other overlays (and the base map) still mount.
		expect( writes ).toEqual( [
			{
				tileOverlays: [
					{ provider: 'openweathermap', layer: 'clouds' },
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

	it( 'inside a free provider panel, no key-required Notice is rendered (issue #150)', () => {
		const { panels } = mountAndCapture( buildAttributes() );

		const seamapPanel = panels.find( ( p ) => p.title === 'OpenSeaMap' );
		expect( seamapPanel ).toBeDefined();

		const { toggles, notices } = captureSinglePanel(
			seamapPanel as CapturedPanel
		);
		expect( toggles.map( ( t ) => t.label ) ).toEqual( [ 'Sea marks' ] );
		expect( notices ).toEqual( [] );
	} );

	it( 'renders no overlay PanelBody at all when the overlay registry is empty', () => {
		const original = ( globalThis as { kntntGpxBlocks?: unknown } )
			.kntntGpxBlocks as {
			providers: unknown;
			overlays: Record< string, unknown >;
			settingsUrl?: string;
			canManageSettings?: boolean;
		};

		try {
			( globalThis as { kntntGpxBlocks?: unknown } ).kntntGpxBlocks = {
				providers: original.providers,
				overlays: {},
				settingsUrl: original.settingsUrl,
				canManageSettings: original.canManageSettings,
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

// Key-required Notice (issue #150). The per-block API-key TextControl
// has been replaced by a Notice pointing the editor at *Settings →
// Kntnt GPX Blocks* where the per-overlay-provider key lives in the
// `kntnt_gpx_blocks_tile_overlay_keys` option. The Notice fires only
// for `requiresKey === true` overlay providers that are not
// PHP-engaged (`apiKeyManagedExternally !== true`); PHP-engaged
// providers behave identically to free providers in the UI by design.
describe( 'MapEdit Overlays panel — key-required Notice (issue #150)', () => {
	it( 'renders a key-required Notice in the paid overlay-provider panel', () => {
		const { panels } = mountAndCapture( buildAttributes() );

		const owmPanel = panels.find( ( p ) => p.title === 'OpenWeatherMap' );
		expect( owmPanel ).toBeDefined();

		const { notices } = captureSinglePanel( owmPanel as CapturedPanel );

		// One Notice with the canonical tile-key className surfaces the
		// settings-page link.
		const keyNotices = notices.filter( ( n ) =>
			( n.className ?? '' ).includes( 'kntnt-gpx-blocks-tile-key-notice' )
		);
		expect( keyNotices ).toHaveLength( 1 );
	} );

	it( 'omits the "Get one" sign-up link from the paid overlay-provider Notice even when the provider record carries a signupUrl (issue #152)', () => {
		// The OpenWeatherMap fixture carries
		// `signupUrl: 'https://openweathermap.org/'`; the settings page
		// is the canonical place for sign-up links, so the Notice must
		// end at "Settings → Kntnt GPX Blocks" without a trailing
		// "Get one" ExternalLink. This is the regression guard for #152.
		const { panels } = mountAndCapture( buildAttributes() );

		const owmPanel = panels.find( ( p ) => p.title === 'OpenWeatherMap' );
		expect( owmPanel ).toBeDefined();

		const { notices } = captureSinglePanel( owmPanel as CapturedPanel );
		const keyNotice = notices.find( ( n ) =>
			( n.className ?? '' ).includes( 'kntnt-gpx-blocks-tile-key-notice' )
		);
		expect( keyNotice ).toBeDefined();
		const flat = flattenChildren(
			( keyNotice as CapturedNotice ).children
		);
		expect( flat ).not.toContain( 'Get one' );
	} );

	it( 'renders no key-required Notice in free overlay-provider panels', () => {
		const { panels } = mountAndCapture( buildAttributes() );

		const wmtPanel = panels.find( ( p ) => p.title === 'Waymarked Trails' );
		expect( wmtPanel ).toBeDefined();

		const { notices } = captureSinglePanel( wmtPanel as CapturedPanel );
		const keyNotices = notices.filter( ( n ) =>
			( n.className ?? '' ).includes( 'kntnt-gpx-blocks-tile-key-notice' )
		);
		expect( keyNotices ).toEqual( [] );
	} );
} );

// PHP-supplied overlay API key (issue #114) + option-layer pre-
// substitution (issue #150). When the editor-data registry marks an
// overlay provider with `apiKeyManagedExternally: true`, the
// key-required Notice is hidden — the site builder owns the key in
// PHP and the editor stays out of the way. The fail-closed
// asymmetry: an empty PHP key leaves `{KEY}` intact in the URL, and
// the preview drops *only* that layer from the resolved overlay
// stack (base map + other overlays still render). With option-layer
// pre-substitution (#150), residual `{KEY}` on a non-PHP-engaged
// provider triggers the same drop branch (no usable option-layer
// key was configured), giving the option-layer path symmetric
// runtime behaviour.
describe( 'MapEdit Overlays panel — PHP-supplied apiKey (issue #114) + option-layer pre-substitution (issue #150)', () => {
	const originalRegistry = (
		globalThis as {
			kntntGpxBlocks?: {
				providers: unknown;
				overlays: unknown;
				settingsUrl?: string;
				canManageSettings?: boolean;
			};
		}
	 ).kntntGpxBlocks;

	beforeAll( () => {
		// Add overlay providers mirroring what `Editor_Data_Enqueuer::
		// shape_collection()` would emit: PHP-engaged with a non-empty
		// key (URL pre-substituted), PHP-engaged with an empty key
		// (`{KEY}` survives), and a non-PHP-engaged paid provider whose
		// option-layer URL was pre-substituted server-side (URL clean
		// of `{KEY}` already).
		(
			globalThis as {
				kntntGpxBlocks?: {
					providers: unknown;
					overlays: unknown;
					settingsUrl?: string;
					canManageSettings?: boolean;
				};
			}
		 ).kntntGpxBlocks = {
			providers: ( originalRegistry as { providers: object } ).providers,
			overlays: {
				...( originalRegistry as { overlays: object } ).overlays,
				owmExternal: {
					label: 'OpenWeatherMap (PHP key)',
					requiresKey: true,
					apiKeyManagedExternally: true,
					signupUrl: 'https://openweathermap.org/',
					layers: {
						clouds: {
							label: 'Clouds',
							url: 'https://tile.openweathermap.org/map/clouds_new/{z}/{x}/{y}.png?appid=PHP-OWM-SUBSTITUTED',
							attribution: 'OWM',
							maxZoom: 19,
						},
						precipitation: {
							label: 'Precipitation',
							url: 'https://tile.openweathermap.org/map/precipitation_new/{z}/{x}/{y}.png?appid=PHP-OWM-SUBSTITUTED',
							attribution: 'OWM',
							maxZoom: 19,
						},
					},
				},
				owmExternalEmpty: {
					label: 'OpenWeatherMap (PHP key, empty)',
					requiresKey: true,
					apiKeyManagedExternally: true,
					signupUrl: 'https://openweathermap.org/',
					layers: {
						clouds: {
							label: 'Clouds (PHP empty)',
							url: 'https://tile.openweathermap.org/map/clouds_new/{z}/{x}/{y}.png?appid={KEY}',
							attribution: 'OWM',
							maxZoom: 19,
						},
					},
				},
				owmOptionEngaged: {
					label: 'OpenWeatherMap (option key)',
					requiresKey: true,
					apiKeyManagedExternally: false,
					signupUrl: 'https://openweathermap.org/',
					layers: {
						clouds: {
							label: 'Clouds (option)',
							url: 'https://tile.openweathermap.org/map/clouds_new/{z}/{x}/{y}.png?appid=OPTION-OWM-SUBSTITUTED',
							attribution: 'OWM',
							maxZoom: 19,
						},
					},
				},
			},
			settingsUrl: ( originalRegistry as { settingsUrl?: string } )
				.settingsUrl,
			canManageSettings: (
				originalRegistry as { canManageSettings?: boolean }
			 ).canManageSettings,
		};
	} );

	afterAll( () => {
		(
			globalThis as {
				kntntGpxBlocks?: {
					providers: unknown;
					overlays: unknown;
					settingsUrl?: string;
					canManageSettings?: boolean;
				};
			}
		 ).kntntGpxBlocks = originalRegistry;
	} );

	it( 'hides the key-required Notice for an overlay provider whose apiKey is managed externally', () => {
		const { panels } = mountAndCapture( buildAttributes() );

		const owmExternalPanel = panels.find(
			( p ) => p.title === 'OpenWeatherMap (PHP key)'
		);
		expect( owmExternalPanel ).toBeDefined();

		const { notices } = captureSinglePanel(
			owmExternalPanel as CapturedPanel
		);
		const keyNotices = notices.filter( ( n ) =>
			( n.className ?? '' ).includes( 'kntnt-gpx-blocks-tile-key-notice' )
		);
		expect( keyNotices ).toEqual( [] );
	} );

	it( 'still hides the key-required Notice when the PHP-supplied key is empty (the drop-the-layer branch)', () => {
		const { panels } = mountAndCapture( buildAttributes() );

		const owmEmptyPanel = panels.find(
			( p ) => p.title === 'OpenWeatherMap (PHP key, empty)'
		);
		expect( owmEmptyPanel ).toBeDefined();

		// The `apiKeyManagedExternally` flag remains `true` regardless
		// of whether the resolved PHP key was empty — engagement is
		// presence-based.
		const { notices } = captureSinglePanel(
			owmEmptyPanel as CapturedPanel
		);
		const keyNotices = notices.filter( ( n ) =>
			( n.className ?? '' ).includes( 'kntnt-gpx-blocks-tile-key-notice' )
		);
		expect( keyNotices ).toEqual( [] );
	} );

	it( 'renders the key-required Notice for paid overlay providers whose apiKey is not managed externally (option-layer path)', () => {
		const { panels } = mountAndCapture( buildAttributes() );

		const owmPanel = panels.find( ( p ) => p.title === 'OpenWeatherMap' );
		expect( owmPanel ).toBeDefined();

		const { notices } = captureSinglePanel( owmPanel as CapturedPanel );
		const keyNotices = notices.filter( ( n ) =>
			( n.className ?? '' ).includes( 'kntnt-gpx-blocks-tile-key-notice' )
		);
		expect( keyNotices ).toHaveLength( 1 );
	} );

	it( 'forwards the pre-substituted URL to MapEditorPreview when the PHP key is non-empty (no client substitution)', () => {
		// Engaged-with-non-empty-key path: the server-side URL has
		// already had `{KEY}` replaced, so the preview receives that
		// URL verbatim — no `{KEY}` placeholder reaches the browser.
		const { previewProps } = mountAndCapture(
			buildAttributes( {
				tileOverlays: [ { provider: 'owmExternal', layer: 'clouds' } ],
			} )
		);

		const lastProps = previewProps[ previewProps.length - 1 ];
		const attributes = lastProps?.attributes as {
			overlays: ReadonlyArray< {
				url: string;
				attribution: string;
				maxZoom: number;
			} >;
		};
		expect( attributes.overlays ).toHaveLength( 1 );
		expect( attributes.overlays[ 0 ].url ).toBe(
			'https://tile.openweathermap.org/map/clouds_new/{z}/{x}/{y}.png?appid=PHP-OWM-SUBSTITUTED'
		);
		expect( attributes.overlays[ 0 ].url ).not.toContain( '{KEY}' );
	} );

	it( 'forwards the pre-substituted URL to MapEditorPreview when the option-layer key is non-empty (no client substitution)', () => {
		// Non-PHP-engaged path with a non-empty option-layer key: the
		// server-side enqueuer pre-substituted `{KEY}` from the option,
		// so the preview again receives a substituted URL verbatim.
		const { previewProps } = mountAndCapture(
			buildAttributes( {
				tileOverlays: [
					{ provider: 'owmOptionEngaged', layer: 'clouds' },
				],
			} )
		);

		const lastProps = previewProps[ previewProps.length - 1 ];
		const attributes = lastProps?.attributes as {
			overlays: ReadonlyArray< { url: string } >;
		};
		expect( attributes.overlays ).toHaveLength( 1 );
		expect( attributes.overlays[ 0 ].url ).toBe(
			'https://tile.openweathermap.org/map/clouds_new/{z}/{x}/{y}.png?appid=OPTION-OWM-SUBSTITUTED'
		);
		expect( attributes.overlays[ 0 ].url ).not.toContain( '{KEY}' );
	} );

	it( 'drops just the affected layer when the PHP key is empty and the URL still contains {KEY}; base map and other overlays still mount', () => {
		// Two overlays selected: one with PHP path engaged and an
		// empty PHP key (drops), one free (survives). The resolved
		// overlay array must contain exactly the free one.
		const { previewProps } = mountAndCapture(
			buildAttributes( {
				tileOverlays: [
					{ provider: 'owmExternalEmpty', layer: 'clouds' },
					{ provider: 'openseamap', layer: 'seamarks' },
				],
			} )
		);

		const lastProps = previewProps[ previewProps.length - 1 ];
		const attributes = lastProps?.attributes as {
			overlays: ReadonlyArray< {
				id: string;
				url: string;
				attribution: string;
				maxZoom: number;
			} >;
		};

		// Exactly one overlay survives — the free seamarks layer.
		// The PHP-empty owmExternalEmpty/clouds layer is dropped from
		// the resolved stack; the base map (mounted independently in
		// the preview) is unaffected.
		expect( attributes.overlays ).toHaveLength( 1 );
		expect( attributes.overlays[ 0 ].id ).toBe( 'openseamap/seamarks' );
		expect( attributes.overlays[ 0 ].url ).not.toContain( '{KEY}' );
	} );

	it( 'the saved (provider, layer) pair survives even when its PHP key is empty — only the runtime mount is suppressed', () => {
		// The toggle state (and the saved attribute) is independent
		// of the runtime drop. Saving the pair into `tileOverlays`
		// must not be blocked by the drop-the-layer branch.
		const { panels } = mountAndCapture(
			buildAttributes( {
				tileOverlays: [
					{ provider: 'owmExternalEmpty', layer: 'clouds' },
				],
			} )
		);

		const owmEmptyPanel = panels.find(
			( p ) => p.title === 'OpenWeatherMap (PHP key, empty)'
		);
		expect( owmEmptyPanel ).toBeDefined();

		const { toggles } = captureSinglePanel(
			owmEmptyPanel as CapturedPanel
		);
		const cloudsToggle = toggles.find(
			( t ) => t.label === 'Clouds (PHP empty)'
		);
		// The toggle is rendered, enabled, and reflects the saved
		// checked state — the editor surface mirrors persisted state
		// rather than silently rewriting it.
		expect( cloudsToggle ).toBeDefined();
		expect( cloudsToggle?.checked ).toBe( true );
		expect( cloudsToggle?.disabled ).not.toBe( true );
	} );
} );
