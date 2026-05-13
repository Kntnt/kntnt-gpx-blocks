/**
 * Unit tests for the margin algorithm.
 *
 * Injects a mock measurer so the tests are fully DOM-free. The
 * measurer returns deterministic widths based on the input string's
 * length so margin computations are exactly predictable from the
 * resulting tick label set.
 *
 * Pins the Step 4 contract:
 *
 *   - `wLeft  = widest(niceYLabels).width + 0.5em`
 *   - `wRight = measure(xReferenceString(distance)).width / 2 + 0.5em`
 *     (driven by the worst-case reference string keyed on `distance`,
 *     not by the actual last X tick label — eliminates the chicken-
 *     and-egg between margins and tick count).
 *   - `wTop   = 0.5 × refHeight + 0.5em` (new in Step 4 — reserves the
 *     upper half of the topmost Y label, which is centred on its tick).
 *   - `h      = measure(HEIGHT_REFERENCE).height + 0.5em`
 *   - Case B (`min === max`) inflates the Y range to `[min−1, min+1]`.
 *   - `em` is the resolved font-size returned by the measurer.
 *
 * @since 1.0.0
 */

import { xReferenceString } from './format';
import { computeMargins } from './margins';
import type { TextMeasurement, TextMeasurer } from './measure';

/**
 * Builds a measurer that returns predictable widths and a fixed
 * height + font-size. Width = `length × widthPerChar`; height and
 * fontSize are constants.
 *
 * @param widthPerChar Pixels of width per character.
 * @param height       Reported height for every string.
 * @param fontSize     Reported font-size for every string.
 * @return The mock measurer plus a recorded list of every text the
 *         caller passed through it.
 */
function makeMeasurer(
	widthPerChar: number,
	height: number,
	fontSize: number
): {
	measure: TextMeasurer;
	calls: string[];
} {
	const calls: string[] = [];
	const measure: TextMeasurer = ( text: string ): TextMeasurement => {
		calls.push( text );
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
			measure
		);
		// Y labels: ['0 m', '200 m', '400 m', '600 m', '800 m', '1000 m'].
		// Widest is '1000 m' (6 chars) → 60 px. 0.5em = 8.
		expect( m.wLeft ).toBe( 60 + 8 );
	} );

	it( 'applies the wRight = refString/2 + 0.5em formula keyed on distance', () => {
		const { measure } = makeMeasurer( 10, 20, 16 );
		const m = computeMargins(
			{ minElevation: 0, maxElevation: 1000, distance: 5000 },
			measure
		);
		// distance=5000 → km-mode refString = '88,8 km' under sv-SE.
		// Length-based mock width = strlen × 10 → 70 / 2 + 8 = 43.
		const refLen = xReferenceString( 5000 ).length;
		expect( m.wRight ).toBe( ( refLen * 10 ) / 2 + 8 );
	} );

	it( 'applies the wTop = 0.5 × refHeight + 0.5em formula', () => {
		const { measure } = makeMeasurer( 10, 22, 16 );
		const m = computeMargins(
			{ minElevation: 0, maxElevation: 1000, distance: 5000 },
			measure
		);
		// 0.5 × 22 + 0.5 × 16 = 11 + 8 = 19.
		expect( m.wTop ).toBe( 11 + 8 );
	} );

	it( 'applies the h = refHeight + 0.5em formula', () => {
		const { measure } = makeMeasurer( 10, 22, 16 );
		const m = computeMargins(
			{ minElevation: 0, maxElevation: 1000, distance: 5000 },
			measure
		);
		// h = 22 + 0.5 * 16 = 30.
		expect( m.h ).toBe( 22 + 8 );
	} );

	it( 'returns the resolved font-size as em', () => {
		const { measure } = makeMeasurer( 10, 20, 24 );
		const m = computeMargins(
			{ minElevation: 0, maxElevation: 100, distance: 1000 },
			measure
		);
		expect( m.em ).toBe( 24 );
	} );

	it( 'inflates the Y range by ±1 m when min === max (Case B)', () => {
		const { measure, calls } = makeMeasurer( 10, 20, 16 );
		computeMargins(
			{ minElevation: 250, maxElevation: 250, distance: 5000 },
			measure
		);
		// Y labels should cover [249, 251]. The widest measured Y label
		// must include 249 or 250 or 251 — never 250 alone — proving
		// the range was inflated.
		expect(
			calls.some(
				( t ) => t.startsWith( '249' ) || t.startsWith( '251' )
			)
		).toBe( true );
	} );

	it( 'measures the height-reference string', () => {
		const { measure, calls } = makeMeasurer( 10, 20, 16 );
		computeMargins(
			{ minElevation: 0, maxElevation: 100, distance: 500 },
			measure
		);
		expect( calls ).toContain( '-0,123456789' );
	} );

	it( 'measures the X reference string for the given distance', () => {
		const { measure, calls } = makeMeasurer( 10, 20, 16 );
		computeMargins(
			{ minElevation: 0, maxElevation: 100, distance: 5000 },
			measure
		);
		// distance=5000 → km-mode refString = '88,8 km' under sv-SE.
		const refString = xReferenceString( 5000 );
		expect( calls ).toContain( refString );
	} );
} );
