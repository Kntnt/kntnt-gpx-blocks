<?php
/**
 * Plugin uninstall handler.
 *
 * WordPress loads this file directly when the user deletes the plugin from the
 * admin area. The autoloader is NOT available here — use fully qualified
 * WordPress functions only, no class references.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

// Abort if WordPress did not trigger this file.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove all attachment post-meta added by this plugin.
global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( '_kntnt_gpx_blocks_' ) . '%'
	)
);
