/**
 * Mount-time helpers for the GPX Elevation block's frontend module.
 *
 * `initElevation` orchestrates four phases: locate the server-rendered DOM
 * nodes, derive the SVG-space chart bounds from the viewBox, snapshot the
 * data series from the per-map state slice, and lazily attach the pointer
 * handlers under an IntersectionObserver. This file owns the first phase
 * (querying the DOM) and the lazy pointer-handler binding. The viewBox →
 * `ChartBounds` derivation and the state snapshot are trivial enough to
 * stay inline in `view.ts`.
 *
 * @since 1.0.0
 */

import { clamp } from './cursor';

/**
 * Subset of `ElevationEntry` returned by `findCursorElements`. The full
 * `ElevationEntry` adds the data snapshot and the chart bounds, both of
 * which `view.ts` derives next to the call site.
 *
 * @since 1.0.0
 */
export interface CursorElements {
	readonly svg: SVGSVGElement;
	readonly cursorLine: SVGLineElement;
	readonly cursorOverlay: HTMLElement;
	readonly cursorDot: HTMLElement;
	readonly tooltip: HTMLElement;
	readonly tooltipDistance: HTMLElement;
	readonly tooltipElevation: HTMLElement;
}

/**
 * Resolve every DOM node the Elevation block's view module mutates.
 *
 * Returns `null` when any required element is missing — the caller treats
 * that as "bail mount entirely" rather than surfacing a partial entry that
 * later pointer / watch callbacks would have to defend against. The HTML
 * cursor overlays sit alongside the SVG (issue #136) and are queried from
 * the wrapper so the layout's non-uniform stretch on the SVG does not
 * affect their lookup.
 *
 * @since 1.0.0
 *
 * @param wrapper - The block wrapper element passed by the Interactivity API.
 * @return All cursor / SVG elements, or `null` when any required node is missing.
 */
export function findCursorElements(
	wrapper: HTMLElement
): CursorElements | null {
	const svg = wrapper.querySelector< SVGSVGElement >( 'svg' );
	if ( ! svg ) {
		return null;
	}
	const cursorLine = svg.querySelector< SVGLineElement >(
		'.kntnt-gpx-blocks-elevation-cursor-line'
	);
	if ( ! cursorLine ) {
		return null;
	}

	const cursorOverlay = wrapper.querySelector< HTMLElement >(
		'.kntnt-gpx-blocks-elevation-cursor'
	);
	const cursorDot = wrapper.querySelector< HTMLElement >(
		'.kntnt-gpx-blocks-elevation-cursor-dot'
	);
	const tooltip = wrapper.querySelector< HTMLElement >(
		'.kntnt-gpx-blocks-elevation-cursor-tooltip'
	);
	const tooltipDistance = wrapper.querySelector< HTMLElement >(
		'.kntnt-gpx-blocks-elevation-cursor-tooltip-distance'
	);
	const tooltipElevation = wrapper.querySelector< HTMLElement >(
		'.kntnt-gpx-blocks-elevation-cursor-tooltip-elevation'
	);

	if (
		! cursorOverlay ||
		! cursorDot ||
		! tooltip ||
		! tooltipDistance ||
		! tooltipElevation
	) {
		return null;
	}

	return {
		svg,
		cursorLine,
		cursorOverlay,
		cursorDot,
		tooltip,
		tooltipDistance,
		tooltipElevation,
	};
}

/**
 * Convert a client-space pointer x-coordinate to a fraction [0, 1] relative
 * to the SVG chart's plot area.
 *
 * Uses the SVG element's bounding rect to map client pixels to the plot
 * rectangle's horizontal range. Under the wrapper-as-image layout
 * (issue #135) the SVG fills the plot rectangle exactly, so the SVG's
 * bounding rect *is* the plot rectangle in CSS pixels.
 *
 * @since 1.0.0
 *
 * @param clientX - Pointer x in client (viewport) pixels.
 * @param svg     - The SVG element.
 * @return Fraction in [0, 1], already clamped.
 */
export function clientXToFraction(
	clientX: number,
	svg: SVGSVGElement
): number {
	const rect = svg.getBoundingClientRect();
	if ( rect.width === 0 ) {
		return 0;
	}
	return clamp( ( clientX - rect.left ) / rect.width, 0, 1 );
}

/**
 * Sink interface for fraction writes performed by the pointer handlers.
 *
 * `bindPointerHandlers` is decoupled from the Interactivity store so the
 * dependency on `state[mapId]` stays at the `view.ts` call site and the
 * helper itself is straightforward to test or reuse from a different
 * mount surface.
 *
 * @since 1.0.0
 */
export interface FractionSink {
	/** Write a fraction value. `null` is used for "pointer left". */
	setFraction: ( value: number | null ) => void;
}

