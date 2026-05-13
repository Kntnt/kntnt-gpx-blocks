# Block specifications

This document specifies the two Gutenberg blocks (GPX Map, GPX Elevation) and the GPX Statistics block-variation + `[kntnt-gpx <key>]` shortcode: attributes, editor UI, render output, interactivity behaviour, accessibility. Read it when implementing or modifying any of the three. For the data flow that feeds them, see [`architecture.md`](architecture.md). For the cache they read from, see [`caching.md`](caching.md).

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

The GPX Statistics variation and the `[kntnt-gpx <key>]` shortcode follow a different model — see *GPX Statistics variation* at the bottom of this doc. The variation lives in `js/statistics-variation.js` (a plain ES2022 file using `window.wp.blocks.registerBlockVariation`); the script is enqueued by `Bootstrap\Variation_Registrar` on `enqueue_block_editor_assets`. The shortcode tag is `kntnt-gpx`, registered by `Bindings\Statistics_Shortcode` on `init`.

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
| `enableScrollWheelZoom` | boolean | `true` | Gates the wheel handler's `'zoom'` branch — both Cmd/Ctrl + wheel on a mouse and the trackpad-pinch gesture browsers deliver as a wheel event with `ctrlKey: true`. When off, the map is fully passive to wheel events: no zoom and no modifier-key hint overlay; the page scrolls past as if the map were a static image. The pan branch is independent and still obeys `enableDrag`. Touchscreen pinch is governed by `enablePinchZoom` and is unaffected. |
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
| `tileProvider` | string | `"openstreetmap"` | Base-tile provider id from the validated registry. Unknown ids fall back silently to `openstreetmap` (and to its default style). |
| `tileStyle` | string | `"mapnik"` | Style id within the selected provider. Unknown style ids inside a known provider fall back silently to the provider's own `default` style. The default seed (`openstreetmap` + `mapnik`) renders OpenStreetMap Mapnik. |
| `tileApiKeys` | object | `{}` | Per-provider API-key map keyed by provider id (e.g. `{ "mapbox": "ABC", "maptiler": "XYZ" }`). One key per provider, shared across all that provider's styles. The renderer looks up `tileApiKeys[ tileProvider ]` and substitutes it into `{KEY}` in the resolved tile URL. Switching providers preserves the other entries, so the editor keeps every previously-entered key. Surrounding whitespace is trimmed before substitution; whitespace-only entries are treated as empty. Missing entries (and a malformed/null attribute) coerce to the empty string and engage the polyline-only fall-back when the provider requires a key. |
| `tileOverlays` | string[] | `[]` | Ordered list of overlay-layer ids stacked on top of the base provider. Unknown ids are dropped at render time. |

### Editor UI

`InspectorControls` follow the WordPress Settings/Styles split. The Settings tab carries behaviour-shaping controls; the Styles tab carries appearance.

**Settings tab**, in order:

1. **Source** — `MediaUpload` for the `.gpx` file. Required. When empty, the block renders a `MediaPlaceholder` and skips the editor preview (`MapEditorPreview` for Map; `<ServerSideRender>` for Elevation).
2. **Controls** — toggles for the four control overlays.
3. **Interactions** — toggles for the five interaction modes (drag, pinch zoom, scroll-wheel zoom, double-click zoom, keyboard). Box zoom is not a toggle and is removed from the wheel handler. *Scroll-wheel zoom* gates every wheel-driven zoom branch — Cmd/Ctrl + wheel on a mouse and the trackpad-pinch gesture browsers deliver as a wheel event with `ctrlKey: true`; when off, the wheel handler also suppresses the modifier-key hint overlay because the "Hold ⌘ + scroll to zoom the map" message is misleading when zoom is disabled. The pan branch is independent and still surfaces the overlay only when *Drag to pan* is off and scroll-wheel zoom is on.
4. **Waypoint info** — two toggles, `Show name` and `Show description`. Both default on. They control whether the corresponding line is rendered inside the per-marker tooltip; setting both off suppresses the tooltip entirely (no hover surface, no sticky-on-click).

**Styles tab**, in order:

1. **Color** — a single `PanelColorSettings` with `enableAlpha: true` exposing six entries in this order: **Track** (`trackColor`), **Cursor** (`trackCursorColor`), **Marker** (`waypointColor`), **Waypoint background** (`tooltipBackground`, alpha-bearing 8-digit hex; also drives the tooltip arrow tip so a semi-transparent background produces a semi-transparent arrow), **Waypoint name** (`tooltipNameColor`), **Waypoint description** (`tooltipDescColor`). Every colour defaults to empty; an unset value falls back to the hardcoded SCSS default.
2. **Waypoint name** — a unified Typography `ToolsPanel` (the same `__experimentalToolsPanel` + `__experimentalToolsPanelItem` pattern core Paragraph and Group use) covering all seven aspects WordPress's standard typography surface offers: **Font** (`FontFamilyControl`), **Size** (`FontSizePicker`), **Appearance** (`FontAppearanceControl` — combined weight + style), **Line height** (`LineHeightControl`), **Letter spacing** (`LetterSpacingControl`), **Decoration** (`TextDecorationControl`), and **Letter case** (`TextTransformControl`). Each aspect can be enabled or disabled individually; an unset aspect inherits from the theme. "Reset all" clears every aspect.
3. **Waypoint description** — same surface as *Waypoint name*, mapped to the `tooltipDesc*` attribute family.

