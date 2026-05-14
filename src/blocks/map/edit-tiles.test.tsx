/**
 * Inspector-shape test for the GPX Map Edit component's Tiles panel.
 *
 * The Map block stores its tile-layer choice as a two-step (provider,
 * style) pair. Per-base-provider tile API keys live in the site-wide
 * `kntnt_gpx_blocks_tile_provider_keys` option (issue #149), not in
 * per-block attributes; the inspector's `TilesPanel` therefore renders
 * two `SelectControl`s (provider, style) plus a conditional `Notice`
 * that points the user at `Settings → Kntnt GPX Blocks` for
 * key-required providers whose key is not already supplied by code.
 *
 * This file mounts `MapEdit` with the Tiles panel's controls captured
 * via mocks, then asserts:
 *
 * - The provider dropdown lists every validated provider; an orphan
 *   saved provider id surfaces as a placeholder option.
 * - The style dropdown is always rendered and lists every style of the
 *   currently selected provider; an orphan saved style id surfaces as
 *   a placeholder option.
 * - The key-required Notice fires for paid providers whose record does
 *   not carry `apiKeyManagedExternally: true`, and is absent for both
 *   free providers and PHP-engaged paid providers.
 * - The Notice contains a link to the settings page when the editor
 *   payload reports `canManageSettings === true`, and renders plain
 *   text otherwise (so `edit_posts`-only users see the same hint
 *   without a non-functional link).
 * - Switching providers resets `tileStyle` to the new provider's
 *   `default`; no API-key state is written, because no API-key
 *   attribute exists.
 *
 * @since 1.0.0
 */

import { createElement, createRoot, flushSync } from '@wordpress/element';

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

/**
 * Captured `Notice` mount across every render. The Tiles panel renders
 * exactly one Notice for the key-required, non-PHP-engaged branch and
 * none otherwise — tests count the array length to assert presence or
 * absence, and inspect the children to verify the link branch.
 *
 * `children` is captured as the React element tree the Notice wraps so
 * tests can recurse into it and assert on the presence/absence of an
 * anchor element regardless of the exact wrapper markup.
 *
 * @since 1.0.0
 */
type CapturedNotice = {
	status?: string;
	children: unknown;
};

const capturedSelectControls: CapturedSelectControl[] = [];
const capturedNotices: CapturedNotice[] = [];

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

