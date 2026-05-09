# AGENTS.md

Guidance for AI coding agents (Claude Code, Copilot, Cursor, Codex, …) working with code in this repository.

## Coding standards

@docs/coding-standards.md

## Project context

`kntnt-gpx-blocks` is a WordPress plugin that registers two Gutenberg blocks plus a Block Bindings source and a `core/group` block-variation for visualising GPX tracks: **GPX Map** (a Leaflet-based map of the recorded track with optional waypoints), **GPX Elevation** (a custom-SVG elevation profile with a cursor synced to the map), and the **GPX Statistics** variation — a `core/group` of `core/paragraph` rows (with `scope: ['inserter']` so it appears as a standalone item in the block inserter) whose `content` attribute is bound to the `kntnt-gpx-blocks/statistics` Block Bindings source, surfacing total distance, min/max elevation, and total ascent/descent in plain paragraphs. GPX Map is the data source. The Elevation block and the Statistics binding source resolve "which Map is on this page" via a sibling binding model: each carries a `mapId` (attribute or arg) that defaults to `"auto"` and resolves to the single GPX Map on the page.

## Architecture

See `docs/design.md` for the original design brief and `docs/architecture.md` for the resolved architecture (data flow, caching, rendering, cross-block sync, consent, error handling). Highlights: GPX is parsed once on upload and stored as GeoJSON plus pre-computed statistics in attachment post-meta, versioned via a plugin constant and an MD5 of the source file. Both blocks are dynamic (`render` field in `block.json`); the Statistics variation is `core/paragraph`s whose values come from the `kntnt-gpx-blocks/statistics` Block Bindings source (registered with `uses_context: ['postId']`). Client data is hydrated via the WordPress Interactivity API. Cross-block cursor sync uses a namespaced Interactivity store keyed by `mapId` carrying a single `fraction` (0..1) value, written and read by both Map and Elevation. OpenStreetMap tile loading is gated by a CMP-neutral, **JavaScript-only** consent contract: a JS global `window.kntnt_gpx_blocks` exposing `getConsent`/`mayProceed`/`onConsentChanged`, plus an inbound JS event `kntnt_gpx_blocks:consent` (tristate — `true`/`false`/`null` with default-allow on absent). The plugin exposes no PHP filter for consent; site builders integrate by dispatching the JS event from their CMP's opt-in/opt-out hooks. The plugin works fully without any CMP installed; the site builder writes a glue snippet to bridge their CMP (any CMP, including but not limited to Real Cookie Banner, Complianz, CookieYes) to this contract. The plugin's own code does *not* call `wp_has_consent()`, does *not* listen for `wp_listen_for_consent_change`, and contains no reference to any specific CMP. See `docs/consent.md` for the full contract.

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
