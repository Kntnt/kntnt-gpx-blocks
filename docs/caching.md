# Caching

This document describes the cache lifecycle for GPX-derived data. Read it when modifying conversion, the parser, the cache store, or any code that depends on cached attachment data. For the algorithms that consume the cache (Douglas-Peucker, LTTB, climb computation), see [`architecture.md`](architecture.md). For the security properties of conversion (XXE, MIME, file size), see [`security.md`](security.md).

## What is cached

For each `.gpx` attachment in the WordPress media library, the plugin stores up to four post-meta entries:

| Meta key | Type | Contents |
|---|---|---|
| `_kntnt_gpx_blocks_geojson` | string | JSON-encoded GeoJSON `FeatureCollection` containing the track as a `LineString` Feature plus zero or more waypoint `Point` Features. Full-fidelity (no simplification applied at conversion time). |
| `_kntnt_gpx_blocks_statistics` | array | `[ 'distance' => float, 'min_elevation' => float|null, 'max_elevation' => float|null, 'ascent' => float|null, 'descent' => float|null ]`. Computed once with the active climb threshold; ascent/descent are `null` when the track has no elevation data. |
| `_kntnt_gpx_blocks_version` | integer | The `Cache_Version::CURRENT` constant value at the time of the last conversion. Used to invalidate when the conversion algorithm changes. |
| `_kntnt_gpx_blocks_source_hash` | string | MD5 of the file's binary content at the time of the last conversion. Used to invalidate when the file is replaced via FTP or other paths that don't fire `attachment_updated`. |
| `_kntnt_gpx_blocks_error` | string | Set only on failure. Holds an error code (see below). When set, the four other keys may be missing or stale. |

Storage is via standard `update_post_meta()` / `get_post_meta()`. Meta is automatically cleaned up by WordPress when the attachment is deleted — no extra unhook needed.

## Why post-meta and not transients

Post-meta is the right store because:

- The cached data is **logically owned by the attachment**, not by a session or a request. Tying its lifecycle to the attachment is correct.
- Transients can be flushed by object cache backends (Redis, Memcached) at any time. A flush on a high-traffic site would cause every render to re-parse the GPX synchronously — death spiral.
- WordPress core, S3 offload plugins, multisite, and migrations all preserve post-meta automatically.
- A 30 KB GeoJSON payload is well within MySQL's reach in `wp_postmeta.meta_value` (`LONGTEXT`). The 50 000-trackpoint hard cap guarantees the largest plausible GeoJSON stays under ~5 MB even with verbose serialisation.

The custom-table option was rejected for being overkill — it adds a migration burden, breaks export/import tooling, and gains nothing over post-meta for a single-tenant cache.

## When does conversion run

Five triggers, in priority order:

1. **`add_attachment` action.** The plugin's `Bootstrap\Conversion_Hooks::on_added` callback inspects the new attachment's MIME. If `application/gpx+xml`, conversion runs synchronously.
2. **`attachment_updated` action.** Fired when an attachment's metadata changes — including some plugin-driven file replacements like Enable Media Replace. The handler compares the file's current MD5 against `_kntnt_gpx_blocks_source_hash`. If different, conversion runs.
3. **Lazy fallback at render time.** The render function reads `_kntnt_gpx_blocks_version`. If missing, or below `Cache_Version::CURRENT`, or the hash mismatches the file's current MD5, conversion runs synchronously before rendering. This catches files uploaded before plugin activation, files where `add_attachment` failed silently, and stale caches after a plugin update.
4. **WP-CLI command.** `wp kntnt-gpx regenerate [--all|--id=N]` runs conversion for the targeted attachment(s). Use during development, after a deploy that bumped the cache version, or for support cases.
5. **Manual filter-driven regeneration.** Calling `\Kntnt\Gpx_Blocks\Cache\Attachment_Cache::regenerate( $attachment_id )` from any plugin code triggers conversion. Useful from custom hooks the site might add.

## Conversion in five steps

