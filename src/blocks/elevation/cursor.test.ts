/**
 * Jest tests for the GPX Elevation cursor-update helpers (issue #136).
 *
 * The pure math (`samplePositionPercent`, `formatDistance`,
 * `formatElevation`) is exercised with plain literals. The DOM-mutation
 * surface (`applyCursorPosition`, `hideCursor`, `showCursor`) is tested
 * against jsdom-backed elements built via `document.createElementNS` /
 * `document.createElement` so the test confirms the post-#136 contract:
 * the cursor LINE inside the SVG gets `x1` / `x2` writes; the HTML
 * overlays get `style.left` / `style.top` writes; the cursor wrapper's
 * `style.display` toggles in lock-step with the line's.
 *
 * @since 1.0.0
 */

import {
	applyCursorPosition,
	clamp,
	formatDistance,
	formatElevation,
	hideCursor,
	samplePositionPercent,
	showCursor,
	type CursorOverlayElements,
} from './cursor';

const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * Build a synthetic `CursorOverlayElements` bundle inside a fresh document.
 *
 * The line is created in the SVG namespace so `setAttribute` writes
 * resemble what `view.ts` does at runtime; the dot, tooltip, and rows
 * are plain HTML elements appended to a wrapping `<div>` so jsdom can
 * compute `clientWidth` / `offsetWidth` against synthetic dimensions.
 *
 * @return The created elements plus the wrapping container.
 */
function buildElements(): CursorOverlayElements & {
	container: HTMLElement;
} {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );

	const svg = document.createElementNS( SVG_NS, 'svg' );
	const cursorLine = document.createElementNS(
		SVG_NS,
		'line'
	) as SVGLineElement;
	svg.appendChild( cursorLine );
	container.appendChild( svg );

	const cursorOverlay = document.createElement( 'div' );
	const cursorDot = document.createElement( 'div' );
	const tooltip = document.createElement( 'div' );
	const tooltipDistance = document.createElement( 'div' );
	const tooltipElevation = document.createElement( 'div' );
	tooltip.appendChild( tooltipDistance );
	tooltip.appendChild( tooltipElevation );
	cursorOverlay.appendChild( cursorDot );
	cursorOverlay.appendChild( tooltip );
	container.appendChild( cursorOverlay );

	return {
		cursorLine,
		cursorOverlay,
		cursorDot,
		tooltip,
		tooltipDistance,
		tooltipElevation,
		container,
	};
}

describe( 'clamp', () => {
	it( 'returns the value when in range', () => {
		expect( clamp( 5, 0, 10 ) ).toBe( 5 );
	} );

	it( 'snaps to the lower bound when the value is below', () => {
		expect( clamp( -3, 0, 10 ) ).toBe( 0 );
	} );

	it( 'snaps to the upper bound when the value is above', () => {
		expect( clamp( 99, 0, 10 ) ).toBe( 10 );
	} );
} );

describe( 'samplePositionPercent', () => {
	it( 'maps the start sample to (0%, top of plot)', () => {
		const out = samplePositionPercent( 0, 200, 100, 100, 200 );
		expect( out.fxPct ).toBeCloseTo( 0, 10 );
		// Elevation == yMax → top of plot (0% from top).
		expect( out.fyPct ).toBeCloseTo( 0, 10 );
	} );

	it( 'maps the end sample to (100%, bottom of plot)', () => {
		const out = samplePositionPercent( 100, 100, 100, 100, 200 );
		expect( out.fxPct ).toBeCloseTo( 100, 10 );
		// Elevation == yMin → bottom of plot (100% from top).
		expect( out.fyPct ).toBeCloseTo( 100, 10 );
	} );

	it( 'maps a midpoint sample to (50%, 50%)', () => {
		const out = samplePositionPercent( 50, 150, 100, 100, 200 );
		expect( out.fxPct ).toBeCloseTo( 50, 10 );
		expect( out.fyPct ).toBeCloseTo( 50, 10 );
	} );

	it( 'snaps fxPct to 0% for a zero totalDistance', () => {
		const out = samplePositionPercent( 50, 150, 0, 100, 200 );
		expect( out.fxPct ).toBe( 0 );
	} );

	it( 'snaps fyPct to 100% for a flat y range', () => {
		const out = samplePositionPercent( 50, 150, 100, 150, 150 );
		expect( out.fyPct ).toBe( 100 );
	} );

	it( 'clamps an out-of-range distance to 0..100', () => {
		const below = samplePositionPercent( -10, 150, 100, 100, 200 );
		expect( below.fxPct ).toBe( 0 );
		const above = samplePositionPercent( 200, 150, 100, 100, 200 );
		expect( above.fxPct ).toBe( 100 );
	} );
} );

describe( 'formatDistance', () => {
	it( 'renders metres for sub-1000 m values', () => {
		expect( formatDistance( 245 ) ).toBe( '245 m' );
		expect( formatDistance( 0 ) ).toBe( '0 m' );
		expect( formatDistance( 999.4 ) ).toBe( '999 m' );
	} );

	it( 'switches to kilometres at the 1000 m threshold', () => {
		expect( formatDistance( 1000 ) ).toBe( '1.0 km' );
		expect( formatDistance( 3200 ) ).toBe( '3.2 km' );
		expect( formatDistance( 5500 ) ).toBe( '5.5 km' );
	} );
} );

