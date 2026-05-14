/**
 * Bootstrap helpers for the elevation cursor lifecycle.
 *
 * The Interactivity `initElevation` callback in `view.ts` runs through
 * several side-effecting stages — locating the wrapper element, reading
 * state, mounting the SVG, computing margins, attaching observers — and
 * delegates the cursor-related decisions to the pure helpers in this
 * module. Issue #144 added the `Cursor & guides` Inspector panel; this
 * file holds the two decision points it surfaces in the view layer:
 *
 *   - {@link buildCursorElementsForLifecycle} — instantiate the cursor
 *     `<g>` only when the editor enabled the master toggle, otherwise
 *     return `null` and never touch the SVG.
 *   - {@link readCursorSettingsFromContext} — read the three Cursor &
 *     guides booleans out of the per-block Interactivity context with
 *     the documented defaults (`showCursor` on, `showVerticalGuide` on,
 *     `showHorizontalGuide` off).
 *
 * Splitting these decisions out of the asynchronous mount pipeline
 * keeps them unit-testable without spinning up the Interactivity API,
 * `document.fonts.ready`, ResizeObserver, or the SVG measurer.
 *
 * @since 1.0.0
 */

import {
	createCursorElements,
	type CursorElements,
	type CursorGuideOptions,
} from './cursor';
import type { ChartScale } from './geometry/scale';

/**
 * The three `Cursor & guides` booleans (issue #144) as resolved from
 * the per-block Interactivity context. The master toggle is exposed
 * separately from the two per-guide toggles so the caller can short-
 * circuit the whole cursor lifecycle when `showCursor` is off.
 *
 * @since 1.0.0
 */
export interface CursorSettings {
	readonly showCursor: boolean;
	readonly guideOptions: CursorGuideOptions;
}

/**
 * The shape of the Interactivity context this module reads. The fields
 * are individually optional because the context may legitimately
 * arrive empty (an Elevation block that has not yet been re-rendered
 * against the latest `block.json` schema).
 *
 * @since 1.0.0
 */
export interface CursorContextShape {
	readonly showCursor?: boolean;
	readonly showVerticalGuide?: boolean;
	readonly showHorizontalGuide?: boolean;
}

/**
 * Reads the three Cursor & guides booleans from the per-block
 * Interactivity context with the documented defaults: `showCursor` on,
 * `showVerticalGuide` on, `showHorizontalGuide` off. The defaults
 * mirror `block.json` so a context bag produced by a fresh-insert
 * block (no boolean fields) resolves to the same end-state the editor
 * preview shows.
 *
 * Exposed as a pure helper so `view.ts`'s `initElevation` and
 * `onElevationCursorChange` can both consume it without duplicating
 * the defaulting logic.
 *
 * @since 1.0.0
 *
 * @param ctx The per-block Interactivity context, possibly empty.
 * @return The resolved Cursor & guides settings.
 */
export function readCursorSettingsFromContext(
	ctx: CursorContextShape | undefined
): CursorSettings {
	const showCursor =
		typeof ctx?.showCursor === 'boolean' ? ctx.showCursor : true;
	const guideOptions: CursorGuideOptions = {
		showVerticalGuide:
			typeof ctx?.showVerticalGuide === 'boolean'
				? ctx.showVerticalGuide
				: true,
		showHorizontalGuide:
			typeof ctx?.showHorizontalGuide === 'boolean'
				? ctx.showHorizontalGuide
				: false,
	};
	return { showCursor, guideOptions };
}

/**
 * Returns the cursor element references when the editor enabled the
 * `Cursor` master toggle, otherwise `null`. A `null` return means the
 * cursor `<g>` is not appended to the SVG at all — see
 * `view.ts`'s lifecycle branch for the downstream consequences (no
 * pointer handlers, watch callback no-ops, fraction writes left to
 * the Map block alone).
 *
 * @since 1.0.0
 *
 * @param settings The resolved Cursor & guides settings.
 * @param svg      The chart's SVG host.
 * @param scale    Current {@link ChartScale}.
 * @return The cursor element references when the master toggle is on,
 *         otherwise `null`.
 */
export function buildCursorElementsForLifecycle(
	settings: CursorSettings,
	svg: SVGSVGElement,
	scale: ChartScale
): CursorElements | null {
	if ( ! settings.showCursor ) {
		return null;
	}
	return createCursorElements( svg, scale, settings.guideOptions );
}
