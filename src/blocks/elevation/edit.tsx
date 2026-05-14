/**
 * GPX Elevation block edit component.
 *
 * Orchestrates the binding model and the chart-rendering surface:
 *
 *   1. Walks the editor block tree via `useMapBlocks()` to find every
 *      GPX Map block on the page.
 *   2. Auto-picks the topmost configured Map when `mapId` is empty or
 *      the literal sentinel `"auto"` (via `useAutoPickMapId`).
 *   3. Fetches the bound Map's cached payload through the editor-only
 *      REST endpoint via `useBoundMapPayload`.
 *   4. Renders the Data Source panel when the picker has a real
 *      choice to surface (≥ 2 configured Maps, or a broken binding
 *      with ≥ 1 configured Map remaining).
 *   5. Routes the resolved binding state into {@link ElevationPreview},
 *      which renders either a warning box, nothing (loading), or the
 *      `<Chart>` for the healthy state.
 *   6. Injects the wrapper baseline (`min-height: 15vh` when the user
 *      has not set their own minHeight) inline on
 *      `useBlockProps().style` so the editor preview wrapper agrees
 *      with the frontend wrapper byte-for-byte.
 *   7. Routes the eight Color attributes to inline CSS custom
 *      properties on the wrapper so the chart and the tooltip
 *      surfaces pick the user's colours up via the standard cascade.
 *
 * @since 1.0.0
 */

import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { BlockEditProps } from '@wordpress/blocks';

import { usefulValue } from './useful-value';
import { InspectorColorPanel } from './inspector-color';
import { TypographyToolsPanel } from '../shared/typography-tools-panel';
import { getDefaultMinHeight } from '../shared/dimensions-defaults';
import { InspectorBottomSpacer } from '../shared/inspector-bottom-spacer';
import { useMapBlocks, type EditorBlock } from './use-map-blocks';
import { isAutoMapId, useAutoPickMapId } from './use-auto-pick-map-id';
import {
	useBoundMapPayload,
	type BoundMapPayload,
	type BoundMapPayloadError,
} from './use-bound-map-payload';
import {
	InspectorDataSource,
	shouldShowDataSourcePanel,
} from './inspector-data-source';
import { ElevationPreview, type PreviewState } from './preview';
import type { TypographyAttributes } from './geometry/measure';

/**
 * Resolved binding outcome for one render of the Elevation block.
 *
 * Carries the {@link PreviewState} the {@link ElevationPreview} consumes
 * plus a `bindingBroken` flag the Data Source panel's visibility logic
 * needs.
 *
 * @since 1.0.0
 */
interface BindingResolution {
	readonly state: PreviewState;
	readonly bindingBroken: boolean;
}

/**
 * Block name as declared in `block.json`. Hoisted as a constant so the
 * `getDefaultMinHeight` call site cannot drift out of sync.
 *
 * @since 1.0.0
 */
const BLOCK_NAME = 'kntnt-gpx-blocks/elevation';

/**
 * Reads a single string attribute or returns the empty string when it
 * is missing or non-string.
 *
 * @since 1.0.0
 *
 * @param attributes Block attribute bag.
 * @param key        Attribute name.
 * @return The string value, or `''`.
 */
function readString(
	attributes: Record< string, unknown >,
	key: string
): string {
	const value = attributes[ key ];
	return typeof value === 'string' ? value : '';
}

/**
 * Extracts the eight Tick-labels typography attributes into the
 * {@link TypographyAttributes} shape the chart's measurer consumes.
 *
 * Only non-empty values are forwarded. Empty values fall through to
 * the SVG's inherited typography (which is the wrapper's resolved
 * typography from theme + Settings tab).
 *
 * @since 1.0.0
 *
 * @param attributes Block attribute bag.
 * @return The typography bundle, with empty fields omitted.
 */
function readTickLabelTypography(
	attributes: Record< string, unknown >
): TypographyAttributes {
	const mapping: Record< keyof TypographyAttributes, string > = {
		fontFamily: 'tickLabelFontFamily',
		fontSize: 'tickLabelFontSize',
		fontWeight: 'tickLabelFontWeight',
		fontStyle: 'tickLabelFontStyle',
		lineHeight: 'tickLabelLineHeight',
		letterSpacing: 'tickLabelLetterSpacing',
		textTransform: 'tickLabelTextTransform',
		textDecoration: 'tickLabelTextDecoration',
	};
	const result: Record< string, string > = {};
	for ( const [ field, key ] of Object.entries( mapping ) ) {
		const value = readString( attributes, key );
		if ( value !== '' ) {
			result[ field ] = value;
		}
	}
	return result as TypographyAttributes;
}

