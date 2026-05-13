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

## Step 4: Tick marks and tick labels

**Goal.** Both axes carry tick marks and numeric labels driven by the GPX data range. The X axis runs from 0 to the bound track's distance and switches between metres and kilometres on a deterministic distance threshold. The Y axis runs from a rounded-down floor to a rounded-up ceiling of the elevation range and stays in metres. The cursor (Step 6), tooltip (Step 7), and the curve itself (Step 5) all layer on top of this scaffold.

**Load list:** `docs/blocks.md`

**v0.12.0 references:** none. v0.12.0 rendered ticks server-side in PHP — superseded by the *Rendering architecture* decision near the top of this document. v0.12.0's `geometry.ts` covers cursor mapping only, not tick rendering, and is consulted in Step 6 — not here.

### Design rationale (locked by the Step 4 grilling)

This spec's algorithm overturns three pieces of the original prose (the spec at `v0.13.3:docs/elevation-rebuild.md` lines 91–122). The overturned pieces are listed first so a reader of the old spec can locate them.

**Overturned: "× 1.5" multiplier for tick crowding.** The original formula `N = floor(W_avail / (label_width × 1.5))` produces *proportional* spacing — wider labels get more luft. Step 4's grilling replaced this with an *additive* minimum: at least `0.5em` of luft between adjacent labels regardless of label width. Adopted formula: `N = floor((avail + 0.5em) / (refWidth + 0.5em))`, clamped to ≥ 2. Constant luft is easier to reason about and gives a more consistent visual across very different etikett-bredder (e.g. `"0 m"` vs `"1234 m"`).

