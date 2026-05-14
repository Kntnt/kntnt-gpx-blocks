/**
 * SVG `d`-attribute builders for the elevation curve.
 *
 * Two builders produce the two `<path>` elements the chart's SVG
 * emits for the curve:
 *
 *   - {@link buildStrokePathD} — the *open* line drawn over the
 *     elevation curve. `fill="none"`, `stroke="var(...)"`.
 *   - {@link buildFillPathD} — the *closed* area under the curve.
 *     `fill="var(...)"`, `stroke="none"`. The closing baseline edges sit
 *     at `y = plotBottom`, joining `[ x0, plotBottom ]` to the first
 *     sample and `[ xn, plotBottom ]` from the last sample.
 *
 * Both builders are pure: no DOM, no math beyond invoking the supplied
 * projection callbacks. Both emit `toFixed( 1 )` precision (10 cm on a
 * typical 1000-px-wide chart) and use explicit `L` commands per segment
 * to keep the rendered SVG readable in browser devtools.
 *
 * Both return the empty string when the input series has fewer than two
 * entries — the chart still draws axes and ticks but emits no `d`
 * attribute on the path elements in that branch.
 *
 * @since 1.0.0
 */

/**
 * A single (distance, elevation) sample.
 *
 * @since 1.0.0
 */
type Sample = readonly [ number, number ];

/**
 * Builds the SVG `d` attribute for the open stroke path of the
 * elevation curve.
 *
 * Format: `M x0 y0 L x1 y1 L … L xn yn`. One decimal of precision on
 * every emitted coordinate. Returns `''` when `samples.length < 2`.
 *
 * @since 1.0.0
 *
 * @param samples  LTTB-downsampled `(distance, elevation)` pairs in
 *                 ascending distance order.
 * @param projectX Projects a distance value to SVG x-coordinates.
 * @param projectY Projects an elevation value to SVG y-coordinates.
 * @return The path string, or `''` for insufficient samples.
 */
export function buildStrokePathD(
	samples: ReadonlyArray< Sample >,
	projectX: ( distance: number ) => number,
	projectY: ( elevation: number ) => number
): string {
	if ( samples.length < 2 ) {
		return '';
	}

	const parts: string[] = [];
	for ( let i = 0; i < samples.length; i++ ) {
		const sample = samples[ i ] as Sample;
		const x = projectX( sample[ 0 ] ).toFixed( 1 );
		const y = projectY( sample[ 1 ] ).toFixed( 1 );
		parts.push( i === 0 ? `M ${ x } ${ y }` : `L ${ x } ${ y }` );
	}
	return parts.join( ' ' );
}

/**
 * Builds the SVG `d` attribute for the closed area path under the
 * elevation curve.
 *
 * Format: `M x0 plotBottom L x0 y0 L x1 y1 … L xn yn L xn plotBottom Z`.
 * One decimal of precision on every emitted coordinate including the
 * `plotBottom` baseline. Returns `''` when `samples.length < 2`.
 *
 * Step 5 emits this path *always*, regardless of `plotFillColor` — its
 * visibility is governed by the resolved
 * `--kntnt-gpx-blocks-elevation-plot-fill` CSS variable (`transparent`
 * by default; a user-picked colour engages the visible fill). This
 * keeps the SVG-host code free of conditional emission logic.
 *
 * @since 1.0.0
 *
 * @param samples    LTTB-downsampled `(distance, elevation)` pairs in
 *                   ascending distance order.
 * @param projectX   Projects a distance value to SVG x-coordinates.
 * @param projectY   Projects an elevation value to SVG y-coordinates.
 * @param plotBottom Y-coordinate of the closing baseline edge (the
 *                   chart's X-axis line in SVG user units).
 * @return The path string, or `''` for insufficient samples.
 */
export function buildFillPathD(
	samples: ReadonlyArray< Sample >,
	projectX: ( distance: number ) => number,
	projectY: ( elevation: number ) => number,
	plotBottom: number
): string {
	if ( samples.length < 2 ) {
		return '';
	}

	const first = samples[ 0 ] as Sample;
	const last = samples[ samples.length - 1 ] as Sample;
	const baseline = plotBottom.toFixed( 1 );
	const firstX = projectX( first[ 0 ] ).toFixed( 1 );
	const lastX = projectX( last[ 0 ] ).toFixed( 1 );

	const parts: string[] = [ `M ${ firstX } ${ baseline }` ];
	for ( const sample of samples ) {
		const x = projectX( sample[ 0 ] ).toFixed( 1 );
		const y = projectY( sample[ 1 ] ).toFixed( 1 );
		parts.push( `L ${ x } ${ y }` );
	}
	parts.push( `L ${ lastX } ${ baseline }` );
	parts.push( 'Z' );
	return parts.join( ' ' );
}
