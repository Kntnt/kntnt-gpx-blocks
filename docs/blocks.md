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
- **Persistence:** every attribute that is a colour or a font reference stores whatever the WordPress editor component delivers — for colours that is hex 3/4/6/8 (including alpha-bearing `#rrggbbaa` from the alpha-enabled `ColorPicker` surfaces); for typography presets it is a `var(--wp--preset--…)` reference. Empty/null falls back to a hardcoded default in CSS. The render path runs every colour value through `Rendering\Color_Sanitizer::sanitize()` before emitting it as a CSS custom property — see [`security.md`](security.md) for the full accepted/rejected list.
- **Block icon:** each block ships its own inline SVG icon authored as a React element in `src/blocks/<slug>/icon.tsx` and passed to `registerBlockType()` via the `icon` field on the settings object. Map uses a teardrop pin over a winding track segment; Elevation uses a mountain-profile polyline over a baseline. Both share the same 24x24 viewBox, `currentColor` strokes, 1.5 stroke width, and round caps/joins as the GPX Statistics variation icon (`js/statistics-variation.js`) so the three read as one cohesive family in the inserter, List View, breadcrumb, and Document Outline. The `icon` field is intentionally absent from `block.json` — when JS provides one, the JSON-side value would be ignored anyway, so we keep the editor-side asset out of the server-side metadata.

The GPX Statistics variation and its bindings source follow a different model — see *GPX Statistics variation* at the bottom of this doc. The variation lives in `js/statistics-variation.js` (a plain ES2022 file using `window.wp.blocks.registerBlockVariation`); the script is enqueued by `Bootstrap\Variation_Registrar` on `enqueue_block_editor_assets`. The bindings source is `kntnt-gpx-blocks/statistics`, registered by `Bindings\Statistics_Source` with `uses_context: ['postId']`.

## GPX Map

The data source. Renders an interactive Leaflet map with the recorded track, optional waypoint markers, and a cursor synced to GPX Elevation.

### Attributes

| Attribute | Type | Default | Notes |
|---|---|---|---|
| `attachmentId` | integer | `0` | WordPress attachment ID for the `.gpx` file. |
| `mapId` | string | `""` | Auto-generated 6-char base36 on first edit. Used as the Interactivity store key. |
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
| `waypointColor` | string | `""` | Marker dot colour for waypoints. |
| `tooltipShowName` | boolean | `true` | Show the GPX `name` as the first line of the waypoint tooltip when present. |
| `tooltipShowDesc` | boolean | `true` | Show the GPX `desc` as the second line of the waypoint tooltip when present. |
| `tooltipBackground` | string | `""` | Tooltip background colour. Hex 3/4/6/8 — alpha supported via `#RRGGBBAA`. Also used to colour the arrow tip so a semi-transparent background renders consistently. Empty falls back to the hardcoded CSS default. |
| `tooltipNameColor` | string | `""` | Name-line text colour. Hex 3/4/6/8. Empty falls back to the hardcoded CSS default. |
| `tooltipNameFontFamily` | string | `""` | Name-line font family. |
| `tooltipNameFontSize` | string | `""` | Name-line font size. |
| `tooltipNameFontWeight` | string | `"700"` | Name-line font weight. |
| `tooltipNameFontStyle` | string | `""` | Name-line font style. |
| `tooltipNameLineHeight` | string | `""` | Name-line line-height. |
| `tooltipNameLetterSpacing` | string | `""` | Name-line letter-spacing. |
| `tooltipNameTextDecoration` | string | `""` | Name-line text-decoration. |
| `tooltipNameTextTransform` | string | `""` | Name-line letter case. |
| `tooltipDescColor` | string | `""` | Description-line text colour. Hex 3/4/6/8. Empty falls back to the hardcoded CSS default. |
| `tooltipDescFontFamily` | string | `""` | Description-line font family. |
| `tooltipDescFontSize` | string | `""` | Description-line font size. |
| `tooltipDescFontWeight` | string | `""` | Description-line font weight. |
| `tooltipDescFontStyle` | string | `"italic"` | Description-line font style. |
| `tooltipDescLineHeight` | string | `""` | Description-line line-height. |
| `tooltipDescLetterSpacing` | string | `""` | Description-line letter-spacing. |
| `tooltipDescTextDecoration` | string | `""` | Description-line text-decoration. |
| `tooltipDescTextTransform` | string | `""` | Description-line letter case. |

