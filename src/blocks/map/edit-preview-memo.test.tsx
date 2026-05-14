/**
 * Regression test for the GPX Map Edit component's provider/overlays
 * memoization (issue #130).
 *
 * Before #130 `resolveProviderForPreview()` and `resolveOverlaysForPreview()`
 * were called directly inline in the JSX passed to `MapEditorPreview`, so the
 * preview received a fresh `provider` object and a fresh `overlays` array on
 * every parent re-render — even when their inputs were unchanged. The preview
 * compensated with a `JSON.stringify`-keyed `useEffect` workaround. The fix
 * moves the memoization upstream into `MapEdit` via `useMemo` and lets the
 * preview depend on `provider` / `overlays` by reference.
 *
 * This file pins the upstream invariant. The mocked `MapEditorPreview`
 * captures every `attributes` prop it receives, the test mounts `MapEdit`,
 * triggers a re-render driven by an unrelated attribute change (a colour
 * picker), and asserts that the `provider` and `overlays` references are
 * the same object on both renders. If a future change drops the `useMemo`
 * call (or its dependency array misses an input it should track), the test
 * fails — and the editor preview would silently rebuild its base tile layer
 * and overlay stack on every keystroke in an unrelated control.
 *
 * @since 1.0.0
 */

import { createElement, createRoot, flushSync } from '@wordpress/element';

/**
 * Captured `MapEditorPreview` props payload across every render in the
 * current test. The provider/overlays references are the values under
 * test; the rest of the attribute shape is irrelevant here and stored
 * loosely typed.
 *
 * @since 1.0.0
 */
const capturedPreviewProps: Array< Record< string, unknown > > = [];

// Mock @wordpress/block-editor — collapse every surface MapEdit reaches
// to a passthrough or noop. `useBlockProps` is a passthrough so the
// outer wrapper renders without error; nothing here cares about its
// styles.
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

// Mock @wordpress/components — every surface collapses to a passthrough or
// noop. PanelBody passes children through so the inspector subtree renders
// without throwing.
jest.mock(
	'@wordpress/components',
	() => ( {
		__esModule: true,
		PanelBody: ( { children }: { children: React.ReactNode } ) => children,
		ToggleControl: () => null,
		ToolbarButton: () => null,
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

// Capture every props payload `MapEditorPreview` receives. Storing the raw
// props object lets the test compare the `attributes.provider` /
// `attributes.overlays` references across renders via `Object.is`.
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

// Editor data globals — a single free provider with one style plus a single
// overlay provider with one layer is enough to drive both the provider and
// the overlays memo paths through their non-null branches.
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
		opensea: {
			label: 'OpenSeaMap',
			requiresKey: false,
			subdomains: [ 't1' ],
			layers: {
				seamarks: {
					label: 'Seamarks',
					url: 'https://t1.openseamap.org/seamark/{z}/{x}/{y}.png',
					attribution: 'OpenSeaMap',
					maxZoom: 18,
				},
			},
		},
	},
};

// Import the component under test AFTER all mocks are registered.
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
		tileApiKeys: {},
		tileOverlays: [ { provider: 'opensea', layer: 'seamarks' } ],
		tileOverlayApiKeys: {},
		...overrides,
	};
}

/**
 * Reads `attributes.provider` from a captured `MapEditorPreview` props
 * payload. Centralises the cast so each test stays focused on the
 * invariant being asserted rather than the prop-shape plumbing.
 *
 * @param props Captured props payload.
 *
 * @return The provider reference forwarded to the preview on that render.
 */
function getProvider( props: Record< string, unknown > ): unknown {
	return ( props.attributes as Record< string, unknown > ).provider;
}

/**
 * Reads `attributes.overlays` from a captured `MapEditorPreview` props
 * payload.
 *
 * @param props Captured props payload.
 *
 * @return The overlays reference forwarded to the preview on that render.
 */
function getOverlays( props: Record< string, unknown > ): unknown {
	return ( props.attributes as Record< string, unknown > ).overlays;
}

/**
 * Simulates Gutenberg's `setAttributes` semantics: a partial update keeps
 * the references of every unchanged attribute intact. The block editor
 * stores attributes as a single object and shallow-merges incoming updates
 * via the Redux reducer; passing a fresh nested map on every render would
 * be a test-only artefact that defeats the memoization the production code
 * intentionally relies on. This helper mirrors the real merge so the memo
 * can be exercised under realistic input.
 *
 * @param base  Base attribute payload (shared maps and primitives).
 * @param patch Partial update to apply on top of `base`.
 *
 * @return Merged attribute payload preserving unchanged sub-references.
 */
function applyPatch(
	base: Record< string, unknown >,
	patch: Record< string, unknown >
): Record< string, unknown > {
	return { ...base, ...patch };
}

