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

	// Default option store is empty; tests override locally via
	// $GLOBALS['kntnt_sp_test_option'] when they need a populated state.
	$GLOBALS['kntnt_sp_test_option'] = [];
	Functions\when( 'get_option' )->alias(
		static function ( string $name, mixed $default = false ): mixed {
			if ( $name === Settings_Page::OPTION_NAME ) {
				$store = $GLOBALS['kntnt_sp_test_option'] ?? [];
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

test( 'register_settings registers the option in the kntnt_gpx_blocks group with the documented sanitize callback', function (): void {

	$captured_setting = null;
	Functions\when( 'register_setting' )->alias(
		static function (
			string $option_group,
			string $option_name,
			array $args,
		) use ( &$captured_setting ): void {
			$captured_setting = [
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

	expect( $captured_setting )->not->toBeNull();
	expect( $captured_setting['option_group'] )->toBe( 'kntnt_gpx_blocks' );
	expect( $captured_setting['option_name'] )->toBe( 'kntnt_gpx_blocks_tile_provider_keys' );
	expect( $captured_setting['args'] )->toHaveKey( 'sanitize_callback' );
	expect( is_callable( $captured_setting['args']['sanitize_callback'] ) )->toBeTrue();

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
				'id'    => $id,
				'title' => $title,
				'page'  => $page,
				'args'  => $args,
			];
		}
	);

	$page = new Settings_Page();
	$page->register_settings();

	// The default registry ships five key-required providers (Jawg
	// Maps, Mapbox, MapTiler, Stadia Maps, Thunderforest). The settings
	// page renders one field per provider — free providers get nothing.
	$provider_ids = array_map( static fn ( array $f ): string => (string) $f['args']['provider_id'], $captured_fields );

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

test( 'register_settings registers the Base providers section against the page slug', function (): void {

	Functions\when( 'register_setting' )->justReturn( null );
	Functions\when( 'add_settings_field' )->justReturn( null );

	$captured_section = null;
	Functions\when( 'add_settings_section' )->alias(
		static function (
			string $id,
			string $title,
			callable $callback,
			string $page,
		) use ( &$captured_section ): void {
			$captured_section = [
				'id'    => $id,
				'title' => $title,
				'page'  => $page,
			];
		}
	);

	( new Settings_Page() )->register_settings();

	expect( $captured_section )->not->toBeNull();
	expect( $captured_section['title'] )->toBe( 'Base providers' );
	expect( $captured_section['page'] )->toBe( 'kntnt-gpx-blocks' );

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
