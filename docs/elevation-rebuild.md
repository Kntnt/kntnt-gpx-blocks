# GPX Elevation: Rebuild Plan

The GPX Elevation block has unresolved rendering issues in the current implementation (tag `v0.12.0`). The other two surfaces — GPX Map and the GPX Statistics variation — work as intended and **must not be touched** while this rebuild is in progress.

This document drives a clean rebuild of GPX Elevation in eight steps, after a Step 0 that resets the block to a blank slate while preserving the block's identity (name and icon). Steps 0–7 rebuild the Elevation block itself; Step 8 is a follow-up that migrates the Map block to share infrastructure introduced during the Elevation rebuild.

## How to use this document

The rebuild proceeds **one step per Claude Code session**. Each session opens with a focused prompt along the lines of:

> Read `docs/elevation-rebuild.md` and execute Step N. Do nothing else.

Every step lists a **load list**: the additional `docs/*.md` files Claude should read for that step. Anything not on the load list should not be read. The default load (`CLAUDE.md` → `AGENTS.md` → `docs/coding-standards.md`) plus the step's load list is enough.

Each step uses **test-driven development**. Start by writing the tests that capture the step's deliverables and confirm they fail (the "red" phase). Implement against those tests until they pass (the "green" phase). Refactor as needed while keeping the tests green. Only when the full test suite is green does the step proceed to the release procedure below.

Block-side JS/TS tests run via `@wordpress/scripts test-unit-js` (Jest), co-located with the source as `<feature>.test.ts` / `<feature>.test.tsx`. Do not introduce Vitest or any other runner for block code — `docs/coding-standards.md` is explicit that block code stays on the `@wordpress/scripts` happy path. Any PHP helpers added during the rebuild are tested with Pest under `tests/Unit/`. For interactive verification of editor or frontend behaviour, use WordPress Playground via `@wp-playground/cli` (see `docs/testing-strategy.md`).

Each step ends with a **tagged GitHub release**, not just a commit. Versions follow the pattern `0.13.N` where `N` is the step number — Step 0 → `v0.13.0`, Step 1 → `v0.13.1`, …, Step 7 → `v0.13.7`, Step 8 → `v0.13.8`.

Follow the full six-step release procedure documented in `AGENTS.md` (section *Cutting a release*) for every step: bump the two version files (`kntnt-gpx-blocks.php` and `package.json`), run all gates, commit and tag, build the ZIP via `./build-release-zip.sh`, push commit and tag, create the GitHub release with the ZIP attached, and verify the asset's content type. The procedure is authoritative — do not skip building the ZIP or attaching it, regardless of how small the step's diff looks. See `docs/updater.md` for the underlying reason.

Commit message convention for these releases: `Release v0.13.N — Step N: <short description>`. This matches the existing `Release vX.Y.Z` pattern while keeping `git log` skim-friendly.

No feature branch, no worktree. Work happens directly on `main`. If a step misfires after its release is published, roll the fix into the next step's release (or cut an interim release if it can't wait) — do not retroactively edit a published release.

When a step says "study how v0.12.0 solved this", consult the tagged code via `git show v0.12.0:src/blocks/elevation/<file>` rather than the working-tree files (which will be partially rebuilt or absent during the rebuild).

## Block architecture

The block has three responsibilities, and the implementation should reflect this so each can be developed in isolation:

1. **The elevation chart.** Render the elevation profile of the GPX track from the bound GPX Map. *This is the part that has rendering problems in v0.12.0.*
2. **The cursor.** A draggable cursor on the chart, synchronised with the cursor in GPX Map (movement in either updates the other). *Works well in v0.12.0 — reuse the pattern.*
3. **The tooltip.** A small label attached to the cursor showing distance and elevation at the cursor position.

The steps below take these in order: chart first (Steps 3–5), cursor next (Step 6), tooltip last (Step 7).

---

## Step 0: Reset

**Goal.** Empty slate — the elevation block still appears in the inserter and still has its name and icon, but the implementation is gone.

**Load list:** `docs/file-structure.md`, `docs/architecture.md`

**Tasks:**

