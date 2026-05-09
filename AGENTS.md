# AGENTS.md

Guidance for AI coding agents (Claude Code, Copilot, Cursor, Codex, …) working with code in this repository.

## Coding standards

@docs/coding-standards.md

## Project context

`kntnt-gpx-blocks` is a WordPress plugin that registers two Gutenberg blocks plus a Block Bindings source and a `core/group` block-variation for visualising GPX tracks: **GPX Map** (a Leaflet-based map of the recorded track with optional waypoints), **GPX Elevation** (a custom-SVG elevation profile with a cursor synced to the map), and the **GPX Statistics** variation — a `core/group` of `core/paragraph` rows (with `scope: ['inserter']` so it appears as a standalone item in the block inserter) whose `content` attribute is bound to the `kntnt-gpx-blocks/statistics` Block Bindings source, surfacing total distance, min/max elevation, and total ascent/descent in plain paragraphs. GPX Map is the data source. The Elevation block and the Statistics binding source resolve "which Map is on this page" via a sibling binding model: each carries a `mapId` (attribute or arg) that defaults to `"auto"` and resolves to the single GPX Map on the page.

## Architecture

See `docs/design.md` for the original design brief and `docs/architecture.md` for the resolved architecture (data flow, caching, rendering, cross-block sync, consent, error handling). Highlights: GPX is parsed once on upload and stored as GeoJSON plus pre-computed statistics in attachment post-meta, versioned via a plugin constant and an MD5 of the source file. Both blocks are dynamic (`render` field in `block.json`); the Statistics variation is `core/paragraph`s whose values come from the `kntnt-gpx-blocks/statistics` Block Bindings source (registered with `uses_context: ['postId']`). Client data is hydrated via the WordPress Interactivity API. Cross-block cursor sync uses a namespaced Interactivity store keyed by `mapId` carrying a single `fraction` (0..1) value, written and read by both Map and Elevation. OpenStreetMap tile loading is gated by a CMP-neutral consent contract: PHP filter `kntnt_gpx_blocks_has_consent` (tristate — `true`/`false`/`null` with default-allow on absent), JS global `window.kntnt_gpx_blocks` exposing `getConsent`/`mayProceed`/`onConsentChanged`, and JS inbound event `kntnt_gpx_blocks:consent`. The plugin works fully without any CMP installed; the site builder writes glue snippets to bridge their CMP (any CMP, including but not limited to Real Cookie Banner, Complianz, CookieYes) to this contract. The plugin's own code does *not* call `wp_has_consent()`, does *not* listen for `wp_listen_for_consent_change`, and contains no reference to any specific CMP. See `docs/consent.md` for the full contract.

## Project-specific conventions

The project follows the standard above verbatim with the instantiations listed in [Project-specific instantiation](docs/coding-standards.md#project-specific-instantiation) at the end of `docs/coding-standards.md`. There are no overrides beyond pinning placeholders to concrete values.
