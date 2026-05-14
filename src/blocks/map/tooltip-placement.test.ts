/**
 * Jest tests for the GPX Map waypoint-tooltip placement helpers.
 *
 * Two pure functions live in `tooltip-placement.ts`: one picks the vertical
 * direction ('top' or 'bottom') so the tooltip never clips against the map
 * container's top edge, and one returns the horizontal offset that nudges
 * the tooltip inward when its left or right edge would otherwise leave the
 * container. The tests exercise the behaviour through the public API only,
 * with concrete numeric fixtures that read like a specification.
 *
 * @since 0.13.5
 */

import {
	chooseTooltipDirection,
	computeTooltipHorizontalOffset,
	measureTooltipBox,
} from './tooltip-placement';

describe( 'chooseTooltipDirection', () => {
	it( 'returns "top" when there is room above the marker', () => {
		// 200 px marker Y, 60 px tooltip, 8 px gap, 8 px padding. Tooltip top
		// edge sits at 200 − 8 − 60 = 132 px, well clear of the 8 px padding.
		expect(
			chooseTooltipDirection( {
				markerY: 200,
				tooltipHeight: 60,
				paddingPx: 8,
				leafletGap: 8,
			} )
		).toBe( 'top' );
	} );

	it( 'returns "bottom" when the marker is too close to the top edge', () => {
		// 30 px marker Y, 60 px tooltip, 8 px gap, 8 px padding. Tooltip top
		// edge would sit at 30 − 8 − 60 = −38 px — clipped well above the
		// container's top edge, so the helper flips the direction.
		expect(
			chooseTooltipDirection( {
				markerY: 30,
				tooltipHeight: 60,
				paddingPx: 8,
				leafletGap: 8,
			} )
		).toBe( 'bottom' );
	} );

	it( 'keeps "top" when the tooltip top sits exactly at the padding line', () => {
		// 76 px marker Y, 60 px tooltip, 8 px gap, 8 px padding. Tooltip top
		// edge would sit at 76 − 8 − 60 = 8 px, exactly equal to the padding
		// minimum. The boundary is inclusive — no flip.
		expect(
			chooseTooltipDirection( {
				markerY: 76,
				tooltipHeight: 60,
				paddingPx: 8,
				leafletGap: 8,
			} )
		).toBe( 'top' );
	} );
} );

describe( 'computeTooltipHorizontalOffset', () => {
	it( 'returns 0 when the centred tooltip fits inside the padding', () => {
		// 200 px marker X, 100 px tooltip width, 500 px container, 8 px
		// padding. Centred tooltip spans [150, 250] — well within [8, 492].
		expect(
			computeTooltipHorizontalOffset( {
				markerX: 200,
				tooltipWidth: 100,
				containerWidth: 500,
				paddingPx: 8,
			} )
		).toBe( 0 );
	} );

	it( 'shifts right when the marker is near the left edge', () => {
		// 20 px marker X, 100 px tooltip width, 500 px container, 8 px
		// padding. A centred tooltip would span [−30, 70] — its left edge
		// sits 38 px before the padding line at 8 px, so the helper shifts
		// the tooltip right by 38 px so the left edge lands exactly at 8 px.
		expect(
			computeTooltipHorizontalOffset( {
				markerX: 20,
				tooltipWidth: 100,
				containerWidth: 500,
				paddingPx: 8,
			} )
		).toBe( 38 );
	} );

	it( 'shifts left when the marker is near the right edge', () => {
		// 480 px marker X, 100 px tooltip width, 500 px container, 8 px
		// padding. A centred tooltip would span [430, 530] — its right edge
		// sits 38 px past the padding line at 492 px, so the helper shifts
		// the tooltip left by 38 px so the right edge lands exactly at 492 px.
		expect(
			computeTooltipHorizontalOffset( {
				markerX: 480,
				tooltipWidth: 100,
				containerWidth: 500,
				paddingPx: 8,
			} )
		).toBe( -38 );
	} );
} );

describe( 'measureTooltipBox', () => {
	it( 'returns numeric width and height', () => {
		const pane = document.createElement( 'div' );
		document.body.appendChild( pane );
		const tooltipEl = document.createElement( 'div' );
		tooltipEl.textContent = 'Sample';

		const box = measureTooltipBox( tooltipEl, pane );

		expect( typeof box.width ).toBe( 'number' );
		expect( typeof box.height ).toBe( 'number' );

		document.body.removeChild( pane );
	} );

	it( 'leaves the tooltip pane empty after measuring', () => {
		const pane = document.createElement( 'div' );
		document.body.appendChild( pane );
		const tooltipEl = document.createElement( 'div' );
		tooltipEl.textContent = 'Sample';

		measureTooltipBox( tooltipEl, pane );

		expect( pane.children.length ).toBe( 0 );

		document.body.removeChild( pane );
	} );

	it( 'does not mutate the original tooltip element', () => {
		const pane = document.createElement( 'div' );
		document.body.appendChild( pane );
		const tooltipEl = document.createElement( 'div' );
		tooltipEl.textContent = 'Sample';

		measureTooltipBox( tooltipEl, pane );

		expect( tooltipEl.parentElement ).toBeNull();
		expect( tooltipEl.textContent ).toBe( 'Sample' );

		document.body.removeChild( pane );
	} );
} );
