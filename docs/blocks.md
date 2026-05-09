# Block specifications

This document specifies the two Gutenberg blocks (GPX Map, GPX Elevation) and the GPX Statistics block-variation + bindings source: attributes, editor UI, render output, interactivity behaviour, accessibility. Read it when implementing or modifying any of the three. For the data flow that feeds them, see [`architecture.md`](architecture.md). For the cache they read from, see [`caching.md`](caching.md).

## Common to both blocks

- **Block API:** v3.
- **Namespace:** `kntnt-gpx-blocks/<slug>` — concrete names are `kntnt-gpx-blocks/map` and `kntnt-gpx-blocks/elevation`.
- **Category:** `kntnt` (display name "Kntnt"), registered by `Bootstrap\Block_Registrar`.
- **Text domain:** `kntnt-gpx-blocks`.
- **Rendering:** dynamic. `block.json` declares `"render": "file:./render.php"` and the `save` callback returns `null`.
- **Frontend interactivity:** `viewScriptModule` (ES module) imports `@wordpress/interactivity`.
- **Editor preview:** for the Map block, a React component (`MapEditorPreview` in `src/blocks/map/editor-preview.tsx`) mounts Leaflet directly using GeoJSON fetched from the plugin's REST endpoint `kntnt-gpx-blocks/v1/preview/<id>` — `view.ts` is frontend-only. For Elevation, `<ServerSideRender>` is used (its server-rendered SVG is visible without any client-side runtime); the editor forwards the live block tree as `__editorBlockSnapshot` (a `role: "local"` attribute) so sibling-Map resolution reflects unsaved edits — see [`architecture.md`](architecture.md) *Editor integration*.
- **Persistence:** every attribute that is a colour or a font reference stores whatever the WordPress editor component delivers — typically a hex string for colours and a `var(--wp--preset--…)` reference for typography presets. Empty/null falls back to a hardcoded default in CSS.

The GPX Statistics variation and its bindings source follow a different model — see *GPX Statistics variation* at the bottom of this doc. The variation lives in `js/statistics-variation.js` (a plain ES2022 file using `window.wp.blocks.registerBlockVariation`); the script is enqueued by `Bootstrap\Variation_Registrar` on `enqueue_block_editor_assets`. The bindings source is `kntnt-gpx-blocks/statistics`, registered by `Bindings\Statistics_Source` with `uses_context: ['postId']`.

## GPX Map

The data source. Renders an interactive Leaflet map with the recorded track, optional waypoint markers, and a cursor synced to GPX Elevation.

### Attributes

| Attribute | Type | Default | Notes |
|---|---|---|---|
| `attachmentId` | integer | `0` | WordPress attachment ID for the `.gpx` file. |
| `mapId` | string | `""` | Auto-generated 6-char base36 on first edit. Used as the Interactivity store key. |
| `aspectRatio` | string | `"16/9"` | CSS `aspect-ratio` value. |
| `minHeight` | string | `"240px"` | CSS `min-height` fallback for very narrow containers. |
| `maxHeight` | string | `""` | Optional cap. Empty = `none`. |
| `showZoomButtons` | boolean | `true` | `L.Control.Zoom`. |
| `showScale` | boolean | `true` | `L.Control.Scale`. |
| `showFullscreen` | boolean | `false` | `Leaflet.fullscreen` plugin. |
| `showDownload` | boolean | `false` | Custom control that links to the original GPX file. |
| `enableDrag` | boolean | `true` | `dragging`. |
| `enablePinchZoom` | boolean | `true` | `touchZoom` (real touch screens). Trackpad pinch on macOS is handled by the wheel handler — see *Wheel handler* below. |
| `enableDoubleClickZoom` | boolean | `true` | `doubleClickZoom`. |
| `enableKeyboard` | boolean | `true` | `keyboard`. Required for accessibility. |
| `trackColor` | string | `""` | Polyline colour. Empty falls back to the hardcoded CSS default. |
| `trackCursorColor` | string | `""` | Cursor marker colour on the polyline. |
| `waypointColor` | string | `""` | Marker colour for waypoints. |
| `waypointLabelBackground` | string | `""` | Hover label background. |
| `waypointLabelColor` | string | `""` | Hover label text colour. |
| `waypointLabelFontFamily` | string | `""` | Hover label font family. |
| `waypointLabelFontSize` | string | `""` | Hover label font size. |
| `waypointLabelFontWeight` | string | `""` | Hover label font weight. |
| `waypointLabelFontStyle` | string | `""` | Hover label font style. |

