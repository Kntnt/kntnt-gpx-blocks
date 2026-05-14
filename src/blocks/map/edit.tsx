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
} from '@wordpress/block-editor';
import {
	PanelBody,
	ToggleControl,
	ToolbarButton,
	SelectControl,
	Notice,
} from '@wordpress/components';
import { useMemo } from '@wordpress/element';
import { useSelect, dispatch } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as noticesStore } from '@wordpress/notices';
import { __, sprintf } from '@wordpress/i18n';
import type { BlockEditProps } from '@wordpress/blocks';

import { useEnsureUniqueMapId } from './use-ensure-unique-map-id';
import {
	MapEditorPreview,
	type EditorOverlayRecord,
	type EditorProviderRecord,
} from './editor-preview';
import { TypographyToolsPanel } from '../shared/typography-tools-panel';
import { getDefaultMinHeight } from '../shared/dimensions-defaults';
import { InspectorBottomSpacer } from '../shared/inspector-bottom-spacer';
import { detectPreviewNotices } from './preview-notices';

// ─── Editor data global (window.kntntGpxBlocks) ─────────────────────────────

/**
 * Editor-only per-layer record nested inside each overlay-provider's
 * `layers` map.
 *
 * Carries the tile-layer fields `MapEditorPreview` forwards to
 * `L.tileLayer()` (URL with `{KEY}` left intact, attribution, maxZoom)
 * plus the localised `label` the per-layer ToggleControl surfaces.
 * `subdomains` is *not* on the layer record — it is a provider-level
 * property inherited by every layer of that overlay-provider whose URL
 * contains `{s}`.
 *
 * @since 1.0.0
 */
interface EditorRegistryOverlayLayer {
	readonly label: string;
	readonly url: string;
	readonly attribution: string;
	readonly maxZoom: number;
}

/**
 * Editor-only overlay-provider record exposed via
 * `window.kntntGpxBlocks.overlays`.
 *
 * Inlined by `Bootstrap\Editor_Data_Enqueuer` on every editor request as
 * `before` script of the GPX Map block's editor handle. Mirrors the
 * base-provider shape minus `default` (overlays are multi-select). The
 * editor needs `label` and `requiresKey` to drive the per-provider
 * sub-section and the conditional API-key Notice; `subdomains` and
 * per-layer URL/attribution/maxZoom let `MapEditorPreview` mount each
 * enabled layer directly via `L.tileLayer()`. The optional `signupUrl`
 * is unused by the editor but kept on the shape to mirror the
 * PHP-rendered registry record verbatim (the settings page is the
 * canonical place for sign-up links — issue #152).
 *
 * The `apiKeyManagedExternally` boolean signals whether the PHP path is
 * engaged for this overlay provider:
 *
 * - `false` (or absent) → option-layer key. The server-side
 *   `Editor_Data_Enqueuer` has already substituted `{KEY}` in each
 *   layer URL from the site-wide
 *   `kntnt_gpx_blocks_tile_overlay_keys` option (issue #150) before
 *   the URL reaches the editor; a residual `{KEY}` in the URL means
 *   no usable option-layer key was available — the preview drops just
 *   that layer.
 * - `true` → PHP path engaged via the `kntnt_gpx_blocks_tile_overlays`
 *   filter's optional `apiKey` field. The option-layer entry for that
 *   overlay provider is ignored, and per-layer URLs have already been
 *   substituted server-side by `Editor_Data_Enqueuer`. A still-
 *   unsubstituted `{KEY}` in a layer URL under this branch means the
 *   PHP-supplied key was empty (fail-closed) — the preview drops just
 *   that layer; the base map and other overlays continue to mount.
 *
 * @since 1.0.0
 */
