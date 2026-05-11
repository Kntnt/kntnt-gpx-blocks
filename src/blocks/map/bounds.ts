/**
 * Pure helpers for deriving the GPX Map's `maxBounds` from the track bbox.
 *
 * Imported by `view.ts` and `editor-preview.tsx` for production use and by
 * `bounds.test.ts` for Jest coverage. Nothing in this module touches Leaflet,
 * so the helper can be exercised without instantiating a real map.
 *
 * The motivation is documented under issue #110: today the user can pan the
 * map arbitrarily far from the GPX track, which costs tile-server bandwidth
 * for no visible benefit. Constraining `maxBounds` to the track bbox plus a
 * margin keeps at least some portion of the track inside the viewport at all
 * times. With `maxBoundsViscosity: 1.0` on the Leaflet map options the pan is
 * rigid; a viscosity of 1.0 means the user cannot drag the centre past the
 * computed corners.
 *
 * Edge cases handled here:
 *
 * - **Degenerate bbox** (single track point or a single LineString vertex):
 *   the south-west and north-east corners coincide. A zero-span bbox would
 *   produce a zero-area `maxBounds` that traps the viewport at one point.
 *   The minimum-span fallback (`MIN_SPAN_DEGREES`) inflates the box to a
 *   small but usable area before applying the fractional padding.
 *
 * - **Very large bbox** (continental track): the fractional padding scales
 *   with the bbox itself, so a 1000-km track still gets a sensible margin
 *   rather than a fixed kilometre count that disappears at that scale.
 *
 * - **Antimeridian crossing**: Leaflet's bounds-normalisation already handles
 *   the wrap, and the longitudes passed in here come from `layer.getBounds()`,
 *   which performs that normalisation. The helper itself does no wrap-aware
 *   arithmetic — it expands a planar rectangle in degree space, which is
 *   correct for every non-antimeridian-crossing track and sufficient for the
 *   "at least part of the track stays visible" contract on the rare crossing
 *   case (the constraint loosens but does not break).
 *
 * @since 1.0.0
 */

/**
 * A `[lat, lng]` tuple in WGS84 decimal degrees.
 *
 * Same shape `geometry.ts` exports; duplicated locally so this module remains
 * self-contained and free of Leaflet types.
 *
 * @since 1.0.0
 */
export type LatLng = readonly [ number, number ];

/**
 * Axis-aligned geographic bounding box.
 *
 * `southWest` is the minimum-lat, minimum-lng corner; `northEast` is the
 * maximum-lat, maximum-lng corner. The shape mirrors Leaflet's
 * `LatLngBounds.getSouthWest()` / `getNorthEast()` accessor pair so a caller
 * can construct one from `bounds.getSouthWest().lat`, etc., without pulling
 * Leaflet types into this module.
 *
 * @since 1.0.0
 */
export interface BoundingBox {
	readonly southWest: LatLng;
	readonly northEast: LatLng;
}

/**
 * Default padding fraction applied around the track bbox when computing
 * `maxBounds`.
 *
 * A value of `0.5` expands each side by 50 % of the original bbox span on
 * that axis. With `maxBoundsViscosity: 1.0` this lets the user pan roughly
 * one-half of a track-length past the visible edge in every direction — far
 * enough to feel unconstrained for normal exploration, while ensuring at
 * least the opposite half of the track always remains in view.
 *
 * @since 1.0.0
 */
export const DEFAULT_PADDING_FRACTION = 0.5;

/**
 * Minimum span (in degrees) applied to a degenerate bbox before padding.
 *
 * A track reduced to a single point would otherwise produce a zero-area
 * `maxBounds` that traps the viewport at that point. `0.01°` is roughly
 * 1 km at the equator and shorter at higher latitudes — small enough that
 * the inflation is visually negligible for any real track, large enough
 * that a degenerate single-point track still allows the user to pan and
 * zoom around the marker. Applied independently to the lat and lng spans.
 *
 * @since 1.0.0
 */
export const MIN_SPAN_DEGREES = 0.01;

