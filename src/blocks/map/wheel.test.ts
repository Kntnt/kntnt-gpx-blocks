/**
 * Jest tests for the GPX Map wheel classifier.
 *
 * Pure-function tests: every fixture is a plain object literal exercising one
 * branch of `classifyWheel`. The `enableDrag` gating on the `'pan'` branch is
 * the regression coverage for issue #66 (trackpad two-finger scroll panning
 * the map even when "Drag to pan" was disabled). The `enableScrollWheelZoom`
 * gating on the `'zoom'` branch is the coverage for issue #139 (Cmd/Ctrl +
 * scroll and trackpad pinch still zooming the map even when every other
 * interaction toggle was off).
 *
 * @since 0.4.4
 */

import {
	classifyWheel,
	type ClassifiableWheelEvent,
	type WheelClassifierSettings,
} from './wheel';

/**
 * Build a wheel-event fixture with sensible defaults; tests override only the
 * fields that matter for the branch under test.
 *
 * @param overrides - Fields that diverge from the defaults.
 * @return A `ClassifiableWheelEvent` with the overrides merged in.
 */
function wheel(
	overrides: Partial< ClassifiableWheelEvent > = {}
): ClassifiableWheelEvent {
	return {
		ctrlKey: false,
		metaKey: false,
		deltaMode: 1,
		...overrides,
	};
}

// Default-on settings — every interaction enabled. The four named slices below
// each turn exactly one knob off so individual branches read cleanly.
const allOn: WheelClassifierSettings = {
	enableDrag: true,
	enableScrollWheelZoom: true,
};
const dragOff: WheelClassifierSettings = {
	enableDrag: false,
	enableScrollWheelZoom: true,
};
const zoomOff: WheelClassifierSettings = {
	enableDrag: true,
	enableScrollWheelZoom: false,
};
const dragAndZoomOff: WheelClassifierSettings = {
	enableDrag: false,
	enableScrollWheelZoom: false,
};

