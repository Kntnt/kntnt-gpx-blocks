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

## Tile providers

The GPX Map block resolves its base-tile provider and any overlay layers through `Kntnt\Gpx_Blocks\Rendering\Tile_Layer_Registry`, which exposes two filters for site builders to add, replace, or remove records. The registry is **PHP-canonical** — there is no JS-side registration. Each block carries a `tileProvider` id, a `tileApiKeys` object (per-provider key map keyed by provider id), and a `tileOverlays` id list as attributes; the renderer looks up `tileApiKeys[ tileProvider ]` and forwards it to the registry, which validates the filtered set, substitutes `{KEY}` server-side, and writes the resolved record(s) into the per-block Interactivity state. Unknown provider ids fall back silently to `osm-standard` with a `Plugin::warning()` log; unknown overlay ids are dropped silently with the same log level.

### `kntnt_gpx_blocks_tile_providers`

Map of base-tile providers keyed by provider id. The default ships eight entries: `osm-standard`, `opentopomap`, `cyclosm`, `thunderforest-outdoors`, `thunderforest-landscape`, `stadia-outdoors`, `maptiler-outdoor`, and `mapbox-outdoors`.

```php
$providers = apply_filters( 'kntnt_gpx_blocks_tile_providers', $defaults );
// array<string, array{
//     label: string,        // Translatable display label.
//     url: string,          // Tile URL with {z}/{x}/{y}; {KEY} iff requiresKey.
//     attribution: string,  // HTML snippet — trusted to the filter callback.
//     maxZoom: int,         // 0..22 inclusive.
//     requiresKey: bool,    // Mirrors {KEY} placeholder in url.
//     signupUrl?: string,   // Optional https:// URL where users obtain a key.
//     subdomains?: string[], // Optional Leaflet {s} substitution list.
// }>
```

### `kntnt_gpx_blocks_tile_overlays`

Map of overlay layers keyed by overlay id. The default ships five entries, all free and key-less: `wmt-hiking` (Waymarked Trails Hiking), `wmt-cycling` (Waymarked Trails Cycling), `wmt-mtb` (Waymarked Trails MTB), `openseamap` (OpenSeaMap sea marks), and `opensnowmap` (OpenSnowMap pistes).

```php
$overlays = apply_filters( 'kntnt_gpx_blocks_tile_overlays', $defaults );
// array<string, array{
//     label: string,        // Translatable display label.
//     url: string,          // Tile URL with {z}/{x}/{y}; no {KEY} (overlays
//                           // carry no per-block API key in v1).
//     attribution: string,  // HTML snippet — trusted to the filter callback.
//     maxZoom: int,         // 0..22 inclusive.
//     subdomains?: string[], // Optional Leaflet {s} substitution list.
// }>
```

### Validation rules

Every record is validated at filter-application time. Records that fail any rule are dropped with a `Plugin::warning()` log naming the offending id and the failing constraint:

- The id must match `^[a-z0-9-]+$` (lowercase letters, digits, hyphens; non-empty).
- `url` must start with `https://` and contain the literals `{z}`, `{x}`, `{y}`.
- For base providers: the URL contains `{KEY}` iff `requiresKey === true`.
- For overlays: the URL must not contain `{KEY}` (overlays carry no key in v1).
- `maxZoom` is an integer in `[0, 22]`. Floats and numeric strings are rejected.
- `label` and `attribution` are non-empty strings.
- `signupUrl`, when present, is an `https://` URL.
- `subdomains`, when present, is a non-empty list of non-empty strings.

The HTML in `attribution` is trusted to the filter callback — keep it tightly scoped (anchor tags and entities) and never include script content.

The canonical `osm-standard` provider is always preserved: if a filter callback drops it, the registry re-injects it with a warning so the resolver always has a fallback target.

### Adding a custom provider

```php
add_filter( 'kntnt_gpx_blocks_tile_providers', static function ( array $providers ): array {
	$providers['my-vector'] = [
		'label'       => 'My Vector Tiles',
		'url'         => 'https://tiles.example.com/v1/{z}/{x}/{y}.png?token={KEY}',
		'attribution' => '&copy; <a href="https://example.com/">Example</a>',
		'maxZoom'     => 20,
		'requiresKey' => true,
		'signupUrl'   => 'https://example.com/signup',
	];
	return $providers;
} );
```

The new provider becomes available to every editor on the site; the editor UI for picking a provider lists the validated set returned by this filter.

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