### Editor UI

`InspectorControls` follow the WordPress Settings/Styles split. The Settings tab carries behaviour-shaping controls; the Styles tab carries appearance.

**Settings tab**, in order:

1. **Source** — `MediaUpload` for the `.gpx` file. Required. When empty, the block renders a `MediaPlaceholder` and skips the editor preview (`MapEditorPreview` for Map; `<ServerSideRender>` for Elevation).
2. **Controls** — toggles for the four control overlays.
3. **Interactions** — toggles for the four interaction modes (drag, pinch zoom, double-click zoom, keyboard). Scroll-wheel and box zoom are not toggles: the wheel handler is fixed (modifier-or-pinch zooms, two-finger pan pans when *Drag to pan* is enabled and otherwise surfaces the hint, mouse wheel surfaces the hint), and box zoom is removed.
4. **Waypoint info** — two toggles, `Show name` and `Show description`. Both default on. They control whether the corresponding line is rendered inside the per-marker tooltip; setting both off suppresses the tooltip entirely (no hover surface, no sticky-on-click).

**Styles tab**, in order:

1. **Color** — a single `PanelColorSettings` with `enableAlpha: true` exposing six entries in this order: **Track** (`trackColor`), **Cursor** (`trackCursorColor`), **Marker** (`waypointColor`), **Waypoint background** (`tooltipBackground`, alpha-bearing 8-digit hex; also drives the tooltip arrow tip so a semi-transparent background produces a semi-transparent arrow), **Waypoint name** (`tooltipNameColor`), **Waypoint description** (`tooltipDescColor`). Every colour defaults to empty; an unset value falls back to the hardcoded SCSS default.
2. **Waypoint info — Name** — a unified Typography `ToolsPanel` (the same `__experimentalToolsPanel` + `__experimentalToolsPanelItem` pattern core Paragraph and Group use) covering all seven aspects WordPress's standard typography surface offers: **Font** (`FontFamilyControl`), **Size** (`FontSizePicker`), **Appearance** (`FontAppearanceControl` — combined weight + style), **Line height** (`LineHeightControl`), **Letter spacing** (`LetterSpacingControl`), **Decoration** (`TextDecorationControl`), and **Letter case** (`TextTransformControl`). Each aspect can be enabled or disabled individually; an unset aspect inherits from the theme. "Reset all" clears every aspect.
3. **Waypoint info — Description** — same surface as *Name*, mapped to the `tooltipDesc*` attribute family.

The default styling renders the tooltip as a roughly 80% opaque black rectangle with a white bold name and a light grey italic description; the values live in `src/blocks/map/style.scss` as `--kntnt-gpx-blocks-tooltip-*` custom-property defaults and apply whenever the corresponding attribute is empty.

