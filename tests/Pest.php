<?php
/**
 * Pest bootstrap file.
 *
 * Configures the test suite: declares the default test dataset, assigns
 * architectures, and sets up any global helpers shared across all tests.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

/*
 * Unit tests run with Brain Monkey so that WordPress functions are available
 * as mocks without a full WordPress install.
 */
uses( Tests\Unit\TestCase::class )->in( 'Unit' );
