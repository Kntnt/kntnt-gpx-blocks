/**
 * Unit tests for {@link ElevationPreview} and its helpers.
 *
 * Pins the six warning strings (one per warning kind) and the loading-
 * returns-null contract. The healthy branch is exercised through
 * `chart.test.tsx` — this file only verifies that the preview
 * dispatches to `<Chart>` for the healthy state.
 *
 * @since 1.0.0
 */

jest.mock(
	'@wordpress/i18n',
	() => ( {
		__esModule: true,
		__: ( s: string ) => s,
		sprintf: ( template: string, ...args: unknown[] ) => {
			let out = template;
			args.forEach( ( arg, idx ) => {
				out = out.replace(
					new RegExp( `%${ idx + 1 }\\$[ds]`, 'g' ),
					String( arg )
				);
			} );
			out = out.replace( /%d/g, () => String( args.shift() ?? '' ) );
			return out;
		},
	} ),
	{ virtual: true }
);

jest.mock( './chart', () => ( {
	__esModule: true,
	Chart: () => null,
} ) );

import { createElement, createRoot, flushSync } from '@wordpress/element';

import {
	ElevationPreview,
	warningMessage,
	type PreviewState,
	type WarningKind,
} from './preview';

/**
 * Renders the preview into a detached DOM node and returns the
 * rendered HTML so tests can assert on the content and structure.
 *
 * @param state Preview state to render.
 */
function renderToHtml( state: PreviewState ): string {
	const container = document.createElement( 'div' );
	const root = createRoot( container );
	flushSync( () => {
		root.render( createElement( ElevationPreview, { state } ) );
	} );
	const html = container.innerHTML;
	root.unmount();
	return html;
}

describe( 'warningMessage', () => {
	it.each< [ WarningKind, string ] >( [
		[
			'no-map',
			'There is no GPX Map block with a selected GPX file on this page. Add a GPX Map block before this one.',
		],
		[
			'bound-deleted',
			'The GPX Map block this block was bound to is no longer on the page. Pick another from the dropdown.',
		],
		[
			'bound-unconfigured',
			'The GPX Map block this block is bound to has no GPX file selected.',
		],
		[
			'no-elevation-data',
			'The bound GPX track has no elevation data. The elevation profile cannot be rendered.',
		],
		[
			'zero-distance',
			'The bound GPX track has no distance (all points are at the same location).',
		],
		[
			'payload-error',
			'Could not fetch data for the bound GPX track. Try reloading the page.',
		],
	] )( 'returns the spec string for kind=%s', ( kind, expected ) => {
		expect( warningMessage( kind ) ).toBe( expected );
	} );
} );

describe( 'ElevationPreview', () => {
	it.each< WarningKind >( [
		'no-map',
		'bound-deleted',
		'bound-unconfigured',
		'no-elevation-data',
		'zero-distance',
		'payload-error',
	] )( 'renders the warning box for kind=%s', ( kind ) => {
		const html = renderToHtml( { kind } );
		expect( html ).toContain(
			'kntnt-gpx-blocks-elevation-preview-warning'
		);
		expect( html ).toContain( warningMessage( kind ) );
	} );

	it( 'renders nothing for kind="loading" (the wrapper retains its slot)', () => {
		const html = renderToHtml( { kind: 'loading' } );
		expect( html ).toBe( '' );
	} );

	it( 'dispatches to <Chart> for kind="healthy"', () => {
		// The Chart mock above returns null, so the only signal we have
		// is that no warning markup is emitted. The chart-specific
		// rendering is covered by chart.test.tsx.
		const html = renderToHtml( {
			kind: 'healthy',
			data: { minElevation: 100, maxElevation: 200, distance: 5000 },
			typography: {},
		} );
		expect( html ).not.toContain(
			'kntnt-gpx-blocks-elevation-preview-warning'
		);
	} );
} );