/**
 * Extracts the eight tooltip-row typography attributes for the given
 * row into the {@link TypographyAttributes} shape.
 *
 * Step 7 pl.3 added these bundles so the editor preview's `Chart`
 * component can list each tooltip-row font field in its layout
 * effect's dep-list, which causes a per-row font-size change in the
 * inspector to re-trigger the SVG measurement that sizes the tooltip
 * `<rect>`. Without these props the wrapper's CSS custom properties
 * would update (so the rendered text would adopt the new font-size),
 * but the measurement that decides the rect's width and height would
 * stay frozen on the previous frame's metrics — leaving the rendered
 * rows escaping the rect on large font-size jumps.
 *
 * @since 1.0.0
 *
 * @param attributes Block attribute bag.
 * @param prefix     Attribute key prefix (`'tooltipDistance'` for the
 *                   distance row, `'tooltipHeight'` for the height row).
 * @return The typography bundle, with empty fields omitted.
 */
function readTooltipRowTypography(
	attributes: Record< string, unknown >,
	prefix: 'tooltipDistance' | 'tooltipHeight'
): TypographyAttributes {
	const suffixes: Record< keyof TypographyAttributes, string > = {
		fontFamily: 'FontFamily',
		fontSize: 'FontSize',
		fontWeight: 'FontWeight',
		fontStyle: 'FontStyle',
		lineHeight: 'LineHeight',
		letterSpacing: 'LetterSpacing',
		textTransform: 'TextTransform',
		textDecoration: 'TextDecoration',
	};
	const result: Record< string, string > = {};
	for ( const [ field, suffix ] of Object.entries( suffixes ) ) {
		const value = readString( attributes, `${ prefix }${ suffix }` );
		if ( value !== '' ) {
			result[ field ] = value;
		}
	}
	return result as TypographyAttributes;
}

/**
 * Cursor & guides + Tooltip info toggle bundle threaded into the
 * healthy `PreviewState`.
 *
 * @since 1.0.0
 */
interface CursorToggleBundle {
	readonly showCursor: boolean;
	readonly showVerticalGuide: boolean;
	readonly showHorizontalGuide: boolean;
	readonly tooltipShowDistance: boolean;
	readonly tooltipShowHeight: boolean;
}

/**
 * Maps the live editor binding inputs to the preview-state union.
 *
 * Pure function. Distinguishes Step 3's Case A (no elevation data) and
 * Case C (zero distance) from the generic REST-error state so the
 * editor surfaces the same dedicated warning as the frontend.
 *
 * @since 1.0.0
 *
 * @param mapId                     Current `mapId` attribute.
 * @param mapBlocks                 Every Map block on the page.
 * @param configuredMapBlocks       Configured subset of `mapBlocks`.
 * @param payload                   Cached payload from the REST endpoint.
 * @param isLoading                 Whether the REST fetch is in flight.
 * @param error                     REST error object, or `null`.
 * @param typography                Tick-labels typography forwarded into the
 *                                  healthy state.
 * @param tooltipDistanceTypography Tooltip distance-row typography
 *                                  forwarded into the healthy state
 *                                  (Step 7 pl.3).
 * @param tooltipHeightTypography   Tooltip height-row typography
 *                                  forwarded into the healthy state
 *                                  (Step 7 pl.3).
 * @param cursorToggles             The three `Cursor & guides` toggles from
 *                                  issue #144.
 */
