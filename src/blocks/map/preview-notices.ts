/**
 * Pure helper that decides which editor-only Notices the GPX Map preview
 * should render for the current configuration.
 *
 * Two distinct conditions warrant a warning Notice in the preview region:
 *
 * - **Unknown provider id** — the saved `tileProvider` attribute is non-empty
 *   but no longer present in the editor-data registry. The site builder
 *   either dropped the provider via `kntnt_gpx_blocks_tile_providers` or
 *   the post-content survived a registry change. The frontend (and the
 *   editor preview) silently fall back to the canonical OSM provider; this
 *   notice tells the editor that fallback is happening so they can pick a
 *   different provider explicitly. The fallback label comes from the
 *   registry rather than a hardcoded string so a site builder who renamed
 *   the OSM entry sees the renamed label.
 *
 * - **Missing API key** — the resolved provider requires a key
 *   (`requiresKey === true`) but the per-block `tileApiKey` is empty.
 *   Without a key the tile URL produces a request that fails to load
 *   tiles; this notice complements the Inspector help text by surfacing
 *   the issue louder, in the preview region itself.
 *
 * Extracted as a standalone module so it can be unit-tested via
 * `wp-scripts test-unit-js` without pulling in React, Leaflet, or the
 * `@wordpress/components` Notice surface (the latter is an external
 * dependency at runtime and is not installed in `node_modules`).
 *
 * @since 1.0.0
 */

/**
 * Editor-side provider record shape — narrowed to the fields the helper
 * inspects.
 *
 * Mirrors `Editor_Data_Enqueuer::shape_providers()`'s output. The full
 * shape is declared in `edit.tsx`; only the two fields read here are
 * required for the detection logic, which keeps the helper trivially
 * mockable from tests.
 *
 * @since 1.0.0
 */
export interface PreviewNoticeProvider {
	readonly label: string;
	readonly requiresKey: boolean;
}

/**
 * Result of the detection — both flags in one object so the consumer can
 * destructure exactly what it needs.
 *
 * `unknownProviderFallbackLabel` is `null` when the saved id is recognised;
 * otherwise it carries the registry's label for the canonical fallback
 * provider, ready to be interpolated into the localised Notice template.
 * Defaults to `"OpenStreetMap"` when the registry has no fallback entry —
 * that is a defensive edge case (a site builder who removed the OSM
 * provider entirely), and the literal still reads correctly as a generic
 * "what we fell back to" hint.
 *
 * `missingKey` is `true` when the resolved provider requires a key and
 * `apiKey` is empty. The "resolved" provider is the one looked up by
 * `providerId` if present, otherwise the fallback — mirroring the runtime
 * resolution in `resolveProviderForPreview()`.
 *
 * @since 1.0.0
 */
export interface PreviewNoticeFlags {
	readonly unknownProviderFallbackLabel: string | null;
	readonly missingKey: boolean;
}

/**
 * Defensive default for the fallback provider's display label when the
 * registry has no entry for the canonical fallback id at all.
 *
 * Hardcoded to the English provider name as a last-resort literal. The
 * happy path always reads the label from the registry so a renamed OSM
 * entry surfaces with its renamed label; this constant only kicks in when
 * a site builder has removed the canonical fallback provider entirely
 * (an unusual configuration but worth surviving without crashing).
 *
 * @since 1.0.0
 */
const DEFAULT_FALLBACK_LABEL = 'OpenStreetMap';

/**
 * Detect the two warning conditions the preview surfaces as Notices.
 *
 * @since 1.0.0
 *
 * @param providerId         - Saved `tileProvider` attribute. The empty
 *                           string is the "no choice yet" sentinel and is
 *                           never treated as unknown — block.json's default
 *                           is `osm-standard`, so an empty value here means
 *                           pre-default state, not a stale id.
 * @param apiKey             - Saved `tileApiKey` attribute.
 * @param providers          - Editor-data registry's `providers` object.
 * @param fallbackProviderId - Canonical fallback provider id (mirrors
 *                           `Tile_Layer_Registry::FALLBACK_PROVIDER_ID`).
 * @return Both detection flags.
 */
export function detectPreviewNotices(
	providerId: string,
	apiKey: string,
	providers: Readonly< Record< string, PreviewNoticeProvider > >,
	fallbackProviderId: string
): PreviewNoticeFlags {
	const fallbackRecord = providers[ fallbackProviderId ] ?? null;
	const fallbackLabel = fallbackRecord?.label ?? DEFAULT_FALLBACK_LABEL;

	// Unknown-id branch: a non-empty saved id that the registry no longer
	// recognises. An empty saved id reflects pre-default state and never
	// triggers the notice.
	const isUnknownProvider =
		providerId !== '' && providers[ providerId ] === undefined;

	// Resolve the provider record the preview will actually mount — saved
	// id when known, fallback otherwise. This matches the runtime path in
	// resolveProviderForPreview() so the missing-key flag is computed
	// against the *effective* provider, not the saved-but-stale id.
	const resolved = providers[ providerId ] ?? fallbackRecord;
	const missingKey = resolved?.requiresKey === true && apiKey === '';

	return {
		unknownProviderFallbackLabel: isUnknownProvider ? fallbackLabel : null,
		missingKey,
	};
}
