/**
 * Unit tests for {@link InspectorDataSource} and
 * {@link shouldShowDataSourcePanel}.
 *
 * The component is a thin wrapper around `SelectControl`; the tests
 * focus on the broken-binding-placeholder behaviour added as the
 * Step 2 follow-up patch:
 *
 *   - Healthy binding → options list is the supplied `mapOptions`
 *     verbatim, with no synthetic placeholder.
 *   - Broken binding → options list is prepended with a single empty
 *     placeholder entry so the native `<select>` displays a
 *     clearly-empty selection and a click on any real option produces
 *     an `onChange` event.
 *
 * `shouldShowDataSourcePanel` is exercised with the four
 * configured-count / broken combinations the Step 2 spec pins.
 *
 * @since 1.0.0
 */

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

interface CapturedSelect {
	value: unknown;
	options: ReadonlyArray< { label: string; value: string } >;
}

const capturedSelects: CapturedSelect[] = [];

jest.mock(
	'@wordpress/block-editor',
	() => ( {
		__esModule: true,
		InspectorControls: ( { children }: { children: React.ReactNode } ) =>
			children,
	} ),
	{ virtual: true }
);

jest.mock(
	'@wordpress/components',
	() => ( {
		__esModule: true,
		PanelBody: ( { children }: { children: React.ReactNode } ) => children,
		SelectControl: ( props: CapturedSelect ) => {
			capturedSelects.push( {
				value: props.value,
				options: props.options,
			} );
			return null;
		},
	} ),
	{ virtual: true }
);

import { createElement, createRoot, flushSync } from '@wordpress/element';
import {
	InspectorDataSource,
	shouldShowDataSourcePanel,
} from './inspector-data-source';

beforeEach( () => {
	capturedSelects.length = 0;
} );

function renderInspector( props: {
	mapId: string;
	mapOptions: ReadonlyArray< { label: string; value: string } >;
	bindingBroken: boolean;
} ): void {
	const container = document.createElement( 'div' );
	const root = createRoot( container );
	flushSync( () => {
		root.render(
			createElement( InspectorDataSource, {
				...props,
				onChange: () => undefined,
			} )
		);
	} );
	root.unmount();
}

describe( 'InspectorDataSource', () => {
	it( 'renders mapOptions verbatim when the binding is healthy', () => {
		renderInspector( {
			mapId: 'map-aaa',
			mapOptions: [
				{ label: 'Map A', value: 'map-aaa' },
				{ label: 'Map B', value: 'map-bbb' },
			],
			bindingBroken: false,
		} );
		expect( capturedSelects ).toHaveLength( 1 );
		expect( capturedSelects[ 0 ].value ).toBe( 'map-aaa' );
		expect( capturedSelects[ 0 ].options ).toEqual( [
			{ label: 'Map A', value: 'map-aaa' },
			{ label: 'Map B', value: 'map-bbb' },
		] );
	} );

	it( 'prepends an empty placeholder option when the binding is broken', () => {
		renderInspector( {
			mapId: 'map-stale',
			mapOptions: [ { label: 'Map A', value: 'map-aaa' } ],
			bindingBroken: true,
		} );
		expect( capturedSelects ).toHaveLength( 1 );
		expect( capturedSelects[ 0 ].options ).toEqual( [
			{ label: '— Select a GPX Map —', value: '' },
			{ label: 'Map A', value: 'map-aaa' },
		] );
		// Critical for the case 1 / case 2 fix: the SelectControl value
		// is still the stale `mapId`, but the empty placeholder is the
		// first option, so the native <select> fallback now displays the
		// placeholder (not the first real option) as selected.
		expect( capturedSelects[ 0 ].value ).toBe( 'map-stale' );
	} );

	it( 'prepends placeholder even when multiple Maps remain after a delete', () => {
		renderInspector( {
			mapId: 'map-stale',
			mapOptions: [
				{ label: 'Map A', value: 'map-aaa' },
				{ label: 'Map B', value: 'map-bbb' },
			],
			bindingBroken: true,
		} );
		expect( capturedSelects[ 0 ].options ).toHaveLength( 3 );
		expect( capturedSelects[ 0 ].options[ 0 ].value ).toBe( '' );
	} );
} );

describe( 'shouldShowDataSourcePanel', () => {
	it.each( [
		// configured, broken, expected
		[ 0, false, false ],
		[ 0, true, false ],
		[ 1, false, false ],
		[ 1, true, true ],
		[ 2, false, true ],
		[ 2, true, true ],
		[ 5, false, true ],
	] )(
		'with configuredCount=%d, bindingBroken=%p → %p',
		( count, broken, expected ) => {
			expect(
				shouldShowDataSourcePanel( count as number, broken as boolean )
			).toBe( expected );
		}
	);
} );
