# AGENTS.md

Guidance for AI coding agents (Claude Code, Copilot, Cursor, Codex, …) working with code in this repository.

## Coding standards

@docs/coding-standards.md

## Project context

`kntnt-gpx-blocks` is a WordPress plugin that registers three Gutenberg blocks for visualising GPX tracks: **GPX Map** (a Leaflet-based map of the recorded track with optional waypoints), **GPX Elevation** (a custom-SVG elevation profile with a cursor synced to the map), and **GPX Statistics** (a server-rendered summary of total distance, min/max elevation, and total ascent/descent). GPX Map is the data source. The other two read from it via a sibling binding model where each block has a `mapId` attribute that defaults to `"auto"` and resolves to the single GPX Map on the page.

## Architecture

See `docs/design.md` for the original design brief and `docs/architecture.md` for the resolved architecture (data flow, caching, rendering, cross-block sync, consent, error handling). Highlights: GPX is parsed once on upload and stored as GeoJSON plus pre-computed statistics in attachment post-meta, versioned via a plugin constant and an MD5 of the source file. All three blocks are dynamic (`render` field in `block.json`). Client data is hydrated via the WordPress Interactivity API. Cross-block cursor sync uses a namespaced Interactivity store keyed by `mapId` carrying a single `fraction` (0..1) value, written and read by both Map and Elevation. Tile loading is gated behind `wp_consent_api` by default, configurable via three filters (`kntnt_gpx_blocks_consent_required`, `kntnt_gpx_blocks_consent_category`, `kntnt_gpx_blocks_consent_service`).

## Project-specific conventions

The project follows the standard above verbatim with the instantiations listed in [Project-specific instantiation](docs/coding-standards.md#project-specific-instantiation) at the end of `docs/coding-standards.md`. There are no overrides beyond pinning placeholders to concrete values.
