/**
 * Editor preview chart component for the Elevation block.
 *
 * Pure React. Mounts an SVG, runs the Step 3 margin algorithm against
 * the bound Map's data, computes the chart scale via the shared
 * helper, and draws the chart's surfaces in the documented layer order:
 *
 *   1. Axis lines (Step 3).
 *   2. Plot fill (Step 5).
 *   3. Plot line (Step 5).
 *   4. Tick marks for both axes (Step 4).
 *   5. Tick labels for both axes (Step 4).
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

import { buildFillPathD, buildStrokePathD } from './geometry/curve';
import {
	computeMargins,
	type Margins,
	type MarginsInput,
} from './geometry/margins';
import {
	createTextMeasurer,
	type TypographyAttributes,
} from './geometry/measure';
import { computeChartScale } from './geometry/scale';

/**
 * A single LTTB-downsampled `(distance, elevation)` sample, as emitted
 * by `Rendering\Elevation_Samples::compute()` server-side.
 *
 * @since 1.0.0
 */
export type ElevationSample = readonly [ number, number ];

/**
 * Props for {@link Chart}.
 *
 * The `typography` prop is the tick-labels typography bundle read
 * from the block's `tickLabel*` attributes. The component applies
 * it as inline style on the host `<svg>` element so the visible
 * tick `<text>` labels and the measurer's hidden `<text>` nodes
 * (both SVG descendants) inherit the user's choices regardless of
 * which CSS-resolution path the Gutenberg editor exposes. The
 * eight fields also populate the dep-list of the layout effect
 * that runs `computeMargins`.
 *
 * @since 1.0.0
 */
export interface ChartProps {
	readonly data: MarginsInput;
	readonly samples: readonly ElevationSample[];
	readonly typography: TypographyAttributes;
}

/**
 * Maps a {@link TypographyAttributes} bundle to the React inline-style
 * object the chart's `<svg>` host carries.
 *
 * Each field is forwarded only when non-empty; empty fields fall
 * through to the SVG's inherited typography (which itself inherits
 * from the wrapper's resolved typography). Returns an empty object
 * when the bundle has no usable fields, so React serialises no
 * `style` attribute at all in that case.
 *
 * Applying typography here (rather than relying on a SCSS rule
 * keyed off the wrapper's tick-label custom properties) is what
 * guarantees the user's choices reach the rendered labels in the
 * Gutenberg editor: an inline `style` declaration wins against any
 * editor-iframe rule that might otherwise target SVG text under a
 * more specific selector. The frontend path keeps the SCSS rule for
 * symmetry — both paths produce the same final `font-*` declarations
 * on the SVG.
 *
 * @since 1.0.0
 *
 * @param typography Tick-labels typography bundle.
 * @return React inline-style object suitable for the `<svg style={…}>` prop.
 */
