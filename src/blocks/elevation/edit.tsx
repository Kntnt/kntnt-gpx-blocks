/**
 * GPX Elevation block edit component — Step 2 of the rebuild.
 *
 * Orchestrates the binding model fixed by Step 2 of
 * `docs/elevation-rebuild.md`:
 *
 *   1. Walks the editor block tree via `useMapBlocks()` to find every
 *      GPX Map block on the page.
 *   2. Auto-picks the topmost configured Map when `mapId` is empty or
 *      the literal sentinel `"auto"` (via `useAutoPickMapId`).
 *   3. Fetches the bound Map's cached payload through the editor-only
 *      REST endpoint via `useBoundMapPayload`.
 *   4. Renders either the Data Source panel (when ≥ 2 configured Maps,
 *      or the binding is broken AND ≥ 1 configured Map remains) or
 *      keeps it hidden.
 *   5. Renders one of six preview states inside the block wrapper:
 *      `no-map`, `bound-deleted`, `bound-unconfigured`, `loading`,
 *      `error`, or `healthy`.
 *
 * The Step-1 surface (Tooltip info toggles + Color panel + three
 * Typography panels) is preserved verbatim — Step 2 adds binding
 * orchestration and the preview body without modifying anything Step 1
 * established.
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
import { useMapBlocks, type EditorBlock } from './use-map-blocks';
import { isAutoMapId, useAutoPickMapId } from './use-auto-pick-map-id';
import {
	useBoundMapPayload,
	type BoundMapPayload,
	type BoundMapPayloadError,
} from './use-bound-map-payload';
import { pickerLabel, type PickerLabelAttributes } from './picker-label';
import {
	InspectorDataSource,
	shouldShowDataSourcePanel,
} from './inspector-data-source';
import { ElevationPreview, type PreviewState } from './preview';

/**
 * Resolved binding outcome for one render of the Elevation block.
 *
 * Carries the {@link PreviewState} the {@link ElevationPreview} consumes
 * plus a `bindingBroken` flag the Data Source panel's visibility logic
 * needs. The bound block (when one was matched) is surfaced too so the
 * caller can reuse it without re-walking the block tree.
 *
 * @since 1.0.0
 */
interface BindingResolution {
	readonly state: PreviewState;
	readonly bindingBroken: boolean;
	readonly boundBlock: EditorBlock | null;
}

/**
 * Maps the live editor binding inputs (current `mapId`, the block tree,
 * the REST payload state) to the preview-state union the
 * {@link ElevationPreview} component renders.
 *
 * Pure function — exported indirectly via the orchestrator below; the
 * unit-test coverage sits in the per-module tests (`picker-label.test.ts`,
 * `use-map-blocks.test.ts`, `preview.test.tsx`).
 *
 * @since 1.0.0
 *
 * @param mapId               Current `mapId` attribute value.
 * @param mapBlocks           Every Map block on the page (configured or
 *                            not).
 * @param configuredMapBlocks Subset of `mapBlocks` that is eligible to
 *                            be bound (configured and has a `mapId`).
 * @param payload             Cached payload from the REST endpoint, or
 *                            `null` while loading / on error.
 * @param isLoading           Whether the REST fetch is in flight.
 * @param error               REST error object, or `null`.
 * @return Discriminated binding resolution; see {@link BindingResolution}.
 */
