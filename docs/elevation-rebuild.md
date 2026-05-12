# GPX Elevation: Rebuild Plan

The GPX Elevation block has unresolved rendering issues in the current implementation (tag `v0.12.0`). The other two surfaces — GPX Map and the GPX Statistics variation — work as intended and **must not be touched** while this rebuild is in progress.

This document drives a clean rebuild of GPX Elevation in seven steps, after a Step 0 that resets the block to a blank slate while preserving the block's identity (name and icon).

## How to use this document

The rebuild proceeds **one step per Claude Code session**. Each session opens with a focused prompt along the lines of:

> Read `docs/elevation-rebuild.md` and execute Step N. Do nothing else.

Every step lists a **load list**: the additional `docs/*.md` files Claude should read for that step. Anything not on the load list should not be read. The default load (`CLAUDE.md` → `AGENTS.md` → `docs/coding-standards.md`) plus the step's load list is enough.

Each step ends with a **tagged GitHub release**, not just a commit. Versions follow the pattern `0.13.N` where `N` is the step number — Step 0 → `v0.13.0`, Step 1 → `v0.13.1`, …, Step 7 → `v0.13.7`.

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
  - `+` Map (empty dropdown for now)
- **Panel: Tooltip info**
  - `+` Show distance (on/off)
  - `+` Show height (on/off)

### Inspector — Design tab

No `Panel` wrappers — the `ToolsPanel`s sit directly in the block inspector.

- **ToolsPanel: Dimensions**
  - `+` Padding
  - `+` Margin
  - `+` Minimum height
  - `+` Aspect ratio
- **ToolsPanel: Border & Shadow**
  - `-` Border
  - `-` Radius
  - `+` Shadow
- **ToolsPanel: Color**
  - `+` Background
  - `+` Plot line
  - `+` Cursor
  - `+` Axis
  - `+` Axis labels
  - `+` Tooltip background
  - `+` Tooltip distance
  - `+` Tooltip height
- **ToolsPanel: Tick labels** (Typography)
  - `-` Font
  - `+` Font size
  - `+` Appearance
  - `-` Line height
  - `-` Letter spacing
  - `-` Orientation
  - `-` Decoration
  - `-` Decoration hover
- **ToolsPanel: Tooltip distance** (Typography) — same item set as Tick labels (font hidden, font size + appearance visible, the rest hidden)
- **ToolsPanel: Tooltip height** (Typography) — same item set as Tick labels (font hidden, font size + appearance visible, the rest hidden)

### Rules

1. **Use standard WordPress panels and components wherever possible.** Do not invent custom controls unless absolutely necessary; even then, build on the WordPress primitives as much as you can. If you write a custom control, justify the decision in a code comment and in the commit message.
2. All colours in the Color panel have **alpha channel enabled**.
3. Only `Color → Background` is wired to the outer `<div>` in this step. The other controls are UI placeholders for now.
4. **WordPress's Dimensions semantics — mutual exclusion.** Minimum height and Aspect ratio are mutually exclusive. Setting Minimum height to a value resets Aspect ratio to "Original". Setting Aspect ratio to anything other than "Original" clears Minimum height. The implementation must respect this behaviour.
5. **Three-state controls.** Most WordPress inspector controls have no value at all until the user first interacts with them. They then transition to a value (which may equal a placeholder default such as `""` for Minimum height or `default` for Aspect ratio) and from there to a non-default value. The implementation must handle all three states: missing entirely, present-but-default, present-and-non-default.

### The "useful-value" wrapper layer

Because of Rule 5 (and its interaction with Rule 4), do not consume control values directly from the component. Introduce a thin wrapper layer whose sole job is to return a usable value when the control has no value at all, or when its value is a placeholder default that would produce invalid CSS (for example, `""` for Minimum height, which can't be passed through to a CSS variable as-is). Build this layer now, applying best-practice design patterns. Decide sensible fallback values as part of the work.

**Defaults for chart-specific dimensions.** A chart has no inherent default for Minimum height or Aspect ratio. Use the same defaults as v0.12.0: **Minimum height = `15vh`**, **Aspect ratio = `default`** (i.e. determined by content).

### Application scope

- All controls in **Dimensions**, **Border & Shadow**, and `Color → Background` apply to the outer `<div>` of the block.
- All other controls are UI placeholders only in this step.

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
4. **Container dimensions:** read the `<div>`'s actual pixel `W` (width) and `H` (height).
5. **SVG setup:** create an SVG with `width="100%"`, `height="100%"`, `viewBox="0 0 W H"`. This gives a 1:1 mapping between pixels and SVG units.
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

## When the rebuild is complete

When Step 7 lands and the block ships, this document is no longer authoritative — `docs/blocks.md` is. Either delete `docs/elevation-rebuild.md`, or move it to `docs/archive/` if the narrative is worth keeping.
