# Consent integration

This document specifies how Kntnt GPX Blocks integrates with cookie-consent management. It is the normative contract between the plugin and any consent management platform (CMP) the site builder may be running. Read it when working on the Map block's bootstrap, on the inline consent stub, or on anything that touches OpenStreetMap tile loading. For the user-facing summary in plain English, see the **For Builders** section in [`README.md`](../README.md). For the security model, see [`security.md`](security.md).

This document is a project-specific adaptation of the generic CMP integration pattern that Kntnt uses across its plugins. The terms *MUST*, *MUST NOT*, *SHOULD*, *SHOULD NOT* and *MAY* are used in the RFC 2119 sense.

## Why consent matters here

The GPX Map block loads its background tiles from `tile.openstreetmap.org`. Every tile request transmits the visitor's IP address to a third-party server (the OpenStreetMap Foundation). Under GDPR and ePrivacy this is a transfer of personal data and may require consent. Although OSM does not use the IP for marketing or profiling, the conservative legal position is that *any* embedded third-party content needs consent before it loads.

The Statistics and Elevation blocks load no third-party resources. They render unconditionally and have no consent surface.

There is exactly one consent-requiring action in the entire plugin: **mounting the Leaflet tile layer in the browser**. Server-side rendering of the block container, embedding the GeoJSON in `wp_interactivity_state()`, drawing the polyline on a canvas — none of these reach a third party and none of them require consent.

## Categories

The plugin declares **one** category and only one:

| Category | Used for |
|---|---|
| `external_media` | OpenStreetMap tile loading |

`external_media` is the standard CMP category for embedded third-party content (YouTube, Google Maps, OSM, Vimeo). It is not `functional` (which is reserved for cookies/processing strictly necessary for a service the visitor explicitly requested, and is exempt from consent under ePrivacy Art. 5(3)). It is not `marketing` (OSM does not profile visitors). The site builder remaps this name to whatever their CMP calls the equivalent group — see the glue template further down.

The plugin *MUST* use the same category name (`'external_media'`) consistently in every PHP and JavaScript call site. The plugin *MUST NOT* read or accept a filter that lets the site builder rename the category on the plugin side; remapping happens on the builder side, in their glue.

## Three-state logic

Every consent query returns one of three values:

- **`true` — granting**: consent has been granted. The action *MAY* proceed.
- **`false` — denying**: consent has been denied or withdrawn. The action *MUST NOT* proceed.
- **`null` — absent**: no signal has been delivered. The action *MAY* proceed.

The default in the absence of a signal is **permitted**. This is the spec's default-allow rule and it is what makes the plugin work without any CMP installed: when nobody answers the question, the plugin proceeds.

The plugin *MUST NOT* introduce a strict mode in which absent is treated as denying. The plugin *MUST NOT* offer a setting that flips this default. A site that wants strict gating installs a CMP and configures its glue to return `false` when consent has not yet been given.

## Why no PHP filter

The contract is **JavaScript-only.** The plugin does *not* expose a PHP filter for consent — historical drafts of this contract documented a `kntnt_gpx_blocks_has_consent` filter, but the plugin never invoked it and the filter was removed from the public API in the resolution of issue #54.

The reasoning is mechanical: the only consent-requiring action in the entire plugin is mounting the Leaflet tile layer, and that action happens in the visitor's browser. A PHP-side gate would have to translate a server-side decision into a client-side action, which means either (a) emitting the decision into Interactivity state and letting JS read it — adding a round-trip through PHP for a value JS already has access to via the local CMP — or (b) refusing to emit the block container at all, which would defeat lazy-mount on consent grant and force a full page reload after every consent change.

Both of those are worse than the present design, where `view.ts` consults `window.kntnt_gpx_blocks` directly and reacts to `kntnt_gpx_blocks:consent` events without a server round-trip. The plugin therefore exposes a single integration surface — the JavaScript one — and site builders integrate by dispatching events from their CMP's opt-in/opt-out hooks. See [`hooks.md`](hooks.md) for the full filter inventory; consent is intentionally absent from it.

A site builder who wants server-side introspection of their own CMP's state should call their CMP's API directly from PHP (`wp_has_consent()`, `cmplz_has_service_consent()`, etc.) — the plugin does not need to mediate that.

## The JavaScript API

The plugin exposes three primitives on the client side, all on the global namespace `window.kntnt_gpx_blocks`:

| Primitive | Purpose |
|---|---|
| `getConsent( category )` | Returns the current tristate value: `true`, `false`, or `null`. |
| `mayProceed( category )` | Returns `true` when the signal is granting *or* absent; `false` only when denying. |
| `onConsentChanged( handler )` | Subscribes to consent transitions. Returns an unsubscribe function. |

