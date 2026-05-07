# Self-update mechanism

This document describes how the plugin checks for new versions on GitHub Releases and presents them in the WordPress admin update UI. Read it when modifying `classes/Updater.php`, when cutting a release, or when integrating the update mechanism with a different distribution channel.

## Why GitHub Releases, not wordpress.org

Distributing through wordpress.org would require the plugin to live in the wordpress.org SVN repository under their licensing and review process. GitHub Releases is faster to ship to (push a tag, create a release with a ZIP asset, done) and gives full control over the release cadence and the artefact contents. The trade-off is that automatic updates require a custom mechanism — that's what `Updater` provides.

The mechanism is identical to other Kntnt plugins (`kntnt-ad-attribution`, …). The class was copied with only a namespace change.

## How it works

WordPress fires `pre_set_site_transient_update_plugins` periodically (typically every 12 hours, plus on demand when the user visits the Updates page). The transient holds an object listing every installed plugin along with its current and (if available) newer version. Plugins can hook the filter to inject their own update information.

The `Updater` callback:

1. **Bail when nothing has been checked yet.** WordPress sets `$transient->checked` only after it has populated the list of installed plugins. If empty, leave the transient untouched.
2. **Read the plugin's own metadata.** `Plugin::get_plugin_data()` returns the WordPress-parsed plugin header (`Name`, `Version`, `PluginURI`, etc.). The `PluginURI` field is expected to be a GitHub URL of the form `https://github.com/Kntnt/kntnt-gpx-blocks`.
3. **Extract the GitHub `user/repo` slug.** `get_github_repo_from_uri()` parses the URI and returns `Kntnt/kntnt-gpx-blocks`. Returns `null` for non-GitHub URIs, which silently skips the update check.
4. **Fetch the latest release.** `get_latest_github_release()` calls `https://api.github.com/repos/Kntnt/kntnt-gpx-blocks/releases/latest` via `wp_remote_get`. Returns `null` on HTTP error or malformed JSON; that path also silently skips.
5. **Compare versions.** Strip a leading `v` from the tag (`v1.2.3` → `1.2.3`). Use `version_compare()` against the installed version.
6. **Find the ZIP asset.** GitHub Releases support arbitrary binary assets. The Updater looks for the first asset with `content_type === "application/zip"` and uses its `browser_download_url` as the package URL. Without a ZIP asset attached to the release, no update information is published.
7. **Inject into the transient.** Build a `\stdClass` with the standard fields (`slug`, `plugin`, `new_version`, `url`, `package`, `tested`) and assign it to `$transient->response[ $plugin_slug_path ]`. WordPress then surfaces "Update Available" in the Plugins screen.

When the user clicks **Update**, WordPress downloads `package`, unzips it over the current plugin folder, and reactivates. The plugin folder is named after the slug (`kntnt-gpx-blocks/`), and the ZIP must contain a top-level folder of the same name.

## What the Updater requires from `Plugin`

Two static methods on `\Kntnt\Gpx_Blocks\Plugin`:

```php
public static function get_plugin_data(): array;
public static function get_plugin_file(): string;
```

`get_plugin_data()` returns the parsed plugin header — `get_plugin_data( self::get_plugin_file() )` from WordPress core, cached once per request. `get_plugin_file()` returns the absolute path to `kntnt-gpx-blocks.php` (typically captured from `__FILE__` in the bootstrap and stored on the singleton).

When implementing `Plugin`, both methods must exist before `Updater` is wired up.

## Wiring it up

In `Plugin`, hook the filter:

```php
$updater = new Updater();
add_filter( 'pre_set_site_transient_update_plugins', [ $updater, 'check_for_updates' ] );
```

The `Updater` instance is held on the singleton so the bound array callable stays valid. There is no `unhook` path; the filter runs for the lifetime of the request.

## What the Updater does **not** do

- **It does not implement `plugins_api`.** When the user clicks "View version 1.2.3 details" on the Plugins page, WordPress calls `plugins_api` to fetch a description, changelog, screenshots, and so on. Our Updater leaves that filter alone, which means the details modal shows WordPress's "Unable to load details" placeholder. That is acceptable — the actual update flow works without it. If a future site really wants the details modal to populate, a `plugins_api` filter callback can be added that returns canned content.
- **It does not test the GitHub API at runtime.** A failed API call falls through to `return $transient` unchanged. The user sees no error; they simply do not see an update offered. WordPress retries on its own schedule.
- **It does not authenticate to the GitHub API.** Anonymous requests are subject to GitHub's unauthenticated rate limit (60 per IP per hour). For a single WordPress site this is far more than enough — WordPress checks for updates roughly every 12 hours. For sites that share a NAT'd IP with many other GitHub-API consumers, the rate limit could be an issue; the cleanest fix is to pass an authenticated `Authorization` header via a filter, but that requires a new feature and is **not** in v1.

## Cutting a release

The release process — once `build-release-zip.sh` is in place:

1. Bump the `Version` header in `kntnt-gpx-blocks.php` to the new version (semantic versioning).
2. Tag the commit: `git tag v1.2.3 && git push --tags`.
3. Run `./build-release-zip.sh`, which assembles the production ZIP into `kntnt-gpx-blocks-v1.2.3.zip`.
4. Create a GitHub release for the tag using `gh release create v1.2.3 ./kntnt-gpx-blocks-v1.2.3.zip --title "v1.2.3" --notes "..."`.
5. The release notes are visible from the Plugins → "View version 1.2.3 details" link, but only if a `plugins_api` callback is added later. Without that, only the Update button surfaces.

The release ZIP must:

- Have a single top-level folder named `kntnt-gpx-blocks/`.
- Contain `vendor/` (Composer's `--no-dev --optimize-autoloader` install).
- Contain `build/` (`@wordpress/scripts` production build).
- Exclude `node_modules/`, `tests/`, `docs/`, `.github/`, `phpcs.xml.dist`, `phpstan.neon.dist`, `package-lock.json`, `composer.lock`, and other dev-only artefacts. The exclude list lives in `build-release-zip.sh`.

## Roll-back

If a release ships a regression, the fix is to publish a new release (with a higher version) that reverts the breaking change. WordPress always installs the highest available version; downgrades require manual upload. Communicate the regression in the release notes so users with auto-updates disabled can decide whether to skip the bad version.

GitHub allows deleting a release, but **don't**. Sites that already auto-updated to the bad version won't see a "downgrade available" — they'll just see the next correct release as a normal update. Deleting the release silently invalidates the `package` URL for users who happen to be in the middle of downloading it. Issuing a corrective release is the only safe path.

## Source of the Updater class

`classes/Updater.php` was copied verbatim from `kntnt-ad-attribution` and adapted in two places:

- `@package` annotation changed from `Kntnt\Ad_Attribution` to `Kntnt\Gpx_Blocks`.
- `namespace Kntnt\Ad_Attribution;` changed to `namespace Kntnt\Gpx_Blocks;`.

The logic is identical. When the upstream changes (a bug fix in `kntnt-ad-attribution/Updater.php`), apply the same change here. The two files are intentionally synchronised by hand rather than through a shared package — Composer-distributing a single-file dependency for two plugins would be more ceremony than it's worth.
