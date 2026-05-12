/**
 * Unit tests for the shared TypographyToolsPanel component.
 *
 * The point of these tests — required by the elevation rebuild plan
 * (`docs/elevation-rebuild.md`, Step 1) — is to lock the prefix →
 * attribute-name mapping the component uses internally. Step 1
 * instantiates the panel for three Elevation prefixes (`tickLabel`,
 * `tooltipDistance`, `tooltipHeight`); Step 8 migrates the Map block's
 * two existing panels onto the same component (`tooltipName`,
 * `tooltipDesc`). Covering all five prefixes here keeps the Step 8
 * migration mechanical: if Step 8 swaps the Map's bespoke panels for
 * the shared one, no attribute keys move and the existing post_content
 * keeps working byte-for-byte.
 *
 * Strategy: mock the WordPress dependency surface with passthrough
 * stubs that capture `onChange` and `onDeselect` callbacks keyed by a
 * shape-specific token (one token per WordPress control type) or by
 * the `ToolsPanelItem` label. The test then triggers each captured
 * callback with a sentinel value and asserts the resulting
 * `setAttributes` write targeted exactly `${prefix}${expectedSuffix}`.
 *
 * The mock-store identifier is named `mockCaptured` so Jest's
 * factory-hoisting allow-list lets the factory close over it.
 *
 * @since 1.0.0
 */

import { createElement, createRoot, flushSync } from '@wordpress/element';

/**
 * Per-render capture buffer for control `onChange` callbacks and
 * `ToolsPanelItem` `onDeselect` callbacks. Each test resets it in
 * `beforeEach()` so test cases stay independent.
 *
 * @since 1.0.0
 */
const mockCaptured = {
	change: {} as Record< string, ( value: unknown ) => void >,
	deselect: {} as Record< string, () => void >,
};

beforeEach( () => {
	mockCaptured.change = {};
	mockCaptured.deselect = {};
} );

jest.mock(
	'@wordpress/block-editor',
	() => ( {
		__esModule: true,
		useSettings: () => [ undefined, undefined ],
		__experimentalFontFamilyControl: ( props: {
			onChange?: ( value: unknown ) => void;
		} ) => {
			mockCaptured.change.FontFamily =
				props.onChange ?? ( () => undefined );
			return null;
		},
		__experimentalFontAppearanceControl: ( props: {
			onChange?: ( value: unknown ) => void;
		} ) => {
			mockCaptured.change.Appearance =
				props.onChange ?? ( () => undefined );
			return null;
		},
		__experimentalLetterSpacingControl: ( props: {
			onChange?: ( value: unknown ) => void;
		} ) => {
			mockCaptured.change.LetterSpacing =
				props.onChange ?? ( () => undefined );
			return null;
		},
		__experimentalTextDecorationControl: ( props: {
			onChange?: ( value: unknown ) => void;
		} ) => {
			mockCaptured.change.TextDecoration =
				props.onChange ?? ( () => undefined );
			return null;
		},
		__experimentalTextTransformControl: ( props: {
			onChange?: ( value: unknown ) => void;
		} ) => {
			mockCaptured.change.TextTransform =
				props.onChange ?? ( () => undefined );
			return null;
		},
		LineHeightControl: ( props: {
			onChange?: ( value: unknown ) => void;
		} ) => {
			mockCaptured.change.LineHeight =
				props.onChange ?? ( () => undefined );
			return null;
		},
	} ),
	{ virtual: true }
);

jest.mock(
	'@wordpress/components',
	() => {
		const { createElement: ce } =
			jest.requireActual( '@wordpress/element' );
		return {
			__esModule: true,
			FontSizePicker: ( props: {
				onChange?: ( value: unknown ) => void;
			} ) => {
				mockCaptured.change.FontSize =
					props.onChange ?? ( () => undefined );
				return null;
			},
			__experimentalToolsPanel: ( props: {
				children?: React.ReactNode;
			} ) =>
				ce( 'div', { 'data-testid': 'tools-panel' }, props.children ),
			__experimentalToolsPanelItem: ( props: {
				label?: string;
				onDeselect?: () => void;
				children?: React.ReactNode;
			} ) => {
				const label = props.label ?? '';
				mockCaptured.deselect[ label ] =
					props.onDeselect ?? ( () => undefined );
				return ce(
					'div',
					{ 'data-tools-panel-item-label': label },
					props.children
				);
			},
		};
	},
	{ virtual: true }
);

jest.mock(
	'@wordpress/i18n',
	() => ( {
		__esModule: true,
		__: ( text: string ) => text,
	} ),
	{ virtual: true }
);

