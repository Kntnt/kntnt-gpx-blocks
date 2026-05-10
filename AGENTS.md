# AGENTS.md

Guidance for AI coding agents (Claude Code, Copilot, Cursor, Codex, …) working with code in this repository.

## Coding standards

@docs/coding-standards.md

## Project context

`kntnt-gpx-blocks` is a WordPress plugin that registers two Gutenberg blocks plus a `[kntnt-gpx <key>]` shortcode and a `core/group` block-variation for visualising GPX tracks: **GPX Map** (a Leaflet-based map of the recorded track with optional waypoints), **GPX Elevation** (a custom-SVG elevation profile with a cursor synced to the map), and the **GPX Statistics** variation — a `core/group` of `core/paragraph` rows (with `scope: ['inserter']` so it appears as a standalone item in the block inserter) whose `content` carries `[kntnt-gpx <key>]` inline, surfacing total distance, min/max elevation, and total ascent/descent in plain paragraphs. The shortcode is equally usable outside the variation: in any paragraph, heading, list item, classic block, or widget on the same page. GPX Map is the data source. The Elevation block and the shortcode resolve "which Map is on this page" via a sibling binding model: each carries a `mapId` (block attribute on Elevation, `map="…"` shortcode attribute on the shortcode) that defaults to `"auto"` and resolves to the single GPX Map on the page.

## Architecture

See `docs/design.md` for the original design brief and `docs/architecture.md` for the resolved architecture (data flow, caching, rendering, cross-block sync, consent, error handling). Decisions made during the design phase are captured in `docs/architecture.md` and supersede `docs/design.md` where the two differ. Highlights: GPX is parsed once on upload and stored as GeoJSON plus pre-computed statistics in attachment post-meta, versioned via a plugin constant and an MD5 of the source file. Both blocks are dynamic (`render` field in `block.json`); the Statistics variation is `core/paragraph`s whose `content` carries `[kntnt-gpx <key>]` inline, with the shortcode resolved through `Bindings\Statistics_Shortcode` against `Resolve_Map_Id` + `Attachment_Cache` + `Value_Formatter`. Client data is hydrated via the WordPress Interactivity API. Cross-block cursor sync uses a namespaced Interactivity store keyed by `mapId` carrying a single `fraction` (0..1) value, written and read by both Map and Elevation. OpenStreetMap tile loading is gated by a CMP-neutral, **JavaScript-only** consent contract: a JS global `window.kntnt_gpx_blocks` exposing `getConsent`/`mayProceed`/`onConsentChanged`, plus an inbound JS event `kntnt_gpx_blocks:consent` (tristate — `true`/`false`/`null` with default-allow on absent). The plugin exposes no PHP filter for consent; site builders integrate by dispatching the JS event from their CMP's opt-in/opt-out hooks. The plugin works fully without any CMP installed; the site builder writes a glue snippet to bridge their CMP (any CMP, including but not limited to Real Cookie Banner, Complianz, CookieYes) to this contract. The plugin's own code does *not* call `wp_has_consent()`, does *not* listen for `wp_listen_for_consent_change`, and contains no reference to any specific CMP. See `docs/consent.md` for the full contract.

## When to load which doc

The `docs/` directory holds deep specs. Don't read all of them every time — load only the docs relevant to the current task so the context window stays focused on the work at hand.

| Task | Read |
|---|---|
| Big-picture orientation | [`docs/architecture.md`](docs/architecture.md) |
| Implementing or modifying a specific block | [`docs/blocks.md`](docs/blocks.md) plus [`docs/architecture.md`](docs/architecture.md) for the data flow |
| Touching the GPX Statistics variation or the `[kntnt-gpx]` shortcode | [`docs/blocks.md`](docs/blocks.md) (Statistics variation section) plus [`docs/architecture.md`](docs/architecture.md) |
| Touching the GPX parser, conversion, or cache | [`docs/caching.md`](docs/caching.md) and [`docs/security.md`](docs/security.md) |
| Touching consent gating or the placeholder | [`docs/consent.md`](docs/consent.md) |
| Hardening security or reviewing input validation | [`docs/security.md`](docs/security.md) |
| Looking up a public filter | [`docs/hooks.md`](docs/hooks.md) |
| Writing or running tests | [`docs/testing-strategy.md`](docs/testing-strategy.md) |
| Modifying the GitHub-Releases auto-updater or cutting a release | [`docs/updater.md`](docs/updater.md) |
| Locating where a kind of file lives in the repo | [`docs/file-structure.md`](docs/file-structure.md) |
| The original design brief, before architectural decisions were made | [`docs/design.md`](docs/design.md) |

## Pre-1.0 policy — no backwards compatibility

This plugin is in **pre-1.0 development**. **There are no users, no installations in the wild, no production posts, and no saved data anywhere except on the maintainer's own machine.** Every commit is to an unreleased product.