// Mock @wordpress/components — capture SelectControl + Notice props;
// collapse everything else to noops or passthroughs. The mocked
// `Notice` captures the React element tree it received as `children`
// before returning null; the tests inspect that tree via
// `flattenChildren` to assert link presence and text content.
//
// `ExternalLink` is rendered as a real anchor element via React's
// `createElement` (resolved at factory time through require() inside
// the factory body — the inline require keeps Jest's out-of-scope
// guard happy).
jest.mock(
	'@wordpress/components',
	() => {
		const wpElement = require( '@wordpress/element' );
		return {
			__esModule: true,
			PanelBody: ( { children }: { children: React.ReactNode } ) =>
				children,
			ToggleControl: () => null,
			ToolbarButton: () => null,
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
			TextControl: () => null,
			ExternalLink: ( {
				children,
				href,
			}: {
				children: React.ReactNode;
				href: string;
			} ) =>
				wpElement.createElement(
					'a',
					{ href, 'data-external': 'true' },
					children
				),
			Notice: ( {
				children,
				status,
			}: {
				children: React.ReactNode;
				status?: string;
			} ) => {
				capturedNotices.push( { status, children } );
				return null;
			},
			__experimentalToolsPanel: () => null,
			__experimentalToolsPanelItem: () => null,
		};
	},
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

/**
 * Editor data globals. Mirrors what `Editor_Data_Enqueuer` emits after
 * issue #149: providers carry the same shape as before plus an
 * `apiKeyManagedExternally` flag, and the payload root carries
 * `settingsUrl` + `canManageSettings`. The PHP path is *not* engaged
 * for any of the seeded providers in the default fixture — tests that
 * want to exercise the PHP-engaged branch override the global locally.
 *
 * The Thunderforest URL is left with `{KEY}` intact so the default
 * fixture exercises the option-not-supplied / fail-closed branch; a
 * test that wants a pre-substituted URL overrides the registry to
 * supply a substituted URL instead.
 */
interface RegistryShape {
	providers: unknown;
	overlays: unknown;
	settingsUrl?: string;
	canManageSettings?: boolean;
}

const defaultRegistry: RegistryShape = {
	providers: {
		openstreetmap: {
			label: 'OpenStreetMap',
			requiresKey: false,
			default: 'mapnik',
			subdomains: [ 'a', 'b', 'c' ],
			apiKeyManagedExternally: false,
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
			apiKeyManagedExternally: false,
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
			apiKeyManagedExternally: false,
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
			apiKeyManagedExternally: false,
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
	settingsUrl:
		'https://example.test/wp-admin/options-general.php?page=kntnt-gpx-blocks',
	canManageSettings: true,
};

( globalThis as { kntntGpxBlocks?: RegistryShape } ).kntntGpxBlocks =
	defaultRegistry;

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
 * Mounts MapEdit and returns the captured SelectControl + Notice props
 * plus the array of setAttributes writes performed during mount.
 *
 * @param attributes Attribute payload to feed into MapEdit.
 *
 * @return Tuple of captures and writes for assertion.
 */
function mountAndCapture( attributes: Record< string, unknown > ): {
	selects: CapturedSelectControl[];
	notices: CapturedNotice[];
	writes: Array< Record< string, unknown > >;
	previewProps: Array< Record< string, unknown > >;
} {
	capturedSelectControls.length = 0;
	capturedNotices.length = 0;
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
		selects: [ ...capturedSelectControls ],
		notices: [ ...capturedNotices ],
		writes,
		previewProps: [ ...capturedPreviewProps ],
	};
}

/**
 * Recursively flattens a React-element tree to its rendered string
 * content. Used so a test can assert "the notice copy mentions the
 * settings-page name" or "the notice copy includes an anchor element"
 * without binding to a specific markup structure.
 *
 * React elements expose `type` (the component or HTML tag) and `props`
 * (including `children`); fragments expose `type === Symbol.for(...)`
 * but the flatten walker doesn't care about the fragment marker — it
 * descends into `props.children` and continues. The walker emits a
 * minimal `<a href="…">…</a>` marker when it encounters an element
 * whose `type` is the string `'a'` (the mocked ExternalLink renders an
 * actual anchor) so tests can grep for the anchor marker.
 *
 * @param node React element, array of elements, primitive, or null.
 *
 * @return Flattened string content with anchor markers spliced in.
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
			type?: unknown;
			props?: { children?: unknown; href?: string };
		};
		if ( ! element.props ) {
			return '';
		}
		const inner = flattenChildren( element.props.children );
		// Surface anchor presence with a marker tag the test can grep for.
		if ( element.type === 'a' ) {
			const href = element.props.href ?? '';
			return '<a href="' + href + '">' + inner + '</a>';
		}
		return inner;
	}
	return '';
}

describe( 'MapEdit Tiles panel — provider/style hierarchy', () => {
	beforeEach( () => {
		( globalThis as { kntntGpxBlocks?: RegistryShape } ).kntntGpxBlocks =
			defaultRegistry;
	} );

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

	it( 'shows no key-required Notice for a free provider', () => {
		// openstreetmap has requiresKey: false. The Tiles panel renders
		// only the two SelectControls — no Notice.
		const { notices } = mountAndCapture( buildAttributes() );

		expect( notices ).toHaveLength( 0 );
	} );

	it( 'writes only tileProvider+tileStyle when switching providers; no key state involved', () => {
		const { selects, writes } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'thunderforest',
				tileStyle: 'outdoor',
			} )
		);

		const providerSelect = selects.find( ( s ) => s.label === 'Provider' );
		expect( providerSelect ).toBeDefined();
		providerSelect?.onChange( 'mapbox' );

		// One write that carries the new provider and the reset style.
		// Per-block API-key state no longer exists; switching providers
		// touches only the (provider, style) pair.
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
} );

describe( 'MapEdit Tiles panel — key-required Notice (issue #149)', () => {
	beforeEach( () => {
		( globalThis as { kntntGpxBlocks?: RegistryShape } ).kntntGpxBlocks =
			defaultRegistry;
	} );

	it( 'renders a Notice for a key-required provider whose key is not managed externally', () => {
		const { notices } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'thunderforest',
				tileStyle: 'outdoor',
			} )
		);

		expect( notices ).toHaveLength( 1 );
		const flat = flattenChildren( notices[ 0 ].children );
		expect( flat ).toContain( 'Settings → Kntnt GPX Blocks' );
	} );

	it( 'wraps the settings-page name in a link when canManageSettings is true', () => {
		const { notices } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'thunderforest',
				tileStyle: 'outdoor',
			} )
		);

		const flat = flattenChildren( notices[ 0 ].children );
		expect( flat ).toContain( '<a href="https://example.test' );
		expect( flat ).toContain( 'Settings → Kntnt GPX Blocks</a>' );
	} );

	it( 'renders the settings-page name as plain text when canManageSettings is false', () => {
		( globalThis as { kntntGpxBlocks?: RegistryShape } ).kntntGpxBlocks = {
			...defaultRegistry,
			canManageSettings: false,
		};

		const { notices } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'thunderforest',
				tileStyle: 'outdoor',
			} )
		);

		expect( notices ).toHaveLength( 1 );
		const flat = flattenChildren( notices[ 0 ].children );
		// The settings-page name still appears, but not as an anchor —
		// `edit_posts`-only users see the hint without a link they
		// could follow uselessly.
		expect( flat ).toContain( 'Settings → Kntnt GPX Blocks' );
		// Filter the captured tree for anchor markers that point at the
		// settings page; the settings-page name must not be one.
		expect( flat ).not.toContain(
			'<a href="https://example.test/wp-admin/options-general.php?page=kntnt-gpx-blocks">Settings → Kntnt GPX Blocks</a>'
		);
	} );

	it( 'omits the Notice when apiKeyManagedExternally is true (PHP path engaged)', () => {
		( globalThis as { kntntGpxBlocks?: RegistryShape } ).kntntGpxBlocks = {
			...defaultRegistry,
			providers: {
				...( defaultRegistry.providers as Record< string, unknown > ),
				phpProvider: {
					label: 'Thunderforest (PHP)',
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
			},
		};

		const { notices } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'phpProvider',
				tileStyle: 'outdoor',
			} )
		);

		expect( notices ).toHaveLength( 0 );
	} );

	it( 'still mounts the preview without invoking client-side substitution for PHP-engaged providers', () => {
		( globalThis as { kntntGpxBlocks?: RegistryShape } ).kntntGpxBlocks = {
			...defaultRegistry,
			providers: {
				...( defaultRegistry.providers as Record< string, unknown > ),
				phpProvider: {
					label: 'Thunderforest (PHP)',
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
			},
		};

		const { previewProps } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'phpProvider',
				tileStyle: 'outdoor',
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
	} );

	it( 'forwards provider=null to MapEditorPreview (polyline-only) when the URL still contains {KEY}', () => {
		// Default Thunderforest URL contains `{KEY}` because no key
		// layer (PHP or option) supplied one. The preview's
		// fail-closed detector recognises the unsubstituted placeholder
		// and returns null, mirroring the frontend's URL-null gate.
		const { previewProps } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'thunderforest',
				tileStyle: 'outdoor',
			} )
		);

		const lastProps = previewProps[ previewProps.length - 1 ];
		const attributes = lastProps?.attributes as { provider: unknown };
		expect( attributes?.provider ).toBeNull();
	} );

	it( 'mounts the preview with the option-substituted URL verbatim when the server pre-substituted the option-layer key', () => {
		( globalThis as { kntntGpxBlocks?: RegistryShape } ).kntntGpxBlocks = {
			...defaultRegistry,
			providers: {
				...( defaultRegistry.providers as Record< string, unknown > ),
				thunderforest: {
					label: 'Thunderforest',
					requiresKey: true,
					default: 'outdoor',
					apiKeyManagedExternally: false,
					signupUrl: 'https://www.thunderforest.com/',
					styles: {
						outdoor: {
							label: 'Outdoors',
							url: 'https://tile.thunderforest.com/outdoors/{z}/{x}/{y}.png?apikey=OPTION-KEY',
							attribution: 'Thunderforest',
							maxZoom: 22,
						},
					},
				},
			},
		};

		const { previewProps, notices } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'thunderforest',
				tileStyle: 'outdoor',
			} )
		);

		const lastProps = previewProps[ previewProps.length - 1 ];
		const attributes = lastProps?.attributes as { provider: unknown };
		const provider = attributes?.provider as {
			url: string;
		} | null;

		// Pre-substituted URL flows through verbatim; the editor
		// preview mounts the working tile layer.
		expect( provider?.url ).toBe(
			'https://tile.thunderforest.com/outdoors/{z}/{x}/{y}.png?apikey=OPTION-KEY'
		);

		// The inspector Notice fires for every paid non-PHP-engaged
		// provider per the issue spec — it points the user at the
		// settings page so they can review or rotate the key, even
		// when the key is already configured.
		expect( notices ).toHaveLength( 1 );
	} );
} );