### Editor UI

`InspectorControls` panels, in order:

1. **Source** — `MediaUpload` for the `.gpx` file. Required. When empty, the block renders a `MediaPlaceholder` and skips the editor preview (`MapEditorPreview` for Map; `<ServerSideRender>` for Elevation).
2. **Layout** — aspect-ratio dropdown (`1/1`, `4/3`, `3/2`, `16/9`, `2/1`, `21/9`, `3/1`, `4/1`, custom), min-height, optional max-height. The same eight presets in the same order are exposed by GPX Elevation.
3. **Controls** — toggles for the four control overlays.
4. **Interactions** — toggles for the four interaction modes (drag, pinch zoom, double-click zoom, keyboard). Scroll-wheel and box zoom are not toggles: the wheel handler is fixed (modifier-or-pinch zooms, two-finger pan pans when *Drag to pan* is enabled and otherwise surfaces the hint, mouse wheel surfaces the hint), and box zoom is removed.
5. **Track** — `PanelColorSettings` for `trackColor` and `trackCursorColor`.
6. **Waypoints** — `PanelColorSettings` for marker colour and the two label colours.
7. **Waypoint label typography** — the unified Typography `ToolsPanel` (the same `__experimentalToolsPanel` + `__experimentalToolsPanelItem` pattern used by core Paragraph and Group), exposing three aspects through the per-aspect dropdown menu: **Font** (`FontFamilyControl`, fed by `useSettings('typography.fontFamilies')`), **Size** (`FontSizePicker`, fed by `useSettings('typography.fontSizes')`), and **Appearance** (`FontAppearanceControl`, which writes to the `waypointLabelFontWeight` and `waypointLabelFontStyle` attributes as a single combined control). Each aspect can be enabled or disabled individually; an unset aspect reads as "Standard" and inherits from the theme. "Reset all" clears every aspect at once. The four `waypointLabelFont*` attribute names and types are unchanged from earlier versions of the plugin, so existing posts keep rendering with their stored values.

### Block supports

`block.json` declares `supports.align: [ "wide", "full" ]` (toolbar offers None / Wide / Full — `left`/`right`/`center` are intentionally excluded) and `supports.anchor: true` (Advanced panel exposes the HTML anchor field). `customClassName` stays at its core default (`true`) so the Advanced panel's "Additional CSS class(es)" field remains available. The frontend wrapper is emitted via `get_block_wrapper_attributes()`, so all three propagate to the rendered HTML alongside any third-party `render_block_data` filters.

### Render output

```html
<div
    class="wp-block-kntnt-gpx-blocks-map kntnt-gpx-blocks-map"
    role="application"
    aria-label="Map of GPX track"
    data-wp-interactive='{"namespace":"kntnt-gpx-blocks"}'
    data-wp-context='{"mapId":"map-abc123"}'
    data-wp-init="callbacks.initMap"
    data-wp-watch--cursor="callbacks.onMapCursorChange"
    style="--kntnt-gpx-blocks-aspect-ratio: 16/9; --kntnt-gpx-blocks-min-height: 240px;"
>
    <noscript><p class="kntnt-gpx-blocks-map-noscript"><!-- text fallback --></p></noscript>
</div>
```

Leaflet mounts directly into the block element when the consent contract permits it; the wrapper has explicit `aspect-ratio` and `min-height` via inline CSS variables, so the container always has well-defined dimensions at mount time. The plugin renders no consent UI of its own — the active CMP's content blocker is expected to reclaim the visual area when consent is denying. See [`consent.md`](consent.md) for the full consent contract.

