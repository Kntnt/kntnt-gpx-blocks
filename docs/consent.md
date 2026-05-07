# Consent integration

This document describes how the plugin defers OpenStreetMap tile loading until visitor consent is granted, and the integration points for cookie-consent plugins. Read it when working on the consent flow, the placeholder UI, or the Map block's bootstrap. For the user-facing summary, see [`README.md`](../README.md). For the security model, see [`security.md`](security.md).

## Why consent matters here

Map tiles for the GPX Map block are loaded from `tile.openstreetmap.org`. Each tile request transmits the visitor's IP address to the OpenStreetMap Foundation's servers. Under GDPR and similar regimes, this is a transfer of personal data to a third party and requires consent — even if OSM doesn't use the IP for marketing. The conservative legal interpretation is that tile loading falls under the `marketing` cookie/consent category. Some lawyers classify it as `functional` because it's required to render the visualisation. The plugin defaults to the conservative side and lets sites opt out via filter.

The Statistics and Elevation blocks load no third-party resources and need no consent.

## Default behaviour

On the server, GPX Map renders the block element regardless of consent state — the placeholder and the map container live in the same DOM, and the Interactivity store decides at hydration time which one is visible. The hydrated state begins as `consent: 'unknown'`. When `view.ts` mounts, it consults the WordPress Consent API:

```js
if ( typeof window.wp_has_consent === 'function' ) {
    state[ mapId ].consent = window.wp_has_consent( category )
        ? 'granted'
        : 'denied';
} else {
    state[ mapId ].consent = 'denied';
}
```

The `category` value is `kntnt_gpx_blocks_consent_category`, default `'marketing'`. If `wp_has_consent` is missing — meaning no Consent API plugin is active — the plugin defaults to `'denied'`, and the placeholder remains visible until the visitor clicks the activate button.

When state goes to `'granted'`, the watch callback initialises Leaflet (lazily, behind an `IntersectionObserver`) and the placeholder hides. When state goes to `'denied'`, no tile request is made.

## The placeholder

A server-rendered `<div>` inside the block with:

- The configurable text from filter `kntnt_gpx_blocks_placeholder_text`. Default (translated): `"Map is disabled until you accept cookies from OpenStreetMap."`
- A button labelled `"Activate map"` (translated). Clicking it dispatches the Interactivity action `actions.grantConsent`, which sets `state[mapId].consent = 'granted'`. This activates **only this map for this page view** — it does not call into the Consent API to grant site-wide consent. That is the consent plugin's job.
- A neutral background that uses the CSS variable `--kntnt-gpx-blocks-placeholder-background`.

The placeholder respects the same `aspect-ratio` and `min-height` as the map, so the layout doesn't jump when consent is granted.

## The three filters

```php
apply_filters( 'kntnt_gpx_blocks_consent_required', true );
apply_filters( 'kntnt_gpx_blocks_consent_category', 'marketing' );
apply_filters( 'kntnt_gpx_blocks_consent_service',  'openstreetmap' );
```

| Filter | Default | When to override |
|---|---|---|
| `kntnt_gpx_blocks_consent_required` | `true` | Return `false` to skip consent gating entirely. Use this when you run a self-hosted tile server, when your jurisdiction doesn't require consent for OSM tiles, or when an internal tool has accepted the trade-off. |
| `kntnt_gpx_blocks_consent_category` | `'marketing'` | Override to `'statistics'` or `'functional'` if your Consent API setup uses a different category for map tiles. Real Cookie Banner, Complianz, and CookieYes typically allow either. |
| `kntnt_gpx_blocks_consent_service` | `'openstreetmap'` | Override when your consent plugin tracks consent per service rather than per category, and uses a different identifier. |

The filter values are read in PHP at render time and embedded into the hydrated state. They are not read on the JavaScript side; the JavaScript reads from state.

## Editor behaviour

In the editor, `is_admin()` and `current_user_can( 'edit_posts' )` are both true. The plugin treats this as "we're behind the WordPress admin login — no consent gate needed for the editor". The placeholder is bypassed in the editor by default. If a site has a strict admin-side privacy policy (e.g. anonymising admin sessions), the same `kntnt_gpx_blocks_consent_required` filter can keep the gate active even in the editor by returning `true` regardless of admin context.

## Consent withdrawal

Consent API plugins fire a `wp_listen_for_consent_change` event when the visitor revokes consent. The `view.ts` callback subscribes to it:

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

The watch callback then either tears down Leaflet (if revoking) or initialises it (if granting).

A teardown destroys the Leaflet instance and shows the placeholder again. There is no attempt to "undo" tile requests already made — that's not technically possible — but no further requests are issued.

## Tested integrations

The Consent API integration is generic. These plugins implement the API and have been verified in manual testing:

- Real Cookie Banner
- Complianz
- CookieYes

Other Consent API-compliant plugins should work without code changes. If a plugin uses a non-Consent-API mechanism, it can still trigger our store directly:

```js
window.wp.interactivity.actions[ 'kntnt-gpx-blocks' ].grantConsent();
```

This dispatches against the active context, which means the call from outside our store needs a way to scope to a specific `mapId`. In practice, most third-party hooks fire from a page-level event (cookie banner clicked) and can iterate every map element on the page to dispatch:

```js
document.querySelectorAll( '.kntnt-gpx-blocks-map' ).forEach( ( el ) => {
    el.dispatchEvent( new CustomEvent( 'kntnt-gpx-blocks/grant-consent', { bubbles: true } ) );
} );
```

The block listens for that custom event as a fallback path. Document this for site builders who use bespoke consent flows.

## Privacy of the GPX data itself

The GPX file is hosted by WordPress, served from the same origin as the page, and gated by the WordPress media library's permissions. No third-party consent applies to the track data — only to the OpenStreetMap tiles that render underneath it. The plugin never sends the GeoJSON, the track points, or the waypoints to any external service.

The `Show download` control on GPX Map is **off by default** specifically because GPS tracks can encode personal locations (where someone lives, where they commute). When the editor enables the download button, every visitor can fetch the original `.gpx` file. See the "Don'ts" section in [`README.md`](../README.md) for the editorial guidance to operators.