The default styling renders the tooltip as a roughly 80% opaque black rectangle with a white bold name and a light grey italic description; the values live in `src/blocks/map/style.scss` as `--kntnt-gpx-blocks-tooltip-*` custom-property defaults and apply whenever the corresponding attribute is empty.

**Floating waypoint-info preview in the editor canvas.** The Map block's editor preview renders a non-interactive floating tooltip anchored to real track geometry so editors see live WYSIWYG feedback in context while they adjust the *Waypoint info* controls. The anchor is the first waypoint's projected position when at least one `Point` feature exists in the cached GeoJSON, otherwise the polyline's midpoint at fraction = 0.5 (computed by walking the LineString and summing Haversine distances client-side — mirrors the math in `Render_Elevation::build_distance_elevation_series` but stays in JS so the editor never crosses the PHP boundary at preview time). The preview is an absolutely-positioned `<div class="kntnt-gpx-blocks-tooltip-preview">` whose `left` / `top` are written by JS on every Leaflet `move` / `zoom` event via `latLngToContainerPoint()`, so panning or zooming the editor map keeps the tooltip pinned to its geographic anchor instead of letting it "swim" off-target. The preview's children mirror the runtime tooltip's per-line DOM (`<div class="kntnt-gpx-blocks-tooltip-name">…</div>`, `<div class="kntnt-gpx-blocks-tooltip-desc">…</div>`), so the same `--kntnt-gpx-blocks-tooltip-*` custom properties drive both surfaces — `MapEdit`'s `useBlockProps()` injects every tooltip variable inline on the wrapper so live edits in the inspector repaint the preview in lock-step. Sample text comes from the first waypoint's `name` / `desc` in the hydrated GeoJSON when present, otherwise from the translatable placeholders `__('Sample name')` / `__('Sample description')`. The preview hides when both *Show name* and *Show description* are off (an `&:empty { display: none; }` rule in `editor.scss` collapses the empty container) and is `pointer-events: none` so it never blocks Gutenberg's clicks underneath. The styles live in `src/blocks/map/editor.scss` so the floating preview never appears on the frontend.

Sizing is delegated to the standard core **Dimensions** panel: `block.json` declares `supports.dimensions: { aspectRatio: true, minHeight: true }`, so the editor surfaces the same aspect-ratio dropdown and min-height field used by core Cover, Image, and Group. The plugin no longer carries its own `aspectRatio` / `minHeight` / `maxHeight` attributes or its own Layout panel — the block stores the chosen values under the core `style.dimensions.*` slot like every other dimensions-aware core block, and the wrapper picks them up through `useBlockProps()` / `get_block_wrapper_attributes()` automatically. The SCSS baseline (`aspect-ratio: 3 / 1; min-height: 30vh;`) applies whenever both Dimensions fields are empty. The plugin's `Rendering\Dimensions_Defaults` filter (registered on `render_block_data`, issue #117) normalises the missing-default state at the attribute source: when both `style.dimensions.minHeight` and `style.dimensions.aspectRatio` are blank or missing, the filter writes `style.dimensions.minHeight = '30vh'` (Map) or `'15vh'` (Elevation) onto the parsed block's `attrs` before the render pipeline reads it. Every downstream consumer — `get_block_wrapper_attributes()`, the SCSS baseline, the editor's `useBlockProps()` style merge — then sees a concrete value through the same path an explicit user value would take. The narrowed condition matters: when the user has picked a non-Original aspect-ratio, the container has a definite height from that, and adding a min-height would fight the aspect-ratio constraint, so the filter leaves the attribute untouched. The editor mirrors the rule through `src/blocks/shared/dimensions-defaults.ts` (`getDefaultMinHeight()`); `MapEdit` and `ElevationEdit` consult that helper and inject the same value inline via `useBlockProps()` so the editor preview wrapper agrees with the frontend wrapper byte-for-byte without writing back to `setAttributes` — the Minimum height field in the Dimensions panel still shows blank.

The block toolbar additionally renders a `MediaReplaceFlow` inside `<BlockControls group="other">` once `attachmentId !== 0`, mirroring the core Image and File blocks. The popover surface is intentionally minimal — only the **Open Media Library** and **Upload** tabs are exposed (no URL input, no Reset/Remove) because either would create state inconsistent with the cross-block sync via `mapId`. The replace operation writes only `attachmentId`; `mapId`, all colour, typography, control, and interaction attributes (along with the core block-supports `style` slot that holds dimensions, border, shadow, and spacing values) are preserved byte-identically, and the existing `MapEditorPreview` re-fits the new track automatically when the attachment ID prop changes. The `mediaURL` is resolved from `core/core-data` (`getMedia( attachmentId )?.source_url`) so the toolbar shows the current filename. Before any attachment is picked the toolbar surface is empty and the original `MediaPlaceholder` flow handles the initial selection unchanged.

### Block supports

