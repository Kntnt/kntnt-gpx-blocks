# Kntnt GPX Blocks

[![Requires WordPress: 6.5+](https://img.shields.io/badge/WordPress-6.5+-blue.svg)](https://wordpress.org)
[![Requires PHP: 8.4+](https://img.shields.io/badge/PHP-8.4+-blue.svg)](https://php.net)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)

WordPress plugin that adds three Gutenberg blocks for visualising GPX tracks: **GPX Map** (an interactive map of the recorded route), **GPX Elevation** (an elevation profile chart), and **GPX Statistics** (a clean summary of distance, min/max elevation, total ascent and descent). The blocks are designed to work together — moving the cursor over the elevation profile highlights the matching point on the map, and vice versa — but each block can also be used on its own.

## Description

Take a GPX file from Strava, Komoot, Garmin Connect, AllTrails, or your own watch, drop it into the WordPress media library, and turn it into a polished route presentation in three blocks: a map, an elevation profile, and a clean summary of the key numbers. No external services, no client-side GPX parsing, no JavaScript libraries fighting your theme — just three blocks that look like they were always part of WordPress.

### Built like a Core block

Every setting uses the same color picker, the same typography panel, and the same alignment controls you already know from Paragraph and Group. Defaults follow your theme automatically. Drop the blocks into a column, a group, a cover — anywhere a Core block fits, these fit too.

### Coordinated, not coupled

The three blocks talk to each other when they're on the same page, but you choose the layout. Place the map full-width at the top of an article, the elevation profile in a sidebar, and the statistics under a hero image — they all stay in sync.

### Privacy by default

Map tiles come from OpenStreetMap, which means visitor IPs are sent to a third party. The plugin won't load a single tile until your visitors have given consent through your existing cookie-consent plugin. Until then, a clean placeholder shows where the map will appear.

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
| WordPress | 6.5 |

The plugin checks the requirements on activation and aborts with a clear error message if any requirement is not met.

### GPX Map block

*Stub — to be expanded.* Insert the **GPX Map** block, upload or pick a `.gpx` file from the media library, and the recorded track is drawn on an OpenStreetMap-based map. Use the block sidebar to toggle zoom buttons, scale, fullscreen, and the GPX download button; switch interactions like dragging, scroll-wheel zoom, and pinch zoom on or off; and choose colours for the track, cursor, and waypoint markers.

### GPX Elevation block

*Stub — to be expanded.* Insert the **GPX Elevation** block on a page that already contains a GPX Map. The elevation profile is rendered as a clean SVG chart with distance on the x-axis and elevation on the y-axis. Hovering the chart moves the cursor on both the chart and the map; hovering the track on the map moves the cursor on the chart.

### GPX Statistics block

*Stub — to be expanded.* Insert the **GPX Statistics** block on a page with a GPX Map to show total distance, lowest and highest elevation, and total ascent and descent. Values are formatted in your site's locale (12,3 km in Swedish, 12.3 km in English) and are calculated server-side; the block works without JavaScript.

### Dos and don'ts

**Do** place all three blocks in the same scope. They communicate by reading each other's attributes from the post content, so they must live in the same post (or the same template part, or the same synced pattern). A GPX Map in the post content and a GPX Elevation in a header template part will not connect — the elevation block will not find the map.

**Do** use the auto-binding when there is one map per page. With a single GPX Map on the page, both GPX Elevation and GPX Statistics resolve to it automatically. Only set the data source explicitly when you genuinely have several maps on the same page.

**Do** check the file size before uploading. The plugin caps GPX uploads at 10 MB by default. Most real-world GPX files are well under this — a 50 km hike at one-second resolution is around 2 MB — but multi-day raw recordings can be larger. Simplify them in your GPX editor before uploading.

**Don't** split the three blocks across different scopes. If the GPX Map is in a template part and you want the elevation profile inside an article, the only reliable solution today is to put both blocks in the article. Cross-scope discovery is not supported in v1.

**Don't** enable the GPX download button for tracks that contain personally identifiable locations — for example, a track that starts at the rider's home or a daily commute — unless the file has first been scrubbed of those locations. The download button gives every visitor the original recording, which means precise GPS coordinates of every point along the route.

**Don't** upload other people's GPX files without permission. A GPS recording can reveal where someone lives, works, and exercises. Treat it as personal data even when it is shared with you informally.

### User FAQ

**Why does this plugin exist?**

Most existing GPX/Leaflet plugins for WordPress either ship a generic shortcode that ignores the block editor, or wrap a third-party JavaScript widget that loads everything from an external CDN. This plugin is built block-first: every control matches the WordPress design system, the GPX file is parsed once on the server, and visitors never download the raw track or fight a runaway scroll-zoom.

**Are visitors tracked when they look at a map?**

The map tiles are loaded from OpenStreetMap's servers, which receive each visitor's IP address. The plugin does not load any tiles until consent has been given through your cookie-consent plugin (any plugin that implements the WordPress Consent API works automatically; Real Cookie Banner, Complianz, and CookieYes are tested integrations). Until consent is given, a placeholder is shown with a button the visitor can click to activate just that map for the current page view.

**Does it work without JavaScript?**

The **GPX Statistics** block is rendered server-side and works in any browser, with or without JavaScript. The **GPX Map** and **GPX Elevation** blocks require JavaScript and show a `<noscript>` fallback with the key numbers when JavaScript is disabled.

**How can I get help or report a bug?**

Open an issue on GitHub: <https://github.com/Kntnt/kntnt-gpx-blocks/issues>. Include your WordPress version, your PHP version, the active theme, and (if possible) the GPX file that triggers the problem.

## For Builders

This section is for developers who want to integrate the plugin with consent plugins or other systems using WordPress hooks. It assumes familiarity with PHP and WordPress development.

### Connecting a Cookie Consent Plugin

*Stub — to be expanded.* The plugin defers all OpenStreetMap tile requests until consent has been granted. By default it integrates with any plugin that implements the [WordPress Consent API](https://github.com/rlankhorst/wp-consent-level-api) — Real Cookie Banner, Complianz, and CookieYes are confirmed working out of the box. Three filters control the integration: `kntnt_gpx_blocks_consent_required` (return `false` to disable gating entirely, e.g. for a self-hosted tile server), `kntnt_gpx_blocks_consent_category` (the consent category, default `'marketing'`), and `kntnt_gpx_blocks_consent_service` (the service identifier, default `'openstreetmap'`).

### Developer Hooks

*Stub — full reference will live in `docs/hooks.md`.* The plugin exposes filters for the parameters most likely to need site-specific tuning:

| Filter | Default | Purpose |
|---|---|---|
| `kntnt_gpx_blocks_consent_required` | `true` | Whether tile loading requires consent at all. |
| `kntnt_gpx_blocks_consent_category` | `'marketing'` | Consent category checked against the WP Consent API. |
| `kntnt_gpx_blocks_consent_service` | `'openstreetmap'` | Service identifier for plugins that track per service. |
| `kntnt_gpx_blocks_max_file_size_bytes` | `10485760` (10 MB) | Hard cap on uploaded GPX size. |
| `kntnt_gpx_blocks_max_track_points` | `50000` | Maximum number of trackpoints accepted. |
| `kntnt_gpx_blocks_track_simplification_meters` | `5.0` | Douglas-Peucker tolerance for the rendered polyline. |
| `kntnt_gpx_blocks_elevation_target_points` | `300` | Target point count after LTTB downsampling for the elevation chart. |
| `kntnt_gpx_blocks_climb_threshold_meters` | `3.0` | Hysteresis threshold for ascent/descent calculation. |
| `kntnt_gpx_blocks_format_distance` | — | Override the formatted distance string. |
| `kntnt_gpx_blocks_format_elevation` | — | Override the formatted elevation string. |
| `kntnt_gpx_blocks_default_waypoint_name` | `''` | Placeholder name for waypoints without a `<name>` element. |
| `kntnt_gpx_blocks_placeholder_text` | (translated string) | Text shown on the consent placeholder. |

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
composer test          # PHPUnit/Pest unit tests
composer phpstan       # Static analysis (PHPStan, max level)
composer phpcs         # WordPress Coding Standards lint
npm run lint:js        # ESLint via wp-scripts
npm run lint:css       # Stylelint via wp-scripts
npm run test:js        # Block JS unit tests via wp-scripts test-unit-js
```

Integration tests run against a WordPress Playground instance — see `tests/Integration/README.md` for details.

### Building a Release ZIP

```bash
./build-release-zip.sh
```

The script runs a fresh `composer install --no-dev --optimize-autoloader`, runs `npm run build` to produce the block bundles, and packages everything except development files into `kntnt-gpx-blocks-vX.Y.Z.zip`. The resulting ZIP is the file users upload via WordPress's Plugins → Add New screen.

### Architecture Overview

The plugin parses the uploaded GPX file once on the server, converts it to GeoJSON plus a small bundle of pre-computed statistics, and caches both on the attachment as post-meta. All three blocks render dynamically (server-side, via the `render` field in `block.json`) and pass their data to the client through the WordPress Interactivity API. The map and the elevation chart synchronise their cursor through a shared interactivity state keyed by a per-map identifier. Tile loading is gated behind the WordPress Consent API. The plugin checks for new versions on GitHub via a built-in `Updater` class that hooks into the WordPress plugin update system.

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
