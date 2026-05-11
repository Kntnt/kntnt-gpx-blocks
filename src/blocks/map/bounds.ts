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
