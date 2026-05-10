<?php
/**
 * Enqueues the editor-only script that registers the GPX Statistics variation.
 *
 * Registers `js/statistics-variation.js` — the `kntnt-gpx-blocks-statistics`
 * variation of `core/group` so the layout appears as a first-class inserter
 * entry under the `kntnt` category. `scope: ['inserter']` keeps it out of the
 * Group block's placeholder picker so unrelated Group insertions are
 * unaffected. The inserted paragraphs carry `[kntnt-gpx <key>]` shortcodes
 * inline; the shortcode resolves at render time via
 * `Bindings\Statistics_Shortcode`, so no editor preview HOC or REST endpoint
 * is needed — `do_shortcode()` runs in the editor preview the same way it
 * does on the frontend.
 *
 * The script is plain ES2022 read directly from `window.wp.*` — no
 * `@wordpress/scripts` build step is needed.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Bootstrap;

use Kntnt\Gpx_Blocks\Plugin;

/**
 * Registers the editor-only script that backs the GPX Statistics variation.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Variation_Registrar {

	/**
	 * Script handle for the variation registration script.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const VARIATION_HANDLE = 'kntnt-gpx-blocks-statistics-variation';

	/**
	 * Path to the variation registration script, relative to the plugin root.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const VARIATION_PATH = '/js/statistics-variation.js';

	/**
	 * Constructs the registrar with an injectable plugin-root path.
	 *
	 * The default resolves to the plugin's own root via
	 * `Plugin::get_plugin_file()`. Tests pass an explicit path so they
	 * don't need to mutate Plugin's static state.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $plugin_root Absolute path to the plugin root,
	 *                                 or null to derive from Plugin::get_plugin_file().
	 */
	public function __construct( private readonly ?string $plugin_root = null ) {}

	/**
	 * Enqueues the variation registration script in the block editor.
	 *
	 * Wired to `enqueue_block_editor_assets` by Plugin so it runs once per
	 * editor request (post, site, widgets, navigation, etc.) and registers
	 * the variation before the inserter is built. A missing script is logged
	 * as a packaging error but does not abort the enqueue path.
	 *
	 * @since 1.0.0
	 */
	public function enqueue(): void {

		// Resolve the plugin root. Tests inject a synthetic root via the
		// constructor; production wiring derives it from the singleton.
		$root         = $this->plugin_root ?? dirname( Plugin::get_plugin_file() );
		$context_file = $root . '/kntnt-gpx-blocks.php';

		$file = $root . self::VARIATION_PATH;
		if ( ! is_file( $file ) ) {
			Plugin::warning( sprintf( 'Variation_Registrar: script missing at %s', $file ) );
			return;
		}

		// mtime as cache-buster so editor reloads pick up edits during
		// development without forcing a plugin version bump.
		$url     = plugins_url( self::VARIATION_PATH, $context_file );
		$version = (string) filemtime( $file );

		wp_enqueue_script(
			self::VARIATION_HANDLE,
			$url,
			[ 'wp-blocks', 'wp-element', 'wp-i18n' ],
			$version,
			true,
		);

		// Hook the script into the WP i18n machinery so __() picks up entries
		// from the plugin's text domain (.json files generated from .po).
		wp_set_script_translations( self::VARIATION_HANDLE, 'kntnt-gpx-blocks' );

	}

}
