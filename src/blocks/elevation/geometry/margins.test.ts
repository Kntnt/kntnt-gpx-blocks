/**
 * Unit tests for the margin algorithm.
 *
 * Injects a mock measurer so the tests are fully DOM-free. The
 * measurer returns deterministic widths based on the input string's
 * length so margin computations are exactly predictable from the
 * resulting tick label set.
 *
 * Pins the Step 3 contract:
 *
 *   - `wLeft  = widest(niceYLabels).width + 0.5em`
 *   - `wRight = last(niceXLabels).width / 2 + 0.5em`
 *   - `h      = measure(HEIGHT_REFERENCE).height + 0.5em`
 *   - Case B (`min === max`) inflates the Y range to `[min−1, min+1]`.
 *   - `em` is the resolved font-size returned by the measurer.
 *
 * @since 1.0.0
 */

import { computeMargins } from './margins';
import type {
	TextMeasurement,
	TextMeasurer,
	TypographyAttributes,
} from './measure';

/**
 * Builds a measurer that returns predictable widths and a fixed
 * height + font-size. Width = `length × widthPerChar`; height and
 * fontSize are constants.
 *
 * @param widthPerChar Pixels of width per character.
 * @param height       Reported height for every string.
 * @param fontSize     Reported font-size for every string.
 * @return The mock measurer plus a recorded list of every (text,
 *         typography) call made through it.
 */
function makeMeasurer(
	widthPerChar: number,
	height: number,
	fontSize: number
): {
	measure: TextMeasurer;
	calls: Array< { text: string; typography: TypographyAttributes } >;
} {
	const calls: Array< { text: string; typography: TypographyAttributes } > =
		[];
	const measure: TextMeasurer = (
		text: string,
		typography: TypographyAttributes
	): TextMeasurement => {
		calls.push( { text, typography } );
		return {
			width: text.length * widthPerChar,
			height,
			fontSize,
		};
	};
	return { measure, calls };
}

describe( 'computeMargins', () => {
	it( 'applies the wLeft = widestY + 0.5em formula', () => {
		const { measure } = makeMeasurer( 10, 20, 16 );
		const m = computeMargins(
			{ minElevation: 0, maxElevation: 1000, distance: 5000 },
			{},
			measure
		);
		// Y labels: ['0 m', '200 m', '400 m', '600 m', '800 m', '1000 m'].
		// Widest is '1000 m' (6 chars) → 60 px. 0.5em = 8.
		expect( m.wLeft ).toBe( 60 + 8 );
	} );

	it( 'applies the wRight = lastX/2 + 0.5em formula', () => {
		const { measure } = makeMeasurer( 10, 20, 16 );
		const m = computeMargins(
			{ minElevation: 0, maxElevation: 1000, distance: 5000 },
			{},
			measure
		);
		// X labels in km mode: 'X km' for the last entry.
		// Last value formatted as e.g. '5,0 km' (6 chars) → 60 px / 2 + 8 = 38.
		// Compute exact: last X tick from niceTicks(0,5000,5) is 5000 → '5,0 km' (6 chars).
		expect( m.wRight ).toBe( 60 / 2 + 8 );
	} );

	it( 'applies the h = refHeight + 0.5em formula', () => {
		const { measure } = makeMeasurer( 10, 22, 16 );
		const m = computeMargins(
			{ minElevation: 0, maxElevation: 1000, distance: 5000 },
			{},
			measure
		);
		// h = 22 + 0.5 * 16 = 30.
		expect( m.h ).toBe( 22 + 8 );
	} );

	it( 'returns the resolved font-size as em', () => {
		const { measure } = makeMeasurer( 10, 20, 24 );
		const m = computeMargins(
			{ minElevation: 0, maxElevation: 100, distance: 1000 },
			{},
			measure
		);
		expect( m.em ).toBe( 24 );
	} );

	it( 'inflates the Y range by ±1 m when min === max (Case B)', () => {
		const { measure, calls } = makeMeasurer( 10, 20, 16 );
		computeMargins(
			{ minElevation: 250, maxElevation: 250, distance: 5000 },
			{},
			measure
		);
		// Y labels should cover [249, 251]. The widest measured Y label
		// must include 249 or 250 or 251 — never 250 alone — proving
		// the range was inflated.
		const yLabelTexts = calls.map( ( c ) => c.text );
		expect(
			yLabelTexts.some(
				( t ) => t.startsWith( '249' ) || t.startsWith( '251' )
			)
		).toBe( true );
	} );

	it( 'forwards the typography bundle to every measurement', () => {
		const { measure, calls } = makeMeasurer( 10, 20, 16 );
		const typography: TypographyAttributes = {
			fontSize: '14px',
			fontWeight: '700',
		};
		computeMargins(
			{ minElevation: 0, maxElevation: 100, distance: 500 },
			typography,
			measure
		);
		expect( calls.every( ( c ) => c.typography === typography ) ).toBe(
			true
		);
	} );

	it( 'measures the height-reference string', () => {
		const { measure, calls } = makeMeasurer( 10, 20, 16 );
		computeMargins(
			{ minElevation: 0, maxElevation: 100, distance: 500 },
			{},
			measure
		);
		expect( calls.map( ( c ) => c.text ) ).toContain( '-0,123456789' );
	} );
} );