**Overturned: m/km switch based on tick values.** The original "more than half of the non-zero tick values ≥ 1000" rule (still present in `format.ts`'s `chooseXUnit(values)`) couples the unit choice to the count of ticks Step 4 renders. Step 4's grilling replaced this with a distance-only threshold: `distance < 2000 ? 'm' : 'km'`. Decoupling unit from N makes the unit choice deterministic at design time, which in turn lets Step 4 build a worst-case reference string before `niceTicks` is called.

**Overturned: "measure the width of an average label".** The original formula needed labels before it could compute N, but `niceTicks` needs N to produce labels. The grilling broke the chicken-and-egg by introducing a **worst-case reference string** keyed off `distance` alone. The reference string is typographically ≥ the bredaste actual label by construction, so measuring it once gives a safe upper bound for N and (newly) for `wRight` in `margins.ts`.

### Reference strings (locked by Q2 + Q3 grilling)

`xReferenceString(distance, locale)` returns the worst-case-bredd label given the chosen distance threshold:

```ts
function xReferenceString( distance: number, locale?: string ): string {
    if ( distance < 2000 ) {
        // m-mode. +2 (not +1) buffer covers niceTicks rounding-up to the
        // next decade (e.g. distance=888 → last tick=1000). Capped at 4
        // digits because distance < 2000 bounds the last tick to ≤ 2000.
        const digits = Math.min(
            4,
            Math.floor( Math.log10( Math.max( distance, 1 ) ) ) + 2
        );
        const num = formatNumber( parseInt( '8'.repeat( digits ), 10 ), 0, locale );
        return `${ num } m`;
    }
    // km-mode. +1 buffer (formula is `floor(log10(distance)) - 1` instead
    // of `-2`) covers niceTicks rounding past the next power of 10
    // (e.g. distance=9999 → last tick=10000).
    const n = Math.max( 1, Math.floor( Math.log10( distance ) ) - 1 );
    const num = formatNumber( parseFloat( '8'.repeat( n ) + '.8' ), 1, locale );
    return `${ num } km`;
}
```

All `"8"` digits chosen typographically because `8` is the bredaste digit in the proportional fonts the project supports (Inter, Source Sans, Roboto, Open Sans, etc.). The leading `"1" + "888"` variant considered during grilling was rejected because the actual label `"2000 m"` (which can appear when `distance ∈ [1500, 1999]`) is wider than `"1888 m"` in those fonts. Reference strings route through `formatNumber` from `format.ts` so the decimal separator matches the visitor's locale (komma in sv-SE, dot in en-US).

Verification table for m-mode (`distance < 2000`):

| `distance` | last nice tick | last label | refString | outcome |
|---|---|---|---|---|
| 99 | 100 | `"100 m"` | `"888 m"` | exact |
| 100 | 100 | `"100 m"` | `"8888 m"` | overreserves ~0.55em |
| 500 | 500 | `"500 m"` | `"8888 m"` | overreserves ~0.55em |
| 888 | 1000 | `"1000 m"` | `"8888 m"` | exact |
| 999 | 1000 | `"1000 m"` | `"8888 m"` | exact |
| 1500 | 1500 | `"1500 m"` | `"8888 m"` | exact |
| 1999 | 2000 | `"2000 m"` | `"8888 m"` | exact |

Verification table for km-mode (`distance ≥ 2000`):

| `distance` | last nice tick | last label | refString | outcome |
|---|---|---|---|---|
| 2000 | 2000 | `"2,0 km"` | `"88,8 km"` | overreserves ~0.55em |
| 9999 | 10000 | `"10,0 km"` | `"88,8 km"` | exact |
| 20000 | 20000 | `"20,0 km"` | `"888,8 km"` | overreserves ~0.55em |
| 99999 | 100000 | `"100,0 km"` | `"888,8 km"` | exact |
| 100000 | 100000 | `"100,0 km"` | `"8888,8 km"` | overreserves ~0.55em |

Overreservation costs at most one digit-width (~0.55em) of right-side luft on the X axis, translating in practice to one fewer tick than the theoretical maximum. Acceptable.

`chooseXUnit` is rewritten as a oneliner taking distance (not the values array):

```ts
export function chooseXUnit( distance: number ): XAxisUnit {
    return distance < 2000 ? 'm' : 'km';
}
```

The existing `chooseXUnit( values )` signature is removed. Both call sites (`formatXLabels` and any new tick-rendering code) pass `distance` instead.

### N derivation (locked by Q1 + Q4 grilling)

For each axis, N is the number of ticks that fits when adjacent labels are kept ≥ `0.5em` apart. From the additive rule `N · labelW + (N − 1) · 0.5em ≤ avail` solved for N:

```
N = floor( ( avail + 0.5em ) / ( refSize + 0.5em ) )
N = max( 2, N )                                       // never fewer than start/end ticks
```

For X: `avail = W − wLeft − wRight`, `refSize = measure( xReferenceString( distance ), typography ).width`.

For Y: `avail = H − wTop − h`, `refSize = ref.height` (the height of `"-0,123456789"` already measured by Step 3's margin algorithm). The Y axis stacks labels vertically; what limits N is etikett-höjd, not bredd.

Step 3's `geometry/ticks.ts:computeTickCount( availableWidth, labelWidth )` is rewritten with a new signature and the additive formula:

```ts
export function computeTickCount(
    avail: number,
    refSize: number,
    em: number,
): number {
    if ( avail <= 0 || refSize <= 0 ) {
        return 2;
    }
    const padding = 0.5 * em;
    const raw = Math.floor( ( avail + padding ) / ( refSize + padding ) );
    return raw < 2 ? 2 : raw;
}
```

Callers update accordingly. The previous `× 1.5` arithmetic is gone.

### Margin amendments (locked by Q4 + Q6a grilling)

The `Margins` type gains a new field `wTop` and the `wRight` field changes derivation:

```ts
export interface Margins {
    readonly wLeft: number;
    readonly wRight: number;
    readonly wTop: number;        // NEW: reserves upper half of topmost Y label
    readonly h: number;
    readonly em: number;
}
```

Updated formulas in `computeMargins`:

- **`wRight = measure( xReferenceString( distance ), typography ).width / 2 + 0.5em`** — replaces `last( niceXLabels ).width / 2 + 0.5em`. Eliminates the dependency on the rendered tick count: refString is a function of `distance` alone, so `wRight` is stable across resize and Step 4 can compute N within already-determined margins.
- **`wTop = 0.5 · ref.height + 0.5em`** — new. The topmost Y label is centred vertically on its tick mark at `y = wTop`; without a top margin its upper half would be clipped against the SVG viewBox top edge. `wTop` reserves half the label height (because the label is centred, not anchored at top) plus the same `0.5em` buffer used by `wLeft`, `wRight`, and `h`. Symmetric with `h = ref.height + 0.5em` which reserves the *full* label height (the X labels are top-anchored, not centred).
- **`wLeft = measure( widest( niceYLabels ) ).width + 0.5em`** — unchanged. The bredaste Y label is robust against N variations because all generated Y labels are measured, not just one extreme. Keeping `wLeft` data-derived saves a Y-axis reference-string function. (If a future bug surfaces a `wLeft` underestimate at extreme N, a Y refString can be added then; not worth the up-front code.)
- **`h = ref.height + 0.5em`** — unchanged.

Step 3's `DEFAULT_TARGET_TICK_COUNT = 5` is retained for the `wLeft` computation only (`niceTicks(yMin, yMax, 5)` for the Y-label set whose widest drives `wLeft`). The X tick set previously generated to derive `wRight` is no longer needed in `computeMargins`.

The plot area becomes `[ wTop, H − h ]` vertically, `[ wLeft, W − wRight ]` horizontally. Y axis line endpoints change: `y1 = wTop` (was `y1 = 0`) in both `chart.tsx` and `view.ts`. X axis line endpoints are unchanged.

### Axis bounds and tick filtering (locked by Q5 grilling)

**Strava-style mixed bounds.** Elevation profiles treat distance and elevation asymmetrically, and Step 4 follows the convention of Strava, Garmin, and Komoot:

- **X axis: data-bound, range `[0, distance]`.** The curve in Step 5 fills the full plot width. Ticks generated by `niceTicks(0, distance, N_x)` are **filtered** to `value ≤ distance` before rendering. The last rendered X tick is the largest nice value ≤ distance and sits somewhere inside the plot area, not necessarily at the right edge. Distance is a concrete endpoint the user cares about; extending the axis past it adds no signal.
- **Y axis: nice-bound, range `[floor(yMin/step) · step, ceil(yMax/step) · step]`.** All generated Y ticks render. The lowest Y tick sits exactly on the X axis line; the highest sits at `y = wTop`. Elevation values are rarely round; rounding the axis bounds gives clean tick values and lets the bottom and top ticks anchor the axis visually.

Consequences for the curve (Step 5): it fills the plot width but is offset from the bottom — the bottom of the plot at `y = H − h` represents the rounded floor `floor(yMin/step) · step`, slightly below the actual data minimum `yMin`. The curve starts above the X axis line.

**First X tick at `x = 0`** is always rendered with both tick mark and `"0 m"` (or `"0"` in km-mode) label. It anchors the scale at the origin.

**Origin corner.** The lowest Y label is centred vertically on the X axis line; the `"0 m"` X label sits with its top `0.5em` below the X axis line. These two labels are *vertically tangent* (Y label bottom at `H − h + 0.5em`, X label top at the same `H − h + 0.5em`) but do not overlap given the spec's geometry. Step 4 accepts the tangential contact; if font rendering exposes a one-pixel visual collision in practice, address it with a small later patch rather than re-deriving the algorithm.

### SVG mechanics (locked by Q6 grilling)

**Tick marks** are rendered as one `<line>` element per tick, grouped:

```html
<g class="kntnt-gpx-blocks-elevation-ticks-x">
    <line x1="…" y1="…" x2="…" y2="…"
          stroke="var(--kntnt-gpx-blocks-elevation-axis)"
          stroke-width="1" />
    …
</g>
<g class="kntnt-gpx-blocks-elevation-ticks-y">
    …
</g>
```

Mark geometry:

- **X tick mark:** vertical line from `( xTick, H − h )` to `( xTick, H − h + 0.2em )`. Extends *downward* from the X axis.
- **Y tick mark:** horizontal line from `( wLeft, yTick )` to `( wLeft − 0.2em, yTick )`. Extends *leftward* from the Y axis.

Stroke width `1` (px under the 1:1 viewBox mapping), same colour and CSS variable as the axis lines.

**Tick labels** are rendered as `<text>` elements grouped similarly:

```html
<g class="kntnt-gpx-blocks-elevation-tick-labels-x"
   fill="var(--kntnt-gpx-blocks-elevation-axis-label)">
    <text x="…" y="…" text-anchor="middle" dominant-baseline="hanging">2000 m</text>
    …
</g>
<g class="kntnt-gpx-blocks-elevation-tick-labels-y"
   fill="var(--kntnt-gpx-blocks-elevation-axis-label)">
    <text x="…" y="…" text-anchor="end" dominant-baseline="central">200 m</text>
    …
</g>
```

Label positioning:

- **X label:** `text-anchor="middle"`, `dominant-baseline="hanging"`. `x = xTick`, `y = ( H − h ) + 0.5em`. The `hanging` baseline places the top of the visible glyph at `y`, so the top of the label sits `0.5em` below the X axis line per spec.
- **Y label:** `text-anchor="end"`, `dominant-baseline="central"`. `x = wLeft − 0.5em`, `y = yTick`. The `central` baseline centres the visible glyph vertically on `y`, aligned with the tick mark.

Browser support: `dominant-baseline: hanging` and `central` are supported in all evergreens since 2019 (Chrome 80+, Safari 12+, Firefox 70+).

**Colour wiring:**

- Tick marks use `var(--kntnt-gpx-blocks-elevation-axis)` — the existing CSS variable that already drives the axis lines (wired in Step 3).
- Tick labels use a *new* CSS variable `var(--kntnt-gpx-blocks-elevation-axis-label)`. The `axisLabelColor` block attribute already exists in `block.json` (added by Step 1). Step 4 wires it through three surfaces:
  - In `edit.tsx`, alongside the existing `axisColor` wiring at `edit.tsx:281–294`:
    ```ts
    const axisLabel = usefulValue< string >(
        attributes,
        setAttributes,
        'axisLabelColor',
        '',
    );
    if ( axisLabel.resolved !== '' ) {
        inlineStyle[ '--kntnt-gpx-blocks-elevation-axis-label' ] =
            axisLabel.resolved;
    }
    ```
  - In `Render_Elevation::render_chart_wrapper()`, the matching server-side emission (mirror of the existing axisColor inline-style line).
  - In `style.scss`, a default value next to the existing `--kntnt-gpx-blocks-elevation-axis: #1e1e1e;`. Default for `--kntnt-gpx-blocks-elevation-axis-label`: `#1e1e1e` (same as axis), letting users override either independently.

### Resize behaviour (locked by Q4 grilling)

Step 3's contract — margins cached separately from SVG dimensions, ResizeObserver triggers axis redraw but not margin recomputation — extends naturally:

- **Margins remain cached across resize.** `wRight` and `wTop` are functions of `distance` and `typography` alone (not of `W_avail` or `H_avail`), so their values are stable. `wLeft` depends on the Y data range and typography. `h` depends on typography. None depends on N.
- **N, step, labels, and tick positions recompute on resize.** ResizeObserver triggers a redraw that recomputes `N_x` and `N_y` from current `W_avail` / `H_avail`, regenerates the tick set with `niceTicks`, and re-renders the tick-mark and tick-label groups. The axis lines reposition (their endpoints move with `W` and `H`), but their start positions (anchored to `wLeft`, `H − h`, `wTop`) stand still in pixel space.

Visual consequence: during a drag-resize the *number of ticks* jumps between discrete values (e.g. 5 → 6 → 7) when the formula's threshold is passed, and the *label values* change with each jump. Between jumps everything stands still. This matches the behaviour of D3, Recharts, and Plotly auto-scaled charts.

### Redraw sequence (one shape for both hosts)

The redraw path runs the same sequence in both `chart.tsx` (React) and `view.ts` (vanilla DOM under Interactivity). Step numbers cross-reference the helpers under `geometry/`:

1. Read `W`, `H` from `svg.getBoundingClientRect()`. Skip if either is zero (not yet laid out).
2. Compute `availX = W − margins.wLeft − margins.wRight` and `availY = H − margins.wTop − margins.h`.
3. Compute `N_x = computeTickCount( availX, refXWidth, margins.em )` and `N_y = computeTickCount( availY, ref.height, margins.em )`. `refXWidth` is the cached measurement of `xReferenceString( distance )`, performed once when margins are computed and reused here. `ref.height` is already in `margins`.
4. Generate tick sets:
   - X: `niceTicks( 0, distance, N_x ).values.filter( v => v <= distance )`.
   - Y: `niceTicks( yMin, yMax, N_y ).values` (no filter; nice-bound range).
   Capture each axis's `step` for use in formatting.
5. Format labels: `formatXLabels( xValues, xStep )` and `formatYLabels( yValues, yStep )` from `format.ts`.
6. Project tick values to SVG coordinates:
   - `xScale( v ) = margins.wLeft + ( v / distance ) * availX`.
   - `yScale( v ) = ( H − margins.h ) − ( v − niceYMin ) / ( niceYMax − niceYMin ) * availY`, where `niceYMin = floor(yMin/yStep) · yStep` and `niceYMax = ceil(yMax/yStep) · yStep`.
7. Replace any existing `<g class="kntnt-gpx-blocks-elevation-ticks-*">` and `<g class="kntnt-gpx-blocks-elevation-tick-labels-*">` groups (remove + reinsert; cheaper than diffing for ≤ 20 elements).

Step 1–6 are pure (no DOM mutation). Step 7 is host-specific: React JSX in `chart.tsx`, `document.createElementNS` in `view.ts`. The two hosts share Steps 1–6 byte-faithfully through the helpers under `geometry/`.

### File layout for Step 4

```
src/blocks/elevation/
├── chart.tsx                                 — modified: tick + label rendering; Y axis y1 = margins.wTop
├── chart.test.tsx                            — modified: new assertions for tick groups, label positioning
├── view.ts                                   — modified: tick + label rendering; Y axis y1 = margins.wTop
├── edit.tsx                                  — modified: axisLabelColor CSS-variable wiring
├── style.scss                                — modified: default for --kntnt-gpx-blocks-elevation-axis-label
└── geometry/
    ├── format.ts                             — modified: chooseXUnit takes distance; new xReferenceString
    ├── format.test.ts                        — modified: chooseXUnit + xReferenceString tables
    ├── ticks.ts                              — modified: computeTickCount signature + additive 0.5em formula
    ├── ticks.test.ts                         — modified: matching test updates
    ├── margins.ts                            — modified: wRight from xReferenceString; new wTop field
    └── margins.test.ts                       — modified: wTop assertions; wRight from xReferenceString

classes/Rendering/
└── Render_Elevation.php                      — modified: --kntnt-gpx-blocks-elevation-axis-label inline emission
```

No new files in Step 4. The seam between pure geometry and DOM-bound work is preserved: `format.ts`, `ticks.ts`, and `margins.ts` add no DOM dependencies; the rendering logic lives in `chart.tsx` and `view.ts`.

### Test-driven development

Write the helper-level tests first, watch them go red, implement until green:

- `format.test.ts` — new cases for `chooseXUnit( distance )` (`1`, `1999`, `2000`, `100000`) and `xReferenceString( distance, locale )` covering the verification tables above plus a sv-SE / en-US locale pair (decimal separator switch).
- `ticks.test.ts` — `computeTickCount( avail, refSize, em )` cases including narrow containers (clamp ≥ 2), large containers, `em` scaling, and `avail ≤ 0` / `refSize ≤ 0` sentinel.
- `margins.test.ts` — `computeMargins` returns `{ wLeft, wRight, wTop, h, em }`; `wRight` matches `measure( xReferenceString( distance ), typography ).width / 2 + 0.5em`; `wTop` matches `0.5 · ref.height + 0.5em`; `wLeft` and `h` unchanged from Step 3 assertions.

Then write the integration test for the React preview:

- `chart.test.tsx` — `<Chart>` renders the expected `<g class="kntnt-gpx-blocks-elevation-ticks-x">` / `…-y` and `…-tick-labels-x` / `…-y` groups for known data shape and mocked typography. First X tick at `x = margins.wLeft`. Y axis line starts at `y = margins.wTop` (not `0`). X-tick values filtered when a generated nice value exceeds `distance`.

Implementation follows the tests until all are green. PHP-side: `Render_ElevationTest` gains an `axisLabelColor` wiring assertion analogous to the existing `axisColor` one.

### Acceptance criteria

Step 4 is done — and `v0.13.4` may be tagged — when **all** of the following hold.

**Behaviour:**

1. The Step 3 healthy-state chart now carries tick marks and labels on both axes. Step 3's degenerate-case warnings (`no-map`, `bound-deleted`, `bound-unconfigured`, `no-elevation-data`, `zero-distance`) are unaffected.
2. `chooseXUnit( distance )` returns `'m'` when `distance < 2000` and `'km'` otherwise. The old values-list signature is gone.
3. `xReferenceString( distance, locale )` exists in `format.ts` and matches the verification tables in this spec. Locale-aware decimal separator (`,` in sv-SE, `.` in en-US) via `formatNumber`.
4. `computeTickCount( avail, refSize, em )` implements the additive formula `floor( ( avail + 0.5em ) / ( refSize + 0.5em ) )`, clamped to ≥ 2.
5. `Margins` carries `wTop`. `wRight = measure( xReferenceString( distance ), typography ).width / 2 + 0.5em`. `wLeft` and `h` unchanged. Plot area is `[ margins.wTop, H − margins.h ]` vertically, `[ margins.wLeft, W − margins.wRight ]` horizontally.
6. **Strava-style bounds:** X axis range `[0, distance]` with ticks filtered to `value ≤ distance`. Y axis range `[ floor( yMin / step ) · step, ceil( yMax / step ) · step ]`; all generated Y ticks render.
7. **First X tick at `x = 0`** is always rendered: tick mark and `"0 m"` (m-mode) or `"0"` (km-mode) label.
8. **Tick mark geometry:** length `0.2em`. X marks extend *downward* from `( xTick, H − margins.h )`. Y marks extend *leftward* from `( margins.wLeft, yTick )`. `stroke="var(--kntnt-gpx-blocks-elevation-axis)"`, `stroke-width="1"`.
9. **Tick label positioning:** X labels `text-anchor="middle"`, `dominant-baseline="hanging"`, `y = ( H − margins.h ) + 0.5em`. Y labels `text-anchor="end"`, `dominant-baseline="central"`, `x = margins.wLeft − 0.5em`.
10. **Tick label colour:** `fill="var(--kntnt-gpx-blocks-elevation-axis-label)"`. New CSS variable wired through `edit.tsx`, `Render_Elevation::render_chart_wrapper()`, and `style.scss` (default `#1e1e1e`). `axisLabelColor` attribute drives the inline custom property.
11. **m/km switch:** when `distance ≥ 2000`, X labels render in kilometres with one decimal, only the last label carries the `" km"` suffix, intermediate labels are unitless. Existing `formatXLabels` behaviour, unchanged.
12. **Resize:** ResizeObserver triggers a redraw that recomputes `N_x`, `N_y`, tick sets, and label positions. Margins do not recompute (cached across resize). Axis line start positions (anchored to `wLeft`, `wTop`, `H − h`) are pixel-stable across resize; only their endpoints move with `W` / `H`.

**Gates (must all pass at HEAD before tagging):**

13. `npm run build`.
14. `composer test` (Pest) — including the extended `Render_ElevationTest` for the `axisLabelColor` wiring.
15. `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M`.
16. `npm run test:js` — including the extended `format.test.ts`, `ticks.test.ts`, `margins.test.ts`, and `chart.test.tsx`.
17. `npx wp-scripts lint-js src/blocks/`.

**Manual verification in WordPress Playground (`@wp-playground/cli`):**

18. Insert one configured Map with a multi-kilometre GPX, then insert Elevation: chart shows axes with tick marks and locale-formatted labels in both editor preview and frontend. The X axis carries kilometre labels (e.g. `"0,5"`, `"1,0"`, …, `"5,0 km"` for a 5 km track) with `" km"` only on the last label.
19. Use a short GPX (`distance < 2000 m`): X axis switches to metres (e.g. `"0 m"`, `"500 m"`, `"1000 m"`, `"1500 m"`).
20. Edit the **Tick labels** font-size to double the default: labels grow visibly, margins recompute, tick count drops accordingly (fewer ticks fit per unit width).
21. Resize the browser window during inspection: the *number* of ticks changes at thresholds, the axis line *start positions* remain pixel-stable, and nothing jitters between jumps.
22. Change **Color → Axis labels** in the inspector: tick label colour updates live in editor and on frontend after save. **Color → Axis** continues to control axis lines and tick marks independently.
23. **Case B verification** — flat-elevation GPX (`min_elevation === max_elevation`): chart renders Y ticks symmetric around the constant value (via Step 3's `[ min − 1, min + 1 ]` substitution).
24. Two Elevation blocks bound to *different* Map blocks on the same page: each block's tick count adapts independently to its own size; per-mapId state slices stay decoupled.
25. **Webfont verification** — theme that lazy-loads a Google Font for **Tick labels** typography: ticks and labels are repainted when `loadingdone` fires; no permanent margin error based on fallback-font metrics.

### Release

When all acceptance criteria hold, follow the six-step release procedure documented in `AGENTS.md` (section *Cutting a release*). Tag `v0.13.4`. Commit message: `Release v0.13.4 — Step 4: tick marks and tick labels`.

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