describe( 'classifyWheel', () => {
	describe( 'zoom branch', () => {
		it( 'returns "zoom" when ctrlKey is set, regardless of deltaMode', () => {
			expect( classifyWheel( wheel( { ctrlKey: true } ), allOn ) ).toBe(
				'zoom'
			);
			expect(
				classifyWheel( wheel( { ctrlKey: true, deltaMode: 0 } ), allOn )
			).toBe( 'zoom' );
		} );

		it( 'returns "zoom" when metaKey is set, regardless of deltaMode', () => {
			expect( classifyWheel( wheel( { metaKey: true } ), allOn ) ).toBe(
				'zoom'
			);
			expect(
				classifyWheel( wheel( { metaKey: true, deltaMode: 0 } ), allOn )
			).toBe( 'zoom' );
		} );

		it( 'returns "zoom" even when enableDrag is false — pinch-zoom is unaffected', () => {
			expect(
				classifyWheel(
					wheel( { ctrlKey: true, deltaMode: 0 } ),
					dragOff
				)
			).toBe( 'zoom' );
			expect(
				classifyWheel(
					wheel( { metaKey: true, deltaMode: 0 } ),
					dragOff
				)
			).toBe( 'zoom' );
		} );

		// Issue #139 — toggling scroll-wheel zoom off must suppress every
		// wheel-driven zoom branch: the Cmd/Ctrl + wheel modifier path on a
		// mouse, and the trackpad-pinch gesture browsers deliver as a wheel
		// event with `ctrlKey: true` (often paired with a very small deltaY).
		it( 'returns "hint" for Cmd+wheel when enableScrollWheelZoom is false (issue #139)', () => {
			expect( classifyWheel( wheel( { metaKey: true } ), zoomOff ) ).toBe(
				'hint'
			);
			expect(
				classifyWheel(
					wheel( { metaKey: true, deltaMode: 0 } ),
					zoomOff
				)
			).toBe( 'hint' );
		} );

		it( 'returns "hint" for Ctrl+wheel mouse zoom when enableScrollWheelZoom is false (issue #139)', () => {
			// Modifier-key wheel on a non-Apple mouse delivers `deltaMode === 1`
			// (line) on Firefox and `deltaMode === 0` (pixel) on Chromium — the
			// classifier must reject both because the modifier alone determines
			// the zoom intent, not the deltaMode.
			expect( classifyWheel( wheel( { ctrlKey: true } ), zoomOff ) ).toBe(
				'hint'
			);
			expect(
				classifyWheel(
					wheel( { ctrlKey: true, deltaMode: 0 } ),
					zoomOff
				)
			).toBe( 'hint' );
		} );

		it( 'returns "hint" for trackpad-pinch (ctrlKey + tiny deltaY pixels) when enableScrollWheelZoom is false (issue #139)', () => {
			// Trackpad-pinch on macOS arrives as a wheel event with
			// `ctrlKey: true` regardless of physical key state and pixel
			// deltas (`deltaMode === 0`). The classifier branches on
			// modifier-or-pinch first, so the pinch must reject too when
			// scroll-wheel zoom is off.
			expect(
				classifyWheel(
					wheel( { ctrlKey: true, deltaMode: 0 } ),
					zoomOff
				)
			).toBe( 'hint' );
		} );
	} );

	describe( 'pan branch (trackpad two-finger scroll)', () => {
		it( 'returns "pan" when deltaMode is 0 and no modifier and enableDrag is true', () => {
			expect( classifyWheel( wheel( { deltaMode: 0 } ), allOn ) ).toBe(
				'pan'
			);
		} );

		it( 'returns "hint" when deltaMode is 0 and no modifier and enableDrag is false (issue #66)', () => {
			expect( classifyWheel( wheel( { deltaMode: 0 } ), dragOff ) ).toBe(
				'hint'
			);
		} );

		// Issue #139 cross-check — the pan branch must keep its own gate. With
		// scroll-wheel zoom off but drag-to-pan on, an unmodified trackpad
		// two-finger pan still pans the map; the two toggles are independent.
		it( 'still returns "pan" when enableScrollWheelZoom is false but enableDrag is true (issue #139)', () => {
			expect( classifyWheel( wheel( { deltaMode: 0 } ), zoomOff ) ).toBe(
				'pan'
			);
		} );

		// Issue #139 cross-check — when both toggles are off, the wheel
		// handler is fully passive: pan branch falls back to hint just like
		// the zoom branch.
		it( 'returns "hint" when both enableDrag and enableScrollWheelZoom are false', () => {
			expect(
				classifyWheel( wheel( { deltaMode: 0 } ), dragAndZoomOff )
			).toBe( 'hint' );
		} );
	} );

	describe( 'hint branch (mouse wheel)', () => {
		it( 'returns "hint" for line-mode wheel deltas without a modifier, regardless of enableDrag', () => {
			expect( classifyWheel( wheel( { deltaMode: 1 } ), allOn ) ).toBe(
				'hint'
			);
			expect( classifyWheel( wheel( { deltaMode: 1 } ), dragOff ) ).toBe(
				'hint'
			);
		} );

		it( 'returns "hint" for page-mode wheel deltas without a modifier', () => {
			expect( classifyWheel( wheel( { deltaMode: 2 } ), allOn ) ).toBe(
				'hint'
			);
		} );

		// Issue #139 — an unmodified mouse wheel (the case the hint overlay
		// was designed for) still classifies as `'hint'` when scroll-wheel
		// zoom is off. The classifier's contract is unchanged for this
		// branch; suppression of the actual overlay rendering is the
		// view-side concern of `attachWheelHandler`, documented in
		// `view.ts`.
		it( 'returns "hint" for unmodified mouse wheel when enableScrollWheelZoom is false (issue #139)', () => {
			expect( classifyWheel( wheel( { deltaMode: 1 } ), zoomOff ) ).toBe(
				'hint'
			);
			expect( classifyWheel( wheel( { deltaMode: 2 } ), zoomOff ) ).toBe(
				'hint'
			);
		} );
	} );
} );
