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

**Released-step recaps.** Once a step's release is tagged, its spec section in this document is collapsed to a short "Recap" naming what's in the codebase, with pointers to the relevant source files for orientation and to the corresponding `Lock Step N specification` commit (or the release tag) for the full original spec. This keeps the document focused on the *next* step at any moment. Retrieve a collapsed spec in full via `git show <commit-or-tag>:docs/elevation-rebuild.md`.

## Block architecture

The block has three responsibilities, and the implementation should reflect this so each can be developed in isolation:

1. **The elevation chart.** Render the elevation profile of the GPX track from the bound GPX Map. *This is the part that has rendering problems in v0.12.0.*
2. **The cursor.** A draggable cursor on the chart, synchronised with the cursor in GPX Map (movement in either updates the other). *Works well in v0.12.0 — reuse the pattern.*
3. **The tooltip.** A small label attached to the cursor showing distance and elevation at the cursor position.

The steps below take these in order: chart first (Steps 3–5), cursor next (Step 6), tooltip last (Step 7).

## Rendering architecture

The chart — axes, ticks, curve, cursor, tooltip — is rendered **client-side**, not server-side. Frontend `render.php` is small: it emits the wrapper `<div>`, whatever Interactivity directives and `wp_interactivity_state()` payload the client needs (the state payload arrives in Step 5 with the track samples; the directives arrive in Step 6 with the cursor), and a server-resolvable warning when no GPX Map is bound on the page. PHP never emits `<svg>` markup. The orphaned `Render_Elevation` removed in commit `aeb367f` is not reborn under a different name during this rebuild.

The editor preview is a **React component tree**, not `<ServerSideRender>` — the same architecture the Map block already uses. The Interactivity runtime does not bootstrap inside the editor canvas, so the frontend mount path cannot also run there (see `docs/architecture.md` § *ServerSideRender is not used for the Map block's editor preview*). The React preview reads track data via the editor-only REST endpoint `Rest\Preview_Controller` already exposes for Map's preview (extending its response shape if Step 5 needs additional fields). The `__editorBlockSnapshot` attribute and the `useSsrErrorMessage`-style DOM polling from v0.12.0 are not reintroduced.

The rationale is rooted in Steps 3 onward. The dynamic-margin algorithm (Step 3) requires measuring the rendered width of tick labels in the user's chosen typography — fundamentally a DOM-measurement task PHP cannot perform reliably without duplicating font metrics. Resize handling (Steps 3 note 1 and Step 5), cursor sync (Step 6), and tooltip flip-on-overflow (Step 7) are all fundamentally JS. With JS already owning the moving parts, also owning the static parts collapses two parallel renderers into one and eliminates the editor-vs-frontend / SSR-vs-React-state divergence that drove v0.12.0's rendering issues. Frontend and editor share the chart's geometry as pure helper modules (margin computation, tick generation, scaling functions, curve building) but instantiate the result through different DOM hosts — vanilla DOM under the Interactivity API in `view.ts` for the frontend, React in the editor preview.

This decision was reached during the Step 2 design grilling and supersedes v0.12.0's hybrid SSR+JS approach. It is binding on every subsequent step in this document.

---

## Step 0 (released as v0.13.0) — recap

Empty-slate baseline released. `src/blocks/elevation/` reduced to `block.json` (registration metadata only), `icon.tsx`, `index.tsx`, a minimal `edit.tsx` rendering the placeholder text "GPX Elevation", and a minimal `render.php` emitting the same placeholder. v0.12.0's full implementation (the rebuild's reference) is preserved at the `v0.12.0` git tag — consult via `git show v0.12.0:src/blocks/elevation/<file>` whenever a step's *v0.12.0 reference* note points back to it.

Full Step 0 specification: `git show v0.13.0:docs/elevation-rebuild.md`.

---

## Step 1 (released as v0.13.1, refined in v0.13.1-pl.1) — recap

The codebase has the Elevation block's outer `<div>` with `useBlockProps`, both inspector tabs populated (Settings: Data Source + Tooltip info; Styles: Dimensions, Border, Box Shadow, a `PanelColorSettings` panel with 8 alpha-enabled colour rows, three collapsible `<PanelBody initialOpen={false}>`-wrapped Typography panels via the shared `TypographyToolsPanel` component). `block.json` declares 35 attributes and 6 `supports` blocks. `Color → Background` is wired through a `usefulValue()` wrapper to the inline CSS variable `--kntnt-gpx-blocks-elevation-background`; everything else persists values but has no rendering effect.

