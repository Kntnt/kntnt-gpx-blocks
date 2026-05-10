/**
 * GPX Map edit component.
 *
 * Renders the block representation inside the Gutenberg editor. When the
 * block has no GPX attachment yet, a MediaPlaceholder is shown so the user
 * can pick a .gpx file. Once an attachment is selected, MapEditorPreview
 * mounts a native Leaflet map inside the editor iframe by fetching the
 * cached GeoJSON via the plugin's REST endpoint — the Interactivity API
 * runtime does not bootstrap inside ServerSideRender's injected DOM in the
 * editor, so the editor cannot reuse the frontend view.ts mount path.
 *
 * The useEnsureUniqueMapId hook auto-generates the mapId attribute on insert
 * and resolves collisions when a block is duplicated.
 *
 * @since 1.0.0
 */

import {
	useBlockProps,
	InspectorControls,
	BlockControls,
	MediaPlaceholder,
	MediaReplaceFlow,
	PanelColorSettings,
	useSettings,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalFontFamilyControl as FontFamilyControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalFontAppearanceControl as FontAppearanceControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalLetterSpacingControl as LetterSpacingControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalTextDecorationControl as TextDecorationControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalTextTransformControl as TextTransformControl,
	LineHeightControl,
} from '@wordpress/block-editor';
import {
	PanelBody,
	ToggleControl,
	FontSizePicker,
	SelectControl,
	TextControl,
	ExternalLink,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToolsPanel as ToolsPanel,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToolsPanelItem as ToolsPanelItem,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';
import type { BlockEditProps } from '@wordpress/blocks';

import { useEnsureUniqueMapId } from './use-ensure-unique-map-id';
import {
	MapEditorPreview,
	type EditorOverlayRecord,
	type EditorProviderRecord,
} from './editor-preview';
import { flattenPresets } from '../shared/flatten-presets';
import { substituteTileApiKey } from './tile-key';
import { detectPreviewNotices } from './preview-notices';

// ─── Editor data global (window.kntntGpxBlocks) ─────────────────────────────

/**
 * Editor-only overlay record exposed via `window.kntntGpxBlocks.overlays`.
 *
 * Inlined by `Bootstrap\Editor_Data_Enqueuer` on every editor request as
 * `before` script of the GPX Map block's editor handle. The editor needs
 * URL/attribution/maxZoom so `MapEditorPreview` can mount the overlay
 * directly via `L.tileLayer()`. Subdomains is optional.
 *
 * @since 1.0.0
 */
interface EditorRegistryOverlay {
	readonly label: string;
	readonly url: string;
	readonly attribution: string;
	readonly maxZoom: number;
	readonly subdomains?: readonly string[];
}

/**
 * Editor-only per-style record nested inside each provider's `styles` map.
 *
 * Carries the tile-layer fields the `MapEditorPreview` forwards to
 * `L.tileLayer()` (URL with `{KEY}` left intact, attribution, maxZoom)
 * plus the localised `label` the style dropdown surfaces.
 * `subdomains` is *not* on the style record — it is a provider-level
 * property inherited by every style of that provider whose URL contains
 * `{s}`.
 *
 * @since 1.0.0
 */
interface EditorRegistryStyle {
	readonly label: string;
	readonly url: string;
	readonly attribution: string;
	readonly maxZoom: number;
}

/**
 * Editor-only provider record exposed via `window.kntntGpxBlocks.providers`.
 *
 * Carries the metadata the Inspector needs to drive its UI (`label`,
 * `requiresKey`, `default` style id, optional `signupUrl`, optional
 * `subdomains`) plus the nested `styles` map of per-style records.
 * The per-style URL still contains the literal `{KEY}` placeholder for
 * paid providers — substitution against
 * `attributes.tileApiKeys[ tileProvider ]` happens in `edit.tsx`
 * immediately before the resolved record is handed to the preview,
 * mirroring how `Render_Map` substitutes server-side for the frontend.
 *
 * @since 1.0.0
 */
interface EditorRegistryProvider {
	readonly label: string;
	readonly requiresKey: boolean;
	readonly default: string;
	readonly styles: Readonly< Record< string, EditorRegistryStyle > >;
	readonly signupUrl?: string;
	readonly subdomains?: readonly string[];
}

/**
 * Shape of the editor data global injected by PHP.
 *
 * @since 1.0.0
 */
interface EditorRegistry {
	readonly providers: Readonly< Record< string, EditorRegistryProvider > >;
	readonly overlays: Readonly< Record< string, EditorRegistryOverlay > >;
}

declare global {
	interface Window {
		/**
		 * Validated tile-provider and overlay registries inlined by
		 * `Bootstrap\Editor_Data_Enqueuer` on `enqueue_block_editor_assets`.
		 * Optional in the type so the editor JS stays robust if the inline
		 * script is stripped or this code is reached outside the editor.
		 *
		 * @since 1.0.0
		 */
		kntntGpxBlocks?: EditorRegistry;
	}
}

/**
 * Attributes for the GPX Map block.
 *
 * @since 1.0.0
 */
interface MapAttributes {
	attachmentId: number;
	mapId: string;
	showZoomButtons: boolean;
	showScale: boolean;
	showFullscreen: boolean;
	showDownload: boolean;
	enableDrag: boolean;
	enablePinchZoom: boolean;
	enableDoubleClickZoom: boolean;
	enableKeyboard: boolean;
	trackColor: string;
	trackCursorColor: string;
	waypointColor: string;
	tooltipShowName: boolean;
	tooltipShowDesc: boolean;
	tooltipBackground: string;
	tooltipNameColor: string;
	tooltipNameFontFamily: string;
	tooltipNameFontSize: string;
	tooltipNameFontWeight: string;
	tooltipNameFontStyle: string;
	tooltipNameLineHeight: string;
	tooltipNameLetterSpacing: string;
	tooltipNameTextDecoration: string;
	tooltipNameTextTransform: string;
	tooltipDescColor: string;
	tooltipDescFontFamily: string;
	tooltipDescFontSize: string;
	tooltipDescFontWeight: string;
	tooltipDescFontStyle: string;
	tooltipDescLineHeight: string;
	tooltipDescLetterSpacing: string;
	tooltipDescTextDecoration: string;
	tooltipDescTextTransform: string;
	tileProvider: string;
	tileStyle: string;
	tileApiKeys: Record< string, string >;
	tileOverlays: string[];
	[ key: string ]: unknown;
}

/**
 * Theme typography preset entry shape, as returned by the unified theme
 * settings (`useSettings('typography.fontFamilies')` /
 * `useSettings('typography.fontSizes')`).
 *
 * @since 1.0.0
 */
interface FontFamilyPreset {
	name: string;
	slug: string;
	fontFamily: string;
}
interface FontSizePreset {
	name: string;
	slug: string;
	size: string;
}

/**
 * Media object shape returned by MediaPlaceholder's onSelect callback.
 *
 * @since 1.0.0
 */
interface MediaObject {
	id: number;
	url: string;
	[ key: string ]: unknown;
}

/**
 * Per-aspect setter signature passed into the typography panel renderer. The
 * panel writes one or more named aspects per call; unset aspects retain their
 * previous value. The empty string is the canonical "unset" marker.
 *
 * @since 1.0.0
 */
type SetTypography = ( values: {
	fontFamily?: string;
	fontSize?: string;
	fontWeight?: string;
	fontStyle?: string;
	lineHeight?: string;
	letterSpacing?: string;
	textDecoration?: string;
	textTransform?: string;
} ) => void;

/**
 * Renders a unified Typography ToolsPanel matching the surface used by core
 * Paragraph/Group blocks: a per-aspect dropdown menu lets the editor enable or
 * disable each aspect individually, and "Reset all" returns every aspect to
 * the inherited theme default.
 *
 * The panel exposes the seven aspects core's standard Typography panel
 * surfaces — Font (family), Size, Appearance (weight + style combined), Line
 * height, Letter spacing, Decoration, and Letter case — written into the
 * caller-provided attribute group via `setTypography`. Each aspect can be
 * enabled or disabled individually; an unset aspect reads as "Default" and
 * inherits from the surrounding theme.
 *
 * @since 1.0.0
 *
 * @param {Object}             props                Component props.
 * @param {string}             props.label          Localised panel title.
 * @param {string}             props.fontFamily     Current font-family value.
 * @param {string}             props.fontSize       Current font-size value.
 * @param {string}             props.fontWeight     Current font-weight value.
 * @param {string}             props.fontStyle      Current font-style value.
 * @param {string}             props.lineHeight     Current line-height value.
 * @param {string}             props.letterSpacing  Current letter-spacing value.
 * @param {string}             props.textDecoration Current text-decoration value.
 * @param {string}             props.textTransform  Current text-transform value.
 * @param {FontFamilyPreset[]} props.fontFamilies   Theme font-family presets.
 * @param {FontSizePreset[]}   props.fontSizes      Theme font-size presets.
 * @param {SetTypography}      props.setTypography  Setter callback.
 */
function TypographyToolsPanel( {
	label,
	fontFamily,
	fontSize,
	fontWeight,
	fontStyle,
	lineHeight,
	letterSpacing,
	textDecoration,
	textTransform,
	fontFamilies,
	fontSizes,
	setTypography,
}: {
	label: string;
	fontFamily: string;
	fontSize: string;
	fontWeight: string;
	fontStyle: string;
	lineHeight: string;
	letterSpacing: string;
	textDecoration: string;
	textTransform: string;
	fontFamilies: FontFamilyPreset[];
	fontSizes: FontSizePreset[];
	setTypography: SetTypography;
} ): JSX.Element {
	const hasAppearance = fontWeight !== '' || fontStyle !== '';

	return (
		// @ts-ignore — ToolsPanel's typings lag the runtime API.
		<ToolsPanel
			label={ label }
			resetAll={ () =>
				setTypography( {
					fontFamily: '',
					fontSize: '',
					fontWeight: '',
					fontStyle: '',
					lineHeight: '',
					letterSpacing: '',
					textDecoration: '',
					textTransform: '',
				} )
			}
		>
			{ /* @ts-ignore — ToolsPanelItem's typings lag the runtime API. */ }
			<ToolsPanelItem
				hasValue={ () => fontFamily !== '' }
				label={ __( 'Font', 'kntnt-gpx-blocks' ) }
				onDeselect={ () => setTypography( { fontFamily: '' } ) }
				isShownByDefault
			>
				<FontFamilyControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					value={ fontFamily }
					fontFamilies={ fontFamilies }
					onChange={ ( value: string | undefined ) =>
						setTypography( { fontFamily: value ?? '' } )
					}
				/>
			</ToolsPanelItem>
			{ /* @ts-ignore — ToolsPanelItem's typings lag the runtime API. */ }
			<ToolsPanelItem
				hasValue={ () => fontSize !== '' }
				label={ __( 'Size', 'kntnt-gpx-blocks' ) }
				onDeselect={ () => setTypography( { fontSize: '' } ) }
				isShownByDefault
			>
				<FontSizePicker
					__next40pxDefaultSize
					value={ fontSize || undefined }
					fontSizes={ fontSizes }
					onChange={ ( value: number | string | undefined ) =>
						setTypography( {
							fontSize:
								value !== undefined && value !== ''
									? String( value )
									: '',
						} )
					}
					withReset={ false }
				/>
			</ToolsPanelItem>
			{ /* @ts-ignore — ToolsPanelItem's typings lag the runtime API. */ }
			<ToolsPanelItem
				hasValue={ () => hasAppearance }
				label={ __( 'Appearance', 'kntnt-gpx-blocks' ) }
				onDeselect={ () =>
					setTypography( { fontWeight: '', fontStyle: '' } )
				}
				isShownByDefault
			>
				<FontAppearanceControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					hasFontWeights
					hasFontStyles
					value={ {
						fontWeight: fontWeight || undefined,
						fontStyle: fontStyle || undefined,
					} }
					onChange={ ( value: {
						fontWeight?: string;
						fontStyle?: string;
					} ) =>
						setTypography( {
							fontWeight: value?.fontWeight ?? '',
							fontStyle: value?.fontStyle ?? '',
						} )
					}
				/>
			</ToolsPanelItem>
			{ /* @ts-ignore — ToolsPanelItem's typings lag the runtime API. */ }
			<ToolsPanelItem
				hasValue={ () => lineHeight !== '' }
				label={ __( 'Line height', 'kntnt-gpx-blocks' ) }
				onDeselect={ () => setTypography( { lineHeight: '' } ) }
				isShownByDefault={ false }
			>
				<LineHeightControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					__unstableInputWidth="auto"
					value={ lineHeight }
					onChange={ ( value: string | undefined ) =>
						setTypography( { lineHeight: value ?? '' } )
					}
				/>
			</ToolsPanelItem>
			{ /* @ts-ignore — ToolsPanelItem's typings lag the runtime API. */ }
			<ToolsPanelItem
				hasValue={ () => letterSpacing !== '' }
				label={ __( 'Letter spacing', 'kntnt-gpx-blocks' ) }
				onDeselect={ () => setTypography( { letterSpacing: '' } ) }
				isShownByDefault={ false }
			>
				<LetterSpacingControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					value={ letterSpacing }
					onChange={ ( value: string | undefined ) =>
						setTypography( { letterSpacing: value ?? '' } )
					}
				/>
			</ToolsPanelItem>
			{ /* @ts-ignore — ToolsPanelItem's typings lag the runtime API. */ }
			<ToolsPanelItem
				hasValue={ () => textDecoration !== '' }
				label={ __( 'Decoration', 'kntnt-gpx-blocks' ) }
				onDeselect={ () => setTypography( { textDecoration: '' } ) }
				isShownByDefault={ false }
			>
				<TextDecorationControl
					value={ textDecoration }
					onChange={ ( value: string | undefined ) =>
						setTypography( { textDecoration: value ?? '' } )
					}
				/>
			</ToolsPanelItem>
			{ /* @ts-ignore — ToolsPanelItem's typings lag the runtime API. */ }
			<ToolsPanelItem
				hasValue={ () => textTransform !== '' }
				label={ __( 'Letter case', 'kntnt-gpx-blocks' ) }
				onDeselect={ () => setTypography( { textTransform: '' } ) }
				isShownByDefault={ false }
			>
				<TextTransformControl
					value={ textTransform }
					onChange={ ( value: string | undefined ) =>
						setTypography( { textTransform: value ?? '' } )
					}
				/>
			</ToolsPanelItem>
		</ToolsPanel>
	);
}

/**
 * Renders the "Overlays" inspector panel when the registry is non-empty.
 *
 * Reads the validated overlay registry from `window.kntntGpxBlocks.overlays`
 * (populated by `Bootstrap\Editor_Data_Enqueuer`) and renders one
 * `ToggleControl` per id. The toggle's checked state mirrors whether the id
 * is present in `attributes.tileOverlays`; toggling adds or removes the id
 * from the array. When the registry is empty (e.g. a site builder dropped
 * every default overlay via the `kntnt_gpx_blocks_tile_overlays` filter)
 * the panel collapses to nothing — the issue spec calls for "no PanelBody",
 * not an empty panel.
 *
 * @since 1.0.0
 *
 * @param props             Component props.
 * @param props.selectedIds Current `tileOverlays` array.
 * @param props.onChange    Setter that writes the new array.
 */
function OverlaysPanel( {
	selectedIds,
	onChange,
}: {
	selectedIds: string[];
	onChange: ( next: string[] ) => void;
} ): JSX.Element | null {
	const overlays = window.kntntGpxBlocks?.overlays ?? {};
	const ids = Object.keys( overlays );
	if ( ids.length === 0 ) {
		return null;
	}

	return (
		<PanelBody title={ __( 'Overlays', 'kntnt-gpx-blocks' ) }>
			{ ids.map( ( id ) => {
				const overlay = overlays[ id ];
				if ( ! overlay ) {
					return null;
				}
				const checked = selectedIds.includes( id );
				return (
					<ToggleControl
						key={ id }
						label={ overlay.label }
						checked={ checked }
						onChange={ ( next: boolean ) => {
							if ( next ) {
								if ( checked ) {
									return;
								}
								onChange( [ ...selectedIds, id ] );
							} else {
								onChange(
									selectedIds.filter(
										( existing ) => existing !== id
									)
								);
							}
						} }
					/>
				);
			} ) }
		</PanelBody>
	);
}

/**
 * Resolves a list of saved overlay ids to runtime records for the editor preview.
 *
 * Mirrors the server-side `Tile_Layer_Registry::resolve_overlays()` contract
 * narrowed to what the editor preview needs: only the `id` survives because
 * URL/attribution/maxZoom/subdomains are not exposed to the editor. Unknown
 * ids are dropped silently — the editor is not the place to surface
 * mis-configurations; PHP logs them on the rendered page.
 *
 * @since 1.0.0
 *
 * @param ids - Overlay ids from `attributes.tileOverlays`.
 * @return Resolved records in the input order, with unknown ids removed.
 */
function resolveOverlaysForPreview(
	ids: readonly string[]
): EditorOverlayRecord[] {
	const overlays = window.kntntGpxBlocks?.overlays ?? {};
	const out: EditorOverlayRecord[] = [];
	for ( const id of ids ) {
		const record = overlays[ id ];
		if ( ! record ) {
			continue;
		}
		const entry: EditorOverlayRecord = {
			id,
			url: record.url,
			attribution: record.attribution,
			maxZoom: record.maxZoom,
		};
		if ( record.subdomains && record.subdomains.length > 0 ) {
			entry.subdomains = [ ...record.subdomains ];
		}
		out.push( entry );
	}
	return out;
}

/**
 * Resolves the saved (provider id, style id) pair and per-block API key to
 * the runtime record the editor preview mounts.
 *
 * Mirrors `Tile_Layer_Registry::resolve_provider()` on the JS side: the
 * lookup falls back to the canonical OpenStreetMap provider when the
 * saved provider id is not in the editor-data global, and to the
 * provider's own `default` style when the saved style id is unknown
 * within a known provider. `{KEY}` is substituted with the per-block key
 * just like the server does for the frontend. When the registry is
 * missing entirely (the inline script was stripped), the helper returns
 * `null` and the caller renders without a tile layer — the editor
 * preview's single useEffect handles the null-provider case defensively
 * rather than crashing.
 *
 * @since 1.0.0
 *
 * @param providerId - Saved `tileProvider` attribute.
 * @param styleId    - Saved `tileStyle` attribute.
 * @param apiKey     - Per-provider API key looked up from
 *                   `attributes.tileApiKeys[providerId]` (may be empty for
 *                   paid providers; the resulting URL will produce
 *                   `null` and the preview ships polyline-only).
 * @return Resolved record with `{KEY}` substituted, or `null` when the
 *         registry global is absent or the resolved provider requires a
 *         key the editor has not supplied.
 */
function resolveProviderForPreview(
	providerId: string,
	styleId: string,
	apiKey: string
): EditorProviderRecord | null {
	const providers = window.kntntGpxBlocks?.providers ?? {};

	// Resolve the provider record with fall-back to the global fallback.
	const provider =
		providers[ providerId ] ?? providers[ FALLBACK_PROVIDER_ID ] ?? null;
	if ( ! provider ) {
		return null;
	}

	// Polyline-only gate: when the resolved provider requires a key and the
	// per-provider key is empty (or whitespace-only), do not return a
	// preview record at all. Returning `null` makes the preview's base-tile
	// useEffect skip the tile layer entirely, mirroring the frontend
	// `Render_Map` PHP gate where the URL is nulled in state.
	if ( provider.requiresKey && apiKey.trim() === '' ) {
		return null;
	}

	// Resolve the style record within the provider with fall-back to the
	// provider's own default. A `default` that itself does not resolve
	// (defensive — should not happen post-validation) falls through to
	// `null`, treating the preview as if the provider were missing.
	const style =
		provider.styles[ styleId ] ?? provider.styles[ provider.default ];
	if ( ! style ) {
		return null;
	}

	const url = provider.requiresKey
		? substituteTileApiKey( style.url, apiKey )
		: style.url;

	const out: EditorProviderRecord = {
		url,
		attribution: style.attribution,
		maxZoom: style.maxZoom,
	};
	if ( provider.subdomains && provider.subdomains.length > 0 ) {
		out.subdomains = [ ...provider.subdomains ];
	}
	return out;
}

/**
 * Identifier of the canonical fallback provider.
 *
 * Mirrors `Tile_Layer_Registry::FALLBACK_PROVIDER_ID` — kept as a string
 * literal here rather than imported because the editor JS has no PHP
 * surface to reach for it. Drifting from the PHP constant is unlikely but
 * would only affect the editor's fallback path; the frontend renders the
 * resolved provider PHP shipped on `state.tileProvider`.
 *
 * @since 1.0.0
 */
const FALLBACK_PROVIDER_ID = 'openstreetmap';

/**
 * Renders the "Tiles" inspector panel.
 *
 * Surfaces a two-step base-tile choice — provider first, then style
 * within that provider — plus a conditional API-key field driven by the
 * resolved provider's `requiresKey` flag. The dropdown options are
 * populated from `window.kntntGpxBlocks.providers` (see
 * `Bootstrap\Editor_Data_Enqueuer`); when the global is missing or empty
 * the panel still renders both `SelectControl`s so the editor surfaces a
 * clear "nothing here" state rather than vanishing silently. The style
 * dropdown is always rendered, even when the selected provider declares
 * a single style — the affordance is consistent across providers, and
 * the user sees what the canonical style for the provider is named.
 *
 * Stale-state surfacing — when the saved `tileProvider` or `tileStyle`
 * is no longer in the validated registry (filter dropped it, or a stale
 * post-content survived a registry change), the affected dropdown
 * prepends a placeholder option labelled with the orphan id itself so
 * the editor reflects the current persisted state without silently
 * rewriting it. The user picks a real option to clear the placeholder.
 *
 * @since 1.0.0
 *
 * @param props          Component props.
 * @param props.provider Current `tileProvider` attribute.
 * @param props.style    Current `tileStyle` attribute.
 * @param props.apiKey   Per-provider API key for the currently-selected
 *                       provider, looked up from
 *                       `tileApiKeys[ tileProvider ]`.
 * @param props.onChange Setter — receives the new provider id, style id,
 *                       and/or key.
 */
function TilesPanel( {
	provider,
	style,
	apiKey,
	onChange,
}: {
	provider: string;
	style: string;
	apiKey: string;
	onChange: ( next: {
		provider?: string;
		style?: string;
		apiKey?: string;
	} ) => void;
} ): JSX.Element {
	const providers = window.kntntGpxBlocks?.providers ?? {};
	const providerIds = Object.keys( providers );
	const selectedProvider = providers[ provider ] ?? null;

	// Build the provider-dropdown options. When the saved provider id is
	// an orphan (no longer in the registry), prepend it as a placeholder
	// option labelled with the id itself.
	const providerOptions = providerIds.map( ( id ) => ( {
		value: id,
		label: providers[ id ]?.label ?? id,
	} ) );
	if ( provider !== '' && ! providers[ provider ] ) {
		providerOptions.unshift( { value: provider, label: provider } );
	}

	// Build the style-dropdown options against the selected provider's
	// `styles` map. When the selected provider is itself an orphan, the
	// style dropdown collapses to just the orphan-id placeholder (or
	// nothing when the saved style is empty too).
	const styleEntries = selectedProvider
		? Object.entries( selectedProvider.styles )
		: [];
	const styleOptions = styleEntries.map( ( [ id, record ] ) => ( {
		value: id,
		label: record.label,
	} ) );
	if (
		style !== '' &&
		( ! selectedProvider || ! selectedProvider.styles[ style ] )
	) {
		styleOptions.unshift( { value: style, label: style } );
	}

	return (
		<PanelBody title={ __( 'Tiles', 'kntnt-gpx-blocks' ) }>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Provider', 'kntnt-gpx-blocks' ) }
				value={ provider }
				options={ providerOptions }
				onChange={ ( next: string ) => {
					// Switching providers resets the style to the new
					// provider's `default` unconditionally — there is no
					// per-provider style memory. Switching back to a
					// previously-used provider does *not* restore a
					// previously-chosen style on that provider; it always
					// lands on the provider's default. The API key, by
					// contrast, *is* per-provider in attribute storage
					// (`tileApiKeys[ providerId ]`), so the inspector
					// surfaces whichever key the new provider has stored.
					const nextProvider = providers[ next ];
					const nextStyle = nextProvider?.default ?? '';
					onChange( { provider: next, style: nextStyle } );
				} }
			/>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Style', 'kntnt-gpx-blocks' ) }
				value={ style }
				options={ styleOptions }
				onChange={ ( next: string ) => onChange( { style: next } ) }
			/>
			{ selectedProvider?.requiresKey && (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'API key', 'kntnt-gpx-blocks' ) }
					value={ apiKey }
					onChange={ ( next: string ) =>
						onChange( { apiKey: next } )
					}
					help={
						selectedProvider.signupUrl ? (
							<>
								{ __(
									'This provider requires an API key.',
									'kntnt-gpx-blocks'
								) }{ ' ' }
								<ExternalLink
									href={ selectedProvider.signupUrl }
								>
									{ __( 'Get one', 'kntnt-gpx-blocks' ) }
								</ExternalLink>
							</>
						) : (
							__(
								"This provider requires an API key. See the provider's documentation.",
								'kntnt-gpx-blocks'
							)
						)
					}
				/>
			) }
		</PanelBody>
	);
}

