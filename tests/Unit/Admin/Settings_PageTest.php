<?php
/**
 * Tests for Admin\Settings_Page.
 *
 * Covers the four sanitize_callback rules listed in the acceptance
 * criteria (non-array input rejected, non-string entries dropped,
 * whitespace trimmed, unknown provider ids dropped), the
 * register-menu/register-settings wiring (page slug, capability,
 * setting + section + fields registered), and the per-provider field
 * renderer's PHP-engaged disabled-with-notice branch.
 *
 * Brain Monkey stubs the WP Settings API functions so the class can
 * run without a live WordPress install.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Admin\Settings_Page;
use Kntnt\Gpx_Blocks\Rendering\Tile_Layer_Registry;

beforeEach( function (): void {

	// __() returns the source string verbatim so labels survive the
	// registry's translation-aware default builder.
	Functions\when( '__' )->returnArg( 1 );

	// Default apply_filters passthrough — every default provider/overlay
	// survives validation.
	Functions\when( 'apply_filters' )->alias(
		static function ( string $filter, mixed $value ): mixed {
			return $value;
		}
	);

	// Default option stores are empty; tests override locally via the
	// matching `$GLOBALS` entry when they need a populated state. The
	// overlay store (issue #150) is parallel to the base store from
	// #149, so both keys share the same shape and defaults.
	$GLOBALS['kntnt_sp_test_option']         = [];
	$GLOBALS['kntnt_sp_test_overlay_option'] = [];
	Functions\when( 'get_option' )->alias(
		static function ( string $name, mixed $default = false ): mixed {
			if ( $name === Settings_Page::OPTION_NAME ) {
				$store = $GLOBALS['kntnt_sp_test_option'] ?? [];
				return is_array( $store ) ? $store : $default;
			}
			if ( $name === Settings_Page::OPTION_NAME_OVERLAYS ) {
				$store = $GLOBALS['kntnt_sp_test_overlay_option'] ?? [];
				return is_array( $store ) ? $store : $default;
			}
			return $default;
		}
	);

	Functions\when( 'esc_html__' )->returnArg( 1 );
	Functions\when( 'esc_attr' )->returnArg( 1 );
	Functions\when( 'esc_html' )->returnArg( 1 );
	Functions\when( 'esc_url' )->returnArg( 1 );
	Functions\when( 'admin_url' )->alias(
		static fn ( string $path ): string => 'https://example.test/wp-admin/' . $path
	);
	Functions\when( 'current_user_can' )->justReturn( true );
	Functions\when( 'submit_button' )->justReturn( null );
	Functions\when( 'settings_fields' )->justReturn( null );
	Functions\when( 'do_settings_sections' )->justReturn( null );

} );

// ---------------------------------------------------------------------------
// sanitize_keys() — the option-write entry point
// ---------------------------------------------------------------------------

test( 'sanitize_keys rejects non-array input', function (): void {

	expect( Settings_Page::sanitize_keys( 'a string' ) )->toBe( [] );
	expect( Settings_Page::sanitize_keys( 42 ) )->toBe( [] );
	expect( Settings_Page::sanitize_keys( null ) )->toBe( [] );
	expect( Settings_Page::sanitize_keys( false ) )->toBe( [] );

} );

test( 'sanitize_keys drops non-string entries silently', function (): void {

	$input  = [
		'thunderforest' => 'GOOD-KEY',
		'mapbox'        => 12345,
		'maptiler'      => true,
		'jawg-maps'     => null,
		'stadia-maps'   => [ 'nested' => 'value' ],
	];
	$output = Settings_Page::sanitize_keys( $input );

	expect( $output )->toHaveKey( 'thunderforest' );
	expect( $output['thunderforest'] )->toBe( 'GOOD-KEY' );
	expect( $output )->not->toHaveKey( 'mapbox' );
	expect( $output )->not->toHaveKey( 'maptiler' );
	expect( $output )->not->toHaveKey( 'jawg-maps' );
	expect( $output )->not->toHaveKey( 'stadia-maps' );

} );

test( 'sanitize_keys trims whitespace and stores empty strings as absence', function (): void {

	$input  = [
		'thunderforest' => '  TRIMMED  ',
		'mapbox'        => "\t\nLEADING-TRAILING\n\t",
		'maptiler'      => '   ',
		'jawg-maps'     => '',
	];
	$output = Settings_Page::sanitize_keys( $input );

	expect( $output['thunderforest'] )->toBe( 'TRIMMED' );
	expect( $output['mapbox'] )->toBe( 'LEADING-TRAILING' );
	// Whitespace-only and empty entries are dropped — absence is the
	// canonical "no key" state.
	expect( $output )->not->toHaveKey( 'maptiler' );
	expect( $output )->not->toHaveKey( 'jawg-maps' );

} );

test( 'sanitize_keys drops entries whose key is not a known base-provider id', function (): void {

	$input  = [
		'thunderforest'     => 'GOOD',
		'dropped-by-filter' => 'KEY-FOR-UNKNOWN-PROVIDER',
		42                  => 'NUMERIC-KEY',
	];
	$output = Settings_Page::sanitize_keys( $input );

	expect( $output )->toHaveKey( 'thunderforest' );
	expect( $output )->not->toHaveKey( 'dropped-by-filter' );
	// Non-string keys never enter the iteration in a way that survives;
	// the assertion is structural.
	expect( $output )->not->toHaveKey( 42 );

} );

test( 'sanitize_keys round-trips a clean input set untouched', function (): void {

	// Every entry passes every rule: known provider id, string value,
	// non-empty after trim, no leading/trailing whitespace. The output
	// must equal the input verbatim.
	$input  = [
		'thunderforest' => 'TF-KEY',
		'mapbox'        => 'MB-KEY',
		'maptiler'      => 'MT-KEY',
		'jawg-maps'     => 'JM-KEY',
		'stadia-maps'   => 'SM-KEY',
	];
	$output = Settings_Page::sanitize_keys( $input );

	expect( $output )->toBe( $input );

} );

// ---------------------------------------------------------------------------
// sanitize_overlay_keys() — the overlay option-write entry point (#150)
//
// Mirrors the base-side cases verbatim against the overlay-provider
// registry. The default registry ships `openweathermap` as the sole
// key-required overlay provider; free overlays (waymarked-trails,
// openseamap, opensnowmap) are all valid keys for the registry-
// intersection check.
// ---------------------------------------------------------------------------

test( 'sanitize_overlay_keys rejects non-array input', function (): void {

	expect( Settings_Page::sanitize_overlay_keys( 'a string' ) )->toBe( [] );
	expect( Settings_Page::sanitize_overlay_keys( 42 ) )->toBe( [] );
	expect( Settings_Page::sanitize_overlay_keys( null ) )->toBe( [] );
	expect( Settings_Page::sanitize_overlay_keys( false ) )->toBe( [] );

} );

test( 'sanitize_overlay_keys drops non-string entries silently', function (): void {

	$input  = [
		'openweathermap'   => 'GOOD-KEY',
		'waymarked-trails' => 12345,
		'openseamap'       => true,
		'opensnowmap'      => null,
	];
	$output = Settings_Page::sanitize_overlay_keys( $input );

	expect( $output )->toHaveKey( 'openweathermap' );
	expect( $output['openweathermap'] )->toBe( 'GOOD-KEY' );
	expect( $output )->not->toHaveKey( 'waymarked-trails' );
	expect( $output )->not->toHaveKey( 'openseamap' );
	expect( $output )->not->toHaveKey( 'opensnowmap' );

} );

test( 'sanitize_overlay_keys trims whitespace and stores empty strings as absence', function (): void {

	$input  = [
		'openweathermap'   => '  TRIMMED  ',
		'waymarked-trails' => "\t\nLEADING-TRAILING\n\t",
		'openseamap'       => '   ',
		'opensnowmap'      => '',
	];
	$output = Settings_Page::sanitize_overlay_keys( $input );

	expect( $output['openweathermap'] )->toBe( 'TRIMMED' );
	expect( $output['waymarked-trails'] )->toBe( 'LEADING-TRAILING' );
	expect( $output )->not->toHaveKey( 'openseamap' );
	expect( $output )->not->toHaveKey( 'opensnowmap' );

} );

test( 'sanitize_overlay_keys drops entries whose key is not a known overlay-provider id', function (): void {

	$input  = [
		'openweathermap'    => 'GOOD',
		'dropped-by-filter' => 'KEY-FOR-UNKNOWN-OVERLAY',
		42                  => 'NUMERIC-KEY',
	];
	$output = Settings_Page::sanitize_overlay_keys( $input );

	expect( $output )->toHaveKey( 'openweathermap' );
	expect( $output )->not->toHaveKey( 'dropped-by-filter' );
	expect( $output )->not->toHaveKey( 42 );

} );

test( 'sanitize_overlay_keys round-trips a clean input set untouched', function (): void {

	$input  = [
		'openweathermap'   => 'OWM-KEY',
		'waymarked-trails' => 'WMT-KEY',
	];
	$output = Settings_Page::sanitize_overlay_keys( $input );

	expect( $output )->toBe( $input );

} );

// ---------------------------------------------------------------------------
// get_stored_keys() — the option-read accessor
// ---------------------------------------------------------------------------

test( 'get_stored_keys returns the empty map when the option is absent', function (): void {

	$GLOBALS['kntnt_sp_test_option'] = [];
	expect( Settings_Page::get_stored_keys() )->toBe( [] );

} );

test( 'get_stored_keys coerces a non-array option value to the empty map', function (): void {

	$GLOBALS['kntnt_sp_test_option'] = 'not-an-array';
	expect( Settings_Page::get_stored_keys() )->toBe( [] );

} );

test( 'get_stored_keys filters out non-string entries on the read path too', function (): void {

	// Direct DB edit could store a malformed shape — the read path
	// re-validates so callers can rely on `array<string, string>`.
	$GLOBALS['kntnt_sp_test_option'] = [
		'thunderforest' => 'GOOD',
		'mapbox'        => 42,
		'maptiler'      => [ 'nested' => 'value' ],
	];
	$output = Settings_Page::get_stored_keys();

	expect( $output )->toHaveKey( 'thunderforest' );
	expect( $output['thunderforest'] )->toBe( 'GOOD' );
	expect( $output )->not->toHaveKey( 'mapbox' );
	expect( $output )->not->toHaveKey( 'maptiler' );

} );

// ---------------------------------------------------------------------------
// get_stored_overlay_keys() — the overlay option-read accessor (#150)
// ---------------------------------------------------------------------------

test( 'get_stored_overlay_keys returns the empty map when the option is absent', function (): void {

	$GLOBALS['kntnt_sp_test_overlay_option'] = [];
	expect( Settings_Page::get_stored_overlay_keys() )->toBe( [] );

} );

test( 'get_stored_overlay_keys coerces a non-array option value to the empty map', function (): void {

	$GLOBALS['kntnt_sp_test_overlay_option'] = 'not-an-array';
	expect( Settings_Page::get_stored_overlay_keys() )->toBe( [] );

} );

test( 'get_stored_overlay_keys filters out non-string entries on the read path too', function (): void {

	$GLOBALS['kntnt_sp_test_overlay_option'] = [
		'openweathermap'   => 'GOOD',
		'waymarked-trails' => 42,
		'openseamap'       => [ 'nested' => 'value' ],
	];
	$output = Settings_Page::get_stored_overlay_keys();

	expect( $output )->toHaveKey( 'openweathermap' );
	expect( $output['openweathermap'] )->toBe( 'GOOD' );
	expect( $output )->not->toHaveKey( 'waymarked-trails' );
	expect( $output )->not->toHaveKey( 'openseamap' );

} );

// ---------------------------------------------------------------------------
// register_menu — the admin-menu binding
// ---------------------------------------------------------------------------

test( 'register_menu calls add_options_page with the documented slug, capability, and callback', function (): void {

	$captured = null;
	Functions\when( 'add_options_page' )->alias(
		static function (
			string $page_title,
			string $menu_title,
			string $capability,
			string $menu_slug,
			callable $callback,
		) use ( &$captured ): string {
			$captured = [
				'page_title' => $page_title,
				'menu_title' => $menu_title,
				'capability' => $capability,
				'menu_slug'  => $menu_slug,
				'callback'   => $callback,
			];
			return 'settings_page_kntnt-gpx-blocks';
		}
	);

	$page = new Settings_Page();
	$page->register_menu();

	expect( $captured )->not->toBeNull();
	expect( $captured['capability'] )->toBe( 'manage_options' );
	expect( $captured['menu_slug'] )->toBe( 'kntnt-gpx-blocks' );
	expect( $captured['page_title'] )->toBe( 'Kntnt GPX Blocks' );
	expect( $captured['menu_title'] )->toBe( 'Kntnt GPX Blocks' );
	expect( is_callable( $captured['callback'] ) )->toBeTrue();

} );

// ---------------------------------------------------------------------------
// register_settings — the option + section + per-field binding
// ---------------------------------------------------------------------------

test( 'register_settings registers the base option in the kntnt_gpx_blocks group with the documented sanitize callback', function (): void {

	$captured_settings = [];
	Functions\when( 'register_setting' )->alias(
		static function (
			string $option_group,
			string $option_name,
			array $args,
		) use ( &$captured_settings ): void {
			$captured_settings[] = [
				'option_group' => $option_group,
				'option_name'  => $option_name,
				'args'         => $args,
			];
		}
	);
	Functions\when( 'add_settings_section' )->justReturn( null );
	Functions\when( 'add_settings_field' )->justReturn( null );

	$page = new Settings_Page();
	$page->register_settings();

	$base = null;
	foreach ( $captured_settings as $entry ) {
		if ( $entry['option_name'] === 'kntnt_gpx_blocks_tile_provider_keys' ) {
			$base = $entry;
			break;
		}
	}
	expect( $base )->not->toBeNull();
	expect( $base['option_group'] )->toBe( 'kntnt_gpx_blocks' );
	expect( $base['args'] )->toHaveKey( 'sanitize_callback' );
	expect( is_callable( $base['args']['sanitize_callback'] ) )->toBeTrue();

} );

test( 'register_settings registers the overlay option in the kntnt_gpx_blocks group with the documented sanitize callback (issue #150)', function (): void {

	$captured_settings = [];
	Functions\when( 'register_setting' )->alias(
		static function (
			string $option_group,
			string $option_name,
			array $args,
		) use ( &$captured_settings ): void {
			$captured_settings[] = [
				'option_group' => $option_group,
				'option_name'  => $option_name,
				'args'         => $args,
			];
		}
	);
	Functions\when( 'add_settings_section' )->justReturn( null );
	Functions\when( 'add_settings_field' )->justReturn( null );

	$page = new Settings_Page();
	$page->register_settings();

	$overlay = null;
	foreach ( $captured_settings as $entry ) {
		if ( $entry['option_name'] === 'kntnt_gpx_blocks_tile_overlay_keys' ) {
			$overlay = $entry;
			break;
		}
	}
	expect( $overlay )->not->toBeNull();
	expect( $overlay['option_group'] )->toBe( 'kntnt_gpx_blocks' );
	expect( $overlay['args'] )->toHaveKey( 'sanitize_callback' );
	expect( is_callable( $overlay['args']['sanitize_callback'] ) )->toBeTrue();

} );

test( 'register_settings emits one settings field per key-required base provider', function (): void {

	Functions\when( 'register_setting' )->justReturn( null );
	Functions\when( 'add_settings_section' )->justReturn( null );

	$captured_fields = [];
	Functions\when( 'add_settings_field' )->alias(
		static function (
			string $id,
			string $title,
			callable $callback,
			string $page,
			string $section,
			array $args = [],
		) use ( &$captured_fields ): void {
			$captured_fields[] = [
				'id'      => $id,
				'title'   => $title,
				'page'    => $page,
				'section' => $section,
				'args'    => $args,
			];
		}
	);

	$page = new Settings_Page();
	$page->register_settings();

	// Filter to the base sub-section by section slug so the assertion
	// stays sharp across base- and overlay-field interleaving.
	$base_fields = array_values( array_filter(
		$captured_fields,
		static fn ( array $f ): bool => $f['section'] === 'kntnt_gpx_blocks_base_providers',
	) );

	// The default registry ships five key-required providers (Jawg
	// Maps, Mapbox, MapTiler, Stadia Maps, Thunderforest). The settings
	// page renders one field per provider — free providers get nothing.
	$provider_ids = array_map( static fn ( array $f ): string => (string) $f['args']['provider_id'], $base_fields );

	expect( $provider_ids )->toContain( 'thunderforest' );
	expect( $provider_ids )->toContain( 'mapbox' );
	expect( $provider_ids )->toContain( 'maptiler' );
	expect( $provider_ids )->toContain( 'jawg-maps' );
	expect( $provider_ids )->toContain( 'stadia-maps' );

	// Free providers never reach the section — the registry has
	// `requiresKey === false` for these.
	expect( $provider_ids )->not->toContain( 'openstreetmap' );
	expect( $provider_ids )->not->toContain( 'opentopomap' );
	expect( $provider_ids )->not->toContain( 'carto' );
	expect( $provider_ids )->not->toContain( 'esri' );

} );

test( 'register_settings emits one settings field per key-required overlay provider (issue #150)', function (): void {

	Functions\when( 'register_setting' )->justReturn( null );
	Functions\when( 'add_settings_section' )->justReturn( null );

	$captured_fields = [];
	Functions\when( 'add_settings_field' )->alias(
		static function (
			string $id,
			string $title,
			callable $callback,
			string $page,
			string $section,
			array $args = [],
		) use ( &$captured_fields ): void {
			$captured_fields[] = [
				'id'      => $id,
				'title'   => $title,
				'page'    => $page,
				'section' => $section,
				'args'    => $args,
			];
		}
	);

	$page = new Settings_Page();
	$page->register_settings();

	$overlay_fields = array_values( array_filter(
		$captured_fields,
		static fn ( array $f ): bool => $f['section'] === 'kntnt_gpx_blocks_overlay_providers',
	) );

	// The default registry ships only `openweathermap` as a key-
	// required overlay provider; every other default overlay provider
	// (waymarked-trails, openseamap, opensnowmap) is free and gets no
	// field. The captured ids must therefore include openweathermap
	// and nothing else from that set.
	$provider_ids = array_map( static fn ( array $f ): string => (string) $f['args']['provider_id'], $overlay_fields );

	expect( $provider_ids )->toContain( 'openweathermap' );
	expect( $provider_ids )->not->toContain( 'waymarked-trails' );
	expect( $provider_ids )->not->toContain( 'openseamap' );
	expect( $provider_ids )->not->toContain( 'opensnowmap' );

} );

test( 'register_settings registers the Overlay providers section against the page slug (issue #150)', function (): void {

	Functions\when( 'register_setting' )->justReturn( null );
	Functions\when( 'add_settings_field' )->justReturn( null );

	$captured_sections = [];
	Functions\when( 'add_settings_section' )->alias(
		static function (
			string $id,
			string $title,
			callable $callback,
			string $page,
		) use ( &$captured_sections ): void {
			$captured_sections[] = [
				'id'    => $id,
				'title' => $title,
				'page'  => $page,
			];
		}
	);

	( new Settings_Page() )->register_settings();

	$overlay_section = null;
	foreach ( $captured_sections as $entry ) {
		if ( $entry['id'] === 'kntnt_gpx_blocks_overlay_providers' ) {
			$overlay_section = $entry;
			break;
		}
	}
	expect( $overlay_section )->not->toBeNull();
	expect( $overlay_section['title'] )->toBe( 'Overlay providers' );
	expect( $overlay_section['page'] )->toBe( 'kntnt-gpx-blocks' );

} );

test( 'register_settings registers the Base providers section against the page slug', function (): void {

	Functions\when( 'register_setting' )->justReturn( null );
	Functions\when( 'add_settings_field' )->justReturn( null );

	$captured_sections = [];
	Functions\when( 'add_settings_section' )->alias(
		static function (
			string $id,
			string $title,
			callable $callback,
			string $page,
		) use ( &$captured_sections ): void {
			$captured_sections[] = [
				'id'    => $id,
				'title' => $title,
				'page'  => $page,
			];
		}
	);

	( new Settings_Page() )->register_settings();

	$base_section = null;
	foreach ( $captured_sections as $entry ) {
		if ( $entry['id'] === 'kntnt_gpx_blocks_base_providers' ) {
			$base_section = $entry;
			break;
		}
	}
	expect( $base_section )->not->toBeNull();
	expect( $base_section['title'] )->toBe( 'Base providers' );
	expect( $base_section['page'] )->toBe( 'kntnt-gpx-blocks' );

} );

// ---------------------------------------------------------------------------
// render_provider_field — disabled + read-only notice for PHP-engaged
// ---------------------------------------------------------------------------

test( 'render_provider_field renders disabled for PHP-engaged providers with a read-only notice', function (): void {

	// Inject a PHP-engaged record for thunderforest via the filter so
	// the page sees `apiKey` on the validated record.
	Functions\when( 'apply_filters' )->alias(
		static function ( string $filter, mixed $value ): mixed {
			if ( $filter === 'kntnt_gpx_blocks_tile_providers' ) {
				$set = is_array( $value ) ? $value : [];
				if ( isset( $set['thunderforest'] ) && is_array( $set['thunderforest'] ) ) {
					$set['thunderforest']['apiKey'] = 'PHP-VALUE';
				}
				return $set;
			}
			return $value;
		}
	);

	$page     = new Settings_Page();
	$registry = new Tile_Layer_Registry();
	$record   = $registry->get_providers()['thunderforest'];

	ob_start();
	$page->render_provider_field( [
		'provider_id' => 'thunderforest',
		'record'      => $record,
	] );
	$html = (string) ob_get_clean();

	expect( $html )->toContain( 'disabled="disabled"' );
	expect( $html )->toContain( 'Supplied by code; this field is read-only.' );
	// The empty value attribute is the disabled-input convention; the
	// PHP-supplied key value must never reach the rendered HTML.
	expect( $html )->not->toContain( 'PHP-VALUE' );

} );

test( 'render_provider_field renders editable for non-PHP-engaged providers, with the stored value pre-filled', function (): void {

	$GLOBALS['kntnt_sp_test_option'] = [ 'thunderforest' => 'EXISTING-KEY' ];

	$page     = new Settings_Page();
	$registry = new Tile_Layer_Registry();
	$record   = $registry->get_providers()['thunderforest'];

	ob_start();
	$page->render_provider_field( [
		'provider_id' => 'thunderforest',
		'record'      => $record,
	] );
	$html = (string) ob_get_clean();

	expect( $html )->not->toContain( 'disabled="disabled"' );
	expect( $html )->not->toContain( 'Supplied by code' );
	expect( $html )->toContain( 'EXISTING-KEY' );

} );

test( 'render_provider_field surfaces the signup URL when the registry record carries one', function (): void {

	$page     = new Settings_Page();
	$registry = new Tile_Layer_Registry();
	$record   = $registry->get_providers()['thunderforest'];

	ob_start();
	$page->render_provider_field( [
		'provider_id' => 'thunderforest',
		'record'      => $record,
	] );
	$html = (string) ob_get_clean();

	expect( $html )->toContain( 'https://www.thunderforest.com/' );
	expect( $html )->toContain( 'Get an API key' );

} );

// ---------------------------------------------------------------------------
// render_overlay_provider_field — symmetric overlay field renderer (#150)
// ---------------------------------------------------------------------------

test( 'render_overlay_provider_field renders disabled for PHP-engaged overlay providers with a read-only notice', function (): void {

	// Inject a PHP-engaged record for openweathermap via the filter so
	// the page sees `apiKey` on the validated overlay record.
	Functions\when( 'apply_filters' )->alias(
		static function ( string $filter, mixed $value ): mixed {
			if ( $filter === 'kntnt_gpx_blocks_tile_overlays' ) {
				$set = is_array( $value ) ? $value : [];
				if ( isset( $set['openweathermap'] ) && is_array( $set['openweathermap'] ) ) {
					$set['openweathermap']['apiKey'] = 'PHP-OVERLAY-VALUE';
				}
				return $set;
			}
			return $value;
		}
	);

	$page     = new Settings_Page();
	$registry = new Tile_Layer_Registry();
	$record   = $registry->get_overlays()['openweathermap'];

	ob_start();
	$page->render_overlay_provider_field( [
		'provider_id' => 'openweathermap',
		'record'      => $record,
	] );
	$html = (string) ob_get_clean();

	expect( $html )->toContain( 'disabled="disabled"' );
	expect( $html )->toContain( 'Supplied by code; this field is read-only.' );
	expect( $html )->not->toContain( 'PHP-OVERLAY-VALUE' );

} );

test( 'render_overlay_provider_field renders editable for non-PHP-engaged overlay providers, with the stored value pre-filled', function (): void {

	$GLOBALS['kntnt_sp_test_overlay_option'] = [ 'openweathermap' => 'EXISTING-OWM-KEY' ];

	$page     = new Settings_Page();
	$registry = new Tile_Layer_Registry();
	$record   = $registry->get_overlays()['openweathermap'];

	ob_start();
	$page->render_overlay_provider_field( [
		'provider_id' => 'openweathermap',
		'record'      => $record,
	] );
	$html = (string) ob_get_clean();

	expect( $html )->not->toContain( 'disabled="disabled"' );
	expect( $html )->not->toContain( 'Supplied by code' );
	expect( $html )->toContain( 'EXISTING-OWM-KEY' );
	// The field name uses the overlay option name so the form POST
	// round-trips through the overlay sanitize callback.
	expect( $html )->toContain( 'name="kntnt_gpx_blocks_tile_overlay_keys[openweathermap]"' );

} );

test( 'render_overlay_provider_field surfaces the signup URL when the overlay record carries one', function (): void {

	$page     = new Settings_Page();
	$registry = new Tile_Layer_Registry();
	$record   = $registry->get_overlays()['openweathermap'];

	ob_start();
	$page->render_overlay_provider_field( [
		'provider_id' => 'openweathermap',
		'record'      => $record,
	] );
	$html = (string) ob_get_clean();

	expect( $html )->toContain( 'https://openweathermap.org/' );
	expect( $html )->toContain( 'Get an API key' );

} );

// ---------------------------------------------------------------------------
// render_page — capability re-check and form scaffolding
// ---------------------------------------------------------------------------

test( 'render_page emits the wrap > h1 > form scaffold when the user has manage_options', function (): void {

	Functions\when( 'current_user_can' )->alias(
		static fn ( string $cap ): bool => $cap === 'manage_options'
	);

	ob_start();
	( new Settings_Page() )->render_page();
	$html = (string) ob_get_clean();

	expect( $html )->toContain( '<div class="wrap">' );
	expect( $html )->toContain( '<h1>Tile API Keys</h1>' );
	expect( $html )->toContain( '<form action="https://example.test/wp-admin/options.php"' );

} );

test( 'render_page returns silently for users without manage_options', function (): void {

	Functions\when( 'current_user_can' )->justReturn( false );

	ob_start();
	( new Settings_Page() )->render_page();
	$html = (string) ob_get_clean();

	expect( $html )->toBe( '' );

} );