1. If not already saved, record a memory entry noting that `v0.12.0` is the best-so-far implementation and the reference for this rebuild. (Likely already saved when this document was created.)
2. Delete the contents of `src/blocks/elevation/` except what is needed to keep the block registered with its current name and icon. Concretely: strip `block.json` of attributes, supports, render-script bindings, view scripts, etc., keeping only the metadata needed to register the block (`name`, `title`, `category`, `textdomain`, `apiVersion`, `icon` reference). Retain `icon.tsx`. Reduce `index.tsx` / `edit.tsx` to a minimal edit component that renders nothing meaningful, and `render.php` to a minimal frontend placeholder.
3. Verify the block still appears in the inserter and inserts as an empty `<div>` (a placeholder label like "GPX Elevation" inside the div is acceptable for this step).
4. Release as `v0.13.0` per the per-step release procedure (see "How to use this document"). Commit message: `Release v0.13.0 — Step 0: reset GPX Elevation to empty block`.

---

## Step 1: Outer `<div>` and block-inspector controls

**Goal.** The block renders an outer `<div>` (no chart yet), and all panels and controls described below are present in the block inspector. Only `Color → Background` is wired to actually affect the output in this step; everything else is UI scaffolding.

**Load list:** `docs/blocks.md`, `docs/architecture.md`

In the lists below, `+` means the control is **visible by default**, `-` means it is **hidden by default** and the user must reveal it from the ToolsPanel ellipsis menu. Parentheses are notes to the implementer and are *not* part of the control name.

### Inspector — Settings tab

- **Panel: Data Source**
  - `+` Map — a `SelectControl` rendered **unconditionally with an empty options array** in Step 1. This is honest UI scaffolding; map discovery and the conditional panel-visibility logic (hide when ≤1 map on page) land in Step 2 alongside the v0.12.0 reference hooks listed there. The dropdown will appear empty in the editor under Step 1 — that is intentional, not a bug.
- **Panel: Tooltip info**
  - `+` Show distance (on/off)
  - `+` Show height (on/off)

### Inspector — Styles tab

