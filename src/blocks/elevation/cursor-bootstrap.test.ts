/**
 * Unit tests for the elevation cursor's bootstrap helpers (issue #144).
 *
 * Pins the two decision points that {@link buildCursorElementsForLifecycle}
 * and {@link readCursorSettingsFromContext} surface in the view layer:
 *
 *   - Reading the three Cursor & guides booleans from the per-block
 *     Interactivity context with the documented defaults.
 *   - Skipping the cursor `<g>` creation entirely when the master
 *     `showCursor` toggle is off.
 *
 * @since 1.0.0
 */
import {
	buildCursorElementsForLifecycle,
	readCursorSettingsFromContext,
} from './cursor-bootstrap';
import type { ChartScale } from './geometry/scale';

const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * Hand-picked plot rectangle so each attribute the helpers write
 * resolves to an obvious expected value.
 *
 * @since 1.0.0
 *
 * @return A complete {@link ChartScale} fit for the unit tests.
 */
function buildScale(): ChartScale {
	const plotLeft = 50;
	const plotRight = 550;
	const plotTop = 10;
	const plotBottom = 190;
	return {
		distance: 1000,
		niceYMin: 0,
		niceYMax: 100,
		plotLeft,
		plotRight,
		plotTop,
		plotBottom,
		availX: plotRight - plotLeft,
		availY: plotBottom - plotTop,
		em: 16,
		projectX: ( d: number ): number =>
			plotLeft + ( d / 1000 ) * ( plotRight - plotLeft ),
		projectY: ( e: number ): number =>
			plotBottom - ( e / 100 ) * ( plotBottom - plotTop ),
		xTicks: [],
		yTicks: [],
	};
}

describe( 'readCursorSettingsFromContext', () => {
	it( 'applies the documented defaults when the context is undefined', () => {
		const settings = readCursorSettingsFromContext( undefined );
		expect( settings.showCursor ).toBe( true );
		expect( settings.guideOptions.showVerticalGuide ).toBe( true );
		expect( settings.guideOptions.showHorizontalGuide ).toBe( false );
	} );

	it( 'applies the documented defaults when the context is empty', () => {
		const settings = readCursorSettingsFromContext( {} );
		expect( settings.showCursor ).toBe( true );
		expect( settings.guideOptions.showVerticalGuide ).toBe( true );
		expect( settings.guideOptions.showHorizontalGuide ).toBe( false );
	} );

	it( 'honours an explicit showCursor = false', () => {
		const settings = readCursorSettingsFromContext( {
			showCursor: false,
		} );
		expect( settings.showCursor ).toBe( false );
	} );

	it( 'honours an explicit showVerticalGuide = false', () => {
		const settings = readCursorSettingsFromContext( {
			showVerticalGuide: false,
		} );
		expect( settings.guideOptions.showVerticalGuide ).toBe( false );
	} );

	it( 'honours an explicit showHorizontalGuide = true', () => {
		const settings = readCursorSettingsFromContext( {
			showHorizontalGuide: true,
		} );
		expect( settings.guideOptions.showHorizontalGuide ).toBe( true );
	} );

	it( 'falls back to defaults on non-boolean values (defence-in-depth)', () => {
		// A malformed context bag (e.g. produced by a future schema
		// migration that ships before the matching client code) must
		// not crash the view module — the helper coerces silently to
		// the defaults.
		const settings = readCursorSettingsFromContext( {
			showCursor: 'yes' as unknown as boolean,
			showVerticalGuide: 1 as unknown as boolean,
			showHorizontalGuide: null as unknown as boolean,
		} );
		expect( settings.showCursor ).toBe( true );
		expect( settings.guideOptions.showVerticalGuide ).toBe( true );
		expect( settings.guideOptions.showHorizontalGuide ).toBe( false );
	} );
} );

