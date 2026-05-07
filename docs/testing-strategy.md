# Testing strategy

This document specifies what is tested, with what tooling, and what is deliberately not tested. Read it when adding tests, modifying the build pipeline, or deciding whether a change needs new test coverage. For the toolchain itself, see [`coding-standards.md`](coding-standards.md).

## Test pyramid

| Layer | Tooling | Where | What it covers |
|---|---|---|---|
| PHP unit | Pest + Brain Monkey + Mockery | `tests/Unit/` | Conversion (parser, GeoJSON converter, statistics calculator), cache (read/write/version/hash), rendering algorithms (Douglas-Peucker, LTTB, climb hysteresis), value formatters, the `Resolve_Map_Id` algorithm |
| PHP integration | WordPress Playground + Pest | `tests/Integration/` | The plugin actually loads in WordPress, the three blocks register, an end-to-end "upload GPX → block renders" flow works |
| Block JS unit | Jest via `wp-scripts test-unit-js` | `src/blocks/<slug>/*.test.tsx` (co-located) | The Edit components render, attribute setters work, the picker enumerates GPX Map blocks, error states render |
| Block end-to-end | Playwright + `@wordpress/e2e-test-utils-playwright` | `tests/e2e/` | The block can be inserted in the editor, the editor preview matches the frontend, cursor sync between Map and Elevation works |

## What is unit-tested

The conversion pipeline and the rendering algorithms are pure-ish PHP — they take input, produce output, do not touch WordPress globals. They are the heart of the plugin's correctness and the place where regressions would be hardest to spot from manual testing.

### `Conversion\Gpx_Parser`

- Parses a known-good single-track GPX into the expected internal structure.
- Falls back from `<trk>` to `<rte>` when the file has only routes.
- Concatenates multiple `<trkseg>` into a single point sequence.
- Drops trackpoints with invalid `lat`/`lon`.
- Aborts with `'too-many-points'` when the cap is exceeded.
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

WordPress Playground spins up a full WP instance in a browser-WASM sandbox. The plugin is installed, an editor user is logged in, and a fixture GPX file is uploaded through the media REST endpoint. Then:

- The three blocks are insertable in a post.
- A post containing all three blocks renders without PHP errors.
- The cached meta is written after upload.
- The MIME registration accepts `.gpx` uploads (without it, this fails).
- Bumping `KNTNT_GPX_BLOCKS_CACHE_VERSION` triggers regeneration on next render.
- The `wp kntnt-gpx regenerate` CLI command works.

Playground integration tests live in `tests/Integration/` as PHP files and are run via `@wp-playground/cli`. The Playground starts in 1–2 seconds; the suite runs in seconds, not minutes.

## What is JS-unit-tested

`wp-scripts test-unit-js` runs Jest with the WordPress preset. Co-located `*.test.tsx` files exercise:

- `MapEdit`, `ElevationEdit`, `StatisticsEdit` render with various attribute combinations.
- The `useEnsureUniqueMapId` hook generates an ID when missing and regenerates on collision.
- The data-source picker enumerates GPX Map blocks correctly given a mocked block tree.
- Error states (no map, multiple maps, no attachment) render the expected notices.

The tests mock `@wordpress/block-editor`, `@wordpress/data`, and `@wordpress/server-side-render` via `jest.mock()` so the components can be exercised without a full editor instance.

## What is end-to-end-tested

Playwright drives a WordPress Playground instance with the plugin installed:

- Insert GPX Map, upload a fixture GPX, verify the polyline appears.
- Insert GPX Elevation, verify the chart appears and shares data with the Map.
- Hover the chart, verify the cursor moves on the Map.
- Hover the Map, verify the cursor moves on the chart.
- Insert GPX Statistics, verify the values match the fixture's known totals.
- Add the consent placeholder when the test simulates "no consent" state, click activate, verify the map appears.

E2E is the most expensive layer. Keep it focused on the cross-block flows that unit and integration cannot cover.

## What is deliberately not tested

- **Leaflet itself.** Any Leaflet code we write has Leaflet-specific assertions; we do not test that Leaflet's `L.geoJSON` returns something useful. Leaflet has its own test suite.
- **WordPress core.** We do not test that `register_block_type()` registers a block. That's WordPress's responsibility.
- **The Updater against live GitHub.** Testing the Updater's API call against the real GitHub Releases endpoint would require either network access in CI or a mock that drifts from the real API. Instead, the Updater is unit-tested by stubbing `wp_remote_get` via Brain Monkey to return canned API responses.
- **Visual rendering.** No screenshot diff tests. The cost-to-value ratio is poor for a plugin where the visual appearance is theme-dependent.
- **Translations.** We do not test that translation files load — that's a WordPress responsibility — but we do test that the translation strings used in code match the strings in `kntnt-gpx-blocks.pot`.

## Coverage policy

Aim for high coverage of `Conversion\`, `Cache\`, `Rendering\` (the algorithms), and `Format\`. These are the parts most likely to harbour subtle bugs that pass code review. The render PHP files (`Rendering\Render_*`) are mostly composition — their unit tests focus on the output structure given canned data; integration tests cover the wiring.

Coverage of `Bootstrap\` and `Plugin` is exercised by the integration tests (the plugin loads → registrations work). Unit-testing them in isolation has poor leverage because they're mostly `add_filter` / `add_action` calls.

JavaScript coverage focuses on the Edit components and `useEnsureUniqueMapId`. The frontend `view.ts` is mostly Leaflet wiring and DOM manipulation; coverage is via E2E rather than Jest.

## Running the suite

| Layer | Command |
|---|---|
| PHP unit | `composer test` |
| PHP static analysis | `composer phpstan` |
| PHP code style | `composer phpcs` |
| Block JS unit | `npm run test:js` |
| Block JS lint | `npm run lint:js` |
| Block CSS lint | `npm run lint:css` |
| Integration | `npm run test:integration` (wraps `@wp-playground/cli`) |
| End-to-end | `npm run test:e2e` |
| Everything | `composer test:all` |

CI runs `composer test:all` on push. The full suite finishes in under two minutes on commodity GitHub Actions runners.