The conversion pipeline lives in `Conversion\` and runs as a single function call from `Cache\Attachment_Cache::regenerate( $attachment_id )`:

1. **Validate.** Check that the attachment exists, the file is on disk, and the file size is below `kntnt_gpx_blocks_max_file_size_bytes` (default 10 MB). Reject otherwise — set `_kntnt_gpx_blocks_error` and abort.
2. **Parse.** `Conversion\Gpx_Parser` opens the file with `XMLReader` and the `LIBXML_NONET` flag (XXE-safe — see [`security.md`](security.md)). It walks the document streamingly, extracting the first `<trk>` (or first `<rte>` as fallback), every `<trkpt>` / `<rtept>` therein, and every `<wpt>`. Track points are collected into a flat array, segments concatenated. Hard-fails if more than `kntnt_gpx_blocks_max_track_points` (default 50 000) trackpoints are seen.
3. **Sanity-check coordinates.** Drop trackpoints with invalid `lat`/`lon` (out of range, NaN, missing). Drop the entire conversion if fewer than two distinct points remain (`'too-few-points'`).
4. **Compute.** `Conversion\Statistics_Calculator` runs Haversine-summation for distance, scans the elevation array for min/max, and runs the hysteresis filter for ascent/descent (threshold from `kntnt_gpx_blocks_climb_threshold_meters`, default 3 m). When the track has no `<ele>` at all, ascent/descent/min/max are `null`. When a fraction of points have `<ele>` (≤50 %), missing values are linearly interpolated; when more than 50 % are missing, treat as no elevation. `Conversion\Geo_Json_Converter` produces the GeoJSON FeatureCollection.
5. **Persist.** Write the four meta keys atomically. Delete `_kntnt_gpx_blocks_error` if it was set previously. Log success at `info` level.

The rendering path (Douglas-Peucker for the polyline, LTTB for the elevation chart) runs at render time, not during conversion. The cache holds full-fidelity data; render-time simplification is fast (typically <5 ms for a thousand-point track).

## Cache version

The constant `Cache_Version::CURRENT` lives in `classes/Cache/Cache_Version.php` as a typed `int` on a final class. Bump it whenever a change to the conversion contract makes existing cached payloads obsolete (a new GPX field captured, a bug fix in distance summation, a different default climb threshold algorithm). Lazy fallback then regenerates each cache on first render after deploy. No manual `wp kntnt-gpx regenerate` is needed except as a way to spread the regeneration cost over the deploy moment instead of over the first render of each cached page.

The cache version is **not** the plugin version. Many plugin releases ship without conversion changes; those don't touch the cache version. Some plugin releases bump the cache version several times if the conversion logic was iterated during development; the constant simply takes the latest value.

## Hash check

The hash check is the second invalidation channel (the version check is the first). It catches scenarios where the file's bytes change but the WordPress hooks don't fire — uploading a replacement via FTP, restoring from a backup, fixing the file with a Composer migration, or any custom path that bypasses `attachment_updated`. The cost is one MD5 of the file at every render that touches a cached attachment; for a 5 MB GPX that's <50 ms on commodity hardware. Acceptable for the safety it buys.

When the hash mismatches, conversion runs and the new hash is stored.

## Error states

When conversion fails, `_kntnt_gpx_blocks_error` is set and the other meta keys are left unchanged (the previous successful conversion's data, if any, remains). The render code reads the error meta first; if present, it returns the appropriate error rendering. Possible codes:

| Code | Cause |
|---|---|
| `'no-track'` | File has neither `<trk>` nor `<rte>`. |
| `'too-few-points'` | Fewer than two valid coordinates after sanitisation. |
| `'too-large'` | File or trackpoint count exceeded the configured caps. |
| `'file-missing'` | Attachment exists but `get_attached_file()` returns a path that doesn't exist on disk. |
| `'parse-failed'` | XMLReader threw on malformed XML. |
| `'wrong-mime'` | The root element is not `<gpx>`. |

Errors are logged via `Plugin::error()` with the attachment ID and code.

## Editor view of the cache

Cache consumers reach the cache through three different paths:

- **Map** — the editor preview is a parallel React component (`MapEditorPreview` in `src/blocks/map/editor-preview.tsx`) that mounts Leaflet directly inside the editor iframe. It fetches the cached GeoJSON through a dedicated, auth-gated REST endpoint `kntnt-gpx-blocks/v1/preview/<id>` served by `Rest\Preview_Controller`. The endpoint reuses `Attachment_Cache` so the editor sees exactly the same payload the frontend will hydrate. The reason for the parallel path: the Interactivity API runtime does not bootstrap inside ServerSideRender's injected DOM, so the frontend `view.ts` mount cannot run in the editor. See [`architecture.md`](architecture.md) *Editor integration* for the full reasoning.
- **Elevation** — the Edit component uses `<ServerSideRender>`, which goes through the same `the_content` render path as the frontend. ServerSideRender is invalidated automatically when the watched block attributes change, so changing the GPX file in the media picker triggers a refetch.
- **GPX Statistics variation** — each bound `core/paragraph` (inserted by the `core/group` block-variation `kntnt-gpx-blocks-statistics`) resolves through `Bindings\Statistics_Source::get_value()`, which calls `Attachment_Cache::get()` for the resolved attachment. The source's per-request memo (`"$post_id|$map_id"`) means the five binding-key calls per inserted variation share a single cache fetch.

Both paths read from the same `Attachment_Cache`, so editor previews stay consistent with the frontend without any extra cache invalidation work.

If you ever need to expose cached data to a non-WordPress consumer (e.g. a headless frontend), extend `Rest\Preview_Controller` or add a sibling controller. Don't introduce a new internal access path for cached data — go through `Attachment_Cache::get()` so the version + hash invalidation logic stays in one place.
