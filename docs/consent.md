# Consent integration

This document describes how the GPX Map block integrates with cookie-consent plugins to defer OpenStreetMap tile loading until visitor consent has been granted. Read it when working on the consent flow or the Map block's bootstrap. For the user-facing summary, see [`README.md`](../README.md). For the security model, see [`security.md`](security.md).

## Why consent matters here

Map tiles for the GPX Map block are loaded from `tile.openstreetmap.org`. Each tile request transmits the visitor's IP address to the OpenStreetMap Foundation's servers. Under GDPR and similar regimes, this is a transfer of personal data to a third party and may require consent — even though OSM does not use the IP for marketing. The conservative legal interpretation is that tile loading falls under the `marketing` cookie/consent category. Some lawyers classify it as `functional` because it is required to render the visualisation. The plugin defaults to the conservative side and lets sites opt out via filter.

The Statistics and Elevation blocks load no third-party resources and need no consent.

## Layering — the plugin is a passive consent consumer

The plugin renders no consent UI of its own. There is no "Activate map" button, no inline placeholder text, no banner, no overlay. The visitor-facing consent UX is the cookie-consent plugin's responsibility — Real Cookie Banner, Complianz, CookieYes, or any other plugin that implements the [WordPress Consent API](https://github.com/rlankhorst/wp-consent-level-api) provides the cookie banner, the per-service blocker, and the messaging.

Our plugin's job is narrow:

1. Render an empty block container with correct dimensions and the WordPress Interactivity API directives.
2. **Do not request any OpenStreetMap tiles** until consent has been resolved.
3. Mount Leaflet when consent transitions to `granted`. Tear it down when it transitions to `denied`.

That is the entire contract. Everything visitor-visible between page load and consent grant — banner, blocker, replacement UI — belongs to the consent-management plugin.

## Default behaviour

On the server, GPX Map renders the block element regardless of consent state. The hydrated state begins as `consent: 'unknown'` (when the gate is in effect) or `'granted'` (when the gate is bypassed in admin or by filter override). When `view.ts` mounts via `data-wp-init="callbacks.initMap"`, it resolves the initial state by querying the Consent API:

```js
if ( typeof window.wp_has_consent === 'function' ) {
    state[ mapId ].consent = window.wp_has_consent( category )
        ? 'granted'
        : 'denied';
} else {
    state[ mapId ].consent = 'denied';
}
```

The `category` value is `kntnt_gpx_blocks_consent_category`, default `'marketing'`. If `wp_has_consent` is missing — meaning no Consent API plugin is active — the plugin defaults to `'denied'` so no tile request is made. The block element stays visually empty. The site operator is expected to install a consent plugin; if they choose not to, they can opt out of the gate entirely via the `kntnt_gpx_blocks_consent_required` filter (see below).

When state goes to `'granted'`, the `data-wp-watch--consent` callback initialises Leaflet (deferred behind an `IntersectionObserver`). When state goes to `'denied'`, the Leaflet instance is torn down if mounted; no further requests are issued.

## The three filters

```php
apply_filters( 'kntnt_gpx_blocks_consent_required', true );
apply_filters( 'kntnt_gpx_blocks_consent_category', 'marketing' );
apply_filters( 'kntnt_gpx_blocks_consent_service',  'openstreetmap' );
```

| Filter | Default | When to override |
|---|---|---|
| `kntnt_gpx_blocks_consent_required` | `true` | Return `false` to skip consent gating entirely. Use this when you run a self-hosted tile server, when your jurisdiction does not require consent for OSM tiles, or when an internal tool has accepted the trade-off. |
| `kntnt_gpx_blocks_consent_category` | `'marketing'` | Override to `'statistics'` or `'functional'` if your Consent API setup uses a different category for map tiles. Real Cookie Banner, Complianz, and CookieYes typically allow either. |
| `kntnt_gpx_blocks_consent_service` | `'openstreetmap'` | Override when your consent plugin tracks consent per service rather than per category, and uses a different identifier. |

The filter values are read in PHP at render time and embedded into the hydrated state. They are not read on the JavaScript side; the JavaScript reads from state.

## Editor behaviour

In the WordPress admin, `is_admin()` is `true` and editors typically have `edit_posts` capability. `Consent_Resolver::is_required()` flips its default to `false` in this context — editors see the live map directly without needing to grant consent. If a site has a strict admin-side privacy policy (e.g. anonymising admin sessions), the same `kntnt_gpx_blocks_consent_required` filter can keep the gate active in the editor by returning `true` regardless of admin context.

## Consent withdrawal

Consent API plugins fire a `wp_listen_for_consent_change` event when the visitor's consent decision changes. The `view.ts` callback subscribes to it:

```js
document.addEventListener( 'wp_listen_for_consent_change', ( event ) => {
    const change = event.detail;
    if ( change[ category ] === 'deny' ) {
        state[ mapId ].consent = 'denied';
    } else if ( change[ category ] === 'allow' ) {
        state[ mapId ].consent = 'granted';
    }
} );
```

The `data-wp-watch--consent` callback then either tears down Leaflet (if revoking) or initialises it (if granting).

A teardown destroys the Leaflet instance and leaves the block element visually empty for the cookie-consent plugin to take over again. There is no attempt to "undo" tile requests already made — that is not technically possible — but no further requests are issued.

## Tested integrations

The Consent API integration is generic. These plugins implement the API and have been verified in manual testing:

- Real Cookie Banner
- Complianz
- CookieYes

Real Cookie Banner is the recommended integration because it ships a Content Blocker for OpenStreetMap out of the box: until the visitor accepts, RCB visually replaces our block element with its own consent prompt. After the visitor accepts (either through the cookie banner or by clicking RCB's per-block accept button), RCB fires the standard `wp_listen_for_consent_change` event and our block mounts Leaflet automatically.

Other Consent API-compliant plugins work without code changes. The block listens to the standard `wp_listen_for_consent_change` event and reacts to allow/deny transitions.

## Non-Consent-API integration point

For consent flows that do not implement the WordPress Consent API, the block listens to a custom DOM event on its element:

```js
document.querySelectorAll( '.kntnt-gpx-blocks-map' ).forEach( ( el ) => {
    el.dispatchEvent( new CustomEvent( 'kntnt-gpx-blocks/grant-consent', { bubbles: true } ) );
} );
```

Dispatch this event from anywhere in the consent flow to grant consent to every map on the page. Document this for site builders who use bespoke consent flows.

## Privacy of the GPX data itself

The GPX file is hosted by WordPress, served from the same origin as the page, and gated by the WordPress media library's permissions. No third-party consent applies to the track data — only to the OpenStreetMap tiles that render underneath it. The plugin never sends the GeoJSON, the track points, or the waypoints to any external service.

The `Show download` control on GPX Map is **off by default** specifically because GPS tracks can encode personal locations (where someone lives, where they commute). When the editor enables the download button, every visitor can fetch the original `.gpx` file. See the "Don'ts" section in [`README.md`](../README.md) for the editorial guidance to operators.
