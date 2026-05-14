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
import { __, sprintf } from '@wordpress/i18n';

import { clientXToFraction } from './cursor-input';
import {
	interpolateSample,
	projectCursor,
} from './geometry/sample-interpolation';
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
import { computeTooltipLayout } from './geometry/tooltip-layout';
import { formatDistance, formatElevation } from './geometry/tooltip-format';
import { computeTooltipPlacement } from './geometry/tooltip-placement';

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
 * from the block's `tickLabel*` attributes. It is **not** consumed
 * by the chart's render output or by the measurer — those read the
 * active typography through CSS inheritance from the host SVG,
 * sourced from the eight `--kntnt-gpx-blocks-elevation-tick-label-*`
 * custom properties the wrapper carries (translated into `font-*` /
 * `letter-spacing` / `text-*` declarations by the SCSS rule on
 * `.kntnt-gpx-blocks-elevation-chart-svg`). The prop is retained
 * because its eight fields populate the dep-list of the layout
 * effect that runs `computeMargins`; without it, a typography change
 * in the inspector would not trigger a re-measurement.
 *
 * `showCursor`, `showVerticalGuide`, and `showHorizontalGuide` carry
 * the three new toggles from issue #144's `Cursor & guides` Inspector
 * panel. They default-on / default-on / default-off so an attribute bag
 * with no explicit values mirrors a fresh-insert block. The editor
 * preview renders the cursor at a static `fraction = 0.5` so the user
 * sees the live effect of the toggles, including which guides are on.
 *
 * @since 1.0.0
 */
export interface ChartProps {
	readonly data: MarginsInput;
	readonly samples: readonly ElevationSample[];
	readonly typography: TypographyAttributes;
	readonly tooltipDistanceTypography: TypographyAttributes;
	readonly tooltipHeightTypography: TypographyAttributes;
	readonly showCursor: boolean;
	readonly showVerticalGuide: boolean;
	readonly showHorizontalGuide: boolean;
	readonly tooltipShowDistance: boolean;
	readonly tooltipShowHeight: boolean;
	/**
	 * Optional callback invoked when the user hovers the cursor hit-rect
	 * with a `[0, 1]` fraction, and with `null` when the pointer leaves
	 * the chart. Threaded through by `edit.tsx` so the Elevation editor
	 * preview can publish fractions onto the editor cursor bridge
	 * (issue #153), where the sibling Map editor preview consumes them.
	 *
	 * Unwired when omitted: the editor preview's static-cursor reference
	 * still renders at `fraction = 0.5`, but no events fire. The
	 * frontend uses a different code path entirely (the Interactivity
	 * runtime in `view.ts`), so this prop has no effect outside the
	 * editor.
	 */
	readonly onHoverFraction?: ( fraction: number | null ) => void;
}

/**
 * Resolved tooltip layout produced by the measurement effect and
 * consumed by the editor preview's tooltip JSX. `null` whenever the
 * tooltip should not render (master toggle off, both rows off, fewer
 * than two samples, …).
 *
 * @since 1.0.0
 */
interface TooltipLayoutState {
	readonly rectX: number;
	readonly rectY: number;
	readonly rectWidth: number;
	readonly rectHeight: number;
	readonly distanceTextX: number;
	readonly distanceTextY: number;
	readonly heightTextX: number;
	readonly heightTextY: number;
	readonly distanceLabel: string;
	readonly heightLabel: string;
	readonly a11yLabel: string;
	readonly showDistance: boolean;
	readonly showHeight: boolean;
}

/**
 * Resolves the locale string used for tooltip number formatting. Reads
 * `<html lang>` and falls back to `'sv-SE'` so the editor preview
 * matches the frontend's deterministic locale resolution (see
 * `geometry/format.ts`).
 *
 * @since 1.0.0
 *
 * @return The resolved locale string.
 */
function resolveDocumentLocale(): string {
	if ( typeof document !== 'undefined' ) {
		const lang = document.documentElement?.lang;
		if ( typeof lang === 'string' && lang !== '' ) {
			return lang;
		}
	}
	return 'sv-SE';
}

/**
 * Builds the tooltip's `<title>` accessibility label from the two
 * visible-row labels.
 *
 * @since 1.0.0
 *
 * @param distanceLabel Formatted distance string.
 * @param heightLabel   Formatted elevation string.
 * @param showDistance  Whether the distance row is visible.
 * @param showHeight    Whether the elevation row is visible.
 * @return The translated a11y label.
 */
