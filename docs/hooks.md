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

The GPX Map block resolves its base-tile provider and any overlay layers through `Kntnt\Gpx_Blocks\Rendering\Tile_Layer_Registry`, which exposes two filters for site builders to add, replace, or remove records. The registry is **PHP-canonical** — there is no JS-side registration. Each block carries a `tileProvider` id, a `tileStyle` id, a `tileApiKeys` object (per-provider key map keyed by provider id), and a `tileOverlays` id list as attributes; the renderer looks up `tileApiKeys[ tileProvider ]` and forwards it to the registry, which validates the filtered set, walks the (provider, style) pair down the nested map, substitutes `{KEY}` server-side, and writes the resolved record(s) into the per-block Interactivity state. Unknown provider ids fall back silently to `openstreetmap` (and to its default style) with a `Plugin::warning()` log; unknown style ids inside a known provider fall back to the provider's own `default` style; unknown overlay ids are dropped silently with the same log level.

### `kntnt_gpx_blocks_tile_providers`

Two-level map of base-tile providers keyed by provider id, each carrying a nested `styles` map keyed by style id. The default ships **9 providers** with the following style counts: Carto (3 styles, key-less), Esri (9 styles, key-less), Jawg Maps (5 styles, key-required), Mapbox (5 styles, key-required), MapTiler (9 styles, key-required), OpenStreetMap (2 styles, key-less; `mapnik` default), OpenTopoMap (1 style, key-less), Stadia Maps (6 styles, key-required), Thunderforest (4 styles, key-required). The block.json default seeds `tileProvider: "openstreetmap"`, `tileStyle: "mapnik"`, `tileApiKeys: {}` so a freshly inserted block always renders.

```php
$providers = apply_filters( 'kntnt_gpx_blocks_tile_providers', $defaults );
// array<string, array{
//     label: string,         // Translatable provider display label.
//     requiresKey: bool,     // One API key per provider, shared across all the provider's styles.
//     default: string,       // Style id used when tileStyle is empty or unknown for this provider.
//     styles: array<string, array{
//         label: string,         // Translatable style display label.
//         url: string,           // Tile URL with {z}/{x}/{y}; {KEY} iff provider.requiresKey.
//         attribution: string,   // HTML snippet — trusted to the filter callback.
//         maxZoom: int,          // 0..22 inclusive.
//     }>,
//     signupUrl?: string,    // Optional https:// URL where users obtain a key.
//     subdomains?: string[], // Optional Leaflet {s} substitution list,
//                            // inherited by every style of this provider
//                            // whose URL contains {s}.
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

Every record is validated at filter-application time. The validator follows a **drop-the-narrowest-unit** rule: a bad single style drops just that style with one `Plugin::warning()` log; a provider-level failure (missing required field, `default` resolving to a dropped style, empty `styles` map, `{s}`-using style on a provider without `subdomains`) drops the whole provider. Each drop emits one log naming the failing id and the failing constraint:

- Provider id and style id must match `^[a-z0-9-]+$` (lowercase letters, digits, hyphens; non-empty).
- Provider-level: `label`, `requiresKey` (bool), `default` (non-empty string style id), and `styles` (non-empty map) are required. Optional `signupUrl` is an `https://` URL when present. Optional `subdomains` is a non-empty list of non-empty strings when present.
- Per-style: `label`, `url`, `attribution`, `maxZoom` are required.
- `url` must start with `https://` and contain the literals `{z}`, `{x}`, `{y}`.
- For base providers: the per-style URL contains `{KEY}` iff `provider.requiresKey === true`.
- For overlays: the URL must not contain `{KEY}` (overlays carry no key in v1).
- **`subdomains` inheritance:** a style whose URL contains the `{s}` placeholder requires its containing provider to declare `subdomains` at the provider level — Leaflet substitutes `{s}` against the provider's list. A `{s}`-using style on a provider without `subdomains` is dropped.
- `maxZoom` is an integer in `[0, 22]`. Floats and numeric strings are rejected.
- Provider survives only when at least one style survives validation **and** its declared `default` is among the survivors.

The HTML in `attribution` is trusted to the filter callback — keep it tightly scoped (anchor tags and entities) and never include script content.

The canonical `openstreetmap` provider is always preserved: if a filter callback drops it, the registry re-injects it with a warning so the resolver always has a fallback target. Resolution fall-backs are layered: unknown provider id → global fallback provider + its default style; known provider, unknown style id → provider's own `default` style; defensive fall-through (provider's `default` itself does not resolve) → global fallback provider's default style.

### Adding a custom provider

```php
add_filter( 'kntnt_gpx_blocks_tile_providers', static function ( array $providers ): array {
	$providers['my-vector'] = [
		'label'       => 'My Vector Tiles',
		'requiresKey' => true,
		'default'     => 'streets',
		'signupUrl'   => 'https://example.com/signup',
		'styles'      => [
			'streets' => [
				'label'       => 'Streets',
				'url'         => 'https://tiles.example.com/v1/streets/{z}/{x}/{y}.png?token={KEY}',
				'attribution' => '&copy; <a href="https://example.com/">Example</a>',
				'maxZoom'     => 20,
			],
			'satellite' => [
				'label'       => 'Satellite',
				'url'         => 'https://tiles.example.com/v1/satellite/{z}/{x}/{y}.png?token={KEY}',
				'attribution' => '&copy; <a href="https://example.com/">Example</a>',
				'maxZoom'     => 20,
			],
		],
	];
	return $providers;
} );
```

The new provider becomes available to every editor on the site; the editor UI lists the validated set returned by this filter — first dropdown picks the provider, second dropdown lists that provider's styles, and a single API-key field captures the key shared across all the provider's styles.

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