describe( 'readCursorSettingsFromContext — watch-callback gating (issue #144)', () => {
	it( 'returns showCursor=false for a context with the master toggle off, so the watch callback can short-circuit', () => {
		// `view.ts`'s `onElevationCursorChange` watches the per-mapId
		// fraction and the test pin here is the boolean that controls
		// the early-return branch — the helper exposes the same value
		// the watch reads, so the gating decision is one place.
		const settings = readCursorSettingsFromContext( {
			showCursor: false,
			showVerticalGuide: true,
			showHorizontalGuide: true,
		} );
		expect( settings.showCursor ).toBe( false );
	} );

	it( 'returns showCursor=true for a context with the master toggle on, so the watch callback runs syncCursor', () => {
		const settings = readCursorSettingsFromContext( {
			showCursor: true,
		} );
		expect( settings.showCursor ).toBe( true );
	} );
} );

describe( 'buildCursorElementsForLifecycle — pointer-handlers gating (issue #144)', () => {
	it( 'returns null so the caller can skip bindPointerHandlersWhenVisible entirely', () => {
		// The pointer-input layer in view.ts is wrapped in
		// `if ( showCursor ) { bindPointerHandlersWhenVisible( … ) }`.
		// `buildCursorElementsForLifecycle` exposes the same yes/no
		// outcome through its return value, so a `null` return is
		// the test surface for the not-bound case.
		const svg = document.createElementNS( SVG_NS, 'svg' ) as SVGSVGElement;
		const result = buildCursorElementsForLifecycle(
			{
				showCursor: false,
				guideOptions: {
					showVerticalGuide: true,
					showHorizontalGuide: false,
				},
			},
			svg,
			buildScale()
		);
		expect( result ).toBeNull();
	} );
} );

describe( 'buildCursorElementsForLifecycle', () => {
	it( 'returns null and never touches the SVG when showCursor is false (issue #144)', () => {
		const svg = document.createElementNS( SVG_NS, 'svg' ) as SVGSVGElement;
		const result = buildCursorElementsForLifecycle(
			{
				showCursor: false,
				guideOptions: {
					showVerticalGuide: true,
					showHorizontalGuide: true,
				},
			},
			svg,
			buildScale()
		);
		expect( result ).toBeNull();
		// No cursor group inserted under the SVG.
		expect(
			svg.querySelectorAll( 'g.kntnt-gpx-blocks-elevation-cursor' )
		).toHaveLength( 0 );
		// And no child of any kind: an unmounted cursor never grows a
		// dom node.
		expect( svg.children ).toHaveLength( 0 );
	} );

	it( 'returns CursorElements and builds the <g> when showCursor is true', () => {
		const svg = document.createElementNS( SVG_NS, 'svg' ) as SVGSVGElement;
		const result = buildCursorElementsForLifecycle(
			{
				showCursor: true,
				guideOptions: {
					showVerticalGuide: true,
					showHorizontalGuide: false,
				},
			},
			svg,
			buildScale()
		);
		expect( result ).not.toBeNull();
		expect(
			svg.querySelectorAll( 'g.kntnt-gpx-blocks-elevation-cursor' )
		).toHaveLength( 1 );
		expect( result?.verticalGuide ).not.toBeNull();
		expect( result?.horizontalGuide ).toBeNull();
	} );

	it( 'threads the per-guide toggles through to createCursorElements', () => {
		const svg = document.createElementNS( SVG_NS, 'svg' ) as SVGSVGElement;
		const result = buildCursorElementsForLifecycle(
			{
				showCursor: true,
				guideOptions: {
					showVerticalGuide: false,
					showHorizontalGuide: false,
				},
			},
			svg,
			buildScale()
		);
		expect( result ).not.toBeNull();
		expect( result?.verticalGuide ).toBeNull();
		expect( result?.horizontalGuide ).toBeNull();
		// Dot and hit-rect are always created when the cursor is on.
		expect( result?.dot ).toBeDefined();
		expect( result?.hitRect ).toBeDefined();
	} );
} );
