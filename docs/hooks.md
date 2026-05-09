# Hook reference

Complete reference for the WordPress filters the plugin exposes. Read it when integrating with the plugin from another plugin or theme. The user-facing summary lives in [`README.md`](../README.md). Each filter is invoked with `apply_filters()` from a single call site in the plugin; the contract here is the source of truth.

The plugin exposes only filters in v1. No actions are emitted yet. If a real integration need surfaces — e.g. a downstream cache that must invalidate when a GPX is reconverted — adding an action is a small change. Until then, exposing none keeps the API surface minimal.

All filter names start with `kntnt_gpx_blocks_`.

## Consent

The plugin exposes **no PHP filter for consent.** The consent contract is JavaScript-only — site builders integrate by dispatching a `kntnt_gpx_blocks:consent` event on `window` from their CMP's opt-in/opt-out hooks. See [`consent.md`](consent.md) for the full normative contract and the rationale (section *Why no PHP filter*).

## Conversion limits

### `kntnt_gpx_blocks_max_file_size_bytes`

Hard cap on uploaded GPX file size, in bytes. Default `10 * 1024 * 1024` (10 MB).

```php
$max = apply_filters( 'kntnt_gpx_blocks_max_file_size_bytes', 10 * 1024 * 1024 );
```

Enforced in `wp_handle_upload_prefilter`; uploads exceeding the cap are rejected before any parsing. Lowering this value protects the host from large files; raising it requires PHP's `upload_max_filesize` and `post_max_size` to allow the new ceiling.

### `kntnt_gpx_blocks_max_track_points`

Maximum number of trackpoints accepted in a single GPX file. Default `50000`.

```php
$max = apply_filters( 'kntnt_gpx_blocks_max_track_points', 50000 );
```

Enforced during streaming parse. When the parser counts more trackpoints than allowed, conversion aborts with `_kntnt_gpx_blocks_error = 'too-large'`. The default supports a 14-hour recording at 1 Hz; raise if you regularly upload longer recordings.

## Rendering

### `kntnt_gpx_blocks_track_simplification_meters`

Douglas-Peucker tolerance for the rendered polyline, in metres. Default `5.0`.

```php
$tolerance = apply_filters( 'kntnt_gpx_blocks_track_simplification_meters', 5.0 );
```

Applied at server render time, not at conversion time. The value is a **perpendicular tolerance** — the maximum deviation between the simplified chord and the original arc — not a sample rate. Lowering increases polyline detail at the cost of more SVG/canvas vertices. Raising smooths the line further. The filter affects the rendered polyline only; the cached GeoJSON is never simplified, so Statistics and Elevation continue to read from full-fidelity data and are unaffected.

### `kntnt_gpx_blocks_elevation_target_points`

Target point count for the elevation chart after LTTB downsampling. Default `300`.

```php
$target = apply_filters( 'kntnt_gpx_blocks_elevation_target_points', 300 );
```

LTTB selects N points from the original elevation array such that visually significant peaks and valleys are preserved. The default produces a smooth chart at typical column widths (600–1200 px). Raising adds detail; lowering simplifies further.

### `kntnt_gpx_blocks_climb_threshold_meters`

Hysteresis threshold for ascent/descent calculation, in metres. Default `3.0`.

```php
$threshold = apply_filters( 'kntnt_gpx_blocks_climb_threshold_meters', 3.0 );
```

Read once at conversion time, not per render. Changing the value invalidates cached statistics — you must bump `Cache_Version::CURRENT` or run `wp kntnt-gpx regenerate --all` for the change to take effect on existing attachments. The default rejects sub-3-metre wobble, which is the dominant source of GPS noise in consumer recordings. Raising over-corrects (real climbs disappear); lowering under-corrects (noise becomes ascent).

## Formatting

### `kntnt_gpx_blocks_format_distance`

Formatted distance string. Default produced by the plugin's locale-aware metric formatter (m below 1000, km above, with `number_format_i18n`).

```php
$formatted = apply_filters( 'kntnt_gpx_blocks_format_distance', $formatted, $metres );
```

The filter receives both the already-formatted string and the raw number of metres so the override can either tweak the default or recompute from scratch. Useful for sites that need imperial units (miles/feet), forced km always, or different decimal precision.

### `kntnt_gpx_blocks_format_elevation`

Formatted elevation string. Default produced by the plugin's metric formatter (always metres, no decimals).

```php
$formatted = apply_filters( 'kntnt_gpx_blocks_format_elevation', $formatted, $metres );
```

Same semantics as the distance filter.

## Editor and rendering text

### `kntnt_gpx_blocks_default_waypoint_name`

**Not yet applied in v1.** This filter is reserved for a future release. Waypoints without a `<name>` element currently receive no hover label. When this filter is applied, it will provide a fallback name for such waypoints.

## Versioned cache

The cache version constant `Cache_Version::CURRENT` is **not** a filter. It lives in `classes/Cache/Cache_Version.php` as a typed `int` on a final class. Bumping it invalidates every cached conversion across the site at the next render. See [`caching.md`](caching.md).
