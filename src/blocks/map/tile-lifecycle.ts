/**
 * Idempotent helpers that add and remove the GPX Map block's tile layers.
 *
 * The polyline, cursor, controls, and waypoint markers mount unconditionally
 * from cached GeoJSON — none of them reach a third party and none of them are
 * subject to the consent contract. Only the base-tile layer and any overlay
 * tile layers issue third-party requests; this module is the seam through
 * which those layers come and go in response to consent transitions or to a
 * runtime change in tile-config validity (e.g. a paid provider with an empty
 * API key produces a `null` URL on state and never gets `addTiles` called).
 *
 * Extracted from `view.ts` so the lifecycle helpers are testable in isolation
 * — `view.ts` itself imports `@wordpress/interactivity`, which is not
 * resolvable inside Jest's module graph without a `wp-scripts` test
 * environment, so unit-testing `addTiles`/`removeTiles` in place would mean
 * standing up the full Interactivity runtime for what amounts to two pure
 * Leaflet calls. A standalone module sidesteps that and keeps the
 * idempotence proofs short.
 *
 * @since 1.0.0
 */

import L from 'leaflet';

/**
 * Resolved tile-layer record carried in the per-map state.
 *
 * Mirrors the validated shape `Tile_Layer_Registry` writes server-side.
 * The `url` may be `null` when the resolved provider requires an API
 * key (`requiresKey === true`) and the per-provider entry in
 * `tileApiKeys` is empty — this is the documented "polyline-only"
 * state for paid providers without a key. The view module checks
 * `url !== null` before calling `addTiles`.
 *
 * The base-provider record drops `id` entirely (the runtime never
 * needs it — the provider is identified by what the user picked in
 * the editor, and the resolver simply substitutes `{KEY}` and writes
 * the slim record). Overlay records do carry `id` so the JS view can
 * tell two overlays apart for the unused-overlay diagnostics; the
 * field is optional here so the same interface covers both shapes.
 *
 * @since 1.0.0
 */
export interface TileLayerRecord {
	readonly id?: string;
	readonly url: string | null;
	readonly attribution: string;
	readonly maxZoom: number;
	readonly subdomains?: readonly string[];
}

/**
 * Tile-layer references kept on a `MapEntry` so `removeTiles` can later
 * find and detach them without re-querying the map.
 *
 * `base` is `null` when no base layer is currently attached; `overlays` is
 * an empty array when no overlay layers are attached. `removeTiles` clears
 * both fields back to those empty values so a subsequent `addTiles` call
 * starts from a clean slate.
 *
 * @since 1.0.0
 */
export interface TileLayerRefs {
	base: L.TileLayer | null;
	overlays: L.TileLayer[];
}

/**
 * Constructs an empty tile-layer reference set.
 *
 * Callers seed `MapEntry.tiles` with this on mount so the field is always a
 * concrete object — `addTiles` mutates it in place rather than reassigning
 * a new object, which keeps the WeakMap reference identity stable.
 *
 * @since 1.0.0
 *
 * @return Fresh empty refs object.
 */
export function createEmptyTileRefs(): TileLayerRefs {
	return {
		base: null,
		overlays: [],
	};
}

/**
 * Returns true when the resolved record has a usable URL.
 *
 * The URL is `null` exactly in the documented "polyline-only" cases:
 * `requiresKey && tileApiKeys[ tileProvider ] === ''` for the base provider, or any future
 * runtime path where PHP cannot resolve a usable URL. The check is centralised
 * here so the call sites read declaratively.
 *
 * @since 1.0.0
 *
 * @param record - Resolved tile-layer record from PHP state.
 * @return `true` when the record has a non-empty string URL.
 */
export function hasUsableUrl(
	record: TileLayerRecord | null | undefined
): boolean {
	return !! record?.url;
}

