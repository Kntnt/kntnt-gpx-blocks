# Hook reference

Complete reference for the WordPress filters the plugin exposes. Read it when integrating with the plugin from another plugin or theme. The user-facing summary lives in [`README.md`](../README.md). Each filter is invoked with `apply_filters()` from a single call site in the plugin; the contract here is the source of truth.

The plugin exposes only filters in v1. No actions are emitted yet. If a real integration need surfaces — e.g. a downstream cache that must invalidate when a GPX is reconverted — adding an action is a small change. Until then, exposing none keeps the API surface minimal.

All filter names start with `kntnt_gpx_blocks_`.

## Consent

### `kntnt_gpx_blocks_consent_required`

Whether tile loading requires consent at all. Default `true`.

```php
$required = apply_filters( 'kntnt_gpx_blocks_consent_required', true );
```

Return `false` to skip the consent gate entirely. Use this when the site runs a self-hosted tile server, the jurisdiction does not require consent for OSM tiles, or an internal tool has accepted the trade-off.

### `kntnt_gpx_blocks_consent_category`

Consent category checked against the WordPress Consent API. Default `'marketing'`.

```php
$category = apply_filters( 'kntnt_gpx_blocks_consent_category', 'marketing' );
```

Override to `'statistics'` or `'functional'` if your Consent API setup classifies map tiles in a different category. Read once at render time and embedded in the hydrated state.

### `kntnt_gpx_blocks_consent_service`

Service identifier for consent plugins that track consent per service rather than per category. Default `'openstreetmap'`.

```php
$service = apply_filters( 'kntnt_gpx_blocks_consent_service', 'openstreetmap' );
```

Real Cookie Banner is the typical consumer of this value.

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

Applied at server render time, not at conversion time. Lowering increases polyline detail at the cost of more SVG/canvas vertices. Raising smooths the line further. Statistics are computed on full-fidelity data and are unaffected.

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

Read once at conversion time, not per render. Changing the value invalidates cached statistics — you must bump `KNTNT_GPX_BLOCKS_CACHE_VERSION` or run `wp kntnt-gpx regenerate --all` for the change to take effect on existing attachments. The default rejects sub-3-metre wobble, which is the dominant source of GPS noise in consumer recordings. Raising over-corrects (real climbs disappear); lowering under-corrects (noise becomes ascent).

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

Placeholder name used when a waypoint has no `<name>` element. Default `''` (empty — the marker has no hover label at all).

```php
$name = apply_filters( 'kntnt_gpx_blocks_default_waypoint_name', '' );
```

Override to a translated string like `__( 'Waypoint', 'your-textdomain' )` if you prefer every waypoint to have a hover label even when the GPX provides none.

### `kntnt_gpx_blocks_placeholder_text`

Text shown on the consent placeholder before the visitor activates the map. Default is the translated string `"Map is disabled until you accept cookies from OpenStreetMap."`

```php
$text = apply_filters( 'kntnt_gpx_blocks_placeholder_text', $default );
```

Override to fit the site's tone of voice. The filter is read at render time, so the override sees the visitor's locale.

## Versioned cache

The cache version constant `KNTNT_GPX_BLOCKS_CACHE_VERSION` is **not** a filter. It lives in `classes/Cache/Cache_Version.php`. Bumping it invalidates every cached conversion across the site at the next render. See [`caching.md`](caching.md).
