/**
 * Keyboard filter for the GPX Map view.
 *
 * Leaflet's keyboard handler attaches its `keydown` listener in bubble phase
 * and reads four key groups from a single object: arrow keys for panning, and
 * the `+` / `-` / `=` keys for zooming. The Map block exposes those two key
 * groups as the two result-named toggles `Pan` and `Zoom` in the Inspector,
 * so this module owns the per-result gating that Leaflet itself cannot
 * express without splitting its handler.
 *
 * The filter is registered on the map container in capture phase and calls
 * `event.stopPropagation()` for the keys that fall under a disabled result.
 * Capture-phase wins over Leaflet's bubble-phase listener reliably, so a
 * stopped event never reaches Leaflet's own handler — the unaffected key
 * group continues to work normally. The keyboard handler itself is still
 * enabled on the map (`map.keyboard.enable()`) whenever either result is on
 * so the map remains focusable for the at-least-one-enabled key group.
 *
 * Registered alongside `attachWheelHandler` in the mount flow and removed
 * via the same `AbortSignal` so the cleanup contract is mechanical.
 *
 * @since 0.13.5
 */

/**
 * Settings consulted by the key filter. A subset of the full `MapSettings`
 * shape on purpose: the filter only needs the two result-named toggles that
 * govern keyboard interaction.
 *
 * @since 0.13.5
 */
export interface KeyFilterSettings {
	/** Whether the Pan result is enabled. Gates the arrow keys. */
	readonly enablePan: boolean;
	/** Whether the Zoom result is enabled. Gates `+` / `-` / `=`. */
	readonly enableZoom: boolean;
}

/**
 * Arrow keys are gated by the Pan result. The set covers every value
 * `KeyboardEvent.key` produces for the four arrow keys across browsers.
 *
 * @since 0.13.5
 */
const PAN_KEYS = new Set( [
	'ArrowUp',
	'ArrowDown',
	'ArrowLeft',
	'ArrowRight',
] );

/**
 * Zoom keys are gated by the Zoom result. The set covers the three keys
 * Leaflet's keyboard handler reads for zoom in/out, including `=` because
 * `+` shares the same physical key on US-layout keyboards and the unshifted
 * value is what `KeyboardEvent.key` reports.
 *
 * @since 0.13.5
 */
const ZOOM_KEYS = new Set( [ '+', '-', '=' ] );

/**
 * Attach a capture-phase `keydown` listener that swallows the keys whose
 * owning result is disabled, before Leaflet's own bubble-phase handler runs.
 *
 * `stopPropagation()` is the right tool here rather than `preventDefault()`:
 * the goal is to suppress Leaflet's handler without changing the browser's
 * own default for the key (the page-scroll behaviour for arrow keys, the
 * zoom shortcut for `+` / `-` in some browsers). A wrapper around the map
 * with its own intent for arrow keys (e.g. a slideshow) keeps working
 * because the event never reaches Leaflet but still bubbles to handlers
 * registered after this one is invoked.
 *
 * Idempotent on a per-signal basis: registering the same listener twice on
 * the same container with the same signal is a no-op (the browser
 * deduplicates by callback identity). Removing happens automatically when
 * the signal aborts; no explicit `removeEventListener` is required.
 *
 * @since 0.13.5
 *
 * @param container - The Leaflet map's wrapper element.
 * @param settings  - The two result-named toggles.
 * @param signal    - AbortSignal that releases the listener on tear-down.
 */
export function attachKeyFilter(
	container: HTMLElement,
	settings: KeyFilterSettings,
	signal: AbortSignal
): void {
	container.addEventListener(
		'keydown',
		( event: KeyboardEvent ) => {
			if ( ! settings.enablePan && PAN_KEYS.has( event.key ) ) {
				event.stopPropagation();
				return;
			}
			if ( ! settings.enableZoom && ZOOM_KEYS.has( event.key ) ) {
				event.stopPropagation();
			}
		},
		{ capture: true, signal }
	);
}
