# Testing strategy

This document specifies what is tested, with what tooling, and what is deliberately not tested. Read it when adding tests, modifying the build pipeline, or deciding whether a change needs new test coverage. For the toolchain itself, see [`coding-standards.md`](coding-standards.md).

## Test pyramid

| Layer | Tooling | Where | What it covers |
|---|---|---|---|
| PHP unit | Pest + Brain Monkey + Mockery | `tests/Unit/` | Conversion (parser, GeoJSON converter, statistics calculator), cache (read/write/version/hash), rendering algorithms (Douglas-Peucker, LTTB, climb hysteresis), value formatters, the `Resolve_Map_Id` algorithm |
| PHP integration | WordPress Playground + Pest | `tests/Integration/` | The plugin actually loads in WordPress, the three blocks register, an end-to-end "upload GPX → block renders" flow works |
| Block JS unit | Jest via `wp-scripts test-unit-js` | `src/blocks/<slug>/*.test.ts(x)` (co-located) | Pure geometry helpers behind the cursor sync; Edit-component coverage is the next target |
| Block end-to-end | Playwright + `@wordpress/e2e-test-utils-playwright` | `tests/e2e/` | The block can be inserted in the editor, the editor preview matches the frontend, cursor sync between Map and Elevation works |

## What is unit-tested

The conversion pipeline and the rendering algorithms are pure-ish PHP — they take input, produce output, do not touch WordPress globals. They are the heart of the plugin's correctness and the place where regressions would be hardest to spot from manual testing.

### `Conversion\Gpx_Parser`

- Parses a known-good single-track GPX into the expected internal structure.
- Falls back from `<trk>` to `<rte>` when the file has only routes.
- Concatenates multiple `<trkseg>` into a single point sequence.
- Drops trackpoints with invalid `lat`/`lon`.
- Aborts with `'too-large'` when the cap is exceeded.
- Aborts with `'wrong-mime'` when the root element isn't `<gpx>`.
- Aborts with `'parse-failed'` on malformed XML.
- Refuses to expand entities (XXE protection — see [`security.md`](security.md)).

The XXE tests use a fixture that includes an external entity reference. The expected result is that the entity is **not** resolved.

### `Conversion\Geo_Json_Converter`

- Produces a valid GeoJSON `FeatureCollection` for a known track.
- Includes waypoint Features with `name`, `sym`, `type`, `desc` properties when present.
- Linearly interpolates missing `<ele>` values when fewer than 50 % are missing.
- Marks elevation as absent when more than 50 % are missing.

### `Conversion\Statistics_Calculator`

- Computes Haversine distance correctly for short and long tracks.
- Identifies min/max elevation correctly.
- Hysteresis filter rejects sub-threshold wobble and preserves real climbs (table-driven tests with synthetic elevation arrays).
- Returns `null` for elevation-derived stats when the track has no elevation.

### `Cache\Attachment_Cache`

- Writes the four meta keys atomically.
- Reads them back correctly.
- Detects version mismatch and triggers regeneration.
- Detects hash mismatch and triggers regeneration.
- Cleans up `_kntnt_gpx_blocks_error` on successful regeneration.

### `Rendering\Douglas_Peucker` and `Rendering\Lttb`

- Polyline simplification keeps endpoints, removes intermediate points within tolerance.
- LTTB returns exactly the requested target point count (or fewer for input below target).
- Both algorithms are stable: same input + same parameters → same output bytes.

### `Rendering\Resolve_Map_Id`

- Returns the single Map's data when one exists and `mapId === "auto"`.
- Returns `'no-map'` when the post has no GPX Map block.
- Returns `'multiple-maps'` when more than one and `mapId === "auto"`.
- Returns the explicit Map's data when `mapId` matches.
- Returns `'map-not-found'` when explicit `mapId` doesn't match any Map.

### `Format\Value_Formatter`

- Distance below 1000 m formats as metres (no decimals).
- Distance ≥1000 m formats as kilometres (one decimal).
- Locale switching changes thousands separator and decimal mark via `number_format_i18n`.

## What is integration-tested

**Not yet wired up.** The `tests/Integration/` directory does not exist yet. The intended scope — when the integration layer is added — is:

WordPress Playground spins up a full WP instance in a browser-WASM sandbox. The plugin is installed, an editor user is logged in, and a fixture GPX file is uploaded through the media REST endpoint. Then:

- The three blocks are insertable in a post.
- A post containing all three blocks renders without PHP errors.
- The cached meta is written after upload.
- The MIME registration accepts `.gpx` uploads (without it, this fails).
- Bumping `Cache_Version::CURRENT` triggers regeneration on next render.
- The `wp kntnt-gpx regenerate` CLI command works.

Playground integration tests will live in `tests/Integration/` as PHP files and will be run via `@wp-playground/cli`. The Playground starts in 1–2 seconds; the suite runs in seconds, not minutes.

## What is JS-unit-tested