The label is **Styles** (WordPress's actual tab name), not "Design" as earlier drafts of this document said. The controls are routed there via `<InspectorControls group="styles">`. No `Panel` wrappers — the `ToolsPanel`s sit directly in the block inspector.

- **ToolsPanel: Dimensions**
  - `+` Padding
  - `+` Margin
  - `+` Minimum height
  - `+` Aspect ratio
- **Border** (core panel via `supports.__experimentalBorder`, see *`block.json` — `supports` declaration* below) — `Color`, `Style`, `Width`, `Radius`. Default visibility is controlled by core's own preferences (sticky per-item), not by this spec; no plugin code is required.
- **Box Shadow** (core panel via `supports.shadow`) — `Shadow`. Default visibility is core-controlled.

> *Earlier drafts of this spec described a single "Border & Shadow" ToolsPanel with three items. WordPress does not ship that as a standard panel — `supports.__experimentalBorder` and `supports.shadow` deliver two separate `ToolsPanel` instances in the Styles tab. Per Rule 1 (use standard WordPress panels and components wherever possible), we accept the two-panel layout and do not build a custom merged panel. This matches the Map block's existing surface.*
- **ToolsPanel: Color**
  - `+` Background
  - `+` Plot line
  - `+` Cursor
  - `+` Axis
  - `+` Axis labels
  - `+` Tooltip background
  - `+` Tooltip distance
  - `+` Tooltip height
- **ToolsPanel: Tick labels** (Typography) — the seven standard typography aspects WordPress's surface offers, mirroring the Map block's *Waypoint name* / *Waypoint description* panels:
  - `-` Font (`FontFamilyControl`)
  - `+` Font size (`FontSizePicker`)
  - `+` Appearance (`FontAppearanceControl` — combined weight + style)
  - `-` Line height (`LineHeightControl`)
  - `-` Letter spacing (`LetterSpacingControl`)
  - `-` Letter case (`TextTransformControl`)
  - `-` Decoration (`TextDecorationControl`)
- **ToolsPanel: Tooltip distance** (Typography) — same item set as Tick labels (font hidden, font size + appearance visible, the rest hidden by default)
- **ToolsPanel: Tooltip height** (Typography) — same item set as Tick labels (font hidden, font size + appearance visible, the rest hidden by default)

**Shared `TypographyToolsPanel` component.** The three Typography panels are structurally identical — they differ only by attribute prefix (`tickLabel`, `tooltipDistance`, `tooltipHeight`), title, and default-visibility set. The same panel pattern is already used twice in the Map block (`tooltipName`, `tooltipDesc`). Build the panel as a single reusable component in `src/blocks/shared/typography-tools-panel.tsx` from Step 1, parameterised by:

- `title: string` (already translated)
- `prefix: string` (e.g. `'tickLabel'`)
- `attributes`, `setAttributes` (passed through from `edit.tsx`)
- `defaultVisibility: Partial<Record<TypographyAspect, boolean>>`
- `panelId: string` (required for ToolsPanel ResetAll grouping)

The component owns the suffix tuple (`FontFamily`, `FontSize`, `FontWeight`, `FontStyle`, `LineHeight`, `LetterSpacing`, `TextTransform`, `TextDecoration`) internally and builds attribute names as `${prefix}${suffix}`. A co-located unit test asserts that the prefix → attribute-name mapping is correct for all five intended prefixes (Elevation's three plus Map's two), so the eventual Map migration in Step 8 is mechanical. The Map block itself is **not modified** during Steps 1–7 — its existing hardcoded Typography panels stay in place until Step 8.

### Rules

1. **Use standard WordPress panels and components wherever possible.** Do not invent custom controls unless absolutely necessary; even then, build on the WordPress primitives as much as you can. If you write a custom control, justify the decision in a code comment and in the commit message.
2. All colours in the Color panel have **alpha channel enabled**. Therefore the Color panel is **a plugin-owned ToolsPanel rendered inside `<InspectorControls group="styles">`** — `supports.color.background` is *not* declared, because core's Background control does not enable alpha. All eight Color items live in this one custom panel, including Background. The plugin owns the entire colour surface for this block.
3. Only `Color → Background` is wired to the outer `<div>` in this step. The other controls are UI placeholders for now.
4. **WordPress's Dimensions semantics — mutual exclusion.** Minimum height and Aspect ratio are mutually exclusive. Setting Minimum height to a value resets Aspect ratio to "Original". Setting Aspect ratio to anything other than "Original" clears Minimum height. Core's Dimensions panel enforces this automatically when `supports.dimensions.{ minHeight, aspectRatio }` are declared — no plugin code required.
5. **Three-state controls.** Most WordPress inspector controls have no value at all until the user first interacts with them. They then transition to a value (which may equal a placeholder default such as `""` for Minimum height or `default` for Aspect ratio) and from there to a non-default value. The implementation must handle all three states: missing entirely, present-but-default, present-and-non-default — that is what the `usefulValue<T>()` wrapper exists for (see below).

### The "useful-value" wrapper layer

Because of Rule 5 (and its interaction with Rule 4), do not consume control values directly from the component. Introduce a thin wrapper layer whose sole job is to return a usable value when the control has no value at all, or when its value is a placeholder default that would produce invalid CSS (for example, `""` for Minimum height, which can't be passed through to a CSS variable as-is). Build this layer now, applying best-practice design patterns. Decide sensible fallback values as part of the work.

**Shape.** The layer is a single pure function `usefulValue<T>(attributes, setAttributes, key, fallback, isEmpty?)` exported from `src/blocks/elevation/useful-value.ts`. It returns `{ raw, resolved, hasValue, set, reset }` so that inspector controls (`ToolsPanelItem`, the inner `<UnitControl>` / `<ColorPicker>` / etc.) can consume the raw three-state value plus the presence/reset callbacks, while the renderer consumes `resolved` (the raw value with a sensible fallback applied). The default empty-detection is `(v) => v === undefined || v === ''`. Co-located unit tests in `useful-value.test.ts`.

**Defaults for chart-specific dimensions.** A chart has no inherent default for Minimum height or Aspect ratio. Use the same defaults as v0.12.0: **Minimum height = `15vh`**, **Aspect ratio = `default`** (i.e. determined by content).

**Extending the layer in subsequent steps.** Step 1 only wires one attribute (`Color → Background`) through the layer, plus the chart-dimension defaults above. Each subsequent step that wires a new control is responsible for *extending the layer's call-sites* with the fallback value and (where the default empty-detection doesn't fit) a value-specific `isEmpty` predicate. When two or more steps end up with the same predicate, refactor it into a named export from `useful-value.ts` (e.g. `isEmptyHex`, `isEmptyDimension`) at the point of second use — not pre-emptively. The layer's API surface (the `usefulValue` function and the `UsefulValue<T>` return type) stays fixed; only the fallback values and the small library of named predicates grow.

### Application scope

- All controls in **Dimensions**, **Border & Shadow**, and `Color → Background` apply to the outer `<div>` of the block.
- All other controls are UI placeholders only in this step.
- **Padding insets the chart area.** The chart drawn from Step 3 onwards fills the wrapper's **content box** (the CSS rectangle inside any padding the user has applied via the Dimensions panel), *not* the border box. Padding stays as visible whitespace between the wrapper edge and the chart edge. All chart-dimension calculations from Step 3 onward operate on the content box's width and height — see Step 3 step 4.

### `block.json` — `supports` declaration

```json
"supports": {
    "align": [ "wide", "full" ],
    "anchor": true,
    "__experimentalBorder": {
        "color": true,
        "radius": true,
        "style": true,
        "width": true
    },
    "shadow": true,
    "dimensions": {
        "aspectRatio": true,
        "minHeight": true
    },
    "spacing": {
        "padding": true,
        "margin": true
    }
}
```

Note: `spacing` enables both `padding` and `margin` — this is an **intentional divergence from the Map block** (which omits `padding` because Leaflet absolutely-positions its panes against the wrapper's padding box, so the padding control has no visible effect there). Elevation's chart is drawn into the content box (see *Application scope*), so padding does have a visible effect and is included. `supports.color` is intentionally *not* declared — see Rule 2.

### `block.json` — `attributes` declaration

**35 attributes total.** All `string` defaults are `""` unless noted; all `boolean` defaults are explicit per the table.

**Behavioural (3 attributes):**

| Attribute | Type | Default | Source UI |
|---|---|---|---|
| `mapId` | string | `"auto"` | Data Source → Map (empty in Step 1, wired in Step 2) |
| `tooltipShowDistance` | boolean | `true` | Tooltip info → Show distance |
| `tooltipShowHeight` | boolean | `true` | Tooltip info → Show height |

**Colours (8 attributes, all `string` default `""`, alpha-bearing hex `#RGB` / `#RGBA` / `#RRGGBB` / `#RRGGBBAA`):**

| Attribute | Source UI | CSS custom property (wrapper inline style) | Wired in |
|---|---|---|---|
| `backgroundColor` | Color → Background | `--kntnt-gpx-blocks-elevation-background` | Step 1 |
| `plotLineColor` | Color → Plot line | `--kntnt-gpx-blocks-elevation-plot-line-color` | Step 5 |
| `cursorColor` | Color → Cursor | `--kntnt-gpx-blocks-elevation-cursor-color` | Step 6 |
| `axisColor` | Color → Axis | `--kntnt-gpx-blocks-elevation-axis-color` | Step 3 |
| `axisLabelColor` | Color → Axis labels | `--kntnt-gpx-blocks-elevation-axis-label-color` | Step 4 |
| `tooltipBackgroundColor` | Color → Tooltip background | `--kntnt-gpx-blocks-elevation-tooltip-background-color` | Step 7 |
| `tooltipDistanceColor` | Color → Tooltip distance | `--kntnt-gpx-blocks-elevation-tooltip-distance-color` | Step 7 |
| `tooltipHeightColor` | Color → Tooltip height | `--kntnt-gpx-blocks-elevation-tooltip-height-color` | Step 7 |

**Typography (24 attributes, all `string` default `""`):** three identical families with prefixes `tickLabel`, `tooltipDistance`, `tooltipHeight`. Each family has eight attributes — the suffixes are `FontFamily`, `FontSize`, `FontWeight`, `FontStyle`, `LineHeight`, `LetterSpacing`, `TextTransform`, `TextDecoration`. Full attribute names: `tickLabelFontFamily`, `tickLabelFontSize`, … `tooltipHeightTextDecoration`. The shared `TypographyToolsPanel` component (see above) builds these names from `prefix + suffix` internally; the test in `typography-tools-panel.test.tsx` locks the mapping for all five intended prefixes.

### Background wiring — exact contract

This is the **only** rendering wire-up in Step 1. The pattern set here is the template for the seven remaining colours in Steps 3–7.

**Editor (`edit.tsx`):**

```ts
const bg = usefulValue( attributes, setAttributes, 'backgroundColor', '' );
const inlineStyle: Record< string, string > = {};
if ( bg.resolved !== '' ) {
    inlineStyle[ '--kntnt-gpx-blocks-elevation-background' ] = bg.resolved;
}
const blockProps = useBlockProps( {
    className: 'kntnt-gpx-blocks-elevation',
    style: inlineStyle as React.CSSProperties,
} );
```

**Frontend (`render.php`):**

```php
use Kntnt\Gpx_Blocks\Rendering\Color_Sanitizer;

$bg = Color_Sanitizer::sanitize( $attributes['backgroundColor'] ?? '' );
$style = $bg !== '' ? '--kntnt-gpx-blocks-elevation-background: ' . $bg . ';' : '';
$wrapper_attributes = get_block_wrapper_attributes( [
    'class' => 'kntnt-gpx-blocks-elevation',
    'style' => $style,
] );
```

`Color_Sanitizer::sanitize()` lives in `classes/Rendering/Color_Sanitizer.php` and accepts hex 3/4/6/8. Reject paths return `''`, so the conditional `$bg !== ''` is correct for both "user didn't set" and "user-set value rejected as malformed".

**CSS consumption.** Step 1 has no `style.scss` file — there is no SVG yet, and one inline `background-color` consumer would not earn its own stylesheet. The wrapper consumes the variable inline via a tiny rule embedded in `render.php`:

```php
// In render.php, after the wrapper opens, prepend a one-rule inline style block keyed by the wrapper class:
echo '<style>.kntnt-gpx-blocks-elevation { background-color: var( --kntnt-gpx-blocks-elevation-background, transparent ); }</style>';
```

Step 3 introduces `style.scss` when the SVG arrives, at which point this inline `<style>` block is removed and the rule migrates into the stylesheet. The `transparent` fallback ensures that unset Background leaves the wrapper transparent — matching every other unstyled core block.

### File layout for Step 1

```
src/blocks/elevation/
├── block.json                        — 35 attributes + 6 supports, per the tables above
├── icon.tsx                          — unchanged from Step 0
├── index.tsx                         — unchanged from Step 0 (registers the block)
├── edit.tsx                          — orchestrator: useBlockProps, both inspector tabs
├── useful-value.ts                   — pure function per Q3
├── useful-value.test.ts              — co-located unit tests
├── inspector-color.tsx               — the custom Color ToolsPanel (8 items, alpha)
└── render.php                        — Background injection + inline <style> rule

src/blocks/shared/
├── typography-tools-panel.tsx        — the shared TypographyToolsPanel component
└── typography-tools-panel.test.tsx   — co-located tests for all 5 prefixes
```

Files **not created in Step 1**: `view.ts` (no interactivity yet), `style.scss` / `editor.scss` (no SVG, the inline `<style>` block in `render.php` carries the single rule). These arrive in Step 3 when the SVG appears.

### Acceptance criteria

Step 1 is considered done — and `v0.13.1` may be tagged — when **all** of the following hold:

**Behaviour:**

1. The block still appears in the inserter under the "Kntnt" category with the same name and icon as after Step 0 (no regression).
2. The block can be inserted in the editor and renders as a single `<div>`.
3. `block.json` declares exactly the 35 attributes and 6 `supports` blocks fixed in the attribute table for Step 1.
4. The **Settings** tab of the block inspector shows:
   - A `Data Source` panel containing a `SelectControl` with empty options (unconditional in Step 1).
   - A `Tooltip info` panel containing two `ToggleControl`s (`tooltipShowDistance`, `tooltipShowHeight`), both defaulting to `true`.
5. The **Styles** tab of the block inspector shows, in order:
   - Core's standard **Dimensions** panel (`Padding`, `Margin`, `Minimum height`, `Aspect ratio`).
   - Core's standard **Border** panel (`Color`, `Style`, `Width`, `Radius`).
   - Core's standard **Box Shadow** panel.
   - A custom **Color** ToolsPanel with eight items (Background, Plot line, Cursor, Axis, Axis labels, Tooltip background, Tooltip distance, Tooltip height), all `enableAlpha`, all visible by default.
   - Three **Typography** ToolsPanels (Tick labels, Tooltip distance, Tooltip height), each rendered via the shared `TypographyToolsPanel` component with `prefix` set to `tickLabel`, `tooltipDistance`, `tooltipHeight` respectively.
6. Changing `Color → Background` updates the editor preview's wrapper background immediately (no SSR round-trip).
7. Changing any `Dimensions`, `Border`, or `Box Shadow` control updates the editor preview accordingly through core's block-supports machinery (no plugin code involved).
8. On the frontend, `render.php` emits the wrapper through `get_block_wrapper_attributes()` with `Color → Background` injected as the CSS custom property `--kntnt-gpx-blocks-elevation-background`. All core supports values reach the wrapper through the standard pipeline.
9. **No other controls than `Color → Background` produce visible output changes** — the remaining seven Color items, both Tooltip-info toggles, the empty Data Source dropdown, and all three Typography panels persist values but have no rendering effect in Step 1.

**Gates (must all pass at HEAD before tagging):**

10. `npm run build`.
11. `composer test` (Pest).
12. `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M`.
13. `npm run test:js` — including the new `useful-value.test.ts` and `typography-tools-panel.test.tsx`. The latter must assert prefix-to-attribute mapping for all five intended prefixes (`tickLabel`, `tooltipDistance`, `tooltipHeight`, `tooltipName`, `tooltipDesc`) so the Step 8 Map migration is mechanical.
14. `npx wp-scripts lint-js src/blocks/` (because `src/blocks/` is touched).

**Manual verification in WordPress Playground (`@wp-playground/cli`):**

15. Insert the block, change `Color → Background`, change `Padding`, `Min height`, and `Aspect ratio`, then preview the post and confirm both editor and frontend reflect the changes consistently.
16. Click "Reset all" in the Color panel and in one Typography panel — every item in that panel returns to its unset/default state and the ellipsis menu reflects the reset.

### Release

When all acceptance criteria hold, follow the six-step release procedure documented in `AGENTS.md` (section *Cutting a release*). Tag `v0.13.1`. Commit message: `Release v0.13.1 — Step 1: outer div and block-inspector controls`.

---

## Step 2: Bind to a GPX Map and surface the binding state

**Goal.** The block resolves which GPX Map on the page provides its data. The Data Source panel becomes conditional. The block displays a temporary placeholder showing the bound track's min/max elevation, or a warning if no usable map is on the page.

**Load list:** `docs/blocks.md`, `docs/architecture.md`

**v0.12.0 reference:** `use-map-blocks.ts`, `use-bind-single-map.ts`, `use-auto-pick-map-id.ts`, `use-ssr-error-message.ts` — study how v0.12.0 fetched track data from the chosen map.

### Behaviour

- The **Data Source** panel is shown only when there is **more than one** GPX Map on the page.
- The **Map** dropdown lists the maps that the GPX Map blocks on the page have loaded.
- The default map (when `mapId === "auto"`) is the **topmost map in the dropdown**. *This is an intentional divergence from v0.12.0, which picked differently.*

### Placeholder boxes (temporary — removed in Step 3)

- **No GPX Map with a usable track on the page:** the block's `<div>` renders as a **warning box** (e.g. light red background with a red left border) carrying a message stating that no GPX Map with a GPX track is present on the page.
- **A GPX Map with a track is present:** the block's `<div>` renders as an **info box** (e.g. light blue background with a blue left border) carrying a message stating the track's **min and max elevation rounded to integers**.

Both boxes are removed in Step 3.

### Implementation notes

Study v0.12.0's solution before designing your own. v0.12.0's pattern is sound; improve on it where appropriate, but keep the cross-block resolve via the Interactivity store keyed by `mapId` — that is the established mechanism (see `docs/architecture.md`).

---

## Step 3: Responsive axes

**Goal.** The block draws the X and Y axes inside a responsive SVG. No tick marks, no labels, no curve yet.

**Load list:** `docs/blocks.md`

The chart needs margins around the plotting area to leave room for tick labels. The margins are computed from the actual rendered text dimensions using the typography settings from the **Tick labels** panel.

1. **Left margin `w_left`:**
   - Identify min and max elevation values for the Y axis.
   - Build their label strings (e.g. `"-10 m"`, `"1050 m"`).
   - Measure the rendered width of the wider of the two strings with the Tick labels typography settings.
   - `w_left = measured_width + 0.5em`.
2. **Bottom margin `h`:**
   - Measure the rendered height of a reference string that contains extreme glyphs (e.g. `"-0,123456789"`).
   - `h = measured_height + 0.5em`.
3. **Right margin `w_right`:**
   - Predict the label for the largest value on the X axis (e.g. `"12,5 km"`).
   - Measure its width.
   - `w_right = measured_width / 2 + 0.5em`. The half-width comes from the fact that the last X label is centred under its tick, so only half of it can overflow the plotting area; `w_right` keeps that overflow clear of the container's right edge.
4. **Container dimensions:** read the **content box's** pixel `W` (width) and `H` (height) — i.e. the rectangle inside any padding the user has set in the Dimensions panel, *not* the border box. The chart is drawn into the content box; padding remains as visible whitespace between the wrapper edge and the SVG edge. With CSS `box-sizing: content-box` (the WordPress default for block wrappers), `element.clientWidth - paddingLeft - paddingRight` and `element.clientHeight - paddingTop - paddingBottom` give the content box, but the more robust approach is to read the SVG's own `getBoundingClientRect()` once the SVG is mounted as a normal-flow child of the wrapper — the SVG sits inside the content box by virtue of CSS layout, so its bounding rect *is* the content box.
5. **SVG setup:** create an SVG with `width="100%"`, `height="100%"`, `viewBox="0 0 W H"`. The SVG is a normal-flow child of the wrapper `<div>`, so `100%` resolves against the content box and the SVG fills exactly that rectangle. This gives a 1:1 mapping between pixels and SVG units inside the content box.
6. **Draw the axes:**
   - X axis: line from `(w_left, H − h)` to `(W − w_right, H − h)`.
   - Y axis: line from `(w_left, H − h)` to `(w_left, 0)`.

### Notes

1. Steps 4–6 of the algorithm above re-run on every container resize.
2. Axis lines use the colour from `Color → Axis`.

---

## Step 4: Tick marks and tick labels

**Goal.** The axes carry tick marks and numeric labels driven by the GPX data range.

**Load list:** `docs/blocks.md`

1. **Decide tick count `N`:**
   - Available plotting width: `W_avail = W − w_left − w_right`.
   - Measure the width of an average label.
   - `N = floor(W_avail / (label_width × 1.5))`. The factor 1.5 prevents labels from crowding.
2. **Generate nice tick values (step size `S`):**
   - Divide the data range by `N` to get a candidate step.
   - Round the candidate to the nearest "nice" value from the series `[1, 2, 5] × 10^n` (i.e. 0.2, 0.5, 1, 2, 5, 10, 20, 50, …). This gives a linear, easy-to-read scale.
3. **X axis unit logic (m / km):**
   - Generate all X tick values (e.g. 0, 500, 1000, 1500, 2000).
   - **Rule:** if more than half of the non-zero tick values are ≥ 1000, convert the whole X axis to kilometres.
   - When in kilometres: divide values by 1000, allow one decimal place where needed (e.g. `"1,5"`), and suffix only the **last** label with `" km"`.
4. **Tick marker geometry:**
   - Length **`0.2em`**.
   - X tick markers start at the axis line and extend **downward**.
   - Y tick markers start at the axis line and extend **leftward**.
5. **Tick label placement:**
   - **X labels:** centred horizontally under their marker; top of text `0.5em` below the axis line; include the unit ` m` (or only `" km"` on the last label per Rule 3).
   - **Y labels:** right-aligned so the text ends `0.5em` left of the Y axis; vertically centred on the marker; include the unit ` m` or ` km`.
6. **Safety margin:** the `w_right` margin from Step 3 guarantees the last X label can be drawn centred without being clipped by the container's right edge.

### Notes

1. All tick label text is rendered with the typography settings from the **Tick labels** panel.
2. Tick markers use the colour from `Color → Axis`.
3. Tick labels use the colour from `Color → Axis labels`.

---

## Step 5: Elevation curve

**Goal.** The elevation profile is drawn inside the plotting area.

**Load list:** `docs/blocks.md`

- Map GPX data (distance, elevation) to SVG coordinates using the dynamic margins and current container dimensions from Steps 3–4.
- Re-render on every container resize.
- Render the entire curve as a **single `<path>` element** for rendering performance.
- Stroke colour comes from `Color → Plot line`.

---

## Step 6: Cursor with cross-block sync

**Goal.** A cursor sits on the curve. The user can move it by hovering and dragging on the curve (desktop) or by touching and dragging it (touch devices). The cursor in GPX Map and the cursor in GPX Elevation stay synchronised — moving either updates the other. **No tooltip yet.**

**Load list:** `docs/blocks.md`, `docs/architecture.md` (Interactivity store, cross-block sync)

**v0.12.0 reference:** `cursor.ts`, `mount.ts`, `view.ts` — both the local cursor handling and the namespaced Interactivity store keyed by `mapId` work well in v0.12.0. Study and reuse the pattern.

Cursor colour comes from `Color → Cursor`.

---

## Step 7: Tooltip

**Goal.** A tooltip is attached to the cursor showing distance and elevation. It flips to the other side of the cursor when it would otherwise overflow the container's right edge.

**Load list:** `docs/blocks.md`

- The tooltip sits **to the right of the cursor** by default. When it approaches the `<div>`'s right edge and would otherwise be clipped, it flips to the **left** of the cursor.
- Two lines:
  - **Line 1:** the X-axis value at the cursor position (distance, with unit ` m` or ` km`).
  - **Line 2:** the Y-axis value at the cursor position (elevation, with unit ` m` or ` km`).
- Typography:
  - Line 1 from the **Tooltip distance** panel.
  - Line 2 from the **Tooltip height** panel.
- Colours:
  - Background from `Color → Tooltip background`.
  - Line 1 from `Color → Tooltip distance`.
  - Line 2 from `Color → Tooltip height`.
- **Visibility gating from Step 1's Tooltip info panel:**
  - `Tooltip info → Show distance` toggles Line 1 on/off independently.
  - `Tooltip info → Show height` toggles Line 2 on/off independently.
  - When both are off, the tooltip is not rendered at all.

---

## Step 8: Migrate GPX Map to the shared `TypographyToolsPanel`

**Goal.** Map's two hardcoded Typography panels (*Waypoint name*, *Waypoint description*) are replaced with the shared `TypographyToolsPanel` component introduced in Step 1. No user-visible behaviour changes; this is a pure deduplication.

**Load list:** `docs/blocks.md`

This is the **first step where Map's source files are modified** — the no-touch rule that applied to Steps 0–7 lifts here. The migration is mechanical because the shared component was designed to handle Map's two prefixes (`tooltipName`, `tooltipDesc`) from Step 1 and its prefix-mapping unit test already covers them.

**Tasks:**

1. In `src/blocks/map/edit.tsx`, replace the inline JSX for the *Waypoint name* and *Waypoint description* Typography ToolsPanels with two `<TypographyToolsPanel>` invocations:
   - One with `prefix="tooltipName"`, title "Waypoint name", default-visibility matching Map's current panel.
   - One with `prefix="tooltipDesc"`, title "Waypoint description", default-visibility matching Map's current panel.
2. Verify in the editor that both panels render identically to before, that ToolsPanel ellipsis / ResetAll behave correctly, and that attribute reads/writes still hit the same `tooltipName*` / `tooltipDesc*` keys.
3. Remove any helper code in `src/blocks/map/` that the migration leaves dead.
4. Run all gates (build, PHPStan, JS lint, JS tests, PHP tests).
5. Release as `v0.13.8` per the per-step release procedure. Commit message: `Release v0.13.8 — Step 8: migrate GPX Map to shared TypographyToolsPanel`.

**Note on Border & Shadow.** The Map block already uses core's standard two-panel layout (one `ToolsPanel` for Border via `supports.__experimentalBorder`, one for Box Shadow via `supports.shadow`) — there is no custom Border-and-Shadow component to refactor. The Border/Shadow surface is left untouched.

---

## When the rebuild is complete

When Step 8 lands and the migration ships, this document is no longer authoritative — `docs/blocks.md` is. Either delete `docs/elevation-rebuild.md`, or move it to `docs/archive/` if the narrative is worth keeping.
