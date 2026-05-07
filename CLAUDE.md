# CLAUDE.md

Entry point for Claude Code working on this repository. The project context, architecture summary, and conventions live in [`AGENTS.md`](AGENTS.md), which is imported below. Coding standards live in [`docs/coding-standards.md`](docs/coding-standards.md), imported transitively through `AGENTS.md`.

## Project guidance

@AGENTS.md

## When to load which doc

The `docs/` directory holds deep specs. Don't read all of them every time — load only the docs relevant to the current task so the context window stays focused on the work at hand.

| Task | Read |
|---|---|
| Big-picture orientation | [`docs/architecture.md`](docs/architecture.md) |
| Implementing or modifying a specific block | [`docs/blocks.md`](docs/blocks.md) plus [`docs/architecture.md`](docs/architecture.md) for the data flow |
| Touching the GPX parser, conversion, or cache | [`docs/caching.md`](docs/caching.md) and [`docs/security.md`](docs/security.md) |
| Touching consent gating or the placeholder | [`docs/consent.md`](docs/consent.md) |
| Hardening security or reviewing input validation | [`docs/security.md`](docs/security.md) |
| Looking up a public filter | [`docs/hooks.md`](docs/hooks.md) |
| Writing or running tests | [`docs/testing-strategy.md`](docs/testing-strategy.md) |
| Modifying the GitHub-Releases auto-updater or cutting a release | [`docs/updater.md`](docs/updater.md) |
| The original design brief, before architectural decisions were made | [`docs/design.md`](docs/design.md) |

## Working in this repo

- The plugin is in active design. Implementation has not begun. The `classes/` directory contains only `Updater.php` so far. Block source under `src/blocks/` is a placeholder.
- Decisions made during the design phase are captured in `docs/architecture.md` and supersede `docs/design.md` where the two differ.
- When asked to implement a piece of the plugin, check `docs/architecture.md` first to understand how it fits the whole, then the specific doc for that area.
- All identifiers and comments in source code are English. User-facing strings are translated via `.po`/`.mo` to Swedish (and possibly other languages) using the `kntnt-gpx-blocks` text domain.
- The plugin does **not** run on this machine — there is no live WordPress instance for ad-hoc testing here. To verify behaviour interactively, use WordPress Playground via `@wp-playground/cli`. See `docs/testing-strategy.md`.