**Floating waypoint-info preview in the editor canvas.** The Map block's editor preview renders a non-interactive floating tooltip anchored to real track geometry so editors see live WYSIWYG feedback in context while they adjust the *Waypoint info* controls. The anchor is the first waypoint's projected position when at least one `Point` feature exists in the cached GeoJSON, otherwise the polyline's midpoint at fraction = 0.5 (computed by walking the LineString and summing Haversine distances client-side — mirrors the math in `Render_Elevation::build_distance_elevation_series` but stays in JS so the editor never crosses the PHP boundary at preview time). The preview is an absolutely-positioned `<div class="kntnt-gpx-blocks-tooltip-preview">` whose `left` / `top` are written by JS on every Leaflet `move` / `zoom` event via `latLngToContainerPoint()`, so panning or zooming the editor map keeps the tooltip pinned to its geographic anchor instead of letting it "swim" off-target. The preview's children mirror the runtime tooltip's per-line DOM (`<div class="kntnt-gpx-blocks-tooltip-name">…</div>`, `<div class="kntnt-gpx-blocks-tooltip-desc">…</div>`), so the same `--kntnt-gpx-blocks-tooltip-*` custom properties drive both surfaces — `MapEdit`'s `useBlockProps()` injects every tooltip variable inline on the wrapper so live edits in the inspector repaint the preview in lock-step. Sample text comes from the first waypoint's `name` / `desc` in the hydrated GeoJSON when present, otherwise from the translatable placeholders `__('Sample name')` / `__('Sample description')`. The preview hides when both *Show name* and *Show description* are off (an `&:empty { display: none; }` rule in `editor.scss` collapses the empty container) and is `pointer-events: none` so it never blocks Gutenberg's clicks underneath. The styles live in `src/blocks/map/editor.scss` so the floating preview never appears on the frontend.

Sizing is delegated to the standard core **Dimensions** panel: `block.json` declares `supports.dimensions: { aspectRatio: true, minHeight: true }`, so the editor surfaces the same aspect-ratio dropdown and min-height field used by core Cover, Image, and Group. The plugin no longer carries its own `aspectRatio` / `minHeight` / `maxHeight` attributes or its own Layout panel — the block stores the chosen values under the core `style.dimensions.*` slot like every other dimensions-aware core block, and the wrapper picks them up through `useBlockProps()` / `get_block_wrapper_attributes()` automatically. The SCSS baseline (`aspect-ratio: 3 / 1; min-height: 240px;`) applies whenever both Dimensions fields are empty.

The block toolbar additionally renders a `MediaReplaceFlow` inside `<BlockControls group="other">` once `attachmentId !== 0`, mirroring the core Image and File blocks. The popover surface is intentionally minimal — only the **Open Media Library** and **Upload** tabs are exposed (no URL input, no Reset/Remove) because either would create state inconsistent with the cross-block sync via `mapId`. The replace operation writes only `attachmentId`; `mapId`, all colour, typography, control, and interaction attributes (along with the core block-supports `style` slot that holds dimensions, border, shadow, and spacing values) are preserved byte-identically, and the existing `MapEditorPreview` re-fits the new track automatically when the attachment ID prop changes. The `mediaURL` is resolved from `core/core-data` (`getMedia( attachmentId )?.source_url`) so the toolbar shows the current filename. Before any attachment is picked the toolbar surface is empty and the original `MediaPlaceholder` flow handles the initial selection unchanged.

### Block supports

`block.json` declares `supports.align: [ "wide", "full" ]` (toolbar offers None / Wide / Full — `left`/`right`/`center` are intentionally excluded) and `supports.anchor: true` (Advanced panel exposes the HTML anchor field). `customClassName` stays at its core default (`true`) so the Advanced panel's "Additional CSS class(es)" field remains available. The frontend wrapper is emitted via `get_block_wrapper_attributes()`, so all three propagate to the rendered HTML alongside any third-party `render_block_data` filters.