describe( 'MapEdit provider/overlays memoization (issue #130)', () => {
	it( 'forwards the same `provider` reference across re-renders driven by an unrelated attribute change', () => {
		capturedPreviewProps.length = 0;
		const container = document.createElement( 'div' );
		const root = createRoot( container );

		// First render: baseline attributes. The preview captures the
		// initial `provider` reference. The base payload is reused across
		// both renders so nested maps (tileApiKeys, tileOverlays,
		// tileOverlayApiKeys) keep their references — mirroring how
		// Gutenberg's Redux store shallow-merges incoming setAttributes
		// payloads and leaves every unchanged sub-object intact.
		const base = buildAttributes();
		flushSync( () => {
			root.render(
				createElement( MapEdit, {
					attributes: base,
					setAttributes: () => undefined,
					clientId: 'test-client',
					isSelected: false,
					name: 'kntnt-gpx-blocks/map',
				} as never )
			);
		} );

		// Second render: same provider/style/API key inputs, but a colour
		// attribute is bumped — the kind of change a colour-picker drag
		// produces dozens of times per second. The memo's dep array does
		// not include trackColor, so the resolved provider record must
		// come back as the same reference.
		flushSync( () => {
			root.render(
				createElement( MapEdit, {
					attributes: applyPatch( base, { trackColor: '#abcdef' } ),
					setAttributes: () => undefined,
					clientId: 'test-client',
					isSelected: false,
					name: 'kntnt-gpx-blocks/map',
				} as never )
			);
		} );

		root.unmount();

		expect( capturedPreviewProps.length ).toBeGreaterThanOrEqual( 2 );
		const first = capturedPreviewProps[ 0 ];
		const last = capturedPreviewProps[ capturedPreviewProps.length - 1 ];
		expect( getProvider( first ) ).not.toBeNull();
		expect( Object.is( getProvider( first ), getProvider( last ) ) ).toBe(
			true
		);
	} );

	it( 'forwards the same `overlays` reference across re-renders driven by an unrelated attribute change', () => {
		capturedPreviewProps.length = 0;
		const container = document.createElement( 'div' );
		const root = createRoot( container );

		const base = buildAttributes();
		flushSync( () => {
			root.render(
				createElement( MapEdit, {
					attributes: base,
					setAttributes: () => undefined,
					clientId: 'test-client',
					isSelected: false,
					name: 'kntnt-gpx-blocks/map',
				} as never )
			);
		} );

		flushSync( () => {
			root.render(
				createElement( MapEdit, {
					attributes: applyPatch( base, {
						waypointColor: '#123456',
					} ),
					setAttributes: () => undefined,
					clientId: 'test-client',
					isSelected: false,
					name: 'kntnt-gpx-blocks/map',
				} as never )
			);
		} );

		root.unmount();

		expect( capturedPreviewProps.length ).toBeGreaterThanOrEqual( 2 );
		const first = capturedPreviewProps[ 0 ];
		const last = capturedPreviewProps[ capturedPreviewProps.length - 1 ];
		// One overlay pair is configured in buildAttributes so the resolved
		// array is non-empty — the reference-equality assertion is
		// meaningful only when the memoized value actually has content.
		expect( Array.isArray( getOverlays( first ) ) ).toBe( true );
		expect( ( getOverlays( first ) as unknown[] ).length ).toBe( 1 );
		expect( Object.is( getOverlays( first ), getOverlays( last ) ) ).toBe(
			true
		);
	} );

	it( 'returns a fresh `provider` reference when `tileProvider` changes — the memo respects its dep array', () => {
		// Counterpart to the stability assertion: a change in an input the
		// memo *does* track must invalidate the cache, otherwise the
		// preview would never see provider switches. Mounts twice with
		// distinct provider ids and asserts the references differ.
		capturedPreviewProps.length = 0;
		const container = document.createElement( 'div' );
		const root = createRoot( container );

		const base = buildAttributes();
		flushSync( () => {
			root.render(
				createElement( MapEdit, {
					attributes: base,
					setAttributes: () => undefined,
					clientId: 'test-client',
					isSelected: false,
					name: 'kntnt-gpx-blocks/map',
				} as never )
			);
		} );

		// Re-render with a saved provider id that is not in the registry.
		// resolveProviderForPreview falls back to the canonical provider in
		// that case, so the resolved record is structurally similar but
		// must be a new object — the memo's tileProvider dep changed.
		flushSync( () => {
			root.render(
				createElement( MapEdit, {
					attributes: applyPatch( base, {
						tileProvider: 'unknown-provider-id',
					} ),
					setAttributes: () => undefined,
					clientId: 'test-client',
					isSelected: false,
					name: 'kntnt-gpx-blocks/map',
				} as never )
			);
		} );

		root.unmount();

		expect( capturedPreviewProps.length ).toBeGreaterThanOrEqual( 2 );
		const first = capturedPreviewProps[ 0 ];
		const last = capturedPreviewProps[ capturedPreviewProps.length - 1 ];
		expect( Object.is( getProvider( first ), getProvider( last ) ) ).toBe(
			false
		);
	} );
} );