The plugin *MUST* use `mayProceed` (or an equivalent helper that respects the same default-allow rule) as the decision point before mounting the Leaflet tile layer. The plugin *MUST* use `onConsentChanged` to subscribe to mid-session transitions so that a `'denying'` signal triggers tile-layer removal and a subsequent `'granting'` signal triggers a tile-layer re-add. The polyline, cursor, controls, and waypoint markers — all of them locally-rendered SVG/canvas drawn from cached GeoJSON — are not subject to the contract and stay visible across both transitions.

The site builder's glue feeds the plugin its consent state by dispatching a `CustomEvent` on `window`:

```js
window.dispatchEvent(
    new CustomEvent( 'kntnt_gpx_blocks:consent', {
        detail: {
            category: 'external_media',
            granted: true,            // true | false | null
        },
    } )
);
```

The plugin's stub listens for this event and updates its internal state.

## The inline stub

The plugin *MUST* inject the following stub as an inline script in `<head>`, *before* the block's view module loads. The stub guarantees that early callers (other plugins, third-party glue) can read and subscribe to the consent state before the main bundle is parsed.

```js
( function ( window, namespace ) {

    'use strict';

    if ( window[ namespace ] ) {
        return;
    }

    const state = new Map();
    const listeners = new Set();

    window[ namespace ] = {

        getConsent( category ) {
            return state.has( category ) ? state.get( category ) : null;
        },

        mayProceed( category ) {
            return state.get( category ) !== false;
        },

        onConsentChanged( handler ) {
            listeners.add( handler );
            return () => listeners.delete( handler );
        },

        _setConsent( category, value ) {
            const previous = state.has( category ) ? state.get( category ) : null;
            const normalised = value === true ? true : value === false ? false : null;
            if ( normalised === null ) {
                state.delete( category );
            } else {
                state.set( category, normalised );
            }
            if ( previous !== normalised ) {
                for ( const listener of listeners ) {
                    try {
                        listener( category, normalised );
                    } catch ( error ) {
                        // Listener errors must not break the dispatcher loop.
                    }
                }
            }
        },

    };

    window.addEventListener( namespace + ':consent', ( event ) => {
        if ( ! event?.detail ) {
            return;
        }
        window[ namespace ]._setConsent( event.detail.category, event.detail.granted );
    } );

}( window, 'kntnt_gpx_blocks' ) );
```

The stub is enqueued via a synthetic script handle (`kntnt-gpx-blocks-consent-stub`) so optimisation plugins can target it for exclusion. The stub *MUST* be emitted in `<head>` (`$in_footer = false`) and *MUST* be a dependency of every plugin script that may run before the view module — in practice we make the view module's load conditionally check for `window.kntnt_gpx_blocks` and the stub handle is enqueued unconditionally when any of the plugin's blocks are present on the page.

The stub *MUST NOT* perform any consent-requiring action itself. It only registers the global and the inbound event listener. No HTTP requests, no cookies, no DOM mutation.

## Default behaviour without a CMP

When no CMP is installed and no builder glue exists:

1. The JS event `kntnt_gpx_blocks:consent` is never dispatched.
2. `window.kntnt_gpx_blocks.mayProceed( 'external_media' )` returns `true` because the internal state has no entry for the category.
3. The Map block mounts Leaflet normally and tiles load.

This is correct and intentional. The plugin *MUST* be fully functional out of the box. The plugin *MUST NOT* emit errors, warnings, or log entries when no glue is present. A site that needs gating installs a CMP; a site that does not need gating uses the plugin as-is.

## Editor behaviour

The plugin *MUST* always render a working map inside the WordPress block editor (Gutenberg, Site Editor) regardless of consent state. The editor is an authoring surface, not a visitor-facing surface — the editor needs to see the actual map to set up colours, inspect waypoints, and verify the file resolved correctly.

