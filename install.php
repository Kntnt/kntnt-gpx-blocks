<?php
/**
 * Plugin activation hooks.
 *
 * Registered in the main plugin file via register_activation_hook(). In v1
 * there is nothing to provision on activation — no custom capabilities, no
 * cron schedules, no rewrite rules — so the callback is a stub reserved for
 * future use.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

/**
 * Runs when the plugin is activated.
 *
 * Reserved for capability provisioning, cron scheduling, and rewrite-rules
 * flush in future versions. Currently a no-op.
 *
 * @since 1.0.0
 */
function kntnt_gpx_blocks_activate(): void {

	// Nothing to do in v1. Future versions will provision capabilities,
	// schedule cron events, and flush rewrite rules here.
}
