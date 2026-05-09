# Architecture

This document is the resolved architecture for `kntnt-gpx-blocks`. It supersedes the design brief in [`design.md`](design.md) wherever the two differ. For per-block specifications see [`blocks.md`](blocks.md), for the cache lifecycle see [`caching.md`](caching.md), for the consent integration see [`consent.md`](consent.md), for the security model see [`security.md`](security.md), and for the full hook reference see [`hooks.md`](hooks.md).

## Overview

The plugin registers two Gutenberg blocks — **GPX Map** and **GPX Elevation** — plus a **GPX Statistics** pattern fronted by a Block Bindings source `kntnt-gpx-blocks/statistics`. GPX Map is the data source. The Elevation block reads from it via a sibling binding model: a `mapId` attribute that defaults to `"auto"` and resolves at render time to "the single GPX Map on this page". The Statistics pattern is `core/group` + `core/paragraph` blocks; each value paragraph's `content` attribute is bound to the source above with a `key` arg and an optional `mapId` arg (defaulting to `"auto"`). The blocks and the pattern are designed to live next to each other in any layout — Map and Elevation communicate via the WordPress Interactivity API (not React state or DOM proximity), so they can be placed in different columns, groups, or other containers without losing the cursor synchronisation.

## Data flow

A GPX file enters the system through the WordPress media library. On `add_attachment` the plugin parses it once with `XMLReader` (streaming), converts it to GeoJSON, computes the five summary statistics (distance, min/max elevation, ascent, descent), and stores both results plus a version stamp and a content hash as post-meta on the attachment. The original file is left untouched. On every subsequent render — whether of a block or of a paragraph bound to the statistics source — the cached data is read from post-meta with no re-parsing. If the file is replaced (`attachment_updated` fires, or the cached hash no longer matches the file's current hash), conversion runs again and overwrites the cache. If the cache version stamp is older than the plugin's current cache version, lazy regeneration kicks in on first render. The `wp kntnt-gpx regenerate` WP-CLI command forces regeneration on demand. See [`caching.md`](caching.md) for the full lifecycle.

## Block coupling

Each GPX Map gets an auto-generated 6-character base36 `mapId` on first edit. The GPX Elevation block has a `mapId` attribute (default `"auto"`) and the GPX Statistics pattern's bound paragraphs accept an optional `mapId` arg in their bindings (also defaulting to `"auto"`). At render time the server enumerates GPX Map blocks in the post content via `parse_blocks()`. When `mapId === "auto"` and exactly one map exists, it resolves to that map. When more than one exists, the consumer renders an error state — for the Elevation block, an editor-only `.kntnt-gpx-blocks-error` notice asking for an explicit pick; for the Statistics pattern, the bound paragraphs render with empty values (the binding callback can only return text, not an HTML notice — a deliberate trade-off for the simpler architecture). The Elevation block's editor sidebar offers a `SelectControl` "Datakälla" with the auto option plus every Map on the page, labelled by GPX filename; the Statistics pattern accepts an explicit `mapId` only via the editor's source view (`"args":{"key":"...","mapId":"..."}`).

Cross-scope discovery is **not** supported in v1: the blocks and the pattern must all live in the same post content. A GPX Map in a template part and a GPX Elevation in the post will not connect. This is an intentional simplification — `parse_blocks()` against `$post->post_content` is fast and reliable, while resolving across templates and synced patterns at render time would require traversal that conflicts with the cache-friendliness goal.

## Rendering strategy

Both blocks are **dynamic**. Each `block.json` declares `"render": "file:./render.php"` and the `save` callback in JavaScript returns `null`. The render PHP file is a thin proxy that loads the autoloader and calls the appropriate `Render_*` class in `Kntnt\Gpx_Blocks\Rendering\`. The block's HTML output is deterministic given its attributes and the cached attachment data, which means edge caches (Cloudflare, Varnish, the WordPress page cache) can serve it without per-request work. The GPX Statistics pattern's bound paragraphs render through WordPress's standard Block Bindings dispatch (in `Kntnt\Gpx_Blocks\Bindings\Statistics_Source::get_value()`), which itself reads from the same `Attachment_Cache` and shares the same determinism guarantees.

Static rendering (a `save.tsx` returning HTML) was rejected because GPX-derived HTML must reflect the current cached GeoJSON: if the GPX file is replaced, every saved post that referenced it must show the new track without the editor opening and re-saving each post. Static rendering would freeze the GeoJSON in `post_content` and require a manual re-save on every content change.

## Hydration via Interactivity API

Client-side data — GeoJSON, waypoints, settings, statistics — reaches the browser through `wp_interactivity_state()`. The PHP render function calls `wp_interactivity_state( 'kntnt-gpx-blocks', [ $map_id => [ ... ] ] )` and emits a block element annotated with `data-wp-interactive='{"namespace":"kntnt-gpx-blocks"}'` and a `data-wp-context` carrying the `mapId`. The block's `viewScriptModule` (an ES module that imports `@wordpress/interactivity`) registers a store with `actions`, `callbacks`, and reactive state. On hydration, `data-wp-init` runs the block's mount callback (build the Leaflet map, build the SVG chart, etc.), and `data-wp-watch` keeps the cursor marker in sync with the shared state.

Inline `<script type="application/json">` payloads are **not** used. Hydration goes exclusively through the Interactivity API.

## Cross-block synchronisation

The Map and the Elevation chart share a single value: the cursor position along the track, expressed as a `fraction` in the range `[0, 1]`. **`fraction` means "fraction of total track distance along the full-fidelity track"**, not a vertex-index ratio and not a percentage along either block's own simplified data. Both blocks therefore resolve the same fraction to the same physical point regardless of how many vertices each one is rendering.

Two server-side state fields wire the shared semantics. `Render_Map` emits `trackCumDist[]` — the per-vertex cumulative Haversine distance along the *original* full-fidelity track, picked off at each surviving Douglas-Peucker vertex — and `totalDistance`, taken directly from `statistics.distance` in the cache. `Render_Elevation` emits the padded `floor`/`ceil` `yMin` / `yMax` it already uses to render the polyline, so the cursor's vertical position is computed against the same scale the line was drawn against. JS reads these fields once at mount time.

Resolving a fraction to a position is a binary search plus a linear interpolation in both blocks. The Map block searches `trackCumDist` for `fraction × totalDistance`, then linearly interpolates the latitude and longitude between the two adjacent simplified vertices. The Elevation block searches the LTTB downsampled distance array for the same value, linearly interpolates the `(distance, elevation)` sample, and projects it into SVG-space using the PHP-supplied `yMin` / `yMax`. The cursor therefore glides smoothly along the rendered polyline at every zoom level instead of jumping from one Douglas-Peucker vertex to the next, and sits exactly on the elevation curve at every fraction.

Writing a fraction goes the other way. A click on the Map's hit-layer is projected onto the nearest simplified segment, the projection parameter `t ∈ [0, 1]` is mapped through the segment's `trackCumDist` endpoints to obtain a cumulative distance, and the result is divided by `totalDistance`. A click on the Elevation chart maps its x-coordinate directly into a fraction of the chart's plot area. Both writes land in the same `state[mapId].fraction` slot.

Each block has its own `data-wp-watch` directive — `callbacks.onMapCursorChange` on the Map block, `callbacks.onElevationCursorChange` on the Elevation block — that reacts to the state change and moves its visible cursor (a Leaflet marker on the map, an SVG circle on the chart). Two distinct callback names are required because both view modules register into the same `kntnt-gpx-blocks` Interactivity store; using the same key in both modules' `callbacks` object would cause whichever module loaded second to overwrite the first, breaking sync in one direction.

The pure geometry helpers — binary search, segment projection, sample interpolation, SVG projection — live in `src/blocks/<map|elevation>/geometry.ts` and are exercised in co-located `geometry.test.ts` files via `wp-scripts test-unit-js`.

Resolution-independent payload was the deliberate choice. The Map polyline is geographically simplified (Douglas-Peucker, ~5 m tolerance); the Elevation profile is downsampled (LTTB to ~300 points). The two have different point counts, so a point index would not work as a sync key. Distance in metres would, but it would force every receiver to know the total track length without offering anything that fraction does not. Fraction has neither problem.

### Decided behavior: Elevation tooltip uses LTTB-interpolated values

The Elevation cursor's tooltip displays the `(distance, elevation)` linearly interpolated between the two adjacent LTTB samples. The cursor's *position* is on the rendered curve by construction (same data, same scale), but the *displayed values* are taken from the downsampled series and not from the original full-fidelity track. In practice the difference is small — LTTB picks 300 visually significant points so it preserves elevation peaks and troughs — and the tradeoff is deliberate: the alternative would mean shipping the full-fidelity `(distance, elevation)` series in state and binary-searching that for the displayed value. **If users observe tooltip elevations that disagree visibly with the surrounding terrain**, send the full-fidelity series in state and binary-search it for the displayed value (~25–125 kB extra payload, ~40 LOC). The cursor placement would not change — only the tooltip text.

## Editor integration

The GPX Map block's Edit component (in TypeScript, `edit.tsx`) shows a `MediaPlaceholder` until a GPX attachment is picked, then delegates to a parallel React component, `MapEditorPreview` (`src/blocks/map/editor-preview.tsx`), which mounts Leaflet directly inside the editor iframe. The preview fetches the cached GeoJSON via the plugin's REST endpoint `kntnt-gpx-blocks/v1/preview/<id>` (auth-gated to `edit_posts`) and renders a tile + polyline + waypoint preview. It is intentionally narrower than the frontend mount: no consent gating (the editor always shows a working map), no IntersectionObserver lazy mount, no controls, no cursor sync. Cosmetic attributes (colours, dimensions) flow through CSS custom properties on the wrapper element so the user gets instant feedback when dragging a colour slider; the polyline colour is also restyled in place because Leaflet's canvas-rendered paths cannot read CSS variables.

The editor preview is **non-interactive by design**. Every Leaflet interaction handler is disabled at construction time (`dragging`, `scrollWheelZoom`, `doubleClickZoom`, `touchZoom`, `boxZoom`, `keyboard`) and `editor.scss` adds `pointer-events: none` on `.leaflet-container` inside the block wrapper. The preview is a visual reference for "how does the block look on this page?", not a working map. Without this lock-down Leaflet's pan/zoom and Gutenberg's block-level drag handler trade layout updates and the block visibly shrinks and grows as the user clicks. The frontend mount path (`view.ts`) honours the user's interaction toggles (`enableDrag`, `enablePinchZoom`, `enableDoubleClickZoom`, `enableKeyboard`) in full and adds the wheel handler described under *Frontend wheel handler* below — only the editor preview is locked.

## Frontend wheel handler

Leaflet's built-in `scrollWheelZoom` is replaced by a custom wheel listener attached to the block element. The handler classifies each `wheel` event into one of three actions based on `event.ctrlKey`/`event.metaKey` and `event.deltaMode`:

- `'zoom'` — `ctrlKey` or `metaKey` is set. Covers two cases: a real `Cmd`/`Ctrl`+wheel from a mouse user, and the pinch gesture on a macOS trackpad (which the OS delivers as a wheel event with `ctrlKey: true`). The handler `preventDefault()`s and calls `map.setZoomAround(map.mouseEventToLatLng(event), nextZoom)`.
- `'pan'` — no modifier and `deltaMode === 0` (pixel deltas). This is the trackpad two-finger pan signature. The handler `preventDefault()`s and calls `map.panBy([deltaX, deltaY], { animate: false })` so the map follows the gesture pixel-for-pixel.
- `'hint'` — no modifier and `deltaMode !== 0` (line/page deltas). This is the regular mouse wheel signature. The handler does **not** `preventDefault()` — the page scrolls normally — and surfaces an aria-live overlay reminding the user to hold the modifier to zoom. The overlay auto-dismisses after ~1.2 seconds of wheel idleness.

The classification is a heuristic (`deltaMode` is not strictly guaranteed to identify the input device) but it is correct on every evergreen browser × current OS combination. Because trackpad pinch sets `ctrlKey:true` regardless of physical key state, the `'zoom'` branch picks it up before the `'pan'` branch is consulted; users do not have to enable a separate "pinch zoom" setting for it to work. Box zoom (Shift+drag-rectangle) is not exposed at all — the rare-and-obscure feature traded off against attribute-surface area.

ServerSideRender is *not* used for the Map block's editor preview. The Interactivity API runtime does not bootstrap inside ServerSideRender's injected DOM in the editor — `data-wp-init` directives never fire there — so a previous attempt to share the frontend `view.ts` mount path with the editor produced a permanently empty container. The parallel React preview is the working architecture; `view.ts` stays the frontend-only path.

The Elevation block still uses `<ServerSideRender>` for its editor preview — the inline SVG markup is visible without any JavaScript runtime. The Statistics pattern needs no special editor preview: the pattern is plain `core/group` and `core/paragraph` blocks, the editor renders them as it renders any other paragraphs, and the bindings resolve via WordPress's standard editor dispatch (the same callback the frontend uses) so the editor sees real values from the page's GPX Map.

## Performance

Per design — confirmed and refined:

- **Track simplification.** Douglas-Peucker at server render time, 5 m default tolerance (filter `kntnt_gpx_blocks_track_simplification_meters`). The 5 m is a **perpendicular tolerance** — the maximum allowed deviation between the simplified chord and the original arc — not a sample rate or a minimum spacing. Only the rendered polyline is simplified; the full-fidelity GeoJSON in the attachment cache is never touched, and the Statistics binding source plus the Elevation block continue to read from it. Reduces a 5-hour recording from ~18 000 points to ~200–500 without visible quality loss at normal zoom. The perpendicular-tolerance interpretation matters for snap-to-roads planned routes (e.g. Topo GPS): a turn vertex *must* survive any reasonable tolerance because removing it produces a chord whose perpendicular distance to the original turn is by definition larger than the tolerance — geometric necessity, not heuristic. Long straight stretches between turns are where the simplification actually trims vertices.
- **Elevation downsampling.** Largest Triangle Three Buckets (LTTB) to 300 points by default (filter `kntnt_gpx_blocks_elevation_target_points`). Preserves visual peaks better than stride-based sampling.
- **Climb computation.** Hysteresis filter with 3 m default threshold (filter `kntnt_gpx_blocks_climb_threshold_meters`) computed once at conversion and stored in cache. Sub-threshold wobble — the dominant source of GPS noise — is rejected; real climbs are preserved exactly.
- **Canvas renderer.** Leaflet uses `L.canvas()` for the polyline so a single canvas paint replaces many SVG elements.
- **Lazy mount.** `view.ts` defers Leaflet initialisation until the block's container intersects the viewport (`IntersectionObserver`).

## Privacy

Map tiles are loaded from OpenStreetMap, which means visitor IPs reach a third party. Tile loading is gated by a CMP-neutral consent contract that the plugin itself defines: a PHP filter `kntnt_gpx_blocks_has_consent` (tristate — `true`/`false`/`null` with default-allow on absent), a JS global `window.kntnt_gpx_blocks` exposing `getConsent`/`mayProceed`/`onConsentChanged`, and an inbound JS event `kntnt_gpx_blocks:consent`. The plugin's own code makes *no* reference to any specific CMP — no `wp_has_consent()`, no `wp_listen_for_consent_change`, no Real-Cookie-Banner or Complianz hooks. The site builder writes a small glue snippet that bridges their CMP to this contract. The plugin renders no consent UI of its own; the CMP's content blocker is expected to reclaim the visual area when consent is denied. The plugin works fully — including loading tiles — when no CMP and no glue exist, because absent signal means permitted. In the WordPress block editor the consent contract is bypassed entirely: the editor always shows a working map. See [`consent.md`](consent.md) for the full normative contract.

The Elevation block and the Statistics pattern's bound paragraphs load no third-party resources and never invoke the consent filter. They render unconditionally.

## Error handling

Every render function checks for an error condition before rendering the block. Errors come in three categories: GPX-side (`'no-track'`, `'too-few-points'`, `'too-large'`, `'file-missing'`, `'parse-failed'`, `'wrong-mime'`), block-side (`'no-attachment'`, `'no-map'`, `'multiple-maps'`, `'map-not-found'`), and runtime (rare; would be a bug). Visitors without `edit_posts` capability see nothing — the block renders an empty string and is invisible on the page. Editors with the capability see a `.kntnt-gpx-blocks-error` notice with a clear English message (translated through `kntnt-gpx-blocks` text domain). Logging goes through `Plugin::error()` / `Plugin::warning()` / `Plugin::info()` / `Plugin::debug()`, gated by the `KNTNT_GPX_BLOCKS_LOG_LEVEL` constant (default `'error'`, `'none'` silences everything).

## Component map

The major classes in `classes/` (PSR-4 namespaced under `Kntnt\Gpx_Blocks`):

| Class | Responsibility |
|---|---|
| `Plugin` | Singleton entry point. Wires hooks. Provides `error()/warning()/info()/debug()`. Exposes `get_plugin_data()` and `get_plugin_file()` for the Updater. |
| `Bootstrap\Block_Registrar` | Registers the two blocks via `register_block_type()` and the `kntnt` block category. |
| `Bootstrap\Pattern_Registrar` | Registers the `kntnt` pattern category and the bundled `kntnt-gpx-blocks/statistics` pattern from `patterns/statistics.php`. |
| `Bootstrap\Mime_Registrar` | `upload_mimes` and `wp_check_filetype_and_ext` filters for `.gpx`. |
| `Bootstrap\Conversion_Hooks` | `add_attachment` / `attachment_updated` callbacks that trigger conversion. |
| `Conversion\Gpx_Parser` | XMLReader-based streaming parser. XXE-safe. |
| `Conversion\Geo_Json_Converter` | Translates parsed GPX into GeoJSON. |
| `Conversion\Statistics_Calculator` | Computes the five summary statistics. |
| `Cache\Attachment_Cache` | Reads and writes the post-meta cache. Handles version + hash checks. |
| `Cache\Cache_Version` | Carries the `CURRENT` typed constant used for cache-format versioning. |
| `Rendering\Douglas_Peucker` | Polyline simplification. |
| `Rendering\Lttb` | Elevation downsampling (Largest Triangle Three Buckets). |
| `Rendering\Resolve_Map_Id` | Locates the GPX Map for a given `mapId` (or `"auto"`) by parsing post content. |
| `Rendering\Render_Map` | Server-side render of GPX Map. |
| `Rendering\Render_Elevation` | Server-side render of GPX Elevation. |
| `Bindings\Statistics_Source` | Block Bindings source `kntnt-gpx-blocks/statistics`. Resolves a `(postId, mapId)` pair to a single formatted statistic per call; memoizes resolve+fetch across the five binding-key calls per pattern instance. |
| `Consent\Consent_Stub` | Builds the inline JS stub and the enqueue handle. The PHP filter `kntnt_gpx_blocks_has_consent` has no PHP-side resolver class — it is a plain `apply_filters()` call from `Render_Map`. |
| `Rest\Preview_Controller` | Editor-only REST endpoint (`kntnt-gpx-blocks/v1/preview/<id>`) that returns the cached GeoJSON for the Map block's React-based editor preview. Auth-gated to `edit_posts`. |
| `Format\Value_Formatter` | Locale-aware number formatting and unit selection (m vs km). |
| `Cli\Regenerate_Command` | `wp kntnt-gpx regenerate` WP-CLI command. |
| `Updater` | Checks GitHub Releases for a newer version. See [`updater.md`](updater.md). |

The block source (TypeScript) lives under `src/blocks/<slug>/` and follows the file layout in [`coding-standards.md`](coding-standards.md).
