/**
 * Unit tests for the Elevation block's Color panel (`InspectorColorPanel`)
 * and the {@link hiddenElevationColorAttributes} helper.
 *
 * Pins:
 *
 *   - The nine-row contract from `elevationColorRows` (order matters —
 *     subsequent steps wire into it).
 *   - The issue #143 hide rules: `Tooltip distance` hides when the
 *     `Distance` toggle is off, `Tooltip height` when `Height` is off,
 *     and the shared `Tooltip background` hides only when *both* are
 *     off. Re-enabling a master toggle restores the row with its saved
 *     value (no clear-on-hide).
 *
 * @since 1.0.0
 */

import { createElement, createRoot, flushSync } from '@wordpress/element';

/**
 * Captured `PanelColorSettings` props payload across every render in the
 * current test. The Elevation block's Color panel is a thin wrapper
 * around `PanelColorSettings`, so capturing its props is the cleanest
 * way to assert which rows render in which order with which values.
 *
 * @since 1.0.0
 */
type CapturedColorPanel = {
	title: string;
	enableAlpha?: boolean;
	colorSettings: Array< {
		value: unknown;
		onChange: ( value: string | undefined ) => void;
		label: string;
	} >;
};
const capturedColorPanels: CapturedColorPanel[] = [];

jest.mock(
	'@wordpress/block-editor',
	() => ( {
		__esModule: true,
		PanelColorSettings: ( props: CapturedColorPanel ) => {
			capturedColorPanels.push( {
				title: props.title,
				enableAlpha: props.enableAlpha,
				colorSettings: props.colorSettings,
			} );
			return null;
		},
	} ),
	{ virtual: true }
);

jest.mock(
	'@wordpress/i18n',
	() => ( {
		__esModule: true,
		__: ( s: string ) => s,
	} ),
	{ virtual: true }
);

import {
	InspectorColorPanel,
	elevationColorRows,
	hiddenElevationColorAttributes,
} from './inspector-color';

/**
 * Mounts the panel, captures its emitted `PanelColorSettings` props,
 * and unmounts. The captured array is cleared first so each test sees
 * only its own emissions.
 *
 * @param attributes Saved block attribute bag to feed into the panel.
 *
 * @return Captured props for every `PanelColorSettings` rendered.
 */
function renderAndCapture(
	attributes: Record< string, unknown >
): CapturedColorPanel[] {
	capturedColorPanels.length = 0;
	const container = document.createElement( 'div' );
	const root = createRoot( container );
	flushSync( () => {
		root.render(
			createElement( InspectorColorPanel, {
				attributes,
				setAttributes: () => undefined,
			} )
		);
	} );
	root.unmount();
	return [ ...capturedColorPanels ];
}

describe( 'elevationColorRows', () => {
	it( 'returns the nine rows in the documented display order', () => {
		expect( elevationColorRows().map( ( row ) => row.attribute ) ).toEqual(
			[
				'backgroundColor',
				'plotLineColor',
				'plotFillColor',
				'cursorColor',
				'axisColor',
				'axisLabelColor',
				'tooltipBackgroundColor',
				'tooltipDistanceColor',
				'tooltipHeightColor',
			]
		);
	} );
} );

describe( 'hiddenElevationColorAttributes (issues #143 + #144)', () => {
	it( 'hides nothing when all three master toggles are on', () => {
		expect( hiddenElevationColorAttributes( true, true, true ) ).toEqual(
			new Set()
		);
	} );

	it( 'hides Tooltip distance when Distance is off', () => {
		expect( hiddenElevationColorAttributes( false, true, true ) ).toEqual(
			new Set( [ 'tooltipDistanceColor' ] )
		);
	} );

	it( 'hides Tooltip height when Height is off', () => {
		expect( hiddenElevationColorAttributes( true, false, true ) ).toEqual(
			new Set( [ 'tooltipHeightColor' ] )
		);
	} );

	it( 'hides the shared Tooltip background only when BOTH tooltip toggles are off', () => {
		expect( hiddenElevationColorAttributes( false, false, true ) ).toEqual(
			new Set( [
				'tooltipDistanceColor',
				'tooltipHeightColor',
				'tooltipBackgroundColor',
			] )
		);
	} );

	it( 'hides Cursor AND every tooltip row when showCursor is off (issue #144 + Step 7)', () => {
		// Step 7 extends issue #144's symmetry to the tooltip: with the
		// cursor master off, the tooltip never renders either, so all
		// three tooltip Color rows hide alongside the Cursor row.
		expect( hiddenElevationColorAttributes( true, true, false ) ).toEqual(
			new Set( [
				'cursorColor',
				'tooltipBackgroundColor',
				'tooltipDistanceColor',
				'tooltipHeightColor',
			] )
		);
	} );

	it( 'hides every dependent row when all three master toggles are off', () => {
		expect( hiddenElevationColorAttributes( false, false, false ) ).toEqual(
			new Set( [
				'tooltipDistanceColor',
				'tooltipHeightColor',
				'tooltipBackgroundColor',
				'cursorColor',
			] )
		);
	} );
} );

