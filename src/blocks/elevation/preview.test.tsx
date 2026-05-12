/**
 * Unit tests for {@link ElevationPreview} and its helpers.
 *
 * Pins the Step 2 warning strings (one per "broken binding" reason)
 * and the healthy-state info-box string, including the integer
 * rendering of min/max elevations.
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

import { createElement, createRoot, flushSync } from '@wordpress/element';

import {
	ElevationPreview,
	healthyMessage,
	warningMessage,
	type PreviewState,
} from './preview';

/**
 * Renders the preview into a detached DOM node and returns the
 * rendered HTML so tests can assert on the content and structure.
 * @param state
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
	it( 'returns the no-map spec string', () => {
		expect( warningMessage( 'no-map' ) ).toBe(
			'There is no GPX Map block with a selected GPX file on this page. Add a GPX Map block before this one.'
		);
	} );

	it( 'returns the bound-deleted spec string', () => {
		expect( warningMessage( 'bound-deleted' ) ).toBe(
			'The GPX Map block this block was bound to is no longer on the page. Pick another from the dropdown.'
		);
	} );

	it( 'returns the bound-unconfigured spec string', () => {
		expect( warningMessage( 'bound-unconfigured' ) ).toBe(
			'The GPX Map block this block is bound to has no GPX file selected.'
		);
	} );
} );

describe( 'healthyMessage', () => {
	it( 'formats the bound label and integer min/max values', () => {
		expect( healthyMessage( 'Northern loop', 12, 345 ) ).toBe(
			'Bound to Northern loop. Min: 12 m, Max: 345 m.'
		);
	} );
} );

describe( 'ElevationPreview', () => {
	it( 'renders the warning box for kind="no-map"', () => {
		const html = renderToHtml( { kind: 'no-map' } );
		expect( html ).toContain(
			'kntnt-gpx-blocks-elevation-preview-warning'
		);
		expect( html ).toContain( 'There is no GPX Map block' );
	} );

	it( 'renders the warning box for kind="bound-deleted"', () => {
		const html = renderToHtml( { kind: 'bound-deleted' } );
		expect( html ).toContain(
			'kntnt-gpx-blocks-elevation-preview-warning'
		);
		expect( html ).toContain( 'no longer on the page' );
	} );

	it( 'renders the warning box for kind="bound-unconfigured"', () => {
		const html = renderToHtml( { kind: 'bound-unconfigured' } );
		expect( html ).toContain(
			'kntnt-gpx-blocks-elevation-preview-warning'
		);
		expect( html ).toContain( 'no GPX file selected' );
	} );

	it( 'renders the info box for kind="healthy"', () => {
		const html = renderToHtml( {
			kind: 'healthy',
			label: 'Northern loop',
			min: 12,
			max: 345,
		} );
		expect( html ).toContain( 'kntnt-gpx-blocks-elevation-preview-info' );
		expect( html ).toContain(
			'Bound to Northern loop. Min: 12 m, Max: 345 m.'
		);
	} );

	it( 'renders a loading box for kind="loading"', () => {
		const html = renderToHtml( { kind: 'loading' } );
		expect( html ).toContain( 'kntnt-gpx-blocks-elevation-preview-info' );
		expect( html ).toContain( 'Loading bound GPX Map' );
	} );

	it( 'renders an error box with the supplied message', () => {
		const html = renderToHtml( {
			kind: 'error',
			message: 'No track in this file',
		} );
		expect( html ).toContain(
			'kntnt-gpx-blocks-elevation-preview-warning'
		);
		expect( html ).toContain( 'No track in this file' );
	} );

	it( 'falls back to a generic message when no error message is supplied', () => {
		const html = renderToHtml( { kind: 'error' } );
		expect( html ).toContain( 'could not be loaded' );
	} );
} );
