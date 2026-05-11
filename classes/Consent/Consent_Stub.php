<?php
/**
 * Inline consent-contract stub enqueuer.
 *
 * Registers a synthetic script handle and inlines `js/consent-stub.js` into the
 * page <head>. The stub exposes `window.kntnt_gpx_blocks` (with `getConsent`,
 * `mayProceed`, `onConsentChanged`) and listens for the inbound CustomEvent
 * `kntnt_gpx_blocks:consent`. The full normative contract lives in
 * docs/consent.md.
 *
 * The stub is enqueued unconditionally on every frontend request — it is small
 * and harmless when no block is present, and detecting block presence reliably
 * (block content nested inside reusable blocks, template parts, or shortcodes)
 * is not worth the cost.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Consent;

use Kntnt\Gpx_Blocks\Plugin;

/**
 * Enqueues the inline consent-contract stub.
 *
 * Constructed once by Plugin and bound to wp_enqueue_scripts. Has no
 * per-instance state.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Consent_Stub {

	/**
	 * Synthetic WordPress script handle for the inline stub.
	 *
	 * Documented in docs/consent.md and README.md as the handle to exclude from
	 * optimisation plugins (defer, delay, combine, lazy-load, footer-move).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const HANDLE = 'kntnt-gpx-blocks-consent-stub';

	/**
	 * Path to the stub source file relative to the plugin root.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const SOURCE_RELATIVE_PATH = 'js/consent-stub.js';

	/**
	 * Registers the synthetic handle and inlines the stub source into <head>.
	 *
	 * Hooked on `wp_enqueue_scripts`. Reads the stub source from disk on each
	 * frontend render — `wp_add_inline_script` would otherwise have no source
	 * to attach to. The synthetic handle (source = `false`) ensures WordPress
	 * does not emit a separate `<script src>` tag; only the inline contents
	 * are printed.
	 *
	 * The `$in_footer` argument to `wp_register_script` is `false` so the stub
	 * is printed in `<head>`, before any block view module reads
	 * `window.kntnt_gpx_blocks`. The version is read from the plugin header so
	 * that the handle's cache fingerprint tracks plugin upgrades.
	 *
	 * When the source file is missing — for example a partial deploy — the
	 * method logs a warning and returns without registering the handle. This
	 * is the only failure path; the stub is otherwise unconditional.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function enqueue(): void {

		// Resolve the absolute path to the stub source.
		$plugin_file = Plugin::get_plugin_file();
		$source_path = dirname( $plugin_file ) . '/' . self::SOURCE_RELATIVE_PATH;

		// Bail (with a warning) when the source file is missing — a partial deploy
		// or a botched build can leave the JS file off disk.
		if ( ! is_readable( $source_path ) ) {
			Plugin::warning(
				sprintf( 'Consent_Stub: stub source not readable at %s', $source_path )
			);
			return;
		}

		// Read the stub source. file_get_contents returns false on failure,
		// which is treated as a hard error — the inline payload is mandatory.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin asset, not a remote URL.
		$source = file_get_contents( $source_path );
		if ( false === $source ) {
			Plugin::warning(
				sprintf( 'Consent_Stub: failed to read stub source at %s', $source_path )
			);
			return;
		}

		// Resolve the plugin version for cache-busting; fall back to a literal
		// when the header parser has not produced a usable string.
		$plugin_data = Plugin::get_plugin_data();
		$raw_version = $plugin_data['Version'] ?? '';
		$version     = is_string( $raw_version ) && '' !== $raw_version ? $raw_version : false;

		// Register a synthetic script handle (source = false) so wp_add_inline_script
		// has a target. $in_footer = false places the stub in <head>, which the
		// contract requires: the stub MUST run before any block view module
		// reads window.kntnt_gpx_blocks.
		wp_register_script( self::HANDLE, false, [], $version, false );
		wp_enqueue_script( self::HANDLE );
		wp_add_inline_script( self::HANDLE, $source );

	}

}
