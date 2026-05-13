/**
 * Inspector-shape test for the GPX Map Edit component's Tiles panel
 * (issue #105 — provider/style hierarchy with per-provider API keys).
 *
 * The Map block now stores its tile-layer choice as a two-step
 * (provider, style) pair plus a `tileApiKeys` object keyed by provider
 * id (one API key per provider, shared across all that provider's
 * styles). The inspector's `TilesPanel` renders two `SelectControl`s
 * (provider, style) plus a conditional `TextControl` (API key) for
 * key-required providers.
 *
 * This file mounts `MapEdit` with the Tiles panel's controls captured
 * via mocks, then asserts:
 *
 * - The provider dropdown lists every validated provider; an orphan
 *   saved provider id surfaces as a placeholder option in the affected
 *   dropdown.
 * - The style dropdown is always rendered (even when the selected
 *   provider has only one style) and lists every style of the currently
 *   selected provider, with orphan saved style ids surfacing as a
 *   placeholder option in the affected dropdown.
 * - The API-key field appears only for `requiresKey === true` providers,
 *   reads the value from `tileApiKeys[ tileProvider ]`, and writes back
 *   a merged map that preserves other providers' keys.
 * - Switching providers resets `tileStyle` to the new provider's
 *   `default` unconditionally and preserves every other provider's
 *   stored API key intact.
 *
 * @since 1.0.0
 */

import { createElement, createRoot, flushSync } from '@wordpress/element';

/**
 * Captured `TextControl` props payload across every render in the
 * current test. The Tiles panel renders exactly one TextControl when
 * the resolved provider requires a key, so each entry corresponds to
 * one render of the panel.
 *
 * @since 1.0.0
 */
type CapturedTextControl = {
	label: string;
	value: string;
	onChange: ( next: string ) => void;
};

/**
 * Captured `SelectControl` props payload across every render. The
 * Tiles panel renders two SelectControls (provider, style); the test
 * keys off `label` to find each one.
 *
 * @since 1.0.0
 */
type CapturedSelectControl = {
	label: string;
	value: string;
	options: Array< { value: string; label: string } >;
	onChange: ( next: string ) => void;
};

const capturedTextControls: CapturedTextControl[] = [];
const capturedSelectControls: CapturedSelectControl[] = [];

// Mock @wordpress/block-editor — collapses every surface MapEdit reaches
// to a passthrough or noop. The Tiles-panel test does not care about
// PanelColorSettings, so it is collapsed to null.
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

// Mock @wordpress/components — capture TextControl and SelectControl
// props; collapse everything else to noops or passthroughs.
jest.mock(
	'@wordpress/components',
	() => ( {
		__esModule: true,
		PanelBody: ( { children }: { children: React.ReactNode } ) => children,
		ToggleControl: () => null,
		FontSizePicker: () => null,
		SelectControl: ( props: CapturedSelectControl ) => {
			capturedSelectControls.push( {
				label: props.label,
				value: props.value,
				options: props.options,
				onChange: props.onChange,
			} );
			return null;
		},
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

// Mock @wordpress/data — useSelect collapses to an empty-store passthrough.
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

// Capture every props payload `MapEditorPreview` receives so the tests
// can assert against the resolved `provider` record (or `null` for
// polyline-only). Keeps the mock to a single noop component while still
// exposing the shape the parent forwarded.
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

// Editor data globals — the Tiles-panel test needs both free providers
// (openstreetmap with two styles; opentopomap with a single style) and
// paid providers (Thunderforest with multiple styles, Mapbox with
// multiple styles) so the switching-preserves-keys assertion has more
// than one paid provider to flip between, and the single-style
// assertion has a concrete fixture.
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
				cyclosm: {
					label: 'CyclOSM',
					url: 'https://{s}.tile-cyclosm.openstreetmap.fr/cyclosm/{z}/{x}/{y}.png',
					attribution: 'OSM',
					maxZoom: 20,
				},
			},
		},
		opentopomap: {
			label: 'OpenTopoMap',
			requiresKey: false,
			default: 'standard',
			subdomains: [ 'a', 'b', 'c' ],
			styles: {
				standard: {
					label: 'Standard',
					url: 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',
					attribution: 'OTM',
					maxZoom: 17,
				},
			},
		},
		thunderforest: {
			label: 'Thunderforest',
			requiresKey: true,
			default: 'outdoor',
			signupUrl: 'https://www.thunderforest.com/',
			styles: {
				atlas: {
					label: 'Atlas',
					url: 'https://tile.thunderforest.com/atlas/{z}/{x}/{y}.png?apikey={KEY}',
					attribution: 'Thunderforest',
					maxZoom: 22,
				},
				outdoor: {
					label: 'Outdoors',
					url: 'https://tile.thunderforest.com/outdoors/{z}/{x}/{y}.png?apikey={KEY}',
					attribution: 'Thunderforest',
					maxZoom: 22,
				},
			},
		},
		mapbox: {
			label: 'Mapbox',
			requiresKey: true,
			default: 'outdoors',
			signupUrl: 'https://www.mapbox.com/',
			styles: {
				outdoors: {
					label: 'Outdoors',
					url: 'https://api.mapbox.com/styles/v1/mapbox/outdoors-v12/tiles/{z}/{x}/{y}?access_token={KEY}',
					attribution: 'Mapbox',
					maxZoom: 22,
				},
				dark: {
					label: 'Dark',
					url: 'https://api.mapbox.com/styles/v1/mapbox/dark-v11/tiles/{z}/{x}/{y}?access_token={KEY}',
					attribution: 'Mapbox',
					maxZoom: 22,
				},
			},
		},
	},
	overlays: {},
};