describe( 'formatElevation', () => {
	it( 'rounds to the nearest whole metre', () => {
		expect( formatElevation( 245.4 ) ).toBe( '245 m' );
		expect( formatElevation( 245.7 ) ).toBe( '246 m' );
		expect( formatElevation( 0 ) ).toBe( '0 m' );
	} );
} );

describe( 'hideCursor / showCursor', () => {
	it( 'hideCursor sets display: none on both the overlay wrapper and the line', () => {
		const els = buildElements();
		hideCursor( els );
		expect( els.cursorOverlay.style.display ).toBe( 'none' );
		expect( els.cursorLine.style.display ).toBe( 'none' );
	} );

	it( 'showCursor clears display: none on both elements', () => {
		const els = buildElements();
		hideCursor( els );
		showCursor( els );
		expect( els.cursorOverlay.style.display ).toBe( '' );
		expect( els.cursorLine.style.display ).toBe( '' );
	} );

	it( 'showCursor is idempotent on already-visible elements', () => {
		const els = buildElements();
		// Set a non-`none` value first so we can confirm it's preserved.
		els.cursorOverlay.style.display = 'block';
		showCursor( els );
		expect( els.cursorOverlay.style.display ).toBe( 'block' );
	} );
} );

describe( 'applyCursorPosition (issue #136 — HTML overlays)', () => {
	it( 'writes x1 / x2 attributes on the SVG cursor line', () => {
		const els = buildElements();
		applyCursorPosition( els, {
			cx: 600,
			fxPct: 50,
			fyPct: 30,
			distanceLabel: '3.2 km',
			elevationLabel: '145 m',
		} );
		expect( els.cursorLine.getAttribute( 'x1' ) ).toBe( '600' );
		expect( els.cursorLine.getAttribute( 'x2' ) ).toBe( '600' );
	} );

	it( 'writes style.left / style.top percentages on the HTML cursor dot', () => {
		const els = buildElements();
		applyCursorPosition( els, {
			cx: 600,
			fxPct: 50,
			fyPct: 30,
			distanceLabel: '3.2 km',
			elevationLabel: '145 m',
		} );
		expect( els.cursorDot.style.left ).toBe( '50%' );
		expect( els.cursorDot.style.top ).toBe( '30%' );
	} );

	it( 'writes the formatted labels into the two tooltip row elements', () => {
		const els = buildElements();
		applyCursorPosition( els, {
			cx: 600,
			fxPct: 50,
			fyPct: 30,
			distanceLabel: '3.2 km',
			elevationLabel: '145 m',
		} );
		expect( els.tooltipDistance.textContent ).toBe( '3.2 km' );
		expect( els.tooltipElevation.textContent ).toBe( '145 m' );
	} );

	it( 'falls back to plain percentage on the tooltip when offsetWidth is 0', () => {
		// jsdom defaults to clientWidth / offsetWidth = 0 for elements that
		// have no layout — exactly the case `applyCursorPosition` falls back
		// for. The tooltip should land at the same left as the dot.
		const els = buildElements();
		applyCursorPosition( els, {
			cx: 600,
			fxPct: 75,
			fyPct: 30,
			distanceLabel: '3.2 km',
			elevationLabel: '145 m',
		} );
		expect( els.tooltip.style.left ).toBe( '75%' );
		// jsdom normalises bare `'0'` to `'0px'` on the style object; both
		// resolve to the same computed value, so accept either form.
		expect( [ '0', '0px' ] ).toContain( els.tooltip.style.top );
	} );

	it( 'clamps the tooltip inside the overlay container when measurable', () => {
		const els = buildElements();
		// Stub clientWidth / offsetWidth so the clamp branch fires. The
		// overlay is 200 px wide; the tooltip is 80 px wide; with fxPct =
		// 95% the centre would be at 190 px, leaving the right edge at 230
		// px — overflowing. The clamp should pull centre back to 160 px so
		// the right edge sits exactly at the overlay's right edge.
		Object.defineProperty( els.cursorOverlay, 'clientWidth', {
			configurable: true,
			value: 200,
		} );
		Object.defineProperty( els.tooltip, 'offsetWidth', {
			configurable: true,
			value: 80,
		} );

		applyCursorPosition( els, {
			cx: 1140,
			fxPct: 95,
			fyPct: 30,
			distanceLabel: '3.2 km',
			elevationLabel: '145 m',
		} );

		// Clamped centre = 160 px → 80% of 200 px.
		expect( els.tooltip.style.left ).toBe( '80%' );
	} );

	it( 'does not rewrite textContent when the label is unchanged (avoids spurious DOM churn)', () => {
		const els = buildElements();
		applyCursorPosition( els, {
			cx: 600,
			fxPct: 50,
			fyPct: 30,
			distanceLabel: '3.2 km',
			elevationLabel: '145 m',
		} );

		// Spy on subsequent writes by overriding the setter so we know whether
		// it's called a second time.
		let setterCalls = 0;
		const originalDistanceNode = els.tooltipDistance;
		Object.defineProperty( originalDistanceNode, 'textContent', {
			configurable: true,
			get() {
				return '3.2 km';
			},
			set() {
				setterCalls += 1;
			},
		} );

		applyCursorPosition( els, {
			cx: 600,
			fxPct: 50,
			fyPct: 30,
			distanceLabel: '3.2 km',
			elevationLabel: '146 m',
		} );

		// Distance label unchanged → no setter invocation; only the elevation
		// changed so the elevation row is written.
		expect( setterCalls ).toBe( 0 );
	} );
} );
