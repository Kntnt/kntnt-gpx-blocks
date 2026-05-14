/**
 * Click-to-copy Map ID badge in the GPX Map block toolbar (issue #147).
 *
 * Surfaces the auto-generated `mapId` attribute in the editor toolbar as a
 * `<ToolbarButton>` whose visible text equals the clipboard contents
 * byte-for-byte. This test mounts `MapEdit`, captures the rendered toolbar
 * button, mocks `navigator.clipboard.writeText` and the `core/notices`
 * dispatcher, and exercises both the success and error paths.
 *
 * The mocks intentionally collapse every surface MapEdit reaches that
 * isn't load-bearing for this assertion. `BlockControls` records the
 * `group` prop and the children it received so the test can assert the
 * badge lives in a `<BlockControls>` with no `group` prop (the middle
 * group between alignment and Replace), separate from the existing
 * `group="other"` BlockControls that carries MediaReplaceFlow. The
 * `ToolbarButton` mock renders a real `<button>` so React events flow
 * through `onClick` and React Testing Library's `act` keeps state
 * transitions ordered.
 *
 * @since 1.0.0
 */

import { createElement, createRoot, flushSync } from '@wordpress/element';
// `react` is provided by `@wordpress/scripts`' Jest preset as a runtime
// peer dependency; the project's own package.json deliberately depends
// only on `@wordpress/element`. The lint disable is identical to the
// pattern used in `src/blocks/elevation/chart.test.tsx`.
// eslint-disable-next-line import/no-extraneous-dependencies
import { act } from 'react';

/**
 * Captured `BlockControls` payload — one entry per render. Each captures
 * the `group` prop (undefined when omitted) and the children React node so
 * the test can locate the right BlockControls and walk its children to
 * find the toolbar button under test.
 *
 * @since 1.0.0
 */
type CapturedBlockControls = {
	group?: string;
	children: React.ReactNode;
};
const capturedBlockControls: CapturedBlockControls[] = [];

/**
 * Captured `ToolbarButton` props payload — one entry per render. Holds
 * everything the test asserts: the `text`, `label`, `aria-label`,
 * `className`, `showTooltip`, and the live `onClick` handler.
 *
 * @since 1.0.0
 */
type CapturedToolbarButton = {
	text?: string;
	label?: string;
	ariaLabel?: string;
	className?: string;
	showTooltip?: boolean;
	onClick?: () => void;
};
const capturedToolbarButtons: CapturedToolbarButton[] = [];

/**
 * Captured `createNotice` arguments — one entry per dispatched notice.
 * Recorded by the `@wordpress/data` mock's `dispatch( noticesStore )`
 * stub so the test can assert the click handler emits the expected
 * snackbar type, message, and options.
 *
 * @since 1.0.0
 */
type CapturedNotice = {
	status: string;
	message: string;
	options: Record< string, unknown >;
};
const capturedNotices: CapturedNotice[] = [];

// Mock @wordpress/block-editor — BlockControls records its props so the
// test can assert the badge lives in a no-`group` BlockControls. The
// other surfaces collapse to passthroughs or noops.
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
		InspectorControls: () => null,
		BlockControls: ( props: {
			group?: string;
			children: React.ReactNode;
		} ) => {
			capturedBlockControls.push( {
				group: props.group,
				children: props.children,
			} );
			return props.children;
		},
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

// Mock @wordpress/components — ToolbarButton renders a real `<button>`
// (with the captured props) so React's onClick handler fires through a
// genuine click event during the test. Everything else collapses to a
// passthrough or noop.
jest.mock(
	'@wordpress/components',
	() => ( {
		__esModule: true,
		PanelBody: ( { children }: { children?: React.ReactNode } ) => children,
		ToggleControl: () => null,
		ToolbarButton: ( props: {
			text?: string;
			label?: string;
			'aria-label'?: string;
			className?: string;
			showTooltip?: boolean;
			onClick?: () => void;
		} ) => {
			capturedToolbarButtons.push( {
				text: props.text,
				label: props.label,
				ariaLabel: props[ 'aria-label' ],
				className: props.className,
				showTooltip: props.showTooltip,
				onClick: props.onClick,
			} );
			// Resolve createElement lazily so the jest.mock factory does
			// not reference an out-of-scope import binding (jest forbids
			// non-`mock`-prefixed captures across the factory boundary).
			const {
				createElement: ce,
			} = require( '@wordpress/element' );
			return ce(
				'button',
				{
					type: 'button',
					className: props.className,
					'aria-label': props[ 'aria-label' ],
					onClick: props.onClick,
				},
				props.text
			);
		},
		FontSizePicker: () => null,
		SelectControl: () => null,
		TextControl: () => null,
		ExternalLink: () => null,
		Notice: () => null,
		__experimentalToolsPanel: ( {
			children,
		}: {
			children?: React.ReactNode;
		} ) => children,
		__experimentalToolsPanelItem: () => null,
	} ),
	{ virtual: true }
);

