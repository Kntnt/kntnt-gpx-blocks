# Consent integration

This document specifies how Kntnt GPX Blocks integrates with cookie-consent management. It is the normative contract between the plugin and any consent management platform (CMP) the site builder may be running. Read it when working on the Map block's bootstrap, on the inline consent stub, on the PHP filter, or on anything that touches OpenStreetMap tile loading. For the user-facing summary in plain English, see the **For Builders** section in [`README.md`](../README.md). For the security model, see [`security.md`](security.md).

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

`external_media` is the standard CMP category for embedded third-party content (YouTube, Google Maps, OSM, Vimeo). It is not `functional` (which is reserved for cookies/processing strictly necessary for a service the visitor explicitly requested, and is exempt from consent under ePrivacy Art. 5(3)). It is not `marketing` (OSM does not profile visitors). The site builder remaps this name to whatever their CMP calls the equivalent group — see the glue templates further down.

The plugin *MUST* use the same category name (`'external_media'`) consistently in every PHP and JavaScript call site. The plugin *MUST NOT* read or accept a filter that lets the site builder rename the category on the plugin side; remapping happens on the builder side, in their glue.

## Three-state logic

Every consent query returns one of three values:

- **`true` — granting**: consent has been granted. The action *MAY* proceed.
- **`false` — denying**: consent has been denied or withdrawn. The action *MUST NOT* proceed.
- **`null` — absent**: no signal has been delivered. The action *MAY* proceed.

The default in the absence of a signal is **permitted**. This is the spec's default-allow rule and it is what makes the plugin work without any CMP installed: when nobody answers the question, the plugin proceeds.

The plugin *MUST NOT* introduce a strict mode in which absent is treated as denying. The plugin *MUST NOT* offer a setting that flips this default. A site that wants strict gating installs a CMP and configures its glue to return `false` when consent has not yet been given.

## The PHP API

### `kntnt_gpx_blocks_has_consent`

```php
$signal = apply_filters(
    'kntnt_gpx_blocks_has_consent',
    null,                  // Default — MUST always be null (absent signal).
    string $category,      // Always 'external_media' in this plugin.
    array  $context = []   // Optional. Plugin currently passes [].
);
```

**Return value contract:**

- `true` — granting.
- `false` — denying.
- `null` (or any non-`true`, non-`false` value, including missing return) — absent.

The plugin *MUST* treat `false` as the only "denying" value and every other value as "permitted". The asymmetry is deliberate: malformed glue that returns `0`, `''`, `'no'`, or anything else will not accidentally block the plugin's primary functionality.

The filter is the *only* PHP-side primitive. The plugin *MUST NOT* read CMP cookies directly, *MUST NOT* call functions from any specific CMP plugin's namespace, and *MUST NOT* inspect WordPress options that belong to a CMP. All such logic lives in the site builder's glue.

### Filter naming — note on the `_has_consent` form

The project's general naming convention is the `kntnt_gpx_blocks_<purpose>` form (see [`coding-standards.md`](coding-standards.md)). The consent filter follows that exact form: `kntnt_gpx_blocks_has_consent`. This is a deliberate deviation from the generic CMP integration pattern, which uses a slash-namespaced form (`kntnt_example/has_consent`). The slash form would force plugin- and theme-side code to write a non-WordPress-idiomatic filter name and breaks tooling that assumes underscore-only hook names. We keep the project convention.

## The JavaScript API

The plugin exposes three primitives on the client side, all on the global namespace `window.kntnt_gpx_blocks`:

| Primitive | Purpose |
|---|---|
| `getConsent( category )` | Returns the current tristate value: `true`, `false`, or `null`. |
| `mayProceed( category )` | Returns `true` when the signal is granting *or* absent; `false` only when denying. |
| `onConsentChanged( handler )` | Subscribes to consent transitions. Returns an unsubscribe function. |

The plugin *MUST* use `mayProceed` (or an equivalent helper that respects the same default-allow rule) as the decision point before mounting the Leaflet tile layer. The plugin *MUST* use `onConsentChanged` to subscribe to mid-session transitions so that a `'denying'` signal triggers a Leaflet tear-down and a subsequent `'granting'` signal triggers a re-mount.

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