/**
 * Compute a padded `maxBounds` rectangle from a track's raw bounding box.
 *
 * The padding is fractional: each side is expanded by `paddingFraction × span`
 * on its axis. A degenerate bbox (zero span on one or both axes) is first
 * inflated to `MIN_SPAN_DEGREES` so the subsequent fractional padding does
 * not collapse to zero. Returns `null` only when the input is structurally
 * unusable (non-finite coordinates), so callers can fall back to leaving
 * `maxBounds` unset and let Leaflet permit unconstrained panning.
 *
 * Latitudes are clamped to `[-90, 90]` after padding because Leaflet treats
 * values outside that range as projection errors. Longitudes are *not*
 * clamped — Leaflet wraps them across the antimeridian and that wrap is
 * harmless for `maxBounds`.
 *
 * @since 1.0.0
 *
 * @param bbox            - Track bounding box from `layer.getBounds()`.
 * @param paddingFraction - Fraction of bbox span to add on each side.
 *                        Defaults to `DEFAULT_PADDING_FRACTION`.
 * @return Padded bounding box, or `null` when the input is unusable.
 */
export function paddedBoundsFromBox(
	bbox: BoundingBox,
	paddingFraction: number = DEFAULT_PADDING_FRACTION
): BoundingBox | null {
	const [ minLat, minLng ] = bbox.southWest;
	const [ maxLat, maxLng ] = bbox.northEast;

	// Reject structurally bad input. `NaN` and `Infinity` would otherwise
	// propagate through the arithmetic and yield a `maxBounds` Leaflet
	// silently rejects. Returning `null` lets the caller skip the call.
	if (
		! Number.isFinite( minLat ) ||
		! Number.isFinite( minLng ) ||
		! Number.isFinite( maxLat ) ||
		! Number.isFinite( maxLng )
	) {
		return null;
	}

	// Inflate a degenerate bbox to a small but non-zero span so the fractional
	// padding below has something to grow from. A single-point track or a
	// track collapsed onto a single meridian/parallel falls into this branch.
	const latSpan = Math.max( maxLat - minLat, MIN_SPAN_DEGREES );
	const lngSpan = Math.max( maxLng - minLng, MIN_SPAN_DEGREES );

	// Centre the inflated span on the original midpoint so the resulting
	// padded box is symmetric around the track, regardless of whether the
	// bbox needed inflation.
	const latMid = ( minLat + maxLat ) / 2;
	const lngMid = ( minLng + maxLng ) / 2;
	const halfLat = latSpan / 2;
	const halfLng = lngSpan / 2;

	// Apply the fractional padding to each side.
	const padLat = latSpan * paddingFraction;
	const padLng = lngSpan * paddingFraction;

	// Latitudes are physical and clamp to [-90, 90]; longitudes wrap, so
	// no clamp is applied there.
	const south = Math.max( latMid - halfLat - padLat, -90 );
	const north = Math.min( latMid + halfLat + padLat, 90 );
	const west = lngMid - halfLng - padLng;
	const east = lngMid + halfLng + padLng;

	return {
		southWest: [ south, west ],
		northEast: [ north, east ],
	};
}

/**
 * Decides whether the map's post-`fitBounds` center is usable as input to
 * `setMaxBounds`.
 *
 * Leaflet's `setMaxBounds` internally calls `_panInsideMaxBounds`, which
 * unprojects the current center and projects it back inside the constraint
 * box. When `fitBounds` runs against a 0-width or 0-height container (some
 * flex/grid parents defer the wrapper's definite width past the
 * `IntersectionObserver` callback that triggers mount), the scale math goes
 * to `-Infinity` and the resulting center is `(NaN, NaN)`. Passing a
 * `setMaxBounds` call through with that center trips an unproject step
 * that throws "Invalid LatLng object: (NaN, NaN)" (issue #116).
 *
 * The check is intentionally narrow: it accepts any finite latitude and
 * longitude, including out-of-range values that the caller has not yet
 * normalised, because the caller's only use of the result is "should I
 * skip the constraint?". A latitude of 95° is geometrically wrong but
 * doesn't crash Leaflet; only NaN/Infinity does.
 *
 * Prefer `canApplyMaxBounds` at the call site — issue #117 widened the
 * guard to cover the parallel `_zoom = NaN` failure where `getCenter()`
 * is finite but `getZoom()` is not, which still crashes `_panInsideMaxBounds`.
 * This narrower predicate is retained as the building block of the new
 * one and for any future caller that genuinely cares only about the
 * center.
 *
 * @since 1.0.0
 *
 * @param center     - Map center as `{ lat, lng }` (Leaflet's `getCenter()`
 *                   shape).
 * @param center.lat - Latitude in WGS84 decimal degrees.
 * @param center.lng - Longitude in WGS84 decimal degrees.
 * @return `true` when both components are finite numbers.
 */