function resolveBinding(
	mapId: string,
	mapBlocks: readonly EditorBlock[],
	configuredMapBlocks: readonly EditorBlock[],
	payload: BoundMapPayload | null,
	isLoading: boolean,
	error: BoundMapPayloadError | null,
	typography: TypographyAttributes,
	tooltipDistanceTypography: TypographyAttributes,
	tooltipHeightTypography: TypographyAttributes,
	cursorToggles: CursorToggleBundle
): BindingResolution {
	// The "auto" sentinel or an empty mapId is the pre-auto-pick state.
	// With 0 configured Maps no candidate exists; render the no-map
	// warning. Otherwise the auto-pick effect is about to fire — render
	// the loading placeholder for this single transient render.
	if ( isAutoMapId( mapId ) ) {
		if ( configuredMapBlocks.length === 0 ) {
			return {
				state: { kind: 'no-map' },
				bindingBroken: false,
			};
		}
		return {
			state: { kind: 'loading' },
			bindingBroken: false,
		};
	}

	// Match against every Map block (configured or not) so the
	// "bound-unconfigured" warning can fire when the user clears the
	// file from the bound Map block while keeping its `mapId`.
	const matched = mapBlocks.find( ( b ) => b.attributes.mapId === mapId );
	if ( ! matched ) {
		return {
			state: { kind: 'bound-deleted' },
			bindingBroken: true,
		};
	}

	const attachmentId =
		typeof matched.attributes.attachmentId === 'number'
			? matched.attributes.attachmentId
			: 0;
	if ( attachmentId <= 0 ) {
		return {
			state: { kind: 'bound-unconfigured' },
			bindingBroken: true,
		};
	}

	// In-flight + missing-payload are the same transient "no data yet"
	// state; both render nothing and let the wrapper hold the slot.
	if ( isLoading ) {
		return {
			state: { kind: 'loading' },
			bindingBroken: false,
		};
	}
	if ( error ) {
		// Surface the underlying error to DevTools — payload-error
		// strips the technical message from the visible UI so editors
		// see one consistent localised string regardless of cause.
		// eslint-disable-next-line no-console
		console.error( 'Elevation: REST payload error', error );
		return {
			state: { kind: 'payload-error' },
			bindingBroken: false,
		};
	}
	if ( ! payload ) {
		return {
			state: { kind: 'loading' },
			bindingBroken: false,
		};
	}

	// Healthy candidate; check the Step 3 degenerate cases before
	// dispatching to the chart.
	const minRaw = payload.statistics.min_elevation;
	const maxRaw = payload.statistics.max_elevation;
	const distanceRaw = payload.statistics.distance;
	if ( minRaw === null || maxRaw === null ) {
		return {
			state: { kind: 'no-elevation-data' },
			bindingBroken: false,
		};
	}
	if ( distanceRaw === null || distanceRaw <= 0 ) {
		return {
			state: { kind: 'zero-distance' },
			bindingBroken: false,
		};
	}

	return {
		state: {
			kind: 'healthy',
			data: {
				minElevation: minRaw,
				maxElevation: maxRaw,
				distance: distanceRaw,
			},
			samples: payload.samples,
			typography,
			tooltipDistanceTypography,
			tooltipHeightTypography,
			showCursor: cursorToggles.showCursor,
			showVerticalGuide: cursorToggles.showVerticalGuide,
			showHorizontalGuide: cursorToggles.showHorizontalGuide,
			tooltipShowDistance: cursorToggles.tooltipShowDistance,
			tooltipShowHeight: cursorToggles.tooltipShowHeight,
		},
		bindingBroken: false,
	};
}

/**
 * Renders the inspector controls and the block's editor wrapper.
 *
 * @since 1.0.0
 *
 * @param props               Gutenberg edit-component props.
 * @param props.attributes    Saved block attribute bag.
 * @param props.setAttributes Standard Gutenberg attribute setter.
 * @param props.clientId      Block client id, used to namespace each
 *                            ToolsPanel's `panelId`.
 */