function buildTooltipA11yLabel(
	distanceLabel: string,
	heightLabel: string,
	showDistance: boolean,
	showHeight: boolean
): string {
	if ( showDistance && showHeight ) {
		return sprintf(
			/* translators: 1: distance, 2: elevation */
			__( 'Distance %1$s, elevation %2$s', 'kntnt-gpx-blocks' ),
			distanceLabel,
			heightLabel
		);
	}
	if ( showDistance ) {
		return sprintf(
			/* translators: %s: distance */
			__( 'Distance %s', 'kntnt-gpx-blocks' ),
			distanceLabel
		);
	}
	return sprintf(
		/* translators: %s: elevation */
		__( 'Elevation %s', 'kntnt-gpx-blocks' ),
		heightLabel
	);
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
 * @param props                           See {@link ChartProps}.
 * @param props.data                      Chart data (elevation range + distance).
 * @param props.samples                   LTTB-downsampled (distance, elevation) pairs.
 * @param props.typography                Tick-labels typography bundle.
 * @param props.showCursor                Whether to render the cursor at all (issue #144).
 * @param props.showVerticalGuide         Whether the vertical guide line is drawn.
 * @param props.showHorizontalGuide       Whether the horizontal guide line is drawn.
 * @param props.tooltipShowDistance       Whether the tooltip's distance row is drawn (Step 7).
 * @param props.tooltipShowHeight         Whether the tooltip's elevation row is drawn (Step 7).
 * @param props.tooltipDistanceTypography Tooltip distance-row typography (Step 7 pl.3) — not consumed by render output (the SVG inherits the row's class-scoped custom properties from the wrapper), but enumerated in the tooltip layout effect's dep-list so a per-row font-size change re-runs the measurement.
 * @param props.tooltipHeightTypography   Tooltip height-row typography (Step 7 pl.3) — same purpose as `tooltipDistanceTypography` for the elevation row.
 * @param props.onHoverFraction           Optional hover-fraction callback forwarded to the cursor hit-rect (issue #153). Receives a `[0, 1]` fraction on `pointermove` and `null` on `pointerleave`; the editor preview wires this to the editor cursor bridge so a sibling Map preview can mirror the position.
 */
export function Chart( {
	data,
	samples,
	typography,
	tooltipDistanceTypography,
	tooltipHeightTypography,
	showCursor,
	showVerticalGuide,
	showHorizontalGuide,
	tooltipShowDistance,
	tooltipShowHeight,
	onHoverFraction,
}: ChartProps ): JSX.Element {
	const svgRef = useRef< SVGSVGElement | null >( null );
	const [ margins, setMargins ] = useState< Margins | null >( null );
	const [ dims, setDims ] = useState< Dimensions >( { w: 0, h: 0 } );
	const [ tooltipLayout, setTooltipLayout ] =
		useState< TooltipLayoutState | null >( null );
	const [ fontsReady, setFontsReady ] = useState< boolean >(
		() =>
			typeof document === 'undefined' ||
			typeof document.fonts === 'undefined' ||
			document.fonts.status === 'loaded'
	);

	// Re-measure trigger for `loadingdone` events that fire after the
	// initial margin computation. The handler bumps this counter and
	// the layout effect below lists it as a dep, so a late-loaded
	// webfont reaches a fresh `computeMargins` call under its final
	// metrics. A counter — rather than clearing `margins` to `null` and
	// re-asserting `fontsReady` — is necessary because `setFontsReady(
	// true )` is a no-op when the value is already `true`, and a
	// `setMargins( null )` re-render alone does not re-fire the layout
	// effect (the dep list does not include `margins`). Without this
	// token, a post-mount `loadingdone` would tear the chart down
	// permanently.
	const [ remeasureToken, setRemeasureToken ] = useState< number >( 0 );

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
			setRemeasureToken( ( previous ) => previous + 1 );
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
	// effect observes the SVG with `getBBox()` the wrapper's freshly
	// committed --kntnt-gpx-blocks-elevation-tick-label-* custom
	// properties (and thus the SCSS rule's resolved font-* on the SVG)
	// are already in force, so the measurer sees the typography the
	// user will see rendered.
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
		remeasureToken,
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

	// Editor preview cursor: a static anchor at fraction = 0.5. The
	// editor canvas does not bootstrap the Interactivity API, so the
	// cursor cannot react to a real fraction here — its purpose is to
	// give the inspector's Cursor colour control a live target. The
	// frontend's `view.ts` mounts an interactive cursor under the
	// Interactivity API instead. When the bound track has fewer than
	// two samples, `interpolateSample` returns null and the cursor
	// group is skipped (the chart still draws axes and ticks but has
	// no curve to anchor a cursor on).
	const previewSample =
		drawable && samples.length >= 2
			? interpolateSample( samples, scale.distance * 0.5 )
			: null;
	const previewCursor =
		drawable && previewSample !== null
			? projectCursor( previewSample, scale )
			: null;

	// Tooltip labels for the midpoint sample. Locale is resolved from
	// `<html lang>` (with an sv-SE fallback) so the editor preview
	// matches the frontend deterministic resolution.
	const tooltipLocale = resolveDocumentLocale();
	const distanceLabel =
		previewSample !== null
			? formatDistance( previewSample.distance, tooltipLocale )
			: '';
	const heightLabel =
		previewSample !== null
			? formatElevation( previewSample.elevation, tooltipLocale )
			: '';

	// Whether the tooltip should be rendered at all on this frame.
	// Master cursor toggle off → no tooltip; both row toggles off →
	// no tooltip; fewer than two samples → no tooltip.
	const tooltipEnabled =
		showCursor &&
		previewCursor !== null &&
		( tooltipShowDistance || tooltipShowHeight );

	// Measure the tooltip's two rows under their class-scoped typography
	// and compute the rect dimensions + placement. Runs in a layout
	// effect so the measurement reflects the freshly committed wrapper
	// custom properties (Step 4 pl.7 architecture). Resets the layout
	// to `null` whenever the tooltip should not render so a previous
	// frame's geometry does not leak into a now-hidden state.
	useLayoutEffect( () => {
		const svg = svgRef.current;
		if ( ! svg ) {
			setTooltipLayout( null );
			return;
		}
		if ( ! tooltipEnabled || ! scale || ! previewCursor ) {
			setTooltipLayout( null );
			return;
		}

		const measureDistance = createTextMeasurer(
			svg,
			'kntnt-gpx-blocks-elevation-tooltip-distance'
		);
		const measureHeight = createTextMeasurer(
			svg,
			'kntnt-gpx-blocks-elevation-tooltip-height'
		);
		const distanceBBox = tooltipShowDistance
			? measureDistance( distanceLabel )
			: null;
		const heightBBox = tooltipShowHeight
			? measureHeight( heightLabel )
			: null;

		// Resolve the rect dimensions from the visible-row bboxes so
		// the placement helper has a final tooltipBox size to flip
		// against.
		const em = scale.em;
		const preliminaryLayout = computeTooltipLayout( {
			placementX: 0,
			placementY: 0,
			em,
			distance: distanceBBox,
			height: heightBBox,
		} );

		const plotRect = {
			x: scale.plotLeft,
			y: scale.plotTop,
			w: scale.plotRight - scale.plotLeft,
			h: scale.plotBottom - scale.plotTop,
		};
		const placement = computeTooltipPlacement( {
			cursor: { cx: previewCursor.cx },
			plotRect,
			tooltipBox: {
				w: preliminaryLayout.rectWidth,
				h: preliminaryLayout.rectHeight,
			},
			em,
			previousSide: null,
		} );

		// Resolve the final per-row text positions relative to the
		// chosen placement origin. The pure layout helper positions
		// each row by its visual bbox top so digit-only labels (no
		// descenders) sit with symmetric padding above and below — see
		// geometry/tooltip-layout.ts.
		const layout = computeTooltipLayout( {
			placementX: placement.x,
			placementY: placement.y,
			em,
			distance: distanceBBox,
			height: heightBBox,
		} );

		setTooltipLayout( {
			rectX: placement.x,
			rectY: placement.y,
			rectWidth: layout.rectWidth,
			rectHeight: layout.rectHeight,
			distanceTextX: layout.distanceTextX,
			distanceTextY: layout.distanceTextY,
			heightTextX: layout.heightTextX,
			heightTextY: layout.heightTextY,
			distanceLabel,
			heightLabel,
			a11yLabel: buildTooltipA11yLabel(
				distanceLabel,
				heightLabel,
				tooltipShowDistance,
				tooltipShowHeight
			),
			showDistance: tooltipShowDistance,
			showHeight: tooltipShowHeight,
		} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [
		tooltipEnabled,
		tooltipShowDistance,
		tooltipShowHeight,
		distanceLabel,
		heightLabel,
		previewCursor?.cx,
		previewCursor?.cy,
		scale?.plotLeft,
		scale?.plotRight,
		scale?.plotTop,
		scale?.plotBottom,
		scale?.em,
		typography.fontFamily,
		typography.fontSize,
		typography.fontWeight,
		typography.fontStyle,
		typography.lineHeight,
		typography.letterSpacing,
		typography.textTransform,
		typography.textDecoration,
		tooltipDistanceTypography.fontFamily,
		tooltipDistanceTypography.fontSize,
		tooltipDistanceTypography.fontWeight,
		tooltipDistanceTypography.fontStyle,
		tooltipDistanceTypography.lineHeight,
		tooltipDistanceTypography.letterSpacing,
		tooltipDistanceTypography.textTransform,
		tooltipDistanceTypography.textDecoration,
		tooltipHeightTypography.fontFamily,
		tooltipHeightTypography.fontSize,
		tooltipHeightTypography.fontWeight,
		tooltipHeightTypography.fontStyle,
		tooltipHeightTypography.lineHeight,
		tooltipHeightTypography.letterSpacing,
		tooltipHeightTypography.textTransform,
		tooltipHeightTypography.textDecoration,
	] );

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
					{ showCursor && previewCursor !== null && (
						<g className="kntnt-gpx-blocks-elevation-cursor">
							<rect
								className="kntnt-gpx-blocks-elevation-cursor-hitarea"
								x={ scale.plotLeft }
								y={ scale.plotTop }
								width={ scale.plotRight - scale.plotLeft }
								height={ scale.plotBottom - scale.plotTop }
								fill="transparent"
								onPointerMove={
									onHoverFraction
										? ( event ) =>
												onHoverFraction(
													clientXToFraction(
														event.clientX,
														event.currentTarget
													)
												)
										: undefined
								}
								onPointerLeave={
									onHoverFraction
										? () => onHoverFraction( null )
										: undefined
								}
							/>
							{ showVerticalGuide && (
								<line
									className="kntnt-gpx-blocks-elevation-cursor-guide-v"
									x1={ previewCursor.cx }
									y1={ previewCursor.cy }
									x2={ previewCursor.cx }
									y2={ scale.plotBottom }
									stroke="var(--kntnt-gpx-blocks-elevation-cursor)"
									strokeWidth={ 1 }
								/>
							) }
							{ showHorizontalGuide && (
								<line
									className="kntnt-gpx-blocks-elevation-cursor-guide-h"
									x1={ previewCursor.cx }
									y1={ previewCursor.cy }
									x2={ scale.plotLeft }
									y2={ previewCursor.cy }
									stroke="var(--kntnt-gpx-blocks-elevation-cursor)"
									strokeWidth={ 1 }
								/>
							) }
							<circle
								className="kntnt-gpx-blocks-elevation-cursor-dot"
								cx={ previewCursor.cx }
								cy={ previewCursor.cy }
								r={ 6 }
								fill="var(--kntnt-gpx-blocks-elevation-cursor)"
								stroke="var(--kntnt-gpx-blocks-elevation-cursor)"
								strokeWidth={ 2 }
							/>
						</g>
					) }
					{ tooltipEnabled && tooltipLayout !== null && (
						<g
							className="kntnt-gpx-blocks-elevation-tooltip"
							pointerEvents="none"
						>
							<title>{ tooltipLayout.a11yLabel }</title>
							<rect
								className="kntnt-gpx-blocks-elevation-tooltip-bg"
								x={ tooltipLayout.rectX }
								y={ tooltipLayout.rectY }
								width={ tooltipLayout.rectWidth }
								height={ tooltipLayout.rectHeight }
								rx="0.25em"
								fill="var(--kntnt-gpx-blocks-elevation-tooltip-background)"
							/>
							{ tooltipLayout.showDistance && (
								<text
									className="kntnt-gpx-blocks-elevation-tooltip-distance"
									x={ tooltipLayout.distanceTextX }
									y={ tooltipLayout.distanceTextY }
									textAnchor="start"
									fill="var(--kntnt-gpx-blocks-elevation-tooltip-distance)"
								>
									{ tooltipLayout.distanceLabel }
								</text>
							) }
							{ tooltipLayout.showHeight && (
								<text
									className="kntnt-gpx-blocks-elevation-tooltip-height"
									x={ tooltipLayout.heightTextX }
									y={ tooltipLayout.heightTextY }
									textAnchor="start"
									fill="var(--kntnt-gpx-blocks-elevation-tooltip-height)"
								>
									{ tooltipLayout.heightLabel }
								</text>
							) }
						</g>
					) }
				</>
			) }
		</svg>
	);
}