For orientation, read the relevant source files directly: `src/blocks/elevation/block.json` (attributes + supports), `src/blocks/elevation/edit.tsx` and `render.php` (the Background wiring is the template for Steps 3–7), `src/blocks/elevation/useful-value.ts` (three-state wrapper), `src/blocks/elevation/inspector-color.tsx` (also exports `elevationColorRows()` — the eight colour attributes' UI metadata as one list, for steps that need to iterate), `src/blocks/shared/typography-tools-panel.tsx` (shared component used by Elevation in Steps 4 and 7, and migrated to from Map in Step 8).

Full Step 1 specification (design rationale, acceptance criteria, manual verification list): `git show 3390f49:docs/elevation-rebuild.md`. The follow-up that aligned the inspector surface with GPX Map's UX (`PanelColorSettings` instead of a custom Color ToolsPanel; collapsible `PanelBody` wrappers around the Typography panels) is commit `d574087`, shipped as the interim release `v0.13.1-pl.1`.

---

## Step 2 (released as v0.13.2, refined in v0.13.2-pl.1) — recap

The Elevation block now resolves which GPX Map on the page supplies its data via the `mapId` attribute. The inspector's **Settings** tab carries a conditional Data Source ToolsPanel that surfaces a `SelectControl` only when the user has a choice to make (≥ 2 configured Maps, or a broken binding with ≥ 1 Map remaining). Picker entries are per Map *block* — no file-based deduplication — labelled through a three-tier fallback (`metadata.name` → `anchor` → `GPX Map #N`). Auto-pick is sticky and re-fires until successful: it writes the topmost configured Map's `mapId` whenever Elevation's own `mapId` is empty / `"auto"`, then stops touching the attribute. Editor preview is React-only (no `<ServerSideRender>`), with the bound payload fetched via `apiFetch` through the editor-only REST endpoint, whose response shape grew from `{ geojson }` to `{ geojson, statistics }`. The healthy state renders a temporary info box `"Bound to {label}. Min: {min} m, Max: {max} m."`; the three broken-binding states render matching warning boxes. The info and warning boxes are placeholders to be replaced by the chart in Steps 3–5; only the warning *state* survives.

For orientation, read the relevant source files directly: `src/blocks/elevation/edit.tsx` (orchestrator wiring the hooks and preview), `src/blocks/elevation/inspector-data-source.tsx` (Data Source panel + `SelectControl`, conditional visibility), `src/blocks/elevation/picker-label.ts` (the three-tier label resolver — pure, shared by inspector and preview), `src/blocks/elevation/use-map-blocks.ts` (recursive block-tree walk returning `{ mapBlocks, configuredMapBlocks, mapOptions }`), `src/blocks/elevation/use-auto-pick-map-id.ts` (re-fire-until-successful effect, live-attribute guard), `src/blocks/elevation/use-bound-map-payload.ts` (`apiFetch` wrapper, dedup-per-id), `src/blocks/elevation/preview.tsx` (warning / info box renderer), `src/blocks/elevation/render.php` (thin proxy delegating to `Render_Elevation`), `classes/Rendering/Render_Elevation.php` (`render_warning()` / `render_info()`, no SVG — the class is the permanent home for the wrapper + state + warning fallback that Steps 3+ build on), and `classes/Rest/Preview_Controller.php` (response shape extended to include `statistics`).

Full Step 2 specification (design rationale, the Q1–Q10 grilling outcomes, acceptance criteria, manual verification list): `git show a9d0352:docs/elevation-rebuild.md`. The follow-up release `v0.13.2-pl.1` (commit `d4bbb11`) fixed three user-reported edge cases: (1, 2) the broken-binding picker rendered the first option as visually selected and swallowed clicks on it — fixed by prepending a synthetic empty placeholder option in `inspector-data-source.tsx` whenever the binding is broken; (3) duplicating a GPX Map caused both the original and the duplicate to regenerate their `mapId`, breaking any existing binding to the original — fixed in `src/blocks/map/use-ensure-unique-map-id.ts` by only regenerating when an *earlier* Map block (pre-order traversal) carries the same `mapId`, so the original keeps its id and only the duplicate gets renamed. The follow-up's touch of `src/blocks/map/` is a deliberate exception to the Steps 0–7 no-touch rule, justified in the commit message.

---

## Step 3: Responsive axes

**Goal.** The block draws the X and Y axes inside a responsive SVG. No tick marks, no labels, no curve yet. Beyond the visible deliverable, Step 3 carries three architectural payloads that Steps 4–7 build on: the handoff from Step 2's server-side info/warning boxes to client-side chart rendering, the shared geometry helpers under `src/blocks/elevation/geometry/`, and the per-mapId Interactivity state contract.

**Load list:** `docs/blocks.md`

**v0.12.0 references:** none for the chart geometry itself — v0.12.0's hybrid SSR + JS approach is explicitly superseded by the *Rendering architecture* decision near the top of this document. `Render_Map`'s `wp_interactivity_state()` call at `classes/Rendering/Render_Map.php:330` is the structural template for Elevation's own state emission; the wrapper-directive pattern at `Render_Map.php:505–519` is the structural template for Elevation's wrapper. `MapEdit`'s `getDefaultMinHeight()` mechanism at `src/blocks/map/edit.tsx:1219–1232` is the structural template for the inline `min-height: 15vh` injection in `edit.tsx`.

### Rendering architecture (locked by the Step 3 grilling)

The cross-cutting "JS owns everything from here on" decision (see *Rendering architecture* near the top of this document) materialises in Step 3. Specific shapes locked during grilling:

**view.ts arrives in Step 3, not Step 6.** Step 2's earlier parenthetical that the file "arrives in Step 6 with the cursor" was corrected during grilling (and the parenthetical patched): the file lands in Step 3 with the chart mount via `data-wp-init="callbacks.initElevation"`. Step 6 adds cursor + store-write wiring on top of this existing file; Step 3 makes no store writes and does not yet read `state[mapId].fraction`.

**Shared geometry, host-specific instantiation.** The math (margin computation, nice-tick generation, m/km switching, label formatting) lives under `src/blocks/elevation/geometry/` as pure helper modules. Editor (`chart.tsx`, React) and frontend (`view.ts`, vanilla DOM under Interactivity) consume the same helpers but construct the SVG independently — React JSX in `chart.tsx`, `document.createElementNS` in `view.ts`. The duplication is small for Step 3 (two `<line>` elements per side) and the locked architecture explicitly accepts it as the price of host independence.

**Text measurement via SVG `<text>` + `getBBox()`.** The measurement primitive lives at `geometry/measure.ts`. It inserts a hidden `<text>` element into the chart SVG with the Tick labels typography applied, reads `getBBox()` for both width and height, removes the element. Measurement is byte-faithful to the eventual visible labels (Step 4) because both pass through SVG's text-rendering pipeline — no HTML-vs-SVG measurement drift. The trade-off is that measurement is coupled to SVG mount; React handles this via a two-pass `useLayoutEffect` (mount → measure → setState → re-render with axes), and `view.ts` handles it imperatively inside its `initElevation` callback.

### Wrapper sizing (locked by Q6)

`src/blocks/elevation/style.scss` sets `min-height: 15vh` on `.wp-block-kntnt-gpx-blocks-elevation`. **No aspect-ratio default.** `edit.tsx` follows the same `getDefaultMinHeight()`-style pattern Map uses today: it injects inline `minHeight: '15vh'` on `useBlockProps().style` *iff* the user has not set `style.dimensions.minHeight`. `Render_Elevation::render_chart_wrapper()` mirrors this server-side via `get_block_wrapper_attributes()` (core's Dimensions block-supports machinery, populated by a `Dimensions_Defaults`-style filter for the elevation namespace). Core's Dimensions panel overrides everything; standard CSS cascade, no special-case PHP/JS logic.

Map's analogous `aspect-ratio: 3 / 1 + min-height: 30vh` combination was identified as a bug during this grilling — the two mechanisms duplicate the height-defining role and let bredd indirectly drive höjd in a way the user never asked for. Map's wrapper is reduced to `min-height: 30vh` alone in Step 8.

### Margin algorithm (locked by Q2 + Q3)

The Step 1 spec's algorithm (six numbered steps from "Identify min and max elevation values" through "Draw the axes") is reformulated to consume **the nice-tick labels Step 4 will draw**, not raw `min_elevation` / `max_elevation` / `distance` values. The nice-tick helper that Step 4's spec originally introduced (tick-count derivation, nice-step rounding, m/km switching) **moves into Step 3** at `geometry/ticks.ts`. Step 4 reduces to "draw the ticks and labels Step 3 already computed".

Concrete margin formulas:

- `w_left = measure( widest( niceYLabels ) ).width + 0.5em`
- `w_right = measure( last( niceXLabels ) ).width / 2 + 0.5em`
- `h = measure( "-0,123456789" ).height + 0.5em`

Where:

- `niceYLabels` are the formatted tick labels over the Y range `[min_elevation, max_elevation]` (with the inflated `[min−1, min+1]` substitution from *Degenerate ranges* below when `min === max`).
- `niceXLabels` are the formatted tick labels over the X range `[0, distance]`, with Step 4's m/km switching rule applied: if more than half of the non-zero X tick values are ≥ 1000, the whole axis converts to km, the last label gets the `" km"` suffix, intermediate labels are unitless.
- `"-0,123456789"` is a fake reference string of extreme glyphs whose **height** (not width) drives `h`. The same character height applies to any realistic numeric label, so no axis-specific computation is needed for `h`.
- `em` is the Tick labels' computed font-size — pragmatically returned by `measure()` alongside `width` and `height`, so callers do not need to re-resolve the typography. (See *Axis appearance* below for the choice of font-size as the `em` base.)

### Container dimensions and SVG setup

The SVG's own `getBoundingClientRect()` is the content box by virtue of CSS layout — the SVG is a normal-flow child of the wrapper with `width: 100%; height: 100%;`, so its rendered rectangle sits inside any padding the user has set in the Dimensions panel. Reading the SVG's BCR sidesteps the box-sizing question entirely (no `clientWidth − paddingLeft − paddingRight` arithmetic).

`<svg width="100%" height="100%" viewBox="0 0 W H">` with `W`/`H` taken from the BCR gives a 1:1 mapping between viewport pixels and SVG user units inside the content box.

### Axis appearance (locked by Q9)

- **Stroke width: `1` (unitless, interpreted as user units → 1 px at the SVG's 1:1 mapping).** Fixed, not user-configurable (no Stroke Width inspector control in Step 1).
- **Stroke colour: `var(--kntnt-gpx-blocks-elevation-axis)`.** Wired through Step 1's `usefulValue()`-wrapped colour attribute; inline custom-property emitted on the wrapper in `edit.tsx` and `Render_Elevation`; default value in `style.scss`.
- **`em` base for the margin formulas:** the Tick labels' computed font-size. `0.5em` of padding therefore scales 1:1 with the etikett-typografi the user has chosen — a doubling of font-size doubles the marginal-padding.
- **Axes drawn:**
  - X axis: `<line x1="${wLeft}" y1="${H - h}" x2="${W - wRight}" y2="${H - h}" stroke="var(--kntnt-gpx-blocks-elevation-axis)" stroke-width="1" />`
  - Y axis: `<line x1="${wLeft}" y1="${H - h}" x2="${wLeft}" y2="0" stroke="var(--kntnt-gpx-blocks-elevation-axis)" stroke-width="1" />`

### Degenerate ranges (locked by Q7)

Three cases trigger non-default behaviour. The healthy-path chart rendering applies only when none of A/B/C is in effect.

- **Case A — `min_elevation === null` or `max_elevation === null`.** GPX track has no `<ele>` data. `Render_Elevation::render_warning('no_elevation_data')` displays the localised string *"Det inbundna GPX-spåret saknar höjddata. Höjdprofilen kan inte renderas."*. Same orsak handled by `preview.tsx` in the editor.
- **Case B — `min_elevation === max_elevation` (non-null, flat track).** Y range is inflated to `[min − 1, min + 1]` (one metre symmetric around the value) for tick generation and axis drawing. Chart draws normally; the curve in Step 5 will be a horizontal line through the middle of the plot area. This is the only B-case that affects geometry; the warning system is not invoked.
- **Case C — `distance === 0`.** No track to render. `Render_Elevation::render_warning('zero_distance')` displays *"Det inbundna GPX-spåret saknar avstånd (alla punkter ligger på samma position)."*. Same orsak in `preview.tsx`.

The five PHP-side warning reasons after Step 3 are: `no_map_on_page`, `bound_map_deleted`, `bound_map_unconfigured` (Step 2), `no_elevation_data`, `zero_distance` (Step 3).

### Transient editor states (locked by Q10)

- **Loading (`useBoundMapPayload` returns `isLoading: true`).** `chart.tsx` returns `null`; the wrapper still occupies its `min-height: 15vh` slot. Chart pops in when data lands. No spinner, no skeleton — sub-100 ms latency is invisible.
- **REST error (`useBoundMapPayload` returns `error`).** `preview.tsx` switches to a sixth warning reason `payload_error` (editor-only — frontend cannot reach this state because PHP server-renders the state payload). Localised string draft: *"Kunde inte hämta data för det inbundna GPX-spåret. Försök ladda om sidan."*. The full error is logged to `console.error` for DevTools users. `payload_error` is **not** added to PHP's `render_warning()` enum.
- **Font-loading.** Both `chart.tsx` and `view.ts` `await document.fonts.ready` before the first measurement, and attach a `document.fonts.addEventListener('loadingdone', …)` listener that re-runs measurement when late-loaded fonts arrive. In React this materialises as a `fontsReady` state that gatekeepers `useLayoutEffect`; in vanilla view.ts the `await` happens inline inside `initElevation`.

### Interactivity state and wrapper directives (locked by Q8)

Per-mapId state-slot merge. `Render_Elevation::render()`, in healthy state, calls

```php
wp_interactivity_state( 'kntnt-gpx-blocks', [
    $map_id => [
        'statistics' => [
            'min_elevation' => $min,
            'max_elevation' => $max,
            'distance'      => $distance,
        ],
    ],
] );
```

This *merges* with Map's per-mapId emission (which carries `geojson`, `totalDistance`, `fraction: null`, etc., per `Render_Map.php:330`). The result on the client: `state.kntntGpxBlocks[mapId].statistics.{min_elevation, max_elevation, distance}` alongside `state.kntntGpxBlocks[mapId].geojson` (the Step 5 curve consumer) and `state.kntntGpxBlocks[mapId].fraction` (the Step 6 cursor consumer). **Map's `Render_Map.php` is not touched.**

Wrapper HTML pattern, parallel to `Render_Map`:

```html
<div [block-wrapper-attrs]
     role="img"
     aria-label="<i18n: Elevation profile of GPX track>"
     data-wp-interactive='{"namespace":"kntnt-gpx-blocks"}'
     data-wp-context='{"mapId":"…"}'
     data-wp-init="callbacks.initElevation">
  <noscript><p>This elevation profile requires JavaScript to display. The track is recorded in the GPX file referenced by this block.</p></noscript>
</div>
```

`role="img"` (not `application`) because Step 3's chart is non-interactive. Step 6's cursor upgrades the role to `application`. `data-wp-watch--cursor` is added in Step 6, not here.

view.ts skeleton:

```ts
import { store, getContext } from '@wordpress/interactivity';

store('kntnt-gpx-blocks', {
  callbacks: {
    async initElevation() {
      const ctx = getContext<{ mapId: string }>();
      const { state } = store('kntnt-gpx-blocks');
      const slice = state[ctx.mapId];
      await document.fonts.ready;
      // mount SVG, create measurer, compute margins, draw axes, observe resize
    },
  },
});
```

### File layout for Step 3

```
src/blocks/elevation/
├── block.json                                — modified: adds viewScriptModule, style, editorStyle
├── view.ts                                   — NEW: vanilla mount under Interactivity API
├── chart.tsx                                 — NEW: React component, healthy-state SVG host
├── chart.test.tsx                            — NEW: RTL tests; stubs getBBox in jsdom
├── style.scss                                — NEW: wrapper baseline (min-height 15vh, axis CSS variable default)
├── editor.scss                               — NEW: editor-only overrides (often empty in Step 3; reserved for Step 4+ tweaks)
├── edit.tsx                                  — modified: inline minHeight + axis CSS variable injection
├── preview.tsx                               — modified: switch extended with <Chart> (healthy) and payload_error (editor-only)
├── render.php                                — modified: thin proxy delegating to Render_Elevation's extended render()
└── geometry/
    ├── ticks.ts                              — NEW: nice-tick generator (Y + X, m/km switch)
    ├── ticks.test.ts                         — NEW
    ├── format.ts                             — NEW: label formatting (m/km units, locale-aware decimals)
    ├── format.test.ts                        — NEW
    ├── margins.ts                            — NEW: computeMargins(data, typography, measureFn) → {wLeft, wRight, h, em}
    ├── margins.test.ts                       — NEW: injects mock measureFn; fully DOM-free
    └── measure.ts                            — NEW: createTextMeasurer(svg) → (text, typography) → {width, height, fontSize}

classes/Rendering/
└── Render_Elevation.php                      — modified: render_info() removed; render_chart_wrapper() added; render_warning() extended to 5 reasons

tests/Unit/
└── Render_ElevationTest.php                  — modified: tests for new warning reasons (no_elevation_data, zero_distance) and render_chart_wrapper (state emission, attribute escaping)
```

The seam between pure helpers and DOM-bound work runs through `geometry/`: everything in there *except* `measure.ts` is fully unit-testable without DOM. `measure.ts`'s logic that does not depend on `getBBox` (applying typography attributes to a `<text>` element) is testable in isolation; the `getBBox` call itself is integration-verified via WordPress Playground.

### Acceptance criteria

Step 3 is considered done — and `v0.13.3` may be tagged — when **all** of the following hold.

**Behaviour:**

1. The block still appears in the inserter under the "Kntnt" category with the Step-1 name and icon (no regression).
2. `block.json`'s 35 attributes and 6 `supports` blocks are unchanged from Step 1. New fields added: `viewScriptModule`, `style`, `editorStyle`.
3. **Wrapper sizing:** `style.scss` declares `min-height: 15vh` on `.wp-block-kntnt-gpx-blocks-elevation`; `edit.tsx` and `Render_Elevation` inject inline `min-height: 15vh` iff the user has not set `style.dimensions.minHeight`. No aspect-ratio default. Core's Dimensions panel overrides everything via the normal cascade.
4. **Healthy state** (binding resolves to a configured Map with usable data, none of A/B/C triggered): two `<line>` elements drawn inside a `<svg viewBox="0 0 W H" width="100%" height="100%">`, sized by the margin algorithm. `stroke-width="1"`, `stroke="var(--kntnt-gpx-blocks-elevation-axis)"`. No ticks, no labels, no curve.
5. **Margin algorithm:** `w_left` uses the widest nice-Y-label width; `w_right` uses the last nice-X-label half-width; `h` uses the height of `"-0,123456789"`. All three add `0.5em` where `em` is the Tick labels' computed font-size. Nice-tick generation (Y + X) and m/km switching live in `geometry/ticks.ts`; the unit-switch threshold (Step 4 rule 3: more than half of non-zero X ticks ≥ 1000 ⇒ km) is observed.
6. **Editor preview:** `chart.tsx` is mounted inside `preview.tsx`'s healthy branch. It uses `useRef` on the SVG, `useLayoutEffect` for measurement (deps: data + typography), and a separate `useEffect` to attach `ResizeObserver` to the SVG element. ResizeObserver triggers axis redraw but does **not** re-run measurement (margins depend only on typography and data, neither of which the wrapper-size change implies).
7. **Frontend:** `view.ts`'s `initElevation` callback runs once per Elevation block on the page. It reads `state[ctx.mapId].statistics`, awaits `document.fonts.ready`, mounts SVG, measures, draws axes, sets up `ResizeObserver` on the SVG element. No store writes in Step 3.
8. **Font-loading:** both editor and frontend wait on `document.fonts.ready` before the first measurement and re-measure on `document.fonts`' `loadingdone` event.
9. **Degenerate ranges:** Case A (`min` or `max` is `null`) → `render_warning('no_elevation_data')` + matching `preview.tsx` branch. Case B (`min === max`) → Y range inflated to `[min−1, min+1]`, chart renders. Case C (`distance === 0`) → `render_warning('zero_distance')` + matching `preview.tsx` branch.
10. **Editor transient states:** `useBoundMapPayload` `isLoading: true` → `chart.tsx` returns `null` (wrapper still occupies 15vh). `useBoundMapPayload` `error` → `preview.tsx` shows the `payload_error` warning (editor-only; not in PHP's `render_warning()` enum).
11. **Interactivity state contract:** `Render_Elevation::render()` in healthy state merges `{ 'statistics' => […] }` onto `state[mapId]` via `wp_interactivity_state('kntnt-gpx-blocks', …)`. Map's `Render_Map.php` is unchanged.
12. **Wrapper directives:** `data-wp-interactive='{"namespace":"kntnt-gpx-blocks"}'`, `data-wp-context='{"mapId":"…"}'`, `data-wp-init="callbacks.initElevation"`, `role="img"`, translatable `aria-label`, `<noscript>` fallback. `data-wp-watch--cursor` arrives in Step 6 — not here.
13. **Step 2 obsolete pieces removed:** `Render_Elevation::render_info()` is deleted; `preview.tsx`'s healthy-state info-box is replaced by `<Chart>`. Step 2 acceptance criterion 8 (the *"Bound to {label}. Min: {min} m, Max: {max} m."* string) is no longer a contract.

**Gates (must all pass at HEAD before tagging):**

14. `npm run build`.
15. `composer test` (Pest) — including the extended `Render_ElevationTest` for `no_elevation_data`, `zero_distance`, and `render_chart_wrapper` (state-emission shape, wrapper-attribute escaping).
16. `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M`.
17. `npm run test:js` — including the new `geometry/ticks.test.ts`, `geometry/format.test.ts`, `geometry/margins.test.ts` (with mock measureFn), `chart.test.tsx`, and the extended `preview.test.tsx` for the `payload_error` branch.
18. `npx wp-scripts lint-js src/blocks/`.

**Manual verification in WordPress Playground (`@wp-playground/cli`):**

19. Insert one configured Map, then insert Elevation: two axis lines appear in the editor preview; same after save on the frontend.
20. Resize the browser window: axes reposition (X axis at the bottom, Y axis at the left), wrapper height obeys `15vh` (or user-set value).
21. Change Tick labels font-size to double the default: margins recompute, Y axis moves right and X axis moves up to accommodate the larger labels.
22. Change Color → Axis in the inspector: axis stroke colour updates live in the editor; same on the frontend after save.
23. **Case B verification** — track with `min_elevation === max_elevation` (e.g. a deliberately flat GPX): chart renders with the inflated `[min−1, min+1]` Y range; axes look normal.
24. **Case A verification** — track without `<ele>` elements: `no_elevation_data` warning replaces the chart in both editor and frontend.
25. **Case C verification** — single-point GPX (`distance === 0`): `zero_distance` warning.
26. **Webfont verification** — theme that lazy-loads a Google Font for Tick labels typography: chart shows correct margins after the font finishes loading; no permanent margin error based on fallback-font metrics; the axes may briefly reposition once when the font lands (`loadingdone` listener fires).
27. Two Elevation blocks bound to different Map blocks on the same page: both render independently with correct per-map data.
28. **Frontend state inspection** — view the rendered page's source and confirm the Interactivity state JSON contains `kntnt-gpx-blocks.<mapId>.statistics` with the correct min/max/distance values for the bound track.
29. **REST-error verification** — in the editor, use DevTools to block requests to `/wp-json/kntnt-gpx-blocks/v1/preview/<attachmentId>`: `payload_error` warning replaces the chart.

### Release

When all acceptance criteria hold, follow the six-step release procedure documented in `AGENTS.md` (section *Cutting a release*). Tag `v0.13.3`. Commit message: `Release v0.13.3 — Step 3: responsive axes with client-side rendering`.

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

## Step 8: Migrate GPX Map to the shared `TypographyToolsPanel`, fix the wrapper-sizing default, and expose `mapId` in the inspector

**Goal.** Three changes on Map:

1. Map's two hardcoded Typography panels (*Waypoint name*, *Waypoint description*) are replaced with the shared `TypographyToolsPanel` component introduced in Step 1 (pure deduplication, no user-visible behaviour change).
2. Map's wrapper-sizing default is reduced to `min-height: 30vh` only — the `aspect-ratio: 3 / 1` in `src/blocks/map/style.scss` is removed. The two together duplicate the height-defining mechanism (whichever is larger wins at any given width), which makes the rendered height bredd-beroende in a way the user never asked for. This is a real user-visible change: at widths where the aspect-ratio used to dominate (roughly ≥ 90 vw on a typical viewport), Map wrappers will be shorter after Step 8 than before. That is the intended outcome; users who want the old behaviour can set `aspectRatio` themselves via the Dimensions panel.
3. The Map block's auto-generated `mapId` is surfaced read-only in the inspector with click-to-copy. The id has been an internal attribute since v0.13.0 (auto-assigned by `useEnsureUniqueMapId`, used by the cursor-sync store key and by `Resolve_Map_Id`), but the editor has had no way to *see* it. Step 8 exposes it so the user can paste it into `[kntnt-gpx <key> map="<id>"]` shortcodes (and into the GPX Elevation picker's "explicit mapId" path) when a page has more than one GPX Map. This is the implementation of the favoured direction from [#137](https://github.com/Kntnt/kntnt-gpx-blocks/issues/137) — surface the existing identifier rather than introduce a new one.

**Load list:** `docs/blocks.md`

This is the **first step where Map's source files are modified** — the no-touch rule that applied to Steps 0–7 lifts here. The TypographyToolsPanel migration is mechanical because the shared component was designed to handle Map's two prefixes (`tooltipName`, `tooltipDesc`) from Step 1 and its prefix-mapping unit test already covers them. The wrapper-sizing fix is a one-line SCSS removal plus test adjustments. The mapId exposure is small and self-contained — a read-only inspector row plus a click handler.

**Tasks:**

1. In `src/blocks/map/edit.tsx`, replace the inline JSX for the *Waypoint name* and *Waypoint description* Typography ToolsPanels with two `<TypographyToolsPanel>` invocations:
   - One with `prefix="tooltipName"`, title "Waypoint name", default-visibility matching Map's current panel.
   - One with `prefix="tooltipDesc"`, title "Waypoint description", default-visibility matching Map's current panel.
2. Verify in the editor that both panels render identically to before, that ToolsPanel ellipsis / ResetAll behave correctly, and that attribute reads/writes still hit the same `tooltipName*` / `tooltipDesc*` keys.
3. Remove the `aspect-ratio: 3 / 1;` line from `src/blocks/map/style.scss`, leaving only `min-height: 30vh;` as the wrapper-sizing baseline. The comment block in `style.scss` that explains the dual-mechanism rationale (around the `aspect-ratio` line) is removed alongside it; what survives is "use `min-height: 30vh` as the default, let core's Dimensions block-supports override when the user sets anything explicit".
4. Adjust `getDefaultMinHeight()` and the corresponding PHP-side `Dimensions_Defaults` filter so their condition is **"user has not set `minHeight`"** rather than the current **"user has set neither `minHeight` nor `aspectRatio`"**. A user-set `aspectRatio` no longer suppresses the default min-height; if both are set, both apply and the larger one wins at any given width (normal CSS cascade).
5. Update Map's `edit.test.tsx` cases that cover the default-min-height permutations — at minimum the four tests around lines 537–598 that assert behaviour conditional on `aspectRatio` being set or blank. The post-Step-8 contract is simpler: 30vh is injected iff `minHeight` is blank.
6. **Expose `mapId` in the inspector with click-to-copy.** Add a read-only row to Map's Settings tab — a small labelled control (label: `Map ID`, translatable) whose value is the block's current `mapId`. The value is rendered as monospace inline text (e.g. inside a `<code>` element styled as a button), and a single click on it copies the id to the clipboard via `navigator.clipboard.writeText()`, with a transient success notice (e.g. `wp.data.dispatch('core/notices').createNotice('success', __('Map ID copied to clipboard', 'kntnt-gpx-blocks'), { type: 'snackbar', isDismissible: true })`). The row is shown only when `mapId` is non-empty (i.e. after `useEnsureUniqueMapId` has assigned one — Step 2's empty-mapId derived rule still applies on the Elevation side). No editing affordance: the id is read-only because it is the cross-block binding key, and re-typing it would silently break every Statistics shortcode and Elevation block already bound to the old value. Place the row in a sensible existing panel (or a tiny new `Map ID` ToolsPanel — pick whichever fits Map's current inspector layout best at implementation time). Add a co-located Jest test asserting the click writes the id to `navigator.clipboard` and emits the snackbar.
7. Remove any helper code in `src/blocks/map/` that the migrations leave dead.
8. Run all gates (build, PHPStan, JS lint, JS tests, PHP tests).
9. Release as `v0.13.8` per the per-step release procedure. Commit message: `Release v0.13.8 — Step 8: migrate GPX Map to shared TypographyToolsPanel, fix wrapper-sizing default, expose mapId`.

**Note on Border & Shadow.** The Map block already uses core's standard two-panel layout (one `ToolsPanel` for Border via `supports.__experimentalBorder`, one for Box Shadow via `supports.shadow`) — there is no custom Border-and-Shadow component to refactor. The Border/Shadow surface is left untouched.

**Note on the wrapper-sizing decision provenance.** Task 3 was added during Step 3's design grilling (commit history will show this), after the same single-mechanism choice was made for Elevation's own wrapper (`min-height: 15vh`, no aspect-ratio). Step 3's choice surfaced the latent ambiguity in Map's existing defaults; this task makes both blocks consistent.

---

## When the rebuild is complete

When Step 8 lands and the migration ships, this document is no longer authoritative — `docs/blocks.md` is. Either delete `docs/elevation-rebuild.md`, or move it to `docs/archive/` if the narrative is worth keeping.
