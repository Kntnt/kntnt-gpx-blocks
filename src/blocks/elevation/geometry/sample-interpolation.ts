/**
 * Pure sample-interpolation and projection helpers for the elevation
 * chart's cursor and tooltip.
 *
 * Two helpers, both DOM-free:
 *
 *   - {@link interpolateSample} — binary-searches the LTTB-downsampled
 *     samples array for the bracket containing a given distance, then
 *     linearly interpolates `(distance, elevation)` between the two
 *     adjacent samples. Returns `null` for the degenerate
 *     `samples.length < 2` case so callers can short-circuit cleanly.
 *   - {@link projectCursor} — composes the supplied {@link ChartScale}'s
 *     projection callbacks into the `(cx, cy)` SVG-space coordinate the
 *     cursor's circle and the tooltip's anchor read from.
 *
 * Used by both the cursor and the tooltip, hence the SRP-clean module
 * under `geometry/`.
 *
 * @since 1.0.0
 */
import type { ChartScale } from './scale';

/**
 * The interpolated `(distance, elevation)` sample on the curve.
 *
 * @since 1.0.0
 */
export interface CursorSample {
	readonly distance: number;
	readonly elevation: number;
}

/**
 * SVG-space coordinate of the cursor's anchor.
 *
 * @since 1.0.0
 */
export interface ProjectedCursor {
	readonly cx: number;
	readonly cy: number;
}

/**
 * Interpolates the elevation at a given distance along the curve.
 *
 * Binary-searches the supplied samples array for the bracket
 * `[ samples[i], samples[i+1] ]` containing `distance`, then linearly
 * interpolates the elevation between them. Distance values outside the
 * array's range clamp to the corresponding endpoint sample. A
 * degenerate bracket (the two samples sharing the same distance) yields
 * the second sample's elevation rather than dividing by zero.
 *
 * Returns `null` when fewer than two samples are available — the chart
 * still draws axes and ticks but emits no curve and therefore has no
 * meaningful place to anchor a cursor or tooltip.
 *
 * @since 1.0.0
 *
 * @param samples  LTTB-downsampled `(distance, elevation)` pairs.
 * @param distance Track distance to interpolate at.
 * @return The interpolated sample, or `null` when fewer than two
 *         samples are supplied.
 */
export function interpolateSample(
	samples: ReadonlyArray< readonly [ number, number ] >,
	distance: number
): CursorSample | null {
	// A curve needs at least two points to interpolate between.
	if ( samples.length < 2 ) {
		return null;
	}

	// Clamp out-of-range distances to the matching endpoint so a
	// pointer drag past the chart's last bracket still yields a sample
	// on the curve.
	const first = samples[ 0 ] as readonly [ number, number ];
	const last = samples[ samples.length - 1 ] as readonly [ number, number ];
	if ( distance <= first[ 0 ] ) {
		return { distance: first[ 0 ], elevation: first[ 1 ] };
	}
	if ( distance >= last[ 0 ] ) {
		return { distance: last[ 0 ], elevation: last[ 1 ] };
	}

	// Binary-search for the rightmost sample whose distance is ≤ the
	// target. The invariant after the loop is samples[lo] is the lower
	// bracket endpoint; samples[lo + 1] is the upper.
	let lo = 0;
	let hi = samples.length - 1;
	while ( hi - lo > 1 ) {
		const mid = Math.floor( ( lo + hi ) / 2 );
		const midSample = samples[ mid ] as readonly [ number, number ];
		if ( midSample[ 0 ] <= distance ) {
			lo = mid;
		} else {
			hi = mid;
		}
	}

	// Linearly interpolate elevation between the two bracket endpoints.
	// A zero-width bracket falls back to the upper endpoint to avoid
	// dividing by zero — exceedingly rare after LTTB but a defence-in-
	// depth measure for hand-supplied samples.
	const lower = samples[ lo ] as readonly [ number, number ];
	const upper = samples[ lo + 1 ] as readonly [ number, number ];
	const span = upper[ 0 ] - lower[ 0 ];
	if ( span <= 0 ) {
		return { distance, elevation: upper[ 1 ] };
	}
	const t = ( distance - lower[ 0 ] ) / span;
	const elevation = lower[ 1 ] + t * ( upper[ 1 ] - lower[ 1 ] );
	return { distance, elevation };
}

/**
 * Projects a `(distance, elevation)` sample into SVG user units via the
 * supplied {@link ChartScale}.
 *
 * Trivial composition; exposed as its own helper so the editor preview
 * and the frontend host both share one call site for the projection
 * step rather than each open-coding `{ cx: scale.projectX(...) }`.
 *
 * @since 1.0.0
 *
 * @param sample The sample to project.
 * @param scale  The current chart scale.
 * @return The projected SVG-space coordinate.
 */
export function projectCursor(
	sample: CursorSample,
	scale: ChartScale
): ProjectedCursor {
	return {
		cx: scale.projectX( sample.distance ),
		cy: scale.projectY( sample.elevation ),
	};
}
