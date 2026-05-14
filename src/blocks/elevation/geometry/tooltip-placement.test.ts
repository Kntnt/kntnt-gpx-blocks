/**
 * Unit tests for {@link computeTooltipPlacement}.
 *
 * Pure math, no DOM. Pins:
 *
 *   - Top-pinned `y` regardless of cursor or previous side.
 *   - Right-side default and the standard right-edge flip threshold.
 *   - 0.5em horizontal hysteresis band: once on the left, the tooltip
 *     does not flip back until the cursor has cleared the boundary by
 *     a full em-multiple.
 *
 * @since 1.0.0
 */
import { computeTooltipPlacement } from './tooltip-placement';

const EM = 16;
const PLOT = { x: 100, y: 50, w: 500, h: 200 };
const BOX = { w: 80, h: 32 };

describe( 'computeTooltipPlacement', () => {
	it( 'pins y to plotRect.y + 0.5em regardless of cursor cx and previousSide', () => {
		// Try a handful of cursor positions and both initial sides; y
		// must be constant.
		const expectedY = PLOT.y + 0.5 * EM;
		for ( const cx of [ 110, 200, 350, 450, 580 ] ) {
			for ( const previousSide of [ null, 'right', 'left' ] as const ) {
				const result = computeTooltipPlacement( {
					cursor: { cx },
					plotRect: PLOT,
					tooltipBox: BOX,
					em: EM,
					previousSide,
				} );
				expect( result.y ).toBe( expectedY );
			}
		}
	} );

	it( 'places the tooltip on the right side of the cursor near plot-left when previousSide is null', () => {
		const cx = 120;
		const result = computeTooltipPlacement( {
			cursor: { cx },
			plotRect: PLOT,
			tooltipBox: BOX,
			em: EM,
			previousSide: null,
		} );
		expect( result.side ).toBe( 'right' );
		expect( result.x ).toBe( cx + 0.5 * EM );
	} );

	it( 'flips to the left side when xRight would clip the plot rectangle (previousSide null)', () => {
		// plotRight - padRight - box.w = 600 - 8 - 80 = 512. Any cx where
		// cx + 0.5em (= 8) > 512 ⇒ cx > 504 triggers a flip.
		const cx = 550;
		const result = computeTooltipPlacement( {
			cursor: { cx },
			plotRect: PLOT,
			tooltipBox: BOX,
			em: EM,
			previousSide: null,
		} );
		expect( result.side ).toBe( 'left' );
		expect( result.x ).toBe( cx - 0.5 * EM - BOX.w );
	} );

	it( 'holds the left side at the threshold when previousSide is already left (hysteresis)', () => {
		// At cx = 504, xRight = 512 = rightOverflowAt → not > boundary,
		// so coming from null the side would be 'right'. But coming from
		// 'left', the hysteresis test requires xRight <= boundary - 0.5em
		// = 504, and 512 > 504, so the tooltip stays on the left.
		const result = computeTooltipPlacement( {
			cursor: { cx: 504 },
			plotRect: PLOT,
			tooltipBox: BOX,
			em: EM,
			previousSide: 'left',
		} );
		expect( result.side ).toBe( 'left' );
	} );

	it( 'stays on the left when cursor is 0.5em - 1px inside the hysteresis band', () => {
		// Boundary on cx is 504; hysteresis band ends at cx = 504 - 8 + 1
		// = 497. A cx of 497 is still inside the band coming from left.
		const result = computeTooltipPlacement( {
			cursor: { cx: 497 },
			plotRect: PLOT,
			tooltipBox: BOX,
			em: EM,
			previousSide: 'left',
		} );
		expect( result.side ).toBe( 'left' );
	} );

	it( 'flips back to the right once the cursor clears the hysteresis band', () => {
		// Hysteresis: xRight <= boundary - 0.5em → cx + 8 <= 504 → cx <= 496.
		const result = computeTooltipPlacement( {
			cursor: { cx: 496 },
			plotRect: PLOT,
			tooltipBox: BOX,
			em: EM,
			previousSide: 'left',
		} );
		expect( result.side ).toBe( 'right' );
		expect( result.x ).toBe( 496 + 0.5 * EM );
	} );

	it( 'returns side: "right" with x = cx + 0.5em on the plot-left edge', () => {
		// Sanity check that the right-side x formula matches the spec.
		const cx = PLOT.x + 5;
		const result = computeTooltipPlacement( {
			cursor: { cx },
			plotRect: PLOT,
			tooltipBox: BOX,
			em: EM,
			previousSide: 'right',
		} );
		expect( result.side ).toBe( 'right' );
		expect( result.x ).toBe( cx + 0.5 * EM );
	} );
} );