The state hydrated via `wp_interactivity_state()`:

```php
[
    'map-abc123' => [
        'attachmentId'   => 42,
        'geojson'        => /* simplified GeoJSON */,
        'waypoints'      => /* GeoJSON FeatureCollection */,
        'gpxFileUrl'     => /* attachment URL or null */,
        'settings'       => [ 'showZoomButtons' => true, /* ... */ ],
        'fraction'       => null,
        'bypassConsent'  => false, // true in the editor (REST block-renderer); false on the frontend.
    ],
]
```

The state slice carries no consent values — the consent decision lives in `window.kntnt_gpx_blocks` (the inline stub's internal Map, fed by the `kntnt_gpx_blocks:consent` event). The only consent-related state field is `bypassConsent`, set to `true` when PHP detects an editor render context so the JS can mount Leaflet immediately without consulting the contract.

### Interactivity behaviour

`callbacks.initMap` (registered in `view.ts`):

1. Reads `state[mapId]`.
2. If `bypassConsent` is `true` (editor context) **or** `window.kntnt_gpx_blocks.mayProceed( 'external_media' )` returns `true`, proceeds to step 3. Otherwise leaves the container empty and skips to step 6 (subscribe to consent transitions).
3. Defers Leaflet initialisation via `IntersectionObserver` until the block element enters the viewport. Builds a Leaflet map with `L.canvas()` renderer and `L.geoJSON()` from the cached GeoJSON, mounting directly into the block element.
4. Adds the configured controls and enables/disables interactions per `settings`.
5. Adds waypoint markers from the hydrated `waypoints` GeoJSON. Each marker has a hover tooltip showing `name` (line 1) and `desc` (line 2 if set), built with text nodes (no innerHTML).
6. Subscribes to consent transitions via `window.kntnt_gpx_blocks.onConsentChanged( handler )`. On a `'granting'` transition, mounts Leaflet (idempotent — guarded by the per-element `mountedMaps` WeakMap). On a `'denying'` transition, tears down via `map.remove()`. Editor bypass skips this subscription entirely.
7. Attaches the polyline scrub cycle: hover writes `fraction = index / (length - 1)` to `state[mapId].fraction`; press-and-drag on the polyline disables `map.dragging` for the duration of the press and follows the pointer over the entire map until release. A document-level `pointerup` ends the scrub and re-enables drag. A `pointerleave` on the block element nulls the fraction unless a scrub is in progress.
8. Attaches the wheel handler on the block element: `Cmd`/`Ctrl`+wheel and trackpad pinch (delivered as a wheel event with `ctrlKey:true`) zoom around the cursor; trackpad two-finger pan (wheel with `deltaMode === 0` and no modifier) pans the map *only when `enableDrag` is true* — when drag-to-pan is disabled in the sidebar, the gesture falls through to the hint path so the page scrolls and the modifier-key overlay surfaces (the "Drag to pan" toggle is honoured across all input modalities, not just mouse and single-touch drag); a regular mouse wheel (`deltaMode === 1+` and no modifier) does not move the map but surfaces a brief overlay reminding the user to hold the modifier to zoom, and the page scrolls past the map normally.

The shared `state[mapId].fraction` is also written by the GPX Elevation block. The Map's watch callback is named `onMapCursorChange` (not `onCursorChange`) so that the two blocks' callbacks do not collide when both register into the shared `kntnt-gpx-blocks` Interactivity store. See [`architecture.md`](architecture.md) § *Cross-block sync*.

### Accessibility

- `role="application"` and `aria-label` on the map container (translated string, e.g. "Map of GPX track").
- Keyboard interaction via `L.keyboard` enabled by default.
- Controls have ARIA labels through Leaflet's defaults.
- Waypoint markers use `<title>` for screen readers.
- Focus indicator: the browser-default focus ring around the block container and Leaflet's `.leaflet-container` is suppressed (it would otherwise wrap the whole map on every click because Leaflet's keyboard handler sets `tabindex="0"` on the container). A keyboard-only `:focus-visible` rule restores a 2 px `currentColor` outline so Tab navigation still produces a clearly visible focus indicator (WCAG 2.1 SC 2.4.7). Leaflet's individual control buttons keep their own focus styling.