function resolveBinding(
	mapId: string,
	mapBlocks: readonly EditorBlock[],
	configuredMapBlocks: readonly EditorBlock[],
	payload: BoundMapPayload | null,
	isLoading: boolean,
	error: BoundMapPayloadError | null
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
				boundBlock: null,
			};
		}
		return {
			state: { kind: 'loading' },
			bindingBroken: false,
			boundBlock: null,
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
			boundBlock: null,
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
			boundBlock: matched,
		};
	}

	// Loading and error states sit between "binding healthy" and "data
	// available" — they are not broken bindings.
	if ( isLoading ) {
		return {
			state: { kind: 'loading' },
			bindingBroken: false,
			boundBlock: matched,
		};
	}
	if ( error ) {
		return {
			state: { kind: 'error', message: error.message },
			bindingBroken: false,
			boundBlock: matched,
		};
	}
	if ( ! payload ) {
		return {
			state: { kind: 'loading' },
			bindingBroken: false,
			boundBlock: matched,
		};
	}

	// Healthy state. The label uses the same three-tier rule + all-map
	// index as the picker entries; min/max come from the cached stats
	// rounded to integers. A track without elevation data lands as the
	// error state — the message is translatable so the editor still
	// shows useful feedback.
	const minRaw = payload.statistics.min_elevation;
	const maxRaw = payload.statistics.max_elevation;
	if ( minRaw === null || maxRaw === null ) {
		return {
			state: {
				kind: 'error',
				message: __(
					'The bound GPX file has no elevation data.',
					'kntnt-gpx-blocks'
				),
			},
			bindingBroken: false,
			boundBlock: matched,
		};
	}
	const indexInAll = mapBlocks.indexOf( matched ) + 1;
	const labelAttrs: PickerLabelAttributes = {
		metadata: matched.attributes.metadata,
		anchor: matched.attributes.anchor,
	};
	return {
		state: {
			kind: 'healthy',
			label: pickerLabel( labelAttrs, indexInAll ),
			min: Math.round( minRaw ),
			max: Math.round( maxRaw ),
		},
		bindingBroken: false,
		boundBlock: matched,
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
	// Wire the only Color attribute Step 1 actually renders. The wrapper
	// emits the CSS custom property `--kntnt-gpx-blocks-elevation-background`
	// for parity with `render.php`'s contract; the direct inline
	// `backgroundColor` ensures the editor preview repaints without a
	// server round-trip.
	const bg = usefulValue< string >(
		attributes,
		setAttributes,
		'backgroundColor',
		''
	);
	const inlineStyle: Record< string, string > = {};
	if ( bg.resolved !== '' ) {
		inlineStyle[ '--kntnt-gpx-blocks-elevation-background' ] = bg.resolved;
		inlineStyle.backgroundColor = bg.resolved;
	}

	// Inject the project class so any future `style.scss` rules attach.
	// The outer wrapper is otherwise managed by core: Dimensions, Border,
	// Box Shadow, and Margin all reach the wrapper through the standard
	// block-supports pipeline merged into `useBlockProps()`.
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
	const mapId =
		typeof attributes.mapId === 'string' ? attributes.mapId : 'auto';

	// Walk the editor block tree to surface configured Map blocks and
	// the picker option list. The hook re-subscribes whenever a Map
	// block is added, removed, configured, or has its `mapId` set.
	const { mapBlocks, configuredMapBlocks, mapOptions } = useMapBlocks();

	// Auto-pick the topmost configured Map when the binding is still
	// in its pre-pick state. The effect re-fires on every render where
	// `mapId` is empty / `"auto"`, so the binding lands at the moment a
	// candidate becomes available — no manual user action required.
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

	const resolution = resolveBinding(
		mapId,
		mapBlocks,
		configuredMapBlocks,
		data,
		isLoading,
		error
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
				<PanelBody title={ __( 'Tooltip info', 'kntnt-gpx-blocks' ) }>
					<ToggleControl
						label={ __( 'Show distance', 'kntnt-gpx-blocks' ) }
						checked={ tooltipShowDistance }
						onChange={ ( value: boolean ) =>
							setAttributes( { tooltipShowDistance: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Show height', 'kntnt-gpx-blocks' ) }
						checked={ tooltipShowHeight }
						onChange={ ( value: boolean ) =>
							setAttributes( { tooltipShowHeight: value } )
						}
					/>
				</PanelBody>
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
			</InspectorControls>
			<div { ...blockProps }>
				<ElevationPreview state={ resolution.state } />
			</div>
		</>
	);
}