/**
 * Build Leaflet `TileLayerOptions` from a resolved record.
 *
 * The `subdomains` array is forwarded only when present and non-empty so the
 * default Leaflet behaviour (no `{s}` expansion) is preserved for providers
 * that did not declare any.
 *
 * @since 1.0.0
 *
 * @param record - Resolved tile-layer record. Must have a non-null URL.
 * @return Leaflet options ready to pass to `L.tileLayer`.
 */
function tileLayerOptions( record: TileLayerRecord ): L.TileLayerOptions {
	const options: L.TileLayerOptions = {
		maxZoom: record.maxZoom,
		attribution: record.attribution,
	};
	if ( record.subdomains && record.subdomains.length > 0 ) {
		options.subdomains = [ ...record.subdomains ];
	}
	return options;
}

/**
 * Add the base-tile layer plus the overlay tile layers to the given map.
 *
 * Idempotent: calling twice with the same arguments is a no-op the second
 * time. The function inspects `refs.base` and `refs.overlays` before adding
 * — when either is already populated, the call returns early so duplicate
 * tile layers never accumulate. The "did the configuration change?" question
 * is the caller's responsibility; this helper assumes the caller has already
 * decided to bring tiles up.
 *
 * Skips the base layer when `baseRecord` lacks a usable URL (the documented
 * polyline-only state for paid providers without a key); overlays without a
 * usable URL are skipped individually so the rest of the stack still mounts.
 *
 * @since 1.0.0
 *
 * @param map            - Leaflet map instance.
 * @param baseRecord     - Resolved base provider record, or `null` when no
 *                       base record is configured.
 * @param overlayRecords - Resolved overlay records in stacking order.
 * @param refs           - Reference holder mutated in place to remember
 *                       which layers were added so `removeTiles` can
 *                       detach them later.
 */
export function addTiles(
	map: L.Map,
	baseRecord: TileLayerRecord | null,
	overlayRecords: readonly TileLayerRecord[],
	refs: TileLayerRefs
): void {
	// Idempotence guard — when any layer is already attached, treat the
	// whole add-pass as a no-op. The caller drives state transitions
	// explicitly (consent grant/deny, config change), so re-entering with
	// "already mounted" means a redundant signal that should not double the
	// layer stack.
	if ( refs.base !== null || refs.overlays.length > 0 ) {
		return;
	}

	// Mount the base layer when the record has a usable URL. A null URL
	// signals the polyline-only state and is the silent skip path — the
	// rest of the map (polyline, cursor, controls, waypoints) is already
	// mounted by the time we reach here.
	if ( hasUsableUrl( baseRecord ) && baseRecord !== null ) {
		refs.base = L.tileLayer(
			baseRecord.url as string,
			tileLayerOptions( baseRecord )
		).addTo( map );
	}

	// Stack overlays in editor-configured order. Each record is added in
	// place; an overlay with a missing URL is skipped silently so a single
	// bad overlay does not block the others.
	for ( const overlay of overlayRecords ) {
		if ( ! hasUsableUrl( overlay ) ) {
			continue;
		}
		refs.overlays.push(
			L.tileLayer(
				overlay.url as string,
				tileLayerOptions( overlay )
			).addTo( map )
		);
	}
}

/**
 * Remove the base-tile layer and overlay tile layers from the given map.
 *
 * Idempotent: calling when no tiles are present is a no-op. Detaches via
 * `map.removeLayer()` (the inverse of `addTo()` used in `addTiles`) so
 * Leaflet detaches DOM nodes and stops emitting tile requests; the rest of
 * the map (polyline, cursor, controls, waypoints) is untouched.
 *
 * The refs object is reset to the empty state so a subsequent `addTiles`
 * call starts clean.
 *
 * @since 1.0.0
 *
 * @param map  - Leaflet map instance.
 * @param refs - Reference holder mutated in place — base set to `null`,
 *             overlays emptied.
 */
export function removeTiles( map: L.Map, refs: TileLayerRefs ): void {
	if ( refs.base !== null ) {
		map.removeLayer( refs.base );
		refs.base = null;
	}
	for ( const overlay of refs.overlays ) {
		map.removeLayer( overlay );
	}
	refs.overlays = [];
}
