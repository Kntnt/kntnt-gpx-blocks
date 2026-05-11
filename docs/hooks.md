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

The GPX Map block resolves its base-tile provider and any overlay layers through `Kntnt\Gpx_Blocks\Rendering\Tile_Layer_Registry`, which exposes two filters for site builders to add, replace, or remove records. The registry is **PHP-canonical** — there is no JS-side registration. Each block carries a `tileProvider` id, a `tileStyle` id, a `tileApiKeys` object (per-provider key map keyed by provider id), a `tileOverlays` array of `{provider, layer}` pairs, and a `tileOverlayApiKeys` object (per-overlay-provider key map keyed by overlay-provider id) as attributes. The renderer looks up `tileApiKeys[ tileProvider ]` and forwards it to the registry, which validates the filtered set, walks the (provider, style) pair down the nested map, substitutes `{KEY}` server-side, and writes the resolved record into the per-block Interactivity state. For each saved overlay pair the registry walks the parallel overlay-provider/layer map, substitutes the per-overlay-provider key from `tileOverlayApiKeys` into `{KEY}` for paid overlay providers, and appends the resolved record to the overlay stack. Unknown provider ids fall back silently to `openstreetmap` (and to its default style) with a `Plugin::warning()` log; unknown style ids inside a known provider fall back to the provider's own `default` style; unknown overlay providers, unknown overlay layers, and overlay pairs whose paid provider has no key are dropped silently with the same log level (the base map and other overlays still render — there is no polyline-only concept for overlays).

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
//     apiKey?: string,       // Optional PHP-supplied API key. Presence
//                            // (not value) engages the PHP path — see
//                            // the "PHP-supplied API key" subsection
//                            // below for the full contract.
// }>
```

#### PHP-supplied API key

The optional `apiKey` field lets a site builder supply a paid provider's API key from PHP — typically from a `wp-config.php` constant — and bypass the per-block `attributes.tileApiKeys` path entirely. This protects the key from any user with `edit_posts` capability who would otherwise be able to read it from `post_content`, the REST API, or the editor's Inspector field.

```php
add_filter( 'kntnt_gpx_blocks_tile_providers', static function ( array $p ): array {
    if ( defined( 'THUNDERFOREST_KEY' ) ) {
        $p['thunderforest']['apiKey'] = THUNDERFOREST_KEY;
    }
    return $p;
} );
```

- **Engagement rule.** `isset( $record['apiKey'] )` — presence, not value — engages the PHP path. The site builder controls where the value comes from (hard-coded string, `wp-config.php` constant via `defined()` guard, environment variable, secrets manager, etc.); plugin code only reads the resolved `apiKey` field.
- **Precedence.** Binary. When the PHP path is engaged for a provider, `attributes.tileApiKeys[ providerId ]` is never read, the editor's API-key TextControl for that provider is hidden with no notice (the provider behaves identically to a free provider in the UI), and both editor preview and frontend use the PHP-supplied key.
- **Fail-closed.** `apiKey === ''` (or whitespace-only) under PHP engagement yields polyline-only state on both frontend and editor preview — same degraded UX as a missing attribute-path key, but the editor field stays hidden. The misconfiguration surfaces in `Plugin::warning()` logs, not in the editor UI.
- **Validator hygiene.** Non-string `apiKey` values are dropped silently (treated as absent). Whitespace is trimmed before storage. The validator's warning log mentions the provider id only; the key value (or the unsanitised input) **never** appears in any log line.
- **Threat-model scope.** This protects against `edit_posts`-level users. It does NOT protect against public-site visitors — browser-rendered tiles always leak the key in network requests. Lock your key to your domain via Referer/Origin whitelisting at the tile provider for the public-visitor case.

### `kntnt_gpx_blocks_tile_overlays`

Two-level map of overlay providers keyed by overlay-provider id, each carrying a nested `layers` map keyed by layer id. The default ships **4 providers** with the following layer counts: OpenSeaMap (1 layer `seamarks`, key-less), OpenSnowMap (1 layer `pistes`, key-less), OpenWeatherMap (5 layers `clouds`, `precipitation`, `pressure`, `temperature`, `wind-speed`, key-required), Waymarked Trails (6 layers `hiking`, `cycling`, `mtb`, `riding`, `skating`, `winter`, key-less). The block.json default seeds `tileOverlays: []` and `tileOverlayApiKeys: {}` so a freshly inserted block has no overlays enabled.

```php
$overlays = apply_filters( 'kntnt_gpx_blocks_tile_overlays', $defaults );
// array<string, array{
//     label: string,         // Translatable overlay-provider display label.
//     requiresKey: bool,     // One API key per provider, shared across all the provider's layers.
//     layers: array<string, array{
//         label: string,         // Translatable layer display label.
//         url: string,           // Tile URL with {z}/{x}/{y}; {KEY} iff provider.requiresKey.
//         attribution: string,   // HTML snippet — trusted to the filter callback.
//         maxZoom: int,          // 0..22 inclusive.
//     }>,
//     signupUrl?: string,    // Optional https:// URL where users obtain a key.
//     subdomains?: string[], // Optional Leaflet {s} substitution list,
//                            // inherited by every layer of this provider
//                            // whose URL contains {s}.
//     apiKey?: string,       // Optional PHP-supplied API key. Presence
//                            // (not value) engages the PHP path — see
//                            // the "PHP-supplied API key (overlay
//                            // providers)" subsection below for the
//                            // full contract.
// }>
```

Unlike base providers, overlay providers carry **no `default` layer** — overlays are multi-select. The editor's "Overlays" panel renders one sub-section per provider with the provider label as a sub-header, the conditional API-key TextControl + signup ExternalLink for key-required providers, and one ToggleControl per layer. The single per-provider key is shared across every layer of that provider that the editor enables.

#### PHP-supplied API key (overlay providers)

The optional `apiKey` field on an overlay-provider record mirrors the base-provider mechanism: a site builder supplies a paid overlay provider's API key from PHP — typically from a `wp-config.php` constant — and bypasses the per-block `attributes.tileOverlayApiKeys` path entirely. The motivation is the same as for base providers: protect the key from any user with `edit_posts` capability who would otherwise be able to read it from `post_content`, the REST API, or the editor's Inspector field.

```php
add_filter( 'kntnt_gpx_blocks_tile_overlays', static function ( array $overlays ): array {
    if ( defined( 'OWM_KEY' ) ) {
        $overlays['openweathermap']['apiKey'] = OWM_KEY;
    }
    return $overlays;
} );
```

- **Engagement rule.** `isset( $record['apiKey'] )` — presence, not value — engages the PHP path. The site builder controls where the value comes from (hard-coded string, `wp-config.php` constant via `defined()` guard, environment variable, secrets manager, etc.); plugin code only reads the resolved `apiKey` field.
- **Precedence.** Binary. When the PHP path is engaged for an overlay provider, `attributes.tileOverlayApiKeys[ providerId ]` is never read, the editor's API-key TextControl for that provider is hidden with no notice (the provider behaves identically to a free provider in the UI), and both editor preview and frontend use the PHP-supplied key.
- **Fail-closed asymmetry — drops the layer, not the map.** Where base providers degrade to polyline-only when their key is empty, an overlay layer *is* the tile load — there is no equivalent degraded state. `apiKey === ''` (or whitespace-only) under PHP engagement therefore drops every affected layer from the resolved overlay stack with a `Plugin::warning()` log naming the (provider, layer) ids. The base map and any other overlays continue to render. The overlay-provider's toggles stay visible in the editor's Overlays panel — they just don't render when toggled on, and the misconfiguration surfaces in the log rather than the editor UI.
- **Validator hygiene.** Non-string `apiKey` values are dropped silently (treated as absent). Whitespace is trimmed before storage. The validator's warning log mentions the overlay-provider id only; the key value (or the unsanitised input) **never** appears in any log line.
- **Threat-model scope.** Same scope as the base side: this protects against `edit_posts`-level users. It does NOT protect against public-site visitors — browser-rendered tiles always leak the key in network requests. Lock your key to your domain via Referer/Origin whitelisting at the tile provider for the public-visitor case.

### Validation rules

Every record is validated at filter-application time. The validator follows a **drop-the-narrowest-unit** rule across both halves of the registry: a bad single style (or overlay layer) drops just that entry with one `Plugin::warning()` log; a provider-level failure drops the whole provider with a separate warning. Each drop emits one log naming the failing id and the failing constraint.

**Base providers (`kntnt_gpx_blocks_tile_providers`)**

- Provider id and style id must match `^[a-z0-9-]+$` (lowercase letters, digits, hyphens; non-empty).
- Provider-level: `label`, `requiresKey` (bool), `default` (non-empty string style id), and `styles` (non-empty map) are required. Optional `signupUrl` is an `https://` URL when present. Optional `subdomains` is a non-empty list of non-empty strings when present. Optional `apiKey` is a string when present (whitespace is trimmed; non-string values are dropped silently); presence engages the PHP path — see the [PHP-supplied API key](#php-supplied-api-key) subsection.
- Per-style: `label`, `url`, `attribution`, `maxZoom` are required.
- `url` must start with `https://` and contain the literals `{z}`, `{x}`, `{y}`.
- The per-style URL contains `{KEY}` iff `provider.requiresKey === true`.
- **`subdomains` inheritance:** a style whose URL contains the `{s}` placeholder requires its containing provider to declare `subdomains` at the provider level — Leaflet substitutes `{s}` against the provider's list. A `{s}`-using style on a provider without `subdomains` is dropped.
- `maxZoom` is an integer in `[0, 22]`. Floats and numeric strings are rejected.
- Provider survives only when at least one style survives validation **and** its declared `default` is among the survivors.