import { TypographyToolsPanel } from './typography-tools-panel';

/**
 * Renders the panel for a given prefix and returns the recorded
 * `setAttributes` write log. React renders synchronously inside
 * `flushSync` so every captured callback is registered before the
 * function returns.
 *
 * @since 1.0.0
 *
 * @param prefix Attribute prefix under test.
 * @return Object with the writes array (mutated by the panel's
 *         `setAttributes` calls during the test body).
 */
function renderPanel( prefix: string ): {
	writes: Array< Record< string, unknown > >;
} {
	const writes: Array< Record< string, unknown > > = [];
	const setAttributes = ( next: Record< string, unknown > ) => {
		writes.push( next );
	};
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	flushSync( () =>
		root.render(
			createElement( TypographyToolsPanel, {
				title: 'Test',
				prefix,
				attributes: {},
				setAttributes,
				defaultVisibility: {},
				panelId: 'test-panel',
			} )
		)
	);
	return { writes };
}

const PREFIXES = [
	'tickLabel',
	'tooltipDistance',
	'tooltipHeight',
	'tooltipName',
	'tooltipDesc',
] as const;

describe.each( PREFIXES )( 'TypographyToolsPanel — prefix %s', ( prefix ) => {
	it( 'writes FontFamily under `<prefix>FontFamily`', () => {
		const { writes } = renderPanel( prefix );
		mockCaptured.change.FontFamily?.( 'Inter' );
		expect( writes ).toEqual( [
			{ [ `${ prefix }FontFamily` ]: 'Inter' },
		] );
	} );

	it( 'writes FontSize under `<prefix>FontSize`', () => {
		const { writes } = renderPanel( prefix );
		mockCaptured.change.FontSize?.( '16px' );
		expect( writes ).toEqual( [ { [ `${ prefix }FontSize` ]: '16px' } ] );
	} );

	it( 'writes Appearance into both `<prefix>FontWeight` and `<prefix>FontStyle`', () => {
		const { writes } = renderPanel( prefix );
		mockCaptured.change.Appearance?.( {
			fontWeight: '600',
			fontStyle: 'italic',
		} );
		expect( writes ).toEqual( [
			{
				[ `${ prefix }FontWeight` ]: '600',
				[ `${ prefix }FontStyle` ]: 'italic',
			},
		] );
	} );

	it( 'writes LineHeight under `<prefix>LineHeight`', () => {
		const { writes } = renderPanel( prefix );
		mockCaptured.change.LineHeight?.( '1.4' );
		expect( writes ).toEqual( [ { [ `${ prefix }LineHeight` ]: '1.4' } ] );
	} );

	it( 'writes LetterSpacing under `<prefix>LetterSpacing`', () => {
		const { writes } = renderPanel( prefix );
		mockCaptured.change.LetterSpacing?.( '0.05em' );
		expect( writes ).toEqual( [
			{ [ `${ prefix }LetterSpacing` ]: '0.05em' },
		] );
	} );

	it( 'writes TextDecoration under `<prefix>TextDecoration`', () => {
		const { writes } = renderPanel( prefix );
		mockCaptured.change.TextDecoration?.( 'underline' );
		expect( writes ).toEqual( [
			{ [ `${ prefix }TextDecoration` ]: 'underline' },
		] );
	} );

	it( 'writes TextTransform under `<prefix>TextTransform`', () => {
		const { writes } = renderPanel( prefix );
		mockCaptured.change.TextTransform?.( 'uppercase' );
		expect( writes ).toEqual( [
			{ [ `${ prefix }TextTransform` ]: 'uppercase' },
		] );
	} );

	it( 'resets every aspect to the empty string via per-item onDeselect', () => {
		const { writes } = renderPanel( prefix );
		const labels = [
			'Font',
			'Size',
			'Appearance',
			'Line height',
			'Letter spacing',
			'Decoration',
			'Letter case',
		];
		for ( const label of labels ) {
			mockCaptured.deselect[ label ]?.();
		}

		const seenKeys = new Set< string >();
		for ( const w of writes ) {
			for ( const k of Object.keys( w ) ) {
				seenKeys.add( k );
				expect( w[ k ] ).toBe( '' );
			}
		}
		expect( seenKeys ).toEqual(
			new Set( [
				`${ prefix }FontFamily`,
				`${ prefix }FontSize`,
				`${ prefix }FontWeight`,
				`${ prefix }FontStyle`,
				`${ prefix }LineHeight`,
				`${ prefix }LetterSpacing`,
				`${ prefix }TextDecoration`,
				`${ prefix }TextTransform`,
			] )
		);
	} );
} );