1. The PHP filter `kntnt_gpx_blocks_has_consent` has no listener and `apply_filters()` returns the default (`null`).
2. The JS event `kntnt_gpx_blocks:consent` is never dispatched.
3. `window.kntnt_gpx_blocks.mayProceed( 'external_media' )` returns `true` because the internal state has no entry for the category.
4. The Map block mounts Leaflet normally and tiles load.

This is correct and intentional. The plugin *MUST* be fully functional out of the box. The plugin *MUST NOT* emit errors, warnings, or log entries when no glue is present. A site that needs gating installs a CMP; a site that does not need gating uses the plugin as-is.

## Editor behaviour

The plugin *MUST* always render a working map inside the WordPress block editor (Gutenberg, Site Editor) regardless of consent state. The editor is an authoring surface, not a visitor-facing surface — the editor needs to see the actual map to set up colours, inspect waypoints, and verify the file resolved correctly.

**Implementation.** The Map block's editor preview is a parallel React component (`MapEditorPreview` in `src/blocks/map/editor-preview.tsx`) that mounts Leaflet directly inside the editor iframe via `useEffect`, using GeoJSON fetched from the plugin's auth-gated REST endpoint `kntnt-gpx-blocks/v1/preview/<id>`. It does not consult the consent contract at all — neither the PHP filter nor `window.kntnt_gpx_blocks.mayProceed`. The editor never goes through `view.ts`, so the consent contract simply does not apply.

The PHP render path also sets `bypassConsent: true` in the per-map state slice when invoked under a `REST_REQUEST` with `edit_posts`. That flag is documented for completeness but is currently a vestigial signal — `view.ts` would honour it, but `view.ts` is not actually run for the editor preview because the Interactivity API runtime does not bootstrap inside ServerSideRender's injected DOM. The flag survives in case a future iteration restores the SSR + Interactivity editor path.

The editor bypass is implemented entirely in the plugin's PHP and JS — it is not exposed as a filter and not configurable. Sites that want strict admin-side privacy isolation should run an authentication boundary on the admin (HTTP basic auth, IP allow-list, VPN), not toggle the editor preview's tile loading.

## Withdrawal

When a `'denying'` signal is received for `'external_media'` after Leaflet has already mounted, the plugin *MUST*:

1. Tear down the Leaflet map instance (`map.remove()`), which removes its DOM and detaches all event listeners.
2. Stop issuing tile requests (a consequence of the tear-down).
3. Leave the block element in the DOM as an empty correctly-sized container.

The plugin does not set cookies of its own. It does not call third-party JavaScript APIs that need to be opted out of. The tear-down is the entire withdrawal action.

OSM's tile servers may have set their own cookies in the visitor's browser via tile responses. These cookies are scoped to `*.tile.openstreetmap.org`, not to the plugin's site, and the plugin *cannot* delete them — same-origin policy forbids it. The site builder's CMP is responsible for any cross-origin cookie cleanup it cares about. The plugin documents this as a known limitation.

After tear-down, the block element stays in the DOM. The CMP's content blocker (if any) is expected to reclaim the visual area with whatever placeholder the CMP provides. The plugin renders no placeholder of its own.

## What the plugin renders when consent is denying

Nothing. The block element is emitted with its inline-style dimensions (so the layout does not jump), with the `data-wp-*` Interactivity directives, and with the GeoJSON hydrated in `wp_interactivity_state()`. The view module reads `mayProceed` and either mounts Leaflet (granting/absent) or skips the mount and leaves the container empty (denying). The CMP owns the visitor-facing UX.

## Builder glue templates

The site builder is expected to add two snippets — one in PHP, one in JavaScript — that translate their CMP's state into the plugin's tristate. The exact CMP API call is the builder's choice; the plugin does not care which CMP is in use.

### PHP template

Place in the theme's `functions.php`, in a site-specific must-use plugin, or anywhere `add_filter` runs early enough:

```php
add_filter( 'kntnt_gpx_blocks_has_consent', function ( $default, $category, $context ) {

    // The plugin only uses one category. Bail for anything else.
    if ( 'external_media' !== $category ) {
        return $default;
    }

    // Defer to the CMP only when its API is actually available.
    if ( ! function_exists( '<cmp_consent_check_function>' ) ) {
        return $default;
    }
    $cmp_result = <cmp_consent_check_function>( '<cmp_category_for_external_media>' );

    // Translate the CMP's answer to the plugin's tristate.
    return true === $cmp_result ? true : ( false === $cmp_result ? false : $default );

}, 10, 3 );
```

Replace `<cmp_consent_check_function>` and `<cmp_category_for_external_media>` with the values appropriate for the CMP in use. The plugin documents two examples for the most common WordPress CMPs in [`README.md`](../README.md).

### JavaScript template

Place in the CMP plugin's "code on opt-in" and "code on opt-out" fields. CMPs replay these snippets on page load when the consent is still in force, so the dispatch happens both at the moment of the click *and* on every subsequent page load — which is what the plugin's stub needs.

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
4. *MUST NOT* support the WordPress Consent API as the only mechanism. The Consent API *MAY* be supported as a parallel channel via builder glue, but the plugin's own code *MUST NOT* call `wp_has_consent()` or listen for `wp_listen_for_consent_change`.
5. *MUST NOT* hard-require any CMP in order to function.
6. *MUST NOT* treat an absent signal as denying.
7. *MUST NOT* offer a configuration setting, filter, or constant that flips the default from permitted to denying.
8. *MUST NOT* emit errors or warnings when no builder glue is present.
9. *MUST NOT* perform any consent-requiring action server-side. The plugin's PHP renders only the container HTML, which is not a third-party request.
10. *MUST NOT* perform consent-requiring actions from `wp_cron`, REST callbacks, or any other user-less context. The plugin currently has no such code paths and *MUST NOT* introduce them.

## Naming summary

| Element | Convention |
|---|---|
| PHP filter (query) | `kntnt_gpx_blocks_has_consent` |
| JavaScript global | `window.kntnt_gpx_blocks` |
| JavaScript inbound event | `kntnt_gpx_blocks:consent` |
| Stub script handle | `kntnt-gpx-blocks-consent-stub` |
| The single category | `external_media` |

## Verification checklist

The implementation conforms to this contract if and only if all of the following hold:

- [ ] The plugin calls `apply_filters( 'kntnt_gpx_blocks_has_consent', null, 'external_media' )` (or its JS counterpart `mayProceed`) before mounting the Leaflet tile layer.
- [ ] The plugin treats `false` as the *only* denying value and every other return as permitted.
- [ ] `window.kntnt_gpx_blocks` exposes `getConsent`, `mayProceed`, and `onConsentChanged` exactly as specified in the stub above.
- [ ] The plugin listens for `kntnt_gpx_blocks:consent` on `window` and updates its internal state accordingly.
- [ ] The stub is enqueued as an inline script in `<head>` before any block view module that depends on the consent state.
- [ ] When a `'denying'` signal is received after Leaflet has mounted, the map is torn down via `map.remove()`.
- [ ] The plugin works with no errors and a fully functional Map block when no CMP and no glue are present.
- [ ] The plugin's PHP and JS code contain no references to `wp_has_consent`, `wp_listen_for_consent_change`, Real Cookie Banner, Complianz, CookieYes, or any other CMP-specific identifier.
- [ ] In the WordPress block editor, the Map block always mounts Leaflet via the parallel React preview, regardless of consent state.
- [ ] The Statistics and Elevation blocks render unconditionally and never invoke the consent filter.
- [ ] [`README.md`](../README.md) contains the PHP and JavaScript glue templates under **For Builders**, with the optimisation-plugin exclusion list.

## References

The CMP integration pattern this document adapts is the generic specification Kntnt uses across plugins. The pattern's core ideas — three-state logic with default-allow, CMP-neutral filter and event contract, builder-supplied glue — are not negotiable. The Kntnt-GPX-Blocks-specific values (single category, single consent-requiring action, OSM tiles) are the only adaptations.