### Errors

| Code | Trigger |
|---|---|
| `'no-attachment'` | `attachmentId === 0`. Edit shows `MediaPlaceholder`; frontend hides. |
| `'file-missing'` | Attachment exists but file is gone from disk. |
| `'parse-failed'` | Conversion failed (corrupted GPX). |
| `'too-large'` | File or trackpoint count exceeds the configured caps. |
| `'no-track'` | File has neither `<trk>` nor `<rte>`. |
| `'too-few-points'` | Fewer than two valid coordinates. |

## GPX Elevation

A custom-SVG elevation profile chart with cursor synchronisation to GPX Map.

### Attributes

| Attribute | Type | Default | Notes |
|---|---|---|---|
| `mapId` | string | `"auto"` | Resolves to the single Map on the page when `"auto"`. |
| `aspectRatio` | string | `"4/1"` | CSS `aspect-ratio`. Elevation profiles are typically wider than tall. |
| `minHeight` | string | `"120px"` | Hard fallback. |
| `backgroundColor` | string | `""` | SVG background. |
| `axisColor` | string | `""` | Axis lines. |
| `axisLabelColor` | string | `""` | Tick labels. |
| `lineColor` | string | `""` | The plotted line. |
| `cursorColor` | string | `""` | Cursor marker on the line. |
| `tooltipBackground` | string | `""` | Cursor tooltip box background. |
| `tooltipColor` | string | `""` | Cursor tooltip text. |
| `axisFontFamily` | string | `""` | |
| `axisFontSize` | string | `""` | |
| `axisFontWeight` | string | `""` | |
| `axisFontStyle` | string | `""` | |
| `tooltipFontFamily` | string | `""` | |
| `tooltipFontSize` | string | `""` | |
| `tooltipFontWeight` | string | `""` | |
| `tooltipFontStyle` | string | `""` | |

### Editor UI

`InspectorControls`:

1. **Data source** — `SelectControl` listing "Auto" and every GPX Map on the page (label = `"Karta {n}: {filename}"`). Open by default.
2. **Layout** — aspect-ratio + min-height.
3. **Colours** — `PanelColorSettings` for the seven colours.
4. **Axis typography** — the unified Typography `ToolsPanel` (the same `__experimentalToolsPanel` + `__experimentalToolsPanelItem` pattern used by core Paragraph and Group), exposing three aspects through the per-aspect dropdown menu: **Font** (`FontFamilyControl`, fed by `useSettings('typography.fontFamilies')`), **Size** (`FontSizePicker`, fed by `useSettings('typography.fontSizes')`), and **Appearance** (`FontAppearanceControl`, which writes to `axisFontWeight` and `axisFontStyle` as a single combined control). Each aspect can be enabled or disabled individually; an unset aspect reads as "Standard" and inherits from the theme. "Reset all" clears every aspect at once.
5. **Tooltip typography** — the same `ToolsPanel` shape, writing into the `tooltipFont*` attribute group instead of the `axisFont*` group. The four `axisFont*` and four `tooltipFont*` attribute names and types are unchanged from earlier versions of the plugin, so existing posts keep rendering with their stored values.

### Block supports

`block.json` declares `supports.align: [ "wide", "full" ]` (toolbar offers None / Wide / Full — `left`/`right`/`center` are intentionally excluded) and `supports.anchor: true` (Advanced panel exposes the HTML anchor field). `customClassName` stays at its core default (`true`). Both the normal-data path and the empty-data fallback emit their wrapper through `get_block_wrapper_attributes()`, so alignment, anchor, and additional className all propagate even when the track has no elevation samples.

### Render output