/**
 * Wire native Pointer Events on the SVG plus a `pointerleave` on the
 * wrapper.
 *
 * `pointerdown` claims the gesture as a scrub: it sets the fraction
 * immediately (so a tap pins the cursor), calls `setPointerCapture` so a
 * drag keeps updating the fraction even when the finger drifts off the
 * SVG, and stays on through `pointermove` until `pointerup` or
 * `pointercancel` ends the gesture. Mouse hover updates the fraction on
 * every `pointermove` because the SVG receives the event while the mouse
 * is over the chart; touch has no hover so the `mouse` branch is a no-op
 * for touch.
 *
 * `pointerleave` on the wrapper nulls the fraction so the cursor
 * disappears when the mouse leaves the block. Skipped while scrubbing so
 * brief excursions out of the block during a fast mouse scrub do not
 * flicker the cursor off; skipped on touch entirely so a finger lift does
 * not dismiss the cursor before the user can read it.
 *
 * `touchmove` is a belt-and-suspenders against browsers (and touch
 * emulators like Firefox responsive design mode) where `touch-action:
 * none` on SVG elements is partially honoured. While scrubbing, every
 * touchmove on the SVG is preventDefault'd so the page does not scroll.
 *
 * @since 1.0.0
 *
 * @param svg     - The chart's SVG element.
 * @param wrapper - The block wrapper element — the `pointerleave` target.
 * @param sink    - Sink receiving fraction writes.
 */
export function bindPointerHandlers(
	svg: SVGSVGElement,
	wrapper: HTMLElement,
	sink: FractionSink
): void {
	let scrubbing = false;

	svg.addEventListener( 'pointerdown', ( event: PointerEvent ) => {
		// Ignore secondary pointers during an active scrub so a stray
		// second finger cannot hijack capture mid-gesture.
		if ( scrubbing ) {
			return;
		}

		event.preventDefault();
		scrubbing = true;
		svg.setPointerCapture( event.pointerId );
		sink.setFraction( clientXToFraction( event.clientX, svg ) );
	} );

	svg.addEventListener( 'pointermove', ( event: PointerEvent ) => {
		// Capture during a scrub keeps this firing even when the pointer
		// is off the SVG; for plain mouse hover the event also fires when
		// the mouse is over the chart. Touch has no hover, so the `mouse`
		// branch is a no-op for touch.
		if ( scrubbing || event.pointerType === 'mouse' ) {
			sink.setFraction( clientXToFraction( event.clientX, svg ) );
		}
	} );

	const endScrub = ( event: PointerEvent ) => {
		if ( ! scrubbing ) {
			return;
		}
		scrubbing = false;
		if ( svg.hasPointerCapture( event.pointerId ) ) {
			svg.releasePointerCapture( event.pointerId );
		}
	};

	svg.addEventListener( 'pointerup', endScrub );
	svg.addEventListener( 'pointercancel', endScrub );

	// Belt-and-suspenders against browsers (and touch emulators like
	// Firefox responsive design mode) where `touch-action: none` on SVG
	// elements is partially honoured. While scrubbing, every touchmove on
	// the SVG is preventDefault'd to guarantee the page does not scroll.
	// `passive: false` is required for preventDefault to take effect.
	svg.addEventListener(
		'touchmove',
		( event: TouchEvent ) => {
			if ( scrubbing ) {
				event.preventDefault();
			}
		},
		{ passive: false }
	);

	// Mouse leaves the block — null the fraction so the cursor disappears.
	// Skip while scrubbing so brief excursions out of the block during a
	// fast mouse scrub do not flicker the cursor off. Skip on touch
	// entirely: a finger lift fires `pointerleave` automatically (the
	// touch pointer ceases), and we want the cursor to stay at its last
	// position so the user can read it (and the corresponding map cursor)
	// after pointing.
	wrapper.addEventListener( 'pointerleave', ( ( event: PointerEvent ) => {
		if ( scrubbing || event.pointerType === 'touch' ) {
			return;
		}
		sink.setFraction( null );
	} ) as EventListener );
}

/**
 * Defer `bindPointerHandlers` until the chart is in (or near) the
 * viewport. This mirrors the Map block's IntersectionObserver pattern:
 * heavy DOM listeners are attached lazily, but the cursor-sync watch is
 * already live because the mount entry is set synchronously by the caller.
 *
 * Falls back to immediate binding when the IntersectionObserver API is
 * unavailable so the block is still interactive on older browsers.
 *
 * @since 1.0.0
 *
 * @param target - Element observed for visibility.
 * @param bind   - Callback to invoke once the target intersects the viewport.
 */
export function bindPointerHandlersWhenVisible(
	target: Element,
	bind: () => void
): void {
	if ( typeof IntersectionObserver === 'undefined' ) {
		bind();
		return;
	}

	const observer = new IntersectionObserver(
		( entries, obs ) => {
			if ( ! entries[ 0 ]?.isIntersecting ) {
				return;
			}

			// Disconnect after first intersection — one-shot pattern.
			obs.disconnect();
			bind();
		},
		// rootMargin pre-triggers 200 px before the SVG enters view,
		// matching the Map block's lazy-mount margin.
		{ rootMargin: '200px 0px', threshold: 0 }
	);
	observer.observe( target );
}