describe( 'InspectorColorPanel (issue #143)', () => {
	it( 'renders all nine rows when both Tooltip info toggles default-on', () => {
		const panels = renderAndCapture( {} );

		expect( panels ).toHaveLength( 1 );
		expect( panels[ 0 ].colorSettings.map( ( e ) => e.label ) ).toEqual( [
			'Background',
			'Plot line',
			'Plot fill',
			'Cursor',
			'Axis',
			'Axis labels',
			'Tooltip background',
			'Tooltip distance',
			'Tooltip height',
		] );
	} );

	it( 'omits "Tooltip distance" when tooltipShowDistance is false', () => {
		const panels = renderAndCapture( { tooltipShowDistance: false } );

		const labels = panels[ 0 ].colorSettings.map( ( e ) => e.label );
		expect( labels ).not.toContain( 'Tooltip distance' );
		expect( labels ).toContain( 'Tooltip height' );
		expect( labels ).toContain( 'Tooltip background' );
	} );

	it( 'omits "Tooltip height" when tooltipShowHeight is false', () => {
		const panels = renderAndCapture( { tooltipShowHeight: false } );

		const labels = panels[ 0 ].colorSettings.map( ( e ) => e.label );
		expect( labels ).not.toContain( 'Tooltip height' );
		expect( labels ).toContain( 'Tooltip distance' );
		expect( labels ).toContain( 'Tooltip background' );
	} );

	it( 'omits the shared "Tooltip background" only when BOTH toggles are off (edge case)', () => {
		const panels = renderAndCapture( {
			tooltipShowDistance: false,
			tooltipShowHeight: false,
		} );

		const labels = panels[ 0 ].colorSettings.map( ( e ) => e.label );
		expect( labels ).not.toContain( 'Tooltip distance' );
		expect( labels ).not.toContain( 'Tooltip height' );
		expect( labels ).not.toContain( 'Tooltip background' );
		expect( labels ).toContain( 'Cursor' );
		expect( labels ).toContain( 'Plot line' );
	} );

	it( 'omits "Cursor" AND every tooltip row when showCursor is false (issue #144 + Step 7)', () => {
		const panels = renderAndCapture( { showCursor: false } );

		const labels = panels[ 0 ].colorSettings.map( ( e ) => e.label );
		expect( labels ).not.toContain( 'Cursor' );
		// Step 7 extends issue #144's symmetry to the tooltip: with the
		// cursor master off, no tooltip surface renders either, so the
		// three tooltip rows hide alongside the Cursor row.
		expect( labels ).not.toContain( 'Tooltip background' );
		expect( labels ).not.toContain( 'Tooltip distance' );
		expect( labels ).not.toContain( 'Tooltip height' );
		// Non-dependent rows remain.
		expect( labels ).toContain( 'Background' );
		expect( labels ).toContain( 'Plot line' );
		expect( labels ).toContain( 'Axis' );
	} );

	it( 'preserves a saved Cursor colour across hide → re-enable (no clear-on-hide, issue #144)', () => {
		// Hide the row first.
		const hidden = renderAndCapture( {
			showCursor: false,
			cursorColor: '#abcdef',
		} );
		expect(
			hidden[ 0 ].colorSettings.map( ( e ) => e.label )
		).not.toContain( 'Cursor' );

		// Then re-enable — the row reappears with the previously saved value.
		const restored = renderAndCapture( {
			showCursor: true,
			cursorColor: '#abcdef',
		} );
		const cursorRow = restored[ 0 ].colorSettings.find(
			( e ) => e.label === 'Cursor'
		);
		expect( cursorRow ).toBeDefined();
		expect( cursorRow?.value ).toBe( '#abcdef' );
	} );

	it( 'preserves a saved colour value across hide → re-enable (no clear-on-hide)', () => {
		// First render with Distance off — the row is hidden and the
		// underlying attribute value is left in place.
		const hidden = renderAndCapture( {
			tooltipShowDistance: false,
			tooltipDistanceColor: '#112233',
		} );
		expect(
			hidden[ 0 ].colorSettings.map( ( e ) => e.label )
		).not.toContain( 'Tooltip distance' );

		// Then render with Distance back on — the row reappears with the
		// previously saved value.
		const restored = renderAndCapture( {
			tooltipShowDistance: true,
			tooltipDistanceColor: '#112233',
		} );
		const distanceRow = restored[ 0 ].colorSettings.find(
			( e ) => e.label === 'Tooltip distance'
		);
		expect( distanceRow ).toBeDefined();
		expect( distanceRow?.value ).toBe( '#112233' );
	} );
} );
