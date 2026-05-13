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

## Step 3 (released as v0.13.3, refined in v0.13.3-pl.1) — recap

JS now owns the entire chart geometry. The frontend `view.ts` registers `callbacks.initElevation` on the shared `kntnt-gpx-blocks` Interactivity store, awaits `document.fonts.ready`, mounts an SVG into the wrapper, runs the margin algorithm against the per-mapId state slice, draws two axis `<line>` elements, and listens for `loadingdone` + `ResizeObserver` events to re-measure on late-loaded fonts and redraw on container resize. The editor preview is the React mirror in `chart.tsx`, consuming the same pure helpers under `src/blocks/elevation/geometry/`. `Render_Elevation::render_chart_wrapper()` emits the Interactivity-bound wrapper (`role="img"`, translatable `aria-label`, `data-wp-interactive`, `data-wp-context`, `data-wp-init`, `<noscript>` fallback) plus `wp_interactivity_state('kntnt-gpx-blocks', [ $map_id => [ 'statistics' => […] ] ])`. Five warning reasons land — `no-map`, `bound-deleted`, `bound-unconfigured`, `no-elevation-data` (Case A), `zero-distance` (Case C). `Dimensions_Defaults` now injects `min-height: 15vh` on Elevation whenever `minHeight` is blank (regardless of `aspectRatio` — a deliberate departure from Map's gate, since Elevation has no SCSS aspect-ratio baseline). `render_info()` is gone; the editor preview's healthy branch dispatches to `<Chart>`.

For orientation, read the relevant source files directly: `src/blocks/elevation/geometry/format.ts` (locale-aware label formatting, m/km switching, locked decimal-digit rule), `src/blocks/elevation/geometry/ticks.ts` (`[1, 2, 5] × 10^n` nice-step series, tick-count derivation), `src/blocks/elevation/geometry/margins.ts` (the `wLeft = widest(niceYLabels) + 0.5em` / `wRight = last(niceXLabels)/2 + 0.5em` / `h = measure("-0,123456789").height + 0.5em` formulas; Step 3 Case-B inflation lives here), `src/blocks/elevation/geometry/measure.ts` (the `<text>` + `getBBox()` measurer; the only DOM-bound module in the geometry layer), `src/blocks/elevation/chart.tsx` (React editor preview — `useRef`, `useLayoutEffect`, `ResizeObserver`, `document.fonts.ready` + `loadingdone`), `src/blocks/elevation/view.ts` (frontend vanilla-DOM mount under the Interactivity API; no store writes, idempotent via per-element `WeakSet`), `src/blocks/elevation/style.scss` (wrapper `position: relative` + `min-height: 15vh` baseline, chart SVG absolute-positioned with `inset: 0` so its intrinsic viewBox ratio cannot drag the wrapper's height, plus the axis colour default), `src/blocks/elevation/style.test.ts` (pins the four CSS contracts the v0.13.3-pl.1 follow-up locked down), `src/blocks/elevation/preview.tsx` (six-kind discriminated union + dispatch to `<Chart>` in healthy state), `src/blocks/elevation/edit.tsx` (axis-colour custom property wiring + `getDefaultMinHeight()` injection + tick-label typography forwarding), `classes/Rendering/Render_Elevation.php` (the 5-reason warning enum + `render_chart_wrapper()` + per-mapId state emission), `classes/Rendering/Dimensions_Defaults.php` (per-block gate strategy — Map keeps "both blank", Elevation uses "minHeight blank" alone), `src/blocks/shared/dimensions-defaults.ts` (editor mirror of the same per-block strategy).

Full Step 3 specification (design rationale, the Q-by-Q grilling outcomes, acceptance criteria, manual verification list): `git show 530d945:docs/elevation-rebuild.md`. The follow-up release `v0.13.3-pl.1` (commit `9bf2c20`) fixed three user-reported SVG-sizing regressions that shared one root cause: the chart SVG sat in normal flow with `width/height: 100%` against a wrapper that only had `min-height`, so percentage heights did not resolve and the SVG's intrinsic viewBox ratio (300×150 by default, or the previous render's viewBox) dragged the wrapper into the wrong shape through the replaced-element sizing rules. Symptoms: (1) picking "Original" again in the Aspect Ratio dropdown kept the editor wrapper at its previous aspect-ratio; (2) freshly inserted blocks rendered at ≈ 18.5 vh instead of the documented 15 vh baseline; (3) enlarging `min-height` in the inspector grew the wrapper but left the chart frozen at the initial render size. Fix: absolute-position the SVG with `inset: 0` plus `position: relative` on the wrapper, so the SVG fills the wrapper's content box without contributing to its intrinsic size. A new `style.test.ts` reads the SCSS file directly and pins the four CSS contracts (`position: relative` on the wrapper, `position: absolute` + `inset: 0` on the SVG, and the `min-height: 15vh` baseline) so the rule cannot regress silently.

---

## Step 4 (released as v0.13.4, refined in v0.13.4-pl.1) — recap

Both axes now carry tick marks and locale-formatted numeric labels driven by the GPX data range. The X axis is Strava-style data-bound — range `[0, distance]` with nice ticks filtered to `value ≤ distance` — and switches between metres and kilometres on a deterministic `distance < 2000` threshold; the origin tick is always rendered. The Y axis is nice-bound — range `[floor(yMin/step) · step, ceil(yMax/step) · step]` — with the lowest tick anchored to the X axis line and the highest at `y = wTop`. `chooseXUnit` now takes a single `distance` number (the values-list signature is gone); a new `xReferenceString(distance, locale)` returns a typographically worst-case label keyed off `distance` alone, breaking the chicken-and-egg between N and label widths so `wRight` is computed once and remains stable across resize. `computeTickCount` uses the additive formula `floor((avail + padding) / (refSize + padding))` clamped to ≥ 2, where `padding = 1em` after the v0.13.4-pl.1 follow-up widened the luft term from `0.5em` (replacing Step 3's proportional `× 1.5` factor). `Margins` carries a new field `wTop = 0.5 · ref.height + 0.5em` that reserves the upper half of the topmost Y label against the SVG viewBox top; `wRight = measure(xReferenceString(distance)).width / 2 + 0.5em`; `wLeft` and `h` are unchanged from Step 3. Tick marks render as one `<line>` per tick (length `0.2em`; X marks downward from `( xTick, H − h )`, Y marks leftward from `( wLeft, yTick )`), grouped under `.kntnt-gpx-blocks-elevation-ticks-{x,y}`. Tick labels render as `<text>` grouped under `.kntnt-gpx-blocks-elevation-tick-labels-{x,y}`: X uses `text-anchor="middle"` + `dominant-baseline="hanging"` at `y = ( H − h ) + 0.5em`; Y uses `text-anchor="end"` + `dominant-baseline="central"` at `x = wLeft − 0.5em`. The `axisLabelColor` attribute (already declared in Step 1) is wired through three surfaces — `edit.tsx`, `Render_Elevation::render_chart_wrapper()`, and `style.scss` — to a new CSS variable `--kntnt-gpx-blocks-elevation-axis-label` (default `#1e1e1e`), letting users override axis-line and axis-label colours independently. ResizeObserver recomputes `N_x`, `N_y`, the tick sets, and label positions on resize but never invalidates margins; axis line start positions stay pixel-stable.

For orientation, read the relevant source files directly: `src/blocks/elevation/geometry/format.ts` (`chooseXUnit(distance)` + new `xReferenceString(distance, locale)` with the typographic `"8"`-digit worst-case construction; the m-mode `+2` and km-mode `+1` digit buffers documented inline), `src/blocks/elevation/geometry/format.test.ts` (the m-mode / km-mode verification tables plus the sv-SE / en-US locale-separator pair), `src/blocks/elevation/geometry/ticks.ts` (additive `computeTickCount(avail, refSize, em)` clamped ≥ 2; the `padding = em` literal that the v0.13.4-pl.1 follow-up bumped from `0.5 * em`), `src/blocks/elevation/geometry/margins.ts` (`wRight` derived from `xReferenceString`, new `wTop` field, `wLeft` and `h` carried over from Step 3 unchanged), `src/blocks/elevation/chart.tsx` (React preview — `buildXTicks` / `buildYTicks` project values to SVG coordinates, tick-mark and tick-label groups rendered as JSX, Y axis line starts at `y = margins.wTop`), `src/blocks/elevation/view.ts` (frontend vanilla-DOM mirror under the Interactivity API; same redraw sequence as `chart.tsx`, group replacement via `document.createElementNS`), `src/blocks/elevation/edit.tsx` (`axisLabelColor` custom-property wiring alongside the existing `axisColor` wiring), `src/blocks/elevation/style.scss` (default value for `--kntnt-gpx-blocks-elevation-axis-label`), `classes/Rendering/Render_Elevation.php` (server-side inline emission of the axis-label custom property), `tests/Unit/Rendering/Render_ElevationTest.php` (extended with an `axisLabelColor` wiring assertion).

Full Step 4 specification (design rationale, the Q-by-Q grilling outcomes, acceptance criteria, manual verification list): `git show 0fcd855:docs/elevation-rebuild.md`. The follow-up release `v0.13.4-pl.1` (commit `1c5a94a`) widened the additive luft term in `computeTickCount` from `0.5em` to `1em` on both axes. Y labels were crowding together visibly at the previous constant; the wider gap also reduces how often `N_y` flips during an editor min-height drag, mitigating the secondary label-jitter symptom (a full debouncing fix is deferred). Side effect: slightly fewer ticks on the X axis too, which keeps horizontal and vertical breathing room consistent. The change is mechanical (the `padding` local in `computeTickCount` becomes `em` instead of `0.5 * em`) and scoped to `src/blocks/elevation/geometry/ticks.ts` and its test file.

---

## Step 5: Elevation curve

**Goal.** The elevation profile is drawn inside the plot rectangle as two SVG `<path>` elements — an optional filled area below the curve and the line itself on top. Both render client-side from a server-emitted, LTTB-downsampled (distance, elevation) samples array, computed once in PHP and consumed byte-identically by the editor preview (`chart.tsx`) and the frontend Interactivity host (`view.ts`) through a new shared chart-scale helper that subsumes Step 4's inline projection math.

**Load list:** `docs/blocks.md`, `docs/hooks.md`

**v0.12.0 references:** `classes/Rendering/Lttb.php`, `tests/Unit/LttbTest.php` (both resurrected verbatim in this step). v0.12.0's `Render_Elevation::extract_line_coordinates()` and `build_distance_elevation_series()` private static methods are consulted for the (distance, elevation) extraction pattern, then ported into the new `Rendering\Elevation_Samples` class. v0.12.0's server-rendered `<polyline>` is **not** reborn — JS owns the SVG per the *Rendering architecture* decision near the top of this document. The orphaned filter `kntnt_gpx_blocks_elevation_target_points` documented in `docs/hooks.md:47` is reactivated by this step without a doc edit.

### Design rationale (locked by the Step 5 grilling)

**Server-side downsampling, not client-side.** The (distance, elevation) extraction runs in PHP at render time, then passes through LTTB to a target point count (default 300, filter-tunable) before being emitted into the per-mapId Interactivity state slice. The alternative — emit full-fidelity GeoJSON and let JS run Haversine + simplification at mount — was rejected for three reasons. First, the client would pay the Haversine pass on every page load by every visitor while PHP pays it once per render. Second, the full-fidelity payload for a typical 3-hour 1 Hz recording is ~240 KB raw JSON / ~75 KB gzipped vs ~7 KB raw / ~2 KB gzipped for 300 LTTB-reduced points — a meaningful difference on mobile budgets. Third, keeping the JS mount work small keeps the perceived "block ready" latency tight. Visual fidelity is preserved by LTTB: the algorithm preserves peaks and valleys deterministically, and at typical chart widths (300–1500 CSS pixels) the rendered curve is visually indistinguishable from the full-fidelity version.

**Reusing Map's already-simplified GeoJSON was rejected.** `Render_Map` emits a Douglas-Peucker-simplified GeoJSON (default 5 m tolerance) plus a parallel `trackCumDist` array. Piggy-backing on that payload would save one extraction pass but would couple Elevation's fidelity to Map's DP tolerance, which operates in lon/lat space — a stretch of GPX that runs in a straight line on the map but rolls vertically would be simplified to a single map-segment by Map's DP, hiding exactly the elevation features the chart needs to show. The two blocks need independent simplification driven by their own rendering domain.

**LTTB resurrection, not rewrite.** v0.12.0 shipped `classes/Rendering/Lttb.php` (~190 LOC) plus `tests/Unit/LttbTest.php` (~260 LOC) under the project's coding standard, with a documented algorithm contract (`downsample(array $points, int $target): array`, endpoints preserved, deterministic tie-breaks). The algorithm is unit-agnostic — it sees `[x, y]` pairs without knowing what they mean — and the implementation has not been superseded by any change in the literature. Resurrecting both files verbatim from the `v0.12.0` tag costs zero code-review surface and yields a tested helper from minute one.

**Linear path segments, not smoothed curves.** Modern data-visualisation convention renders elevation profiles with straight line segments between samples (Strava, Garmin, Komoot, Highcharts, Recharts, d3's default `curveLinear`). Cubic Bezier or Catmull-Rom smoothing introduces overshoot at sharp peaks and implies invented detail between measurements — the opposite of what an LTTB-preserved peak should look like. The path's `d` attribute is built as `M x0 y0 L x1 y1 L x2 y2 …` with `toFixed( 1 )` precision (10 cm on a typical 1000-px-wide chart) and explicit `L` per segment for devtools readability.

**Plot fill as its own attribute, not derived from plot line.** Step 1 declared `plotLineColor` (singular) without a separate fill attribute. Step 5 adds a 9th colour row, `Plot fill`, positioned directly after `Plot line` in the Color panel. Default value is the empty string, which means no inline custom property is emitted and the SCSS default of `transparent` applies — the fill `<path>` is in the DOM but renders invisibly. The user opts in to fill by picking a colour (typically with reduced alpha via the panel's `enableAlpha`, e.g. `rgba( 34, 197, 94, 0.25 )` for a translucent fill that doesn't overpower the line). Rejected alternatives: (a) deriving the fill from `plotLineColor` via `color-mix` with a hardcoded alpha — opinionated and locks the user out of decoupled control; (b) skipping fill entirely — leaves a real visual style choice off the table to preserve Step 1's eight-row count. The pre-1.0 policy explicitly permits breaking the Step 1-locked inspector surface, and the cost is ~30 lines distributed over six files. Step 1's recap in this document is **not** retroactively edited; Step 5's spec records the amendment.

**Two `<path>` elements, not one combined.** A single `<path>` carrying both `fill` and `stroke` would draw the stroke along the closing baseline edge, painting a horizontal line under the curve that visually pollutes the X axis. Emitting fill and stroke as two separate `<path>` elements keeps the stroke open (`M x0 y0 L … L xn yn`) and the fill closed (`M x0 plotBottom L x0 y0 L … L xn yn L xn plotBottom Z`). The fill path is always emitted; its visibility is governed by the CSS variable resolving to `transparent` (default) vs a real colour (user-set), which keeps the SVG host code free of conditional emission logic.

**Shared `ChartScale` helper, not duplicated projection math.** Step 4's `buildXTicks` and `buildYTicks` compute tick positions inline using projection formulas (`plotLeft + (v/distance) · availX`, `plotBottom − ((v − niceYMin) / (niceYMax − niceYMin)) · availY`). Step 5 needs the same projection functions to project samples onto the SVG canvas. Three alternatives were considered: (i) both helpers call `niceTicks` independently — relies on determinism, an implicit "must stay consistent" coupling; (ii) hoist `niceYMin` / `niceYMax` to the redraw loop and pass to both helpers as scalars — explicit shared value but no abstraction; (iii) extract a `ChartScale` object bundling `projectX`, `projectY`, `availX`, `availY`, `plotLeft`/`plotRight`/`plotTop`/`plotBottom`, `niceYMin`/`niceYMax`, and the X/Y tick sets. Step 5 adopts (iii) because Step 6's cursor (`projectX` + `projectY` for cursor positioning) and Step 7's tooltip (`availX`, `plotLeft`/`plotRight` for flip-on-overflow logic) both naturally consume the same object. Introducing it now amortises across three steps; deferring it would require extracting `projectY` separately for Step 6 anyway. The cost is ~50 lines of new helper code plus a refactor of `chart.tsx` and `view.ts` that *removes* their local `buildXTicks` / `buildYTicks` functions in favour of the consolidated `computeChartScale()` call — net change in those two files is roughly neutral.

### Server-side samples pipeline (locked by Q1 + Q3 + Q5 grilling)

**LTTB resurrection.** Both files come back verbatim from the `v0.12.0` tag:

```
git show v0.12.0:classes/Rendering/Lttb.php > classes/Rendering/Lttb.php
git show v0.12.0:tests/Unit/LttbTest.php > tests/Unit/LttbTest.php
```

The algorithm contract is unchanged: `Lttb::downsample( array $points, int $target ): array`, endpoints preserved, deterministic tie-breaks on lowest source index, pass-through when `count( $points ) <= $target`. Both files were authored under HEAD's coding standard and pass HEAD's PHPCS / PHPStan configurations as-is; if either lints with a warning the resolution is to fix in place, not to rewrite the algorithm.

**New `Rendering\Elevation_Samples` class.** v0.12.0 had the (distance, elevation) extraction as two private static methods on `Render_Elevation`. With both `Render_Elevation` and `Preview_Controller` now needing the same logic, the methods promote to a new class:

```php
namespace Kntnt\Gpx_Blocks\Rendering;

final class Elevation_Samples {

    public const DEFAULT_TARGET = 300;

    /**
     * Extracts the (distance, elevation) series from a GeoJSON
     * FeatureCollection. Walks the first LineString feature's coordinate
     * chain summing Haversine distance over every consecutive pair,
     * emitting a [distance, elevation] tuple whenever the coordinate
     * carries a third dimension. Distance continues to accumulate
     * across coords that lack elevation — defensive carry-over for the
     * rare hybrid case where Geo_Json_Converter's interpolation didn't
     * fill every gap. No WordPress functions; pure deterministic logic.
     * Both fields are rounded to one decimal (10 cm precision) before
     * emission.
     *
     * @param array<int|string, mixed> $geojson Decoded GeoJSON.
     * @return array<int, array{0: float, 1: float}>
     */
    public static function compute_full( array $geojson ): array;

    /**
     * Composition of compute_full() and Lttb::downsample(). Returns
     * the downsampled (distance, elevation) array ready for emission
     * into the per-mapId Interactivity state slice.
     *
     * @param array<int|string, mixed> $geojson Decoded GeoJSON.
     * @param int                      $target  LTTB target (≥ 2).
     * @return array<int, array{0: float, 1: float}>
     */
    public static function compute( array $geojson, int $target ): array;

}
```

`compute_full()` is the port of v0.12.0's `extract_line_coordinates()` + `build_distance_elevation_series()`, calling the existing `Conversion\Distance::haversine_meters()` helper. `compute()` is the trivial composition `Lttb::downsample( self::compute_full( $geojson ), $target )`.

**Filter at the call site.** `kntnt_gpx_blocks_elevation_target_points` is read at each call site rather than wrapped inside `Elevation_Samples`. The four-line boilerplate is duplicated:

```php
$target_raw = apply_filters( 'kntnt_gpx_blocks_elevation_target_points', Elevation_Samples::DEFAULT_TARGET );
$target     = is_int( $target_raw ) && $target_raw > 0 ? $target_raw : Elevation_Samples::DEFAULT_TARGET;
$samples    = Elevation_Samples::compute( $payload['geojson'], $target );
```

Rejected alternative: a `Elevation_Samples::filtered_target()` static method calling `apply_filters` internally — that couples the class to WordPress and forces Brain Monkey into its unit tests. The four-line duplication is the right side of the trade-off.

**Payload precision.** `compute_full()` rounds both fields to **one decimal** via `round( $value, 1 )` before emission. Rationale: chart display precision is at finest 1 m (Y tick labels) or 100 m (X km-mode tick labels); the cursor tooltip never exceeds those precisions; sub-decimetre source-data precision survives through LTTB but adds no UI-visible detail. The rounding reduces the inline payload by ~60% (300 samples × two scalars: ~10 KB raw JSON → ~4 KB raw JSON; ~3 KB → ~1 KB gzipped). The 10 cm precision is ~100× finer than any display value, leaving margin for future zoom-in renderings without needing to re-emit.

**Edge cases.** `compute_full()` returns `[]` when (a) no `LineString` feature is present in the FeatureCollection, (b) the LineString has fewer than two coordinates, or (c) all coordinates are 2D (no third element). The 2D case occurs for tracks where `Geo_Json_Converter` dropped elevation outright (>50% missing per its `MAX_MISSING_ELEVATION_FRACTION` rule) and is already caught upstream by `Render_Elevation::render()`'s `no-elevation-data` warning — `compute_full()`'s `[]` return is defense-in-depth. `compute()` propagates an empty result from `compute_full()`. The JS-side defensive guards in `chart.tsx` / `view.ts` (Q4) treat `samples.length < 2` as "render axes only, skip the curve" rather than crashing.

### Per-mapId state slice and REST shape (locked by Q1 grilling)

`Render_Elevation::render()` extends the existing `wp_interactivity_state` block to carry the new `samples` field:

```php
wp_interactivity_state( 'kntnt-gpx-blocks', [
    $resolved['map_id'] => [
        'statistics' => [
            'min_elevation' => $min,
            'max_elevation' => $max,
            'distance'      => $distance,
        ],
        'samples' => $samples,  // NEW: Array<[float, float]>, rounded to 1 decimal.
    ],
] );
```

`Preview_Controller::get_preview()` returns the same field in the REST response:

```php
return new \WP_REST_Response( [
    'geojson'    => $payload['geojson'],
    'statistics' => $payload['statistics'],
    'samples'    => $samples,  // NEW: same Elevation_Samples helper.
] );
```

The shared helper guarantees editor and frontend receive byte-identical samples arrays for the same attachment, preserving Step 3's "one shape for both hosts" discipline through Step 5. **`yMin` / `yMax` are not added to the state slice** — Step 4's redraw loop computes `niceYMin` / `niceYMax` per redraw via `niceTicks`, and both the tick set and the curve project against the same JS-computed values. v0.12.0's `yMin` / `yMax` state fields were needed because PHP rendered the polyline server-side; they are obsolete now.

### Plot fill colour attribute (locked by Q2b grilling)

A new block attribute `plotFillColor` is added in `block.json`:

```json
"plotFillColor": {
    "type": "string",
    "default": ""
}
```

The inspector's Color panel grows from 8 rows to 9. The new row inserts at position 3 (after `Plot line`, before `Cursor`), labelled `Plot fill` (translatable, text domain `kntnt-gpx-blocks`). The existing `enableAlpha` on the panel lets the user pick a colour with custom alpha directly.

Wiring mirrors the `axisLabelColor` template established in Step 4:

- **`src/blocks/elevation/inspector-color.tsx`** — `elevationColorRows()` gains a new entry between `plotLineColor` and `cursorColor`. Docstring "eight rows" → "nine rows".
- **`src/blocks/elevation/edit.tsx`** — adds a `usefulValue` wiring parallel to `axisLabelColor` (lines 281–294), reading `attributes.plotFillColor` and assigning to the inline CSS custom property `--kntnt-gpx-blocks-elevation-plot-fill` when non-empty.
- **`classes/Rendering/Render_Elevation::build_inline_style()`** — adds the mirror PHP-side emission, parallel to the `axisLabelColor` block at lines 314–319.
- **`src/blocks/elevation/style.scss`** — declares the SCSS default `--kntnt-gpx-blocks-elevation-plot-fill: transparent;`. A second default lands too: `--kntnt-gpx-blocks-elevation-plot-line: #1e1e1e;` (same neutral default as axis / axis-label).
- **`src/blocks/elevation/block.json`** — declares the attribute.

`tests/Unit/Elevation_Block_Json_ShapeTest.php` updates: the expected attribute count rises from 35 to 36, and `plotFillColor` is added to both the master attribute list and the "every colour attribute defaults to empty string" list.

### Chart scale helper (locked by Q4-iii-b grilling)

`src/blocks/elevation/geometry/scale.ts` — new module:

```ts
export interface ProjectedTick {
    readonly position: number;
    readonly label: string;
}

export interface ChartScale {
    readonly distance: number;
    readonly niceYMin: number;
    readonly niceYMax: number;
    readonly plotLeft: number;
    readonly plotRight: number;
    readonly plotTop: number;
    readonly plotBottom: number;
    readonly availX: number;
    readonly availY: number;
    readonly em: number;
    readonly projectX: ( distance: number ) => number;
    readonly projectY: ( elevation: number ) => number;
    readonly xTicks: readonly ProjectedTick[];
    readonly yTicks: readonly ProjectedTick[];
}

export interface ChartScaleInput {
    readonly distance: number;
    readonly minElevation: number;
    readonly maxElevation: number;
    readonly margins: Margins;
    readonly width: number;
    readonly height: number;
}

export function computeChartScale( input: ChartScaleInput ): ChartScale;
```

`computeChartScale` performs Step 4's full redraw geometry inside one function: derives `availX` / `availY` from `width`, `height`, and the cached `margins`; derives `refXWidth` / `refHeight` from the margin scalars (`refXWidth = 2 · ( margins.wRight − 0.5em )`, `refHeight = margins.h − 0.5em`); computes `N_x` and `N_y` via `computeTickCount`; generates X-tick values via `niceTicks( 0, distance, N_x ).values.filter( v => v <= distance )` (Strava-style filter) and Y-tick values via `niceTicks( yMin, yMax, N_y ).values`; formats labels via `formatXLabels` and `formatYLabels`; projects tick positions; builds `projectX` / `projectY` arrow functions. The Step 3 Case-B inflation (when `minElevation === maxElevation`, render against `[ min − 1, min + 1 ]`) lives inside `computeChartScale`, so callers don't recompose it.

When `availX <= 0` or `availY <= 0` (SVG not yet laid out), `computeChartScale` returns a sentinel `ChartScale` with `xTicks: []`, `yTicks: []`, and projection functions that return `NaN`. Callers handle the empty-tick case by skipping all drawing — they already test `w === 0 || h === 0` before invoking the redraw.

**Step 4 refactor inside Step 5's scope.** With `computeChartScale` owning the projection math, `chart.tsx` and `view.ts` lose their local `buildXTicks` and `buildYTicks` functions. The two redraw loops shrink to roughly:

```ts
const scale = computeChartScale( {
    distance: data.distance,
    minElevation: data.minElevation,
    maxElevation: data.maxElevation,
    margins,
    width: w,
    height: h,
} );

// Step 4 surfaces consume scale.xTicks, scale.yTicks.
// Step 5 surfaces consume scale.projectX, scale.projectY, scale.plotBottom.
```

The `ProjectedTick` interface moves out of both hosts and into `scale.ts`. Step 4's *rendered output* is byte-identical pre- and post-refactor — the change is purely how the same data flows.

`src/blocks/elevation/geometry/scale.test.ts` covers the new helper: known inputs produce known projection outputs; `projectX( 0 ) === plotLeft`, `projectX( distance ) === plotRight`, `projectY( niceYMin ) === plotBottom`, `projectY( niceYMax ) === plotTop`; Case-B inflation engages exactly when `minElevation === maxElevation`; X ticks are filtered to `value <= distance`; the sentinel branch returns empty tick sets and `NaN` projections when `availX <= 0`.

### Path construction (locked by Q2 + Q4 grilling)

`src/blocks/elevation/geometry/curve.ts` — new module:

```ts
/**
 * Builds the SVG `d` attribute for the open stroke path of the
 * elevation curve. Format:
 *     M x0 y0 L x1 y1 L … L xn yn
 * with one decimal of precision on every coordinate. Returns the
 * empty string when samples has fewer than 2 entries.
 */
export function buildStrokePathD(
    samples: readonly ( readonly [ number, number ] )[],
    projectX: ( d: number ) => number,
    projectY: ( e: number ) => number,
): string;

/**
 * Builds the SVG `d` attribute for the closed area path under the
 * curve. Format:
 *     M x0 plotBottom L x0 y0 L x1 y1 … L xn yn L xn plotBottom Z
 * with one decimal of precision on every coordinate. Returns the
 * empty string when samples has fewer than 2 entries.
 */
export function buildFillPathD(
    samples: readonly ( readonly [ number, number ] )[],
    projectX: ( d: number ) => number,
    projectY: ( e: number ) => number,
    plotBottom: number,
): string;
```

Both functions iterate the samples array exactly once, calling `.toFixed( 1 )` on every emitted coordinate. They are pure — no DOM, no math beyond invoking the projection callbacks. Co-located `curve.test.ts` asserts string equality against fixtures for a 5-sample input under known projection functions, the `< 2` early return, and that `buildFillPathD`'s closing `Z` makes the path closed.

### SVG mechanics (locked by Q2b + Q4 grilling)

Two `<path>` elements emitted from both `chart.tsx` (React JSX) and `view.ts` (`document.createElementNS` + `appendChild`):

```html
<path class="kntnt-gpx-blocks-elevation-plot-fill"
      d="M 84.0 312.4 L 84.0 287.6 L 87.5 281.2 … L 916.0 312.4 Z"
      fill="var(--kntnt-gpx-blocks-elevation-plot-fill)"
      stroke="none"/>

<path class="kntnt-gpx-blocks-elevation-plot-line"
      d="M 84.0 287.6 L 87.5 281.2 L 91.0 274.8 … L 916.0 268.4"
      fill="none"
      stroke="var(--kntnt-gpx-blocks-elevation-plot-line)"
      stroke-width="2"
      stroke-linejoin="round"
      stroke-linecap="round"
      vector-effect="non-scaling-stroke"/>
```

- **`stroke-width="2"`** (px under the 1:1 viewBox mapping). Hardcoded — Step 1 declared no `plotLineWidth` attribute and Step 5 does not add one.
- **`vector-effect="non-scaling-stroke"`** keeps the 2-px stroke pixel-stable across resize. Without it the stroke would scale with viewBox refresh, going faintly thicker on widening and thinner on narrowing.
- **`stroke-linejoin="round"`** prevents miter spikes at sharp peaks.
- **`stroke-linecap="round"`** softens the curve's endpoints.
- **Fill `<path>` is always emitted.** Its visibility is governed by the resolved CSS variable: `transparent` (SCSS default, no inline override) makes it invisible; a user-picked colour from the Color panel sets the inline `--kntnt-gpx-blocks-elevation-plot-fill` custom property and makes the fill visible. No conditional emission logic in JS — the DOM always has both paths.

**Layer order in the SVG** (insertion order under the SVG host):

1. X axis line (Step 3).
2. Y axis line (Step 3).
3. **Plot fill** (Step 5, new).
4. **Plot line** (Step 5, new).
5. X tick marks (Step 4).
6. Y tick marks (Step 4).
7. X tick labels (Step 4).
8. Y tick labels (Step 4).

The curve sits *above* the axis lines (the axis lines must not visibly cross over a peak) and *below* the tick marks and labels (tick scaffolding must remain legible where the curve passes through it).

**`view.ts`'s `removeMatching` selector list** grows to include the two new path classes so a re-mount or redraw cleanly tears down old DOM:

```ts
removeMatching( svg, [
    '.kntnt-gpx-blocks-elevation-axis-x',
    '.kntnt-gpx-blocks-elevation-axis-y',
    '.kntnt-gpx-blocks-elevation-plot-fill',          // NEW
    '.kntnt-gpx-blocks-elevation-plot-line',          // NEW
    '.kntnt-gpx-blocks-elevation-ticks-x',
    '.kntnt-gpx-blocks-elevation-ticks-y',
    '.kntnt-gpx-blocks-elevation-tick-labels-x',
    '.kntnt-gpx-blocks-elevation-tick-labels-y',
].join( ',' ) );
```

### Resize behaviour (locked by Q4 grilling)

Step 3's invariants extend to Step 5 without change:

- **Margins remain cached across resize.** `wLeft`, `wRight`, `wTop`, `h`, and `em` are functions of data and typography alone (not of `W_avail` / `H_avail`), so resize never invalidates them.
- **`samples` is stable across resize.** Computed once server-side, never recomputed in JS, never re-projected at the data level — only the *rendering* of the curve re-runs.
- **Curve redraws on every container resize.** `ResizeObserver` fires the redraw, which calls `computeChartScale` (fresh `availX` / `availY` / nice-Y bounds / tick sets), then `buildStrokePathD` and `buildFillPathD` with the new projection functions. Tick positions and curve geometry move together — the nice-Y bounds determine both.

Visual consequence during a drag-resize: the *number of ticks* still jumps between discrete values when the formula's threshold is passed (Step 4 behaviour), the curve re-projects to fit the new plot rectangle, and the stroke width stays pixel-stable thanks to `vector-effect="non-scaling-stroke"`. Path string regeneration at 300 samples is sub-millisecond; no frame-rate concerns on typical hardware.

### Redraw sequence (one shape for both hosts)

Both `chart.tsx` and `view.ts` run the same logical sequence in their redraw paths. Step numbers cross-reference helpers under `geometry/`:

1. Read `W`, `H` from `svg.getBoundingClientRect()`. Skip if either is zero (not yet laid out).
2. Call `computeChartScale( { distance, minElevation, maxElevation, margins, width: W, height: H } )` to get a `ChartScale` covering plot rectangle, projections, and tick sets.
3. If `scale.xTicks.length === 0` (sentinel branch — `availX` or `availY` non-positive), skip drawing entirely.
4. Build the curve `d` strings:
   - `strokeD = buildStrokePathD( samples, scale.projectX, scale.projectY )`.
   - `fillD = buildFillPathD( samples, scale.projectX, scale.projectY, scale.plotBottom )`.
   - When `samples.length < 2`, both helpers return `''` — the redraw still draws axes and ticks but emits no `d` attribute on the path elements (`chart.tsx` conditionally skips JSX, `view.ts` skips `appendChild`).
5. Wipe any previous run's elements via `removeMatching` (in `view.ts`); React's reconciliation handles this for `chart.tsx`.
6. Append the axis lines, then the fill and stroke paths, then the tick mark groups, then the tick label groups — in the layer order documented above.

Steps 1–4 are pure (no DOM mutation). Steps 5–6 are host-specific.

### File layout for Step 5

```
src/blocks/elevation/
├── block.json                                   — modified: new plotFillColor attribute
├── edit.tsx                                     — modified: plotFillColor wiring + Step 4 redraw refactor for ChartScale
├── chart.tsx                                    — modified: removes buildXTicks/buildYTicks, consumes ChartScale, emits two <path> elements
├── chart.test.tsx                               — modified: assertions for plot-fill / plot-line groups; ChartScale consumption
├── view.ts                                      — modified: removes buildXTicks/buildYTicks, consumes ChartScale, emits two <path> elements, extends removeMatching
├── inspector-color.tsx                          — modified: 9th row for plotFillColor; docstring update
├── style.scss                                   — modified: --kntnt-gpx-blocks-elevation-plot-fill and --kntnt-gpx-blocks-elevation-plot-line defaults
└── geometry/
    ├── scale.ts                                 — NEW: computeChartScale + ChartScale + ProjectedTick + ChartScaleInput
    ├── scale.test.ts                            — NEW: pure helper tests
    ├── curve.ts                                 — NEW: buildStrokePathD + buildFillPathD
    └── curve.test.ts                            — NEW: pure helper tests

classes/Rendering/
├── Lttb.php                                     — NEW: resurrected verbatim from v0.12.0
├── Elevation_Samples.php                        — NEW: compute_full + compute (pure, no WordPress)
└── Render_Elevation.php                         — modified: filter read + samples emission in render(); plotFillColor in build_inline_style()

classes/Rest/
└── Preview_Controller.php                       — modified: response shape gains samples field

tests/Unit/
├── LttbTest.php                                 — NEW: resurrected verbatim from v0.12.0
├── Elevation_SamplesTest.php                    — NEW: pure algorithmic tests
├── Render_ElevationTest.php                     — modified: assertions for samples emission and plotFillColor wiring
├── Preview_ControllerTest.php                   — modified: assertion for samples in response shape
└── Elevation_Block_Json_ShapeTest.php           — modified: attribute count 35 → 36, plotFillColor added

src/blocks/elevation/
└── use-bound-map-payload.ts                     — modified: BoundMapPayload interface gains samples field
```

The seam between pure geometry and DOM-bound work is preserved: `scale.ts`, `curve.ts`, `format.ts`, `ticks.ts`, `margins.ts` add no DOM dependencies; rendering logic stays in `chart.tsx` and `view.ts`. The PHP-pure boundary holds too: `Elevation_Samples` and `Lttb` call no WordPress functions and have no Brain Monkey dependency in their tests.

### Test-driven development

Write the helper-level tests first, watch them go red, implement until green:

- **`tests/Unit/Elevation_SamplesTest.php`** — pure algorithmic tests:
  - 3-point 3D LineString → exactly 3 samples; cumulative distance matches manual Haversine; elevations match third-element values.
  - 2D LineString → `[]`.
  - LineString missing from FeatureCollection → `[]`.
  - Single-point LineString → `[]` (length < 2 guard).
  - 1000-point 3D LineString with `target = 50` → exactly 50 samples, endpoints preserved, deterministic.
  - 50-point 3D LineString with `target = 300` → pass-through (50 samples returned unchanged).
  - All emitted values rounded to 1 decimal.
- **`tests/Unit/LttbTest.php`** — resurrected from v0.12.0, passes without modification.
- **`src/blocks/elevation/geometry/scale.test.ts`** — pure helper tests:
  - Known input produces known projection: `projectX( 0 ) === plotLeft`, `projectX( distance ) === plotRight`, `projectY( niceYMin ) === plotBottom`, `projectY( niceYMax ) === plotTop`.
  - Case-B inflation engages on `minElevation === maxElevation`; nice-Y bounds become `[ min − 1, min + 1 ]`-derived.
  - X ticks filtered to `value <= distance`.
  - Sentinel branch returns empty tick sets when `availX <= 0` or `availY <= 0`.
- **`src/blocks/elevation/geometry/curve.test.ts`** — pure helper tests:
  - 5-sample fixture with identity projections → assert exact `d` string.
  - `< 2` samples → both helpers return `''`.
  - `buildFillPathD` ends with ` Z`; `buildStrokePathD` does not.
  - All coordinates carry exactly one decimal in the output.

Then write the integration tests:

- **`src/blocks/elevation/chart.test.tsx`** — `<Chart>` renders the expected `<path>` elements (one with class `kntnt-gpx-blocks-elevation-plot-line`, one with `kntnt-gpx-blocks-elevation-plot-fill`) for a known data shape and mocked typography. The plot-line's `stroke` resolves to `var(--kntnt-gpx-blocks-elevation-plot-line)`. The plot-fill is in the DOM regardless of `plotFillColor` value. ChartScale-driven `xTicks` / `yTicks` still render as Step 4 specified.
- **`tests/Unit/Render_ElevationTest.php`** — `render()` emits the `samples` key in `wp_interactivity_state` for the healthy state; `plotFillColor` wiring produces the `--kntnt-gpx-blocks-elevation-plot-fill` inline custom property (mirror of the existing `axisLabelColor` assertion).
- **`tests/Unit/Preview_ControllerTest.php`** — `get_preview()` returns the `samples` field in the REST response.
- **`tests/Unit/Elevation_Block_Json_ShapeTest.php`** — attribute count 36, `plotFillColor` present and defaulting to `''`.

Implementation follows the tests until all are green.

### Acceptance criteria

Step 5 is done — and `v0.13.5` may be tagged — when **all** of the following hold.

**Behaviour:**

1. The Step 3/4 healthy-state chart now carries an elevation curve on both axes' scaffold. The five degenerate-case warnings from Step 3 (`no-map`, `bound-deleted`, `bound-unconfigured`, `no-elevation-data`, `zero-distance`) are unaffected.
2. `classes/Rendering/Lttb.php` and `tests/Unit/LttbTest.php` are present, byte-identical to `v0.12.0`. `Lttb::downsample( $points, $target )` preserves endpoints, returns input unchanged when `count <= target`, and is deterministic.
3. `classes/Rendering/Elevation_Samples.php` exists with `DEFAULT_TARGET = 300`, `compute_full( array $geojson ): array`, and `compute( array $geojson, int $target ): array`. The class calls no WordPress functions. Both fields in emitted tuples are rounded to one decimal.
4. The filter `kntnt_gpx_blocks_elevation_target_points` is read at both `Render_Elevation::render()` and `Preview_Controller::get_preview()`, with `is_int && > 0` coercion falling back to `Elevation_Samples::DEFAULT_TARGET`.
5. **Per-mapId state slice** gains a `samples` field: `state[ mapId ].samples` is an `Array<[number, number]>` with the LTTB-downsampled (distance, elevation) tuples. The `statistics` sub-object is unchanged. **No** `yMin` / `yMax` field is added.
6. **REST response** from `/kntnt-gpx-blocks/v1/preview/<id>` gains a `samples` field with the same shape and same value (computed by the shared `Elevation_Samples` helper).
7. `block.json` declares the new `plotFillColor` attribute (type `string`, default `""`). Total attribute count rises from 35 to 36.
8. The Color panel renders 9 rows; the new row labelled `Plot fill` sits between `Plot line` and `Cursor`.
9. The custom property `--kntnt-gpx-blocks-elevation-plot-fill` is wired through `edit.tsx`, `Render_Elevation::build_inline_style()`, and `style.scss` (default `transparent`). The custom property `--kntnt-gpx-blocks-elevation-plot-line` has its SCSS default `#1e1e1e`.
10. **`computeChartScale` exists** in `geometry/scale.ts` with the documented interface. `chart.tsx` and `view.ts` no longer carry local `buildXTicks` / `buildYTicks` functions — both consume `scale.xTicks` and `scale.yTicks` instead. The `ProjectedTick` interface lives in `scale.ts` only.
11. **`buildStrokePathD` and `buildFillPathD` exist** in `geometry/curve.ts`. Both produce paths with `toFixed( 1 )` precision; both return `''` when `samples.length < 2`. `buildFillPathD`'s output ends with ` Z`.
12. **Curve renders as two `<path>` elements** in the SVG: `kntnt-gpx-blocks-elevation-plot-fill` followed by `kntnt-gpx-blocks-elevation-plot-line`. The fill path is always emitted regardless of `plotFillColor` value. The stroke path carries `stroke-width="2"`, `stroke-linejoin="round"`, `stroke-linecap="round"`, `vector-effect="non-scaling-stroke"`, `fill="none"`.
13. **SVG layer order** matches the documented sequence: axis lines → plot fill → plot line → tick marks → tick labels.
14. **`view.ts`'s `removeMatching` selector list** includes both new path classes.
15. **Resize:** `ResizeObserver` triggers a redraw that re-projects samples and rebuilds the curve, alongside the existing tick recompute. Margins do not recompute. The 2-px stroke stays pixel-stable across viewport widths.

**Gates (must all pass at HEAD before tagging):**

16. `npm run build`.
17. `composer test` (Pest) — including the new `Elevation_SamplesTest`, the resurrected `LttbTest`, the extended `Render_ElevationTest`, the extended `Preview_ControllerTest`, and the extended `Elevation_Block_Json_ShapeTest`.
18. `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M`.
19. `npm run test:js` — including the new `scale.test.ts`, `curve.test.ts`, the extended `chart.test.tsx`, and the existing test corpus.
20. `npx wp-scripts lint-js src/blocks/`.

**Manual verification in WordPress Playground (`@wp-playground/cli`):**

21. Insert one configured Map with a multi-kilometre GPX, then insert Elevation: the chart now carries an elevation curve drawn over Step 4's axes and ticks. The curve fills the plot rectangle horizontally (starts at `plotLeft`, ends at `plotRight`) and sits within the nice-Y bounds vertically.
22. The Color panel shows nine rows including `Plot fill` in position 3.
23. **Plot line colour** — pick a vivid colour for `Plot line` in the inspector: the curve's stroke updates live in editor and on frontend after save.
24. **Plot fill colour** — pick `rgba( 34, 197, 94, 0.25 )` for `Plot fill`: a translucent green fill appears under the curve in editor and on frontend. Clear the value: the fill disappears (resolves to `transparent`).
25. **Resize the browser window** during inspection: the curve re-projects to the new plot rectangle without lag, the stroke width remains visually 2 px, the tick count adjusts at thresholds, and nothing jitters between jumps.
26. **Two Elevation blocks bound to *different* Map blocks** on the same page: each block's curve uses its own LTTB-downsampled samples; per-mapId state slices stay decoupled.
27. **Webfont verification** — theme that lazy-loads a Google Font for `Tick labels` typography: when `loadingdone` fires, margins re-measure and the curve re-projects against the corrected nice-Y bounds; no visible jump at the curve's endpoints.
28. **Case-B verification** — flat-elevation GPX (`min_elevation === max_elevation`): the curve renders as a horizontal line through the middle of the plot rectangle; the Y axis carries ticks symmetric around the constant value (Step 3's `[ min − 1, min + 1 ]` substitution).
29. **LTTB filter** — set `add_filter( 'kntnt_gpx_blocks_elevation_target_points', fn () => 50 )` in a mu-plugin: the curve still renders correctly but with visibly coarser segmentation; the cursor in Step 6 would still bracket correctly (Step 6 is not yet implemented).

### Release

When all acceptance criteria hold, follow the six-step release procedure documented in `AGENTS.md` (section *Cutting a release*). Tag `v0.13.5`. Commit message: `Release v0.13.5 — Step 5: elevation curve`.

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
