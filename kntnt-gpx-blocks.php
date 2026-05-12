<?php
/**
 * Plugin Name:       Kntnt GPX Blocks
 * Plugin URI:        https://github.com/Kntnt/kntnt-gpx-blocks
 * Description:       Gutenberg blocks for visualising GPX tracks: map, elevation profile, and statistics.
 * Version:           0.13.0
 * Requires at least: 6.7
 * Requires PHP:      8.4
 * Author:            Kntnt
 * Author URI:        https://www.kntnt.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kntnt-gpx-blocks
 * Domain Path:       /languages
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

// Prevent direct file access outside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guards against running on PHP versions older than 8.4.
 *
 * When the requirement is not met, an admin notice is displayed and the plugin
 * deactivates itself so it does not produce fatal errors. Returns true when
 * the environment is acceptable, false otherwise.
 *
 * @since 1.0.0
 *
 * @return bool True when PHP >= 8.4, false when the guard fires.
 */
function kntnt_gpx_blocks_php_version_check(): bool {

	// Nothing to do when the runtime meets the requirement.
	if ( version_compare( PHP_VERSION, '8.4', '>=' ) ) {
		return true;
	}

	// Show a dismissible admin notice and deactivate the plugin gracefully.
	add_action(
		'admin_notices',
		static function (): void {
			/* translators: 1: required PHP version, 2: current server PHP version */
			$tpl = esc_html__( 'Kntnt GPX Blocks needs PHP %1$s+. Server runs %2$s.', 'kntnt-gpx-blocks' );
			$message = sprintf( $tpl, '8.4', PHP_VERSION );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped via esc_html__() above.
			echo '<div class="notice notice-error"><p>' . $message . '</p></div>';
		}
	);

	// Deactivate the plugin so WordPress does not try to load it again.
	add_action(
		'admin_init',
		static function (): void {
			deactivate_plugins( plugin_basename( __FILE__ ) );
		}
	);

	return false;
}

// Abort the rest of the bootstrap when the PHP version guard fires.
if ( ! kntnt_gpx_blocks_php_version_check() ) {
	return;
}

// Load the PSR-4 autoloader (delegates to vendor/autoload.php).
require_once __DIR__ . '/autoloader.php';

// Bootstrap the plugin singleton, passing this file's path so it can expose
// get_plugin_file() and get_plugin_data() to the Updater and other consumers.
\Kntnt\Gpx_Blocks\Plugin::get_instance( __FILE__ );