// Mock @wordpress/data — `useSelect` collapses to empty stores, and
// `dispatch( noticesStore )` returns a `createNotice` stub that records
// every call so the test can assert the snackbar payload exactly.
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
		dispatch: () => ( {
			createNotice: (
				status: string,
				message: string,
				options: Record< string, unknown >
			) => {
				capturedNotices.push( { status, message, options } );
			},
		} ),
	} ),
	{ virtual: true }
);

// Mock @wordpress/core-data and @wordpress/notices — only the store
// references are read so a string sentinel suffices.
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

// Mock @wordpress/i18n — translation passthrough with a sprintf that
// substitutes %s placeholders in order so the aria-label assertion can
// match against the rendered string verbatim.
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

// Mock the editor preview and the unique-mapId hook — both reach into
// surfaces (Leaflet, the block-editor store) the toolbar test doesn't
// need. The unique-id hook is a noop because the test feeds mapId
// directly through the attributes payload.
jest.mock(
	'./editor-preview',
	() => ( { __esModule: true, MapEditorPreview: () => null } ),
	{ virtual: true }
);
jest.mock(
	'./use-ensure-unique-map-id',
	() => ( { __esModule: true, useEnsureUniqueMapId: () => undefined } ),
	{ virtual: true }
);

// Editor data globals — MapEdit reads `window.kntntGpxBlocks.providers`
// and `…overlays` when rendering tile-provider controls. Minimal shapes
// keep the destructure stable without exercising those panels.
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
	overlays: {},
};

// Import the component under test AFTER all mocks are registered so the
// jest module factory hooks resolve before MapEdit's module evaluates.
import { MapEdit } from './edit';

/**
 * Builds a full MapAttributes payload with overrides applied on top of
 * the defaults. Mirrors the attribute shape in block.json so the
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
		mapId: 'map-7k9f2m',
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
		tileOverlays: [],
		tileOverlayApiKeys: {},
		...overrides,
	};
}

/**
 * Mounts MapEdit, returns the container element so the test can find the
 * rendered toolbar button by class name. The container is left mounted
 * so the caller can invoke its click handler; tests are responsible for
 * unmounting it.
 *
 * @param attributes Attribute payload to feed into MapEdit.
 *
 * @return Mounted container and the React root for cleanup.
 */
function mountMapEdit( attributes: Record< string, unknown > ): {
	container: HTMLElement;
	root: ReturnType< typeof createRoot >;
} {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
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
	return { container, root };
}