/**
 * Editor preview for the GPX Map block.
 *
 * Shows a MediaPlaceholder when no attachment is selected; otherwise
 * delegates to MapEditorPreview which mounts Leaflet directly via React.
 * InspectorControls always render regardless of attachment state so the
 * Controls panel is accessible from the moment the block is inserted.
 *
 * @since 1.0.0
 *
 * @param {Object}   props               Standard Gutenberg block edit props.
 * @param {string}   props.clientId      This block's unique client ID.
 * @param {Object}   props.attributes    Current block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 */
export const MapEdit = ( {
	clientId,
	attributes,
	setAttributes,
}: BlockEditProps< MapAttributes > ): JSX.Element => {
	// Ensure this block's mapId is non-empty and unique across the post.
	useEnsureUniqueMapId( clientId, attributes, setAttributes );

	// Destructure all attributes before use so useBlockProps can read colour
	// values when it injects the instant-preview CSS variables. mapId is
	// managed by useEnsureUniqueMapId above and is not consumed here directly.
	const {
		attachmentId,
		showZoomButtons,
		showScale,
		showFullscreen,
		showDownload,
		enableDrag,
		enablePinchZoom,
		enableDoubleClickZoom,
		enableKeyboard,
		trackColor,
		trackCursorColor,
		waypointColor,
		tooltipShowName,
		tooltipShowDesc,
		tooltipBackground,
		tooltipNameColor,
		tooltipNameFontFamily,
		tooltipNameFontSize,
		tooltipNameFontWeight,
		tooltipNameFontStyle,
		tooltipNameLineHeight,
		tooltipNameLetterSpacing,
		tooltipNameTextDecoration,
		tooltipNameTextTransform,
		tooltipDescColor,
		tooltipDescFontFamily,
		tooltipDescFontSize,
		tooltipDescFontWeight,
		tooltipDescFontStyle,
		tooltipDescLineHeight,
		tooltipDescLetterSpacing,
		tooltipDescTextDecoration,
		tooltipDescTextTransform,
		tileProvider,
		tileStyle,
		tileApiKeys,
		tileOverlays,
	} = attributes;

	// Resolve the attached media's source URL so MediaReplaceFlow can label
	// the toolbar button with the current filename. The media object may be
	// undefined while core/core-data is still resolving the attachment, in
	// which case the toolbar button degrades gracefully to the generic label.
	const mediaURL = useSelect(
		( select ) => {
			if ( attachmentId === 0 ) {
				return undefined;
			}
			const { getMedia } = select( coreStore ) as {
				getMedia: ( id: number ) => { source_url?: string } | undefined;
			};
			return getMedia( attachmentId )?.source_url;
		},
		[ attachmentId ]
	);

	// Pull the merged theme typography presets so the unified Typography
	// panel exposes the same Standard/preset choices as core Paragraph/Group.
	// useSettings returns the origin-keyed `{ default, theme, custom }` shape
	// for multi-origin settings, which the underlying controls iterate with
	// `.map()` — flatten to a plain array before forwarding.
	const [ themeFontFamilies, themeFontSizes ] = useSettings(
		'typography.fontFamilies',
		'typography.fontSizes'
	);
	const fontFamilies =
		flattenPresets< FontFamilyPreset >( themeFontFamilies );
	const fontSizes = flattenPresets< FontSizePreset >( themeFontSizes );

	// Inject the project class so the shared style.scss rules (layout
	// baseline, focus styles, hit-band styling, tooltip styling, …) apply to
	// the editor wrapper exactly as they do to the frontend wrapper that
	// `Render_Map` produces via `get_block_wrapper_attributes()`. Dimensions
	// (`aspect-ratio`, `min-height`) come from core's `dimensions` block
	// supports — `useBlockProps()` already merges them into the inline style
	// it returns. The track and cursor colour custom properties are added
	// here so canvas-painted Leaflet polylines that read them via CSS
	// inheritance see the editor's current colour-picker values. The tooltip
	// custom properties feed the floating waypoint-info preview rendered
	// inside `MapEditorPreview` so its colours and typography update live
	// as the editor adjusts the inspector controls.
	const blockProps = useBlockProps( {
		className: 'kntnt-gpx-blocks-map',
		style: {
			...( trackColor
				? { '--kntnt-gpx-blocks-track-color': trackColor }
				: {} ),
			...( trackCursorColor
				? { '--kntnt-gpx-blocks-track-cursor-color': trackCursorColor }
				: {} ),
			...( waypointColor
				? { '--kntnt-gpx-blocks-waypoint-color': waypointColor }
				: {} ),
			...( tooltipBackground
				? { '--kntnt-gpx-blocks-tooltip-bg': tooltipBackground }
				: {} ),
			...( tooltipNameColor
				? { '--kntnt-gpx-blocks-tooltip-name-color': tooltipNameColor }
				: {} ),
			...( tooltipNameFontFamily
				? {
						'--kntnt-gpx-blocks-tooltip-name-font-family':
							tooltipNameFontFamily,
				  }
				: {} ),
			...( tooltipNameFontSize
				? {
						'--kntnt-gpx-blocks-tooltip-name-font-size':
							tooltipNameFontSize,
				  }
				: {} ),
			...( tooltipNameFontWeight
				? {
						'--kntnt-gpx-blocks-tooltip-name-font-weight':
							tooltipNameFontWeight,
				  }
				: {} ),
			...( tooltipNameFontStyle
				? {
						'--kntnt-gpx-blocks-tooltip-name-font-style':
							tooltipNameFontStyle,
				  }
				: {} ),
			...( tooltipNameLineHeight
				? {
						'--kntnt-gpx-blocks-tooltip-name-line-height':
							tooltipNameLineHeight,
				  }
				: {} ),
			...( tooltipNameLetterSpacing
				? {
						'--kntnt-gpx-blocks-tooltip-name-letter-spacing':
							tooltipNameLetterSpacing,
				  }
				: {} ),
			...( tooltipNameTextDecoration
				? {
						'--kntnt-gpx-blocks-tooltip-name-text-decoration':
							tooltipNameTextDecoration,
				  }
				: {} ),
			...( tooltipNameTextTransform
				? {
						'--kntnt-gpx-blocks-tooltip-name-text-transform':
							tooltipNameTextTransform,
				  }
				: {} ),
			...( tooltipDescColor
				? { '--kntnt-gpx-blocks-tooltip-desc-color': tooltipDescColor }
				: {} ),
			...( tooltipDescFontFamily
				? {
						'--kntnt-gpx-blocks-tooltip-desc-font-family':
							tooltipDescFontFamily,
				  }
				: {} ),
			...( tooltipDescFontSize
				? {
						'--kntnt-gpx-blocks-tooltip-desc-font-size':
							tooltipDescFontSize,
				  }
				: {} ),
			...( tooltipDescFontWeight
				? {
						'--kntnt-gpx-blocks-tooltip-desc-font-weight':
							tooltipDescFontWeight,
				  }
				: {} ),
			...( tooltipDescFontStyle
				? {
						'--kntnt-gpx-blocks-tooltip-desc-font-style':
							tooltipDescFontStyle,
				  }
				: {} ),
			...( tooltipDescLineHeight
				? {
						'--kntnt-gpx-blocks-tooltip-desc-line-height':
							tooltipDescLineHeight,
				  }
				: {} ),
			...( tooltipDescLetterSpacing
				? {
						'--kntnt-gpx-blocks-tooltip-desc-letter-spacing':
							tooltipDescLetterSpacing,
				  }
				: {} ),
			...( tooltipDescTextDecoration
				? {
						'--kntnt-gpx-blocks-tooltip-desc-text-decoration':
							tooltipDescTextDecoration,
				  }
				: {} ),
			...( tooltipDescTextTransform
				? {
						'--kntnt-gpx-blocks-tooltip-desc-text-transform':
							tooltipDescTextTransform,
				  }
				: {} ),
		} as React.CSSProperties,
	} );

	// Show the media picker until the user selects a .gpx attachment.
	if ( attachmentId === 0 ) {
		return (
			<div { ...blockProps }>
				<MediaPlaceholder
					icon="location-alt"
					labels={ {
						title: __( 'GPX Map', 'kntnt-gpx-blocks' ),
						instructions: __(
							'Upload a GPX file or pick one from the media library.',
							'kntnt-gpx-blocks'
						),
					} }
					accept=".gpx"
					allowedTypes={ [ 'application/gpx+xml' ] }
					onSelect={ ( media: MediaObject ) => {
						setAttributes( { attachmentId: media.id } );
					} }
				/>
			</div>
		);
	}

	// Render the inspector controls and the editor-side React preview once a
	// GPX file is attached. The preview component renders any error notice
	// inline; no separate Notice in the inspector is needed. The toolbar
	// surfaces a Replace flow (Media Library + Upload tabs only — no URL,
	// no Reset) so the editor can swap the .gpx without losing any other
	// attribute; only `attachmentId` is written by the onSelect callback.
	return (
		<>
			<BlockControls group="other">
				<MediaReplaceFlow
					mediaId={ attachmentId }
					mediaURL={ mediaURL }
					accept=".gpx"
					allowedTypes={ [ 'application/gpx+xml' ] }
					onSelect={ ( media: MediaObject ) => {
						setAttributes( { attachmentId: media.id } );
					} }
				/>
			</BlockControls>
			<InspectorControls>
				<PanelBody title={ __( 'Controls', 'kntnt-gpx-blocks' ) }>
					<ToggleControl
						label={ __( 'Zoom buttons', 'kntnt-gpx-blocks' ) }
						checked={ showZoomButtons }
						onChange={ ( value ) =>
							setAttributes( { showZoomButtons: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Scale', 'kntnt-gpx-blocks' ) }
						checked={ showScale }
						onChange={ ( value ) =>
							setAttributes( { showScale: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Fullscreen button', 'kntnt-gpx-blocks' ) }
						checked={ showFullscreen }
						onChange={ ( value ) =>
							setAttributes( { showFullscreen: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Download GPX button',
							'kntnt-gpx-blocks'
						) }
						checked={ showDownload }
						onChange={ ( value ) =>
							setAttributes( { showDownload: value } )
						}
					/>
				</PanelBody>
				<PanelBody title={ __( 'Interactions', 'kntnt-gpx-blocks' ) }>
					<ToggleControl
						label={ __( 'Drag to pan', 'kntnt-gpx-blocks' ) }
						checked={ enableDrag }
						onChange={ ( value ) =>
							setAttributes( { enableDrag: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Pinch zoom', 'kntnt-gpx-blocks' ) }
						checked={ enablePinchZoom }
						onChange={ ( value ) =>
							setAttributes( { enablePinchZoom: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Double-click zoom', 'kntnt-gpx-blocks' ) }
						checked={ enableDoubleClickZoom }
						onChange={ ( value ) =>
							setAttributes( { enableDoubleClickZoom: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Keyboard navigation',
							'kntnt-gpx-blocks'
						) }
						checked={ enableKeyboard }
						onChange={ ( value ) =>
							setAttributes( { enableKeyboard: value } )
						}
					/>
				</PanelBody>
				<PanelBody title={ __( 'Waypoint info', 'kntnt-gpx-blocks' ) }>
					<ToggleControl
						label={ __( 'Show name', 'kntnt-gpx-blocks' ) }
						checked={ tooltipShowName }
						onChange={ ( value ) =>
							setAttributes( { tooltipShowName: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Show description', 'kntnt-gpx-blocks' ) }
						checked={ tooltipShowDesc }
						onChange={ ( value ) =>
							setAttributes( { tooltipShowDesc: value } )
						}
					/>
				</PanelBody>
				<TilesPanel
					provider={ tileProvider }
					style={ tileStyle }
					apiKey={ tileApiKeys?.[ tileProvider ] ?? '' }
					onChange={ ( next ) => {
						const update: Partial< MapAttributes > = {};
						if ( next.provider !== undefined ) {
							update.tileProvider = next.provider;
						}
						if ( next.style !== undefined ) {
							update.tileStyle = next.style;
						}
						if ( next.apiKey !== undefined ) {
							update.tileApiKeys = {
								...( tileApiKeys ?? {} ),
								[ tileProvider ]: next.apiKey,
							};
						}
						setAttributes( update );
					} }
				/>
				<OverlaysPanel
					selectedIds={ tileOverlays }
					onChange={ ( next ) =>
						setAttributes( { tileOverlays: next } )
					}
				/>
			</InspectorControls>
			<InspectorControls group="styles">
				{ /* @ts-ignore — PanelColorSettings is exported from @wordpress/block-editor but its typings lag behind. */ }
				<PanelColorSettings
					title={ __( 'Color', 'kntnt-gpx-blocks' ) }
					enableAlpha
					colorSettings={ [
						{
							value: trackColor,
							onChange: ( value: string | undefined ) =>
								setAttributes( { trackColor: value ?? '' } ),
							label: __( 'Track', 'kntnt-gpx-blocks' ),
						},
						{
							value: trackCursorColor,
							onChange: ( value: string | undefined ) =>
								setAttributes( {
									trackCursorColor: value ?? '',
								} ),
							label: __( 'Cursor', 'kntnt-gpx-blocks' ),
						},
						{
							value: waypointColor,
							onChange: ( value: string | undefined ) =>
								setAttributes( { waypointColor: value ?? '' } ),
							label: __( 'Marker', 'kntnt-gpx-blocks' ),
						},
						{
							value: tooltipBackground,
							onChange: ( value: string | undefined ) =>
								setAttributes( {
									tooltipBackground: value ?? '',
								} ),
							label: __(
								'Waypoint background',
								'kntnt-gpx-blocks'
							),
						},
						{
							value: tooltipNameColor,
							onChange: ( value: string | undefined ) =>
								setAttributes( {
									tooltipNameColor: value ?? '',
								} ),
							label: __( 'Waypoint name', 'kntnt-gpx-blocks' ),
						},
						{
							value: tooltipDescColor,
							onChange: ( value: string | undefined ) =>
								setAttributes( {
									tooltipDescColor: value ?? '',
								} ),
							label: __(
								'Waypoint description',
								'kntnt-gpx-blocks'
							),
						},
					] }
				/>
				<PanelBody
					title={ __( 'Waypoint name', 'kntnt-gpx-blocks' ) }
					initialOpen={ false }
				>
					<TypographyToolsPanel
						label={ __( 'Typography', 'kntnt-gpx-blocks' ) }
						fontFamily={ tooltipNameFontFamily }
						fontSize={ tooltipNameFontSize }
						fontWeight={ tooltipNameFontWeight }
						fontStyle={ tooltipNameFontStyle }
						lineHeight={ tooltipNameLineHeight }
						letterSpacing={ tooltipNameLetterSpacing }
						textDecoration={ tooltipNameTextDecoration }
						textTransform={ tooltipNameTextTransform }
						fontFamilies={ fontFamilies }
						fontSizes={ fontSizes }
						setTypography={ ( values ) => {
							const next: Partial< MapAttributes > = {};
							if ( values.fontFamily !== undefined ) {
								next.tooltipNameFontFamily = values.fontFamily;
							}
							if ( values.fontSize !== undefined ) {
								next.tooltipNameFontSize = values.fontSize;
							}
							if ( values.fontWeight !== undefined ) {
								next.tooltipNameFontWeight = values.fontWeight;
							}
							if ( values.fontStyle !== undefined ) {
								next.tooltipNameFontStyle = values.fontStyle;
							}
							if ( values.lineHeight !== undefined ) {
								next.tooltipNameLineHeight = values.lineHeight;
							}
							if ( values.letterSpacing !== undefined ) {
								next.tooltipNameLetterSpacing =
									values.letterSpacing;
							}
							if ( values.textDecoration !== undefined ) {
								next.tooltipNameTextDecoration =
									values.textDecoration;
							}
							if ( values.textTransform !== undefined ) {
								next.tooltipNameTextTransform =
									values.textTransform;
							}
							setAttributes( next );
						} }
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Waypoint description', 'kntnt-gpx-blocks' ) }
					initialOpen={ false }
				>
					<TypographyToolsPanel
						label={ __( 'Typography', 'kntnt-gpx-blocks' ) }
						fontFamily={ tooltipDescFontFamily }
						fontSize={ tooltipDescFontSize }
						fontWeight={ tooltipDescFontWeight }
						fontStyle={ tooltipDescFontStyle }
						lineHeight={ tooltipDescLineHeight }
						letterSpacing={ tooltipDescLetterSpacing }
						textDecoration={ tooltipDescTextDecoration }
						textTransform={ tooltipDescTextTransform }
						fontFamilies={ fontFamilies }
						fontSizes={ fontSizes }
						setTypography={ ( values ) => {
							const next: Partial< MapAttributes > = {};
							if ( values.fontFamily !== undefined ) {
								next.tooltipDescFontFamily = values.fontFamily;
							}
							if ( values.fontSize !== undefined ) {
								next.tooltipDescFontSize = values.fontSize;
							}
							if ( values.fontWeight !== undefined ) {
								next.tooltipDescFontWeight = values.fontWeight;
							}
							if ( values.fontStyle !== undefined ) {
								next.tooltipDescFontStyle = values.fontStyle;
							}
							if ( values.lineHeight !== undefined ) {
								next.tooltipDescLineHeight = values.lineHeight;
							}
							if ( values.letterSpacing !== undefined ) {
								next.tooltipDescLetterSpacing =
									values.letterSpacing;
							}
							if ( values.textDecoration !== undefined ) {
								next.tooltipDescTextDecoration =
									values.textDecoration;
							}
							if ( values.textTransform !== undefined ) {
								next.tooltipDescTextTransform =
									values.textTransform;
							}
							setAttributes( next );
						} }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<MapEditorPreview
					attributes={ {
						attachmentId,
						trackColor,
						waypointColor,
						tooltipShowName,
						tooltipShowDesc,
						provider: resolveProviderForPreview(
							tileProvider,
							tileStyle,
							tileApiKeys?.[ tileProvider ] ?? ''
						),
						overlays: resolveOverlaysForPreview( tileOverlays ),
					} }
					{ ...detectPreviewNotices(
						tileProvider,
						tileApiKeys?.[ tileProvider ] ?? '',
						window.kntntGpxBlocks?.providers ?? {},
						FALLBACK_PROVIDER_ID
					) }
				/>
			</div>
		</>
	);
};
