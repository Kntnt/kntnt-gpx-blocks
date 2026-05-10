/**
 * Inspector-shape test for the GPX Map Edit component's Tiles panel
 * (issue #102 — per-provider API keys).
 *
 * The Map block previously stored a single `tileApiKey` string scoped to
 * whichever provider was currently selected. Switching from provider A to
 * provider B and back to A forced the editor to re-enter A's key, which
 * was hostile to anyone configuring more than one paid provider. Issue
 * #102 replaces the scalar with a `tileApiKeys` object keyed by provider
 * id; the inspector's TextControl reads and writes the entry for the
 * currently-selected provider, and switching providers preserves the
 * other providers' keys intact.
 *
 * This file mounts MapEdit with the Tiles panel's TextControl captured
 * via a mock, then asserts:
 *
 * - The displayed value reflects `tileApiKeys[ tileProvider ]`.
 * - Typing in the TextControl writes back a `tileApiKeys` object that
 *   merges the new entry into the existing map without touching keys for
 *   other providers.
 * - Switching the selected provider (via the SelectControl) keeps every
 *   other provider's key intact.
 *
 * @since 1.0.0
 */

import { createElement, createRoot, flushSync } from '@wordpress/element';

/**
 * Captured `TextControl` props payload across every render in the
 * current test. The Tiles panel renders exactly one TextControl when the
 * resolved provider requires a key, so each entry corresponds to one
 * render of the panel.
 *
 * @since 1.0.0
 */
type CapturedTextControl = {
	label: string;
	value: string;
	onChange: ( next: string ) => void;
};

/**
 * Captured `SelectControl` props payload across every render. The Tiles
 * panel renders one SelectControl listing every provider in the editor
 * registry; assertions key off its `onChange` to flip the selected id.
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

// Editor data globals — the Tiles-panel test needs both a free provider
// (osm-standard, no key surface) and two paid providers (Thunderforest
// Outdoors and MapTiler Outdoor) so the switching-preserves-keys
// assertion has more than one paid id to flip between.
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
		'thunderforest-outdoors': {
			label: 'Thunderforest Outdoors',
			requiresKey: true,
			url: 'https://tile.thunderforest.com/outdoors/{z}/{x}/{y}.png?apikey={KEY}',
			attribution: 'Thunderforest',
			maxZoom: 22,
			signupUrl: 'https://www.thunderforest.com/',
		},
		'maptiler-outdoor': {
			label: 'MapTiler Outdoor',
			requiresKey: true,
			url: 'https://api.maptiler.com/maps/outdoor-v2/{z}/{x}/{y}.png?key={KEY}',
			attribution: 'MapTiler',
			maxZoom: 22,
			signupUrl: 'https://www.maptiler.com/',
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
		tileApiKeys: {},
		tileOverlays: [],
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
} {
	capturedTextControls.length = 0;
	capturedSelectControls.length = 0;
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
	};
}

describe( 'MapEdit Tiles panel — per-provider API keys (issue #102)', () => {
	it( 'shows no API-key field for a free provider', () => {
		// osm-standard has requiresKey: false. The Tiles panel renders only
		// the SelectControl in that case.
		const { texts } = mountAndCapture( buildAttributes() );

		const apiKeyFields = texts.filter( ( t ) => t.label === 'API key' );
		expect( apiKeyFields ).toHaveLength( 0 );
	} );

	it( 'reads the API-key value from tileApiKeys[ tileProvider ]', () => {
		const { texts } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'thunderforest-outdoors',
				tileApiKeys: {
					'thunderforest-outdoors': 'THUNDER_KEY',
					'maptiler-outdoor': 'MAPTILER_KEY',
				},
			} )
		);

		const apiKeyField = texts.find( ( t ) => t.label === 'API key' );
		expect( apiKeyField ).toBeDefined();
		expect( apiKeyField?.value ).toBe( 'THUNDER_KEY' );
	} );

	it( 'falls back to the empty string when the selected provider has no entry', () => {
		const { texts } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'maptiler-outdoor',
				tileApiKeys: {
					'thunderforest-outdoors': 'THUNDER_KEY',
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
				tileProvider: 'thunderforest-outdoors',
				tileApiKeys: {
					'thunderforest-outdoors': 'OLD_THUNDER',
					'maptiler-outdoor': 'MAPTILER_KEY',
				},
			} )
		);

		const apiKeyField = texts.find( ( t ) => t.label === 'API key' );
		expect( apiKeyField ).toBeDefined();
		apiKeyField?.onChange( 'NEW_THUNDER' );

		expect( writes ).toEqual( [
			{
				tileApiKeys: {
					'thunderforest-outdoors': 'NEW_THUNDER',
					'maptiler-outdoor': 'MAPTILER_KEY',
				},
			},
		] );
	} );

	it( 'switching provider does not overwrite or drop the previous provider key (preserves all entries on switch)', () => {
		// The Tiles panel's onChange writes only tileProvider when the
		// SelectControl fires — tileApiKeys is untouched, so the existing
		// map carries forward in the next render. This test mirrors the
		// "switching providers preserves keys" acceptance criterion.
		const { selects, writes } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'thunderforest-outdoors',
				tileApiKeys: {
					'thunderforest-outdoors': 'THUNDER_KEY',
					'maptiler-outdoor': 'MAPTILER_KEY',
				},
			} )
		);

		const providerSelect = selects.find( ( s ) => s.label === 'Provider' );
		expect( providerSelect ).toBeDefined();
		providerSelect?.onChange( 'maptiler-outdoor' );

		// Only tileProvider is written; tileApiKeys is left intact in the
		// attribute store so both keys survive the provider switch.
		expect( writes ).toEqual( [ { tileProvider: 'maptiler-outdoor' } ] );
	} );

	it( 'sets the first per-provider entry when tileApiKeys starts empty', () => {
		const { texts, writes } = mountAndCapture(
			buildAttributes( {
				tileProvider: 'thunderforest-outdoors',
				tileApiKeys: {},
			} )
		);

		const apiKeyField = texts.find( ( t ) => t.label === 'API key' );
		expect( apiKeyField ).toBeDefined();
		apiKeyField?.onChange( 'FIRST_KEY' );

		expect( writes ).toEqual( [
			{
				tileApiKeys: {
					'thunderforest-outdoors': 'FIRST_KEY',
				},
			},
		] );
	} );
} );
