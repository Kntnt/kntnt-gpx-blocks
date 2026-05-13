/**
 * Editor preview chart component for the Elevation block.
 *
 * Pure React. Mounts an SVG, runs the Step 3 margin algorithm against
 * the bound Map's data, and draws the two axis lines plus the Step 4
 * tick marks and labels. The curve, cursor, and tooltip follow in
 * Steps 5–7.
 *
 * Architecture (Step 3 *Rendering architecture*):
 *
 *   - `useRef` holds the SVG node so the measurer can attach hidden
 *     `<text>` nodes to it.
 *   - `useLayoutEffect` runs the margin computation. Its deps are
 *     `data`, `typography`, and `fontsReady` — none of them changes
 *     when the wrapper resizes, so resize does not retrigger margin
 *     work.
 *   - A separate `useEffect` attaches `ResizeObserver` to the SVG.
 *     Resize updates the cached `width` / `height` used to recompute
 *     the tick set, but does not invalidate the margins themselves.
 *   - Font loading is gated by `document.fonts.ready` plus a
 *     `loadingdone` listener so late-loaded webfonts re-measure
 *     correctly.
 *
 * The frontend's `view.ts` consumes the same geometry helpers but
 * builds the SVG imperatively under the Interactivity API runtime.
 * The duplication is intentional and small — the chart geometry
 * helpers under `./geometry/` carry the math, and each host
 * instantiates the result in its native idiom.
 *
 * @since 1.0.0
 */

