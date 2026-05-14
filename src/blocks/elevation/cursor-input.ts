/**
 * Pointer-input layer for the elevation chart's cursor.
 *
 * Step 6 of `docs/elevation-rebuild.md` defines the pointer protocol on
 * the chart's hit-rect. This module owns the implementation.
 *
 * The matrix:
 *
 * | Event           | Target     | Condition                                       | Action                                                                          |
 * |-----------------|------------|-------------------------------------------------|---------------------------------------------------------------------------------|
 * | `pointerdown`   | hit-rect   | `! scrubbing`                                   | preventDefault + setPointerCapture + scrubbing = true + setFraction.             |
 * | `pointermove`   | hit-rect   | `scrubbing \|\| pointerType === 'mouse'`        | setFraction.                                                                    |
 * | `pointerup`     | hit-rect   | `scrubbing`                                     | releasePointerCapture + scrubbing = false. **No fraction write.**                |
 * | `pointercancel` | hit-rect   | `scrubbing`                                     | Same as `pointerup`.                                                            |
 * | `pointerleave`  | wrapper    | `! scrubbing && pointerType !== 'touch'`        | setFraction(null) — cursor disappears.                                          |
 *
 * The wrapper-level `pointerleave` (rather than a hit-rect-level one)
 * means moving the mouse from the plot rectangle into a label margin
 * does not flicker the cursor off — only crossing the block boundary
 * dismisses it. Touch never fires `pointerleave` mid-drag with a sane
 * UX, and finger-lift's automatic `pointerleave` is explicitly
 * suppressed via the `pointerType !== 'touch'` guard so the cursor
 * persists for the user to read.
 *
 * The {@link bindPointerHandlersWhenVisible} wrapper defers binding
 * until the chart approaches the viewport (200 px lead margin, matching
 * Map's lazy-mount). The cursor-sync watch is *not* gated by the
 * observer — it activates as soon as the entry is published in
 * `mountedElevations` so cross-block cursor sync works even before the
 * Elevation block is in view.
 *
 * @since 1.0.0
 */

/**
 * Sink the pointer handlers write to. Decouples this module from the
 * Interactivity store so the unit tests can plug in a recorder.
 *
 * @since 1.0.0
 */
export interface FractionSink {
	setFraction: ( value: number | null ) => void;
}

/**
 * Maps a pointer's `clientX` to a `[ 0, 1 ]` fraction relative to the
 * supplied hit-rect. The mapping is rect-relative — pointer x outside
 * the rect simply clamps, and the formula has no internal `plotLeft`
 * arithmetic because the geometry is baked into the rect's CSS-pixel
 * bounding-rect.
 *
 * Returns `0` for a zero-width rect (the transient pre-layout state on
 * the very first frame after mount) so any pointer event that arrives
 * during that one frame leaves the cursor at the start of the track
 * rather than crashing the watch's projection math.
 *
 * @since 1.0.0
 *
 * @param clientX Pointer x in viewport CSS pixels.
 * @param hitRect The cursor hit-rect.
 * @return The fraction in `[ 0, 1 ]`.
 */
export function clientXToFraction(
	clientX: number,
	hitRect: SVGRectElement
): number {
	const rect = hitRect.getBoundingClientRect();
	if ( rect.width === 0 ) {
		return 0;
	}
	const raw = ( clientX - rect.left ) / rect.width;
	return Math.max( 0, Math.min( 1, raw ) );
}

/**
 * Wires the pointer matrix above onto the supplied hit-rect and
 * wrapper. The handlers are attached as ordinary listeners (no
 * AbortController integration); the chart's lifecycle never tears them
 * down because the SVG and the wrapper are persistent across redraws.
 *
 * @since 1.0.0
 *
 * @param hitRect The cursor hit-rect inside the chart SVG.
 * @param wrapper The block wrapper element. Used as the `pointerleave`
 *                target so margin-area moves do not flicker the cursor
 *                off.
 * @param sink    Where to write fraction values.
 */
export function bindPointerHandlers(
	hitRect: SVGRectElement,
	wrapper: HTMLElement,
	sink: FractionSink
): void {
	let scrubbing = false;
	let scrubPointerId: number | null = null;

	// Press: open a scrub iff none is active. setPointerCapture keeps
	// subsequent moves coming to this rect even when the pointer drifts
	// off; preventDefault stops touch events from also firing legacy
	// mouse synthesis.
	hitRect.addEventListener( 'pointerdown', ( event: PointerEvent ): void => {
		if ( scrubbing ) {
			return;
		}
		event.preventDefault();
		try {
			hitRect.setPointerCapture( event.pointerId );
		} catch {
			// jsdom and ancient browsers can throw here — the gesture
			// degrades to a normal pointermove sequence without capture.
		}
		scrubbing = true;
		scrubPointerId = event.pointerId;
		sink.setFraction( clientXToFraction( event.clientX, hitRect ) );
	} );

	// Move: update on every event during a scrub, plus the
	// hover-without-press branch that gives mouse users continuous
	// feedback. Touch never fires a hover move because there is no
	// pointer floating between gestures.
	hitRect.addEventListener( 'pointermove', ( event: PointerEvent ): void => {
		if ( ! scrubbing && event.pointerType !== 'mouse' ) {
			return;
		}
		sink.setFraction( clientXToFraction( event.clientX, hitRect ) );
	} );

	// Release: end the scrub. The cursor stays at its last position so
	// the user can read the value (essential when Step 7's tooltip
	// lands on top).
	const endScrub = ( event: PointerEvent ): void => {
		if ( ! scrubbing ) {
			return;
		}
		try {
			if ( scrubPointerId !== null ) {
				hitRect.releasePointerCapture( scrubPointerId );
			}
		} catch {
			// Releasing a non-held capture throws on some runtimes — the
			// scrub has already ended, so there is nothing to recover.
		}
		scrubbing = false;
		scrubPointerId = null;
		// Reference event to satisfy noUnusedParameters under the
		// shared listener signature without affecting behaviour.
		void event;
	};
	hitRect.addEventListener( 'pointerup', endScrub );
	hitRect.addEventListener( 'pointercancel', endScrub );

	// Leave: dismiss the cursor when the user moves out of the wrapper.
	// Skipped during a scrub (the user is mid-drag) and on touch (the
	// pointer has just lifted; the cursor should persist for reading).
	wrapper.addEventListener( 'pointerleave', ( event: PointerEvent ): void => {
		if ( scrubbing ) {
			return;
		}
		if ( event.pointerType === 'touch' ) {
			return;
		}
		sink.setFraction( null );
	} );
}

/**
 * Defers `bind()` until the supplied target enters the viewport.
 *
 * Mirrors Map's lazy-mount margin (200 px lead) so the pointer
 * listeners attach only when the user is about to interact with the
 * chart. Falls back to invoking `bind()` immediately on environments
 * where `IntersectionObserver` is unavailable (older runtimes,
 * happy-dom variants).
 *
 * @since 1.0.0
 *
 * @param target The block wrapper element.
 * @param bind   The deferred binding callback.
 */
export function bindPointerHandlersWhenVisible(
	target: Element,
	bind: () => void
): void {
	if ( typeof IntersectionObserver === 'undefined' ) {
		bind();
		return;
	}

	let done = false;
	const observer = new IntersectionObserver(
		( entries ): void => {
			if ( done ) {
				return;
			}
			const entry = entries[ 0 ];
			if ( ! entry?.isIntersecting ) {
				return;
			}
			done = true;
			observer.disconnect();
			bind();
		},
		{ rootMargin: '200px 0px' }
	);
	observer.observe( target );
}
