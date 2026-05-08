# Block specifications

This document specifies each of the three blocks: attributes, editor UI, render output, interactivity behaviour, accessibility. Read it when implementing or modifying a block. For the data flow that feeds the blocks, see [`architecture.md`](architecture.md). For the cache the blocks read from, see [`caching.md`](caching.md).

## Common to all three blocks

- **Block API:** v3.
- **Namespace:** `kntnt-gpx-blocks/<slug>` — concrete names are `kntnt-gpx-blocks/map`, `kntnt-gpx-blocks/elevation`, `kntnt-gpx-blocks/statistics`.
- **Category:** `kntnt` (display name "Kntnt"), registered by `Bootstrap\Block_Registrar`.
- **Text domain:** `kntnt-gpx-blocks`.
- **Rendering:** dynamic. `block.json` declares `"render": "file:./render.php"` and the `save` callback returns `null`.
- **Frontend interactivity:** `viewScriptModule` (ES module) imports `@wordpress/interactivity`.
- **Editor preview:** for the Map block, a React component (`MapEditorPreview` in `src/blocks/map/editor-preview.tsx`) mounts Leaflet directly using GeoJSON fetched from the plugin's REST endpoint `kntnt-gpx-blocks/v1/preview/<id>` — `view.ts` is frontend-only. For Elevation and Statistics, `<ServerSideRender>` is used (their server-rendered SVG / `<dl>` is visible without any client-side runtime).
- **Persistence:** every attribute that is a colour or a font reference stores whatever the WordPress editor component delivers — typically a hex string for colours and a `var(--wp--preset--…)` reference for typography presets. Empty/null falls back to a hardcoded default in CSS.

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

1. **Source** — `MediaUpload` for the `.gpx` file. Required. When empty, the block renders a `MediaPlaceholder` and skips the editor preview (`MapEditorPreview` for Map; `<ServerSideRender>` for Elevation and Statistics).
2. **Layout** — aspect-ratio dropdown (`1/1`, `4/3`, `3/2`, `16/9`, `21/9`, custom), min-height, optional max-height.
3. **Controls** — toggles for the four control overlays.
4. **Interactions** — toggles for the four interaction modes (drag, pinch zoom, double-click zoom, keyboard). Scroll-wheel and box zoom are not toggles: the wheel handler is fixed (modifier-or-pinch zooms, two-finger pan pans, mouse wheel surfaces a hint), and box zoom is removed.
5. **Track** — `PanelColorSettings` for `trackColor` and `trackCursorColor`.
6. **Waypoints** — `PanelColorSettings` for marker colour, label colours, and a typography group for the label font.

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
8. Attaches the wheel handler on the block element: `Cmd`/`Ctrl`+wheel and trackpad pinch (delivered as a wheel event with `ctrlKey:true`) zoom around the cursor; trackpad two-finger pan (wheel with `deltaMode === 0` and no modifier) pans the map; a regular mouse wheel (`deltaMode === 1+` and no modifier) does not move the map but surfaces a brief overlay reminding the user to hold the modifier to zoom, and the page scrolls past the map normally.

The shared `state[mapId].fraction` is also written by the GPX Elevation block. The Map's watch callback is named `onMapCursorChange` (not `onCursorChange`) so that the two blocks' callbacks do not collide when both register into the shared `kntnt-gpx-blocks` Interactivity store. See [`architecture.md`](architecture.md) § *Cross-block sync*.

### Accessibility

- `role="application"` and `aria-label` on the map container (translated string, e.g. "Map of GPX track").
- Keyboard interaction via `L.keyboard` enabled by default.
- Controls have ARIA labels through Leaflet's defaults.
- Waypoint markers use `<title>` for screen readers.

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
4. **Axis typography** — `FontFamilyControl`, `FontSizePicker`, weight, style.
5. **Tooltip typography** — same group, separate values.

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

### Errors

| Code | Trigger |
|---|---|
| `'no-map'` | No GPX Map block on page. |
| `'multiple-maps'` | More than one map and `mapId === "auto"`. |
| `'map-not-found'` | Explicit `mapId` doesn't match any Map. |
| `'no-elevation'` | The track has no `<ele>` data. Renders an empty-state message instead of the chart. |

The Map's own errors propagate up if the underlying attachment is broken — Elevation shows the same error.

## GPX Statistics

Server-rendered HTML summary. No JavaScript required for this block on the frontend. Cursor sync is not applicable.

### Attributes

| Attribute | Type | Default | Notes |
|---|---|---|---|
| `mapId` | string | `"auto"` | Same picker logic as Elevation. |
| `headerBackground` | string | `""` | Background for the `<dt>` part. |
| `headerColor` | string | `""` | Text colour for headers. |
| `headerFontFamily` | string | `""` | |
| `headerFontSize` | string | `""` | |
| `headerFontWeight` | string | `""` | |
| `headerFontStyle` | string | `""` | |
| `valueBackground` | string | `""` | Background for the `<dd>` part. |
| `valueColor` | string | `""` | |
| `valueFontFamily` | string | `""` | |
| `valueFontSize` | string | `""` | |
| `valueFontWeight` | string | `""` | |
| `valueFontStyle` | string | `""` | |

### Editor UI

`InspectorControls`:

1. **Data source** — same `SelectControl` as Elevation.
2. **Headers** — `PanelColorSettings` for header colours, typography group.
3. **Values** — same panels for the value typography.

### Render output

```html
<dl class="wp-block-kntnt-gpx-blocks-statistics kntnt-gpx-blocks-statistics" style="--kntnt-gpx-blocks-header-color: #1e1e1e;">
    <div class="kntnt-gpx-blocks-statistics-item">
        <dt>Total length</dt>
        <dd>12.3 km</dd>
    </div>
    <div class="kntnt-gpx-blocks-statistics-item">
        <dt>Lowest elevation</dt>
        <dd>123 m</dd>
    </div>
    <!-- Highest elevation, Total ascent, Total descent -->
</dl>
```

When the track has no elevation data, the four elevation rows are omitted entirely. When the track has too few points, the block renders an error state (visible only to editors). The `<div>` wrapper around `<dt>` / `<dd>` enables CSS Grid layout that wraps to 1–2 items per row in narrow columns and shows all five in a row on wide screens.

The block has **no** JavaScript on the frontend. Values are formatted server-side via `Format\Value_Formatter`, which uses `number_format_i18n()` for locale-aware decimals and applies the auto-metric unit selection (m below 1000, km above) described in [`architecture.md`](architecture.md).

### Errors

Same set as Elevation: `'no-map'`, `'multiple-maps'`, `'map-not-found'`, plus the upstream Map errors.