describe( 'MapEdit click-to-copy Map ID badge (issue #147)', () => {
	beforeEach( () => {
		capturedBlockControls.length = 0;
		capturedToolbarButtons.length = 0;
		capturedNotices.length = 0;
	} );

	it( 'renders the badge inside a <BlockControls> with no group prop', () => {
		const { root } = mountMapEdit( buildAttributes() );

		// The component renders one BlockControls with no `group` (the
		// middle-group home of the badge) and one with `group="other"`
		// (the existing MediaReplaceFlow surface).
		const groups = capturedBlockControls.map( ( entry ) => entry.group );
		expect( groups ).toContain( undefined );
		expect( groups ).toContain( 'other' );

		// The badge's ToolbarButton renders exactly once.
		expect( capturedToolbarButtons ).toHaveLength( 1 );

		root.unmount();
	} );

	it( 'uses the literal mapId as visible text with no decoration', () => {
		const { root } = mountMapEdit(
			buildAttributes( { mapId: 'map-7k9f2m' } )
		);

		// WYSIWYG: the text prop equals the clipboard contents byte-for-byte.
		expect( capturedToolbarButtons[ 0 ].text ).toBe( 'map-7k9f2m' );

		root.unmount();
	} );

	it( 'sets the visual tooltip label, the badge class, and showTooltip', () => {
		const { root } = mountMapEdit( buildAttributes() );

		const button = capturedToolbarButtons[ 0 ];
		expect( button.label ).toBe( 'Copy Map ID' );
		expect( button.className ).toBe( 'kntnt-gpx-blocks-map-id-badge' );
		expect( button.showTooltip ).toBe( true );

		root.unmount();
	} );

	it( 'builds an aria-label that names the exact mapId being copied', () => {
		const { root } = mountMapEdit(
			buildAttributes( { mapId: 'map-abc123' } )
		);

		expect( capturedToolbarButtons[ 0 ].ariaLabel ).toBe(
			'Copy Map ID map-abc123 to clipboard'
		);

		root.unmount();
	} );

	it( 'is omitted when mapId is empty (pre-useEnsureUniqueMapId state)', () => {
		const { root } = mountMapEdit( buildAttributes( { mapId: '' } ) );

		// No ToolbarButton rendered when mapId is empty — the badge would
		// otherwise flash an empty pill on a freshly inserted block before
		// useEnsureUniqueMapId has assigned an id.
		expect( capturedToolbarButtons ).toHaveLength( 0 );

		// The `other`-group BlockControls (MediaReplaceFlow) still
		// renders unconditionally.
		const groups = capturedBlockControls.map( ( entry ) => entry.group );
		expect( groups ).toContain( 'other' );
		expect( groups ).not.toContain( undefined );

		root.unmount();
	} );

	it( 'copies the exact mapId to the clipboard and emits a success snackbar on click', async () => {
		// Mock navigator.clipboard.writeText to resolve immediately. The
		// Promise resolution is observed via the success-snackbar capture.
		const writeText = jest.fn< Promise< void >, [ string ] >( () =>
			Promise.resolve()
		);
		Object.defineProperty( navigator, 'clipboard', {
			configurable: true,
			value: { writeText },
		} );

		const { container, root } = mountMapEdit(
			buildAttributes( { mapId: 'map-7k9f2m' } )
		);

		// Locate the rendered <button> by class — the ToolbarButton mock
		// renders one — and click it through `act` so React flushes the
		// promise resolution before the assertions run.
		const button = container.querySelector(
			'button.kntnt-gpx-blocks-map-id-badge'
		) as HTMLButtonElement | null;
		expect( button ).not.toBeNull();

		await act( async () => {
			button?.click();
			// Yield to the microtask queue so the writeText promise's
			// `.then` handler runs and dispatches the snackbar.
			await Promise.resolve();
		} );

		expect( writeText ).toHaveBeenCalledTimes( 1 );
		expect( writeText ).toHaveBeenCalledWith( 'map-7k9f2m' );

		expect( capturedNotices ).toHaveLength( 1 );
		expect( capturedNotices[ 0 ] ).toEqual( {
			status: 'success',
			message: 'Map ID copied',
			options: { type: 'snackbar', isDismissible: true },
		} );

		root.unmount();
	} );

	it( 'surfaces an error snackbar when navigator.clipboard.writeText rejects', async () => {
		// Mock writeText to reject so the click handler's failure branch
		// fires and the error snackbar is dispatched.
		const writeText = jest.fn< Promise< void >, [ string ] >( () =>
			Promise.reject( new Error( 'clipboard blocked' ) )
		);
		Object.defineProperty( navigator, 'clipboard', {
			configurable: true,
			value: { writeText },
		} );

		const { container, root } = mountMapEdit(
			buildAttributes( { mapId: 'map-7k9f2m' } )
		);

		const button = container.querySelector(
			'button.kntnt-gpx-blocks-map-id-badge'
		) as HTMLButtonElement | null;
		expect( button ).not.toBeNull();

		await act( async () => {
			button?.click();
			// Allow both the rejected promise to settle and the rejection
			// handler to dispatch the error snackbar.
			await Promise.resolve();
			await Promise.resolve();
		} );

		expect( writeText ).toHaveBeenCalledWith( 'map-7k9f2m' );

		expect( capturedNotices ).toHaveLength( 1 );
		expect( capturedNotices[ 0 ] ).toEqual( {
			status: 'error',
			message: "Couldn't copy Map ID",
			options: { type: 'snackbar', isDismissible: true },
		} );

		root.unmount();
	} );
} );