```html
<div
    class="wp-block-kntnt-gpx-blocks-elevation kntnt-gpx-blocks-elevation"
    data-wp-interactive='{"namespace":"kntnt-gpx-blocks"}'
    data-wp-context='{"mapId":"map-abc123"}'
    data-wp-init="callbacks.initElevation"
    data-wp-watch="callbacks.onElevationCursorChange"
    style="aspect-ratio: 4/1; --kntnt-gpx-blocks-line-color: #06c;"
>
    <svg viewBox="0 0 1200 300" role="img" aria-labelledby="kntnt-gpx-blocks-elevation-desc-map-abc123" preserveAspectRatio="none">
        <desc id="kntnt-gpx-blocks-elevation-desc-map-abc123"><!-- screen-reader summary --></desc>
        <!-- axes, polyline, server-rendered cursor group — all SVG elements -->
    </svg>
    <noscript><!-- summary text --></noscript>
</div>
```

The screen-reader summary in `<desc>` reads (translated): `"Elevation profile from {min} m at the start to {max} m after {distance}, with total ascent {ascent} m and descent {descent} m."`

### Interactivity behaviour

`callbacks.initElevation`:

1. Locates the server-rendered cursor group (`<g class="kntnt-gpx-blocks-elevation-cursor">`) inside the inline SVG. Reads the chart plot boundaries from `data-plot-left`, `data-plot-right`, `data-plot-top`, `data-plot-bottom` attributes on the group, which match the PHP `MARGIN_*` constants exactly.
2. Snapshots the LTTB-downsampled `(distance, elevation)` pairs from `state[mapId].elevation` at mount time.
3. Defers `pointermove` / `pointerleave` binding on the SVG until the block enters the viewport via `IntersectionObserver`. Pointer events compute `fraction` from the pointer's x-position relative to the plot area and write it to `state[mapId].fraction`.
4. The `callbacks.onElevationCursorChange` watch updates the cursor line x-position, the dot position, and the tooltip text whenever `state[mapId].fraction` changes (from either Elevation's own pointer events or from GPX Map). Does not write back to fraction (no feedback loop). Named per block so the Map module's watch callback (`onMapCursorChange`) survives the merge into the shared `kntnt-gpx-blocks` store.

The tooltip is rendered as a single SVG `<text>` element with two `<tspan>` children — distance on the first row, elevation on the second — both centred horizontally. The default `--kntnt-gpx-blocks-tooltip-font-size` is `16px` so the two rows stay legible at the chart's default `4/1` aspect ratio and at the other unified aspect-ratio presets; editors can still override it from the *Tooltip typography* panel. Distance switches from metres to kilometres at 1000 m to match the x-axis; elevation is always whole metres.

### Errors

| Code | Trigger |
|---|---|
| `'no-map'` | No GPX Map block on page. |
| `'multiple-maps'` | More than one map and `mapId === "auto"`. |
| `'map-not-found'` | Explicit `mapId` doesn't match any Map. |
| `'no-elevation'` | The track has no `<ele>` data. Renders an empty-state message instead of the chart. |

The Map's own errors propagate up if the underlying attachment is broken — Elevation shows the same error.

## GPX Statistics variation

The GPX Statistics summary is **not** a block. It is a *`core/group` block-variation* + a *Block Bindings source*. The variation provides the layout (a 2×3 grid of label/value paragraph pairs, first row spanning both columns) and is registered with `scope: ['inserter']` so it appears as a standalone item in the block inserter alongside GPX Map and GPX Elevation. The bindings source provides the data (one formatted statistic per binding key). All theming is whatever the user's theme + the standard core paragraph controls give — there is no plugin-specific theming surface.

### Block variation

- **Variation name:** `kntnt-gpx-blocks-statistics` (variation of `core/group`).
- **Title / description:** translated via JS-side `__()`.
- **Category:** `kntnt` (the existing block category registered by `Bootstrap\Block_Registrar`).
- **Scope:** `['inserter']` only — keeps the variation out of the Group placeholder picker so unrelated `core/group` insertions are unaffected.
- **Icon:** `chart-bar` (Dashicon string).
- **Source file:** `js/statistics-variation.js`. Plain ES2022 that calls `window.wp.blocks.registerBlockVariation('core/group', { ... })` and uses `window.wp.i18n.__()` for label translation. Enqueued by `Bootstrap\Variation_Registrar` on `enqueue_block_editor_assets` with the script handle `kntnt-gpx-blocks-statistics-variation` and dependencies on `wp-blocks` and `wp-i18n`. `wp_set_script_translations()` wires the `__()` calls into the plugin's text domain.
- **Inserted markup shape:**

```html
<!-- wp:group {"metadata":{"name":"GPX Statistics"},"layout":{"type":"grid","columnCount":2}} -->
  <!-- wp:group {"metadata":{"name":"Total length"},"style":{"layout":{"columnSpan":2}},"layout":{"type":"flex"}} -->
    <!-- wp:paragraph {"metadata":{"name":"Label"}} --><p><strong>Total length:</strong></p><!-- /wp:paragraph -->
    <!-- wp:paragraph {"metadata":{"name":"Value","bindings":{"content":{"source":"kntnt-gpx-blocks/statistics","args":{"key":"distance"}}}}} -->
      <p></p>
    <!-- /wp:paragraph -->
  <!-- /wp:group -->
  <!-- ... four more rows for min_elevation, max_elevation, ascent, descent ... -->
<!-- /wp:group -->
```

The `metadata.name` strings on the inner blocks (`Total length`, `Label`, `Value`, etc.) are deliberately fixed English — Gutenberg's `metadata.name` is editor-side metadata, and Core's own templates leave it as a fixed English string. The visitor-facing label paragraph content (`Total length:`) stays translated through the plugin's text domain.

Once the user inserts the variation, the markup is standard `core/group` and `core/paragraph` content — the variation's role ends at insertion time; the post_content carries no reference back to the variation name.

### Bindings source

- **Source name:** `kntnt-gpx-blocks/statistics`.
- **Class:** `Bindings\Statistics_Source` (held as a private property on `Plugin`, registered on `init`).
- **`uses_context`:** `['postId']`. Required because `core/paragraph` does not declare `postId` in its own context.
- **Args schema:**
  - `key` (required) — one of `'distance'`, `'min_elevation'`, `'max_elevation'`, `'ascent'`, `'descent'`. Other values resolve to an empty string + `Plugin::warning()`.
  - `mapId` (optional) — defaults to `'auto'`. Forwarded to `Resolve_Map_Id::resolve()` along with the host post ID.
- **Return value:** a string, locale-formatted via `Format\Value_Formatter` (the same formatter the plugin uses elsewhere). Distance gets auto-metric units (m below 1000, km above); elevations are always whole metres. Both go through the existing `kntnt_gpx_blocks_format_distance` and `kntnt_gpx_blocks_format_elevation` filters.
- **Error contract:** every error path (no map, multiple maps with `'auto'`, mapId not found, cache parse error, missing file, unknown key, missing postId) returns the empty string. The misconfiguration is logged once per render via `Plugin::error()` (resolve/cache errors) or `Plugin::warning()` (unknown key) — bindings cannot return HTML, so the editor's only signal is the visible empty values in the editor preview.
- **Per-request memoization:** an instance-level array keyed by `"$post_id|$map_id"` collapses the five binding-key calls per inserted variation into one map resolve + one cache fetch + one log line. The memo lives for the request only; cleared by PHP shutdown.

### Render output

The inserted markup is plain `core/group` + `core/paragraph`. The post_content persists as standard core blocks; only the `metadata.bindings` slot on each value paragraph references the plugin. There is no plugin-specific HTML wrapper, no plugin-specific CSS class, and no plugin-specific JS at render time.

When the track has no elevation data, the four elevation rows render with empty values (the binding callback returns `''` for null statistics). The static label paragraphs still render — the user can delete unwanted rows from the inserted variation if they want to hide them entirely.

### Errors (visitor side)

Bound paragraph values render as empty strings on every error path. Visitors see the static label "Lowest elevation:" with a blank value beside it. There is no editor-only `.kntnt-gpx-blocks-error` notice — the bindings API does not allow returning HTML for that purpose. The error is logged once per render via the plugin's logging API for editors who check `error_log`.
