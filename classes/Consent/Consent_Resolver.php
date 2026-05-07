<?php
/**
 * Resolves the plugin's consent configuration from WordPress filters.
 *
 * The three public methods map directly to the three filters exposed in
 * docs/hooks.md. PHP reads these values at render time and embeds them into the
 * hydrated Interactivity state — the JavaScript side never calls the Consent
 * API directly; it reads from state.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Consent;

/**
 * Reads and applies the three consent-related filters.
 *
 * Instantiated by Render_Map at render time and discarded afterwards — there
 * is no shared instance and no state between requests.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Consent_Resolver {

	/**
	 * Whether tile loading requires visitor consent.
	 *
	 * The default is `true` on the frontend. In the WordPress admin, the default
	 * flips to `false` when the current user can edit posts — editors should see
	 * the live map without needing to click through a consent placeholder. A site
	 * with a strict admin-side privacy policy can override this by hooking
	 * `kntnt_gpx_blocks_consent_required` and returning `true` regardless of
	 * context.
	 *
	 * @since 1.0.0
	 *
	 * @return bool `true` when the consent gate must be shown, `false` to bypass it.
	 */
	public function is_required(): bool {

		// In the editor, bypass the gate by default so editors can see the map
		// without a consent placeholder; the filter still has the final word.
		$default = ( is_admin() && current_user_can( 'edit_posts' ) ) ? false : true;

		return (bool) apply_filters( 'kntnt_gpx_blocks_consent_required', $default );

	}

	/**
	 * The consent category checked against the WordPress Consent API.
	 *
	 * Passed to `wp_has_consent()` on the JavaScript side via hydrated state.
	 * Default is `'marketing'` — the conservative classification for third-party
	 * tile servers under GDPR. Override to `'statistics'` or `'functional'` when
	 * the site's consent setup uses a different category for map tiles.
	 *
	 * @since 1.0.0
	 *
	 * @return string Consent category slug.
	 */
	public function get_category(): string {

		$value = apply_filters( 'kntnt_gpx_blocks_consent_category', 'marketing' );

		return is_string( $value ) ? $value : 'marketing';

	}

	/**
	 * The service identifier for consent plugins that track consent per service.
	 *
	 * Used by Real Cookie Banner and similar plugins. Default is `'openstreetmap'`.
	 * Override when the consent plugin uses a different identifier for OSM tiles.
	 *
	 * @since 1.0.0
	 *
	 * @return string Service slug.
	 */
	public function get_service(): string {

		$value = apply_filters( 'kntnt_gpx_blocks_consent_service', 'openstreetmap' );

		return is_string( $value ) ? $value : 'openstreetmap';

	}

}
