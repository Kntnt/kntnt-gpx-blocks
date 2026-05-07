# Architecture

This document is the resolved architecture for `kntnt-gpx-blocks`. It supersedes the design brief in [`design.md`](design.md) wherever the two differ. For per-block specifications see [`blocks.md`](blocks.md), for the cache lifecycle see [`caching.md`](caching.md), for the consent integration see [`consent.md`](consent.md), for the security model see [`security.md`](security.md), and for the full hook reference see [`hooks.md`](hooks.md).

## Overview

The plugin registers three Gutenberg blocks: **GPX Map**, **GPX Elevation**, and **GPX Statistics**. GPX Map is the data source. The other two read from it via a sibling binding model: each block has a `mapId` attribute that defaults to `"auto"` and resolves at render time to "the single GPX Map on this page". The blocks are designed to live next to each other in any layout — they communicate via the WordPress Interactivity API, not via React state or DOM proximity, so they can be placed in different columns, groups, or other containers without losing the cursor synchronisation.

## Data flow

A GPX file enters the system through the WordPress media library. On `add_attachment` the plugin parses it once with `XMLReader` (streaming), converts it to GeoJSON, computes the five summary statistics (distance, min/max elevation, ascent, descent), and stores both results plus a version stamp and a content hash as post-meta on the attachment. The original file is left untouched. On every subsequent render of any of the three blocks, the cached data is read from post-meta — no re-parsing. If the file is replaced (`attachment_updated` fires, or the cached hash no longer matches the file's current hash), conversion runs again and overwrites the cache. If the cache version stamp is older than the plugin's current cache version, lazy regeneration kicks in on first render. The `wp kntnt-gpx regenerate` WP-CLI command forces regeneration on demand. See [`caching.md`](caching.md) for the full lifecycle.

## Block coupling

Each GPX Map gets an auto-generated 6-character base36 `mapId` on first edit. Each GPX Elevation and GPX Statistics has its own `mapId` attribute, default `"auto"`. At render time the server enumerates GPX Map blocks in the post content via `parse_blocks()`. When `mapId === "auto"` and exactly one map exists, it resolves to that map. When more than one exists, the consumer block renders an error state asking the editor to pick explicitly. The editor sidebar offers a `SelectControl` "Datakälla" with the auto option plus every Map on the page, labelled by GPX filename.

Cross-scope discovery is **not** supported in v1: the three blocks must all live in the same post content. A GPX Map in a template part and a GPX Elevation in the post will not connect. This is an intentional simplification — `parse_blocks()` against `$post->post_content` is fast and reliable, while resolving across templates and synced patterns at render time would require traversal that conflicts with the cache-friendliness goal.

## Rendering strategy

All three blocks are **dynamic**. Each `block.json` declares `"render": "file:./render.php"` and the `save` callback in JavaScript returns `null`. The render PHP file is a thin proxy that loads the autoloader and calls the appropriate `Render_*` class in `Kntnt\Gpx_Blocks\Rendering\`. The block's HTML output is deterministic given its attributes and the cached attachment data, which means edge caches (Cloudflare, Varnish, the WordPress page cache) can serve it without per-request work.

Static rendering (a `save.tsx` returning HTML) was rejected because GPX-derived HTML must reflect the current cached GeoJSON: if the GPX file is replaced, every saved post that referenced it must show the new track without the editor opening and re-saving each post. Static rendering would freeze the GeoJSON in `post_content` and require a manual re-save on every content change.

## Hydration via Interactivity API

Client-side data — GeoJSON, waypoints, settings, statistics — reaches the browser through `wp_interactivity_state()`. The PHP render function calls `wp_interactivity_state( 'kntnt-gpx-blocks', [ $map_id => [ ... ] ] )` and emits a block element annotated with `data-wp-interactive='{"namespace":"kntnt-gpx-blocks"}'` and a `data-wp-context` carrying the `mapId`. The block's `viewScriptModule` (an ES module that imports `@wordpress/interactivity`) registers a store with `actions`, `callbacks`, and reactive state. On hydration, `data-wp-init` runs the block's mount callback (build the Leaflet map, build the SVG chart, etc.), and `data-wp-watch` keeps the cursor marker in sync with the shared state.

Inline `<script type="application/json">` payloads are **not** used. Hydration goes exclusively through the Interactivity API.

## Cross-block synchronisation

The Map and the Elevation chart share a single value: the cursor position along the track, expressed as a `fraction` in the range `[0, 1]`. When the user moves the pointer over the polyline on the map, the Map block computes the fraction and writes it to `state[mapId].fraction`. When the user moves the pointer over the elevation chart, the Elevation block does the same. Both blocks have a `data-wp-watch="callbacks.onCursorChange"` that reacts to the state change and moves its visible cursor (a Leaflet marker on the map, an SVG circle on the chart). Each block resolves the fraction to its own local data — `lat`/`lng` for Map, `(distance, ele)` for Elevation — at the moment of rendering.

Resolution-independent payload was the deliberate choice. The Map polyline is geographically simplified (Douglas-Peucker, ~5 m tolerance); the Elevation profile is downsampled (LTTB to ~300 points). The two have different point counts, so a point index would not work as a sync key. Distance in metres would, but it would force every receiver to know the total track length. Fraction has neither problem.

## Editor integration

The Edit component (in TypeScript, `edit.tsx`) renders a `<ServerSideRender>` for content-bearing attributes (`attachmentId`, `mapId`) and uses CSS custom properties for cosmetic attributes (colours, typography) and Interactivity state mutations for behaviour toggles (zoom buttons, drag, scroll-wheel zoom, etc.). This three-way split keeps the editor responsive: dragging a colour slider updates the rendered map at frame rate via CSS variables, while picking a different GPX file triggers a single server round-trip.

The `view.ts` module is shared between editor and frontend. ServerSideRender returns the same HTML the frontend gets, including the `data-wp-interactive` annotations, so Interactivity hydrates inside the editor's iframe identically to the frontend. There is no separate React implementation of the map or chart for the editor.

## Performance

Per design — confirmed and refined:

- **Track simplification.** Douglas-Peucker at server render time, 5 m default tolerance (filter `kntnt_gpx_blocks_track_simplification_meters`). Reduces a 5-hour recording from ~18 000 points to ~200–500 without visible quality loss at normal zoom.
- **Elevation downsampling.** Largest Triangle Three Buckets (LTTB) to 300 points by default (filter `kntnt_gpx_blocks_elevation_target_points`). Preserves visual peaks better than stride-based sampling.
- **Climb computation.** Hysteresis filter with 3 m default threshold (filter `kntnt_gpx_blocks_climb_threshold_meters`) computed once at conversion and stored in cache. Sub-threshold wobble — the dominant source of GPS noise — is rejected; real climbs are preserved exactly.
- **Canvas renderer.** Leaflet uses `L.canvas()` for the polyline so a single canvas paint replaces many SVG elements.
- **Lazy mount.** `view.ts` defers Leaflet initialisation until the block's container intersects the viewport (`IntersectionObserver`).

## Privacy

Map tiles are loaded from OpenStreetMap, which means visitor IPs reach a third party. The plugin does not load any tiles until consent has been granted. The integration is built on the WordPress Consent API, configurable via three filters (`kntnt_gpx_blocks_consent_required`, `kntnt_gpx_blocks_consent_category`, `kntnt_gpx_blocks_consent_service`). When consent is missing, a server-rendered placeholder takes the map's place; clicking it activates that single block's tile loading for the current page view without granting site-wide consent. See [`consent.md`](consent.md) for the full integration model.

The Statistics and Elevation blocks load no third-party resources and need no consent. They render unconditionally.

## Error handling

Every render function checks for an error condition before rendering the block. Errors come in three categories: GPX-side (`'no-track'`, `'too-few-points'`, `'too-large'`, `'file-missing'`, `'parse-failed'`, `'wrong-mime'`), block-side (`'no-attachment'`, `'no-map'`, `'multiple-maps'`, `'map-not-found'`), and runtime (rare; would be a bug). Visitors without `edit_posts` capability see nothing — the block renders an empty string and is invisible on the page. Editors with the capability see a `.kntnt-gpx-blocks-error` notice with a clear English message (translated through `kntnt-gpx-blocks` text domain). Logging goes through `Plugin::error()` / `Plugin::warning()` / `Plugin::info()` / `Plugin::debug()`, gated by the `KNTNT_GPX_BLOCKS_LOG_LEVEL` constant (default `'error'`, `'none'` silences everything).

## Component map

The major classes in `classes/` (PSR-4 namespaced under `Kntnt\Gpx_Blocks`):

| Class | Responsibility |
|---|---|
| `Plugin` | Singleton entry point. Wires hooks. Provides `error()/warning()/info()/debug()`. Exposes `get_plugin_data()` and `get_plugin_file()` for the Updater. |
| `Bootstrap\Block_Registrar` | Registers the three blocks via `register_block_type()` and the `kntnt` block category. |
| `Bootstrap\Mime_Registrar` | `upload_mimes` and `wp_check_filetype_and_ext` filters for `.gpx`. |
| `Bootstrap\Conversion_Hooks` | `add_attachment` / `attachment_updated` callbacks that trigger conversion. |
| `Conversion\Gpx_Parser` | XMLReader-based streaming parser. XXE-safe. |
| `Conversion\Geo_Json_Converter` | Translates parsed GPX into GeoJSON. |
| `Conversion\Statistics_Calculator` | Computes the five summary statistics. |
| `Cache\Attachment_Cache` | Reads and writes the post-meta cache. Handles version + hash checks. |
| `Cache\Cache_Version` | The `KNTNT_GPX_BLOCKS_CACHE_VERSION` constant. |
| `Rendering\Douglas_Peucker` | Polyline simplification. |
| `Rendering\Lttb` | Elevation downsampling (Largest Triangle Three Buckets). |
| `Rendering\Resolve_Map_Id` | Locates the GPX Map for a given `mapId` (or `"auto"`) by parsing post content. |
| `Rendering\Render_Map` | Server-side render of GPX Map. |
| `Rendering\Render_Elevation` | Server-side render of GPX Elevation. |
| `Rendering\Render_Statistics` | Server-side render of GPX Statistics. |
| `Consent\Consent_Resolver` | Resolves the active consent state (filter values + Consent API). |
| `Format\Value_Formatter` | Locale-aware number formatting and unit selection (m vs km). |
| `Cli\Regenerate_Command` | `wp kntnt-gpx regenerate` WP-CLI command. |
| `Updater` | Checks GitHub Releases for a newer version. See [`updater.md`](updater.md). |

The block source (TypeScript) lives under `src/blocks/<slug>/` and follows the file layout in [`coding-standards.md`](coding-standards.md).
