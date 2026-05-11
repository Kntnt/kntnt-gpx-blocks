# Repository layout

A finder's guide to where each kind of file lives in this repository. The component-level responsibilities of each PHP class are listed in [`architecture.md`](architecture.md) § *Component map*. The normative layout rules — including the WordPress-flavoured PSR-4 conventions for `classes/` — are in [`coding-standards.md`](coding-standards.md) § *WordPress plugin project structure*. This doc is purely orientational.

## Plugin root

The plugin's bootstrap files and project-level configuration live at the repo root.

| File | Purpose |
|---|---|
| `kntnt-gpx-blocks.php` | Main plugin file. Carries the WordPress plugin header, the PHP-version guard, the autoloader require, the activation hook registration, and the `Plugin::get_instance()` call. |
| `autoloader.php` | Loads `vendor/autoload.php` if present (Composer's PSR-4 autoloader maps `\Kntnt\Gpx_Blocks\` to `classes/`). |
| `install.php` | Activation-hook handler. Currently a no-op stub reserved for future capability provisioning, cron scheduling, and rewrite-rule flushing. |
| `uninstall.php` | Runs when the plugin is deleted from the admin area. Deletes every `_kntnt_gpx_blocks_*` post-meta entry across the site. Runs without the autoloader. |
| `composer.json` | PHP dependencies, PSR-4 autoload mapping, and the `composer test` / `composer phpstan` / `composer phpcs` script aliases. |
| `package.json` | Block JS/CSS dependencies and the `npm run build` / `npm run start` / `npm run lint:*` scripts that delegate to `@wordpress/scripts`. |
| `build-release-zip.sh` | Builds a distribution ZIP via a fresh `composer install --no-dev` and `npm run build`, packaging everything except development files. |
| `phpcs.xml.dist` | WordPress Coding Standards config for `composer phpcs`. |
| `phpstan.neon.dist` | PHPStan config (max level) with the `szepeviktor/phpstan-wordpress` extension. |
| `tsconfig.json` | TypeScript config for the block source. |
| `CLAUDE.md`, `AGENTS.md`, `README.md` | Entry-point docs (AI agents, humans). |

## `classes/` — PSR-4 PHP source

PHP classes under the `\Kntnt\Gpx_Blocks` namespace, mapped one-to-one to filenames in `classes/` (e.g. `\Kntnt\Gpx_Blocks\Cache\Attachment_Cache` → `classes/Cache/Attachment_Cache.php`). Folders correspond to sub-namespaces:

| Folder | Sub-namespace | Concern |
|---|---|---|
| `Bootstrap/` | `\Kntnt\Gpx_Blocks\Bootstrap` | `Block_Registrar`, `Conversion_Hooks`, `Editor_Data_Enqueuer`, `Mime_Registrar`, `Theme_Json_Aspect_Ratios`, `Theme_Json_Border_Optin`, `Upload_Guard`, `Variation_Registrar`. Wires WordPress hooks at startup. |
| `Cache/` | `\Kntnt\Gpx_Blocks\Cache` | `Attachment_Cache`, `Cache_Version`. Reads and writes the post-meta cache; carries the `CURRENT` typed-int constant for cache invalidation. |
| `Cli/` | `\Kntnt\Gpx_Blocks\Cli` | `Regenerate_Command`. The `wp kntnt-gpx regenerate` WP-CLI command. |
| `Consent/` | `\Kntnt\Gpx_Blocks\Consent` | `Consent_Stub`. Builds the inline JS stub that publishes the `window.kntnt_gpx_blocks` API. |
| `Conversion/` | `\Kntnt\Gpx_Blocks\Conversion` | `Gpx_Parser`, `Geo_Json_Converter`, `Statistics_Calculator`, `Track_Data`, `Track_Point`, `Waypoint`, `Parser_Exception`. The streaming GPX → GeoJSON + statistics pipeline. |
| `Format/` | `\Kntnt\Gpx_Blocks\Format` | `Value_Formatter`. Locale-aware metric formatting via `number_format_i18n()`. |
| `Rendering/` | `\Kntnt\Gpx_Blocks\Rendering` | `Render_Map`, `Render_Elevation`, `Render_Error`, `Error_Renderer`, `Resolve_Map_Id`, `Douglas_Peucker`, `Lttb`. Server-side render of each block plus shared simplification/downsampling/lookup helpers. |
| `Bindings/` | `\Kntnt\Gpx_Blocks\Bindings` | `Statistics_Shortcode`. The `[kntnt-gpx <key>]` shortcode that resolves the five statistics keys (distance, min/max elevation, ascent, descent) inline inside the GPX Statistics block-variation — or anywhere else shortcodes resolve. |
| `Rest/` | `\Kntnt\Gpx_Blocks\Rest` | `Preview_Controller`. The editor-only REST endpoint `kntnt-gpx-blocks/v1/preview/<id>` consumed by the Map block's React-based editor preview. |
| _(root)_ | `\Kntnt\Gpx_Blocks` | `Plugin` (singleton entry point that wires everything) and `Updater` (GitHub-Releases auto-update). |

## `src/blocks/` — block source

TypeScript and SCSS source for the two blocks, compiled by `@wordpress/scripts`. One folder per block:

```
src/blocks/
├── map/
│   ├── block.json          # Block metadata; references render.php and view.ts
│   ├── index.tsx           # Editor entry: imports edit + icon, calls registerBlockType
│   ├── edit.tsx            # MapEdit — InspectorControls and MediaPlaceholder
│   ├── editor-preview.tsx  # MapEditorPreview — React + Leaflet preview inside the editor
│   ├── icon.tsx            # MapIcon — inline SVG block icon (pin over a track segment)
│   ├── render.php          # Server-side render — proxies to Render_Map
│   ├── view.ts             # Frontend Interactivity-API mount path
│   ├── use-ensure-unique-map-id.ts  # Custom hook: 6-char base36 mapId per Map block
│   ├── style.scss          # Frontend + editor styles
│   └── editor.scss         # Editor-only style overrides
└── elevation/
    ├── block.json
    ├── index.tsx
    ├── edit.tsx
    ├── icon.tsx            # ElevationIcon — inline SVG block icon (mountain profile)
    ├── render.php          # Server-side render — proxies to Render_Elevation
    ├── view.ts             # Frontend Interactivity-API mount path
    ├── style.scss
    └── editor.scss
```

Each block is dynamic (`render` field in `block.json`) and uses `viewScriptModule` to load `view.ts` as an ES module that imports `@wordpress/interactivity`.

## `build/` — compiled bundles

Output of `npm run build`. Mirrors `src/blocks/` with one subfolder per block, each containing the bundled `index.js`, `index.css`, `index.asset.php` (script dependencies), and where applicable `view.js` and `view.asset.php`. **`build/` is committed to git** so users who clone the repo do not need a Node toolchain — see [`coding-standards.md`](coding-standards.md) § *Project-specific instantiation* for the rationale.

## `js/` — non-bundled ES2022

Plain ES2022 scripts that are enqueued directly by WordPress without going through the `@wordpress/scripts` build pipeline. Currently holds:

- `consent-stub.js` — the inline-friendly source for the `kntnt-gpx-blocks-consent-stub` script handle that publishes the `window.kntnt_gpx_blocks` API. The contents are inlined into `<head>` by `Consent\Consent_Stub`. See [`consent.md`](consent.md) for the full contract.
- `statistics-variation.js` — calls `window.wp.blocks.registerBlockVariation()` to register the GPX Statistics block-variation of `core/group`. Enqueued in the editor by `Bootstrap\Variation_Registrar` on `enqueue_block_editor_assets`; depends on the `wp-blocks`, `wp-element`, and `wp-i18n` script handles. `wp_set_script_translations()` makes the `__()` calls inside the file pick up entries from the plugin's text domain.

These files are shipped **un-minified**, both in the repo and in the release ZIP. `build-release-zip.sh` copies them verbatim. The decision is deliberate: `consent-stub.js` is part of the public integration contract documented in [`consent.md`](consent.md), so site builders writing CMP glue read the source directly in DevTools without needing source maps; the files are tiny (a few kB each, gzipped to ~2 kB combined over the wire) so the bandwidth gain from minification is below the noise floor; and adding a minifier just for these two files would drag a build step (`terser`/`esbuild`) into a directory whose entire point is "no build". The block code under `src/blocks/` is a separate matter — `@wordpress/scripts` minifies it in production mode, and the minified output in `build/` is what ships.

## `tests/`

Pest-based PHP test suite.

```
tests/
├── Unit/                   # Pest + Brain Monkey + Mockery
│   ├── *Test.php           # One test file per class under test
│   ├── Rest/               # REST-controller tests
│   ├── fixtures/           # Sample GPX files used across tests
│   ├── TestCase.php        # Shared base
│   └── Pest.php            # Pest configuration
└── Integration/            # WordPress Playground via @wp-playground/cli — planned, not yet wired up
```

The unit suite runs via `composer test`. See [`testing-strategy.md`](testing-strategy.md) for the full pyramid (PHP unit, PHP integration, block JS unit, block end-to-end).

## `docs/`

Project specs, all imported by `CLAUDE.md` and read by humans:

| Doc | Topic |
|---|---|
| [`design.md`](design.md) | Original design brief — pre-architecture. Reference only. |
| [`architecture.md`](architecture.md) | Resolved architecture. The source of truth for data flow, rendering, hydration, cross-block sync. |
| [`blocks.md`](blocks.md) | Per-block specs: attributes, editor UI, render output, accessibility. |
| [`caching.md`](caching.md) | Cache lifecycle: meta keys, versioning, hash check, conversion triggers. |
| [`consent.md`](consent.md) | The CMP-neutral consent contract. |
| [`security.md`](security.md) | XXE protection, MIME validation, output escaping, capability gating. |
| [`hooks.md`](hooks.md) | Filter reference. |
| [`testing-strategy.md`](testing-strategy.md) | What is tested, with what, and what is deliberately not. |
| [`updater.md`](updater.md) | GitHub-Releases auto-updater and release process. |
| [`coding-standards.md`](coding-standards.md) | Project coding standard — language, style, naming, tooling. |
| [`file-structure.md`](file-structure.md) | This doc. |

## Reserved for future use

- `migrations/` — version-based PHP migrations. Not present yet; v0.1.x has nothing to migrate.
- `languages/` — translation files (`.pot` source plus per-locale `.po`/`.mo`). Not present yet; user-facing strings are wrapped in `__()` against the `kntnt-gpx-blocks` text domain but no translations have been generated.

## Not in version control

- `vendor/` — Composer dependencies (gitignored). Materialised by `composer install`. Production builds run `composer install --no-dev --optimize-autoloader` via `build-release-zip.sh`.
- `node_modules/` — npm dependencies (gitignored). Materialised by `npm install`. Block bundles are built ahead of time and committed to `build/`, so `node_modules/` is only needed when modifying the block source.
