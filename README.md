# Kntnt GPX Blocks

[![Requires WordPress: 6.7+](https://img.shields.io/badge/WordPress-6.7+-blue.svg)](https://wordpress.org)
[![Requires PHP: 8.4+](https://img.shields.io/badge/PHP-8.4+-blue.svg)](https://php.net)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)

WordPress plugin that adds two Gutenberg blocks for visualising GPX tracks — **GPX Map** (an interactive map of the recorded route) and **GPX Elevation** (an elevation profile chart) — plus a **GPX Statistics** block-variation that uses a Block Bindings source to pull total distance, min/max elevation, and total ascent and descent into ordinary paragraphs. The two blocks work together — moving the cursor over the elevation profile highlights the matching point on the map, and vice versa — and either can also be used on its own. The Statistics variation is data-only: layout and theming use core paragraphs and the standard typography and colour controls.

## Description

Take a GPX file from Strava, Komoot, Garmin Connect, AllTrails, or your own watch, drop it into the WordPress media library, and turn it into a polished route presentation: a map, an elevation profile, and a clean summary of the key numbers. No external services, no client-side GPX parsing, no JavaScript libraries fighting your theme — just blocks that look like they were always part of WordPress.

### Built like a Core block

Every setting uses the same color picker, the same typography panel, and the same alignment controls you already know from Paragraph and Group. Defaults follow your theme automatically. Drop the blocks into a column, a group, a cover — anywhere a Core block fits, these fit too.

### Coordinated, not coupled

The blocks talk to each other when they're on the same page, but you choose the layout. Place the map full-width at the top of an article, the elevation profile in a sidebar, and the statistics under a hero image — they all reference the same GPX track via the page's Map block.

### Privacy by default

Map tiles come from OpenStreetMap, which means visitor IPs are sent to a third party. The plugin exposes a small, CMP-neutral consent contract — a JavaScript global and one inbound event — that any cookie-consent plugin can be wired up to with a short glue snippet. Once the glue is in place, no tile request leaves the browser until the visitor has given consent. The plugin renders no consent UI of its own — your cookie-consent plugin handles the visitor-facing flow exactly the way it handles every other third-party service. Out of the box, with no consent plugin installed and no glue written, the map works fully (default-allow on absent signal). Activate the gate by installing a CMP and adding the template in the **For Builders** section.

### Performance you don't have to think about

GPX files are parsed once on the server, converted to a compact format, and cached on the attachment. Your visitors never download the original GPX. The polyline is simplified before render, the elevation profile is downsampled to a few hundred points, and the map only loads when it's about to scroll into view.

## For users

This section is for marketers, website owners, WordPress administrators, and agency staff who want to install, configure, and use the plugin.

### Installation

1. [Download the latest release ZIP file](https://github.com/Kntnt/kntnt-gpx-blocks/releases/latest/download/kntnt-gpx-blocks.zip).
2. In your WordPress admin panel, go to **Plugins → Add New**.
3. Click **Upload Plugin** and select the downloaded ZIP file.
4. Activate the plugin.

The plugin is distributed via GitHub Releases and updated through the standard WordPress plugin update UI. When a new version is available, you will see it on the WordPress Updates page.

#### System Requirements

| Requirement | Minimum |
|-------------|---------|
| PHP | 8.4 |
| WordPress | 6.7 |

The plugin checks the requirements on activation and aborts with a clear error message if any requirement is not met.

### GPX Map block

Insert the **GPX Map** block and, in the **Source** panel on the right, upload or pick an existing `.gpx` file from the media library. The GPX is parsed once on the server and the result is cached; subsequent page loads go straight to the cache. The track is drawn as a polyline on an OpenStreetMap tile layer using Leaflet, and the viewport is automatically fitted to the track bounds. If the file contains `<wpt>` waypoints, they appear as circle markers whose hover tooltips show the waypoint name and optional description from the GPX.

Use the standard **Dimensions** panel to control the block's aspect ratio and minimum height — the same controls every dimensions-aware core block exposes. Empty fields fall back to a 3:1 baseline shape with a 240 px minimum height. The **Controls** panel toggles the four map overlays individually: zoom buttons (on by default), a metric scale bar (on by default), a fullscreen button (off by default), and a GPX download button (off by default — see the "Don'ts" section below). The **Interactions** panel controls the six interaction modes: drag-to-pan (on), scroll-wheel zoom (off by default to avoid scroll hijacking), pinch zoom (on), double-click zoom (on), box zoom via Shift+drag (off), and keyboard navigation (on).

The **Track** panel provides colour pickers for the track polyline and for the cursor marker that appears when hovering either the map or the elevation chart. The **Waypoints** panel configures the marker fill colour, the hover label's background and text colour, and the label's font family, size, weight, and style. All colour inputs accept hex values; all typography inputs accept CSS lengths or theme font-size preset tokens. Leaving any input blank lets the CSS fall back to the hardcoded default in the plugin's stylesheet, so the block integrates cleanly with your theme without requiring any configuration.

When a cookie-consent plugin is wired up to the plugin's consent contract (see **For Builders**) and the visitor has not yet granted consent, the block shows the track polyline, cursor, controls, and waypoint markers over a plain background — no tiles. The polyline is local SVG drawn from cached GeoJSON, so it reaches no third party and is not subject to the consent contract. Only the tile-layer requests are gated; as soon as consent is granted, tiles fade in over the polyline, and if consent is later withdrawn, the tiles are removed but the polyline stays. The plugin renders no consent UI of its own — your cookie-consent plugin handles the visitor-facing flow exactly the way it does for every other third-party service. In the WordPress block editor, the map always shows regardless of consent state — editors need to see the actual map to set up the block. Out of the box, with no cookie-consent plugin installed and no glue written, tiles mount immediately on every page load.

### GPX Elevation block

Insert the **GPX Elevation** block on a page that already contains a GPX Map. The **Datakälla** panel in the block sidebar lets you choose the data source: "Auto" (the default) resolves automatically to the single GPX Map on the page, and is the right choice whenever there is exactly one map. If the page has more than one GPX Map block you must pick the specific map explicitly from the dropdown, which lists each map by its GPX filename. The standard **Dimensions** panel controls the block's aspect ratio and minimum height; empty fields fall back to a 4:1 baseline shape with a 120 px minimum height.

The elevation profile is rendered server-side as an inline SVG chart with distance on the x-axis and elevation (always in metres) on the y-axis. Five evenly spaced tick labels appear on each axis. The x-axis label switches between metres and kilometres automatically at 2000 m. The SVG carries a screen-reader `<desc>` element summarising the profile in text ("Elevation profile from … at the start to … after …, with total ascent … and descent …"), and a `<noscript>` fallback paragraph repeats the same summary for visitors with JavaScript disabled. Because the chart is server-rendered, it is visible even before any JavaScript initialises.

When JavaScript is active, moving the pointer over the SVG highlights a vertical cursor line, a dot on the elevation polyline, and a tooltip showing the current distance and elevation ("3.2 km | 245 m"). Moving the pointer over the GPX Map block's polyline simultaneously moves the cursor on the elevation chart, and vice versa, via a shared Interactivity API state keyed by the map's identifier. The cursor hides when the pointer leaves either block. The downsampling algorithm (LTTB) keeps up to 300 points by default — configurable via the `kntnt_gpx_blocks_elevation_target_points` filter — preserving visually significant peaks and valleys while keeping the SVG compact.

The **Colours** panel provides seven colour pickers: background, axis lines, axis tick labels, the elevation line, the cursor, and the cursor tooltip's background and text colour. Two typography groups — **Axis typography** and **Tooltip typography** — let you set font family, size, weight, and style independently for the axis labels and the tooltip text. Leaving any colour or typography input blank falls back to the plugin's CSS defaults, which inherit from your theme.

### GPX Statistics

Insert the **GPX Statistics** block from the inserter (under the **Kntnt** category) on a page that already contains a GPX Map. It appears alongside the GPX Map and GPX Elevation blocks in the main block list. What you actually insert is a `core/group` block-variation: a 2×3 grid of label/value paragraph pairs where the first row spans both columns for the total length and the four remaining rows show lowest and highest elevation, total ascent, and total descent. Each value paragraph uses Block Bindings to pull its number from the page's GPX Map automatically; you do not need to configure anything. When the page has more than one Map block you can edit a value paragraph's binding (in the block-editor source view) to add `"mapId":"<id>"`; otherwise the values auto-resolve to the single Map on the page.

Once inserted, the layout is built from ordinary `core/paragraph` blocks inside `core/group` containers, so every label, every value, every column, and every spacing rule is editable through the standard block-editor controls — typography panel, colour panel, layout controls, alignment, and so on. There is no separate "Statistics block" type with its own custom theming attributes; theming is whatever your theme and the core controls give you.

Distance is formatted auto-metric: whole metres below 1000 m, one-decimal kilometres above. Elevation is always formatted in whole metres. Both use WordPress's `number_format_i18n()` so the decimal separator and thousands separator respect your site's locale — a Swedish site shows "12,3 km" and "1 234 m" while an English site shows "12.3 km" and "1,234 m". The formatting can be overridden entirely for imperial units via the `kntnt_gpx_blocks_format_distance` and `kntnt_gpx_blocks_format_elevation` filters.

The bound values render server-side. The statistics work in any browser with or without JavaScript enabled. There is no cursor synchronisation with the map or elevation chart — they are a static summary, not an interactive visualisation. When the GPX track contains no elevation data, the four elevation rows render with empty values; if you want them hidden in that case, delete the rows from the inserted block (the variation is editable like any other content).

### Dos and don'ts

**Do** place the blocks in the same scope. They communicate by reading each other's attributes from the post content, so they must live in the same post (or the same template part, or the same synced pattern). A GPX Map in the post content and a GPX Elevation in a header template part will not connect — the elevation block will not find the map.

**Do** use the auto-binding when there is one map per page. With a single GPX Map on the page, both GPX Elevation and GPX Statistics resolve to it automatically. Only set the data source explicitly when you genuinely have several maps on the same page.

**Do** check the file size before uploading. The plugin caps GPX uploads at 10 MB by default. Most real-world GPX files are well under this — a 50 km hike at one-second resolution is around 2 MB — but multi-day raw recordings can be larger. Simplify them in your GPX editor before uploading.

**Don't** split the blocks across different scopes. If the GPX Map is in a template part and you want the elevation profile inside an article, the only reliable solution today is to put both in the article. Cross-scope discovery is not supported in v1.

**Don't** enable the GPX download button for tracks that contain personally identifiable locations — for example, a track that starts at the rider's home or a daily commute — unless the file has first been scrubbed of those locations. The download button gives every visitor the original recording, which means precise GPS coordinates of every point along the route.

**Don't** upload other people's GPX files without permission. A GPS recording can reveal where someone lives, works, and exercises. Treat it as personal data even when it is shared with you informally.

### User FAQ

**Why does this plugin exist?**

Most existing GPX/Leaflet plugins for WordPress either ship a generic shortcode that ignores the block editor, or wrap a third-party JavaScript widget that loads everything from an external CDN. This plugin is built block-first: every control matches the WordPress design system, the GPX file is parsed once on the server, and visitors never download the raw track or fight a runaway scroll-zoom.

**Are visitors tracked when they look at a map?**

The map tiles are loaded from OpenStreetMap's servers, which receive each visitor's IP address. The plugin exposes a small consent contract that any cookie-consent plugin can be wired up to with a short glue snippet (see **For Builders**); once glued, no tile loads until the visitor has consented. With no cookie-consent plugin installed and no glue written, the plugin loads tiles by default — install a CMP if your jurisdiction requires consent for embedded third-party content. The plugin renders no consent UI of its own — your cookie-consent plugin shows the banner, the per-service blocker, and any messaging exactly the way it does for every other third-party service.

**Does it work without JavaScript?**

The **GPX Statistics** values are rendered server-side via Block Bindings and work in any browser, with or without JavaScript. The **GPX Map** and **GPX Elevation** blocks require JavaScript and show a `<noscript>` fallback with the key numbers when JavaScript is disabled.

**How can I get help or report a bug?**

Open an issue on GitHub: <https://github.com/Kntnt/kntnt-gpx-blocks/issues>. Include your WordPress version, your PHP version, the active theme, and (if possible) the GPX file that triggers the problem.

## For Builders

This section is for developers who want to integrate the plugin with consent plugins or other systems using WordPress hooks. It assumes familiarity with PHP and WordPress development.

### Connecting a cookie-consent plugin

The plugin's only consent-requiring action is loading OpenStreetMap tiles in the visitor's browser. To gate that, the plugin exposes a small CMP-neutral consent contract: a JavaScript global and a JavaScript inbound event. The plugin's own code references no specific cookie-consent plugin — *you* write a short JavaScript glue snippet that bridges your CMP to this contract. The contract is the same regardless of which CMP you use (Real Cookie Banner, Complianz, CookieYes, Borlabs, Cookiebot, a homegrown solution, or anything else). The full normative contract lives in [`docs/consent.md`](docs/consent.md); this section gives you the working glue.

The contract uses three values: **`true`** (granting), **`false`** (denying), and **`null`** (absent — no signal yet). The default in the absence of any signal is *permitted*, which is why the plugin works fully without any CMP or glue installed. Only the literal value `false` blocks tile loading. The plugin uses one category — **`external_media`** — and only one. Your glue maps that name to whatever your CMP calls the equivalent group.

The contract is **JavaScript-only.** There is no PHP filter to hook — the only consent-requiring action (Leaflet tile mount) happens in the browser, so the gate lives where the action is. See [`docs/consent.md`](docs/consent.md) section *Why no PHP filter* for the rationale.

#### The JavaScript glue (CMP plugin's "code on opt-in" / "code on opt-out" fields)

Place the following snippets in your CMP's per-service or per-category opt-in/opt-out hook fields. Most CMPs replay these snippets on every page load when the consent is still in force, which is the behaviour the plugin's stub depends on.

On opt-in:

```js
window.dispatchEvent( new CustomEvent( 'kntnt_gpx_blocks:consent', {
    detail: { category: 'external_media', granted: true },
} ) );
```

On opt-out:

```js
window.dispatchEvent( new CustomEvent( 'kntnt_gpx_blocks:consent', {
    detail: { category: 'external_media', granted: false },
} ) );
```

If your CMP does *not* replay the opt-in code on every page load (the great majority of WordPress CMPs do; a small minority do not), add a page-load snippet that reads the CMP's current state and dispatches the corresponding event. Without this, the plugin will load tiles on the first page view after the visitor consented even though they have already accepted.

#### The JavaScript API (for advanced integrations)

The plugin's stub exposes three functions on `window.kntnt_gpx_blocks`. Only `mayProceed` is intended for callers other than the plugin itself; the other two are documented for completeness:

| Function | Purpose |
|---|---|
| `getConsent( 'external_media' )` | Returns the current tristate value: `true`, `false`, or `null`. |
| `mayProceed( 'external_media' )` | Returns `true` when the signal is granting *or* absent; `false` only when denying. |
| `onConsentChanged( handler )` | Subscribes to consent transitions. Returns an unsubscribe function. The handler signature is `( category, granted ) => void`. |

#### Optimisation plugin warning — IMPORTANT

The plugin's consent stub is enqueued as an inline script in `<head>` under the script handle **`kntnt-gpx-blocks-consent-stub`**. The stub *MUST* run before any `kntnt_gpx_blocks:consent` event is dispatched and before the Map block's view module reads the consent state. Optimisation plugins (WP Rocket, Autoptimize, Perfmatters, NitroPack, FlyingPress, SG Optimizer, Hummingbird, etc.) typically defer, delay, combine, or move scripts in ways that break this ordering.

Whenever you use any optimisation plugin, exclude `kntnt-gpx-blocks-consent-stub` from:

- Defer or delay of JavaScript.
- Combination or minification of inline scripts.
- Lazy loading.
- Movement from `<head>` to the footer.

If your optimisation plugin identifies inline scripts by content rather than by handle, the unique fragment to match is `'kntnt_gpx_blocks'` together with `_setConsent`. Both strings appear in the stub source.

#### Bypassing the gate entirely

If you do not need consent gating — for example you self-host tiles, your jurisdiction does not require consent for embedded maps, or it is an internal tool — simply do not install the JavaScript glue. With no `kntnt_gpx_blocks:consent` event ever dispatched, the plugin's default-allow rule keeps the map loading on every page. There is *no* setting on the plugin side that turns the gate "on" — the gate is opt-in via your glue, not opt-out via configuration.

### Developer Hooks

*Stub — full reference lives in [`docs/hooks.md`](docs/hooks.md).* The plugin exposes filters for the parameters most likely to need site-specific tuning:

| Filter | Default | Purpose |
|---|---|---|
| `kntnt_gpx_blocks_max_file_size_bytes` | `10485760` (10 MB) | Hard cap on uploaded GPX size. |
| `kntnt_gpx_blocks_max_track_points` | `50000` | Maximum number of trackpoints accepted. |
| `kntnt_gpx_blocks_track_simplification_meters` | `5.0` | Douglas-Peucker tolerance for the rendered polyline. |
| `kntnt_gpx_blocks_elevation_target_points` | `300` | Target point count after LTTB downsampling for the elevation chart. |
| `kntnt_gpx_blocks_climb_threshold_meters` | `3.0` | Hysteresis threshold for ascent/descent calculation. |
| `kntnt_gpx_blocks_format_distance` | — | Override the formatted distance string. |
| `kntnt_gpx_blocks_format_elevation` | — | Override the formatted elevation string. |

Consent integration is JavaScript-only — see the *Connecting a cookie-consent plugin* section above and [`docs/consent.md`](docs/consent.md).

## For Contributors

This section is for developers who want to contribute code to the plugin — cloning the repository, understanding the architecture, running tests, building releases, and submitting pull requests.

#### Requirements

- **PHP 8.4** locally, matching the runtime requirement.
- **Composer** for PHP dependencies and PSR-4 autoloading.
- **Node.js 20 LTS or later** for building the block editor JavaScript bundles.
- **WordPress Playground CLI** (`@wp-playground/cli`) for running integration tests without setting up a real WordPress instance.

### Building from Source

```bash
git clone https://github.com/Kntnt/kntnt-gpx-blocks.git
cd kntnt-gpx-blocks
composer install
npm install
npm run build
```

`npm run start` watches for changes to JavaScript and SCSS files in `src/blocks/`. `npm run build` produces a production build into `build/`.

### Running tests

```bash
composer test          # Pest unit tests
composer phpstan       # Static analysis (PHPStan, max level)
composer phpcs         # WordPress Coding Standards lint
npm run lint:js        # ESLint via wp-scripts
npm run lint:css       # Stylelint via wp-scripts
```

Block JS unit tests via `wp-scripts test-unit-js`, integration tests against WordPress Playground, and end-to-end tests via Playwright are planned but not yet wired up. See `docs/testing-strategy.md` for the intended scope.

### Building a Release ZIP

```bash
./build-release-zip.sh
```

The script runs a fresh `composer install --no-dev --optimize-autoloader`, runs `npm run build` to produce the block bundles, and packages everything except development files into `kntnt-gpx-blocks.zip`. The filename intentionally has no version segment — the GitHub Releases asset URL stays stable across releases because the per-release tag in the URL already encodes the version. The resulting ZIP is the file users upload via WordPress's Plugins → Add New screen.

### Architecture Overview

The plugin parses the uploaded GPX file once on the server, converts it to GeoJSON plus a small bundle of pre-computed statistics, and caches both on the attachment as post-meta. The two blocks render dynamically (server-side, via the `render` field in `block.json`) and pass their data to the client through the WordPress Interactivity API. The map and the elevation chart synchronise their cursor through a shared interactivity state keyed by a per-map identifier. The GPX Statistics layout is a `core/group` block-variation whose inner `core/paragraph` blocks have a `content` attribute bound to the `kntnt-gpx-blocks/statistics` Block Bindings source; the source resolves the page's GPX Map, reads the cached statistics, and returns the locale-formatted value. OpenStreetMap tile loading is gated by a CMP-neutral, JavaScript-only consent contract that the plugin defines (JS global, JS event); the plugin's own code references no specific cookie-consent plugin. The plugin checks for new versions on GitHub via a built-in `Updater` class that hooks into the WordPress plugin update system.

The detailed specs live in `docs/`:

| Document | Contents |
|---|---|
| [`docs/design.md`](docs/design.md) | Original design brief — the user-visible behaviour each block should have, written before architecture decisions. |
| [`docs/architecture.md`](docs/architecture.md) | Resolved architecture: data flow, block coupling model, render strategy, cross-block synchronisation. |
| [`docs/coding-standards.md`](docs/coding-standards.md) | Project coding standard — language, style, naming, tooling, PHP/WordPress/TypeScript/block-specific rules. |
| [`docs/blocks.md`](docs/blocks.md) | Per-block specification: attributes, editor controls, render output, accessibility. |
| [`docs/caching.md`](docs/caching.md) | Cache lifecycle: meta keys, versioning, hash check, lazy fallback, WP-CLI command. |
| [`docs/consent.md`](docs/consent.md) | Consent integration model and the hooks for consent-plugin authors. |
| [`docs/security.md`](docs/security.md) | Security model: XXE protection, MIME validation, capability checks, output escaping policy. |
| [`docs/file-structure.md`](docs/file-structure.md) | Repository layout and where each kind of file lives. |
| [`docs/testing-strategy.md`](docs/testing-strategy.md) | What is unit-tested, integration-tested, end-to-end-tested, and what is deliberately not. |
| [`docs/updater.md`](docs/updater.md) | How the GitHub-Releases-based update mechanism works and how to cut a release. |

### Contributor FAQ

**How can I contribute?**

Contributions are welcome! Fork the repository, make your changes, and submit a pull request on GitHub. Please read the coding standards in `docs/coding-standards.md` and follow the existing patterns in the codebase.

**Where are the detailed specs?**

All specifications live in the `docs/` directory. Read the relevant doc before implementing a feature — see the [Architecture Overview](#architecture-overview) table for a guide to which doc covers what.

**How are releases distributed?**

The plugin is distributed via GitHub Releases, not wordpress.org. The `Updater` class hooks into the WordPress plugin update system and checks for new GitHub releases automatically. Use `build-release-zip.sh` to create a distribution ZIP — see [Building a Release ZIP](#building-a-release-zip).
