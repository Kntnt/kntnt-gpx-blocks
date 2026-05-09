<?php
/**
 * Enqueues the editor-only script that registers the GPX Statistics
 * block variation of `core/group`.
 *
 * The variation gives the GPX Statistics layout a first-class entry in the
 * block inserter (under the `kntnt` block category) instead of burying it
 * on the patterns tab. `scope: ['inserter']` in the JS keeps it out of the
 * Group block's placeholder picker so unrelated Group insertions are
 * unaffected. The bound paragraphs inside the variation pull their values
 * from the `kntnt-gpx-blocks/statistics` Block Bindings source the same
 * way any other consumer would.
 *
 * The script lives in `js/statistics-variation.js` as plain ES2022 that
 * uses `window.wp.blocks` and `window.wp.i18n` directly — no bundling via
 * `@wordpress/scripts` is needed for ~90 lines.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Bootstrap;

use Kntnt\Gpx_Blocks\Plugin;

/**
 * Registers the editor-only script that defines the GPX Statistics
 * block variation.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Variation_Registrar {

	/**
	 * Script handle registered with WordPress.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const HANDLE = 'kntnt-gpx-blocks-statistics-variation';

	/**
	 * Path to the variation registration script, relative to the plugin root.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const RELATIVE_PATH = '/js/statistics-variation.js';

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
	 * the variation before the inserter is built. A missing script file is
	 * a packaging error worth surfacing rather than silencing.
	 *
	 * @since 1.0.0
	 */
	public function enqueue(): void {

		// Resolve the plugin root. Tests inject a synthetic root via the
		// constructor; production wiring derives it from the singleton.
		$root = $this->plugin_root ?? dirname( Plugin::get_plugin_file() );
		$file = $root . self::RELATIVE_PATH;

		// Bail loudly when the script is missing — packaging error, not a
		// runtime condition worth silencing.
		if ( ! is_file( $file ) ) {
			Plugin::warning( sprintf( 'Variation_Registrar: script missing at %s', $file ) );
			return;
		}

		// plugins_url() expects a "context" file path inside the plugin
		// directory; the main plugin file is the canonical choice. mtime as
		// cache-buster so editor reloads pick up edits during development
		// without forcing a plugin version bump.
		$context_file = $root . '/kntnt-gpx-blocks.php';
		$url          = plugins_url( self::RELATIVE_PATH, $context_file );
		$version      = (string) filemtime( $file );

		wp_enqueue_script(
			self::HANDLE,
			$url,
			[ 'wp-blocks', 'wp-i18n' ],
			$version,
			true,
		);

		// Hook the script into the WP i18n machinery so __() picks up entries
		// from the plugin's text domain (.json files generated from .po).
		wp_set_script_translations( self::HANDLE, 'kntnt-gpx-blocks' );

	}

}