`wp-scripts test-unit-js` runs Jest with the WordPress preset, wired up via `npm run test:js`. Tests live co-located with the source they cover (`src/blocks/<slug>/<name>.test.ts(x)`), matching the convention in [`coding-standards.md`](coding-standards.md).

What ships today:

- `src/blocks/map/geometry.test.ts` — covers the pure geometry helpers used by the cursor sync: `lowerBoundIndex`, `fractionToLatLng`, `clickToFraction`. Edge cases include `fraction = 0` and `fraction = 1`, zero-length segments, clicks exactly on a vertex, clicks on segment endpoints, and clicks on the line's extension.
- `src/blocks/elevation/geometry.test.ts` — covers `interpolateSample` and `sampleToSvg` with the same edge-case coverage and asserts that the SVG projection uses the padded `yMin` / `yMax` rather than the raw LTTB min/max.

The geometry helpers are pure functions — no DOM, no Leaflet — so the tests run fast and need no environment beyond what `@wordpress/scripts` ships.

What is not yet covered (intended future scope):

- `MapEdit`, `ElevationEdit`, `StatisticsEdit` render with various attribute combinations.
- The `useEnsureUniqueMapId` hook generates an ID when missing and regenerates on collision.
- The data-source picker enumerates GPX Map blocks correctly given a mocked block tree.
- Error states (no map, multiple maps, no attachment) render the expected notices.

The Edit-component tests will mock `@wordpress/block-editor`, `@wordpress/data`, and `@wordpress/server-side-render` via `jest.mock()` so the components can be exercised without a full editor instance.

## What is end-to-end-tested

**Not yet wired up.** No `tests/e2e/` directory exists and `npm run test:e2e` is not defined in `package.json`. The intended scope — when the E2E layer is added — is:

Playwright drives a WordPress Playground instance with the plugin installed:

- Insert GPX Map, upload a fixture GPX, verify the polyline appears.
- Insert GPX Elevation, verify the chart appears and shares data with the Map.
- Hover the chart, verify the cursor moves on the Map.
- Hover the Map, verify the cursor moves on the chart.
- Insert GPX Statistics, verify the values match the fixture's known totals.
- Simulate a "denying" consent state by dispatching `window.dispatchEvent(new CustomEvent('kntnt_gpx_blocks:consent', { detail: { category: 'external_media', granted: false } }))` before the map renders, and verify no tile request reaches `tile.openstreetmap.org`. Then dispatch the same event with `granted: true` and verify the map mounts. Verify that the default state (no event dispatched) loads tiles — the spec's default-allow rule.
- Verify the editor bypass: in a `block-renderer` REST request, the map mounts regardless of consent state.

E2E is the most expensive layer. Keep it focused on the cross-block flows that unit and integration cannot cover.

## What is deliberately not tested

- **Leaflet itself.** Any Leaflet code we write has Leaflet-specific assertions; we do not test that Leaflet's `L.geoJSON` returns something useful. Leaflet has its own test suite.
- **WordPress core.** We do not test that `register_block_type()` registers a block. That's WordPress's responsibility.
- **The Updater against live GitHub.** Testing the Updater's API call against the real GitHub Releases endpoint would require either network access in CI or a mock that drifts from the real API. Instead, the Updater is unit-tested by stubbing `wp_remote_get` via Brain Monkey to return canned API responses.
- **Visual rendering.** No screenshot diff tests. The cost-to-value ratio is poor for a plugin where the visual appearance is theme-dependent.
- **Translations.** We do not test that translation files load — that's a WordPress responsibility — but we do test that the translation strings used in code match the strings in `kntnt-gpx-blocks.pot`.

## Coverage policy

Aim for high coverage of `Conversion\`, `Cache\`, `Rendering\` (the algorithms), and `Format\`. These are the parts most likely to harbour subtle bugs that pass code review. The render PHP files (`Rendering\Render_*`) are mostly composition — their unit tests focus on the output structure given canned data; integration tests cover the wiring.

Coverage of `Bootstrap\` and `Plugin` will be exercised by integration tests once that layer is added. Unit-testing them in isolation has poor leverage because they're mostly `add_filter` / `add_action` calls.

JavaScript coverage today is the pure geometry helpers behind the cursor sync (`src/blocks/<map|elevation>/geometry.ts`). The next high-leverage targets are the Edit components and `useEnsureUniqueMapId`. The frontend `view.ts` is mostly Leaflet wiring and DOM manipulation; intended coverage is via E2E rather than unit tests.

## Running the suite

The commands below reflect what is currently wired up. Integration and E2E layers are planned but not yet configured.

| Layer | Command | Status |
|---|---|---|
| PHP unit | `composer test` | Available |
| PHP static analysis | `composer phpstan` | Available |
| PHP code style | `composer phpcs` | Available |
| Block JS lint | `npm run lint:js` | Available |
| Block CSS lint | `npm run lint:css` | Available |
| Block JS unit | `npm run test:js` | Available |
| Integration | `npm run test:integration` | Planned (not yet wired) |
| End-to-end | `npm run test:e2e` | Planned (not yet wired) |