The full `border` (color, radius, style, width) and `shadow` block supports are also enabled, so the editor's standard Border and Shadow panels are available on the block. The block root sets `overflow: hidden` in its stylesheet so border-radius cleanly clips Leaflet's absolutely-positioned tile layers to the rounded edge; `box-shadow` is unaffected by `overflow` on the same element, so shadows still render outside the wrapper. Note that `block.json`'s `supports.border` declaration alone is not enough on themes that have not opted into appearance tools or per-feature border settings in their own `theme.json` — core's editor-side `useHasBorderPanel()` reads the per-feature flags via `useSettings()`, and the panel disappears entirely when those flags are `false` (issue #87). To keep the editor experience uniform across themes, `Bootstrap\Theme_Json_Border_Optin` hooks `wp_theme_json_data_theme` and injects a `settings.blocks["kntnt-gpx-blocks/map"].border = { color, radius, style, width: true }` slice into the theme data layer (and the same for `kntnt-gpx-blocks/elevation`). The opt-in is scoped to the two blocks this plugin owns; no global theme settings are touched.

`supports.dimensions` enables `aspectRatio` and `minHeight`, which surface the standard core Dimensions panel for sizing. The chosen values land under `attributes.style.dimensions.*` like every other dimensions-aware core block and reach the wrapper as plain inline `aspect-ratio` / `min-height` styles via `get_block_wrapper_attributes()`. Empty fields fall back to the SCSS baseline (`aspect-ratio: 3 / 1; min-height: 240px;`).

`supports.spacing` enables only `margin`, deliberately omitting `padding` and `blockGap`. Leaflet absolutely-positions its `.leaflet-pane` elements against the wrapper's padding box, so a padding control would emit inline `padding` that has zero visible effect — the same anti-pattern as `blockGap` (the block has no inner blocks). Site builders who want a padded frame around the map compose it with `core/group`. GPX Elevation likewise exposes only `margin`: with the dedicated background-colour control removed, padding has no surface to inset the SVG against, so the padding control would change layout without producing a visible inset. Margin still applies because it positions the block within its surrounding flow.

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
    style="aspect-ratio:16/9;min-height:240px;--kntnt-gpx-blocks-track-color:#06c"
>
    <noscript><p class="kntnt-gpx-blocks-map-noscript"><!-- text fallback --></p></noscript>
</div>
```

Leaflet mounts directly into the block element when the consent contract permits it. The wrapper has explicit dimensions through core's `dimensions` block supports (the editor's chosen `aspect-ratio` / `min-height` arrive inline via `get_block_wrapper_attributes()`; the SCSS baseline `aspect-ratio: 3 / 1; min-height: 240px;` applies when both fields are empty), so the container always has well-defined dimensions at mount time. The plugin renders no consent UI of its own — the active CMP's content blocker is expected to reclaim the visual area when consent is denying. See [`consent.md`](consent.md) for the full consent contract.

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
5. Adds waypoint markers from the hydrated `waypoints` GeoJSON. Each marker carries a Leaflet tooltip whose body is built from per-line `<div>`s: `<div class="kntnt-gpx-blocks-tooltip-name">` for the GPX `name` (when `tooltipShowName` is on) and `<div class="kntnt-gpx-blocks-tooltip-desc">` for the GPX `desc` (when `tooltipShowDesc` is on). Both row contents come from GPX content and are inserted via `textContent`, so source markup never reaches the DOM as HTML. When both toggles are off — or when the source has neither `name` nor `desc` — no tooltip is bound to the marker; sticky-on-click only applies to markers with a bound tooltip.
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
2. **Colours** — `PanelColorSettings` for the six colours.
3. **Axis typography** — the unified Typography `ToolsPanel` (the same `__experimentalToolsPanel` + `__experimentalToolsPanelItem` pattern used by core Paragraph and Group), exposing three aspects through the per-aspect dropdown menu: **Font** (`FontFamilyControl`, fed by `useSettings('typography.fontFamilies')`), **Size** (`FontSizePicker`, fed by `useSettings('typography.fontSizes')`), and **Appearance** (`FontAppearanceControl`, which writes to `axisFontWeight` and `axisFontStyle` as a single combined control). Each aspect can be enabled or disabled individually; an unset aspect reads as "Standard" and inherits from the theme. "Reset all" clears every aspect at once.
4. **Tooltip typography** — the same `ToolsPanel` shape, writing into the `tooltipFont*` attribute group instead of the `axisFont*` group.

Sizing is delegated to the standard core **Dimensions** panel exactly like GPX Map: `block.json` declares `supports.dimensions: { aspectRatio: true, minHeight: true }`, so the editor surfaces the standard aspect-ratio dropdown and min-height field with no plugin-specific Layout panel, no `aspectRatio` / `minHeight` attributes, and no custom validation regex. The SCSS baseline (`aspect-ratio: 4 / 1; min-height: 120px;`) applies whenever both Dimensions fields are empty.

### Block supports

`block.json` declares `supports.align: [ "wide", "full" ]` (toolbar offers None / Wide / Full — `left`/`right`/`center` are intentionally excluded) and `supports.anchor: true` (Advanced panel exposes the HTML anchor field). `customClassName` stays at its core default (`true`). Both the normal-data path and the empty-data fallback emit their wrapper through `get_block_wrapper_attributes()`, so alignment, anchor, and additional className all propagate even when the track has no elevation samples.

The full `border` (color, radius, style, width) and `shadow` block supports are also enabled, so the editor's standard Border and Shadow panels are available on the block. The block root sets `overflow: hidden` in its stylesheet so border-radius cleanly clips the inline SVG to the rounded edge; `box-shadow` is unaffected by `overflow` on the same element, so shadows still render outside the wrapper. The same `wp_theme_json_data_theme` opt-in described in the Map block's *Block supports* section above also enables the Border panel for this block (`Bootstrap\Theme_Json_Border_Optin`); without it themes that haven't enabled appearance tools or per-feature border settings in their own `theme.json` would hide the panel entirely (issue #87).

`supports.dimensions` enables `aspectRatio` and `minHeight`, with the same plumbing as GPX Map (see the Map's *Block supports* section above). Empty fields fall back to the SCSS baseline (`aspect-ratio: 4 / 1; min-height: 120px;`).

`supports.spacing` enables only `margin` (not `padding`, and not `blockGap` — the latter being meaningless without inner blocks). Padding was previously offered because the inline SVG paints into the wrapper's content box and would respect padding like ordinary block content; with the dedicated background-colour control removed, however, there is no surface to inset the SVG against, so the padding control changed layout without producing a visible inset and was dropped. Site builders who want a padded frame around the chart compose it with `core/group`, exactly as for GPX Map.

### Render output

```html
<div
    class="wp-block-kntnt-gpx-blocks-elevation kntnt-gpx-blocks-elevation"
    data-wp-interactive='{"namespace":"kntnt-gpx-blocks"}'
    data-wp-context='{"mapId":"map-abc123"}'
    data-wp-init="callbacks.initElevation"
    data-wp-watch="callbacks.onElevationCursorChange"
    style="aspect-ratio:4/1;min-height:120px;--kntnt-gpx-blocks-line-color: #06c;"
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

The tooltip is rendered as a single SVG `<text>` element with two `<tspan>` children — distance on the first row, elevation on the second — both centred horizontally. The default `--kntnt-gpx-blocks-tooltip-font-size` is `16px` so the two rows stay legible at the chart's default `4/1` aspect ratio and at any other ratio the editor sets via the core Dimensions panel; editors can still override the size from the *Tooltip typography* panel. Distance switches from metres to kilometres at 1000 m to match the x-axis; elevation is always whole metres.

**Editor-only cursor preview.** When the block is rendered through the REST `block-renderer` endpoint (the editor's `<ServerSideRender>` preview) and the requesting user has `edit_posts`, `Render_Elevation::build_svg()` server-renders the cursor group visible at `fraction = 0.5` with the corresponding LTTB-interpolated distance and elevation values pre-filled into the tooltip. The cursor group carries a `data-preview="1"` attribute so `view.ts`'s `onElevationCursorChange` watch knows to skip the hide-on-undefined-fraction branch during the initial mount-time fire. The first real `fraction` update — from the user scrubbing the chart, or from a sibling Map block — clears the attribute and the cursor follows live state from that point on. The frontend (non-editor) render path is unchanged: the cursor group still carries `style="display:none"` and `view.ts` reveals it on the first `pointermove`. This mirrors the Map block's floating waypoint-info preview pattern: editors get live feedback for the Cursor / Tooltip background / Tooltip text colour controls and the Tooltip typography panel without having to interact with the chart first.

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
- **Icon:** an inline SVG (three vertical bars over a short winding track segment) authored in `js/statistics-variation.js` via `window.wp.element.createElement` and passed as the `icon` field on the `registerBlockVariation` call. Drawn with `currentColor` strokes so it adapts to editor light/dark chrome, matching the GPX Map and GPX Elevation block icons in stroke weight, viewBox, and optical density.
- **Source file:** `js/statistics-variation.js`. Plain ES2022 that calls `window.wp.blocks.registerBlockVariation('core/group', { ... })`, uses `window.wp.i18n.__()` for label translation, and uses `window.wp.element.createElement` to build the inline SVG icon. Enqueued by `Bootstrap\Variation_Registrar` on `enqueue_block_editor_assets` with the script handle `kntnt-gpx-blocks-statistics-variation` and dependencies on `wp-blocks`, `wp-element`, and `wp-i18n`. `wp_set_script_translations()` wires the `__()` calls into the plugin's text domain.
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

### Editor preview

The editor needs richer feedback than the front-end render provides. Without intervention, every bound paragraph in the editor falls back to the source's `label` ("GPX statistics") because the bindings system shows the label whenever the resolved value is an empty string — and `Statistics_Source::get_value()` deliberately returns `''` from any error path, so the editor would show that label uniformly across all five rows even after a Map block is configured.

Two editor-only assets fix this:

- A REST endpoint, `GET /wp-json/kntnt-gpx-blocks/v1/statistics-preview?postId={int}&mapId={string}`, served by `Rest\Statistics_Preview_Controller` (capability-gated to `edit_posts`). It runs the same `Resolve_Map_Id` + `Attachment_Cache` + `Value_Formatter` chain as the bindings source and returns `{ attachmentId, mapId, values: { distance, min_elevation, max_elevation, ascent, descent } }`. Each value is the formatted string the front end would render, or `null` for statistics the track does not carry (e.g. a no-elevation GPX). On any error, the response is a `WP_Error` whose code matches the documented vocabulary (`no-map`, `multiple-maps`, `map-not-found`, `parse-failed`, `file-missing`, `too-large`, `wrong-mime`, `no-track`, `too-few-points`).

- An editor-only script, `js/statistics-preview.js`, registers an `editor.BlockEdit` HOC (via `wp.hooks.addFilter`) that wraps `core/paragraph`'s edit component for paragraphs whose `metadata.bindings.content.source` equals `kntnt-gpx-blocks/statistics`. The HOC reads the host post id from `core/editor`, derives a stable fingerprint of all GPX Map blocks in the live block tree (`useSelect( select => select( 'core/block-editor' ).getBlocks() )`), and fetches the resolved values via `wp.apiFetch`. Concurrent fetches for the same `(postId, mapId)` pair coalesce on a module-level promise cache; the fingerprint busts the cache whenever a Map block is added, removed, or its `attachmentId` / `mapId` attribute changes. The HOC then hands the wrapped `BlockEdit` a shallow-cloned attributes object whose `content` field carries an inline `<span class="kntnt-gpx-blocks-statistics-preview …">` with the formatted value (or fallback hint, or em-dash for null statistics). The override never reaches `setAttributes`, so the saved post content stays as the empty string the bindings system writes. The matching stylesheet, `css/statistics-preview.css`, colours the span with `var(--wp-block-synced-color, #7a00df)` — the same purple Gutenberg uses for the connected/bound attribute indicator chip — making the visual contract self-evident: purple text in the editor is a dynamic source-resolved value, not authored content. Italic styling is reserved for the fallback hints (`"GPX Map not found"`, `"Add a GPX Map to see values"`, etc.) so resolved values and error states are visually distinct.

The script and stylesheet are enqueued in the block editor by `Bootstrap\Variation_Registrar` alongside `js/statistics-variation.js`. Both are plain ES2022 / plain CSS — no `@wordpress/scripts` build step. The front-end render path is unaffected: visitors continue to see the values produced by `Statistics_Source::get_value()` with no plugin-specific markup or styling.