**Implementation.** The Map block's editor preview is a parallel React component (`MapEditorPreview` in `src/blocks/map/editor-preview.tsx`) that mounts Leaflet directly inside the editor iframe via `useEffect`, using GeoJSON fetched from the plugin's auth-gated REST endpoint `kntnt-gpx-blocks/v1/preview/<id>`. It does not consult the consent contract at all. The editor never goes through `view.ts`, so the consent contract simply does not apply. The editor preview does, however, honour the missing-key gate: when the resolved provider requires an API key and the per-provider entry in `tileApiKeys` is empty, the preview ships polyline-only with a Notice above the canvas explaining why (the same end state the frontend produces, surfaced for the editor's benefit — issues #81 and #82).

The PHP render path also sets `bypassConsent: true` in the per-map state slice when invoked under a `REST_REQUEST` with `edit_posts`. That flag is documented for completeness but is currently a vestigial signal — `view.ts` would honour it, but `view.ts` is not actually run for the editor preview because the Interactivity API runtime does not bootstrap inside ServerSideRender's injected DOM. The flag survives in case a future iteration restores the SSR + Interactivity editor path.

The editor bypass is implemented entirely in the plugin's PHP and JS — it is not exposed as a filter and not configurable. Sites that want strict admin-side privacy isolation should run an authentication boundary on the admin (HTTP basic auth, IP allow-list, VPN), not toggle the editor preview's tile loading.

## Withdrawal

When a `'denying'` signal is received for `'external_media'` after Leaflet has already mounted, the plugin *MUST*:

1. Remove the base tile layer and any overlay tile layers from the Leaflet map instance, which stops issuing tile requests.
2. Leave the polyline, cursor marker, Leaflet controls, and waypoint markers in place. Those are locally-rendered SVG/canvas drawn from the cached GeoJSON in `wp_interactivity_state()` — they reach no third party and they are not consent-requiring actions.
3. Leave the block element in the DOM with the polyline + waypoints visible over a plain (transparent) background where the tiles used to be.

A subsequent `'granting'` signal restores the tile layers via the same per-block `addTiles` call the initial mount used; the polyline et al. are unaffected because they were never removed.

The plugin does not set cookies of its own. It does not call third-party JavaScript APIs that need to be opted out of. Tile-layer removal is the entire withdrawal action.

OSM's tile servers may have set their own cookies in the visitor's browser via tile responses. These cookies are scoped to `*.tile.openstreetmap.org`, not to the plugin's site, and the plugin *cannot* delete them — same-origin policy forbids it. The site builder's CMP is responsible for any cross-origin cookie cleanup it cares about. The plugin documents this as a known limitation.

The CMP's content blocker (if any) may still render whatever placeholder it likes over the block, but the visitor-facing default is the polyline-only render — the route geometry stays visible because it is local data.

## What the plugin renders when consent is denying

The track polyline, cursor marker, Leaflet controls, and waypoint markers — drawn from the cached GeoJSON in `wp_interactivity_state()`. The block element is emitted with its inline-style dimensions (so the layout does not jump), with the `data-wp-*` Interactivity directives, and with the GeoJSON hydrated in `wp_interactivity_state()`. The view module reads `mayProceed` and adds the tile layers when granting/absent or skips them when denying; the rest of the map mounts unconditionally because the polyline is local SVG and the consent contract only governs third-party requests.

The same polyline-only render applies when the resolved tile provider requires an API key (`requiresKey === true`) and the per-provider entry in `tileApiKeys` is empty — the server-side render writes `tileProvider.url = null` and the view module skips `addTiles` regardless of consent. Consent-denied and missing-key are unified into a single "tiles unavailable" visual state from the visitor's perspective.

## Builder glue template

The site builder is expected to add a single JavaScript snippet — there is no PHP-side glue — that translates their CMP's state into the plugin's tristate by dispatching events. The exact CMP API call is the builder's choice; the plugin does not care which CMP is in use.

Place the snippets in the CMP plugin's "code on opt-in" and "code on opt-out" fields. CMPs replay these snippets on page load when the consent is still in force, so the dispatch happens both at the moment of the click *and* on every subsequent page load — which is what the plugin's stub needs.

On opt-in:

```js
window.dispatchEvent( new CustomEvent( 'kntnt_gpx_blocks:consent', {
    detail: { category: 'external_media', granted: true },
} ) );
```

On opt-out:

```js
window.dispatchEvent( new CustomEvent( 'kntnt_gpx_blocks:consent', {
    detail: { category: 'external_media', granted: false },
} ) );
```

If the CMP does *not* replay the opt-in code on page load (most major CMPs do; a small minority do not), the builder must additionally add a page-load snippet that reads the CMP's current state and dispatches the corresponding event. The plugin *MUST NOT* assume the builder has done this.

## Optimisation plugin warnings

The inline stub *MUST* run before any `kntnt_gpx_blocks:consent` event is dispatched and before the view module reads the consent state. Optimisation plugins (WP Rocket, Autoptimize, Perfmatters, NitroPack, FlyingPress, SG Optimizer, etc.) may break this ordering by deferring, delaying, combining, or moving the inline script. There is no plugin-agnostic API for opting out of every such optimisation programmatically.

The plugin documents — in [`README.md`](../README.md) — that the script handle `kntnt-gpx-blocks-consent-stub` *MUST* be excluded from the following optimisations whenever an optimisation plugin is in use:

- Defer or delay of JavaScript.
- Combination or minification of inline scripts.
- Lazy loading.
- Movement from `<head>` to `<body>` footer.

A unique string from the stub (the IIFE marker `'kntnt_gpx_blocks'` together with `_setConsent`) is published as a fallback identifier for optimisation tools that target inline scripts by content rather than by handle.

The plugin *MAY* additionally integrate against specific optimisation plugins via their documented filters (WP Rocket's `rocket_exclude_defer_js`, Autoptimize's `autoptimize_filter_js_exclude`, etc.) but doing so is not a substitute for the documentation requirement above.

## What the plugin *MUST NOT* do

Reflecting the contract above as prohibitions:

1. *MUST NOT* read cookies, options, or transients belonging to a specific CMP plugin.
2. *MUST NOT* call functions from any specific CMP's namespace.
3. *MUST NOT* mention any specific CMP plugin in the plugin's own code (only in documentation, where examples are useful).
4. *MUST NOT* call `wp_has_consent()` or listen for `wp_listen_for_consent_change` from the plugin's own code. The WordPress Consent API *MAY* be supported as a parallel channel via builder glue (the JS event from the builder's snippet can be wired to either the WP Consent API or any other CMP), but the plugin itself does not consume it.
5. *MUST NOT* expose a PHP filter as part of the consent contract. The contract is JavaScript-only; see *Why no PHP filter* above.
6. *MUST NOT* hard-require any CMP in order to function.
7. *MUST NOT* treat an absent signal as denying.
8. *MUST NOT* offer a configuration setting, filter, or constant that flips the default from permitted to denying.
9. *MUST NOT* emit errors or warnings when no builder glue is present.
10. *MUST NOT* perform any consent-requiring action server-side. The plugin's PHP renders only the container HTML, which is not a third-party request.
11. *MUST NOT* perform consent-requiring actions from `wp_cron`, REST callbacks, or any other user-less context. The plugin currently has no such code paths and *MUST NOT* introduce them.

