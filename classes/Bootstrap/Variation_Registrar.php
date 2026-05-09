<?php
/**
 * Enqueues the editor-only assets behind the GPX Statistics block-variation.
 *
 * Two assets are enqueued, both editor-only and both tied to the same
 * `enqueue_block_editor_assets` hook:
 *
 *   1. `js/statistics-variation.js` — registers the `kntnt-gpx-blocks-statistics`
 *      variation of `core/group` so the layout appears as a first-class
 *      inserter entry under the `kntnt` category. `scope: ['inserter']` keeps
 *      it out of the Group block's placeholder picker so unrelated Group
 *      insertions are unaffected. The bound paragraphs inside the variation
 *      pull their values from the `kntnt-gpx-blocks/statistics` Block
 *      Bindings source the same way any other consumer would.
 *
 *   2. `js/statistics-preview.js` + `css/statistics-preview.css` — wraps
 *      `core/paragraph`'s edit component to render the real resolved
 *      statistics value (in the same purple Gutenberg uses for synced/bound
 *      attributes) inside each bound paragraph in the editor. Without it
 *      the bindings system shows the source's `label` ("GPX statistics") for
 *      every bound paragraph — uninformative and indistinguishable from a
 *      generic placeholder. See classes/Rest/Statistics_Preview_Controller.
 *
 * Both scripts are plain ES2022 read directly from `window.wp.*` — no
 * `@wordpress/scripts` build step is needed.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Bootstrap;

use Kntnt\Gpx_Blocks\Plugin;

/**
 * Registers the editor-only scripts and stylesheet that back the GPX
 * Statistics block variation and its editor preview.
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
	 * Script handle for the editor preview HOC script.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const PREVIEW_HANDLE = 'kntnt-gpx-blocks-statistics-preview';

	/**
	 * Path to the editor preview script, relative to the plugin root.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const PREVIEW_SCRIPT_PATH = '/js/statistics-preview.js';

	/**
	 * Path to the editor preview stylesheet, relative to the plugin root.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const PREVIEW_STYLE_PATH = '/css/statistics-preview.css';

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
	 * Enqueues the variation registration script, the editor preview script,
	 * and the editor preview stylesheet in the block editor.
	 *
	 * Wired to `enqueue_block_editor_assets` by Plugin so it runs once per
	 * editor request (post, site, widgets, navigation, etc.) and registers
	 * the variation before the inserter is built. Missing assets are logged
	 * as packaging errors but do not abort the rest of the enqueues.
	 *
	 * @since 1.0.0
	 */
	public function enqueue(): void {

		// Resolve the plugin root. Tests inject a synthetic root via the
		// constructor; production wiring derives it from the singleton.
		$root         = $this->plugin_root ?? dirname( Plugin::get_plugin_file() );
		$context_file = $root . '/kntnt-gpx-blocks.php';

		// Enqueue the variation registration script first so the inserter has
		// the variation registered before any block-list rendering begins.
		$this->enqueue_variation_script( $root, $context_file );

		// Enqueue the editor preview script + stylesheet that replace the
		// "GPX statistics" placeholder with the real resolved values.
		$this->enqueue_preview_assets( $root, $context_file );

	}

	/**
	 * Enqueues the variation registration script.
	 *
	 * Bails loudly when the script is missing — packaging error, not a
	 * runtime condition worth silencing.
	 *
	 * @since 1.0.0
	 *
	 * @param string $root         Absolute plugin root path.
	 * @param string $context_file Absolute path to the main plugin file (for plugins_url()).
	 */
	private function enqueue_variation_script( string $root, string $context_file ): void {

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

	/**
	 * Enqueues the editor preview script and its stylesheet.
	 *
	 * Each missing file is logged independently so a partial packaging error
	 * still gets the surviving asset onto the page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $root         Absolute plugin root path.
	 * @param string $context_file Absolute path to the main plugin file (for plugins_url()).
	 */
	private function enqueue_preview_assets( string $root, string $context_file ): void {

		// Enqueue the script. Depends on wp-hooks/wp-element/wp-compose/wp-data/
		// wp-i18n/wp-api-fetch which are all available in the editor by default.
		$script_file = $root . self::PREVIEW_SCRIPT_PATH;
		if ( is_file( $script_file ) ) {
			$url     = plugins_url( self::PREVIEW_SCRIPT_PATH, $context_file );
			$version = (string) filemtime( $script_file );
			wp_enqueue_script(
				self::PREVIEW_HANDLE,
				$url,
				[ 'wp-hooks', 'wp-element', 'wp-compose', 'wp-data', 'wp-i18n', 'wp-api-fetch' ],
				$version,
				true,
			);
			wp_set_script_translations( self::PREVIEW_HANDLE, 'kntnt-gpx-blocks' );
		} else {
			Plugin::warning( sprintf( 'Variation_Registrar: preview script missing at %s', $script_file ) );
		}

		// Enqueue the matching stylesheet so the resolved values render in the
		// bindings purple. Style handle name mirrors the script handle.
		$style_file = $root . self::PREVIEW_STYLE_PATH;
		if ( is_file( $style_file ) ) {
			$url     = plugins_url( self::PREVIEW_STYLE_PATH, $context_file );
			$version = (string) filemtime( $style_file );
			wp_enqueue_style(
				self::PREVIEW_HANDLE,
				$url,
				[],
				$version,
			);
		} else {
			Plugin::warning( sprintf( 'Variation_Registrar: preview stylesheet missing at %s', $style_file ) );
		}

	}

}
