<?php
/**
 * PSR-4 autoloader bootstrap.
 *
 * Delegates all class loading to the Composer-generated autoloader so that
 * the main plugin file stays thin and the class map benefits from
 * --optimize-autoloader.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

// Hand off to Composer's optimised class map.
require_once __DIR__ . '/vendor/autoload.php';