export function ElevationEdit( {
	attributes,
	setAttributes,
	clientId,
}: BlockEditProps< Record< string, unknown > > ): JSX.Element {
	// Wire the Background colour and the Axis colour to inline CSS
	// custom properties on the wrapper. The chart's two axis lines
	// reference `--kntnt-gpx-blocks-elevation-axis`; future tick marks,
	// tick labels, curve, cursor, and tooltip surfaces follow the same
	// pattern in later steps.
	const bg = usefulValue< string >(
		attributes,
		setAttributes,
		'backgroundColor',
		''
	);
	const axis = usefulValue< string >(
		attributes,
		setAttributes,
		'axisColor',
		''
	);
	const axisLabel = usefulValue< string >(
		attributes,
		setAttributes,
		'axisLabelColor',
		''
	);
	const plotLine = usefulValue< string >(
		attributes,
		setAttributes,
		'plotLineColor',
		''
	);
	const plotFill = usefulValue< string >(
		attributes,
		setAttributes,
		'plotFillColor',
		''
	);
	const cursor = usefulValue< string >(
		attributes,
		setAttributes,
		'cursorColor',
		''
	);
	const tooltipBackground = usefulValue< string >(
		attributes,
		setAttributes,
		'tooltipBackgroundColor',
		''
	);
	const tooltipDistance = usefulValue< string >(
		attributes,
		setAttributes,
		'tooltipDistanceColor',
		''
	);
	const tooltipHeight = usefulValue< string >(
		attributes,
		setAttributes,
		'tooltipHeightColor',
		''
	);
	const inlineStyle: Record< string, string > = {};
	if ( bg.resolved !== '' ) {
		inlineStyle[ '--kntnt-gpx-blocks-elevation-background' ] = bg.resolved;
		inlineStyle.backgroundColor = bg.resolved;
	}
	if ( axis.resolved !== '' ) {
		inlineStyle[ '--kntnt-gpx-blocks-elevation-axis' ] = axis.resolved;
	}
	if ( axisLabel.resolved !== '' ) {
		inlineStyle[ '--kntnt-gpx-blocks-elevation-axis-label' ] =
			axisLabel.resolved;
	}
	if ( plotLine.resolved !== '' ) {
		inlineStyle[ '--kntnt-gpx-blocks-elevation-plot-line' ] =
			plotLine.resolved;
	}
	if ( plotFill.resolved !== '' ) {
		inlineStyle[ '--kntnt-gpx-blocks-elevation-plot-fill' ] =
			plotFill.resolved;
	}
	if ( cursor.resolved !== '' ) {
		inlineStyle[ '--kntnt-gpx-blocks-elevation-cursor' ] = cursor.resolved;
	}
	if ( tooltipBackground.resolved !== '' ) {
		inlineStyle[ '--kntnt-gpx-blocks-elevation-tooltip-background' ] =
			tooltipBackground.resolved;
	}
	if ( tooltipDistance.resolved !== '' ) {
		inlineStyle[ '--kntnt-gpx-blocks-elevation-tooltip-distance' ] =
			tooltipDistance.resolved;
	}
	if ( tooltipHeight.resolved !== '' ) {
		inlineStyle[ '--kntnt-gpx-blocks-elevation-tooltip-height' ] =
			tooltipHeight.resolved;
	}

	// Tick-labels typography. Mirrors Render_Elevation::build_inline_style
	// PHP-side so the editor preview and the server-rendered frontend
	// emit identical custom properties on the wrapper. Each pair maps
	// an attribute name to the CSS custom-property suffix; empty values
	// are omitted so the SCSS rule falls back to `inherit`. Sanitisation
	// is intentionally delegated to PHP — the editor's TypographyToolsPanel
	// already constrains the value space to what Typography_Sanitizer
	// would accept, and trying to mirror the regex set client-side would
	// be both duplication and inheritance-vs-sanitisation surface area.
	const tickLabelMap: ReadonlyArray< readonly [ string, string ] > = [
		[ 'tickLabelFontFamily', 'font-family' ],
		[ 'tickLabelFontSize', 'font-size' ],
		[ 'tickLabelFontWeight', 'font-weight' ],
		[ 'tickLabelFontStyle', 'font-style' ],
		[ 'tickLabelLineHeight', 'line-height' ],
		[ 'tickLabelLetterSpacing', 'letter-spacing' ],
		[ 'tickLabelTextTransform', 'text-transform' ],
		[ 'tickLabelTextDecoration', 'text-decoration' ],
	];
	for ( const [ attrKey, cssSuffix ] of tickLabelMap ) {
		const value = readString( attributes, attrKey );
		if ( value !== '' ) {
			inlineStyle[
				`--kntnt-gpx-blocks-elevation-tick-label-${ cssSuffix }`
			] = value;
		}
	}

	// Tooltip distance and tooltip height typography (Step 7). Two
	// parallel 8-row maps with the same shape as the tick-label loop
	// above; sanitisation is delegated to PHP via Typography_Sanitizer
	// the same way.
	const tooltipTypographyMaps: ReadonlyArray<
		readonly [
			'distance' | 'height',
			ReadonlyArray< readonly [ string, string ] >,
		]
	> = [
		[
			'distance',
			[
				[ 'tooltipDistanceFontFamily', 'font-family' ],
				[ 'tooltipDistanceFontSize', 'font-size' ],
				[ 'tooltipDistanceFontWeight', 'font-weight' ],
				[ 'tooltipDistanceFontStyle', 'font-style' ],
				[ 'tooltipDistanceLineHeight', 'line-height' ],
				[ 'tooltipDistanceLetterSpacing', 'letter-spacing' ],
				[ 'tooltipDistanceTextTransform', 'text-transform' ],
				[ 'tooltipDistanceTextDecoration', 'text-decoration' ],
			],
		],
		[
			'height',
			[
				[ 'tooltipHeightFontFamily', 'font-family' ],
				[ 'tooltipHeightFontSize', 'font-size' ],
				[ 'tooltipHeightFontWeight', 'font-weight' ],
				[ 'tooltipHeightFontStyle', 'font-style' ],
				[ 'tooltipHeightLineHeight', 'line-height' ],
				[ 'tooltipHeightLetterSpacing', 'letter-spacing' ],
				[ 'tooltipHeightTextTransform', 'text-transform' ],
				[ 'tooltipHeightTextDecoration', 'text-decoration' ],
			],
		],
	];
	for ( const [ row, entries ] of tooltipTypographyMaps ) {
		for ( const [ attrKey, cssSuffix ] of entries ) {
			const value = readString( attributes, attrKey );
			if ( value !== '' ) {
				inlineStyle[
					`--kntnt-gpx-blocks-elevation-tooltip-${ row }-${ cssSuffix }`
				] = value;
			}
		}
	}

	// Inject the Step 3 default min-height (15vh) only when the user
	// has not set their own. The condition is centralised in
	// `getDefaultMinHeight()` so editor and PHP stay in lock-step.
	const defaultMinHeight = getDefaultMinHeight( BLOCK_NAME, attributes );
	if ( defaultMinHeight ) {
		inlineStyle.minHeight = defaultMinHeight;
	}

	// Inject the project class so the SCSS rules attach.
	const blockProps = useBlockProps( {
		className: 'kntnt-gpx-blocks-elevation',
		style: inlineStyle as React.CSSProperties,
	} );

	const tooltipShowDistance =
		typeof attributes.tooltipShowDistance === 'boolean'
			? attributes.tooltipShowDistance
			: true;
	const tooltipShowHeight =
		typeof attributes.tooltipShowHeight === 'boolean'
			? attributes.tooltipShowHeight
			: true;

	// Read the three Cursor & guides toggles from issue #144 with the
	// same default semantics block.json declares (cursor on, vertical
	// guide on, horizontal guide off). The master toggle gates the two
	// sub-toggles in the inspector tree and gates the cursor lifecycle
	// in the chart + view layer.
	const showCursor =
		typeof attributes.showCursor === 'boolean'
			? attributes.showCursor
			: true;
	const showVerticalGuide =
		typeof attributes.showVerticalGuide === 'boolean'
			? attributes.showVerticalGuide
			: true;
	const showHorizontalGuide =
		typeof attributes.showHorizontalGuide === 'boolean'
			? attributes.showHorizontalGuide
			: false;

	const mapId =
		typeof attributes.mapId === 'string' ? attributes.mapId : 'auto';

	// Walk the editor block tree to surface configured Map blocks and
	// the picker option list.
	const { mapBlocks, configuredMapBlocks, mapOptions } = useMapBlocks();

	// Auto-pick the topmost configured Map when the binding is still
	// in its pre-pick state.
	useAutoPickMapId(
		mapId,
		configuredMapBlocks,
		setAttributes as ( attrs: { mapId: string } ) => void
	);

	// Resolve the bound Map (if any) and fetch its cached payload.
	const boundForFetch = mapBlocks.find(
		( b ) => b.attributes.mapId === mapId && ! isAutoMapId( mapId )
	);
	const boundAttachmentId =
		boundForFetch &&
		typeof boundForFetch.attributes.attachmentId === 'number'
			? boundForFetch.attributes.attachmentId
			: 0;
	const { data, isLoading, error } = useBoundMapPayload( boundAttachmentId );

	const typography = readTickLabelTypography( attributes );
	const tooltipDistanceTypography = readTooltipRowTypography(
		attributes,
		'tooltipDistance'
	);
	const tooltipHeightTypography = readTooltipRowTypography(
		attributes,
		'tooltipHeight'
	);
	const resolution = resolveBinding(
		mapId,
		mapBlocks,
		configuredMapBlocks,
		data,
		isLoading,
		error,
		typography,
		tooltipDistanceTypography,
		tooltipHeightTypography,
		{
			showCursor,
			showVerticalGuide,
			showHorizontalGuide,
			tooltipShowDistance,
			tooltipShowHeight,
		}
	);

	const showPanel = shouldShowDataSourcePanel(
		configuredMapBlocks.length,
		resolution.bindingBroken
	);

	return (
		<>
			{ showPanel && (
				<InspectorDataSource
					mapId={ mapId }
					mapOptions={ mapOptions }
					bindingBroken={ resolution.bindingBroken }
					onChange={ ( value: string ) =>
						setAttributes( { mapId: value } )
					}
				/>
			) }
			<InspectorControls>
				<PanelBody
					title={ __( 'Cursor & guides', 'kntnt-gpx-blocks' ) }
				>
					<ToggleControl
						label={ __( 'Cursor', 'kntnt-gpx-blocks' ) }
						checked={ showCursor }
						onChange={ ( value: boolean ) =>
							setAttributes( { showCursor: value } )
						}
					/>
					{ showCursor && (
						<>
							<ToggleControl
								label={ __(
									'Vertical guide',
									'kntnt-gpx-blocks'
								) }
								checked={ showVerticalGuide }
								onChange={ ( value: boolean ) =>
									setAttributes( {
										showVerticalGuide: value,
									} )
								}
							/>
							<ToggleControl
								label={ __(
									'Horizontal guide',
									'kntnt-gpx-blocks'
								) }
								checked={ showHorizontalGuide }
								onChange={ ( value: boolean ) =>
									setAttributes( {
										showHorizontalGuide: value,
									} )
								}
							/>
						</>
					) }
				</PanelBody>
				{ showCursor && (
					<PanelBody
						title={ __( 'Tooltip info', 'kntnt-gpx-blocks' ) }
					>
						<ToggleControl
							label={ __( 'Distance', 'kntnt-gpx-blocks' ) }
							checked={ tooltipShowDistance }
							onChange={ ( value: boolean ) =>
								setAttributes( { tooltipShowDistance: value } )
							}
						/>
						<ToggleControl
							label={ __( 'Height', 'kntnt-gpx-blocks' ) }
							checked={ tooltipShowHeight }
							onChange={ ( value: boolean ) =>
								setAttributes( { tooltipShowHeight: value } )
							}
						/>
					</PanelBody>
				) }
			</InspectorControls>
			<InspectorControls group="styles">
				<InspectorColorPanel
					attributes={ attributes }
					setAttributes={ setAttributes }
				/>
				<PanelBody
					title={ __( 'Tick labels', 'kntnt-gpx-blocks' ) }
					initialOpen={ false }
				>
					<TypographyToolsPanel
						title={ __( 'Typography', 'kntnt-gpx-blocks' ) }
						prefix="tickLabel"
						attributes={ attributes }
						setAttributes={ setAttributes }
						defaultVisibility={ {
							size: true,
							appearance: true,
						} }
						panelId={ `${ clientId }-tick-label` }
					/>
				</PanelBody>
				{ showCursor && tooltipShowDistance && (
					<PanelBody
						title={ __( 'Tooltip distance', 'kntnt-gpx-blocks' ) }
						initialOpen={ false }
					>
						<TypographyToolsPanel
							title={ __( 'Typography', 'kntnt-gpx-blocks' ) }
							prefix="tooltipDistance"
							attributes={ attributes }
							setAttributes={ setAttributes }
							defaultVisibility={ {
								size: true,
								appearance: true,
							} }
							panelId={ `${ clientId }-tooltip-distance` }
						/>
					</PanelBody>
				) }
				{ showCursor && tooltipShowHeight && (
					<PanelBody
						title={ __( 'Tooltip height', 'kntnt-gpx-blocks' ) }
						initialOpen={ false }
					>
						<TypographyToolsPanel
							title={ __( 'Typography', 'kntnt-gpx-blocks' ) }
							prefix="tooltipHeight"
							attributes={ attributes }
							setAttributes={ setAttributes }
							defaultVisibility={ {
								size: true,
								appearance: true,
							} }
							panelId={ `${ clientId }-tooltip-height` }
						/>
					</PanelBody>
				) }
			</InspectorControls>
			<InspectorBottomSpacer />
			<div { ...blockProps }>
				<ElevationPreview state={ resolution.state } />
			</div>
		</>
	);
}