function typographyToSvgStyle(
	typography: TypographyAttributes
): React.CSSProperties {
	const style: Record< string, string > = {};
	if ( typography.fontFamily ) {
		style.fontFamily = typography.fontFamily;
	}
	if ( typography.fontSize ) {
		style.fontSize = typography.fontSize;
	}
	if ( typography.fontWeight ) {
		style.fontWeight = typography.fontWeight;
	}
	if ( typography.fontStyle ) {
		style.fontStyle = typography.fontStyle;
	}
	if ( typography.lineHeight ) {
		style.lineHeight = typography.lineHeight;
	}
	if ( typography.letterSpacing ) {
		style.letterSpacing = typography.letterSpacing;
	}
	if ( typography.textTransform ) {
		style.textTransform = typography.textTransform;
	}
	if ( typography.textDecoration ) {
		style.textDecoration = typography.textDecoration;
	}
	return style as React.CSSProperties;
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
 * Returns the SVG rendered dimensions from its `getBoundingClientRect`.
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
 * Renders the editor preview chart.
 *
 * Returns an SVG host that fills its parent. Axes, curve, tick marks,
 * and tick labels appear inside the SVG once both the margin algorithm
 * and the resize observer have produced their first results; on the
 * first paint either may be pending and the SVG is empty.
 *
 * @since 1.0.0
 *
 * @param props            See {@link ChartProps}.
 * @param props.data       Chart data (elevation range + distance).
 * @param props.samples    LTTB-downsampled (distance, elevation) pairs.
 * @param props.typography Tick-labels typography bundle.
 */
export function Chart( {
	data,
	samples,
	typography,
}: ChartProps ): JSX.Element {
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
	// change. Margins do not depend on wrapper dimensions, so they
	// are not invalidated by ResizeObserver. The dep list enumerates
	// each typography field separately so a fresh-object prop from a
	// parent re-render does not trigger an unnecessary remeasure loop.
	// useLayoutEffect runs after commit but before paint, so when the
	// effect observes the SVG with `getBBox()` the typography inline
	// style (committed in the same render pass) is already in force.
	useLayoutEffect( () => {
		if ( ! fontsReady ) {
			return;
		}
		const svg = svgRef.current;
		if ( ! svg ) {
			return;
		}
		const measure = createTextMeasurer( svg );
		setMargins( computeMargins( data, measure ) );
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

	// Resolve the chart scale and the two curve `d` strings up front
	// so the render branch stays declarative.
	const scale =
		ready && margins
			? computeChartScale( {
					distance: data.distance,
					minElevation: data.minElevation,
					maxElevation: data.maxElevation,
					margins,
					width: w,
					height: h,
			  } )
			: null;
	const drawable = scale !== null && scale.xTicks.length > 0;
	const strokeD = drawable
		? buildStrokePathD( samples, scale.projectX, scale.projectY )
		: '';
	const fillD = drawable
		? buildFillPathD(
				samples,
				scale.projectX,
				scale.projectY,
				scale.plotBottom
		  )
		: '';
	const tickMarkLength = drawable ? 0.2 * scale.em : 0;
	const labelOffset = drawable ? 0.5 * scale.em : 0;

	return (
		<svg
			ref={ svgRef }
			className="kntnt-gpx-blocks-elevation-chart-svg"
			width="100%"
			height="100%"
			viewBox={ `0 0 ${ w } ${ h }` }
			role="img"
			aria-label={ ariaLabel }
			style={ typographyToSvgStyle( typography ) }
		>
			{ drawable && scale && (
				<>
					<line
						className="kntnt-gpx-blocks-elevation-axis-x"
						x1={ scale.plotLeft }
						y1={ scale.plotBottom }
						x2={ scale.plotRight }
						y2={ scale.plotBottom }
						stroke="var(--kntnt-gpx-blocks-elevation-axis)"
						strokeWidth={ 1 }
					/>
					<line
						className="kntnt-gpx-blocks-elevation-axis-y"
						x1={ scale.plotLeft }
						y1={ scale.plotBottom }
						x2={ scale.plotLeft }
						y2={ scale.plotTop }
						stroke="var(--kntnt-gpx-blocks-elevation-axis)"
						strokeWidth={ 1 }
					/>
					<path
						className="kntnt-gpx-blocks-elevation-plot-fill"
						d={ fillD }
						fill="var(--kntnt-gpx-blocks-elevation-plot-fill)"
						stroke="none"
					/>
					<path
						className="kntnt-gpx-blocks-elevation-plot-line"
						d={ strokeD }
						fill="none"
						stroke="var(--kntnt-gpx-blocks-elevation-plot-line)"
						strokeWidth={ 2 }
						strokeLinejoin="round"
						strokeLinecap="round"
						vectorEffect="non-scaling-stroke"
					/>
					<g className="kntnt-gpx-blocks-elevation-ticks-x">
						{ scale.xTicks.map( ( t, i ) => (
							<line
								key={ i }
								x1={ t.position }
								y1={ scale.plotBottom }
								x2={ t.position }
								y2={ scale.plotBottom + tickMarkLength }
								stroke="var(--kntnt-gpx-blocks-elevation-axis)"
								strokeWidth={ 1 }
							/>
						) ) }
					</g>
					<g className="kntnt-gpx-blocks-elevation-ticks-y">
						{ scale.yTicks.map( ( t, i ) => (
							<line
								key={ i }
								x1={ scale.plotLeft }
								y1={ t.position }
								x2={ scale.plotLeft - tickMarkLength }
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
						{ scale.xTicks.map( ( t, i ) => (
							<text
								key={ i }
								x={ t.position }
								y={ scale.plotBottom + labelOffset }
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
						{ scale.yTicks.map( ( t, i ) => (
							<text
								key={ i }
								x={ scale.plotLeft - labelOffset }
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