interface EditorRegistryOverlayProvider {
	readonly label: string;
	readonly requiresKey: boolean;
	readonly layers: Readonly< Record< string, EditorRegistryOverlayLayer > >;
	readonly signupUrl?: string;
	readonly subdomains?: readonly string[];
	readonly apiKeyManagedExternally?: boolean;
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
 * `requiresKey`, `default` style id, optional `subdomains`) plus the
 * nested `styles` map of per-style records and the
 * `apiKeyManagedExternally` boolean that signals whether the PHP path
 * is engaged for this provider. The optional `signupUrl` is unused by
 * the editor but kept on the shape to mirror the PHP-rendered registry
 * record verbatim (the settings page is the canonical place for
 * sign-up links — issue #152).
 *
 * The per-style URL is always pre-substituted server-side: either
 * `Editor_Data_Enqueuer` substitutes `{KEY}` from the PHP-supplied
 * `apiKey` (when `apiKeyManagedExternally === true`), or it substitutes
 * from the site-wide `kntnt_gpx_blocks_tile_provider_keys` option
 * (issue #149) when the PHP path is not engaged. A residual `{KEY}` in
 * the URL means neither layer supplied a usable key — the preview
 * ships polyline-only via `resolveProviderForPreview()`'s fail-closed
 * branch. `apiKeyManagedExternally === true` additionally tells the
 * Inspector not to render the key-required Notice for this provider,
 * because the site builder owns the key in code and the editor stays
 * out of the way.
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
	readonly apiKeyManagedExternally?: boolean;
}

/**
 * Shape of the editor data global injected by PHP.
 *
 * `settingsUrl` and `canManageSettings` (issue #149) surface the
 * site-wide tile-API-key admin page so the Inspector's
 * key-required Notice can link to the page when the current user
 * holds `manage_options`. The URL is emitted unconditionally —
 * `canManageSettings` is the binary flag the editor JS reads to
 * decide whether to wrap the page name in an anchor element.
 *
 * @since 1.0.0
 */
interface EditorRegistry {
	readonly providers: Readonly< Record< string, EditorRegistryProvider > >;
	readonly overlays: Readonly<
		Record< string, EditorRegistryOverlayProvider >
	>;
	readonly settingsUrl?: string;
	readonly canManageSettings?: boolean;
}

/**
 * Persisted (provider, layer) overlay-pair shape used in
 * `attributes.tileOverlays`.
 *
 * Mirrors `block.json`'s `tileOverlays` array entries. The pair preserves
 * stacking order: the array's order is the order overlays are layered on
 * top of the base map. Per-overlay-provider API keys live in the
 * site-wide `kntnt_gpx_blocks_tile_overlay_keys` option (issue #150),
 * not in block attributes; the same key is shared across every layer of
 * that provider that the editor enables, on every GPX Map block.
 *
 * @since 1.0.0
 */
interface OverlayPair {
	readonly provider: string;
	readonly layer: string;
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
	enablePan: boolean;
	enableZoom: boolean;
	enableTrackPositionCursor: boolean;
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
	tileOverlays: OverlayPair[];
	[ key: string ]: unknown;
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
 * Renders one collapsible inspector panel per overlay provider, plus an
 * optional orphan panel at the bottom.
 *
 * Reads the validated overlay-provider registry from
 * `window.kntntGpxBlocks.overlays` (populated by
 * `Bootstrap\Editor_Data_Enqueuer`) and emits one `<PanelBody>` per
 * provider so the inspector stays uncluttered when several overlay
 * providers are enabled. Each provider's panel renders, in order:
 *
 * 1. One `ToggleControl` per layer. The toggle's checked state mirrors
 *    whether the (provider, layer) pair is present in
 *    `attributes.tileOverlays`; toggling adds or removes the pair from
 *    the array, preserving stacking order.
 * 2. For `requiresKey === true` providers that are *not* PHP-engaged
 *    (`apiKeyManagedExternally !== true`): a `Notice` pointing the
 *    user at *Settings → Kntnt GPX Blocks* where the per-overlay-
 *    provider key is administered (issue #150). The single
 *    per-provider key is shared across every layer of that provider
 *    that the editor enables, on every GPX Map block on the site.
 *
 * Each provider panel is collapsed by default (`initialOpen={false}`) —
 * a site builder may enable several overlay providers, and opening
 * every panel by default would recreate the visual clutter that
 * motivated this restructure.
 *
 * Stale-state surfacing — orphan saved pairs (the provider is gone, or
 * the layer is gone within a still-present provider) are surfaced in a
 * separate "Unrecognised overlays" panel at the bottom as disabled
 * toggles labelled with the orphan ids themselves so the editor
 * reflects persisted state without silently rewriting it. The user
 * removes them by saving the post with different choices or by
 * manually unchecking the disabled toggle (which still fires the
 * standard `onChange` because `disabled` is purely an affordance — the
 * underlying state can be cleared with the same code path).
 *
 * When the registry is empty (e.g. a site builder dropped every default
 * overlay provider via the `kntnt_gpx_blocks_tile_overlays` filter) the
 * component renders nothing — the issue spec calls for "no PanelBody",
 * not an empty panel.
 *
 * @since 1.0.0
 *
 * @param props               Component props.
 * @param props.selectedPairs Current `tileOverlays` array (typed pairs).
 * @param props.onPairsChange Setter that writes the new `tileOverlays`
 *                            array.
 */
function OverlaysPanel( {
	selectedPairs,
	onPairsChange,
}: {
	selectedPairs: OverlayPair[];
	onPairsChange: ( next: OverlayPair[] ) => void;
} ): JSX.Element | null {
	const overlays = window.kntntGpxBlocks?.overlays ?? {};
	const providerIds = Object.keys( overlays );
	const settingsUrl = window.kntntGpxBlocks?.settingsUrl ?? '';
	const canManageSettings = window.kntntGpxBlocks?.canManageSettings === true;

	// Pre-compute the orphan pairs (saved pairs whose provider is missing
	// from the registry, or whose layer is missing within a present
	// provider). They render in a dedicated panel at the bottom so the
	// editor sees the persisted state rather than having it silently
	// rewritten on render.
	const orphanPairs = selectedPairs.filter( ( pair ) => {
		const provider = overlays[ pair.provider ];
		if ( ! provider ) {
			return true;
		}
		return ! provider.layers[ pair.layer ];
	} );

	// Nothing to render when the registry is empty and no orphan pair
	// would otherwise pull a panel into existence either.
	if ( providerIds.length === 0 && orphanPairs.length === 0 ) {
		return null;
	}

	return (
		<>
			{ providerIds.map( ( providerId ) => {
				const provider = overlays[ providerId ];
				if ( ! provider ) {
					return null;
				}
				const layerIds = Object.keys( provider.layers );
				const shouldShowKeyNotice =
					provider.requiresKey === true &&
					provider.apiKeyManagedExternally !== true;
				return (
					<PanelBody
						key={ providerId }
						title={ provider.label }
						initialOpen={ false }
						className="kntnt-gpx-blocks-overlay-provider"
					>
						{ layerIds.map( ( layerId ) => {
							const layer = provider.layers[ layerId ];
							if ( ! layer ) {
								return null;
							}
							const checked = selectedPairs.some(
								( pair ) =>
									pair.provider === providerId &&
									pair.layer === layerId
							);
							return (
								<ToggleControl
									key={ layerId }
									label={ layer.label }
									checked={ checked }
									onChange={ ( next: boolean ) => {
										if ( next ) {
											if ( checked ) {
												return;
											}
											onPairsChange( [
												...selectedPairs,
												{
													provider: providerId,
													layer: layerId,
												},
											] );
										} else {
											onPairsChange(
												selectedPairs.filter(
													( pair ) =>
														! (
															pair.provider ===
																providerId &&
															pair.layer ===
																layerId
														)
												)
											);
										}
									} }
								/>
							);
						} ) }
						{ shouldShowKeyNotice && (
							<Notice
								status="info"
								isDismissible={ false }
								className="kntnt-gpx-blocks-tile-key-notice"
							>
								{ canManageSettings && settingsUrl !== '' ? (
									<>
										{ __(
											'This provider needs an API key. Configure it in',
											'kntnt-gpx-blocks'
										) }{ ' ' }
										<a href={ settingsUrl }>
											{ __(
												'Settings → Kntnt GPX Blocks',
												'kntnt-gpx-blocks'
											) }
										</a>
										{ '.' }
									</>
								) : (
									__(
										'This provider needs an API key. Configure it in Settings → Kntnt GPX Blocks.',
										'kntnt-gpx-blocks'
									)
								) }
							</Notice>
						) }
					</PanelBody>
				);
			} ) }
			{ orphanPairs.length > 0 && (
				<PanelBody
					title={ __( 'Unrecognised overlays', 'kntnt-gpx-blocks' ) }
					initialOpen={ false }
					className="kntnt-gpx-blocks-overlay-provider kntnt-gpx-blocks-overlay-orphans"
				>
					{ orphanPairs.map( ( pair ) => {
						const orphanLabel = `${ pair.provider } / ${ pair.layer }`;
						return (
							<ToggleControl
								key={ orphanLabel }
								label={ orphanLabel }
								checked={ true }
								disabled
								onChange={ ( next: boolean ) => {
									if ( next ) {
										return;
									}
									onPairsChange(
										selectedPairs.filter(
											( other ) =>
												! (
													other.provider ===
														pair.provider &&
													other.layer === pair.layer
												)
										)
									);
								} }
							/>
						);
					} ) }
				</PanelBody>
			) }
		</>
	);
}

/**
 * Resolves a list of saved overlay (provider, layer) pairs to runtime
 * records for the editor preview.
 *
 * Mirrors the server-side `Tile_Layer_Registry::resolve_overlays()`
 * contract on the editor side. Per-overlay-provider API keys live in
 * the site-wide `kntnt_gpx_blocks_tile_overlay_keys` option (issue
 * #150); the server-side `Editor_Data_Enqueuer` pre-substitutes
 * `{KEY}` from either the PHP-supplied value (when
 * `apiKeyManagedExternally === true`) or the option-layer value before
 * the URL reaches the editor. The only remaining client-side branch is
 * the fail-closed detector: a residual `{KEY}` in a layer URL after
 * the enqueuer has had its turn means no usable key was available —
 * the affected layer is dropped from the resolved overlay stack while
 * the base map and other overlays continue to render (the documented
 * asymmetric fail-closed; no polyline-only equivalent exists for
 * overlays). Unknown providers and unknown layers are handled
 * silently — the editor preview surfaces the resulting overlay stack
 * without diagnostics here (PHP logs them on the rendered page).
 *
 * The `id` field on `EditorOverlayRecord` is set to `${provider}/${layer}`
 * for telemetry parity with the previous flat shape; the preview does
 * not actually need it to mount the layer.
 *
 * @since 1.0.0
 *
 * @param pairs - Overlay pairs from `attributes.tileOverlays`.
 * @return Resolved records in the input order, with unknown / missing-key
 *         pairs removed.
 */
function resolveOverlaysForPreview(
	pairs: readonly OverlayPair[]
): EditorOverlayRecord[] {
	const overlays = window.kntntGpxBlocks?.overlays ?? {};
	const out: EditorOverlayRecord[] = [];
	for ( const pair of pairs ) {
		const provider = overlays[ pair.provider ];
		if ( ! provider ) {
			continue;
		}
		const layer = provider.layers[ pair.layer ];
		if ( ! layer ) {
			continue;
		}

		// Fail-closed detector. The server-side enqueuer pre-substitutes
		// `{KEY}` from whichever layer (PHP or option) supplied a
		// non-empty key; a residual `{KEY}` means no layer supplied
		// one, so the affected overlay is dropped from the resolved
		// stack — same outcome the frontend's `resolve_overlays()`
		// asymmetric fail-closed produces.
		if ( provider.requiresKey && layer.url.includes( '{KEY}' ) ) {
			continue;
		}

		const entry: EditorOverlayRecord = {
			id: `${ pair.provider }/${ pair.layer }`,
			url: layer.url,
			attribution: layer.attribution,
			maxZoom: layer.maxZoom,
		};
		if ( provider.subdomains && provider.subdomains.length > 0 ) {
			entry.subdomains = [ ...provider.subdomains ];
		}
		out.push( entry );
	}
	return out;
}

/**
 * Resolves the saved (provider id, style id) pair to the runtime record
 * the editor preview mounts.
 *
 * Mirrors `Tile_Layer_Registry::resolve_provider()` on the JS side: the
 * lookup falls back to the canonical OpenStreetMap provider when the
 * saved provider id is not in the editor-data global, and to the
 * provider's own `default` style when the saved style id is unknown
 * within a known provider. Per-base-provider API keys live in a
 * site-wide WP option (issue #149); the server-side
 * `Editor_Data_Enqueuer` pre-substitutes `{KEY}` from either the
 * PHP-supplied value or the option-layer value before the URL reaches
 * the editor. The only remaining client-side branch is the fail-closed
 * detector: a URL that still contains `{KEY}` after the enqueuer has
 * had its turn means no usable key was available — the preview ships
 * polyline-only by returning `null`. When the registry is missing
 * entirely (the inline script was stripped), the helper returns `null`
 * and the caller renders without a tile layer.
 *
 * @since 1.0.0
 *
 * @param providerId - Saved `tileProvider` attribute.
 * @param styleId    - Saved `tileStyle` attribute.
 * @return Resolved record with `{KEY}` already substituted server-side,
 *         or `null` when the resolved provider requires a key the
 *         server-side substitution could not supply.
 */
function resolveProviderForPreview(
	providerId: string,
	styleId: string
): EditorProviderRecord | null {
	const providers = window.kntntGpxBlocks?.providers ?? {};

	// Resolve the provider record with fall-back to the global fallback.
	const provider =
		providers[ providerId ] ?? providers[ FALLBACK_PROVIDER_ID ] ?? null;
	if ( ! provider ) {
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

	// Fail-closed detector. The server-side enqueuer pre-substitutes
	// `{KEY}` from whichever layer (PHP or option) supplied a non-empty
	// key; a residual `{KEY}` means no layer supplied one, so the
	// preview ships polyline-only — same outcome the frontend's
	// `Render_Map` URL-null gate produces.
	if ( style.url.includes( '{KEY}' ) ) {
		return null;
	}

	const out: EditorProviderRecord = {
		url: style.url,
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
 * within that provider — plus a conditional Notice driven by the
 * resolved provider's `requiresKey` flag. The dropdown options are
 * populated from `window.kntntGpxBlocks.providers` (see
 * `Bootstrap\Editor_Data_Enqueuer`); when the global is missing or empty
 * the panel still renders both `SelectControl`s so the editor surfaces a
 * clear "nothing here" state rather than vanishing silently. The style
 * dropdown is always rendered, even when the selected provider declares
 * a single style — the affordance is consistent across providers, and
 * the user sees what the canonical style for the provider is named.
 *
 * Per-base-provider tile API keys live in the site-wide
 * `kntnt_gpx_blocks_tile_provider_keys` option (issue #149); the
 * Inspector no longer carries a per-block API-key TextControl. For
 * key-required providers that are *not* PHP-engaged, a Notice points
 * the user at `Settings → Kntnt GPX Blocks`. The Notice text is plain
 * by default; users who hold `manage_options` see the settings-page
 * name wrapped in a link (resolved via `window.kntntGpxBlocks.settingsUrl`)
 * so they can jump directly to the configuration page. PHP-engaged
 * providers (`apiKeyManagedExternally === true`) emit no Notice at all
 * — the editor stays out of the way and any misconfiguration surfaces
 * via the underlying `Plugin::warning()` log.
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
 * @param props.onChange Setter — receives the new provider id and/or
 *                       style id.
 */
function TilesPanel( {
	provider,
	style,
	onChange,
}: {
	provider: string;
	style: string;
	onChange: ( next: { provider?: string; style?: string } ) => void;
} ): JSX.Element {
	const providers = window.kntntGpxBlocks?.providers ?? {};
	const providerIds = Object.keys( providers );
	const selectedProvider = providers[ provider ] ?? null;
	const settingsUrl = window.kntntGpxBlocks?.settingsUrl ?? '';
	const canManageSettings = window.kntntGpxBlocks?.canManageSettings === true;

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

	// Decide whether to render the key-required Notice. The notice fires
	// only for key-required providers whose key is not already supplied
	// by code (PHP-engaged providers behave like free providers in the
	// editor UI by design).
	const shouldShowKeyNotice =
		selectedProvider?.requiresKey === true &&
		selectedProvider.apiKeyManagedExternally !== true;

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
					// lands on the provider's default.
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
			{ shouldShowKeyNotice && (
				<Notice
					status="info"
					isDismissible={ false }
					className="kntnt-gpx-blocks-tile-key-notice"
				>
					{ canManageSettings && settingsUrl !== '' ? (
						<>
							{ __(
								'This provider needs an API key. Configure it in',
								'kntnt-gpx-blocks'
							) }{ ' ' }
							<a href={ settingsUrl }>
								{ __(
									'Settings → Kntnt GPX Blocks',
									'kntnt-gpx-blocks'
								) }
							</a>
							{ '.' }
						</>
					) : (
						__(
							'This provider needs an API key. Configure it in Settings → Kntnt GPX Blocks.',
							'kntnt-gpx-blocks'
						)
					) }
				</Notice>
			) }
		</PanelBody>
	);
}

/**
 * One row in the consolidated Color panel.
 *
 * Mirrors the shape `PanelColorSettings` accepts via `colorSettings`:
 * a current value, a setter, and the row label. Kept as a local
 * interface so {@link buildMapColorSettings} can both build and
 * filter the array with the same type the caller forwards.
 *
 * @since 1.0.0
 */
interface MapColorSetting {
	readonly value: string;
	readonly onChange: ( value: string | undefined ) => void;
	readonly label: string;
}

/**
 * Builds the `colorSettings` array for the Map block's `PanelColorSettings`,
 * filtering out rows whose master toggle in the *Controls* / *Waypoint info*
 * panels is off.
 *
 * Issue #143 — `Cursor` hides when `enableTrackPositionCursor` is off,
 * `Waypoint name` when `tooltipShowName` is off, `Waypoint description`
 * when `tooltipShowDesc` is off, and `Waypoint background` only when
 * *both* tooltip content sources are off (the shared backing surface
 * has no work left to do). Hidden attribute values are not cleared on
 * hide — the saved value is preserved so re-enabling the master toggle
 * restores the previous picker state.
 *
 * @since 1.0.0
 *
 * @param params                           Toggle state, current colour values, and the
 *                                         standard Gutenberg attribute setter.
 * @param params.enableTrackPositionCursor Whether the `Track cursor` toggle is on.
 * @param params.tooltipShowName           Whether the `Name` toggle is on.
 * @param params.tooltipShowDesc           Whether the `Description` toggle is on.
 * @param params.trackColor                Current `trackColor` attribute.
 * @param params.trackCursorColor          Current `trackCursorColor` attribute.
 * @param params.waypointColor             Current `waypointColor` attribute.
 * @param params.tooltipBackground         Current `tooltipBackground` attribute.
 * @param params.tooltipNameColor          Current `tooltipNameColor` attribute.
 * @param params.tooltipDescColor          Current `tooltipDescColor` attribute.
 * @param params.setAttributes             Standard Gutenberg attribute setter.
 * @return The filtered `colorSettings` array in display order.
 */
function buildMapColorSettings( params: {
	readonly enableTrackPositionCursor: boolean;
	readonly tooltipShowName: boolean;
	readonly tooltipShowDesc: boolean;
	readonly trackColor: string;
	readonly trackCursorColor: string;
	readonly waypointColor: string;
	readonly tooltipBackground: string;
	readonly tooltipNameColor: string;
	readonly tooltipDescColor: string;
	readonly setAttributes: ( next: Partial< MapAttributes > ) => void;
} ): MapColorSetting[] {
	const {
		enableTrackPositionCursor,
		tooltipShowName,
		tooltipShowDesc,
		trackColor,
		trackCursorColor,
		waypointColor,
		tooltipBackground,
		tooltipNameColor,
		tooltipDescColor,
		setAttributes,
	} = params;

	const settings: MapColorSetting[] = [];

	// `Track` always renders — it has no dependent master toggle.
	settings.push( {
		value: trackColor,
		onChange: ( value: string | undefined ) =>
			setAttributes( { trackColor: value ?? '' } ),
		label: __( 'Track', 'kntnt-gpx-blocks' ),
	} );

	// `Cursor` depends on the `Track cursor` master toggle in the
	// *Interactions* panel.
	if ( enableTrackPositionCursor ) {
		settings.push( {
			value: trackCursorColor,
			onChange: ( value: string | undefined ) =>
				setAttributes( { trackCursorColor: value ?? '' } ),
			label: __( 'Cursor', 'kntnt-gpx-blocks' ),
		} );
	}

	// `Marker` always renders — it is the waypoint pin colour and has
	// no master toggle in the *Waypoint info* panel.
	settings.push( {
		value: waypointColor,
		onChange: ( value: string | undefined ) =>
			setAttributes( { waypointColor: value ?? '' } ),
		label: __( 'Marker', 'kntnt-gpx-blocks' ),
	} );

	// `Waypoint background` backs both tooltip content rows; hide only
	// when *every* content source backing it is also off, mirroring the
	// Elevation block's `Tooltip background` rule.
	if ( tooltipShowName || tooltipShowDesc ) {
		settings.push( {
			value: tooltipBackground,
			onChange: ( value: string | undefined ) =>
				setAttributes( { tooltipBackground: value ?? '' } ),
			label: __( 'Waypoint background', 'kntnt-gpx-blocks' ),
		} );
	}

	if ( tooltipShowName ) {
		settings.push( {
			value: tooltipNameColor,
			onChange: ( value: string | undefined ) =>
				setAttributes( { tooltipNameColor: value ?? '' } ),
			label: __( 'Waypoint name', 'kntnt-gpx-blocks' ),
		} );
	}

	if ( tooltipShowDesc ) {
		settings.push( {
			value: tooltipDescColor,
			onChange: ( value: string | undefined ) =>
				setAttributes( { tooltipDescColor: value ?? '' } ),
			label: __( 'Waypoint description', 'kntnt-gpx-blocks' ),
		} );
	}

	return settings;
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
	// surfaced read-only in the block toolbar as a click-to-copy badge once
	// `useEnsureUniqueMapId` above has assigned a value (issue #147).
	const {
		attachmentId,
		mapId,
		showZoomButtons,
		showScale,
		showFullscreen,
		showDownload,
		enablePan,
		enableZoom,
		enableTrackPositionCursor,
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

	// Resolve the plugin-defined default `min-height` for the wrapper.
	// `getDefaultMinHeight()` returns `'30vh'` when the user has not
	// set a `minHeight` on this block — the same single-mechanism
	// condition the server-side `Dimensions_Defaults` filter checks —
	// and `undefined` otherwise. When the value is `undefined`, no
	// inline minHeight is injected here and core's dimensions
	// block-supports machinery surfaces whatever the user chose.
	// Issue #117 centralised the rule between PHP and JS; issue #146
	// simplified Map's gate to match Elevation's.
	const defaultMinHeight = getDefaultMinHeight(
		'kntnt-gpx-blocks/map',
		attributes
	);
	const minHeightDefault: React.CSSProperties = defaultMinHeight
		? { minHeight: defaultMinHeight }
		: {};

	// Memoize the resolved provider record so the preview's effects can use
	// reference equality as their dependency comparison. Without memoization
	// `resolveProviderForPreview()` returns a fresh object on every parent
	// render, which would re-fire the preview's base-tile useEffect on every
	// keystroke in an unrelated control. The dep array tracks the inputs the
	// resolver actually reads — saved provider/style ids only; per-base-
	// provider tile API keys live in the site-wide WP option (issue #149)
	// and the server-side `Editor_Data_Enqueuer` pre-substitutes `{KEY}`
	// before the URL reaches the editor, so the editor JS no longer touches
	// any API-key value.
	const providerForPreview = useMemo(
		() => resolveProviderForPreview( tileProvider, tileStyle ),
		[ tileProvider, tileStyle ]
	);

	// Same pattern for the resolved overlay records. The overlay effect wipes
	// and re-adds every layer on each fire, so a fresh array on every parent
	// render would rebuild the whole overlay stack on every keystroke. Per-
	// overlay-provider keys live in the site-wide
	// `kntnt_gpx_blocks_tile_overlay_keys` option (issue #150) and the
	// server-side `Editor_Data_Enqueuer` pre-substitutes `{KEY}` before the
	// URL reaches the editor, so keying the memo on the saved pairs alone
	// keeps the reference stable as long as overlays are unchanged.
	const overlaysForPreview = useMemo(
		() => resolveOverlaysForPreview( tileOverlays ),
		[ tileOverlays ]
	);

	// Inject the project class so the shared style.scss rules (layout
	// baseline, focus styles, hit-band styling, tooltip styling, …) apply to
	// the editor wrapper exactly as they do to the frontend wrapper that
	// `Render_Map` produces via `get_block_wrapper_attributes()`. Dimensions
	// (`aspect-ratio`, user-set `min-height`) come from core's `dimensions`
	// block supports — `useBlockProps()` already merges them into the inline
	// style it returns; the plugin-defined `min-height` default above covers
	// the blank-min-height state. The track and cursor colour custom
	// properties are added here so canvas-painted Leaflet polylines that
	// read them via CSS inheritance see the editor's current colour-picker
	// values. The tooltip custom properties feed the floating waypoint-info
	// preview rendered inside `MapEditorPreview` so its colours and
	// typography update live as the editor adjusts the inspector controls.
	const blockProps = useBlockProps( {
		className: 'kntnt-gpx-blocks-map',
		style: {
			...minHeightDefault,
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
	// surfaces two groups: a middle-group click-to-copy badge that exposes
	// the auto-generated `mapId` so site builders can bind sibling Elevation
	// blocks and `[kntnt-gpx]` shortcodes to a specific Map (issue #147),
	// and an `other`-group Replace flow (Media Library + Upload tabs only —
	// no URL, no Reset) so the editor can swap the .gpx without losing any
	// other attribute; only `attachmentId` is written by the onSelect
	// callback. The badge renders only after `useEnsureUniqueMapId` has
	// assigned a non-empty value, so a freshly inserted block never flashes
	// an empty toolbar button.
	return (
		<>
			{ mapId && (
				<BlockControls>
					<ToolbarButton
						text={ mapId }
						label={ __( 'Copy Map ID', 'kntnt-gpx-blocks' ) }
						showTooltip
						className="kntnt-gpx-blocks-map-id-badge"
						aria-label={ sprintf(
							/* translators: %s: auto-generated Map ID such as "map-7k9f2m". */
							__(
								'Copy Map ID %s to clipboard',
								'kntnt-gpx-blocks'
							),
							mapId
						) }
						onClick={ () => {
							// Modern WP admin runs over HTTPS where
							// `navigator.clipboard` is reliably available, so
							// no `document.execCommand` fallback is wired —
							// the rare failure surfaces honestly as an error
							// snackbar rather than silent success.
							navigator.clipboard.writeText( mapId ).then(
								() => {
									dispatch( noticesStore ).createNotice(
										'success',
										__(
											'Map ID copied',
											'kntnt-gpx-blocks'
										),
										{
											type: 'snackbar',
											isDismissible: true,
										}
									);
								},
								() => {
									dispatch( noticesStore ).createNotice(
										'error',
										__(
											"Couldn't copy Map ID",
											'kntnt-gpx-blocks'
										),
										{
											type: 'snackbar',
											isDismissible: true,
										}
									);
								}
							);
						} }
					/>
				</BlockControls>
			) }
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
						label={ __( 'Pan', 'kntnt-gpx-blocks' ) }
						checked={ enablePan }
						onChange={ ( value ) =>
							setAttributes( { enablePan: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Zoom', 'kntnt-gpx-blocks' ) }
						checked={ enableZoom }
						onChange={ ( value ) =>
							setAttributes( { enableZoom: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Track cursor', 'kntnt-gpx-blocks' ) }
						checked={ enableTrackPositionCursor }
						onChange={ ( value ) =>
							setAttributes( {
								enableTrackPositionCursor: value,
							} )
						}
					/>
				</PanelBody>
				<PanelBody title={ __( 'Waypoint info', 'kntnt-gpx-blocks' ) }>
					<ToggleControl
						label={ __( 'Name', 'kntnt-gpx-blocks' ) }
						checked={ tooltipShowName }
						onChange={ ( value ) =>
							setAttributes( { tooltipShowName: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Description', 'kntnt-gpx-blocks' ) }
						checked={ tooltipShowDesc }
						onChange={ ( value ) =>
							setAttributes( { tooltipShowDesc: value } )
						}
					/>
				</PanelBody>
				<TilesPanel
					provider={ tileProvider }
					style={ tileStyle }
					onChange={ ( next ) => {
						const update: Partial< MapAttributes > = {};
						if ( next.provider !== undefined ) {
							update.tileProvider = next.provider;
						}
						if ( next.style !== undefined ) {
							update.tileStyle = next.style;
						}
						setAttributes( update );
					} }
				/>
				<OverlaysPanel
					selectedPairs={ tileOverlays }
					onPairsChange={ ( next ) =>
						setAttributes( { tileOverlays: next } )
					}
				/>
			</InspectorControls>
			<InspectorControls group="styles">
				{ /* @ts-ignore — PanelColorSettings is exported from @wordpress/block-editor but its typings lag behind. */ }
				<PanelColorSettings
					title={ __( 'Color', 'kntnt-gpx-blocks' ) }
					enableAlpha
					colorSettings={ buildMapColorSettings( {
						enableTrackPositionCursor,
						tooltipShowName,
						tooltipShowDesc,
						trackColor,
						trackCursorColor,
						waypointColor,
						tooltipBackground,
						tooltipNameColor,
						tooltipDescColor,
						setAttributes,
					} ) }
				/>
				{ tooltipShowName && (
					<PanelBody
						title={ __( 'Waypoint name', 'kntnt-gpx-blocks' ) }
						initialOpen={ false }
					>
						<TypographyToolsPanel
							title={ __( 'Typography', 'kntnt-gpx-blocks' ) }
							prefix="tooltipName"
							attributes={ attributes }
							setAttributes={ setAttributes }
							defaultVisibility={ {
								size: true,
								appearance: true,
							} }
							panelId={ `${ clientId }-tooltip-name` }
						/>
					</PanelBody>
				) }
				{ tooltipShowDesc && (
					<PanelBody
						title={ __(
							'Waypoint description',
							'kntnt-gpx-blocks'
						) }
						initialOpen={ false }
					>
						<TypographyToolsPanel
							title={ __( 'Typography', 'kntnt-gpx-blocks' ) }
							prefix="tooltipDesc"
							attributes={ attributes }
							setAttributes={ setAttributes }
							defaultVisibility={ {
								size: true,
								appearance: true,
							} }
							panelId={ `${ clientId }-tooltip-desc` }
						/>
					</PanelBody>
				) }
			</InspectorControls>
			<InspectorBottomSpacer />
			<div { ...blockProps }>
				<MapEditorPreview
					attributes={ {
						attachmentId,
						trackColor,
						waypointColor,
						tooltipShowName,
						tooltipShowDesc,
						provider: providerForPreview,
						overlays: overlaysForPreview,
					} }
					{ ...detectPreviewNotices(
						tileProvider,
						tileStyle,
						window.kntntGpxBlocks?.providers ?? {},
						FALLBACK_PROVIDER_ID
					) }
				/>
			</div>
		</>
	);
};