// Import the component under test AFTER all mocks are registered.
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
		enableScrollWheelZoom: true,
		enableDoubleClickZoom: true,
		enableKeyboard: true,
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
		tileApiKeys: {},
		tileOverlays: [],
		tileOverlayApiKeys: {},
		...overrides,
	};
}

/**
 * Mounts MapEdit and returns the captured TextControl / SelectControl
 * props plus the array of setAttributes writes performed during mount.
 *
 * @param attributes Attribute payload to feed into MapEdit.
 *
 * @return Tuple of captures and writes for assertion.
 */
function mountAndCapture( attributes: Record< string, unknown > ): {
	texts: CapturedTextControl[];
	selects: CapturedSelectControl[];
	writes: Array< Record< string, unknown > >;
	previewProps: Array< Record< string, unknown > >;
} {
	capturedTextControls.length = 0;
	capturedSelectControls.length = 0;
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
		texts: [ ...capturedTextControls ],
		selects: [ ...capturedSelectControls ],
		writes,
		previewProps: [ ...capturedPreviewProps ],
	};
}

describe( 'MapEdit Tiles panel — provider/style hierarchy (issue #105)', () => {
	it( 'renders both Provider and Style SelectControls for a free provider with multiple styles', () => {
		const { selects } = mountAndCapture( buildAttributes() );

		const providerSelect = selects.find( ( s ) => s.label === 'Provider' );
		const styleSelect = selects.find( ( s ) => s.label === 'Style' );

		expect( providerSelect ).toBeDefined();
		expect( styleSelect ).toBeDefined();

		// Style options reflect the selected provider's styles, in
		// registry order.
		expect( styleSelect?.options.map( ( o ) => o.value ) ).toEqual( [
			'mapnik',
			'cyclosm',
		] );
	} );

	it( 'always renders the Style SelectControl even when the selected provider has only one style', () => {
		const { selects } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'opentopomap',
				tileStyle: 'standard',
			} )
		);

		const styleSelect = selects.find( ( s ) => s.label === 'Style' );
		expect( styleSelect ).toBeDefined();
		expect( styleSelect?.options.map( ( o ) => o.value ) ).toEqual( [
			'standard',
		] );
	} );

	it( 'shows no API-key field for a free provider', () => {
		// openstreetmap has requiresKey: false. The Tiles panel renders
		// only the two SelectControls in that case.
		const { texts } = mountAndCapture( buildAttributes() );

		const apiKeyFields = texts.filter( ( t ) => t.label === 'API key' );
		expect( apiKeyFields ).toHaveLength( 0 );
	} );

	it( 'reads the API-key value from tileApiKeys[ tileProvider ] for a paid provider', () => {
		const { texts } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'thunderforest',
				tileStyle: 'outdoor',
				tileApiKeys: {
					thunderforest: 'THUNDER_KEY',
					mapbox: 'MAPBOX_KEY',
				},
			} )
		);

		const apiKeyField = texts.find( ( t ) => t.label === 'API key' );
		expect( apiKeyField ).toBeDefined();
		expect( apiKeyField?.value ).toBe( 'THUNDER_KEY' );
	} );

	it( 'falls back to the empty string when the selected provider has no key entry', () => {
		const { texts } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'mapbox',
				tileStyle: 'outdoors',
				tileApiKeys: {
					thunderforest: 'THUNDER_KEY',
				},
			} )
		);

		const apiKeyField = texts.find( ( t ) => t.label === 'API key' );
		expect( apiKeyField ).toBeDefined();
		expect( apiKeyField?.value ).toBe( '' );
	} );

	it( 'writes back via tileApiKeys merged with existing entries, preserving other providers keys', () => {
		const { texts, writes } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'thunderforest',
				tileStyle: 'outdoor',
				tileApiKeys: {
					thunderforest: 'OLD_THUNDER',
					mapbox: 'MAPBOX_KEY',
				},
			} )
		);

		const apiKeyField = texts.find( ( t ) => t.label === 'API key' );
		expect( apiKeyField ).toBeDefined();
		apiKeyField?.onChange( 'NEW_THUNDER' );

		expect( writes ).toEqual( [
			{
				tileApiKeys: {
					thunderforest: 'NEW_THUNDER',
					mapbox: 'MAPBOX_KEY',
				},
			},
		] );
	} );

	it( 'switching providers resets tileStyle to the new provider default and preserves every other provider key', () => {
		// Per-provider style memory is NOT retained: switching to Mapbox
		// always lands on Mapbox's `default` (`outdoors`), regardless of
		// which Mapbox style the user might have picked previously.
		// Per-provider key memory IS retained: every key in tileApiKeys
		// carries forward untouched.
		const { selects, writes } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'thunderforest',
				tileStyle: 'outdoor',
				tileApiKeys: {
					thunderforest: 'THUNDER_KEY',
					mapbox: 'MAPBOX_KEY',
				},
			} )
		);

		const providerSelect = selects.find( ( s ) => s.label === 'Provider' );
		expect( providerSelect ).toBeDefined();
		providerSelect?.onChange( 'mapbox' );

		// One write that carries both the new provider and the reset style.
		// `tileApiKeys` is left intact in the attribute store so both
		// keys survive the provider switch.
		expect( writes ).toEqual( [
			{ tileProvider: 'mapbox', tileStyle: 'outdoors' },
		] );
	} );

	it( 'writes only tileStyle when the style dropdown fires', () => {
		const { selects, writes } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'openstreetmap',
				tileStyle: 'mapnik',
			} )
		);

		const styleSelect = selects.find( ( s ) => s.label === 'Style' );
		expect( styleSelect ).toBeDefined();
		styleSelect?.onChange( 'cyclosm' );

		expect( writes ).toEqual( [ { tileStyle: 'cyclosm' } ] );
	} );

	it( 'surfaces an orphan saved tileProvider as a placeholder option in the Provider dropdown', () => {
		// The saved provider id is not in the registry. The dropdown
		// prepends it as a placeholder labelled with the id itself so
		// the editor reflects the persisted state without silently
		// rewriting it.
		const { selects } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'dropped-by-filter',
				tileStyle: 'whatever',
			} )
		);

		const providerSelect = selects.find( ( s ) => s.label === 'Provider' );
		expect( providerSelect ).toBeDefined();
		expect( providerSelect?.options[ 0 ] ).toEqual( {
			value: 'dropped-by-filter',
			label: 'dropped-by-filter',
		} );
	} );

	it( 'surfaces an orphan saved tileStyle as a placeholder option in the Style dropdown', () => {
		// The saved provider is valid but the saved style id is not in
		// that provider's styles map. The Style dropdown prepends the
		// orphan style id as a placeholder.
		const { selects } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'openstreetmap',
				tileStyle: 'dropped-style',
			} )
		);

		const styleSelect = selects.find( ( s ) => s.label === 'Style' );
		expect( styleSelect ).toBeDefined();
		expect( styleSelect?.options[ 0 ] ).toEqual( {
			value: 'dropped-style',
			label: 'dropped-style',
		} );
	} );

	it( 'sets the first per-provider entry when tileApiKeys starts empty', () => {
		const { texts, writes } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'thunderforest',
				tileStyle: 'outdoor',
				tileApiKeys: {},
			} )
		);

		const apiKeyField = texts.find( ( t ) => t.label === 'API key' );
		expect( apiKeyField ).toBeDefined();
		apiKeyField?.onChange( 'FIRST_KEY' );

		expect( writes ).toEqual( [
			{
				tileApiKeys: {
					thunderforest: 'FIRST_KEY',
				},
			},
		] );
	} );
} );