`block.json` declares `supports.align: [ "wide", "full" ]` (toolbar offers None / Wide / Full — `left`/`right`/`center` are intentionally excluded) and `supports.anchor: true` (Advanced panel exposes the HTML anchor field). `customClassName` stays at its core default (`true`) so the Advanced panel's "Additional CSS class(es)" field remains available. The frontend wrapper is emitted via `get_block_wrapper_attributes()`, so all three propagate to the rendered HTML alongside any third-party `render_block_data` filters.

The full border (color, radius, style, width) and `shadow` block supports are also enabled, so the editor's standard Border and Shadow panels are available on the block. The block root sets `overflow: hidden` in its stylesheet so border-radius cleanly clips Leaflet's absolutely-positioned tile layers to the rounded edge; `box-shadow` is unaffected by `overflow` on the same element, so shadows still render outside the wrapper. Surfacing the Border panel in the editor requires two independent gates to be on, and the plugin owns both halves. The first half is the block-support key in `block.json`: the declaration is `supports.__experimentalBorder = { color, radius, style, width: true }`, not `supports.border` — Gutenberg's `packages/block-editor/src/hooks/border.js` reads `getBlockSupport( blockName, '__experimentalBorder' )` (constant `BORDER_SUPPORT_KEY`) and silently ignores the unprefixed key, so the wrong key means the editor never registers the `style.border` / `borderColor` attributes and the panel never tries to render (issue #107). The second half is theme.json: even with the block-support key correct, core's editor-side `useHasBorderColorControl()` (and its three siblings) reads `settings.border.color` etc. via `useSettings()`, and on themes that have not enabled appearance tools or per-feature border settings the panel disappears entirely (issue #87). `Bootstrap\Theme_Json_Border_Optin` closes the second gate by hooking `wp_theme_json_data_theme` and injecting a `settings.blocks["kntnt-gpx-blocks/map"].border = { color, radius, style, width: true }` slice into the theme data layer (and the same for `kntnt-gpx-blocks/elevation`). Note the asymmetry between the two surfaces: the block-support registry uses the experimental key `__experimentalBorder`, the theme.json schema uses the public key `border` — they are different APIs that happen to gate the same panel. The opt-in is scoped to the two blocks this plugin owns; no global theme settings are touched.

`supports.dimensions` enables `aspectRatio` and `minHeight`, which surface the standard core Dimensions panel for sizing. The chosen values land under `attributes.style.dimensions.*` like every other dimensions-aware core block and reach the wrapper as plain inline `aspect-ratio` / `min-height` styles via `get_block_wrapper_attributes()`. Empty fields fall back to the SCSS baseline (`aspect-ratio: 3 / 1; min-height: 30vh;`). The `Rendering\Dimensions_Defaults` filter (issue #117) writes `style.dimensions.minHeight = '30vh'` onto the parsed block's `attrs` when both `minHeight` and `aspectRatio` are blank — see the *Sizing* paragraph above for the full mechanism.

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
    style="min-height: 30vh; --kntnt-gpx-blocks-track-color: #06c;"
>
    <noscript><p class="kntnt-gpx-blocks-map-noscript"><!-- text fallback --></p></noscript>
</div>
```

Leaflet mounts directly into the block element when the consent contract permits it. The wrapper always has explicit dimensions: the editor's chosen `aspect-ratio` / `min-height` arrive inline via `get_block_wrapper_attributes()` when set; the `Dimensions_Defaults` filter (issue #117) normalises a blank-and-blank state to `min-height: 30vh` at the attribute source so the wrapper carries that value through the standard block-supports pipeline; and the SCSS baseline `aspect-ratio: 3 / 1; min-height: 30vh;` covers any path where neither of those reaches the wrapper. So the container always has well-defined dimensions at mount time. The frontend `view.ts` also keeps a backstop guard around `setMaxBounds` via `applyMaxBoundsIfSafe` (`bounds.ts`) — when the wrapper somehow still ends up 0×0 at mount, Leaflet's `getCenter()` and `getZoom()` can report `NaN`, and the helper short-circuits the rigid-pan constraint rather than letting Leaflet's internal unproject step crash on `Invalid LatLng object: (NaN, NaN)`. The plugin renders no consent UI of its own — the active CMP's content blocker is expected to reclaim the visual area when consent is denying. See [`consent.md`](consent.md) for the full consent contract.

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
3. Defers Leaflet initialisation via `IntersectionObserver` until the block element enters the viewport. Builds a Leaflet map with `L.canvas()` renderer and `L.geoJSON()` from the cached GeoJSON, mounting directly into the block element. After `fitBounds`, derives `maxBounds` from the rendered polyline's bbox via `paddedBoundsFromBox()` (in `src/blocks/map/bounds.ts`) and calls `map.setMaxBounds()` so panning is constrained to a region that always keeps at least part of the track inside the viewport. The Leaflet option `maxBoundsViscosity: 1.0` is set at map construction so the constraint is rigid; the option is inert until `setMaxBounds` is actually called. Degenerate single-point tracks are inflated to a small minimum span before padding so the user can still pan and zoom around the marker (issue #110).
4. Adds the configured controls and enables/disables interactions per `settings`.
5. Adds waypoint markers from the hydrated `waypoints` GeoJSON. Each marker carries a Leaflet tooltip whose body is built from per-line `<div>`s: `<div class="kntnt-gpx-blocks-tooltip-name">` for the GPX `name` (when `tooltipShowName` is on) and `<div class="kntnt-gpx-blocks-tooltip-desc">` for the GPX `desc` (when `tooltipShowDesc` is on). Both row contents come from GPX content and are inserted via `textContent`, so source markup never reaches the DOM as HTML. When both toggles are off — or when the source has neither `name` nor `desc` — no tooltip is bound to the marker; sticky-on-click only applies to markers with a bound tooltip.
6. Subscribes to consent transitions via `window.kntnt_gpx_blocks.onConsentChanged( handler )`. On a `'granting'` transition, mounts Leaflet (idempotent — guarded by the per-element `mountedMaps` WeakMap). On a `'denying'` transition, tears down via `map.remove()`. Editor bypass skips this subscription entirely.
7. Attaches the polyline scrub cycle: hover writes `fraction = index / (length - 1)` to `state[mapId].fraction`; press-and-drag on the polyline disables `map.dragging` for the duration of the press and follows the pointer over the entire map until release. A document-level `pointerup` ends the scrub and re-enables drag. A `pointerleave` on the block element nulls the fraction unless a scrub is in progress.
8. Attaches the wheel handler on the block element: `Cmd`/`Ctrl`+wheel and trackpad pinch (delivered as a wheel event with `ctrlKey:true`) zoom around the cursor *only when `enableScrollWheelZoom` is true* — when scroll-wheel zoom is disabled in the sidebar, every wheel event falls through to the hint path and the modifier-key overlay is suppressed too (the "Hold ⌘ + scroll to zoom the map" message is misleading when zoom is off altogether), so the map behaves as a passive static image for wheel input (issue #139); trackpad two-finger pan (wheel with `deltaMode === 0` and no modifier) pans the map *only when `enableDrag` is true* — when drag-to-pan is disabled in the sidebar, the gesture falls through to the hint path so the page scrolls and the modifier-key overlay surfaces (the "Drag to pan" toggle is honoured across all input modalities, not just mouse and single-touch drag), unless scroll-wheel zoom is also off, in which case the overlay is suppressed for the same reason; a regular mouse wheel (`deltaMode === 1+` and no modifier) does not move the map but surfaces a brief overlay reminding the user to hold the modifier to zoom (suppressed when scroll-wheel zoom is off), and the page scrolls past the map normally.

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

Typography is delegated to core's block-level `supports.typography` (no per-context attributes). See *Block supports* below for the declared aspects.

### Editor UI

`InspectorControls` follow the WordPress Settings/Styles split, mirroring the GPX Map block. The Settings tab carries the data-source picker; the Styles tab carries the Color panel. Typography is delegated to core's block-level `supports.typography` — core contributes its own standard Typography panel into the styles group automatically, with no `TypographyToolsPanel` rendered by this block's `edit.tsx`.

**Settings tab**:

1. **Data source** — `SelectControl` listing every configured GPX Map on the page (label = `"Karta {n}: {filename}"`). The panel is conditional on the configured-map count: with **zero** configured maps the panel is hidden entirely (the SSR layer surfaces a *No GPX Map block on this page* notice instead); with **exactly one** configured map the panel is hidden and an effect writes that map's `mapId` into this block so the binding is explicit even though the user never opened the picker; with **two or more** configured maps the panel is rendered and lists one option per configured map, with no leading "Auto" entry because it cannot resolve deterministically against multiple maps. On insert, a `useRef`-guarded effect (`use-auto-pick-map-id.ts`) walks the editor's top-level block order, finds the closest preceding `kntnt-gpx-blocks/map` block, and pre-sets `mapId` to that map's id; with no preceding Map the attribute keeps its `"auto"` default and resolves through the single-map fallback.

**Styles tab** (rendered through a second `<InspectorControls group="styles">` slot), in order:

1. **Color** — `PanelColorSettings` for the six colours.
2. **Typography** — contributed by core from `supports.typography`. The block declares all eight aspects (`fontFamily`, `fontSize`, `fontWeight`, `fontStyle`, `lineHeight`, `letterSpacing`, `textTransform`, `textDecoration`) with the standard `defaultControls` trio (Font, Size, Appearance) shown by default; the rest are reachable through the per-aspect dropdown menu. One typography setting applies to the whole block — both the axis-label HTML overlays and the cursor-tooltip text inherit from the block wrapper. Editors who want differentiated axis vs tooltip styling wrap the block in a `core/group` and override there.

Sizing is delegated to the standard core **Dimensions** panel exactly like GPX Map: `block.json` declares `supports.dimensions: { aspectRatio: true, minHeight: true }`, so the editor surfaces the standard aspect-ratio dropdown and min-height field with no plugin-specific Layout panel, no `aspectRatio` / `minHeight` attributes, and no custom validation regex. Under the wrapper-as-image layout (issue #135) the SCSS baseline is `aspect-ratio: 4 / 1;` alone — sizing is fully driven by `aspect-ratio` plus the typographic padding values emitted by `Render_Elevation::render()`, so the Elevation block carries no `min-height` baseline. The `Rendering\Dimensions_Defaults` filter still recognises the Elevation block to strip the blank-equivalent `aspectRatio: 'auto'` keyword (which would otherwise cause core to emit `min-height: unset` and override the SCSS aspect-ratio fallback) but it does not inject a `min-height` default for this block. The Map block keeps its `15vh`-era counterpart `30vh`; see the GPX Map *Sizing* paragraph above for that mechanism.

### Block supports

`block.json` declares `supports.align: [ "wide", "full" ]` (toolbar offers None / Wide / Full — `left`/`right`/`center` are intentionally excluded) and `supports.anchor: true` (Advanced panel exposes the HTML anchor field). `customClassName` stays at its core default (`true`). Both the normal-data path and the empty-data fallback emit their wrapper through `get_block_wrapper_attributes()`, so alignment, anchor, and additional className all propagate even when the track has no elevation samples.

The full border (color, radius, style, width) and `shadow` block supports are also enabled, so the editor's standard Border and Shadow panels are available on the block. The block root sets `overflow: hidden` in its stylesheet so border-radius cleanly clips the inline SVG to the rounded edge; `box-shadow` is unaffected by `overflow` on the same element, so shadows still render outside the wrapper. The same two-gate model described in the Map block's *Block supports* section above applies: the block declares `supports.__experimentalBorder = { color, radius, style, width: true }` (the experimental key is the one Gutenberg actually reads — issue #107), and `Bootstrap\Theme_Json_Border_Optin` injects the matching theme.json opt-in via `wp_theme_json_data_theme` so the Border panel surfaces on themes that haven't enabled appearance tools or per-feature border settings in their own `theme.json` (issue #87).

`supports.dimensions` enables `aspectRatio` and `minHeight`, with the same plumbing as GPX Map (see the Map's *Block supports* section above). Under the wrapper-as-image layout (issue #135) empty fields fall back to the SCSS baseline `aspect-ratio: 4 / 1;` alone — the block carries no `min-height` baseline, because sizing is fully driven by `aspect-ratio` plus the typographic padding values emitted by `Render_Elevation::render()`. `Rendering\Dimensions_Defaults` recognises the block for the `aspectRatio: 'auto'` strip but does not inject a `min-height` default for it.

`supports.spacing` enables only `margin` (not `padding`, and not `blockGap` — the latter being meaningless without inner blocks). Padding was previously offered because the inline SVG paints into the wrapper's content box and would respect padding like ordinary block content; with the dedicated background-colour control removed, however, there is no surface to inset the SVG against, so the padding control changed layout without producing a visible inset and was dropped. Site builders who want a padded frame around the chart compose it with `core/group`, exactly as for GPX Map.

`supports.typography` enables every aspect — `fontFamily`, `fontSize`, `fontWeight`, `fontStyle`, `lineHeight`, `letterSpacing`, `textTransform`, `textDecoration` — with the standard `defaultControls` trio (Font, Size, Appearance) visible at the top of the panel and the rest reachable from the per-aspect dropdown menu. Typography reaches the chart through ordinary CSS inheritance: `get_block_wrapper_attributes()` emits the chosen values as inline declarations on the wrapper, the SCSS rules for `.kntnt-gpx-blocks-elevation-axis-label` and `.kntnt-gpx-blocks-elevation-cursor-tooltip` set every font-* property to `inherit`, and the wrapper-as-image padding model (issue #135) uses `ch`-based and `em`/`lh`-based units throughout so the reserved axis-label space grows automatically with the wrapper's chosen `font-size` and `line-height`. The cursor dot's diameter is set in `em` (`--kntnt-gpx-blocks-elevation-cursor-dot-size`, default `0.6em`) for the same reason. One typography setting applies to the whole block — editors who want differentiated axis vs tooltip styling wrap the block in a `core/group` and use that block's own typography controls on the inner content.

### Render output

```html
<div
    class="wp-block-kntnt-gpx-blocks-elevation kntnt-gpx-blocks-elevation"
    data-wp-interactive='{"namespace":"kntnt-gpx-blocks"}'
    data-wp-context='{"mapId":"map-abc123"}'
    data-wp-init="callbacks.initElevation"
    data-wp-watch="callbacks.onElevationCursorChange"
    style="--kntnt-gpx-blocks-elev-pad-x: calc(5ch + 0.5em); --kntnt-gpx-blocks-elev-pad-top: 0.5lh; --kntnt-gpx-blocks-elev-pad-bottom: calc(0.5em + 0.2em); --kntnt-gpx-blocks-line-color: #06c;"
>
    <svg class="kntnt-gpx-blocks-elevation-svg" viewBox="0 0 1200 300" role="img" aria-labelledby="kntnt-gpx-blocks-elevation-desc-map-abc123" preserveAspectRatio="none">
        <desc id="kntnt-gpx-blocks-elevation-desc-map-abc123"><!-- screen-reader summary --></desc>
        <!-- frame lines (vector-effect="non-scaling-stroke"), polyline (vector-effect="non-scaling-stroke"), vertical cursor line (vector-effect="non-scaling-stroke") — all SVG elements -->
    </svg>
    <div class="kntnt-gpx-blocks-elevation-cursor" aria-hidden="true" style="display:none">
        <div class="kntnt-gpx-blocks-elevation-cursor-dot"></div>
        <div class="kntnt-gpx-blocks-elevation-cursor-tooltip">
            <div class="kntnt-gpx-blocks-elevation-cursor-tooltip-distance"></div>
            <div class="kntnt-gpx-blocks-elevation-cursor-tooltip-elevation"></div>
        </div>
    </div>
    <div class="kntnt-gpx-blocks-elevation-y-labels" aria-hidden="true">
        <span class="kntnt-gpx-blocks-elevation-axis-label" style="top:0.00%">205 m</span>
        <!-- five y-tick labels, each positioned by inline top: <pct>% -->
    </div>
    <div class="kntnt-gpx-blocks-elevation-x-labels" aria-hidden="true">
        <span class="kntnt-gpx-blocks-elevation-axis-label" style="left:0.00%">0.0 km</span>
        <!-- five x-tick labels, each positioned by inline left: <pct>% -->
    </div>
    <noscript><!-- summary text --></noscript>
</div>
```

The screen-reader summary in `<desc>` reads (translated): `"Elevation profile from {min} m at the start to {max} m after {distance}, with total ascent {ascent} m and descent {descent} m."`

**Layout model (wrapper-as-image, issue #135).** The wrapper *is* the image: it carries the editor-set `aspect-ratio` (default `4 / 1` from the SCSS baseline) and three data-driven typographic padding values emitted as CSS custom properties on the wrapper's inline style. `--kntnt-gpx-blocks-elev-pad-x = calc(<widest-y-label>ch + 0.5em)` — the widest of the five formatted y-tick label strings measured in `ch` plus the 0.5 em gap to the y-axis line; `--kntnt-gpx-blocks-elev-pad-top = 0.5lh` — half the y-label's resolved line-height so the topmost y-label tangents the wrapper's top edge; `--kntnt-gpx-blocks-elev-pad-bottom = calc(0.5em + 0.2em)` — 0.5 em gap from the x-axis line to the x-label baseline plus a 0.2 em descender approximation so the lowest descender tangents the wrapper's bottom edge. The SCSS applies these three variables to the wrapper's `padding-left` (= `padding-right`), `padding-top`, and `padding-bottom`. The SVG fills the wrapper's content box exactly: pinned with `position: absolute` and `inset` matching the same three variables, with `preserveAspectRatio="none"` and `PLOT_INSET = 0` so the polyline spans `0..viewBox_w` × `0..viewBox_h` and stretches non-uniformly to fill the plot rectangle. `vector-effect="non-scaling-stroke"` (set as an SVG attribute on the polyline, the two axis frame lines, and the cursor line) keeps stroke widths visually consistent under that stretch. Axis tick labels live in two sibling `<div>` overlays whose containers span the plot rectangle's vertical / horizontal extent exactly, so the tick fractions (0/25/50/75/100 %) within each overlay match the polyline's tick positions byte-for-byte. The model needs no JS measurement loop — every value is plain CSS that resolves against the wrapper's chosen `font-size` and `line-height`.

### Interactivity behaviour

`callbacks.initElevation`:

1. Locates the server-rendered cursor LINE (`<line class="kntnt-gpx-blocks-elevation-cursor-line">`) inside the inline SVG and the HTML cursor overlays alongside the SVG — the wrapping `<div class="kntnt-gpx-blocks-elevation-cursor">`, the dot `<div>`, the tooltip `<div>`, and its two row children. Issue #136 moved the dot and tooltip out of the SVG into HTML overlays so they are immune to the wrapper-as-image layout's non-uniform stretch; the cursor LINE stays inside the SVG because `vector-effect="non-scaling-stroke"` already keeps its stroke width visually consistent under that stretch.
2. Derives the SVG-space chart bounds from the SVG's own viewBox attribute. Under the wrapper-as-image layout (issue #135) `PLOT_INSET = 0`, so the bounds are `{ left: 0, right: viewBox.width, top: 0, bottom: viewBox.height }`. No `data-plot-*` attributes are needed — the cursor LINE updates run in viewBox units; the HTML overlay positions are expressed as percentages of the plot rectangle and resolved by CSS against the overlay container, which spans the same rectangle via the wrapper's padding variables.
3. Snapshots the LTTB-downsampled `(distance, elevation)` pairs from `state[mapId].elevation` at mount time.
4. Defers `pointermove` / `pointerleave` binding on the SVG until the block enters the viewport via `IntersectionObserver`. Pointer events compute `fraction` from the pointer's x-position relative to the SVG's bounding rect and write it to `state[mapId].fraction`.
5. The `callbacks.onElevationCursorChange` watch updates the cursor LINE's `x1`/`x2` (in viewBox units), the HTML dot's `style.left` / `style.top` (as percentages of the plot rectangle), and the HTML tooltip's `style.left` (centred on the dot and clamped inside the overlay container) plus the tooltip's text content whenever `state[mapId].fraction` changes (from either Elevation's own pointer events or from GPX Map). Does not write back to fraction (no feedback loop). Named per block so the Map module's watch callback (`onMapCursorChange`) survives the merge into the shared `kntnt-gpx-blocks` store. The cursor-update primitives — fraction-of-plot-rect math and the DOM writes — live in `src/blocks/elevation/cursor.ts` so they are unit-testable independently of the Interactivity API store; `view.ts` imports them.

The tooltip is rendered as a `<div>` with two row `<div>` children — distance on the first row, elevation on the second — both block-level so they stack vertically. The wrapping `<div class="kntnt-gpx-blocks-elevation-cursor">` toggles overall visibility via `style.display` and also flips the SVG-side cursor line's `display` in lock-step so the two surfaces never disagree. Typography (`font-family`, `font-size`, etc.) is inherited from the block wrapper via block-level `supports.typography` — the tooltip text and the HTML axis labels share the same typography choice. Distance switches from metres to kilometres at 1000 m to match the x-axis; elevation is always whole metres. The cursor dot is sized in `em` via `--kntnt-gpx-blocks-elevation-cursor-dot-size` (default `0.6em`) so it stays perfectly circular at every aspect ratio and grows with the block's chosen font-size.

**Editor-only cursor preview.** When the block is rendered through the REST `block-renderer` endpoint (the editor's `<ServerSideRender>` preview) and the requesting user has `edit_posts`, `Render_Elevation::build_chart()` server-renders the cursor visible at `fraction = 0.5` with the corresponding LTTB-interpolated distance and elevation values pre-filled into the tooltip. The wrapping cursor `<div>` carries a `data-preview="1"` attribute so `view.ts`'s `onElevationCursorChange` watch knows to skip the hide-on-undefined-fraction branch during the initial mount-time fire; the dot and tooltip carry inline `style.left` / `style.top` percentages computed from the midpoint sample so the cursor renders at the right spot before any JS has run; the SVG cursor line carries the same midpoint `x1`/`x2` so the line and the HTML overlays agree. The first real `fraction` update — from the user scrubbing the chart, or from a sibling Map block — clears the `data-preview` attribute, overwrites the inline styles, and the cursor follows live state from that point on. The frontend (non-editor) render path is unchanged in shape: the wrapping cursor `<div>` carries `style="display:none"` and the cursor line carries `style="display:none"`, and `view.ts` reveals both on the first `pointermove`. This mirrors the Map block's floating waypoint-info preview pattern: editors get live feedback for the Cursor / Tooltip background / Tooltip text colour controls and the block-level Typography panel without having to interact with the chart first.

### Errors

| Code | Trigger |
|---|---|
| `'no-map'` | No GPX Map block on page. |
| `'multiple-maps'` | More than one map and `mapId === "auto"`. |
| `'map-not-found'` | Explicit `mapId` doesn't match any Map. |
| `'no-elevation'` | The track has no `<ele>` data. Renders an empty-state message instead of the chart. |

The Map's own errors propagate up if the underlying attachment is broken — Elevation shows the same error.

## GPX Statistics variation

The GPX Statistics summary is **not** a block. It is a *`core/group` block-variation* + a `[kntnt-gpx <key>]` *shortcode*. The variation provides the layout (a two-column grid of paragraph rows, first row spanning both columns) and is registered with `scope: ['inserter']` so it appears as a standalone item in the block inserter alongside GPX Map and GPX Elevation. The shortcode provides the data (one formatted statistic per invocation). All theming is whatever the user's theme + the standard core paragraph controls give — there is no plugin-specific theming surface.

### Block variation

- **Variation name:** `kntnt-gpx-blocks-statistics` (variation of `core/group`).
- **Title / description:** translated via JS-side `__()`.
- **Category:** `kntnt` (the existing block category registered by `Bootstrap\Block_Registrar`).
- **Scope:** `['inserter']` only — keeps the variation out of the Group placeholder picker so unrelated `core/group` insertions are unaffected.
- **Icon:** an inline SVG (three vertical bars over a short winding track segment) authored in `js/statistics-variation.js` via `window.wp.element.createElement` and passed as the `icon` field on the `registerBlockVariation` call. Drawn with `currentColor` strokes so it adapts to editor light/dark chrome, matching the GPX Map and GPX Elevation block icons in stroke weight, viewBox, and optical density.
- **Source file:** `js/statistics-variation.js`. Plain ES2022 that calls `window.wp.blocks.registerBlockVariation('core/group', { ... })`, uses `window.wp.i18n.__()` for label translation, and uses `window.wp.element.createElement` to build the inline SVG icon. Enqueued by `Bootstrap\Variation_Registrar` on `enqueue_block_editor_assets` with the script handle `kntnt-gpx-blocks-statistics-variation` and dependencies on `wp-blocks`, `wp-element`, and `wp-i18n`. `wp_set_script_translations()` wires the `__()` calls into the plugin's text domain.
- **Inserted markup shape:**

```html
<!-- wp:group {"metadata":{"name":"GPX Statistics"},"style":{"spacing":{"blockGap":"var:preset|spacing|small"}},"layout":{"type":"grid","columnCount":2,"minimumColumnWidth":null}} -->
<div class="wp-block-group">
  <!-- wp:paragraph {"metadata":{"name":"Total length"},"style":{"layout":{"columnSpan":2}}} -->
  <p><strong>Total length:</strong> [kntnt-gpx distance]</p>
  <!-- /wp:paragraph -->

  <!-- wp:paragraph {"metadata":{"name":"Lowest elevation"}} -->
  <p><strong>Lowest elevation:</strong> [kntnt-gpx min-elevation]</p>
  <!-- /wp:paragraph -->

  <!-- wp:paragraph {"metadata":{"name":"Highest elevation"}} -->
  <p><strong>Highest elevation:</strong> [kntnt-gpx max-elevation]</p>
  <!-- /wp:paragraph -->

  <!-- wp:paragraph {"metadata":{"name":"Total ascent"}} -->
  <p><strong>Total ascent:</strong> [kntnt-gpx ascent]</p>
  <!-- /wp:paragraph -->

  <!-- wp:paragraph {"metadata":{"name":"Total descent"}} -->
  <p><strong>Total descent:</strong> [kntnt-gpx descent]</p>
  <!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
```

The `metadata.name` strings on the inner blocks (`Total length`, `Lowest elevation`, etc.) are deliberately fixed English — Gutenberg's `metadata.name` is editor-side metadata, and Core's own templates leave it as a fixed English string. The visitor-facing label content (`Total length:`) stays translated through the plugin's text domain.

Once the user inserts the variation, the markup is standard `core/group` and `core/paragraph` content with inline `[kntnt-gpx <key>]` shortcodes — the variation's role ends at insertion time; the post_content carries no reference back to the variation name.

### Shortcode

- **Tag:** `kntnt-gpx`.
- **Class:** `Bindings\Statistics_Shortcode` (held as a private property on `Plugin`, registered on `init` via `add_shortcode( 'kntnt-gpx', [ $this, 'render' ] )`).
- **Syntax:**
  - `[kntnt-gpx <key>]` — auto-resolves to the single GPX Map on the page.
  - `[kntnt-gpx <key> map="<map_id>"]` — resolves to the named map; `map=""` coerces to `"auto"`.
- **Key allow-list:** the first positional attribute is one of `'distance'`, `'min-elevation'`, `'max-elevation'`, `'ascent'`, `'descent'`. The hyphenated form is the only public vocabulary — the cache shape uses underscores internally and `Statistics_Shortcode` maps between the two at the boundary. Other values resolve to an empty string + `Plugin::warning()`.
- **Return value:** a string, locale-formatted via `Format\Value_Formatter` (the same formatter the plugin uses elsewhere). Distance gets auto-metric units (m below 1000, km above); elevations are always whole metres. Both go through the existing `kntnt_gpx_blocks_format_distance` and `kntnt_gpx_blocks_format_elevation` filters.
- **Host post resolution:** the shortcode reads the host post via `get_the_ID()` — the same anchor every other template-tag-flavoured WordPress function uses. Outside the loop (`get_the_ID()` returns `false`), the shortcode renders empty.
- **Error contract:** every error path (no map, multiple maps with `'auto'`, mapId not found, cache parse error, missing file, unknown key, missing post context) renders empty. The misconfiguration is logged once per render via `Plugin::error()` (resolve/cache errors) or `Plugin::warning()` (unknown key) — the shortcode contract is plain text, so the editor's only signal is the visible empty value once the page is previewed.
- **Per-request memoization:** an instance-level array keyed by `"$post_id|$map_id"` collapses the five inline shortcodes per inserted variation into one map resolve + one cache fetch + one log line. The memo lives for the request only; cleared by PHP shutdown.

### Render output

The inserted markup is plain `core/group` + `core/paragraph` with `[kntnt-gpx <key>]` tokens inline. The post_content persists as standard core blocks. There is no plugin-specific HTML wrapper, no plugin-specific CSS class, and no plugin-specific JS at render time — `do_shortcode()` runs against `the_content`, the shortcode handler returns the formatted string, and the paragraph renders normally.

When the track has no elevation data, the four elevation rows render with empty values (the shortcode returns `''` for null statistics). The static label still renders — the user can delete unwanted rows from the inserted variation if they want to hide them entirely.

### Errors (visitor side)

Inline `[kntnt-gpx]` tokens render as empty strings on every error path. Visitors see the static label "Lowest elevation:" with a blank value beside it. There is no editor-only `.kntnt-gpx-blocks-error` notice — a shortcode handler cannot inject HTML notices into the surrounding paragraph content without breaking the inline reading order. The error is logged once per render via the plugin's logging API for editors who check `error_log`.

### Editor experience

The editor shows the literal shortcode token (`[kntnt-gpx distance]`) inside each paragraph — the same way it shows `[gallery]`, `[caption]`, or any other shortcode token inside paragraph content. No editor preview HOC, no editor-only REST endpoint, and no shadow rendering chain are involved. To verify the resolved values, the editor previews the post via the standard Preview button — `do_shortcode()` runs in that path and the values appear in place.

Editors who want to retarget a single row to a different Map block edit the inline `map="…"` attribute directly in the paragraph content (e.g. change `[kntnt-gpx distance]` to `[kntnt-gpx distance map="map-xyz"]`); the shortcode is a plain inline token, so there is no separate "bindings args" surface to dive into. The shortcode is equally usable outside the variation — drop `[kntnt-gpx ascent]` into any paragraph, heading, list item, or widget on the same page and it resolves to the corresponding statistic.