**As long as the major version number is `0`, no design or implementation decision shall factor in existing users, existing installations, existing saved post content, existing attribute shapes, existing meta keys, or any other form of backwards compatibility.** That includes: no `block.json` `deprecated` entries, no attribute migrations, no aliasing of old attribute names, no fallback paths "in case someone has the old shape", no concern for "people who already have this in their `post_content`". Pick the cleanest end-state, change the code to match, and ship the breaking change. Breaking changes are free until v1.0.0.

When weighing alternatives, do not raise backwards compatibility as a constraint, do not weight it against other criteria, and do not propose extra work to preserve it. If a user-supplied prompt asks for a migration path or a deprecation while the major version is still `0`, push back: explain that this rule supersedes that request and ask whether the user wants to override it for a specific reason.

This rule sunsets automatically the moment the `Version:` header in `kntnt-gpx-blocks.php` and `"version"` in `package.json` cross `1.0.0`. From that release onward, normal backwards-compatibility discipline applies and this section should be deleted.

## Working in this repo

- Both blocks are implemented; the GPX Statistics `core/group` block-variation + the `[kntnt-gpx <key>]` shortcode (registered by `Bindings\Statistics_Shortcode`) replace what used to be a third block. `classes/` is organised under the `Kntnt\Gpx_Blocks\` namespace into `Bindings\`, `Bootstrap\`, `Cache\`, `Cli\`, `Consent\`, `Conversion\`, `Format\`, `Rendering\`, and `Rest\`, plus the `Plugin` singleton and the `Updater` at the namespace root. Block source lives in `src/blocks/{map,elevation}/` and compiles to `build/blocks/<slug>/`. The plugin's own ES2022 scripts that don't go through `@wordpress/scripts` live in `js/` — currently the consent stub and the `statistics-variation.js` script that calls `registerBlockVariation()`. See [`docs/file-structure.md`](docs/file-structure.md) for the full layout.
- When asked to implement a piece of the plugin, check `docs/architecture.md` first to understand how it fits the whole, then the specific doc for that area.
- All identifiers and comments in source code are English. User-facing strings are translated via `.po`/`.mo` to Swedish (and possibly other languages) using the `kntnt-gpx-blocks` text domain.
- The plugin does **not** run on this machine — there is no live WordPress instance for ad-hoc testing here. To verify behaviour interactively, use WordPress Playground via `@wp-playground/cli`. See `docs/testing-strategy.md`.

## Project-specific conventions

The project follows the standard above verbatim with the instantiations listed in [Project-specific instantiation](docs/coding-standards.md#project-specific-instantiation) at the end of `docs/coding-standards.md`. There are no overrides beyond pinning placeholders to concrete values.

## Cutting a release

The full release sequence is six steps, in order. None are optional — skipping the build-zip + upload pair (steps 4 and 6) means the auto-updater silently sees no new version and users who notice the new tag and try to install GitHub's auto-generated source ZIP end up with a parallel `kntnt-gpx-blocks-<tag>/` plugin alongside the original. See `docs/updater.md` for the underlying mechanism.

1. **Bump the version** in two files (must match exactly): the `Version:` header in `kntnt-gpx-blocks.php` and `"version"` in `package.json`. Pre-1.0 semver: minor when any closed issue is `type:feature`, patch otherwise.
2. **Run all gates** one final time over the merged work: `npm run build && composer test && vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M && npm run test:js`. Plus `npx wp-scripts lint-js src/blocks/` if `src/blocks/` was touched. A green run on every individual issue commit does not guarantee a green run on their union — that is what this gate catches.
3. **Commit and tag.** `git commit -m "Release vX.Y.Z"` then `git tag -a vX.Y.Z -m vX.Y.Z`.
4. **Build the release ZIP.** `./build-release-zip.sh` produces `kntnt-gpx-blocks.zip` in the project root with top-level folder `kntnt-gpx-blocks/` and runtime artefacts only (`vendor/` from `composer install --no-dev`, `build/`, `classes/`, `js/`, etc.). Re-runs `npm ci` and rebuilds, then restores the dev composer install. Working tree stays clean.
5. **Push the commit and the tag.** `git push origin main && git push origin vX.Y.Z`.
6. **Create the GitHub release with the ZIP attached.** `gh release create vX.Y.Z ./kntnt-gpx-blocks.zip --title "vX.Y.Z" --notes "..."`. The `Updater` identifies the right asset by `content_type === "application/zip"`, not by filename, so the stable filename is intentional. Verify with `gh release view vX.Y.Z --json assets --jq '.assets[].contentType'` — must return `application/zip`.

If a user-supplied prompt or instruction omits steps 4 or 6 (e.g. says "no asset" or "skip the ZIP"), flag the contradiction with `docs/updater.md` before proceeding rather than following the prompt literally.