**Overlay providers (`kntnt_gpx_blocks_tile_overlays`)**

The rules mirror the base side minus the `default` requirement (overlays are multi-select):

- Overlay-provider id and layer id must match `^[a-z0-9-]+$` (lowercase letters, digits, hyphens; non-empty).
- Provider-level: `label`, `requiresKey` (bool), and `layers` (non-empty map) are required. Optional `signupUrl` is an `https://` URL when present. Optional `subdomains` is a non-empty list of non-empty strings when present. Optional `apiKey` is a string when present (whitespace is trimmed; non-string values are dropped silently); presence engages the PHP path — see the [PHP-supplied API key (overlay providers)](#php-supplied-api-key-overlay-providers) subsection.
- Per-layer: `label`, `url`, `attribution`, `maxZoom` are required.
- `url` must start with `https://` and contain the literals `{z}`, `{x}`, `{y}`.
- The per-layer URL contains `{KEY}` iff `provider.requiresKey === true`. For key-required overlay providers, the renderer substitutes `tileOverlayApiKeys[ providerId ]` server-side; an enabled overlay pair whose provider requires a key but whose `tileOverlayApiKeys` entry is empty is dropped at render time with a `Plugin::warning()` log (the toggle state still saves; the base map and other overlays still render). When the PHP path is engaged for the overlay provider (presence of `apiKey` on the validated record), the attribute-path entry is ignored entirely and an empty PHP-supplied key drops every layer of that provider with the same fail-closed semantics.
- **`subdomains` inheritance:** identical to the base side. A `{s}`-using layer on a provider without `subdomains` is dropped.
- `maxZoom` is an integer in `[0, 22]`. Floats and numeric strings are rejected.
- Overlay provider survives only when at least one layer survives validation.

The HTML in `attribution` is trusted to the filter callback — keep it tightly scoped (anchor tags and entities) and never include script content.

The canonical `openstreetmap` base provider is always preserved: if a filter callback drops it, the registry re-injects it with a warning so the resolver always has a fallback target. Base resolution fall-backs are layered: unknown provider id → global fallback provider + its default style; known provider, unknown style id → provider's own `default` style; defensive fall-through (provider's `default` itself does not resolve) → global fallback provider's default style. Overlay resolution has no fall-back — overlays are multi-select, so an unknown saved (provider, layer) pair is simply dropped from the resolved overlay stack.

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

### Adding a custom overlay provider

```php
add_filter( 'kntnt_gpx_blocks_tile_overlays', static function ( array $overlays ): array {
	$overlays['my-overlay'] = [
		'label'       => 'My Overlay',
		'requiresKey' => true,
		'signupUrl'   => 'https://example.com/signup',
		'layers'      => [
			'heatmap' => [
				'label'       => 'Heatmap',
				'url'         => 'https://overlay.example.com/heatmap/{z}/{x}/{y}.png?token={KEY}',
				'attribution' => '&copy; <a href="https://example.com/">Example</a>',
				'maxZoom'     => 18,
			],
			'contours' => [
				'label'       => 'Contours',
				'url'         => 'https://overlay.example.com/contours/{z}/{x}/{y}.png?token={KEY}',
				'attribution' => '&copy; <a href="https://example.com/">Example</a>',
				'maxZoom'     => 18,
			],
		],
	];
	return $overlays;
} );
```

The new overlay provider becomes available to every editor on the site; the Overlays panel renders one sub-section per validated provider — provider label as a sub-header, conditional API-key field for key-required providers, and one toggle per layer that adds or removes a `{provider, layer}` pair from `attributes.tileOverlays` while preserving stacking order.

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
