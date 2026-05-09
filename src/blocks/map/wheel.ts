/**
 * Pure wheel-event classification for the GPX Map view.
 *
 * Imported by `view.ts` for production use and by `wheel.test.ts` for Jest
 * coverage. Nothing in this module touches the DOM or Leaflet, so it can be
 * exercised without a browser environment — the same shape as `geometry.ts`.
 *
 * @since 0.4.4
 */

/**
 * Settings consulted by the classifier. A subset of the full `MapSettings`
 * shape on purpose: the classifier only needs the toggles that actually gate
 * a wheel modality.
 *
 * @since 0.4.4
 */
export interface WheelClassifierSettings {
	/** Whether drag-to-pan is enabled. Gates the `'pan'` branch. */
	readonly enableDrag: boolean;
}

/**
 * The minimal wheel-event surface the classifier reads. Defined here rather
 * than depending on `lib.dom`'s `WheelEvent` so the function can be unit-
 * tested with plain object literals.
 *
 * @since 0.4.4
 */
export interface ClassifiableWheelEvent {
	readonly ctrlKey: boolean;
	readonly metaKey: boolean;
	readonly deltaMode: number;
}

/**
 * The action a wheel event should trigger on the map.
 *
 * @since 0.4.4
 */
export type WheelAction = 'zoom' | 'pan' | 'hint';

/**
 * Classify a wheel event into the action it should trigger on the map.
 *
 * On macOS, trackpad pinch gestures are delivered as wheel events with
 * `ctrlKey: true` regardless of whether Ctrl is physically pressed; the same
 * shortcut is used by mouse-wheel zoom on every other platform. Trackpad
 * two-finger pan delivers wheel events with `deltaMode === 0` (pixel deltas)
 * and no modifier. Mouse wheels deliver `deltaMode === 1` (line deltas) on
 * most browsers; even when they emit pixel deltas, the per-tick delta is
 * coarse and not paired with the small horizontal pans a trackpad emits.
 *
 * Pan is gated on `settings.enableDrag` so that disabling drag-to-pan in the
 * block sidebar honours the visitor-facing promise across input modalities:
 * mouse drag, single-touch drag, and trackpad two-finger pan all stop moving
 * the map when the toggle is off. The would-be pan instead falls through to
 * `'hint'`, which lets the page scroll and surfaces the same modifier-key
 * overlay shown for plain mouse wheel.
 *
 * @since 0.4.4
 *
 * @param event    - The wheel event (or any object exposing the same surface).
 * @param settings - Map interaction settings. Only `enableDrag` is consulted.
 * @return One of `'zoom'`, `'pan'`, or `'hint'`.
 */
export function classifyWheel(
	event: ClassifiableWheelEvent,
	settings: WheelClassifierSettings
): WheelAction {
	if ( event.ctrlKey || event.metaKey ) {
		return 'zoom';
	}
	if ( event.deltaMode === 0 ) {
		return settings.enableDrag ? 'pan' : 'hint';
	}
	return 'hint';
}
