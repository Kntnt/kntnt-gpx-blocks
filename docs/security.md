# Security model

This document specifies the security properties of the plugin: what is hardened, against what threats, and where the trust boundaries lie. Read it when modifying the parser, the upload handlers, the render functions, or any code that takes external input. For consent-related privacy concerns, see [`consent.md`](consent.md). For the cache lifecycle that the parser feeds, see [`caching.md`](caching.md).

## Trust boundaries

| Boundary | Trust level | Defence |
|---|---|---|
| `.gpx` upload from authenticated editor | Low — the file comes from outside, the user is just authorised to introduce it | XMLReader streaming with `LIBXML_NONET`, MIME validation, file size cap, trackpoint count cap, root-element check |
| Block attributes from editor | Medium — set by an editor with `edit_posts`, but persisted to `post_content` and visible to all readers | Sanitised on render; structured types are validated on the React side and the PHP side |
| `wp_interactivity_state()` payload | High inside, low to outside | Auto-encoded by WordPress; no user-derived strings interpolated as HTML |
| OpenStreetMap tile responses | Out of scope | Loaded only after consent (see [`consent.md`](consent.md)); no parsing of tile content |

## XML parsing — XXE and entity attacks

`Conversion\Gpx_Parser` uses `XMLReader` (streaming) rather than `simplexml_load_string` or `DOMDocument`. Streaming gives constant memory regardless of file size. The XXE-relevant flags:

```php
$reader = new \XMLReader();
$reader->open(
    $file_path,
    null,
    LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR
);
$reader->setParserProperty( \XMLReader::SUBST_ENTITIES, false );
$reader->setParserProperty( \XMLReader::LOADDTD,        false );
```

This neutralises three classes of attack:

- **External entity resolution** (the classic XXE). `LIBXML_NONET` forbids any network access during parsing, which means an entity reference like `<!ENTITY xxe SYSTEM "file:///etc/passwd">` cannot read local files via SSRF and `<!ENTITY xxe SYSTEM "http://attacker/">` cannot exfiltrate.
- **Billion laughs / quadratic blowup.** `SUBST_ENTITIES = false` prevents the parser from expanding entity references that would otherwise multiply geometrically (`<!ENTITY a "lol"><!ENTITY b "&a;&a;&a;...">` etc.).
- **External DTD loading.** `LOADDTD = false` stops the parser from following `<!DOCTYPE foo SYSTEM "...">` references, which could trigger network requests or DoS via large DTDs.

PHP 8.0 made the XXE-vulnerable defaults safer (entities are not expanded by default, `libxml_disable_entity_loader` is gone), so the explicit flags above are belt-and-suspenders. The parser is safe even if a future PHP regression reintroduces a default. Do not remove these flags.

## File validation

Three layers of validation, in order:

1. **MIME registration.** `Bootstrap\Mime_Registrar::add_gpx` adds `application/gpx+xml` to `upload_mimes` so WordPress accepts `.gpx` uploads at all. Without this, every `.gpx` upload fails with a "file type not allowed" error.
2. **MIME overlay.** `wp_check_filetype_and_ext` runs `finfo` on the file content and typically returns `text/xml` or `application/xml` for GPX (because GPX is just XML — `finfo` doesn't know about the GPX vendor MIME). WordPress would reject the upload because `text/xml` doesn't match the registered `application/gpx+xml`. The plugin filters `wp_check_filetype_and_ext` to override the result when the extension is `.gpx`, accepting the file as `application/gpx+xml`. This is the only safe way to allow GPX uploads while keeping `wp_check_filetype_and_ext` enabled for other file types.
3. **File size cap.** `wp_handle_upload_prefilter` rejects uploads larger than `kntnt_gpx_blocks_max_file_size_bytes` (default 10 MB). The check runs before any parsing, so an attacker cannot DoS the server by uploading a 1 GB file.
4. **Root element check.** `Conversion\Gpx_Parser` reads the first XML element after the prolog. If it is not `<gpx>`, the conversion sets `_kntnt_gpx_blocks_error = 'wrong-mime'` and stops. This catches files renamed to `.gpx` but containing arbitrary XML (or non-XML).
5. **Trackpoint count cap.** During streaming, the parser maintains a count of trackpoints seen. When it exceeds `kntnt_gpx_blocks_max_track_points` (default 50 000), conversion aborts with `'too-large'`. Prevents memory exhaustion on adversarial files.

## Path safety

The plugin never constructs file paths from user input. All file access goes through `get_attached_file( $attachment_id )`, which returns an absolute, canonicalised path managed by WordPress. There is no string-concatenation of paths anywhere in the conversion pipeline.

## Capabilities

| Action | Capability |
|---|---|
| Upload a `.gpx` file | `upload_files` (Author and above by WordPress default) |
| Insert either block or the GPX Statistics variation in a post | `edit_posts` (transitively, via the block editor) |
| See error renderings | `edit_posts` — visitors without it see an empty block |
| Run `wp kntnt-gpx regenerate` | Shell access to the host (no web exposure) |

The plugin defines no custom capabilities. The defaults align with the principle that operating on attachments and authoring posts already require authentication. No filter is exposed for tightening these checks; sites with custom role policies can override standard WordPress capabilities through their existing role-management plugin.

## Output escaping

Every piece of derived data is escaped at the point of HTML output:

| Source | Context | Escaping function |
|---|---|---|
| Waypoint `name` | DOM text node | `Element.textContent = name` (no markup parsing) |
| Waypoint `desc` | DOM text node | `Element.textContent = desc` (no markup parsing) |
| GPX file URL for download control | URL in `<a href>` | `esc_url()` (and the URL is `wp_get_attachment_url()` which is already validated) |
| Editor-supplied solid colour values (Map track / cursor / waypoint dot) | CSS custom property value | `sanitize_hex_color()` then `esc_attr()` |
| Editor-supplied alpha-aware colour values on Map (`tooltipBackground`, `tooltipNameColor`, `tooltipDescColor`) | CSS custom property value | Local hex-3/4/6/8 regex whitelist (mirrors the shared `Color_Sanitizer` contract; folded into the shared validator in a follow-up slice) then `esc_attr()` |
| Editor-supplied colour values on Elevation (all seven attributes) | CSS custom property value | `Rendering\Color_Sanitizer::sanitize()` — alpha-aware hex 3/4/6/8 whitelist; rejects `rgb(...)`, `rgba(...)`, `hsl(...)`, named colours, CSS variable references, and any URL-injection attempt — then `esc_attr()` |
| Editor-supplied font references | CSS custom property value | Whitelist regex (matches CSS variable references and a handful of keywords); fallback to default if outside the whitelist |
| Block class names | HTML attribute | `sanitize_html_class()` for any user-derived parts; static classes are concatenated as-is |
| Statistics values | Element text | `esc_html()` after `number_format_i18n()` |
| GeoJSON in `wp_interactivity_state()` | JSON literal in `<script>` | Auto-encoded by WordPress; never interpolated as HTML |

Per the WordPress Coding Standards, escaping is always at the point of output, never at input time. The cache holds raw values; the render functions escape on the way out.

## Cross-site scripting

The principal XSS vector for this plugin would be GPX content (waypoint names, descriptions, URLs in `<link>` tags) reaching the page un-escaped. Three defences:

1. The parser stores raw strings; it never executes or evaluates them.
2. Render functions escape every interpolation at the point of output.
3. The view module builds waypoint tooltip bodies as per-line `<div>` elements whose `textContent` is set from the GPX `name` and `desc`, never `innerHTML`. Source markup cannot reach the DOM as HTML.

We do **not** trust the HTML in `<desc>` even if a GPX producer wrote markup there. Description text is treated as plain text only. If a future feature needs rich HTML in descriptions, it must go through `wp_kses_post()` before output, with a documented allowlist.

## Cross-site request forgery

The plugin exposes one REST route of its own: `GET kntnt-gpx-blocks/v1/preview/<id>` (`Rest\Preview_Controller`). It returns the cached GeoJSON for the Map block's React-based editor preview. Properties:

- **Permission**: `permission_callback` requires `edit_posts`. Anonymous and read-only authenticated callers receive 403.
- **Idempotent**: GET-only, no state mutation. No nonce required.
- **Input validation**: the `id` URL parameter is narrowed to a non-negative integer; the controller verifies the post exists, is an attachment, and has `application/gpx+xml` MIME type before reading the cache.
- **Output**: the cached GeoJSON FeatureCollection. Already exposed publicly via `wp_interactivity_state()` on every page that contains the Map block, so no new attack surface is introduced — the REST route just provides a different access channel for editors.

The Elevation and Statistics blocks' editor previews go through ServerSideRender, which uses the WordPress core REST endpoint `wp/v2/block-renderer/<name>`. That endpoint validates the editor's nonce and capability automatically. The Elevation block additionally forwards a `__editorBlockSnapshot` attribute carrying the editor's live block tree (registered with `"role": "local"` in `block.json` so it never reaches saved post content). `Render_Elevation::render()` only honours the snapshot when `current_user_can('edit_posts')` returns true — defence-in-depth that matches the REST endpoint's own gate, so the resolver cannot be steered by attribute payloads in any frontend context.

If a future feature adds another custom REST route, it must:

- Set `permission_callback` to at least `'edit_posts'` for editor-only routes.
- Validate the request via `wp_verify_nonce` if the route accepts mutating requests.
- Sanitise every parameter and escape every output.

## Logging

`Plugin::error()`, `Plugin::warning()`, `Plugin::info()`, and `Plugin::debug()` go to PHP `error_log()`. The destination is whatever the host's php.ini configures — usually a server-side log file, sometimes stderr in containerised setups. The plugin does **not** create its own log file in `wp-content/`, which would be a small attack surface (predictable filename, world-readable on misconfigured hosts).

The default log level is `error`. Setting `KNTNT_GPX_BLOCKS_LOG_LEVEL` to `'debug'` is fine in development but should not be left on in production: the debug stream contains attachment IDs, file hashes, and conversion timings — not secrets, but enough operational information that it should not leak to public log analysers.

`Plugin::error()` calls do not include stack traces. PHP's own error handlers add stack traces for uncaught exceptions; that is sufficient for post-mortem analysis. The plugin's own log lines are intentionally one-liners that grep cleanly.

## Dependencies

Composer is used for autoloading and dev tools only. The plugin's runtime PHP code has no third-party dependencies. The block JavaScript bundles Leaflet (and `Leaflet.fullscreen` if the fullscreen control is enabled), via npm and webpack. Leaflet's licence (BSD-2-Clause) is compatible with GPL-2+. No JavaScript runs from a third-party CDN.

`vendor/` is git-ignored and assembled by the release script with `composer install --no-dev --optimize-autoloader`. Distributed releases ship the `vendor/` folder as part of the ZIP. The build step is the only moment Composer dependencies are resolved, which gives a reproducible distribution and prevents transitive surprises.

## Threat model summary

The plugin is hardened against:

- Malicious GPX uploads that try to read local files (XXE), exhaust memory (entity expansion, Billion laughs, gigantic files), or crash the parser.
- Malicious editors who insert blocks with adversarial attribute values trying to break out of CSS or HTML context.
- Cross-origin tile requests that would otherwise leak visitor IPs without consent. (Gating is performed by the consent contract documented in [`consent.md`](consent.md); the plugin's defaults default-allow only because the contract is opt-in for the site builder. A site that needs strict gating installs a CMP and writes glue.)

The plugin is **not** designed to defend against:

- Malicious editors who upload GPX files revealing other people's locations. This is a content-policy issue, not a technical one — see the "Don'ts" guidance in [`README.md`](../README.md).
- Malicious admins who add JavaScript to the site that dispatches a forged `kntnt_gpx_blocks:consent` event with `granted: true`, bypassing the CMP. Admins can run arbitrary JavaScript on a WordPress site; if the policy needs to be tamper-proof, deploy the plugin to an environment where script changes require code review.
- The OpenStreetMap tile servers themselves. They are an external service and out of scope.