import {
	useEffect,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { formatXLabels, formatYLabels } from './geometry/format';
import {
	computeMargins,
	type Margins,
	type MarginsInput,
} from './geometry/margins';
import {
	createTextMeasurer,
	type TypographyAttributes,
} from './geometry/measure';
import { computeTickCount, niceTicks } from './geometry/ticks';

/**
 * Props for {@link Chart}.
 *
 * @since 1.0.0
 */
export interface ChartProps {
	readonly data: MarginsInput;
	readonly typography: TypographyAttributes;
}

/**
 * Cached SVG dimensions. Initialised to zero so the first frame
 * renders an empty viewBox; the ResizeObserver effect rewrites both
 * fields once the SVG has been laid out.
 *
 * @since 1.0.0
 */
interface Dimensions {
	readonly w: number;
	readonly h: number;
}

/**
 * One projected tick — the SVG-space coordinate plus the formatted
 * label. Built once per redraw by the X / Y tick builders.
 *
 * @since 1.0.0
 */
interface ProjectedTick {
	readonly position: number;
	readonly label: string;
}

/**
 * Returns the SVG rendered dimensions from its `getBoundingClientRect`.
 *
 * Reading the BCR rather than `clientWidth/clientHeight` keeps the
 * code agnostic of border/padding details — the rect is the content
 * box by virtue of the SVG sitting as a normal-flow child of the
 * wrapper with `width: 100%; height: 100%;`.
 *
 * @since 1.0.0
 *
 * @param svg The SVG element to inspect.
 * @return The measured dimensions, both clamped to non-negative.
 */
function readDimensions( svg: SVGSVGElement ): Dimensions {
	const rect = svg.getBoundingClientRect();
	return {
		w: rect.width < 0 ? 0 : rect.width,
		h: rect.height < 0 ? 0 : rect.height,
	};
}

/**
 * Builds the projected X-axis tick set for the redraw.
 *
 * Generates a nice tick set over `[0, distance]`, filters to
 * `value ≤ distance` (Strava-style bounds), formats with the m/km
 * unit chosen for the distance, and projects each value to its SVG
 * x-coordinate.
 *
 * @since 1.0.0
 *
 * @param distance Track distance in metres.
 * @param availX   Plot width in user units.
 * @param refWidth Width of the worst-case X reference label.
 * @param em       Resolved Tick-labels font-size in pixels.
 * @param wLeft    Left margin in user units (the plot's left edge).
 * @return Projected ticks in ascending order.
 */
function buildXTicks(
	distance: number,
	availX: number,
	refWidth: number,
	em: number,
	wLeft: number
): readonly ProjectedTick[] {
	const n = computeTickCount( availX, refWidth, em );
	const raw = niceTicks( 0, distance, n );
	const values = raw.values.filter( ( v ) => v <= distance );
	const labels = formatXLabels( values, raw.step, distance );
	return values.map( ( v, i ) => ( {
		position: wLeft + ( v / distance ) * availX,
		label: labels[ i ] as string,
	} ) );
}

/**
 * Builds the projected Y-axis tick set for the redraw.
 *
 * Generates a nice tick set over the (possibly Case-B-inflated) Y
 * range and projects each value vertically so the lowest tick lands on
 * the X axis line and the highest on `y = wTop`.
 *
 * @since 1.0.0
 *
 * @param yMin      Inflated-where-needed Y range minimum.
 * @param yMax      Inflated-where-needed Y range maximum.
 * @param availY    Plot height in user units.
 * @param refHeight Height of the reference label.
 * @param em        Resolved Tick-labels font-size in pixels.
 * @param wTop      Top margin in user units (the plot's top edge).
 * @param hBottom   Distance from the SVG bottom edge to the X axis
 *                  line in user units (the `h` margin scalar).
 * @param H         SVG rendered height in user units.
 * @return Projected ticks ordered from low (bottom) to high (top).
 */
function buildYTicks(
	yMin: number,
	yMax: number,
	availY: number,
	refHeight: number,
	em: number,
	wTop: number,
	hBottom: number,
	H: number
): readonly ProjectedTick[] {
	const n = computeTickCount( availY, refHeight, em );
	const raw = niceTicks( yMin, yMax, n );
	const labels = formatYLabels( raw.values, raw.step );
	const firstValue = raw.values[ 0 ] ?? yMin;
	const lastValue = raw.values[ raw.values.length - 1 ] ?? yMax;
	const span = lastValue - firstValue;
	const bottom = H - hBottom;

	// A zero span happens only when niceTicks degenerated to a single
	// value (max === min after Case-B inflation should never trigger
	// this, but the guard keeps `bottom - 0/0 * availY` from emitting
	// NaN coordinates).
	if ( span <= 0 ) {
		return raw.values.map( ( _, i ) => ( {
			position: bottom,
			label: labels[ i ] as string,
		} ) );
	}

	return raw.values.map( ( v, i ) => ( {
		position: bottom - ( ( v - firstValue ) / span ) * availY,
		label: labels[ i ] as string,
	} ) );
}

/**
 * Renders the editor preview chart.
 *
 * Returns an SVG host that fills its parent. Axes, tick marks, and
 * tick labels appear inside the SVG once both the margin algorithm and
 * the resize observer have produced their first results; on the first
 * paint either may be pending and the SVG is empty.
 *
 * @since 1.0.0
 *
 * @param props            See {@link ChartProps}.
 * @param props.data       Chart data (elevation range + distance).
 * @param props.typography Tick-labels typography bundle.
 */
export function Chart( { data, typography }: ChartProps ): JSX.Element {
	const svgRef = useRef< SVGSVGElement | null >( null );
	const [ margins, setMargins ] = useState< Margins | null >( null );
	const [ dims, setDims ] = useState< Dimensions >( { w: 0, h: 0 } );
	const [ fontsReady, setFontsReady ] = useState< boolean >(
		() =>
			typeof document === 'undefined' ||
			typeof document.fonts === 'undefined' ||
			document.fonts.status === 'loaded'
	);

	// Wait for fonts.ready before the first measurement; re-measure
	// on later loadingdone events so late-loaded webfonts replace
	// fallback-font metrics rather than leaving the chart with
	// permanently wrong margins.
	useEffect( () => {
		if ( typeof document === 'undefined' ) {
			return undefined;
		}
		const { fonts } = document;
		if ( typeof fonts === 'undefined' ) {
			setFontsReady( true );
			return undefined;
		}

		let cancelled = false;
		fonts.ready.then( () => {
			if ( ! cancelled ) {
				setFontsReady( true );
			}
		} );

		const onLoadingDone = (): void => {
			if ( cancelled ) {
				return;
			}
			// Force a re-measure by clearing margins; the next
			// useLayoutEffect tick rebuilds them under the now-final
			// font metrics.
			setMargins( null );
			setFontsReady( true );
		};
		fonts.addEventListener( 'loadingdone', onLoadingDone );

		return () => {
			cancelled = true;
			fonts.removeEventListener( 'loadingdone', onLoadingDone );
		};
	}, [] );

	// Compute margins after fonts are ready, on data/typography
	// change. Margins do not depend on wrapper dimensions, so they are
	// not invalidated by ResizeObserver. The dep list reads each
	// primitive separately so a fresh-object prop from a parent
	// re-render does not trigger an unnecessary remeasure loop.
	useLayoutEffect( () => {
		if ( ! fontsReady ) {
			return;
		}
		const svg = svgRef.current;
		if ( ! svg ) {
			return;
		}
		const measure = createTextMeasurer( svg );
		setMargins( computeMargins( data, typography, measure ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [
		data.minElevation,
		data.maxElevation,
		data.distance,
		typography.fontFamily,
		typography.fontSize,
		typography.fontWeight,
		typography.fontStyle,
		typography.lineHeight,
		typography.letterSpacing,
		typography.textTransform,
		typography.textDecoration,
		fontsReady,
	] );

	// Cache SVG dimensions for axis drawing; ResizeObserver triggers
	// redraw without re-running margin work.
	useEffect( () => {
		const svg = svgRef.current;
		if ( ! svg ) {
			return undefined;
		}

		setDims( readDimensions( svg ) );

		if ( typeof ResizeObserver === 'undefined' ) {
			return undefined;
		}
		const ro = new ResizeObserver( () => {
			setDims( readDimensions( svg ) );
		} );
		ro.observe( svg );
		return () => ro.disconnect();
	}, [] );

	const { w, h } = dims;
	const ready = margins !== null && w > 0 && h > 0;
	const ariaLabel = __(
		'Elevation profile of GPX track',
		'kntnt-gpx-blocks'
	);

	// Derive the worst-case reference sizes back from the margin
	// scalars — wRight = refXWidth/2 + 0.5em and h = refHeight + 0.5em
	// by construction in computeMargins(). This avoids a second measurer
	// round-trip while still routing the redraw through the spec's
	// computeTickCount(avail, refSize, em) signature.
	let xTicks: readonly ProjectedTick[] = [];
	let yTicks: readonly ProjectedTick[] = [];
	let plotTop = 0;
	let plotBottom = 0;
	let plotLeft = 0;
	let plotRight = 0;
	let tickMarkLength = 0;
	let labelOffset = 0;

	if ( ready && margins ) {
		const em = margins.em;
		const halfEm = 0.5 * em;
		const refXWidth = 2 * ( margins.wRight - halfEm );
		const refHeight = margins.h - halfEm;
		const availX = w - margins.wLeft - margins.wRight;
		const availY = h - margins.wTop - margins.h;

		plotLeft = margins.wLeft;
		plotRight = w - margins.wRight;
		plotTop = margins.wTop;
		plotBottom = h - margins.h;
		tickMarkLength = 0.2 * em;
		labelOffset = halfEm;

		if ( availX > 0 && availY > 0 ) {
			const flatY = data.minElevation === data.maxElevation;
			const yMin = flatY ? data.minElevation - 1 : data.minElevation;
			const yMax = flatY ? data.maxElevation + 1 : data.maxElevation;

			xTicks = buildXTicks(
				data.distance,
				availX,
				refXWidth,
				em,
				margins.wLeft
			);
			yTicks = buildYTicks(
				yMin,
				yMax,
				availY,
				refHeight,
				em,
				margins.wTop,
				margins.h,
				h
			);
		}
	}

	return (
		<svg
			ref={ svgRef }
			className="kntnt-gpx-blocks-elevation-chart-svg"
			width="100%"
			height="100%"
			viewBox={ `0 0 ${ w } ${ h }` }
			role="img"
			aria-label={ ariaLabel }
		>
			{ ready && margins && (
				<>
					<line
						className="kntnt-gpx-blocks-elevation-axis-x"
						x1={ plotLeft }
						y1={ plotBottom }
						x2={ plotRight }
						y2={ plotBottom }
						stroke="var(--kntnt-gpx-blocks-elevation-axis)"
						strokeWidth={ 1 }
					/>
					<line
						className="kntnt-gpx-blocks-elevation-axis-y"
						x1={ plotLeft }
						y1={ plotBottom }
						x2={ plotLeft }
						y2={ plotTop }
						stroke="var(--kntnt-gpx-blocks-elevation-axis)"
						strokeWidth={ 1 }
					/>
					<g className="kntnt-gpx-blocks-elevation-ticks-x">
						{ xTicks.map( ( t, i ) => (
							<line
								key={ i }
								x1={ t.position }
								y1={ plotBottom }
								x2={ t.position }
								y2={ plotBottom + tickMarkLength }
								stroke="var(--kntnt-gpx-blocks-elevation-axis)"
								strokeWidth={ 1 }
							/>
						) ) }
					</g>
					<g className="kntnt-gpx-blocks-elevation-ticks-y">
						{ yTicks.map( ( t, i ) => (
							<line
								key={ i }
								x1={ plotLeft }
								y1={ t.position }
								x2={ plotLeft - tickMarkLength }
								y2={ t.position }
								stroke="var(--kntnt-gpx-blocks-elevation-axis)"
								strokeWidth={ 1 }
							/>
						) ) }
					</g>
					<g
						className="kntnt-gpx-blocks-elevation-tick-labels-x"
						fill="var(--kntnt-gpx-blocks-elevation-axis-label)"
					>
						{ xTicks.map( ( t, i ) => (
							<text
								key={ i }
								x={ t.position }
								y={ plotBottom + labelOffset }
								textAnchor="middle"
								dominantBaseline="hanging"
							>
								{ t.label }
							</text>
						) ) }
					</g>
					<g
						className="kntnt-gpx-blocks-elevation-tick-labels-y"
						fill="var(--kntnt-gpx-blocks-elevation-axis-label)"
					>
						{ yTicks.map( ( t, i ) => (
							<text
								key={ i }
								x={ plotLeft - labelOffset }
								y={ t.position }
								textAnchor="end"
								dominantBaseline="central"
							>
								{ t.label }
							</text>
						) ) }
					</g>
				</>
			) }
		</svg>
	);
}