## Naming summary

| Element | Convention |
|---|---|
| JavaScript global | `window.kntnt_gpx_blocks` |
| JavaScript inbound event | `kntnt_gpx_blocks:consent` |
| Stub script handle | `kntnt-gpx-blocks-consent-stub` |
| The single category | `external_media` |

## Verification checklist

The implementation conforms to this contract if and only if all of the following hold:

- [ ] The Map block's view module calls `window.kntnt_gpx_blocks.mayProceed( 'external_media' )` before mounting the Leaflet tile layer.
- [ ] The plugin treats `false` as the *only* denying value and every other return as permitted.
- [ ] `window.kntnt_gpx_blocks` exposes `getConsent`, `mayProceed`, and `onConsentChanged` exactly as specified in the stub above.
- [ ] The plugin listens for `kntnt_gpx_blocks:consent` on `window` and updates its internal state accordingly.
- [ ] The stub is enqueued as an inline script in `<head>` before any block view module that depends on the consent state.
- [ ] When a `'denying'` signal is received after Leaflet has mounted, the base tile layer and overlay tile layers are removed; the polyline, cursor, controls, and waypoint markers remain visible.
- [ ] When a `'granting'` signal is received after a previous denial, the tile layers are re-added without re-mounting the rest of the map.
- [ ] The plugin works with no errors and a fully functional Map block when no CMP and no glue are present.
- [ ] The plugin exposes no PHP filter for consent — neither `apply_filters()` nor `do_action()` for any consent-related hook appears anywhere in the plugin's PHP source.
- [ ] The plugin's PHP and JS code contain no references to `wp_has_consent`, `wp_listen_for_consent_change`, Real Cookie Banner, Complianz, CookieYes, or any other CMP-specific identifier.
- [ ] In the WordPress block editor, the Map block always mounts Leaflet via the parallel React preview, regardless of consent state.
- [ ] The Statistics and Elevation blocks render unconditionally and consult no consent surface.
- [ ] [`README.md`](../README.md) contains the JavaScript glue template under **For Builders**, with the optimisation-plugin exclusion list.

## References

The CMP integration pattern this document adapts is the generic specification Kntnt uses across plugins. The pattern's core ideas — three-state logic with default-allow, CMP-neutral filter and event contract, builder-supplied glue — are not negotiable. The Kntnt-GPX-Blocks-specific values (single category, single consent-requiring action, OSM tiles) are the only adaptations.
