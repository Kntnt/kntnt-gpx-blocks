/**
 * Unit tests for {@link computeTooltipLayout}.
 *
 * Pins the Step 7 pl.2 contract: each visible row's bbox top is
 * `padY` from the relevant rect edge regardless of glyph composition,
 * which means digit-only labels no longer drift downward inside the
 * rect on Chrome/Safari (where `getBBox` reports a bbox that reaches
 * the font's descent extent even when no descender glyphs are drawn).
 *
 * @since 1.0.0
 */
import { computeTooltipLayout } from './tooltip-layout';
import type { TextMeasurement } from './measure';

/**
 * Convenience factory for a {@link TextMeasurement} stub.
 *
 * Defaults model a typical sans-serif row: 16 px of ascent (the bbox
 * top sits 16 px above the baseline) and a 20 px total height (so 4
 * px of phantom descender extent below the baseline). Width is
 * parameterised so the rect-width test can drive the `Math.max`
 * branch deterministically.
 *
 * @since 1.0.0
 *
 * @param overrides Optional field overrides.
 * @return A {@link TextMeasurement} suitable for layout tests.
 */
function makeMeasurement(
	overrides: Partial< TextMeasurement > = {}
): TextMeasurement {
	return {
		width: 50,
		height: 20,
		topOffset: -16,
		fontSize: 16,
		...overrides,
	};
}

describe( 'computeTooltipLayout', () => {
	const em = 16;
	const padY = 0.5 * em;
	const padX = 0.5 * em;
	const lineGap = 0.25 * em;

	describe( 'two rows visible', () => {
		it( 'sizes the rect to fit both rows plus padding and line gap', () => {
			const layout = computeTooltipLayout( {
				placementX: 100,
				placementY: 200,
				em,
				distance: makeMeasurement( { width: 60 } ),
				height: makeMeasurement( { width: 40 } ),
			} );
			// width = max(60, 40) + 2 * padX
			expect( layout.rectWidth ).toBe( 60 + 2 * padX );
			// height = 20 + 20 + lineGap + 2 * padY
			expect( layout.rectHeight ).toBe( 20 + 20 + lineGap + 2 * padY );
			expect( layout.distanceVisible ).toBe( true );
			expect( layout.heightVisible ).toBe( true );
		} );

		it( 'positions row 1 bbox top at padY and row 2 bbox bottom at rectHeight - padY', () => {
			const placementY = 200;
			const distance = makeMeasurement();
			const height = makeMeasurement();
			const layout = computeTooltipLayout( {
				placementX: 100,
				placementY,
				em,
				distance,
				height,
			} );
			// Row 1 bbox top = textY + topOffset, should equal placementY + padY.
			expect( layout.distanceTextY + distance.topOffset ).toBe(
				placementY + padY
			);
			// Row 2 bbox bottom = textY + topOffset + height, should
			// equal placementY + rectHeight - padY.
			expect(
				layout.heightTextY + height.topOffset + height.height
			).toBe( placementY + layout.rectHeight - padY );
		} );

		it( 'separates row 1 bbox bottom from row 2 bbox top by lineGap', () => {
			const distance = makeMeasurement();
			const height = makeMeasurement();
			const layout = computeTooltipLayout( {
				placementX: 0,
				placementY: 0,
				em,
				distance,
				height,
			} );
			const row1Bottom =
				layout.distanceTextY + distance.topOffset + distance.height;
			const row2Top = layout.heightTextY + height.topOffset;
			expect( row2Top - row1Bottom ).toBe( lineGap );
		} );

		it( 'aligns both rows to the same left edge at padX from the rect', () => {
			const layout = computeTooltipLayout( {
				placementX: 100,
				placementY: 0,
				em,
				distance: makeMeasurement(),
				height: makeMeasurement(),
			} );
			expect( layout.distanceTextX ).toBe( 100 + padX );
			expect( layout.heightTextX ).toBe( 100 + padX );
		} );
	} );

	describe( 'distance row only', () => {
		it( 'leaves equal padding above and below the bbox (the pl.2 fix)', () => {
			const placementY = 200;
			const distance = makeMeasurement();
			const layout = computeTooltipLayout( {
				placementX: 0,
				placementY,
				em,
				distance,
				height: null,
			} );
			const bboxTop = layout.distanceTextY + distance.topOffset;
			const bboxBottom = bboxTop + distance.height;
			const rectTop = placementY;
			const rectBottom = placementY + layout.rectHeight;
			expect( bboxTop - rectTop ).toBe( padY );
			expect( rectBottom - bboxBottom ).toBe( padY );
		} );

		it( 'sizes the rect from the distance row only', () => {
			const layout = computeTooltipLayout( {
				placementX: 0,
				placementY: 0,
				em,
				distance: makeMeasurement( { width: 80, height: 20 } ),
				height: null,
			} );
			expect( layout.rectWidth ).toBe( 80 + 2 * padX );
			expect( layout.rectHeight ).toBe( 20 + 2 * padY );
			expect( layout.distanceVisible ).toBe( true );
			expect( layout.heightVisible ).toBe( false );
		} );

		it( 'leaves the height row position at zero so callers can ignore it', () => {
			const layout = computeTooltipLayout( {
				placementX: 0,
				placementY: 0,
				em,
				distance: makeMeasurement(),
				height: null,
			} );
			expect( layout.heightTextY ).toBe( 0 );
			expect( layout.heightTextX ).toBe( 0 );
		} );
	} );

	describe( 'height row only', () => {
		it( 'leaves equal padding above and below the bbox', () => {
			const placementY = 200;
			const height = makeMeasurement();
			const layout = computeTooltipLayout( {
				placementX: 0,
				placementY,
				em,
				distance: null,
				height,
			} );
			const bboxTop = layout.heightTextY + height.topOffset;
			const bboxBottom = bboxTop + height.height;
			const rectTop = placementY;
			const rectBottom = placementY + layout.rectHeight;
			expect( bboxTop - rectTop ).toBe( padY );
			expect( rectBottom - bboxBottom ).toBe( padY );
		} );

		it( 'sizes the rect from the height row only', () => {
			const layout = computeTooltipLayout( {
				placementX: 0,
				placementY: 0,
				em,
				distance: null,
				height: makeMeasurement( { width: 30, height: 20 } ),
			} );
			expect( layout.rectWidth ).toBe( 30 + 2 * padX );
			expect( layout.rectHeight ).toBe( 20 + 2 * padY );
			expect( layout.distanceVisible ).toBe( false );
			expect( layout.heightVisible ).toBe( true );
		} );

		it( 'leaves the distance row position at zero so callers can ignore it', () => {
			const layout = computeTooltipLayout( {
				placementX: 0,
				placementY: 0,
				em,
				distance: null,
				height: makeMeasurement(),
			} );
			expect( layout.distanceTextY ).toBe( 0 );
			expect( layout.distanceTextX ).toBe( 0 );
		} );
	} );

	describe( 'neither row visible', () => {
		it( 'collapses the rect to padding only and zeroes both text positions', () => {
			const layout = computeTooltipLayout( {
				placementX: 100,
				placementY: 200,
				em,
				distance: null,
				height: null,
			} );
			expect( layout.rectWidth ).toBe( 2 * padX );
			expect( layout.rectHeight ).toBe( 2 * padY );
			expect( layout.distanceTextY ).toBe( 0 );
			expect( layout.heightTextY ).toBe( 0 );
			expect( layout.distanceVisible ).toBe( false );
			expect( layout.heightVisible ).toBe( false );
		} );
	} );
} );