export function isCenterUsableForMaxBounds( center: {
	readonly lat: number;
	readonly lng: number;
} ): boolean {
	return Number.isFinite( center.lat ) && Number.isFinite( center.lng );
}

/**
 * The narrow Leaflet surface `applyMaxBoundsIfSafe` consults.
 *
 * Declaring a structural type rather than importing `L.Map` keeps this
 * module free of the Leaflet types in tests where Leaflet is not
 * installed. Production callers pass a real `L.Map` and the structural
 * match holds.
 *
 * @since 1.0.0
 */
export interface MapForMaxBounds {
	/** Leaflet's `getCenter()` — returns the current map center. */
	readonly getCenter: () => { readonly lat: number; readonly lng: number };
	/** Leaflet's `getZoom()` — returns the current zoom level. */
	readonly getZoom: () => number;
	/** Leaflet's `setMaxBounds()` — sets the rigid pan constraint. */
	readonly setMaxBounds: ( bounds: readonly [ LatLng, LatLng ] ) => void;
}

/**
 * Decides whether the map is in a state where `setMaxBounds` will not
 * throw.
 *
 * Issue #117 widens the v0.11.3 guard. The original v0.11.3 check
 * (`isCenterUsableForMaxBounds`) only looked at the center; that closes
 * the path where `getCenter()` reads as `(NaN, NaN)` but leaves the
 * parallel path where the center is finite while `_zoom` is `NaN` (the
 * `getScaleZoom(-Infinity, …)` branch in Leaflet's internals). Both
 * paths are reachable when `fitBounds` runs against a 0-size container
 * and both crash `setMaxBounds` → `_panInsideMaxBounds` for the same
 * reason: an internal unproject step against non-finite input throws
 * "Invalid LatLng object: (NaN, NaN)".
 *
 * This predicate accepts only when both the center and the zoom are
 * finite numbers. Out-of-range (but finite) values pass — Leaflet
 * handles those without crashing; only `NaN` and `±Infinity` are the
 * pathology this gate exists to catch. In a normal page lifecycle the
 * predicate returns `true` on every fitBounds call; the false branch
 * is reached only when the wrapper had zero pixel width or height at
 * the moment Leaflet ran the fit math, which is itself the bug
 * `Dimensions_Defaults` exists to prevent. The two work as belt and
 * braces — the attribute-side fix prevents the bad state from arising,
 * this predicate keeps `setMaxBounds` from crashing if something else
 * (a third-party CSS rule, a future block-supports interaction) gets
 * the wrapper to zero size anyway.
 *
 * @since 1.0.0
 *
 * @param center     - Map center as `{ lat, lng }` (Leaflet's `getCenter()`
 *                   shape).
 * @param center.lat - Latitude in WGS84 decimal degrees.
 * @param center.lng - Longitude in WGS84 decimal degrees.
 * @param zoom       - Current zoom level as returned by Leaflet's
 *                   `getZoom()`.
 * @return `true` when center and zoom are both finite numbers.
 */
export function canApplyMaxBounds(
	center: { readonly lat: number; readonly lng: number },
	zoom: number
): boolean {
	return isCenterUsableForMaxBounds( center ) && Number.isFinite( zoom );
}

/**
 * Apply `setMaxBounds` to a map only when `canApplyMaxBounds` says it
 * is safe to do so.
 *
 * Encapsulates the gate so the call site in `view.ts` reads as a single
 * action and so the gate can be unit-tested without instantiating a
 * real Leaflet map (the predicate alone is already covered by
 * `bounds.test.ts`; this thin wrapper closes the integration loop —
 * issue #117 E1). Returns `true` when the constraint was applied and
 * `false` when the gate skipped it; the boolean is useful to callers
 * that want to log the rare skip.
 *
 * @since 1.0.0
 *
 * @param map    - The map to constrain. Anything that exposes
 *               `getCenter`, `getZoom`, and `setMaxBounds` qualifies.
 * @param bounds - The `[southWest, northEast]` rectangle to apply.
 * @return `true` when the constraint was applied; `false` when skipped.
 */
export function applyMaxBoundsIfSafe(
	map: MapForMaxBounds,
	bounds: readonly [ LatLng, LatLng ]
): boolean {
	if ( ! canApplyMaxBounds( map.getCenter(), map.getZoom() ) ) {
		return false;
	}
	map.setMaxBounds( bounds );
	return true;
}
