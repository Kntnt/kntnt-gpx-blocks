/**
 * Jest tests for the GPX Map wheel classifier.
 *
 * Pure-function tests: every fixture is a plain object literal exercising one
 * branch of `classifyWheel`. The `enableDrag` gating on the `'pan'` branch is
 * the regression coverage for issue #66 (trackpad two-finger scroll panning
 * the map even when "Drag to pan" was disabled).
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

const dragOn: WheelClassifierSettings = { enableDrag: true };
const dragOff: WheelClassifierSettings = { enableDrag: false };

describe( 'classifyWheel', () => {
	describe( 'zoom branch', () => {
		it( 'returns "zoom" when ctrlKey is set, regardless of deltaMode', () => {
			expect( classifyWheel( wheel( { ctrlKey: true } ), dragOn ) ).toBe(
				'zoom'
			);
			expect(
				classifyWheel(
					wheel( { ctrlKey: true, deltaMode: 0 } ),
					dragOn
				)
			).toBe( 'zoom' );
		} );

		it( 'returns "zoom" when metaKey is set, regardless of deltaMode', () => {
			expect( classifyWheel( wheel( { metaKey: true } ), dragOn ) ).toBe(
				'zoom'
			);
			expect(
				classifyWheel(
					wheel( { metaKey: true, deltaMode: 0 } ),
					dragOn
				)
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
	} );

	describe( 'pan branch (trackpad two-finger scroll)', () => {
		it( 'returns "pan" when deltaMode is 0 and no modifier and enableDrag is true', () => {
			expect( classifyWheel( wheel( { deltaMode: 0 } ), dragOn ) ).toBe(
				'pan'
			);
		} );

		it( 'returns "hint" when deltaMode is 0 and no modifier and enableDrag is false (issue #66)', () => {
			expect( classifyWheel( wheel( { deltaMode: 0 } ), dragOff ) ).toBe(
				'hint'
			);
		} );
	} );

	describe( 'hint branch (mouse wheel)', () => {
		it( 'returns "hint" for line-mode wheel deltas without a modifier, regardless of enableDrag', () => {
			expect( classifyWheel( wheel( { deltaMode: 1 } ), dragOn ) ).toBe(
				'hint'
			);
			expect( classifyWheel( wheel( { deltaMode: 1 } ), dragOff ) ).toBe(
				'hint'
			);
		} );

		it( 'returns "hint" for page-mode wheel deltas without a modifier', () => {
			expect( classifyWheel( wheel( { deltaMode: 2 } ), dragOn ) ).toBe(
				'hint'
			);
		} );
	} );
} );