// PHP-supplied API key (issue #113). When the editor-data registry marks
// a provider with `apiKeyManagedExternally: true`, the per-provider API-key
// TextControl is hidden — the site builder owns the key in PHP and the
// editor must not surface it. The flag is presence-based (engagement is
// driven by the existence of the `apiKey` field on the
// `kntnt_gpx_blocks_tile_providers` filter callback's record, not its
// value), so the editor never sees the key value itself: only the URL
// the server pre-substituted, and the boolean flag.
describe( 'MapEdit Tiles panel — PHP-supplied apiKey (issue #113)', () => {
	const originalRegistry = (
		globalThis as {
			kntntGpxBlocks?: { providers: unknown; overlays: unknown };
		}
	 ).kntntGpxBlocks;

	beforeAll( () => {
		// Add two providers to the editor-data registry, mirroring what
		// `Editor_Data_Enqueuer::shape_providers()` would emit when the
		// PHP path is engaged: `apiKeyManagedExternally: true`, with
		// `{KEY}` either already substituted server-side (the resolved
		// key was non-empty) or still present (the resolved key was
		// empty, signalling fail-closed / polyline-only).
		(
			globalThis as {
				kntntGpxBlocks?: { providers: unknown; overlays: unknown };
			}
		 ).kntntGpxBlocks = {
			providers: {
				...( originalRegistry as { providers: object } ).providers,
				thunderforestExternal: {
					label: 'Thunderforest (PHP key)',
					requiresKey: true,
					default: 'outdoor',
					apiKeyManagedExternally: true,
					signupUrl: 'https://www.thunderforest.com/',
					styles: {
						outdoor: {
							label: 'Outdoors',
							url: 'https://tile.thunderforest.com/outdoors/{z}/{x}/{y}.png?apikey=PHP-SUBSTITUTED',
							attribution: 'Thunderforest',
							maxZoom: 22,
						},
					},
				},
				thunderforestExternalEmpty: {
					label: 'Thunderforest (PHP key, empty)',
					requiresKey: true,
					default: 'outdoor',
					apiKeyManagedExternally: true,
					signupUrl: 'https://www.thunderforest.com/',
					styles: {
						outdoor: {
							label: 'Outdoors',
							url: 'https://tile.thunderforest.com/outdoors/{z}/{x}/{y}.png?apikey={KEY}',
							attribution: 'Thunderforest',
							maxZoom: 22,
						},
					},
				},
			},
			overlays: {},
		};
	} );

	afterAll( () => {
		(
			globalThis as {
				kntntGpxBlocks?: { providers: unknown; overlays: unknown };
			}
		 ).kntntGpxBlocks = originalRegistry;
	} );

	it( 'hides the API-key TextControl for a provider whose apiKey is managed externally', () => {
		const { texts } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'thunderforestExternal',
				tileStyle: 'outdoor',
				tileApiKeys: {
					thunderforestExternal: 'this-attribute-key-is-ignored',
				},
			} )
		);

		const apiKeyField = texts.find( ( t ) => t.label === 'API key' );
		expect( apiKeyField ).toBeUndefined();
	} );

	it( 'still hides the API-key TextControl when the PHP-supplied key is empty (polyline-only preview branch)', () => {
		// The `apiKeyManagedExternally` flag remains `true` regardless
		// of whether the resolved PHP key was empty; engagement is
		// presence-based. The user must not see a TextControl that
		// would tempt them into supplying an attribute-path key the
		// renderer would never read.
		const { texts } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'thunderforestExternalEmpty',
				tileStyle: 'outdoor',
				tileApiKeys: {},
			} )
		);

		const apiKeyField = texts.find( ( t ) => t.label === 'API key' );
		expect( apiKeyField ).toBeUndefined();
	} );

	it( 'still renders the API-key TextControl for paid providers whose apiKey is not managed externally (attribute-path behaviour unchanged)', () => {
		const { texts } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'thunderforest',
				tileStyle: 'outdoor',
				tileApiKeys: { thunderforest: 'ATTR_KEY' },
			} )
		);

		const apiKeyField = texts.find( ( t ) => t.label === 'API key' );
		expect( apiKeyField ).toBeDefined();
		expect( apiKeyField?.value ).toBe( 'ATTR_KEY' );
	} );

	it( 'forwards the pre-substituted URL to MapEditorPreview without invoking client substitution', () => {
		// Engaged-with-non-empty-key path: the server-side URL has
		// already had `{KEY}` replaced, so the preview receives that
		// URL verbatim — no `{KEY}` placeholder and no attribute-path
		// substitution.
		const { previewProps } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'thunderforestExternal',
				tileStyle: 'outdoor',
				tileApiKeys: {
					thunderforestExternal: 'this-attribute-key-is-ignored',
				},
			} )
		);

		const lastProps = previewProps[ previewProps.length - 1 ];
		const attributes = lastProps?.attributes as { provider: unknown };
		const provider = attributes?.provider as {
			url: string;
			attribution: string;
			maxZoom: number;
		} | null;
		expect( provider ).not.toBeNull();
		expect( provider?.url ).toBe(
			'https://tile.thunderforest.com/outdoors/{z}/{x}/{y}.png?apikey=PHP-SUBSTITUTED'
		);
		expect( provider?.url ).not.toContain( '{KEY}' );
		// Attribute-path key never reaches the URL — the bypass is binary.
		expect( provider?.url ).not.toContain(
			'this-attribute-key-is-ignored'
		);
	} );

	it( 'forwards provider=null to MapEditorPreview (polyline-only) when the PHP key is empty and the URL still contains {KEY}', () => {
		const { previewProps } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'thunderforestExternalEmpty',
				tileStyle: 'outdoor',
				tileApiKeys: {},
			} )
		);

		const lastProps = previewProps[ previewProps.length - 1 ];
		const attributes = lastProps?.attributes as { provider: unknown };
		expect( attributes?.provider ).toBeNull();
	} );
} );
