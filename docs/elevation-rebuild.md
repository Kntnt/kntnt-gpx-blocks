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

## Step 6 (released as v0.13.6) — recap

The Elevation block's healthy chart now carries a cursor — an SVG `<circle>` anchored to the elevation curve at the pointer's distance plus two L-shape `<line>` guides pointing from the circle down to the corresponding x-axis tick and across to the corresponding y-axis tick — synchronised cross-block with GPX Map through a single shared slot `state[ mapId ].fraction` written and read by both blocks' watch callbacks (`onMapCursorChange` and `onElevationCursorChange`). An invisible `<rect class="kntnt-gpx-blocks-elevation-cursor-hitarea" fill="transparent">` sized to the plot rectangle catches every pointer event; `fraction = clamp( ( clientX − hitRect.getBoundingClientRect().left ) / hitRect.width, 0, 1 )` is purely rect-relative, so the y-axis label margin can never map to fraction = 0. The pointer matrix is v0.12.0's verbatim: `pointerdown` captures and writes; `pointermove` writes when scrubbing *or* `pointerType === 'mouse'` (the latter gives desktop hover-without-press); `pointerup` releases capture but does *not* null fraction (cursor stays at its last position so the user can read it); `pointerleave` on the *wrapper* (not the hit-rect) nulls fraction only on mouse + no-active-scrub (touch lift is explicitly skipped so the cursor persists for the user to read). `touch-action: none` on the hit-rect class prevents the browser from scrolling the page during a touch-drag on the plot; the page still scrolls when the user touches the label margins (bonus UX). The cursor `<g>` is appended *after* every other content group and persists across redraws — `view.ts`'s `removeMatching` selector list does **not** include any cursor class; resize, `loadingdone`, and other redraw triggers only re-write the cursor's geometry attributes through `updateHitRect` + `applyCursorPosition`. A two-registry lifecycle handles the async-mount race: a `mounted: WeakSet< Element >` claims the wrapper synchronously at the start of `initElevation` (preventing double-init even mid-await across `await document.fonts.ready`), and a `mountedElevations: WeakMap< Element, ElevationEntry >` is populated only after the first `drawChart` completes — the watch's read-fraction-first-then-guard idiom handles the gap between claim and publish. Editor preview in `chart.tsx` renders a static cursor at fraction = 0.5 with the same DOM shape as the frontend (no `display="none"`, no `useEffect`, pure JSX), giving the inspector's *Cursor* colour row a live target; when `samples.length < 2` the entire `<g>` is conditionally skipped. The `cursorColor` block attribute (declared in Step 1) is wired through three surfaces — `edit.tsx`, `Render_Elevation::build_inline_style()`, and `style.scss` — to a new CSS variable `--kntnt-gpx-blocks-elevation-cursor` (SCSS default `#d63638`, matching Map's `--kntnt-gpx-blocks-track-cursor-color` default for visual parity between the two synced cursors). The single PHP wrapper change is `render_chart_wrapper()` emitting `data-wp-watch--cursor="callbacks.onElevationCursorChange"` on the healthy-state wrapper; `wrap_warning()` does *not* emit it; `role="img"` is kept — no upgrade to `role="application"` because the cursor is mouse/touch-only in Step 6. **No new state fields:** Step 6 reads three already-emitted fields (`samples`, `statistics.distance`, `fraction`) and writes one (`fraction`); `Render_Map::render()` emits the `fraction: null` initial slot, merged with Elevation's slice through `wp_interactivity_state`'s namespace-keyed merge. The SVG insertion order grows from eight to nine groups, with **Cursor `<g>`** appended last after the X / Y tick label groups; inside the group the order is hit-rect → vertical guide → horizontal guide → dot, so the dot visually covers the two guides' endpoints at `(cx, cy)`. A Map-side z-order fix ships alongside as a no-touch exception (justified in the spirit of `v0.13.2-pl.1`): the Map cursor previously sat on the default `L.canvas()` renderer while waypoints used the shared `svgRenderer` (`L.svg()`), so waypoints visually obscured the cursor whenever it scrubbed through a waypoint position. `createCursorMarker` and `maybeCreateCursorMarker` now accept an `svgRenderer` parameter and pass it as `L.circleMarker`'s `renderer` option; `bootMount` reorders the cursor's addition to *after* `addWaypointMarkers`, so within the shared SVG the cursor sits on top of any waypoint it passes over.

For orientation, read the relevant source files directly: `src/blocks/elevation/geometry/cursor.ts` (new — pure math: `interpolateSample( samples, distance )` does a binary-search bracket + linear interpolation with endpoint clamping and returns `null` on `samples.length < 2`; `projectCursor( sample, scale )` is the trivial `{ cx: scale.projectX(...), cy: scale.projectY(...) }` composition), `src/blocks/elevation/cursor.ts` (new — SVG DOM helpers: `createCursorElements( svg, scale )` builds the `<g>` + four children with `display="none"` on the three visible ones, `updateHitRect( elements, scale )` syncs the four hit-rect geometry attributes from the current scale, `applyCursorPosition` / `hideCursor` / `showCursor` toggle visibility via the SVG `display` attribute rather than CSS), `src/blocks/elevation/cursor-input.ts` (new — pointer handlers: `clientXToFraction` with the `rect.width === 0` first-frame guard, `bindPointerHandlers` implementing the v0.12.0 matrix against a `FractionSink` indirection so the file doesn't depend on the Interactivity store directly, `bindPointerHandlersWhenVisible` wrapping the bind in an `IntersectionObserver` with `rootMargin: '200px 0px'` matching Map's lazy-mount margin), `src/blocks/elevation/view.ts` (rewired orchestrator — synchronous `mounted` claim, async font-wait, build SVG, first `drawChart` creates persistent cursor elements once, publishes the entry to `mountedElevations`, registers `bindPointerHandlersWhenVisible` with a sink writing `state[ mapId ].fraction`, registers the `onElevationCursorChange` watch that reads fraction first then guards on the entry's presence), `src/blocks/elevation/chart.tsx` (extended — imports `interpolateSample` + `projectCursor`, renders the static cursor at fraction = 0.5 as JSX after the tick label groups), `src/blocks/elevation/edit.tsx` (`cursorColor` custom-property wiring parallel to Step 5's `plotFillColor` and Step 4's `axisLabelColor`), `src/blocks/elevation/style.scss` (default `--kntnt-gpx-blocks-elevation-cursor: #d63638` plus the `touch-action: none` + `cursor: crosshair` rules on `.kntnt-gpx-blocks-elevation-cursor-hitarea`), `src/blocks/elevation/style.test.ts` (extended with the cursor-default and hit-rect CSS contract pins), `src/blocks/elevation/geometry/cursor.test.ts`, `src/blocks/elevation/cursor.test.ts`, `src/blocks/elevation/cursor-input.test.ts`, and the extended `src/blocks/elevation/chart.test.tsx` (the helper-first TDD test surface: pure math identities, SVG DOM-builder shape, pointer matrix simulation, host-level cursor rendering assertions), `classes/Rendering/Render_Elevation.php` (`build_inline_style()` emits the `cursorColor` CSS variable; `render_chart_wrapper()` emits `data-wp-watch--cursor`; the Step 3 comment about a future `role` upgrade is deleted), `tests/Unit/Rendering/Render_ElevationTest.php` (extended with the `cursorColor` wiring assertions and a `data-wp-watch--cursor` presence/absence pair across healthy and warning states), `src/blocks/map/mount.ts` and `src/blocks/map/view.ts` (the no-touch exception — `createCursorMarker` gains the `svgRenderer` parameter, `bootMount` reorders the cursor's addition after `addWaypointMarkers`), `src/blocks/map/cursor-gate.test.ts` (new — pins the z-order fix).

**Follow-up release (issue #144) — Cursor & guides controls.** Three new boolean attributes `showCursor` (default `true`), `showVerticalGuide` (default `true`), and `showHorizontalGuide` (default `false`) gate the Elevation cursor lifecycle from the inspector. A new `Cursor & guides` PanelBody sits above `Tooltip info` in the Settings tab with three `ToggleControl`s. The two sub-toggles hide whenever the master `Cursor` toggle is off (same hide-on-master-off pattern as issue #143) and reappear with their saved values when the master is re-enabled. The `Cursor` row in the Color panel hides under the same rule. When `showCursor` is off, the disablement is functional: the cursor `<g>` never enters the SVG, the chart's pointer handlers are not bound (Elevation produces no `fraction` writes), and `onElevationCursorChange` returns silently. Each guide's `<line>` is created only when its respective sub-toggle is on; the dot and hit-rect always exist when the cursor is on. The class names rename from `cursor-line-{v,h}` to `cursor-guide-{v,h}` and `CursorElements.{verticalLine,horizontalLine}` rename to `{verticalGuide,horizontalGuide}` — the pre-1.0 policy applies, no aliases are kept. A new pure helper module `cursor-bootstrap.ts` factors the lifecycle gating decisions (`readCursorSettingsFromContext` plus `buildCursorElementsForLifecycle`) out of `view.ts`'s async mount pipeline so the gating is unit-testable independent of the Interactivity API runtime. The three booleans travel through the per-block `data-wp-context` rather than the shared `state[ mapId ]` slice, because two Elevation blocks bound to the same Map may legitimately disagree about cursor visibility. The Map block's `enableTrackPositionCursor` is unaffected.

Full Step 6 specification (design rationale, the Q1–Q10 grilling outcomes, the no-touch Map-side exception, acceptance criteria, manual verification list): `git show b792e7b:docs/elevation-rebuild.md`.

---

## Step 7: Tooltip

**Goal.** A tooltip is attached to the cursor anchor introduced in Step 6, showing the interpolated distance and elevation at the cursor's fraction. The tooltip is pinned to the top of the plot rectangle and follows the cursor horizontally; when its right edge would clip the plot rectangle, it flips to the left side of the cursor. The two rows — distance on top, elevation below — read from `state[ mapId ].samples` via `interpolateSample` and format locale-aware, deterministically, with the same unit choice the x-axis ticks already use. Visibility is gated by two per-block flags (`tooltipShowDistance`, `tooltipShowHeight`) and by the Step 6 follow-up `showCursor` master: when both row toggles are off, the tooltip is not rendered; when the cursor master is off, the tooltip is not rendered and its entire inspector surface (rows in *Tooltip info*, three Color rows, two Typography panels) is hidden. The tooltip is the final visual element of the Elevation block — after this step, Step 8 is the migration follow-up on Map.

**Load list:** `docs/blocks.md`, `docs/architecture.md` (Interactivity store, cross-block sync).

**v0.12.0 references:** `src/blocks/elevation/cursor.ts` and `src/blocks/elevation/mount.ts` at the `v0.12.0` tag carried the tooltip alongside the cursor in a single update path. The text-formatting branch is the most portable piece: v0.12.0 already split distance and elevation through dedicated formatters with deterministic m/km thresholds and locale-aware separator handling, and the rebuild's `geometry/format.ts` reuses that shape. Per-fraction text-content writes guarded by `textContent !==` equality checks are also worth porting; they keep the DOM quiet between identical updates and let Safari skip layout invalidations on every pointermove. **What does *not* port over:** v0.12.0's tooltip was an HTML overlay anchored to the wrapper via `style.left` percentages, justified solely by issue #135's wrapper-as-image non-uniform stretch (the same motivation as the v0.12.0 cursor overlay; see Step 6's *v0.12.0 references*). The rebuild's 1:1 viewBox-to-CSS-pixels mapping (Step 3) makes the overlay's *reason* go away, and the cursor already lives inside the SVG (Step 6). Putting the tooltip back inside the SVG as a sibling `<g>` makes it share the chart's coordinate system with cursor and curve, lets it project through the same `ChartScale`, and keeps the rebuild's "one renderer per chart" invariant intact. The v0.12.0 `tooltip.offsetWidth`-based clamping is also out — it measured the HTML element's intrinsic width after layout, which is a DOM-and-layout round-trip per move; the SVG approach measures `<text>` once per `drawChart` via `getBBox()` and reuses the result across every subsequent fraction write.

### Design rationale (locked by the Step 7 grilling)

**SVG `<g>` inside the chart SVG, not HTML overlay (Q1).** The tooltip lives in `<g class="kntnt-gpx-blocks-elevation-tooltip">` as a sibling of the cursor `<g>` introduced in Step 6, appended last so SVG painting order puts it on top of every other group. Rejected alternatives:

- *HTML `<div>` absolute-positioned over the wrapper.* Easier to style with CSS `border-radius` / `box-shadow` / native padding, but reintroduces the parallel-pipeline problem that v0.12.0 carried: positioning requires translating SVG coordinates to wrapper layout pixels every frame, doubles the redraw paths (one for chart, one for overlay), and breaks the rebuild's "one renderer per chart" invariant established in Step 3. The CSS-styling ergonomics are not worth restoring v0.12.0's coordinate-system divergence.
- *SVG `<foreignObject>` with HTML inside.* Combines the worst of both: still inside the SVG (so projection works) but with foreignObject's well-known layout glitches across browsers (Safari measurement quirks, Chrome paint-order surprises on transforms) and reduced `<text>`-measurer reuse.

The tooltip's two rows are short formatted numbers with no wrapping needed; `<text>` is sufficient, `<rect>` provides the background, and CSS `border-radius` / corner rounding is expressed as `rx="0.25em"` on the rect. `box-shadow` is not in scope for Step 7 — adding it later as a `tooltipShadow` attribute is a separate, additive change.

**Top-pinned vertical anchor with horizontal-only flip, not Floating UI flip+shift (Q2).** The tooltip's `y` is constant at `wTop + 0.5em` (the top of the plot rectangle plus 0.5em luft); only `x` changes with the cursor. Rejected alternatives:

- *Centred on cursor's `cy` (the natural Floating-UI default).* The cursor bobs vertically as it follows the curve; on a hilly track the tooltip would jiggle vertically through tens of pixels during a slow scrub, forcing the eye to re-acquire the read target on every movement. Elevation tooltips are **read sensors**, not interactive workspaces — stability wins over physical attachment to the dot.
- *Floating UI's `flip()` + `shift()` middleware.* Industry standard for general 2D placement (popovers, marker tooltips), but solves a problem we don't have: our scrub is one-dimensional along the x-axis, so the y-degrees-of-freedom that flip+shift handles are pure cost without benefit. Floating UI also re-anchors the tooltip near container edges via shift, producing a second discontinuity on top of the right-edge flip — three jumps per gesture instead of one.
- *Bottom-pinned (anchored to `plotBottom − tooltipBox.h`).* Symmetric mirror of the top-pinned choice; loses to it because the curve's most-common cursor positions are below the top of the chart, putting the tooltip far from the dot. The top is the area users least often have cursor positions in (a track's elevation rarely peaks at exactly the max-elevation tick), so parking the tooltip there minimises visual overlap with the dot.

Branch precedent: Strava, Garmin Connect, Komoot, Ride with GPS, Trailforks, and Wikiloc all pin elevation-profile tooltips to the top of the plot. The 1D-scrub use case has a well-established UX answer; Step 7 follows it.

**Distance via `chooseXUnit`, elevation always in metres (Q3).** Line 1 reuses the same `chooseXUnit( distance )` function the x-axis already uses, so the tooltip's distance unit and the x-axis tick labels' unit always agree — no "axis says 5.2 km, tooltip says 5234 m" inconsistency. Line 2 is **always in metres**, never in km. Rationale: GPX elevation values are typically 0–4 000 m; users mentally compute elevations in metres even when a peak is at 2 800 m. The spec's "with unit ` m` or ` km`" wording for line 2 reads literally as a request for symmetric m/km switching, but switching to km on high tracks (e.g. a Pyrenees stage above 2 000 m would show `2.8 km`) is actively confusing and breaks the universal convention in cycling and hiking apps. The deliberate departure from the spec's wording is documented here so future readers don't reintroduce it.

Decimal precision: 0 decimals in metres, 1 decimal in km, for both lines (only relevant to line 1 in practice). Locale-formatted via the same `Intl.NumberFormat` pipeline as `format.ts` (`sv-SE` → `5,2 km`, `en-US` → `5.2 km`). No prefix labels on the rows — values only, with the `m` / `km` unit appended to the formatted number with a single space (e.g. `"5,2 km"`, `"247 m"`). Rationale: typography styling is per-row (eight attributes per row), not per-token, so a `"Distance:"` prefix would inherit the same styling as the value and read as part of the number; the rows are scannable in milliseconds when values stand alone; Strava / Garmin do the same.

**Two `<text>` elements, left-aligned, bbox-driven stacking (Q4).** Two separate `<text>` nodes — one with class `kntnt-gpx-blocks-elevation-tooltip-distance`, one with class `kntnt-gpx-blocks-elevation-tooltip-height` — give each row its own scoped CSS rule and its own measurer call. Rejected alternatives:

- *One `<text>` with two `<tspan>`.* In SVG, `<tspan>` inherits font from its `<text>` parent, but each `<tspan>` must override every typography property explicitly for the two rows to render with different fonts/sizes/weights. The styling-wiring becomes per-tspan inline declarations and breaks Step 4 pl.7's "CSS custom properties on the wrapper, SCSS rules on the SVG host, no inline style" invariant.
- *Three or more `<text>` (e.g. unit suffixes as separate spans for alignment).* Overkill; the unit is part of the value's typography by design (Strava precedent).

The two `<text>` elements use `text-anchor="start"` so both rows align to a common left edge. Stacking is bbox-driven: rad 1's `y` is `padTop + bboxRow1.height` (baseline-positioned), rad 2's `y` is rad 1's `y + lineGap + bboxRow2.height`. The `<rect>` height is `padTop + bboxRow1.height + lineGap + bboxRow2.height + padBottom`. When only one row is visible (one of the row toggles is off), the visible row is centred vertically in the now-shorter `<rect>`.

`padX = padY = 0.5em`, `lineGap = 0.25em`. The `<rect>` has `rx="0.25em"` for soft corners and no `stroke`, no `box-shadow` (Step 7 scope). `pointer-events="none"` on the `<g>` itself so the tooltip never blocks the hit-rect underneath.

**Tooltip `<g>` is a sibling of the cursor `<g>` (Q6a).** Each visual concern owns one top-level `<g>` under the SVG host. The same pattern as Step 3-5 (axes, plot-fill, plot-line, tick-marks, tick-labels) and Step 6 (cursor); Step 7 adds *tooltip* as the ninth sibling. The alternative — tooltip as a child *inside* cursor `<g>` — would couple two concerns (free hide-on-cursor-hide via SVG `display` inheritance) at the cost of test-isolation and per-concern lifecycle. Sibling layout preserves SRP at the DOM level and is consistent with the rest of the chart.

**Hide-on-master-off coupling to `showCursor` (Q6b).** When the Step 6 follow-up master toggle `showCursor` is off, the tooltip is functionally disabled (no fraction is being written by this Elevation block, the cursor `<g>` is not in the DOM at all) and all tooltip-related inspector surface is hidden: the *Tooltip info* PanelBody disappears from the Settings tab; the three Color rows (*Tooltip background*, *Tooltip distance*, *Tooltip height*) disappear from `elevationColorRows()`'s output; both Typography PanelBodys (*Tooltip distance*, *Tooltip height*) disappear from the Styles tab. The two row toggles `tooltipShowDistance` and `tooltipShowHeight` remain at their stored values; turning `showCursor` back on restores every panel with its previous configuration. The `showCursor` master is itself in the always-visible *Cursor & guides* PanelBody from Step 6 FU, so the user is never locked out of re-enabling.

**No explicit `showTooltip` master toggle (Q6c).** The spec defines "both Show distance and Show height off → no tooltip rendered" as the visibility floor; that *is* the implicit master. Introducing a third toggle would add an attribute to block.json (pushing the attribute count from 36 to 37), add UI weight in the inspector, and offer no behaviour the implicit floor doesn't already give. Map does not have a `showTooltip` toggle either; consistency wins.

**Per-block `data-wp-context` routing for the row toggles (Q7a).** `tooltipShowDistance` and `tooltipShowHeight` travel via the wrapper's existing `data-wp-context` object (per-block) rather than via `state[ mapId ]` (per-mapId, shared with Map). Two Elevation blocks bound to the same Map can legitimately disagree about which rows their tooltips show — for instance, one block laid out narrow in a sidebar showing only height, a wider block in the body showing both — and the per-block context is the right vehicle for per-block preferences. Step 6 FU set this exact precedent for `showCursor` / `showVerticalGuide` / `showHorizontalGuide`; Step 7 extends the same context object with two booleans. The shared per-mapId slice gains no new fields in Step 7.

**Cross-block sync is automatic fallout (Q9c).** Map's cursor sync writes `state[ mapId ].fraction` (Step 6), and the same fraction read drives both Elevation's cursor *and* its tooltip (Step 8b's extended watch). No new sync wiring; the tooltip moves whenever a fraction is written, regardless of source (Elevation pointer, Map pointer, programmatic).

**No tooltip in warning states (Q9d).** The five warning reasons (Step 3) replace the chart wrapper's entire contents with a text warning; no SVG is emitted, so no tooltip is emitted either. `render_chart_wrapper()` is the healthy-state code path; `wrap_warning()` is separate. Confirmed automatic, no special-casing.

**Cleanups carried alongside the feature work:**

- `interpolateSample` and `projectCursor` migrate from `geometry/cursor.ts` to a new `geometry/sample-interpolation.ts`. Step 6 placed them in `cursor.ts` because cursor was the only consumer; with tooltip also consuming them, the SRP-cleaner split is one module per concern. The two cursor-only DOM helpers (`createCursorElements` etc.) stay in `cursor.ts`; `geometry/cursor.ts` is deleted. `chart.tsx` and `view.ts` update their imports. Pure refactor with no behaviour change.
- `createTextMeasurer` (introduced in Step 4) gains an optional `className` parameter. Step 4's measurer creates a hidden `<text>` in the SVG host that inherits typography via the wrapper's CSS custom properties; the tick-label measurer relied on inherited values. The tooltip rows have their own typography custom properties (`--kntnt-gpx-blocks-elevation-tooltip-distance-*` and `…-height-*`) consumed by class-scoped SCSS rules, so the measurer needs to render its hidden `<text>` with that class to pick up the right font. `createTextMeasurer( svg, className? )` adds the class to the hidden node when supplied; the existing tick-label call site passes no class and is unchanged.

### Tooltip anatomy and projection (locked by Q4 + Q5 + Q6a + Q9 grilling)

A single SVG `<g>` group plus three children, all inside the chart SVG:

```html
<g class="kntnt-gpx-blocks-elevation-tooltip" pointer-events="none">
    <title>{a11yLabel}</title>
    <rect class="kntnt-gpx-blocks-elevation-tooltip-bg"
          x="{x}" y="{y}"
          width="{w}" height="{h}"
          rx="0.25em"
          fill="var(--kntnt-gpx-blocks-elevation-tooltip-background)"
          display="none"/>
    <text class="kntnt-gpx-blocks-elevation-tooltip-distance"
          x="{x + padX}" y="{distanceBaselineY}"
          text-anchor="start"
          fill="var(--kntnt-gpx-blocks-elevation-tooltip-distance)"
          display="none">{distanceLabel}</text>
    <text class="kntnt-gpx-blocks-elevation-tooltip-height"
          x="{x + padX}" y="{heightBaselineY}"
          text-anchor="start"
          fill="var(--kntnt-gpx-blocks-elevation-tooltip-height)"
          display="none">{heightLabel}</text>
</g>
```

**Insertion order inside the group:** `<title>` first (so SR-readers reach it when the group has focus or hover-traversal); `<rect>` next (so it paints behind the two rows); `<text class="…-tooltip-distance">` third; `<text class="…-tooltip-height">` fourth. The two `<text>` order matches their visual top-to-bottom layout, which keeps document order and visual order in sync for screen readers that fall back to document order.

**Group position in the chart SVG.** Step 6's SVG insertion order grows from nine to ten groups, with **Tooltip `<g>`** appended last after the cursor `<g>`:

1. X axis line.
2. Y axis line.
3. Plot fill `<path>`.
4. Plot line `<path>`.
5. X tick marks `<g>`.
6. Y tick marks `<g>`.
7. X tick labels `<g>`.
8. Y tick labels `<g>`.
9. Cursor `<g>`.
10. **Tooltip `<g>`** *(new)*.

The tooltip therefore paints on top of the cursor when they overlap visually, which only matters in the degenerate case where the cursor is at the very top of the chart and the tooltip's `y = wTop + 0.5em` rectangle covers the dot — rare, harmless (the user can read the values inside the tooltip), and consistent with "tooltip-over-everything-else".

**Conditional rendering of the row `<text>` nodes.** Each row's `<text>` is in the DOM only when its corresponding toggle (`tooltipShowDistance` / `tooltipShowHeight`) is on. Step 6 FU established the precedent for cursor guides; the tooltip follows the same per-element approach (skip the `<text>` entirely rather than render-and-hide via CSS). When both toggles are off, the entire `<g>` is skipped — no `<rect>`, no `<title>`, nothing in the DOM.

**Visibility via SVG `display` attribute.** Initially the `<rect>` and both `<text>` carry `display="none"`. `applyTooltipPosition` removes the attribute on the rect, on each visible row's `<text>`, and on the `<title>` content; `hideTooltip` re-applies it. Same idiom as the cursor (Step 6) — SVG attribute over CSS rule, since the attribute sits next to every other geometry attribute in the same imperative-DOM API and can't be overridden by stray editor-iframe stylesheets.

**Accessibility — `<title>` with the read values.** The `<title>` child carries a translated string built from the visible rows: `"Distance 5,2 km, elevation 247 m"` when both rows are visible, `"Distance 5,2 km"` when only line 1, `"Elevation 247 m"` when only line 2. The string is rebuilt on every fraction update so a SR-user who triggers the title (via hover-traversal or programmatic focus) gets a fresh value. No `aria-live` — the title is "available on demand", not announced on every pointermove; otherwise the SR would spam during a scrub. The chart's outer `role="img"` + `aria-label` (Step 3) remains the high-level entry; the tooltip `<title>` is a deeper read for users who navigate into the chart.

### Placement algorithm with horizontal hysteresis (locked by Q5 + Q10a grilling)

Pure helper `computeTooltipPlacement` in `geometry/tooltip-placement.ts`, no DOM:

```ts
export interface TooltipPlacementInput {
    readonly cursor: { readonly cx: number };
    readonly plotRect: {
        readonly x: number;
        readonly y: number;
        readonly w: number;
        readonly h: number;
    };
    readonly tooltipBox: {
        readonly w: number;
        readonly h: number;
    };
    readonly em: number;
    readonly previousSide: 'right' | 'left' | null;
}

export interface TooltipPlacementOutput {
    readonly x: number;
    readonly y: number;
    readonly side: 'right' | 'left';
}

export function computeTooltipPlacement(
    input: TooltipPlacementInput,
): TooltipPlacementOutput {
    const gap = 0.5 * input.em;
    const padTop = 0.5 * input.em;
    const padRight = 0.5 * input.em;
    const hysteresis = 0.5 * input.em;

    const y = input.plotRect.y + padTop;
    const xRight = input.cursor.cx + gap;
    const xLeft = input.cursor.cx - gap - input.tooltipBox.w;

    const plotRight = input.plotRect.x + input.plotRect.w;
    const rightOverflowAt = plotRight - padRight - input.tooltipBox.w;

    const side: 'right' | 'left' =
        input.previousSide === 'left'
            ? xRight <= rightOverflowAt - hysteresis ? 'right' : 'left'
            : xRight > rightOverflowAt ? 'left' : 'right';

    return {
        x: side === 'right' ? xRight : xLeft,
        y,
        side,
    };
}
```

**Cursor's `cy` is not an input.** The tooltip is top-pinned (Alt. D — see *Design rationale* above), so the only cursor coordinate the algorithm consumes is `cx`. Reducing the input surface to one cursor field documents the choice in the type system.

**Container is the plot rectangle, not the wrapper.** `plotRect` is the same `{ x: wLeft, y: wTop, w: plotW, h: plotH }` rectangle Steps 3-6 compute. Using the plot rectangle means the tooltip never visually overlaps the axis-label margins (the rightmost x-tick label, the topmost y-tick label area), at the cost of flipping slightly earlier on narrow charts. The trade is worth it — overlap with tick labels is uglier than an earlier flip, and on narrow charts the tooltip would be near the edges anyway.

**Hysteresis prevents flip oscillation at the threshold.** Without hysteresis, a cursor sitting at exactly the overflow boundary while the user wiggles the pointer ±1px would flicker the tooltip back and forth between sides every frame. With a 0.5em hysteresis band, the tooltip stays on its current side until the cursor has clearly committed to the other side: on the right, it flips to left at the standard `xRight > rightOverflowAt` threshold; once on the left, it flips back to right only when the cursor has moved 0.5em past the threshold in the opposite direction (`xRight <= rightOverflowAt - hysteresis`). The asymmetric direction matches users' physical scrubbing motion: a deliberate movement crosses 0.5em easily; a wiggle does not.

**`previousSide` is out-of-band state.** The pure helper takes the previous side as input rather than maintaining its own state, so the function stays trivially testable (deterministic in / out). The state lives in the `ElevationEntry` on the frontend (`tooltipSide: 'right' | 'left' | null`), updated after each call. The editor preview passes `previousSide: null` since `chart.tsx` renders a single static frame at fraction = 0.5 — there's no previous frame to remember.

**No vertical shift, no vertical flip.** Top-pinned means the tooltip's `y` is always `plotRect.y + 0.5em`; nothing can push it outside the plot rectangle vertically because it's already at the top with 0.5em luft. The Floating-UI-style `shift()` middleware that handles perpendicular-axis overflow is not part of this algorithm. If a future feature changes the vertical anchor (e.g. "follow cursor vertically with shift"), `computeTooltipPlacement` is the place to add it; today it's deliberately absent.

### Editor preview tooltip at fixed fraction = 0.5 (locked by Q5f grilling)

`chart.tsx` (the React preview) renders the tooltip at the same midpoint where Step 6's cursor previews:

```tsx
const previewFraction = 0.5;
const sample = interpolateSample(
    samples,
    statistics.distance * previewFraction,
);
const projected = sample !== null ? projectCursor( sample, scale ) : null;
const tooltipBox = projected !== null
    ? measureTooltipBox( /* ... see Measurement strategy below ... */ )
    : null;
const placement = tooltipBox !== null && projected !== null
    ? computeTooltipPlacement( {
        cursor: { cx: projected.cx },
        plotRect: scale.plotRect,
        tooltipBox,
        em: scale.em,
        previousSide: null,
    } )
    : null;

// ... after the cursor JSX:
{ placement !== null && (
    <g className="kntnt-gpx-blocks-elevation-tooltip" pointerEvents="none">
        <title>{ a11yLabel }</title>
        <rect className="kntnt-gpx-blocks-elevation-tooltip-bg"
              x={ placement.x } y={ placement.y }
              width={ tooltipBox.w } height={ tooltipBox.h }
              rx="0.25em"
              fill="var(--kntnt-gpx-blocks-elevation-tooltip-background)" />
        { showDistance && (
            <text className="kntnt-gpx-blocks-elevation-tooltip-distance"
                  x={ placement.x + padX } y={ distanceBaselineY }
                  textAnchor="start"
                  fill="var(--kntnt-gpx-blocks-elevation-tooltip-distance)">
                { distanceLabel }
            </text>
        ) }
        { showHeight && (
            <text className="kntnt-gpx-blocks-elevation-tooltip-height"
                  x={ placement.x + padX } y={ heightBaselineY }
                  textAnchor="start"
                  fill="var(--kntnt-gpx-blocks-elevation-tooltip-height)">
                { heightLabel }
            </text>
        ) }
    </g>
) }
```

In editor mode the tooltip is *always* visible at the midpoint (no `display="none"`) — same principle as Step 6's editor cursor. There's no Interactivity-driven fraction to hide it from; its purpose is to give the inspector's color and typography controls a live preview target. `previousSide = null` since `chart.tsx` renders a single static frame.

When `samples.length < 2` (defensive — the chart wouldn't render in this case anyway because `Render_Elevation` emits the `zero-distance` or `no-elevation-data` warning), `interpolateSample` returns `null` and the entire `<g>` is conditionally skipped. When both row toggles are off (`tooltipShowDistance === false && tooltipShowHeight === false`), `chart.tsx` also skips the `<g>` so the editor preview matches the frontend.

Measurement in the editor uses the same augmented `createTextMeasurer( svg, className )` helper as the frontend; `useLayoutEffect` measures the two rows, computes the box, computes the placement, and stores in React state for the render pass. A change to any of the inspector's tooltip-typography or tooltip-color attributes triggers a re-render through the existing React data flow, which re-runs the effect and re-measures.

### Measurement strategy (locked by Q10b grilling)

`createTextMeasurer` gains an optional second parameter:

```ts
export function createTextMeasurer(
    svg: SVGSVGElement,
    className?: string,
): ( text: string ) => TextMeasurement;
```

When `className` is supplied, the hidden `<text>` node the measurer creates inside the SVG receives the class. SCSS rules scoped to that class apply (e.g. `font-family: var(--kntnt-gpx-blocks-elevation-tooltip-distance-font-family)`), and the measurer's `getBBox()` returns dimensions that reflect the same typography the rendered row will use.

The tooltip-rendering pipeline instantiates three measurer functions per `drawChart`:

```ts
const measureDistance = createTextMeasurer(
    svg, 'kntnt-gpx-blocks-elevation-tooltip-distance',
);
const measureHeight = createTextMeasurer(
    svg, 'kntnt-gpx-blocks-elevation-tooltip-height',
);
// existing tick-label measurer (no class) is unchanged
```

Distance and height row widths are measured independently:

```ts
const distanceBBox = showDistance ? measureDistance( distanceLabel ) : null;
const heightBBox = showHeight ? measureHeight( heightLabel ) : null;
```

The `<rect>` dimensions follow from the bboxes plus padding/lineGap:

```ts
const rowsHeight =
    ( distanceBBox?.height ?? 0 )
    + ( heightBBox?.height ?? 0 )
    + ( distanceBBox !== null && heightBBox !== null ? lineGap : 0 );
const rectWidth =
    Math.max( distanceBBox?.width ?? 0, heightBBox?.width ?? 0 )
    + 2 * padX;
const rectHeight = rowsHeight + 2 * padY;
```

Single-row tooltips (only distance or only height enabled) shrink the `<rect>` proportionally and the visible row is vertically centred in the shrunken rect (`baselineY = rectY + rectHeight / 2 + bbox.height / 2 - bbox.descent`, computed against the bbox so the baseline accounts for descenders).

**Why class-scoped measurers, not inline-style measurers.** Setting `style.font` inline on the hidden `<text>` would also produce correct measurements, but it bypasses the wrapper-CSS-variable → SVG-host-CSS-rule → `<text>`-inheritance chain that Step 4 pl.7 locked. Inline styles re-introduce the divergence pl.3 introduced and pl.7 reverted; class-scoped measurers reuse the exact same rules the rendered rows will use, with no additional sources of truth.

### Lifecycle and watch extension (locked by Q8 grilling)

**Persistent tooltip elements, same idiom as Step 6's cursor.** `createTooltipElements( svg )` runs once during the first `drawChart` for a wrapper and stores the element references on the `ElevationEntry`. The `view.ts` `removeMatching` selector list gains `.kntnt-gpx-blocks-elevation-tooltip` to the exception list, so resize / font-load / data-change redraws do not destroy the tooltip group. Subsequent updates flow through `applyTooltipPosition( elements, layout )` and `hideTooltip( elements )` writing geometry attributes and `textContent` in place.

```ts
interface ElevationEntry {
    // ...existing fields from Step 6...
    readonly tooltipElements: TooltipElements | null;
    tooltipSide: 'right' | 'left' | null;
}

interface TooltipElements {
    readonly group: SVGGElement;
    readonly title: SVGTitleElement;
    readonly rect: SVGRectElement;
    readonly distance: SVGTextElement | null;
    readonly height: SVGTextElement | null;
}
```

`tooltipElements` is `null` when both row toggles are off at mount time (no tooltip ever rendered for this block); the `<g>` is not created. `distance` and `height` are nullable to reflect the per-row toggle: when `tooltipShowDistance === false`, `distance` is `null` and no `<text>` for that row exists in the DOM.

**Mount sequence inside the first `drawChart`.** After Step 6's cursor mount lands, Step 7 inserts the tooltip mount immediately after:

```ts
// Step 6 cursor mount...
const cursorElements = createCursorElements( svg, scale );

// Step 7 tooltip mount (if at least one row is enabled).
const showDistance = context.tooltipShowDistance === true;
const showHeight = context.tooltipShowHeight === true;
const tooltipElements = ( showDistance || showHeight )
    ? createTooltipElements( svg, { showDistance, showHeight } )
    : null;
```

The decision to mount-or-not is made once at mount; runtime toggles of the row flags do not add or remove the tooltip group on the live frontend (the Interactivity API does not re-emit the chart). If the user wants to change tooltip visibility, they republish the post — the same constraint applies to every other server-emitted attribute. Editor preview re-renders on every inspector change (React data flow), so editor users see immediate feedback.

**Extended `onElevationCursorChange` watch.** The Step 6 watch reads `state[ mapId ].fraction` first (Interactivity subscription idiom), looks up the entry, and applies the cursor. Step 7 extends the same callback to drive both the cursor and the tooltip from the same fraction snapshot. The name is kept (`onElevationCursorChange`) to minimise rename churn; a JSDoc comment documents the extended scope ("Updates both the cursor and the tooltip from the current fraction snapshot, so they cannot drift apart between frames.").

```ts
function onElevationCursorChange(): void {
    const fraction = state[ mapId ].fraction;
    const entry = mountedElevations.get( wrapper );
    if ( entry === undefined ) {
        return;
    }
    if ( fraction === null ) {
        hideCursor( entry.cursorElements );
        if ( entry.tooltipElements !== null ) {
            hideTooltip( entry.tooltipElements );
        }
        return;
    }
    const sample = interpolateSample(
        entry.samples, fraction * entry.distance,
    );
    if ( sample === null ) {
        return;
    }
    const projected = projectCursor( sample, entry.scale );
    applyCursorPosition( entry.cursorElements, projected );
    if ( entry.tooltipElements !== null ) {
        applyTooltipFromSample( entry, sample, projected );
    }
}
```

`applyTooltipFromSample` is the per-frame tooltip update: format the two labels (only the ones whose toggles are on), measure the row(s) (cheap — same hidden `<text>` infrastructure as `drawChart`, but `getBBox` is constant-time on already-rendered nodes), compute the placement against `entry.tooltipSide`, update `<rect>` geometry, update `<text>` `textContent` (guarded by `!==`), update `<title>`, store `entry.tooltipSide = placement.side`, remove `display="none"` from the visible elements.

**Reading per-row toggles inside the watch.** `tooltipShowDistance` / `tooltipShowHeight` live in `data-wp-context`, not in `state[ mapId ]`. The watch runs in the Interactivity API's context-aware environment, so reading them is `getContext()` rather than `state[…]`. Mount decided whether to create per-row `<text>` nodes; the watch simply uses whichever nodes exist (the `null`-checks on `entry.tooltipElements.distance` / `.height` cover the case).

**No tooltip-input file.** Unlike `cursor-input.ts` (Step 6), the tooltip has no pointer handlers of its own. The `pointer-events="none"` on the tooltip `<g>` means the hit-rect underneath sees every pointer event; the cursor-input pipeline is unchanged.

### Tooltip colour and typography wiring (locked by Q4 + Q7e grilling)

**Three new colour custom properties** (Step 1 declared the block attributes; Step 7 wires them through):

- `--kntnt-gpx-blocks-elevation-tooltip-background` ← `tooltipBackgroundColor`, SCSS default `#000000cc`.
- `--kntnt-gpx-blocks-elevation-tooltip-distance` ← `tooltipDistanceColor`, SCSS default `#ffffff`.
- `--kntnt-gpx-blocks-elevation-tooltip-height` ← `tooltipHeightColor`, SCSS default `#dddddd`.

Defaults match Map's `--kntnt-gpx-blocks-tooltip-bg` / `--kntnt-gpx-blocks-tooltip-name-color` / `--kntnt-gpx-blocks-tooltip-desc-color` exactly so two synced tooltips (a future feature, not part of Step 7) would read as visually consistent. Map's typography defaults of weight 700 on row 1 and italic on row 2 are *not* mirrored on Elevation; row 1 and row 2 are numeric values, and italic on numerals is unusual (Strava does not bold or italic its read-out values).

**Sixteen new typography custom properties** (eight per row):

```
--kntnt-gpx-blocks-elevation-tooltip-distance-font-family
--kntnt-gpx-blocks-elevation-tooltip-distance-font-size
--kntnt-gpx-blocks-elevation-tooltip-distance-font-weight
--kntnt-gpx-blocks-elevation-tooltip-distance-font-style
--kntnt-gpx-blocks-elevation-tooltip-distance-line-height
--kntnt-gpx-blocks-elevation-tooltip-distance-letter-spacing
--kntnt-gpx-blocks-elevation-tooltip-distance-text-decoration
--kntnt-gpx-blocks-elevation-tooltip-distance-text-transform
--kntnt-gpx-blocks-elevation-tooltip-height-font-family
--kntnt-gpx-blocks-elevation-tooltip-height-font-size
--kntnt-gpx-blocks-elevation-tooltip-height-font-weight
--kntnt-gpx-blocks-elevation-tooltip-height-font-style
--kntnt-gpx-blocks-elevation-tooltip-height-line-height
--kntnt-gpx-blocks-elevation-tooltip-height-letter-spacing
--kntnt-gpx-blocks-elevation-tooltip-height-text-decoration
--kntnt-gpx-blocks-elevation-tooltip-height-text-transform
```

All sixteen default to `inherit` (Step 4 pl.1 convention; the wrapper's own typography flows through). SCSS rules consume them on `.kntnt-gpx-blocks-elevation-tooltip-distance` and `.kntnt-gpx-blocks-elevation-tooltip-height` selectors (Step 4 pl.7 architecture: custom properties on the wrapper, SCSS rules on classed SVG-host descendants, no inline styles).

**Three sites to wire** (mirroring Step 6's wiring for `cursorColor`):

- **`src/blocks/elevation/edit.tsx`** — extends the inline-style builder with `usefulValue`-wrapped emission for each of the 19 new properties (3 colour + 16 typography). Pattern parallels `axisLabelColor` (Step 4) and `plotFillColor` (Step 5).
- **`classes/Rendering/Render_Elevation::build_inline_style()`** — emits the same 19 properties server-side. Colours use `Color_Sanitizer::sanitize`; typography uses the existing `Typography_Sanitizer` family (`font_family`, `font_size`, `font_weight`, `font_style`, `line_height`, `letter_spacing`, `text_decoration`, `text_transform`).
- **`src/blocks/elevation/style.scss`** — declares all 19 defaults and the SCSS rules that map the custom properties to the actual CSS properties on the two `<text>` selectors and the `<rect>` selector.

### Inspector hide-on-master-off (locked by Q9a Alt II grilling)

When `showCursor === false`, the following inspector surface is hidden (in addition to Step 6 FU's existing cursor-guide controls):

- **Settings tab → *Tooltip info* PanelBody** (the two row toggles `tooltipShowDistance` and `tooltipShowHeight`). Removed entirely from the panel list, not just collapsed.
- **Settings tab → Color panel → three rows** (`Tooltip background`, `Tooltip distance`, `Tooltip height`). `elevationColorRows()` filters them out when `showCursor === false`.
- **Styles tab → *Tooltip distance* PanelBody** and **Styles tab → *Tooltip height* PanelBody**. Both Typography panels removed from the panel list.

When `showCursor` flips back to `true`, the three surfaces reappear with their previously stored values intact. The `showCursor` toggle itself lives in the always-visible *Cursor & guides* PanelBody from Step 6 FU; the user cannot lock themselves out of re-enabling.

The implementation extends Step 6 FU's existing `showCursor`-gated conditional rendering in `edit.tsx`. No new component; the JSX block for the tooltip-related panels lives inside the same `{ showCursor && ( ... ) }` conditional that already wraps the cursor color row.

### Server-side changes (locked by Q9e + Q7e grilling)

**`Render_Elevation::render_chart_wrapper()`** extends `data-wp-context` with two booleans:

```php
$context = [
    'showCursor'          => (bool) ( $attributes['showCursor'] ?? true ),
    'showVerticalGuide'   => (bool) ( $attributes['showVerticalGuide'] ?? true ),
    'showHorizontalGuide' => (bool) ( $attributes['showHorizontalGuide'] ?? false ),
    'tooltipShowDistance' => (bool) ( $attributes['tooltipShowDistance'] ?? true ),
    'tooltipShowHeight'   => (bool) ( $attributes['tooltipShowHeight'] ?? true ),
];
```

No new `data-wp-watch--*` directive; the existing `data-wp-watch--cursor="callbacks.onElevationCursorChange"` already drives the tooltip via the extended watch callback.

**`Render_Elevation::build_inline_style()`** appends the 19 tooltip custom properties (3 colours + 16 typography) using the same `Color_Sanitizer` / `Typography_Sanitizer` family Step 4 and Step 5 established. Empty / invalid values are omitted (sanitizer returns empty string → no property in output → SCSS default kicks in).

**`wp_interactivity_state()` payload is unchanged.** No new fields on `state[ mapId ]`. The per-mapId slice still has `samples`, `statistics`, `fraction` as the only three keys.

### Module structure (locked by Q7d + Q10 grilling + Step 6 patterns)

Three new TypeScript files plus modifications to existing files.

**New files:**

```
src/blocks/elevation/geometry/sample-interpolation.ts
src/blocks/elevation/geometry/sample-interpolation.test.ts
src/blocks/elevation/geometry/tooltip-placement.ts
src/blocks/elevation/geometry/tooltip-placement.test.ts
src/blocks/elevation/geometry/tooltip-format.ts
src/blocks/elevation/geometry/tooltip-format.test.ts
src/blocks/elevation/tooltip.ts
src/blocks/elevation/tooltip.test.ts
```

**`geometry/sample-interpolation.ts`** — exports `interpolateSample` and `projectCursor`, moved from `geometry/cursor.ts`. Identical behaviour, identical signatures; only the module location changes. `geometry/cursor.ts` is deleted, its tests fold into `sample-interpolation.test.ts` verbatim.

**`geometry/tooltip-placement.ts`** — pure math, no DOM. The full pseudocode in *Placement algorithm* above is the entire contents.

**`geometry/tooltip-format.ts`** — pure formatting. Two exports:

```ts
export function formatDistance(
    distance: number,
    locale: string,
): string;

export function formatElevation(
    elevation: number,
    locale: string,
): string;
```

`formatDistance` reuses `chooseXUnit( distance )` from `geometry/format.ts`: when `distance < 2000`, return `"${integer} m"` (locale-formatted); otherwise return `"${oneDecimal} km"`. `formatElevation` returns `"${integer} m"` always (no km switching, per Q3 decision). Both use `Intl.NumberFormat` with the supplied locale and the digit-group separator + decimal separator the locale dictates (sv-SE → `5,2 km` / `247 m`; en-US → `5.2 km` / `247 m`).

**`tooltip.ts`** — SVG DOM helpers, JSDOM-testable. Exports:

```ts
export interface TooltipElements {
    readonly group: SVGGElement;
    readonly title: SVGTitleElement;
    readonly rect: SVGRectElement;
    readonly distance: SVGTextElement | null;
    readonly height: SVGTextElement | null;
}

export interface TooltipLayout {
    readonly rectX: number;
    readonly rectY: number;
    readonly rectWidth: number;
    readonly rectHeight: number;
    readonly distanceTextX: number;
    readonly distanceTextY: number;
    readonly heightTextX: number;
    readonly heightTextY: number;
    readonly distanceLabel: string;
    readonly heightLabel: string;
    readonly a11yLabel: string;
}

export interface TooltipCreateOptions {
    readonly showDistance: boolean;
    readonly showHeight: boolean;
}

export function createTooltipElements(
    svg: SVGSVGElement,
    options: TooltipCreateOptions,
): TooltipElements;

export function applyTooltipPosition(
    elements: TooltipElements,
    layout: TooltipLayout,
): void;

export function hideTooltip( elements: TooltipElements ): void;
```

`createTooltipElements` builds the `<g>` plus the `<title>`, `<rect>`, and (conditionally) one or two `<text>` nodes, sets `display="none"` on the visibles, returns references. `applyTooltipPosition` writes geometry attributes on the `<rect>`, position on each `<text>`, `textContent` on each `<text>` and on `<title>` (each guarded by `!==`), and removes `display="none"`. `hideTooltip` re-applies `display="none"` to `<rect>` and both `<text>` nodes.

**Modified files:**

- `src/blocks/elevation/view.ts` — `initElevation` extends to mount tooltip elements; the watch callback `onElevationCursorChange` extends to update both cursor and tooltip from the same fraction snapshot; the local `applyTooltipFromSample( entry, sample, projected )` composes the per-frame format + measure + place + apply pipeline.
- `src/blocks/elevation/chart.tsx` — adds the static tooltip JSX after the cursor JSX; `useLayoutEffect` measures the rows and stores the box and placement in React state.
- `src/blocks/elevation/chart.test.tsx` — extended with tooltip-rendering assertions, including per-row visibility, placement (right/left/centered single-row), and reactive updates to inspector changes.
- `src/blocks/elevation/edit.tsx` — wires the 19 tooltip custom properties into the inline style on the wrapper; conditionally hides the *Tooltip info* PanelBody and the two Typography panels when `showCursor === false`.
- `src/blocks/elevation/inspector-color.tsx` — `elevationColorRows()` filters out the three tooltip color rows when `showCursor === false`.
- `src/blocks/elevation/style.scss` — declares 19 new custom-property defaults; SCSS rules on `.kntnt-gpx-blocks-elevation-tooltip-distance` and `.kntnt-gpx-blocks-elevation-tooltip-height` map the typography properties; an SCSS rule on `.kntnt-gpx-blocks-elevation-tooltip-bg` and `.kntnt-gpx-blocks-elevation-tooltip` (or the `<g>` selector) maps the background and `pointer-events: none`.
- `src/blocks/elevation/style.test.ts` — extended with assertions for the 19 SCSS contracts and the `pointer-events` rule.
- `src/blocks/elevation/geometry/measure.ts` — `createTextMeasurer` gains the optional `className` second parameter; existing call sites with no className are unchanged.
- `src/blocks/elevation/geometry/measure.test.ts` — extended with assertions covering the className path (hidden `<text>` carries the class; measurement reflects class-scoped CSS).
- `classes/Rendering/Render_Elevation.php` — `build_inline_style()` gains 19 emissions for the tooltip properties; `render_chart_wrapper()` gains `tooltipShowDistance` and `tooltipShowHeight` in the `data-wp-context` JSON.
- `tests/Unit/Rendering/Render_ElevationTest.php` — extended with assertions for each of the 19 wirings (empty input → no property; valid input → sanitized property; invalid input → no property) and the two new context keys.
- `src/blocks/elevation/geometry/cursor.ts` and `src/blocks/elevation/geometry/cursor.test.ts` — **deleted** (contents moved to `geometry/sample-interpolation.ts` / `.test.ts`).

### File layout for Step 7

```
src/blocks/elevation/
├── view.ts                                      — modified: tooltip mount + extended watch
├── chart.tsx                                    — modified: static tooltip at fraction = 0.5
├── chart.test.tsx                               — modified: tooltip JSX assertions
├── edit.tsx                                     — modified: 19 inline custom-property wirings; hide-on-master-off
├── inspector-color.tsx                          — modified: hide three tooltip rows when showCursor is off
├── style.scss                                   — modified: 19 tooltip defaults + SCSS rules + pointer-events
├── style.test.ts                                — modified: 19 SCSS contract pins
├── tooltip.ts                                   — NEW: SVG DOM helpers
├── tooltip.test.ts                              — NEW
└── geometry/
    ├── cursor.ts                                — DELETED (moved to sample-interpolation.ts)
    ├── cursor.test.ts                           — DELETED (moved to sample-interpolation.test.ts)
    ├── sample-interpolation.ts                  — NEW: interpolateSample + projectCursor
    ├── sample-interpolation.test.ts             — NEW
    ├── tooltip-placement.ts                     — NEW: computeTooltipPlacement with hysteresis
    ├── tooltip-placement.test.ts                — NEW
    ├── tooltip-format.ts                        — NEW: formatDistance + formatElevation
    ├── tooltip-format.test.ts                   — NEW
    ├── measure.ts                               — modified: optional className parameter
    └── measure.test.ts                          — modified: className-path assertions

classes/Rendering/
└── Render_Elevation.php                         — modified: 19 inline-style emissions; 2 context keys

tests/Unit/Rendering/
└── Render_ElevationTest.php                     — modified: 19 wiring assertions; 2 context-key assertions
```

The split mirrors Step 6's seam (pure math under `geometry/`, SVG DOM helpers at the package root, no input file because the tooltip is `pointer-events: none`). Each new file is independently testable: the three `geometry/` files need no DOM, `tooltip.ts` needs only JSDOM.

### Test-driven development

Write the helper-level tests first, watch them go red, implement until green:

- **`src/blocks/elevation/geometry/sample-interpolation.test.ts`** — the existing `geometry/cursor.test.ts` cases moved verbatim. No behavioural change.
- **`src/blocks/elevation/geometry/tooltip-placement.test.ts`** — pure math:
  - With cursor near plot-left, `previousSide: null`: returns `side: 'right'`, `x = cursor.cx + 0.5em`.
  - With cursor near plot-right enough that `xRight + tooltipBox.w > plotRect.right - 0.5em`, `previousSide: null`: returns `side: 'left'`, `x = cursor.cx - 0.5em - tooltipBox.w`.
  - With cursor exactly at the flip threshold and `previousSide: 'right'`: returns `side: 'left'`.
  - With cursor exactly at the flip threshold and `previousSide: 'left'`: returns `side: 'left'` (hysteresis holds).
  - With cursor 0.5em - 1px past the flip threshold to the left and `previousSide: 'left'`: returns `side: 'left'` (still inside hysteresis band).
  - With cursor 0.5em + 1px past the flip threshold to the left and `previousSide: 'left'`: returns `side: 'right'` (flips back).
  - `y` is always `plotRect.y + 0.5em` regardless of cursor `cx` or `previousSide`.
- **`src/blocks/elevation/geometry/tooltip-format.test.ts`** — locale-aware formatting:
  - `formatDistance( 1234, 'sv-SE' )` returns `"1 234 m"` (sv-SE thousand separator is a non-breaking space or none; assert against the locale's actual output).
  - `formatDistance( 2500, 'sv-SE' )` returns `"2,5 km"`.
  - `formatDistance( 5234, 'en-US' )` returns `"5.2 km"`.
  - `formatDistance( 1999, 'en-US' )` returns `"1,999 m"` (threshold inclusive on the m side).
  - `formatDistance( 2000, 'en-US' )` returns `"2.0 km"` (threshold flips to km).
  - `formatElevation( 247, 'sv-SE' )` returns `"247 m"` regardless of locale.
  - `formatElevation( 2800, 'sv-SE' )` returns `"2 800 m"` (no km switching).
  - `formatElevation( -42, 'sv-SE' )` returns `"−42 m"` (negative elevations are real on coastal tracks below sea level).
- **`src/blocks/elevation/tooltip.test.ts`** — SVG DOM helpers:
  - `createTooltipElements( svg, { showDistance: true, showHeight: true } )` appends `<g class="…-tooltip">` with `pointer-events="none"`, `<title>`, `<rect>` (with `rx="0.25em"`, `display="none"`), and two `<text>` nodes (with the right classes, `text-anchor="start"`, `display="none"`).
  - With `showDistance: false`, the distance `<text>` is *not* created; `elements.distance === null`.
  - With both flags false, the function is not invoked (mount-side decision) — covered in `view.test.ts` if it exists, or asserted via the mount-side test.
  - `applyTooltipPosition( elements, layout )` writes the eight rect attributes, both `<text>` positions, both `<text>` `textContent`s, the `<title>` `textContent`, and removes `display="none"` from rect and both visible texts.
  - `textContent !== newValue` guard: a second `applyTooltipPosition` with identical `distanceLabel` does not write `textContent` again (mock the setter or assert with a getter sentinel).
  - `hideTooltip` re-applies `display="none"` to rect and both `<text>` nodes; the `<title>` and `<g>` are unaffected.
- **`src/blocks/elevation/geometry/measure.test.ts`** — extended:
  - `createTextMeasurer( svg, 'kntnt-gpx-blocks-elevation-tooltip-distance' )` creates a hidden `<text>` with that class; querying the SVG for `text.kntnt-gpx-blocks-elevation-tooltip-distance` returns at least one node during measurement.
  - The hidden node is removed after measurement (or made invisible).
  - With no class supplied (existing path), no class attribute is set on the hidden node; existing tick-label tests remain green.

Then write the host-level tests:

- **`src/blocks/elevation/chart.test.tsx`** — extended:
  - With non-empty `samples`, `samples.length >= 2`, and both row toggles on, the editor preview's SVG contains `<g class="kntnt-gpx-blocks-elevation-tooltip">` with `<title>`, `<rect>`, and two `<text>` children.
  - The tooltip's `y` matches `plotRect.y + 0.5em`.
  - The `<text>` `textContent` matches `formatDistance(...)` and `formatElevation(...)` for the midpoint sample.
  - With `tooltipShowDistance: false, tooltipShowHeight: true`, only the height `<text>` is in the DOM and it's vertically centred in the shrunken `<rect>`.
  - With both flags false, the `<g>` is not rendered.
  - With `samples.length < 2`, the `<g>` is not rendered.
  - When the `tooltipBackgroundColor` attribute changes, the wrapper's inline style for `--kntnt-gpx-blocks-elevation-tooltip-background` updates and re-rendered SVG reflects it.
- **`src/blocks/elevation/style.test.ts`** — extended:
  - `.kntnt-gpx-blocks-elevation` carries each of the 19 default values (or `inherit` for the typography ones).
  - `.kntnt-gpx-blocks-elevation-tooltip` carries `pointer-events: none`.
  - `.kntnt-gpx-blocks-elevation-tooltip-distance` and `…-height` map each of their eight typography properties to the corresponding custom property via `var()`.
  - `.kntnt-gpx-blocks-elevation-tooltip-bg` maps `fill` to `--…-tooltip-background`.
- **`src/blocks/elevation/edit.test.tsx`** — extended:
  - When `showCursor` is `true`, the *Tooltip info* PanelBody is rendered with both row toggles; both Typography panels (*Tooltip distance*, *Tooltip height*) are rendered; the three tooltip Color rows are in the Color panel.
  - When `showCursor` is `false`, none of the above are rendered.
  - Toggling `showCursor` back to `true` restores all surfaces with their previously stored values.
- **`tests/Unit/Rendering/Render_ElevationTest.php`** — extended:
  - `render()` includes `tooltipShowDistance` and `tooltipShowHeight` in the `data-wp-context` JSON of the healthy-state wrapper.
  - `build_inline_style( [ 'tooltipBackgroundColor' => '#fff' ] )` produces `--kntnt-gpx-blocks-elevation-tooltip-background: #ffffff` after sanitizer canonicalisation.
  - `build_inline_style( [ 'tooltipDistanceColor' => '' ] )` omits the property.
  - `build_inline_style( [ 'tooltipDistanceColor' => 'not-a-colour' ] )` omits the property (sanitiser rejects).
  - One representative typography test per row: `build_inline_style( [ 'tooltipDistanceFontWeight' => '700' ] )` produces `--kntnt-gpx-blocks-elevation-tooltip-distance-font-weight: 700`; mirror for `tooltipHeightFontStyle: italic` etc. (The full 16-typography matrix is reasonable to enumerate as a data-driven test in Pest with `it( ... )->with( ... )`.)

**No cross-block integration test.** The tooltip moves whenever fraction is written, regardless of who writes it. Both blocks correctly read and write the same `state[ mapId ].fraction` slot, verified by Step 6's tests. An integration test simulating Map writing → Elevation tooltip updating would exercise the Interactivity API runtime, not our code.

Implementation follows the tests until all are green.

### Acceptance criteria

Step 7 is done — and `v0.13.7` may be tagged — when **all** of the following hold.

**Behaviour:**

1. The Step 3/4/5/6 healthy-state chart now carries a tooltip in addition to the cursor whenever the cursor is visible (i.e. `state[ mapId ].fraction` is non-null and `showCursor === true`). The five warning states still emit no SVG and therefore no tooltip.
2. The tooltip consists of one `<g class="kntnt-gpx-blocks-elevation-tooltip">` group at the end of the SVG host's children list (after the cursor `<g>`), with `pointer-events="none"` so it does not block the hit-rect. The group contains a `<title>` (for SR access), a `<rect class="…-tooltip-bg">` with `rx="0.25em"` and `fill="var(--kntnt-gpx-blocks-elevation-tooltip-background)"`, and up to two `<text>` rows (`…-tooltip-distance` and `…-tooltip-height`) with `text-anchor="start"` and the corresponding `fill="var(...)"`.
3. **Top-pinned placement.** The tooltip's `y` is `plotRect.y + 0.5em` regardless of cursor `cy`. The tooltip's `x` follows the cursor's `cx`, offset by `+0.5em` on the right side or `-(0.5em + tooltipBox.w)` on the left side after a flip.
4. **Horizontal flip with 0.5em hysteresis.** When the tooltip on the right side would otherwise extend past `plotRect.right - 0.5em`, it flips to the left side. Once on the left, it flips back to the right only when the cursor has moved at least 0.5em past the flip threshold in the opposite direction. The user cannot trigger flip oscillation by wiggling at the boundary.
5. **Row values.** Line 1 reads `formatDistance( interpolatedDistance, locale )` from `geometry/tooltip-format.ts` — m below 2000m, km above (matching `chooseXUnit`'s x-axis decision); locale-formatted via `Intl.NumberFormat`; 0 decimals in m, 1 in km. Line 2 reads `formatElevation( interpolatedElevation, locale )` — always in m, 0 decimals, locale-formatted. No prefix labels; the `m` / `km` unit is appended with a single space.
6. **Per-row visibility (independent toggles).** When `tooltipShowDistance` is off, line 1's `<text>` is not in the DOM and line 2 (if visible) is vertically centred in the shorter `<rect>`. Symmetric for `tooltipShowHeight`. When both are off, the entire `<g>` is not rendered.
7. **Cross-block sync.** A scrub on Map's polyline moves the Elevation cursor *and* updates the tooltip's text and position in lockstep. A scrub on Elevation's chart moves the Map cursor and updates Elevation's own tooltip. The cursor and tooltip cannot drift apart between frames — they update from the same fraction snapshot in the same watch callback.
8. **`showCursor === false` disables the entire tooltip subsystem.** No tooltip `<g>` in the DOM (on either frontend or editor); no *Tooltip info* PanelBody, no tooltip Color rows, no *Tooltip distance* / *Tooltip height* Typography panels in the inspector. Toggling `showCursor` back to `true` restores every surface with stored values intact.
9. **The 19 tooltip custom properties are wired three ways.** Each is settable in the inspector (Color or Typography panel), written to the wrapper's inline style by both `edit.tsx` and `Render_Elevation::build_inline_style()`, consumed by SCSS rules on the appropriate `<text>` / `<rect>` selector. Empty / invalid values fall back to SCSS defaults: `#000000cc` (background), `#ffffff` (distance text), `#dddddd` (height text), `inherit` (all 16 typography properties).
10. **`Render_Elevation::render_chart_wrapper()`** emits `tooltipShowDistance` and `tooltipShowHeight` in the `data-wp-context` JSON of the healthy-state wrapper. `wrap_warning()` does not. The total per-block context object has five booleans after Step 7.
11. **State payload unchanged.** No new fields on `state[ mapId ]`. The Step 6 trio (`samples`, `statistics`, `fraction`) is still the entire per-mapId slice contract.
12. **Editor preview** in `chart.tsx` shows a static tooltip at fraction = 0.5 with the same DOM shape and styling as the frontend. Inspector edits to any tooltip color or typography attribute update the preview live via the existing React data flow and CSS variables.
13. **`<title>` accessibility.** The `<title>` child carries a translated string like `"Distance 5,2 km, elevation 247 m"`, rebuilt on every fraction update, respecting per-row toggle state. The chart's outer `role="img"` + `aria-label` remains unchanged.
14. **Tooltip `<g>` is persistent across redraws.** `view.ts`'s `removeMatching` selector list does not include `.kntnt-gpx-blocks-elevation-tooltip`; resize, `loadingdone`, font-load, and other redraw triggers update the tooltip's geometry and text content without re-creating its DOM nodes.
15. **`interpolateSample` and `projectCursor` migrate to `geometry/sample-interpolation.ts`.** `geometry/cursor.ts` is deleted; `chart.tsx` and `view.ts` import from the new module. Behaviour is identical.
16. **`createTextMeasurer( svg, className? )`** accepts an optional class parameter; the hidden measurement `<text>` carries the class when supplied. Existing tick-label call sites (no class) are unchanged in behaviour.

**Gates (must all pass at HEAD before tagging):**

17. `npm run build`.
18. `composer test` (Pest), including the extended `Render_ElevationTest`.
19. `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M`.
20. `npm run test:js` — including the new `geometry/sample-interpolation.test.ts` (migrated), `geometry/tooltip-placement.test.ts`, `geometry/tooltip-format.test.ts`, `tooltip.test.ts`, and the extended `chart.test.tsx`, `edit.test.tsx`, `style.test.ts`, `geometry/measure.test.ts`.
21. `npx wp-scripts lint-js src/blocks/`.

**Manual verification in WordPress Playground (`@wp-playground/cli`):**

22. Insert one configured Map and one Elevation block on the same post: hovering over the Elevation chart shows a tooltip at the top of the plot rectangle, on the right side of the cursor, with two rows displaying interpolated distance and elevation.
23. **Mouse-scrub** the Elevation chart from left to right: the tooltip follows the cursor horizontally; both values update continuously; the tooltip flips to the left side of the cursor when it would otherwise clip the plot rectangle's right edge.
24. **Wiggle the mouse at the flip threshold:** the tooltip does not flicker — it commits to one side and stays there until the cursor has moved 0.5em past the threshold.
25. **Scrub on Map's polyline:** the Elevation tooltip moves and updates in lockstep with the Elevation cursor.
26. **Touch + drag** on a touch device or in DevTools touch emulation: the tooltip follows the finger; lifting the finger leaves the tooltip in place (no flicker-off).
27. **Mouse leaves the wrapper** outside an active scrub: both cursor and tooltip disappear simultaneously.
28. **Switch locale** between `sv-SE` and `en-US`: the tooltip values respect the locale (decimal separator, thousand separator, group symbol). The chosen unit (m vs km) does not depend on locale.
29. **Toggle *Show distance* off** in the inspector: only the elevation row remains; it is vertically centred in the shorter tooltip rectangle.
30. **Toggle *Show height* off** also: the tooltip group is not rendered at all (no `<g>` in DOM).
31. **Toggle either *Show distance* or *Show height* back on**: the tooltip reappears with the correct row layout immediately on the next scrub.
32. **Change *Tooltip background* color** in the inspector: editor preview reflects it instantly; published post reflects it after save.
33. **Change *Tooltip distance* font weight to 700** and *Tooltip height font style* to italic: editor preview reflects both changes; the rectangle's width and height grow to accommodate the wider / taller text.
34. **Toggle *Cursor* off** in the inspector: cursor and tooltip both vanish; the *Tooltip info* PanelBody, the three tooltip Color rows, and both Typography panels disappear from the inspector.
35. **Toggle *Cursor* back on**: all surfaces reappear with their previously chosen values; the cursor and tooltip return on the next pointer interaction.
36. **Elevation in warning state** (e.g. unbind the Map, or use a 1-point track): no chart, no cursor, no tooltip, no watch errors in the console.
37. **Inspect the editor preview's SVG**: `<g class="kntnt-gpx-blocks-elevation-tooltip">` is present with the expected child structure at the midpoint of the samples array; switching `previousSide` cannot affect the static editor preview.
38. **Resize the browser window**: the tooltip stays attached to the cursor's fraction (the tooltip's `x` follows the recomputed cursor `cx`); typography changes are not lost; flip side is recomputed against the new plot rectangle.

### Release

When all acceptance criteria hold, follow the six-step release procedure documented in `AGENTS.md` (section *Cutting a release*). Tag `v0.13.7`. Commit message: `Release v0.13.7 — Step 7: tooltip`.

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
