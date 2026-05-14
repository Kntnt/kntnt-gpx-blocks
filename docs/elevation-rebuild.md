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

## Step 4 (released as v0.13.4, refined in v0.13.4-pl.1, v0.13.5-pl.1, v0.13.5-pl.3 and v0.13.5-pl.7) — recap

Both axes now carry tick marks and locale-formatted numeric labels driven by the GPX data range. The X axis is Strava-style data-bound — range `[0, distance]` with nice ticks filtered to `value ≤ distance` — and switches between metres and kilometres on a deterministic `distance < 2000` threshold; the origin tick is always rendered. The Y axis is nice-bound — range `[floor(yMin/step) · step, ceil(yMax/step) · step]` — with the lowest tick anchored to the X axis line and the highest at `y = wTop`. `chooseXUnit` now takes a single `distance` number (the values-list signature is gone); a new `xReferenceString(distance, locale)` returns a typographically worst-case label keyed off `distance` alone, breaking the chicken-and-egg between N and label widths so `wRight` is computed once and remains stable across resize. `computeTickCount` uses the additive formula `floor((avail + padding) / (refSize + padding))` clamped to ≥ 2, where `padding = 1em` after the v0.13.4-pl.1 follow-up widened the luft term from `0.5em` (replacing Step 3's proportional `× 1.5` factor). `Margins` carries a new field `wTop = 0.5 · ref.height + 0.5em` that reserves the upper half of the topmost Y label against the SVG viewBox top; `wRight = measure(xReferenceString(distance)).width / 2 + 0.5em`; `wLeft` and `h` are unchanged from Step 3. Tick marks render as one `<line>` per tick (length `0.2em`; X marks downward from `( xTick, H − h )`, Y marks leftward from `( wLeft, yTick )`), grouped under `.kntnt-gpx-blocks-elevation-ticks-{x,y}`. Tick labels render as `<text>` grouped under `.kntnt-gpx-blocks-elevation-tick-labels-{x,y}`: X uses `text-anchor="middle"` + `dominant-baseline="hanging"` at `y = ( H − h ) + 0.5em`; Y uses `text-anchor="end"` + `dominant-baseline="central"` at `x = wLeft − 0.5em`. The `axisLabelColor` attribute (already declared in Step 1) is wired through three surfaces — `edit.tsx`, `Render_Elevation::render_chart_wrapper()`, and `style.scss` — to a new CSS variable `--kntnt-gpx-blocks-elevation-axis-label` (default `#1e1e1e`), letting users override axis-line and axis-label colours independently. ResizeObserver recomputes `N_x`, `N_y`, the tick sets, and label positions on resize but never invalidates margins; axis line start positions stay pixel-stable.

For orientation, read the relevant source files directly: `src/blocks/elevation/geometry/format.ts` (`chooseXUnit(distance)` + new `xReferenceString(distance, locale)` with the typographic `"8"`-digit worst-case construction; the m-mode `+2` and km-mode `+1` digit buffers documented inline), `src/blocks/elevation/geometry/format.test.ts` (the m-mode / km-mode verification tables plus the sv-SE / en-US locale-separator pair), `src/blocks/elevation/geometry/ticks.ts` (additive `computeTickCount(avail, refSize, em)` clamped ≥ 2; the `padding = em` literal that the v0.13.4-pl.1 follow-up bumped from `0.5 * em`), `src/blocks/elevation/geometry/margins.ts` (`wRight` derived from `xReferenceString`, new `wTop` field, `wLeft` and `h` carried over from Step 3 unchanged), `src/blocks/elevation/chart.tsx` (React preview — `buildXTicks` / `buildYTicks` project values to SVG coordinates, tick-mark and tick-label groups rendered as JSX, Y axis line starts at `y = margins.wTop`), `src/blocks/elevation/view.ts` (frontend vanilla-DOM mirror under the Interactivity API; same redraw sequence as `chart.tsx`, group replacement via `document.createElementNS`), `src/blocks/elevation/edit.tsx` (`axisLabelColor` custom-property wiring alongside the existing `axisColor` wiring), `src/blocks/elevation/style.scss` (default value for `--kntnt-gpx-blocks-elevation-axis-label`), `classes/Rendering/Render_Elevation.php` (server-side inline emission of the axis-label custom property), `tests/Unit/Rendering/Render_ElevationTest.php` (extended with an `axisLabelColor` wiring assertion).

Full Step 4 specification (design rationale, the Q-by-Q grilling outcomes, acceptance criteria, manual verification list): `git show 0fcd855:docs/elevation-rebuild.md`. The follow-up release `v0.13.4-pl.1` (commit `1c5a94a`) widened the additive luft term in `computeTickCount` from `0.5em` to `1em` on both axes. Y labels were crowding together visibly at the previous constant; the wider gap also reduces how often `N_y` flips during an editor min-height drag, mitigating the secondary label-jitter symptom (a full debouncing fix is deferred). Side effect: slightly fewer ticks on the X axis too, which keeps horizontal and vertical breathing room consistent. The change is mechanical (the `padding` local in `computeTickCount` becomes `em` instead of `0.5 * em`) and scoped to `src/blocks/elevation/geometry/ticks.ts` and its test file.

The second follow-up release `v0.13.5-pl.1` wires the eight Tick-labels typography attributes (`tickLabel{FontFamily,FontSize,FontWeight,FontStyle,LineHeight,LetterSpacing,TextTransform,TextDecoration}`) to the rendered tick `<text>` elements. v0.13.4 already routed the typography into the margin algorithm (the chart's reserved space adapted to bold/large labels), but the visible labels themselves never picked up the user's choices — the editor's `<Chart>` applied the typography only to the measurer's hidden `<text>` nodes via inline style, and the frontend `view.ts` passed `typography: {}` to the measurer outright, so labels rendered with the wrapper's default typography regardless of the inspector picks. The fix follows GPX Map's tooltip-typography pattern verbatim: typography travels as eight `--kntnt-gpx-blocks-elevation-tick-label-*` custom properties on the block wrapper (emitted server-side by `Render_Elevation::build_inline_style()` and editor-side by `ElevationEdit`'s `inlineStyle` builder), consumed by a SCSS rule on `.kntnt-gpx-blocks-elevation-chart-svg` that turns each into a `font-*` / `letter-spacing` / `text-*` declaration with `inherit` as the fallback. Bred scope on the SVG host (not on the `<g class="…-tick-labels-x/y">` groups) is a correctness requirement, not a stylistic choice: the measurer's hidden `<text>` nodes are direct SVG children, not nested in the label groups, so a narrower scope would re-introduce the measurer/rendering divergence the rebuild eliminates. With CSS inheritance now the single source of truth, the measurer no longer accepts a `TypographyAttributes` argument — `createTextMeasurer( svg )` returns `(text: string) => TextMeasurement` and `computeMargins( data, measure )` loses its typography parameter. The eight sanitisation methods that Step 1 introduced as private statics in `Render_Map` extract to a new `Rendering\Typography_Sanitizer` class (sibling of `Color_Sanitizer`); Render_Map's call sites are repointed and the private methods are deleted. `chart.tsx` keeps the `typography` prop solely as a `useLayoutEffect` dep-list trigger — a typography change in the inspector still triggers a re-measurement against the now-updated CSS, and a Strategy-B pinning test in `chart.test.tsx` (`'remeasures when the typography prop changes'`) protects this against a future "cleaner" who would otherwise see the prop as unused. Scoped to `classes/Rendering/{Typography_Sanitizer,Render_Map,Render_Elevation}.php`, `src/blocks/elevation/{style.scss,edit.tsx,chart.tsx,view.ts,geometry/measure.ts,geometry/margins.ts}`, and the corresponding tests under `tests/Unit/Rendering/` and `src/blocks/elevation/`.

The third follow-up release `v0.13.5-pl.3` patches the editor side of the v0.13.5-pl.1 wiring: typography reached the rendered tick labels on the frontend but not in the Gutenberg editor, where the SCSS rule on `.kntnt-gpx-blocks-elevation-chart-svg` — keyed off the wrapper's `--…tick-label-*` custom properties — failed to win against whatever editor-iframe CSS targets SVG text under a more specific selector. (The colour custom properties on the same wrapper carried through unchanged because they are consumed via `var()` directly in element attributes rather than via inherited declarations.) Rather than chase the editor-only specificity battle, `chart.tsx` now applies the eight `font-*` / `letter-spacing` / `text-*` declarations as inline `style` on the host `<svg>` element via a new `typographyToSvgStyle( typography )` helper. Inline styles win specificity against any class- or tag-based rule, so the user's choices reach the rendered labels regardless of the surrounding stylesheet stack. The descendants (visible tick `<text>` under the `<g>` groups, hidden measurement `<text>` as direct SVG children) inherit from the same `<svg>` host, preserving the measurer/rendering parity v0.13.5-pl.1 established. The frontend keeps the SCSS-rule + wrapper-custom-property path unchanged — it works there and the symmetry makes Step 7's tooltip-typography wiring easier to model. The `Chart.props.typography` JSDoc updates to reflect that the prop is now consumed (not merely a dep-list signal), and the `chart.test.tsx` pin renames from `'does not apply font-* inline to tick label <text> nodes'` to `'applies typography inline on the <svg> host'` with a companion test that pins the absence of font-* on the `<text>` descendants themselves. Scoped to `src/blocks/elevation/chart.tsx` and `src/blocks/elevation/chart.test.tsx`.

The fourth follow-up release `v0.13.5-pl.7` reverts the pl.3 inline-style hack and reinstates the pl.1 SCSS-only architecture, because pl.3's diagnosis turned out to be wrong. A diagnostic snippet run against the maintainer's actual live site (`elfsborgsmarschen.se`, Ollie theme, ~30 active plugins including `wp-typography` and `fluent-crm` whose `apiVersion: 1` blocks force the editor into non-iframe mode where theme stylesheets bleed into the editor DOM) and against a DDEV mirror with the same stack measured `getComputedStyle(svg)` *after* programmatically stripping the pl.3 inline style attribute — and in both environments the SCSS rule on `.kntnt-gpx-blocks-elevation .kntnt-gpx-blocks-elevation-chart-svg` produced exactly the user's chosen typography (`Mona Sans Condensed, 2rem, 800, italic` → computed `Mona Sans Condensed, sans-serif / 32px / 800 / italic` on live; `.95rem` → `15.2px` on DDEV; with the tick `<text>` descendants inheriting the same values). There is no theme rule that wins over the SCSS rule; pl.3's "editor-iframe CSS targets SVG text under a more specific selector" claim never had a measured selector behind it. The most likely cause of the original symptom the user observed with pl.1 — given how reliably the SCSS path measures correct now — was a stale browser/CDN cache of the pre-pl.1 `style-index.css` at the time pl.1 was first installed. pl.7 removes the `typographyToSvgStyle( typography )` helper from `chart.tsx` along with the `style={ … }` prop on the host `<svg>`, restores the pl.1 JSDoc on `ChartProps.typography` ("not consumed by render output or measurer — retained as a `useLayoutEffect` dep-list trigger"), drops the two pl.3 pinning tests, and adds a new editor-surface regression test in `chart.test.tsx` that reads the compiled `build/blocks/elevation/style-index.css`, injects it into the JSDOM document, renders `<Chart>` inside a wrapper carrying the eight `--…-tick-label-*` custom properties, and asserts that `getComputedStyle(svg)` resolves to the expected `font-family` / `font-size` / `font-weight` / `font-style` — the regression hole that allowed the pl.1 → pl.3 round-trip to happen unseen. Scoped to `src/blocks/elevation/chart.tsx` and `src/blocks/elevation/chart.test.tsx` plus this recap paragraph.

---

## Step 5 (released as v0.13.5) — recap

The Elevation block's chart now carries the elevation curve drawn as two SVG `<path>` elements — an always-emitted fill below the curve and the stroke on top — projected client-side onto Step 4's plot rectangle from a server-emitted, LTTB-downsampled (distance, elevation) `samples` array. The array is computed once in PHP and consumed byte-identically by the editor preview (`chart.tsx`) and the frontend Interactivity host (`view.ts`) through a new shared `ChartScale` helper that subsumes Step 4's inline projection math; `chart.tsx` and `view.ts` lose their local `buildXTicks` / `buildYTicks` functions in favour of `computeChartScale`'s consolidated tick + projection output, and the Step 3 Case-B `[ min − 1, min + 1 ]` inflation moves inside the helper. v0.12.0's `Lttb` is resurrected verbatim from the tag (algorithm contract intact: endpoints preserved, deterministic tie-breaks on lowest source index, pass-through when `count <= target`); a new `Rendering\Elevation_Samples` class composes (distance, elevation) extraction with `Lttb::downsample()` — `compute_full()` ports v0.12.0's two private statics and emits tuples rounded to one decimal (10 cm precision, ~60% smaller payload at 300 samples), `compute()` is the trivial composition. The orphaned `kntnt_gpx_blocks_elevation_target_points` filter is reactivated at both call sites (`Render_Elevation::render()` and `Rest\Preview_Controller::get_preview()`) with `is_int && > 0` coercion falling back to `Elevation_Samples::DEFAULT_TARGET = 300`. The per-mapId Interactivity state slice and the editor REST response each gain a `samples` field (`Array<[number, number]>`) alongside the existing `statistics` sub-object; no `yMin` / `yMax` field is emitted because the redraw still derives nice-Y bounds via `niceTicks` per Step 4. A new block attribute `plotFillColor` (type `string`, default `""`) is declared in `block.json` — attribute count rises from 35 to 36 — and the Color panel grows from 8 to 9 rows, with `Plot fill` inserted between `Plot line` and `Cursor`; the wiring mirrors Step 4's `axisLabelColor` template, threading `--kntnt-gpx-blocks-elevation-plot-fill` (SCSS default `transparent`) and `--kntnt-gpx-blocks-elevation-plot-line` (SCSS default `#1e1e1e`) through `edit.tsx`, `Render_Elevation::build_inline_style()`, and `style.scss`. The fill `<path>` is always in the DOM and renders invisibly when the user hasn't picked a colour; the stroke `<path>` carries hardcoded `stroke-width="2"`, `vector-effect="non-scaling-stroke"`, `stroke-linejoin="round"`, `stroke-linecap="round"`, and `fill="none"`. SVG insertion order is locked to axis lines → plot fill → plot line → tick marks → tick labels.

For orientation, read the relevant source files directly: `src/blocks/elevation/geometry/scale.ts` (new — `computeChartScale` plus the `ChartScale`, `ProjectedTick`, and `ChartScaleInput` interfaces; owns the projection pipeline, the X-tick filter to `value <= distance`, and the Case-B inflation; returns a sentinel with empty tick sets and `NaN` projectors when `availX <= 0` or `availY <= 0`), `src/blocks/elevation/geometry/scale.test.ts` (new — projection identities at the four plot-rectangle corners, Case-B engagement, sentinel branch), `src/blocks/elevation/geometry/curve.ts` (new — `buildStrokePathD` + `buildFillPathD`; one-decimal precision via `.toFixed( 1 )` on every coordinate; both return `''` when `samples.length < 2`; fill ends with ` Z`), `src/blocks/elevation/geometry/curve.test.ts` (new — `d`-string fixtures, `< 2` early return, closed-vs-open shape), `src/blocks/elevation/chart.tsx` (React preview consuming `ChartScale` and emitting both `<path>` elements as JSX), `src/blocks/elevation/view.ts` (frontend vanilla-DOM mirror; `removeMatching` selector list extends to `.kntnt-gpx-blocks-elevation-plot-fill` and `.kntnt-gpx-blocks-elevation-plot-line`), `src/blocks/elevation/inspector-color.tsx` (`elevationColorRows()` gains the 9th row between `plotLineColor` and `cursorColor`), `src/blocks/elevation/edit.tsx` (`plotFillColor` custom-property wiring alongside the Step 4 `axisLabelColor` wiring), `src/blocks/elevation/style.scss` (defaults for the two new custom properties), `src/blocks/elevation/use-bound-map-payload.ts` (the `BoundMapPayload` interface gains a `samples` field carrying the REST payload's new field), `src/blocks/elevation/block.json` (`plotFillColor` attribute declared), `classes/Rendering/Lttb.php` (resurrected verbatim from `v0.12.0`), `classes/Rendering/Elevation_Samples.php` (new — `DEFAULT_TARGET = 300`, `compute_full`, `compute`; no WordPress calls), `classes/Rendering/Render_Elevation.php` (filter read at the call site + `samples` emitted into the per-mapId state slice + `plotFillColor` mirror in `build_inline_style()`), `classes/Rest/Preview_Controller.php` (response shape extended to include `samples`), `tests/Unit/LttbTest.php` (resurrected verbatim from `v0.12.0`), `tests/Unit/Rendering/Elevation_SamplesTest.php` (new — pure algorithmic tests: 3-point 3D LineString round-trip, 2D / missing / single-point empty returns, 1000 → 50 LTTB endpoints + determinism, pass-through, one-decimal rounding), `tests/Unit/Rendering/Render_ElevationTest.php` (extended with `samples` emission and `plotFillColor` wiring assertions), `tests/Unit/Rest/Preview_ControllerTest.php` (extended with the new response field), `tests/Unit/Elevation_Block_Json_ShapeTest.php` (attribute count 36, `plotFillColor` registered).

Full Step 5 specification (design rationale, the Q-by-Q grilling outcomes, acceptance criteria, manual verification list): `git show b08480f:docs/elevation-rebuild.md`.

---

## Step 6: Cursor with cross-block sync

**Goal.** A cursor sits on the elevation curve. The user can move it by hovering or pressing-and-dragging anywhere inside the chart's plot rectangle on desktop, or by touching and dragging on touch devices. The cursor consists of a circle anchored to the curve at the pointer's distance plus two L-shaped guide lines pointing from the circle down to the corresponding x-axis tick and across to the corresponding y-axis tick. The cursor in GPX Map and the cursor in GPX Elevation stay synchronised: a scrub on either block writes a shared `state[ mapId ].fraction` value and both blocks' watch callbacks reposition their cursors against the same value. **No tooltip yet** — Step 7 adds it on top of the cursor anchor introduced here.

**Load list:** `docs/blocks.md`, `docs/architecture.md` (Interactivity store, cross-block sync).

**v0.12.0 references:** `src/blocks/elevation/cursor.ts`, `src/blocks/elevation/mount.ts`, `src/blocks/elevation/view.ts` — the namespaced Interactivity store keyed by `mapId`, the per-mount `WeakMap<Element, ElevationEntry>` registry, the read-fraction-before-guard pattern in the watch callback, the `pointerType === 'mouse'` branch that gives desktop a hover-without-press UX, and the `pointerleave`-on-wrapper dismissal asymmetry between mouse and touch all work well in v0.12.0 and port over verbatim. **What does *not* port over:** v0.12.0 server-rendered the cursor LINE and the HTML overlays (dot + tooltip) in PHP and moved them out of the SVG to escape issue #135's wrapper-as-image non-uniform stretch. The rebuild's `drawChart` mounts the SVG with a 1:1 viewBox-to-CSS-pixels mapping (Step 3), so the non-uniform stretch never exists; the rebuild puts every cursor element back inside the SVG and JS creates them client-side. The orphaned `data-preview="1"` server-rendered editor preview is also out — the editor uses the React `chart.tsx` host (Step 2), which renders its own static cursor at fraction = 0.5.

### Design rationale (locked by the Step 6 grilling)

**Three SVG elements: circle on curve + L-shape lines to axes.** Three visible shapes inside one `<g class="kntnt-gpx-blocks-elevation-cursor">` group: a `<circle>` anchored to the curve at the interpolated `(distance, elevation)` sample, a `<line>` running vertically from that circle down to `plotBottom` (pointing at the corresponding x-axis tick), and a `<line>` running horizontally from that circle leftward to `plotLeft` (pointing at the corresponding y-axis tick). Rejected alternatives:

- *Dot only* — loses the "which x-tick / which y-tick does this correspond to" signal when the curve is flat or close to one of the axes, exactly when users need it most.
- *Full-height vertical line, no dot* — what v0.12.0 actually shipped. Visually cleaner than a dot alone but Step 7's tooltip needs a y-anchor, so adding the dot in Step 7 would unspool any work spent on a v0.12.0-style line.
- *Full-length lines through the dot (crosshair)* — common in financial-chart libraries (TradingView, Plotly). Locked out: lines passing *through* the dot have to keep going past the curve in both directions, which visually cuts the curve in two and competes with the data. L-shape from the dot to each axis is the cleaner semantic: "this point on the curve maps to this x-tick and this y-tick".

The three elements sit inside the SVG, not in an HTML overlay. v0.12.0:s issue-#136 overlay model existed only to escape the wrapper-as-image non-uniform stretch; in the rebuild the SVG renders 1:1 with CSS pixels, so the overlay's *motivation* is gone. Putting the cursor inside the SVG means it shares the chart's coordinate system, can reuse `scale.projectX` / `scale.projectY` directly, and renders with the same redraw cycle as axes and ticks — no cross-coordinate-system synchronisation required.

**Invisible `<rect>` hit-target over the plot rectangle.** A single transparent `<rect>` sized to `(plotLeft, plotTop, plotRight − plotLeft, plotBottom − plotTop)` catches every pointer event. Pointer x-coordinate maps to fraction relative to the hit-rect's bounding rect:

```ts
fraction = clamp( ( clientX − hitRect.getBoundingClientRect().left ) / hitRect.width, 0, 1 )
```

The mapping is purely *rect-relative* — pointer position in the y-axis label margin maps to nothing (the event doesn't reach the rect at all), and the formula has no internal `plotLeft` arithmetic because the geometry is baked into the rect's CSS-pixel bounding-rect. Rejected alternatives:

- *Hit-target on the whole SVG* (v0.12.0's choice) — was correct there because v0.12.0's `PLOT_INSET = 0` meant the SVG bounding rect *was* the plot rectangle. The rebuild has real internal margins (`wLeft`, `wTop`, `wRight`, `h`), so reusing v0.12.0's formula would produce a visible "jump" effect on the first pixels: hovering in the y-axis label margin would set fraction = 0, projecting the dot to `plotLeft` while the pointer is still to the left of `plotLeft`.
- *Hit-target on the curve `<path>` with a fat invisible hit-layer* (Map's pattern) — Map needs this because its polyline is a thin track over a 2D landscape; restricting scrubbing to "near the track" is semantically meaningful. Elevation has no such 2D structure: y-position of the pointer doesn't carry information at all (the cursor anchors to the curve regardless of pointer y), so restricting hover vertically would just frustrate users.

`touch-action: none` on the hit-rect class (CSS, not `touchmove preventDefault`) prevents the browser from scrolling the page during a touch-drag on the plot. The page *can* still scroll when the user touches the chart's label margins (outside the hit-rect) — bonus UX, free.

**Dismissal asymmetry: hit-rect for scrub, wrapper for `pointerleave`.** Pointer-down / move / up / cancel bind on the hit-rect. `pointerleave` binds on the *block wrapper* (not the hit-rect). Without this asymmetry, the cursor would flicker off every time the user's mouse crossed from the plot into a label margin and back. Map does the same: scrub handlers on the polyline `<path>`, `pointerleave` on the block wrapper.

**Pointer protocol verbatim from v0.12.0.** Desktop mouse: hover-without-press updates fraction (the `pointerType === 'mouse'` branch in `pointermove`). Press-and-drag also updates, with `setPointerCapture` so the gesture persists when the pointer drifts off the hit-rect. Pointer-up releases capture *without* nulling fraction — the cursor stays at its last position so the user can read the value (essential when Step 7's tooltip lands on top). Touch: only press-and-drag updates fraction (touch has no hover); finger-lift fires `pointerleave` automatically but is explicitly *skipped* (`pointerType === 'touch'`) so the cursor persists for the user to read. Mouse `pointerleave` on the wrapper, *not* during a scrub and *not* on a touch pointer, nulls fraction so the cursor disappears when the user moves on.

**Editor preview cursor at fixed fraction = 0.5.** The editor canvas does not bootstrap the Interactivity API runtime, so `state[ mapId ].fraction` doesn't exist there and `data-wp-watch` never fires. `chart.tsx` (the React preview, Step 2) renders a static cursor at the midpoint of the samples array — same DOM shape, same CSS variable, same projection through `ChartScale`. No editor-side interactivity (no React-state hover), matching the Map block's "editor preview is a visual reference, not a working map" principle. The editor cursor's purpose is to give the inspector's *Cursor* colour control a live target; users preview the post for actual interactive behaviour. Step 7's tooltip will reuse the same anchor.

**No cursor toggle on Elevation.** Map has `enableTrackPositionCursor` (issue #118) because a standalone Map without a paired Elevation block has no useful cursor to reflect — a toggle there is meaningful. Elevation has no equivalent toggle because cursor is the *primary* affordance of the chart: a non-interactive elevation chart is just a static line drawing that could be a `<picture>` element. Removing the cursor with a toggle would remove the block's reason for existing. The Map↔Elevation pairing already handles the Map-side opt-out (`enableTrackPositionCursor: false` → Map's `onMapCursorChange` early-returns; Elevation's cursor still works, Map shows no reflection).

**State contract unchanged at the data level.** Step 6 reads three already-emitted fields — `state[ mapId ].samples` (Step 5), `state[ mapId ].statistics.distance` (Step 3 + 5), and `state[ mapId ].fraction` (initialised to `null` by `Render_Map`) — and writes one (`state[ mapId ].fraction`). No new state fields. The merge behaviour of `wp_interactivity_state()` keyed on the same `mapId` guarantees `fraction: null` is present whenever Elevation renders in its healthy state, because Elevation's healthy state requires a configured Map block on the same page, which means `Render_Map` has run and written its slice including `fraction: null` first. The single PHP change is `render_chart_wrapper()` adding `data-wp-watch--cursor="callbacks.onElevationCursorChange"` to the wrapper directives. **`role="img"` is kept** — `role="application"` would over-promise to assistive tech (the cursor is mouse/touch-only in Step 6; no keyboard handler is added).

**Two mount-trackers, not one.** v0.12.0's `mountedElevations: WeakMap` served two purposes simultaneously because v0.12.0's mount was synchronous: claim-the-slot (prevent double-init) *and* publish-the-entry (used by the watch). The rebuild's `initElevation` awaits `document.fonts.ready`, opening a window between "claim" and "publish" where a second Interactivity-mount trigger could pass any single-WeakMap guard and run the async body twice. Step 6 splits the responsibility: a `mounted: WeakSet<Element>` claims the slot synchronously at the start of `initElevation` (preventing double-init even mid-await), and a `mountedElevations: WeakMap<Element, ElevationEntry>` is populated only after the first `drawChart` completes. The watch's read-fraction-first-then-guard pattern handles the gap: when the watch fires before `mountedElevations` is set, the read still establishes the Interactivity API's subscription and the guard returns silently; the first non-null fraction after publish then renders the cursor correctly.

**Map-side cursor z-order fix (no-touch exception).** Current Map code creates the cursor `L.CircleMarker` *without* an explicit `renderer`, so it falls back to the map's default `L.canvas()`. Waypoints use the shared `svgRenderer` (`L.svg()`). In Leaflet's `overlayPane`, the SVG element is added *after* the canvas, so the SVG renders above the canvas — meaning **waypoint markers visually obscure the cursor whenever the cursor scrubs through a waypoint position**. The fix: pass `svgRenderer` to `createCursorMarker` so the cursor lives in the same SVG as waypoints, and reorder `bootMount` so the cursor is added *after* `addWaypointMarkers` — within the shared SVG, DOM order = z-order, so the cursor sits on top. This breaks the no-touch-on-Map rule that applies to Steps 0–7, justified as a deliberate exception in the spirit of `v0.13.2-pl.1`: the bug is *directly* tied to Step 6's headline deliverable (cursor sync), and waiting until Step 8 means two releases with a visibly broken cursor on every waypoint pass.

**Cleanups carried alongside the feature work:**

- `view.ts`:`drawChart` and the `touchmove` belt-and-suspenders `preventDefault` from v0.12.0:s `mount.ts` are not reintroduced. Modern Chrome / Safari / Firefox have honoured `touch-action: none` on SVG child elements since 2017–2019; the redundant listener is dropped. If a real-world browser bug surfaces post-release, it can come back with a specific reference.
- No defensive `Number.isFinite` / `Array.isArray` guards inside the cursor pipeline. Step 5's `statisticsToMarginsInput` and `readSamples` already validate the state slice; `initElevation` returns silently before the cursor code runs when data is invalid. The cursor code trusts the guarantees.
- The existing `vector-effect="non-scaling-stroke"` on the plot line **stays**. In steady state it is redundant under the rebuild's 1:1 viewBox; during the brief transient between a wrapper resize and `ResizeObserver` firing `drawChart`, the SVG renders with the old viewBox at the new CSS dimensions and `vector-effect` keeps the 2-px stroke pixel-stable across that one frame. Removing it would create a cosmetic one-frame regression on every resize.

### Cursor anatomy and projection (locked by Q1 + Q2 + Q9 grilling)

Three visible SVG elements plus one invisible hit-target, all inside the chart SVG:

```html
<g class="kntnt-gpx-blocks-elevation-cursor">
    <rect class="kntnt-gpx-blocks-elevation-cursor-hitarea"
          x="{plotLeft}" y="{plotTop}"
          width="{plotRight − plotLeft}"
          height="{plotBottom − plotTop}"
          fill="transparent"/>
    <line class="kntnt-gpx-blocks-elevation-cursor-line-v"
          x1="{cx}" y1="{cy}"
          x2="{cx}" y2="{plotBottom}"
          stroke="var(--kntnt-gpx-blocks-elevation-cursor)"
          stroke-width="1"
          display="none"/>
    <line class="kntnt-gpx-blocks-elevation-cursor-line-h"
          x1="{cx}" y1="{cy}"
          x2="{plotLeft}" y2="{cy}"
          stroke="var(--kntnt-gpx-blocks-elevation-cursor)"
          stroke-width="1"
          display="none"/>
    <circle class="kntnt-gpx-blocks-elevation-cursor-dot"
            cx="{cx}" cy="{cy}" r="6"
            fill="var(--kntnt-gpx-blocks-elevation-cursor)"
            stroke="var(--kntnt-gpx-blocks-elevation-cursor)"
            stroke-width="2"
            display="none"/>
</g>
```

**Insertion order inside the group:** hit-rect first (invisible, order irrelevant visually but keeps pointer-events fire-order predictable), then `line-v`, then `line-h`, then `dot`. The dot sits last so it visually covers the two lines' endpoints at `(cx, cy)`.

**Group position in the chart SVG:** the cursor `<g>` is appended *after* every other content group, so SVG draws it on top of axes, plot fill, plot stroke, tick marks, and tick labels. `view.ts`'s SVG insertion order grows to:

1. X axis line.
2. Y axis line.
3. Plot fill `<path>`.
4. Plot line `<path>`.
5. X tick marks `<g>`.
6. Y tick marks `<g>`.
7. X tick labels `<g>`.
8. Y tick labels `<g>`.
9. **Cursor `<g>`** *(new)*.

**Projection.** The dot's `cx` / `cy` come from `projectCursor( sample, scale )` (new pure helper, see *Module structure* below): `cx = scale.projectX( sample[ 0 ] )`, `cy = scale.projectY( sample[ 1 ] )`. The sample itself is computed by `interpolateSample( samples, distance )` where `distance = fraction × statistics.distance`. The vertical guide line goes from `(cx, cy)` down to `(cx, scale.plotBottom)`; the horizontal goes from `(cx, cy)` across to `(scale.plotLeft, cy)`. The cursor sits exactly on the rendered curve at every fraction because the same `scale.projectX` / `scale.projectY` drew the curve in Step 5.

**Visibility.** The three visible elements carry the SVG attribute `display="none"` at create-time; `applyCursorPosition` removes the attribute when fraction is non-null and re-applies it via `hideCursor` when fraction transitions to null. The hit-rect is never display-toggled — toggling it would suppress pointer events. The choice of SVG `display` attribute over CSS `display: none` is deliberate: it sits next to every other cursor attribute in the same `setAttribute` API, and it can't be overridden by a stray stylesheet rule in the editor's iframe.

**No `vector-effect` on cursor elements.** Cursor strokes are 1 px (lines) and 2 px (dot stroke) under the 1:1 viewBox mapping; the redraw fires on every resize, so no transient stretch is possible (unlike the plot line, which renders before the next `drawChart` after a resize). Skipping the attribute is mechanically safe.

### Hit-rect and pointer-to-fraction mapping (locked by Q3 grilling)

The hit-rect's geometry is updated on every `drawChart` invocation to track the current plot rectangle (axes positions move with the margin recompute on font / data changes; the plot rectangle moves with resize). `updateHitRect( elements, scale )` writes the four geometry attributes and is called at the start of the cursor-update segment of `drawChart`.

Pointer events bind once per mount on the hit-rect:

```ts
function clientXToFraction( clientX: number, hitRect: SVGRectElement ): number {
    const rect = hitRect.getBoundingClientRect();
    if ( rect.width === 0 ) {
        return 0;
    }
    return Math.max( 0, Math.min( 1, ( clientX − rect.left ) / rect.width ) );
}
```

The `rect.width === 0` guard handles a transient state during the first frame after mount (the SVG element has been inserted but the browser hasn't yet laid it out); returning `0` rather than `NaN` keeps any user input during that frame at the start of the track instead of crashing the watch's projection math. Once layout settles, subsequent events get real geometry.

### Pointer protocol (locked by Q4 grilling)

`bindPointerHandlers( hitRect, wrapper, sink )` wires the following event matrix. `sink` is a small interface `{ setFraction: ( value: number | null ) => void }` so the pointer-input file does not depend on the Interactivity store directly.

| Event | Target | Condition | Action |
|---|---|---|---|
| `pointerdown` | hit-rect | `! scrubbing` | `event.preventDefault()`, `setPointerCapture( event.pointerId )`, `scrubbing = true`, `sink.setFraction( clientXToFraction( event.clientX, hitRect ) )`. |
| `pointermove` | hit-rect | `scrubbing \|\| event.pointerType === 'mouse'` | `sink.setFraction( clientXToFraction( event.clientX, hitRect ) )`. |
| `pointerup` | hit-rect | `scrubbing` | `releasePointerCapture( event.pointerId )` if capture is held, `scrubbing = false`. **No fraction write** — cursor stays at last position. |
| `pointercancel` | hit-rect | `scrubbing` | Same as `pointerup`. |
| `pointerleave` | block wrapper | `! scrubbing && event.pointerType !== 'touch'` | `sink.setFraction( null )` — cursor disappears. |

A scrub triggered by a primary pointer is not interrupted by secondary pointers: the `! scrubbing` guard on `pointerdown` drops any second-finger event that arrives during an active scrub.

`bindPointerHandlersWhenVisible( target, bind )` wraps the binding in an `IntersectionObserver` with `rootMargin: '200px 0px'` (same as Map's lazy-mount margin and v0.12.0's elevation lazy-mount margin), so the pointer listeners attach only when the chart approaches the viewport. The cursor-sync watch is *not* gated by the observer — it activates as soon as `mountedElevations.set` publishes the entry, so cross-block cursor sync works as soon as both blocks are mounted, regardless of whether either has been scrolled into view yet.

### State contract and wrapper directive (locked by Q5 grilling)

**No PHP data changes.** Step 6 reads three fields already on the per-mapId state slice (`samples`, `statistics`, `fraction`) and writes one (`fraction`). The `fraction: null` initial slot is emitted by `Render_Map::render()` and merged with Elevation's slice through `wp_interactivity_state`'s namespace-keyed merge behaviour. Render_Elevation does **not** emit `fraction`.

**One PHP wrapper change.** `Render_Elevation::render_chart_wrapper()` adds the watch directive:

```php
data-wp-watch--cursor="callbacks.onElevationCursorChange"
```

The qualifier suffix (`--cursor`) matches Map's `data-wp-watch--cursor="callbacks.onMapCursorChange"` — the two blocks use the same suffix because they're synchronising the same value via different callback names. `role="img"` is kept; the Step 3 comment in `render_chart_wrapper()` that says *"Step 6 will also upgrade `role` to `application`"* is deleted in this step's diff because the upgrade is not happening.

`wrap_warning()` does **not** add the watch directive — warning states have no chart and no cursor, and the Interactivity directives would cost a watch subscription with no payoff.

### Lifecycle (locked by Q6 grilling)

**Async mount.** `initElevation` is `async` (it awaits `document.fonts.ready`). Two registries guard the lifecycle:

```ts
const mounted = new WeakSet< Element >();
const mountedElevations = new WeakMap< Element, ElevationEntry >();

interface ElevationEntry {
    readonly svg: SVGSVGElement;
    readonly wrapper: HTMLElement;
    readonly samples: ReadonlyArray< readonly [ number, number ] >;
    readonly distance: number;
    scale: ChartScale;
    readonly cursorElements: CursorElements;
}

interface CursorElements {
    readonly hitRect: SVGRectElement;
    readonly dot: SVGCircleElement;
    readonly verticalLine: SVGLineElement;
    readonly horizontalLine: SVGLineElement;
}
```

`mounted` claims the wrapper synchronously at the start of `initElevation` to prevent double-init even mid-await. `mountedElevations` is set *after* the first `drawChart` completes, so the watch never sees an incomplete entry. The watch's first-fire-before-publish case is handled by the standard read-fraction-then-guard idiom.

**Persistent cursor elements.** `createCursorElements( svg, scale )` runs once during the first `drawChart` and stores the four element references in the `ElevationEntry`. Subsequent `drawChart` invocations call `updateHitRect( elements, scale )` to track plot-rect changes — the visible elements update through the next call to `applyCursorPosition`, driven by the watch reading the current fraction.

**Redraw sequence with cursor.** `drawChart` runs:

1. `svg.setAttribute( 'viewBox', '0 0 {w} {h}' )`.
2. `removeMatching( svg, "axes, plot paths, tick groups" )` — does **not** include the cursor classes; cursor elements are persistent and only get updated, never removed.
3. `computeChartScale(...)` → fresh `ChartScale`.
4. Skip if `scale.xTicks.length === 0` (sentinel).
5. Append axes, fill, stroke, tick marks, tick labels.
6. *(New in Step 6)* If this is the first `drawChart` for this wrapper, `createCursorElements( svg, scale )` and store in entry; otherwise `updateHitRect( entry.cursorElements, scale )`.
7. *(New in Step 6)* Update `entry.scale = scale` so subsequent watch fires project against the current scale.
8. *(New in Step 6)* Call `applyCursorFromState( entry )` — reads `state[ mapId ].fraction`, projects through current scale, applies position; hides cursor when fraction is null. Restores cursor visibility after resize when a fraction is already set.

The cursor is *not* removed and re-added between redraws. It is created once, repositioned forever.

**Watch callback.** `onElevationCursorChange` reads `state[ mapId ].fraction` first (Interactivity subscription idiom), then looks up the entry in `mountedElevations`, then returns silently if the entry is absent (race window before first `drawChart` completes). When the entry is present, it interpolates the sample, projects, and writes via `applyCursorPosition` (visible-on-non-null) or `hideCursor` (visible-off-on-null).

### Editor preview cursor (locked by Q7 grilling)

`chart.tsx` (the React preview) imports `interpolateSample` and `projectCursor` from `geometry/cursor.ts` and renders the three visible elements at a fixed fraction = 0.5:

```tsx
const previewFraction = 0.5;
const sample = interpolateSample( samples, statistics.distance * previewFraction );
const projected = sample !== null ? projectCursor( sample, scale ) : null;

// ... inside the SVG JSX, after the tick labels:
{ projected !== null && (
    <g className="kntnt-gpx-blocks-elevation-cursor">
        <rect className="kntnt-gpx-blocks-elevation-cursor-hitarea"
              x={ scale.plotLeft } y={ scale.plotTop }
              width={ scale.plotRight − scale.plotLeft }
              height={ scale.plotBottom − scale.plotTop }
              fill="transparent" />
        <line className="kntnt-gpx-blocks-elevation-cursor-line-v"
              x1={ projected.cx } y1={ projected.cy }
              x2={ projected.cx } y2={ scale.plotBottom }
              stroke="var(--kntnt-gpx-blocks-elevation-cursor)"
              strokeWidth="1" />
        <line className="kntnt-gpx-blocks-elevation-cursor-line-h"
              x1={ projected.cx } y1={ projected.cy }
              x2={ scale.plotLeft } y2={ projected.cy }
              stroke="var(--kntnt-gpx-blocks-elevation-cursor)"
              strokeWidth="1" />
        <circle className="kntnt-gpx-blocks-elevation-cursor-dot"
                cx={ projected.cx } cy={ projected.cy } r="6"
                fill="var(--kntnt-gpx-blocks-elevation-cursor)"
                stroke="var(--kntnt-gpx-blocks-elevation-cursor)"
                strokeWidth="2" />
    </g>
) }
```

In editor mode the cursor is *always* visible (no `display="none"`) because there is no Interactivity-driven fraction to hide it from. No `useEffect`, no React state, no DOM imperative writes — pure JSX. The midpoint is computed each render; when the inspector changes `cursorColor`, React re-renders and the colour custom property on the wrapper updates (existing Step 1 + 3 wiring), so the cursor visually repaints.

When `samples.length < 2` (degenerate-but-still-healthy case, e.g., a 1-point track that somehow passed `Render_Elevation`'s `zero-distance` gate), `interpolateSample` returns `null` and the entire `<g>` is conditionally skipped.

### Cursor colour wiring (locked by Q8 grilling)

The `cursorColor` block attribute already exists (Step 1) and is already an inspector row in `elevationColorRows()` (Step 1). Step 6 wires it through to the cursor SVG:

- **`src/blocks/elevation/edit.tsx`** — adds a `usefulValue` wiring parallel to `axisLabelColor` (existing) and `plotFillColor` (Step 5), reading `attributes.cursorColor` and writing the inline CSS custom property `--kntnt-gpx-blocks-elevation-cursor` on the wrapper when non-empty.
- **`classes/Rendering/Render_Elevation::build_inline_style()`** — adds the mirror PHP-side emission, after the `plot_fill` block and before the typography block:

  ```php
  $cursor = Color_Sanitizer::sanitize(
      is_string( $attributes['cursorColor'] ?? null ) ? (string) $attributes['cursorColor'] : ''
  );
  if ( '' !== $cursor ) {
      $parts[] = '--kntnt-gpx-blocks-elevation-cursor: ' . esc_attr( $cursor );
  }
  ```

- **`src/blocks/elevation/style.scss`** — declares the SCSS default:

  ```scss
  .kntnt-gpx-blocks-elevation {
      --kntnt-gpx-blocks-elevation-cursor: #d63638;
      // ...existing defaults...

      .kntnt-gpx-blocks-elevation-cursor-hitarea {
          touch-action: none;
          cursor: crosshair;
      }
  }
  ```

  The default `#d63638` matches Map's `--kntnt-gpx-blocks-track-cursor-color` default in `src/blocks/map/mount.ts:443`. Same default colour on both sides gives the synced cursors visual parity — users perceive them as "the same point on the track". A `cursor: crosshair` rule on the hit-rect surfaces the affordance on desktop without needing a custom SVG cursor; touch users get no affordance change (correct — there's nothing to hover).

The SVG elements themselves read the variable through `fill="var(...)"` / `stroke="var(...)"` attributes; nothing reads the attribute via `setAttribute` at runtime, so the cursor's colour updates automatically when the user changes it in the inspector (the custom property on the wrapper changes, the SVG's `var()` resolution follows).

### Map-side z-order fix (no-touch exception)

The exception breaks the Steps 0–7 no-touch rule on Map for a single, narrowly scoped bug fix. Two files change:

**`src/blocks/map/mount.ts`** — `createCursorMarker` and `maybeCreateCursorMarker` gain an `svgRenderer` parameter (typed `L.Renderer`) and pass it through to `L.circleMarker`'s options:

```diff
 export function createCursorMarker(
     map: L.Map,
+    svgRenderer: L.Renderer,
     coords: Array< [ number, number ] >,
     trackCumDist: number[],
     totalDistance: number,
     blockEl: HTMLElement
 ): L.CircleMarker {
     // ...
     const cursor = L.circleMarker( midLatLng, {
         radius: 6,
         color: trackCursorColor,
         weight: 2,
         fillColor: trackCursorColor,
         fillOpacity: 1,
         interactive: false,
         opacity: 0,
+        renderer: svgRenderer,
     } );
     cursor.addTo( map );
     return cursor;
 }
```

`maybeCreateCursorMarker` forwards the renderer through identically.

**`src/blocks/map/view.ts`** — `bootMount` reorders the cursor and waypoint additions so the cursor is created *after* the waypoints, and passes `svgRenderer` (already returned by `renderTrackLayers`) through to the cursor factory:

```diff
-    const cursor = maybeCreateCursorMarker(
-        settings.enableTrackPositionCursor,
-        map, coords, trackCumDist, totalDistance, blockEl
-    );
-
     const closeSticky = addWaypointMarkers(
         map, mapState.waypoints, settings, svgRenderer, blockEl
     );
+
+    const cursor = maybeCreateCursorMarker(
+        settings.enableTrackPositionCursor,
+        map, svgRenderer, coords, trackCumDist, totalDistance, blockEl
+    );
```

The `mountedMaps.set` call moves to after the new cursor creation so the entry's `cursor` field reflects the post-fix marker. The `attachScrubHandlers` call stays in its current location after the entry is set (no order change needed there).

After the fix, both cursor and waypoints render inside the same shared `<svg>` under `overlayPane`. Within that SVG, the DOM order is `hitLayer → waypoint markers → cursor`, so the cursor sits on top of any waypoint it passes over. The pre-fix `<canvas>` element under `overlayPane` continues to host the track polyline alone.

### Module structure (locked by Q10 grilling)

Three new TypeScript files plus modifications to existing files.

**New files:**

```
src/blocks/elevation/geometry/cursor.ts
src/blocks/elevation/geometry/cursor.test.ts
src/blocks/elevation/cursor.ts
src/blocks/elevation/cursor.test.ts
src/blocks/elevation/cursor-input.ts
src/blocks/elevation/cursor-input.test.ts
```

**`geometry/cursor.ts`** — pure math, no DOM. Two exports:

```ts
export interface CursorSample {
    readonly distance: number;
    readonly elevation: number;
}

export interface ProjectedCursor {
    readonly cx: number;
    readonly cy: number;
}

export function interpolateSample(
    samples: ReadonlyArray< readonly [ number, number ] >,
    distance: number,
): CursorSample | null;

export function projectCursor(
    sample: CursorSample,
    scale: ChartScale,
): ProjectedCursor;
```

`interpolateSample` does a binary search on the samples array for the bracket containing `distance`, then linearly interpolates `elevation` between the two adjacent samples. Returns `null` when `samples.length < 2`; clamps to endpoints when `distance` is out of range. `projectCursor` is the trivial composition `{ cx: scale.projectX( sample.distance ), cy: scale.projectY( sample.elevation ) }`.

**`cursor.ts`** — SVG DOM helpers, JSDOM-testable. Exports:

```ts
export interface CursorElements {
    readonly hitRect: SVGRectElement;
    readonly dot: SVGCircleElement;
    readonly verticalLine: SVGLineElement;
    readonly horizontalLine: SVGLineElement;
}

export function createCursorElements(
    svg: SVGSVGElement,
    scale: ChartScale,
): CursorElements;

export function updateHitRect(
    elements: CursorElements,
    scale: ChartScale,
): void;

export function applyCursorPosition(
    elements: CursorElements,
    projected: ProjectedCursor,
): void;

export function hideCursor( elements: CursorElements ): void;

export function showCursor( elements: CursorElements ): void;
```

`createCursorElements` builds the `<g>` plus its four children, sets `display="none"` on the three visible elements, returns references. `updateHitRect` writes the four geometry attributes on the hit-rect from the current scale. `applyCursorPosition` writes `cx` / `cy` on the dot, `x1` / `y1` / `x2` / `y2` on both lines, and removes the `display` attribute. `hideCursor` re-sets `display="none"` on the three visible elements (leaves the hit-rect alone). `showCursor` removes `display="none"` from the three visible elements (idempotent on already-visible elements).

**`cursor-input.ts`** — pointer handling, JSDOM-testable. Exports:

```ts
export interface FractionSink {
    setFraction( value: number | null ): void;
}

export function clientXToFraction(
    clientX: number,
    hitRect: SVGRectElement,
): number;

export function bindPointerHandlers(
    hitRect: SVGRectElement,
    wrapper: HTMLElement,
    sink: FractionSink,
): void;

export function bindPointerHandlersWhenVisible(
    target: Element,
    bind: () => void,
): void;
```

The matrix from *Pointer protocol* above is implemented inside `bindPointerHandlers`. The `IntersectionObserver` fallback in `bindPointerHandlersWhenVisible` follows v0.12.0's pattern: when the API is undefined (older runtimes), `bind()` runs immediately.

**Modified files:**

- `src/blocks/elevation/view.ts` — `initElevation` becomes the orchestrator: claim `mounted` slot, wait for fonts, build SVG, compute margins, run first `drawChart` (which now also creates cursor elements via `cursor.ts`), publish to `mountedElevations`, register `bindPointerHandlersWhenVisible` with a sink that writes `state[ mapId ].fraction`, register the `onElevationCursorChange` watch. The watch reads fraction first, looks up the entry, and applies through `applyCursorPosition` / `hideCursor`. The local `mounted` WeakSet remains; `mountedElevations` is the new WeakMap.
- `src/blocks/elevation/chart.tsx` — imports `interpolateSample` and `projectCursor` from `geometry/cursor.ts`; the editor preview renders the static cursor at fraction = 0.5 as shown above.
- `src/blocks/elevation/chart.test.tsx` — extended with cursor-rendering assertions.
- `src/blocks/elevation/edit.tsx` — adds the `cursorColor` inline custom-property wiring.
- `src/blocks/elevation/style.scss` — adds the `--kntnt-gpx-blocks-elevation-cursor: #d63638` default and the `touch-action: none` + `cursor: crosshair` rules on `.kntnt-gpx-blocks-elevation-cursor-hitarea`.
- `src/blocks/elevation/style.test.ts` — extended with assertions for the new SCSS contracts.
- `classes/Rendering/Render_Elevation.php` — `build_inline_style()` gains the `cursorColor` emission; `render_chart_wrapper()` gains the `data-wp-watch--cursor` directive and removes the Step 3 comment about a future `role` upgrade.
- `tests/Unit/Rendering/Render_ElevationTest.php` — extended with `cursorColor` wiring assertions and a `data-wp-watch--cursor` presence assertion.
- `src/blocks/map/mount.ts` and `src/blocks/map/view.ts` — the no-touch exception as documented above.

### File layout for Step 6

```
src/blocks/elevation/
├── view.ts                                      — modified: orchestrator with cursor lifecycle
├── chart.tsx                                    — modified: static cursor at fraction = 0.5
├── chart.test.tsx                               — modified: cursor JSX assertions
├── edit.tsx                                     — modified: cursorColor inline custom-property wiring
├── style.scss                                   — modified: --kntnt-gpx-blocks-elevation-cursor default; hit-rect CSS
├── style.test.ts                                — modified: cursor CSS contract pins
├── cursor.ts                                    — NEW: SVG DOM helpers
├── cursor.test.ts                               — NEW
├── cursor-input.ts                              — NEW: pointer handlers + IO wrapper
├── cursor-input.test.ts                         — NEW
└── geometry/
    ├── cursor.ts                                — NEW: interpolateSample + projectCursor
    └── cursor.test.ts                           — NEW

classes/Rendering/
└── Render_Elevation.php                         — modified: cursorColor in build_inline_style(); data-wp-watch--cursor in render_chart_wrapper()

tests/Unit/Rendering/
└── Render_ElevationTest.php                     — modified: cursorColor + watch directive assertions

src/blocks/map/
├── mount.ts                                     — modified (no-touch exception): createCursorMarker accepts svgRenderer
└── view.ts                                      — modified (no-touch exception): bootMount reorders cursor after waypoints
```

The pure-geometry / DOM / pointer-input seam mirrors v0.12.0's split (`cursor.ts`, `mount.ts`, `view.ts`) but realigned to the rebuild's `geometry/` folder convention. Each file is independently testable: `geometry/cursor.ts` needs no DOM, `cursor.ts` needs only JSDOM, `cursor-input.ts` needs JSDOM and synthesised events.

### Test-driven development

Write the helper-level tests first, watch them go red, implement until green:

- **`src/blocks/elevation/geometry/cursor.test.ts`** — pure math:
  - `interpolateSample( samples, 0 )` returns the first sample's elevation.
  - `interpolateSample( samples, lastSample.distance )` returns the last sample's elevation.
  - `interpolateSample( samples, mid-distance )` linearly interpolates between the two bracketing samples; assert against a hand-computed value for a 3-sample fixture.
  - `interpolateSample( samples, -1 )` and `interpolateSample( samples, totalDistance + 1 )` clamp to endpoints.
  - `interpolateSample( [], 0 )` and `interpolateSample( [ single ], 0 )` return `null`.
  - `projectCursor( sample, scale )` returns `{ cx: scale.projectX( sample.distance ), cy: scale.projectY( sample.elevation ) }`. Use a synthesised `ChartScale` with identity projections to assert against exact values.
- **`src/blocks/elevation/cursor.test.ts`** — SVG DOM:
  - `createCursorElements( svg, scale )` appends a `<g class="kntnt-gpx-blocks-elevation-cursor">` to the SVG and returns references to all four children.
  - The three visible elements have `display="none"`; the hit-rect does not.
  - Hit-rect's `x`, `y`, `width`, `height` match `scale.plotLeft`, `scale.plotTop`, `scale.plotRight − scale.plotLeft`, `scale.plotBottom − scale.plotTop`.
  - `updateHitRect( elements, newScale )` updates all four hit-rect attributes.
  - `applyCursorPosition( elements, { cx: 100, cy: 50 } )` sets the dot's `cx="100" cy="50"`, the vertical line's `x1="100" x2="100"`, the horizontal line's `y1="50" y2="50"`, and removes `display="none"` from all three.
  - `hideCursor( elements )` re-applies `display="none"` to the three visible elements; the hit-rect's `display` is unchanged.
  - `showCursor` on already-visible elements is a no-op.
- **`src/blocks/elevation/cursor-input.test.ts`** — pointer handlers:
  - `clientXToFraction` produces the expected fraction for known `clientX` values against a synthesised bounding rect. Out-of-range inputs clamp to `[ 0, 1 ]`. A zero-width rect returns `0`.
  - `bindPointerHandlers` writes to `sink.setFraction` on `pointerdown`. The same handler calls `event.preventDefault()` and `hitRect.setPointerCapture( pointerId )`.
  - `pointermove` during a scrub (after `pointerdown`) writes to `sink.setFraction`. A `pointermove` with `pointerType === 'mouse'` *without* a prior `pointerdown` also writes. A `pointermove` with `pointerType === 'touch'` without a scrub does *not* write.
  - `pointerup` releases capture and clears the internal scrubbing flag, but does *not* write `sink.setFraction( null )`.
  - `pointerleave` on the wrapper with `pointerType === 'mouse'` and no scrub writes `sink.setFraction( null )`. With `pointerType === 'touch'`, the write is skipped. With an active scrub, the write is also skipped.
  - A second `pointerdown` during an active scrub is ignored (no `setFraction`, no `setPointerCapture`).
  - `bindPointerHandlersWhenVisible` invokes `bind()` immediately when `IntersectionObserver` is undefined; otherwise the call fires from the observer callback on first intersection. (Mock the observer.)

Then write the host-level tests:

- **`src/blocks/elevation/chart.test.tsx`** — extended:
  - With a non-empty `samples` array and `samples.length >= 2`, the editor preview's SVG contains a `<g class="kntnt-gpx-blocks-elevation-cursor">` with the four children classes documented above.
  - The cursor circle has `r="6"` and `stroke-width="2"`; the lines have `stroke-width="1"`.
  - The cursor elements reference `var(--kntnt-gpx-blocks-elevation-cursor)` for `fill` (on the dot) and `stroke` (on all three visible elements).
  - The dot's `cx` / `cy` match `projectCursor( interpolateSample( samples, statistics.distance * 0.5 ), scale )` for the synthesised input.
  - With `samples.length < 2`, the cursor `<g>` is not rendered.
- **`src/blocks/elevation/style.test.ts`** — extended:
  - `.kntnt-gpx-blocks-elevation` carries `--kntnt-gpx-blocks-elevation-cursor: #d63638`.
  - `.kntnt-gpx-blocks-elevation-cursor-hitarea` carries `touch-action: none` and `cursor: crosshair`.
- **`tests/Unit/Rendering/Render_ElevationTest.php`** — extended:
  - `render()` includes `data-wp-watch--cursor="callbacks.onElevationCursorChange"` in the healthy-state wrapper HTML.
  - `render()` does *not* include the watch directive in any warning-state wrapper.
  - `build_inline_style( [ 'cursorColor' => '#abc' ] )` produces `--kntnt-gpx-blocks-elevation-cursor: #aabbcc` (after sanitiser canonicalisation).
  - `build_inline_style( [ 'cursorColor' => '' ] )` omits the custom property.
  - `build_inline_style( [ 'cursorColor' => 'not-a-colour' ] )` omits the custom property (sanitiser rejects).

Map-side fix:

- Whatever existing test file covers `bootMount` (or a new co-located test if none exists) — assert that after `bootMount` runs against a fixture containing both a cursor toggle and waypoints, the cursor's DOM element appears *after* the last waypoint marker in the shared SVG. Use a JSDOM stand-in for Leaflet's DOM.

**No cross-block integration test.** Both blocks correctly read and write the same `state[ mapId ].fraction` slot; that's a property of each block individually verified by the unit tests above. An integration test simulating Map writing → Elevation reading would exercise the Interactivity API runtime, not our code.

Implementation follows the tests until all are green.

### Acceptance criteria

Step 6 is done — and `v0.13.6` may be tagged — when **all** of the following hold.

**Behaviour:**

1. The Step 3/4/5 healthy-state chart now carries a visible cursor at the user's pointer position whenever the pointer is inside the plot rectangle (or, on touch, during an active scrub). The five warning states are unaffected.
2. The cursor consists of one `<circle>` at the curve point plus two `<line>` elements forming an L-shape from the circle down to `plotBottom` and across to `plotLeft`. The three visible elements live inside a `<g class="kntnt-gpx-blocks-elevation-cursor">` group at the end of the SVG host's children list.
3. An invisible `<rect class="kntnt-gpx-blocks-elevation-cursor-hitarea" fill="transparent">` covers exactly the plot rectangle. CSS gives it `touch-action: none` and `cursor: crosshair`.
4. **Pointer protocol matrix from *Pointer protocol* holds in full.** Mouse hover updates fraction continuously; mouse press-and-drag updates with `setPointerCapture`; mouse `pointerup` does not null fraction; mouse `pointerleave` on the wrapper (no active scrub) nulls fraction. Touch press-and-drag updates fraction; touch `pointerup` does not null; touch `pointerleave` does *not* null.
5. **Cross-block sync:** a scrub on Map's polyline moves the Elevation cursor; a scrub on Elevation's chart moves the Map cursor. Both blocks read and write `state[ mapId ].fraction` through their respective watch callbacks (`onMapCursorChange`, `onElevationCursorChange`).
6. **No tooltip** — Step 7 is separate.
7. The `cursorColor` block attribute (already declared in Step 1) is wired through `edit.tsx`, `Render_Elevation::build_inline_style()`, and `style.scss` to the custom property `--kntnt-gpx-blocks-elevation-cursor`. The SCSS default is `#d63638` (Gutenberg red, matching Map's default `--kntnt-gpx-blocks-track-cursor-color`).
8. `Render_Elevation::render_chart_wrapper()` emits `data-wp-watch--cursor="callbacks.onElevationCursorChange"` on the healthy-state wrapper. `wrap_warning()` does *not* emit it. The wrapper's `role` remains `"img"`.
9. **The cursor `<g>` is persistent across redraws.** `view.ts`'s `removeMatching` selector list does **not** include any cursor classes; resize, `loadingdone`, and other redraw triggers update the cursor's geometry without re-creating its DOM nodes.
10. **`mounted: WeakSet` claims the slot synchronously** at the start of `initElevation`. **`mountedElevations: WeakMap` is populated only after the first `drawChart` completes.** The watch's read-fraction-first-then-guard idiom handles the race window between claim and publish.
11. **Editor preview** in `chart.tsx` shows a static cursor at fraction = 0.5 with the same DOM shape as the frontend. The cursor's colour responds live to inspector edits via the CSS variable.
12. **`vector-effect="non-scaling-stroke"`** is not emitted on any cursor element. The existing attribute on the plot line stays in place.
13. **`touchmove` belt-and-suspenders preventDefault from v0.12.0 is not reintroduced.** Touch-cancellation of page-scroll is handled entirely by `touch-action: none` on the hit-rect class.
14. **Map-side z-order fix:** after Step 6, `createCursorMarker` accepts an `svgRenderer` parameter and `bootMount` adds the cursor *after* the waypoints. The Map cursor renders visually on top of any waypoint it passes over.

**Gates (must all pass at HEAD before tagging):**

15. `npm run build`.
16. `composer test` (Pest), including the extended `Render_ElevationTest`.
17. `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M`.
18. `npm run test:js` — including the new `geometry/cursor.test.ts`, `cursor.test.ts`, `cursor-input.test.ts`, the extended `chart.test.tsx`, `style.test.ts`, and any extended Map-mount tests for the z-order fix.
19. `npx wp-scripts lint-js src/blocks/`.

**Manual verification in WordPress Playground (`@wp-playground/cli`):**

20. Insert one configured Map and one Elevation block on the same post: scrubbing on the Elevation chart moves the Map cursor; scrubbing on the Map's polyline moves the Elevation cursor. Both cursors track the same position on the recorded track.
21. **Desktop mouse hover** on the Elevation chart without a press: the cursor follows the mouse.
22. **Desktop mouse press-and-drag**: cursor follows, even when the mouse drifts outside the chart's hit-rect. Releasing the button leaves the cursor at its final position.
23. **Touch + drag** on a touch device or in DevTools touch emulation: cursor follows the finger. Lifting the finger leaves the cursor in place (no flicker-off).
24. **Mouse leaves the wrapper:** cursor disappears.
25. **Resize the browser window**: the cursor stays at the same fraction; the L-shape lines re-anchor to the new `plotLeft` / `plotBottom`.
26. **Pick `Cursor` colour** in the inspector: editor preview updates instantly; published post reflects the colour after save.
27. **Map with `enableTrackPositionCursor: false`**: scrubbing on Elevation still moves Elevation's cursor; Map shows no cursor; no console errors on either side.
28. **Cursor on Map passing over a waypoint marker**: the cursor sits *on top of* the waypoint marker (z-order fix).
29. **Elevation in warning state** (`bound-unconfigured`, `no-elevation-data`, `zero-distance`): no chart, no cursor, no watch errors in the console.
30. **Inspect the editor preview's SVG**: `<g class="kntnt-gpx-blocks-elevation-cursor">` is present with all four children, the visible elements at the midpoint of the samples array.

### Release

When all acceptance criteria hold, follow the six-step release procedure documented in `AGENTS.md` (section *Cutting a release*). Tag `v0.13.6`. Commit message: `Release v0.13.6 — Step 6: cursor with cross-block sync`.

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
