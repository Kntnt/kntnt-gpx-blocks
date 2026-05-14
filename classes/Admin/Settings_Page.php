<?php
/**
 * Settings page for site-wide plugin configuration.
 *
 * Adds an "Kntnt GPX Blocks" entry under the WordPress Settings menu and
 * registers a single option `kntnt_gpx_blocks_tile_provider_keys` holding
 * the per-base-provider tile API keys. The page uses the classic
 * Settings API: `add_options_page()` on `admin_menu` and
 * `register_setting()` + `add_settings_section()` + `add_settings_field()`
 * on `admin_init`. WordPress core's `options.php` handles the form POST,
 * nonce, and capability check; this class never touches `$_POST` directly.
 *
 * Centralising the keys here means a site administrator rotates one
 * entry per provider instead of editing every GPX Map block on the site
 * in turn — the central motivation for issue #149.
 *
 * The page is currently structured around base providers only; the
 * overlay-provider half (#150) will plug in a second sub-section through
 * the same `add_settings_section()` mechanism without restructuring this
 * class.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Admin;

use Kntnt\Gpx_Blocks\Rendering\Tile_Layer_Registry;

/**
 * Registers the Settings → Kntnt GPX Blocks admin page and its option.
 *
 * Bound from `Plugin::__construct()` to `admin_menu` (page registration)
 * and `admin_init` (setting + sections + fields). Stateless apart from
 * the injected `Tile_Layer_Registry`, which is shared with the rest of
 * the plugin so the filtered provider set drives the page identically
 * to how it drives the renderer.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Settings_Page {

	/**
	 * Option name where the per-base-provider API-key map is stored.
	 *
	 * Shape: `array<string, string>` keyed by base-provider id. Trimmed
	 * empty strings are stored as absence (the key is dropped from the
	 * array by the sanitize callback). Keys not in the validated
	 * provider registry are also dropped at sanitize time so a removed
	 * provider does not leave dead data behind.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const OPTION_NAME = 'kntnt_gpx_blocks_tile_provider_keys';

	/**
	 * Option-group name passed to `register_setting()`.
	 *
	 * WordPress core's `options.php` handler authenticates the form POST
	 * against this string via `settings_fields()`; every field on the
	 * page must live in the same group for the round-trip to succeed.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const OPTION_GROUP = 'kntnt_gpx_blocks';

	/**
	 * Menu slug for the settings page.
	 *
	 * Used by `add_options_page()`, `menu_page_url()`, and the editor
	 * Notice's link target. Mirrors the plugin's text domain so the URL
	 * `wp-admin/options-general.php?page=kntnt-gpx-blocks` reads
	 * unambiguously.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const MENU_SLUG = 'kntnt-gpx-blocks';

	/**
	 * Settings-section slug for the "Base providers" sub-section.
	 *
	 * The overlay-provider half (#150) will add a parallel section
	 * under a different slug; keeping this one base-only keeps the
	 * symmetry obvious without forcing it.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const SECTION_BASE_PROVIDERS = 'kntnt_gpx_blocks_base_providers';

	/**
	 * Constructs the settings page with an injectable registry.
	 *
	 * Tests inject a synthetic registry; production wiring constructs a
	 * fresh `Tile_Layer_Registry` lazily inside the hook callbacks so
	 * the filter chain runs against the live filter state, not against
	 * whatever state existed when the singleton booted.
	 *
	 * @since 1.0.0
	 *
	 * @param Tile_Layer_Registry|null $registry Tile-layer registry, or null to
	 *                                           construct a default one lazily.
	 */
	public function __construct( private readonly ?Tile_Layer_Registry $registry = null ) {}

	/**
	 * Registers the admin menu entry under Settings.
	 *
	 * Wired to `admin_menu`. `manage_options` gates the page so only
	 * site administrators can read or write the tile-API-key option —
	 * `edit_posts`-level users (authors, contributors) never see the
	 * menu entry and cannot reach the URL. The render callback is the
	 * `render_page()` method below.
	 *
	 * @since 1.0.0
	 */
	public function register_menu(): void {
		add_options_page(
			__( 'Kntnt GPX Blocks', 'kntnt-gpx-blocks' ),
			__( 'Kntnt GPX Blocks', 'kntnt-gpx-blocks' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_page' ],
		);
	}

	/**
	 * Registers the option, the section, and the per-provider fields.
	 *
	 * Wired to `admin_init`. `register_setting()` declares the option to
	 * core so `options.php` accepts the form POST and routes it through
	 * the supplied sanitize callback; `add_settings_section()` creates
	 * the "Base providers" heading; `add_settings_field()` emits one
	 * labelled input per base provider whose `requiresKey === true`,
	 * in registry order.
	 *
	 * Free providers (`requiresKey === false`) get no field — they have
	 * no key to configure. PHP-engaged providers (validated record
	 * carries `apiKey`) get a visible-but-disabled field with a
	 * read-only notice so the administrator sees that the key is
	 * supplied by code and is not editable through the UI.
	 *
	 * @since 1.0.0
	 */
	public function register_settings(): void {

		// Register the option with WordPress core. The sanitize callback
		// is the one place all writes flow through; non-array input
		// reverts to an empty map, non-string entries are dropped, and
		// values are trimmed before storage.
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			[
				'type'              => 'object',
				'description'       => __( 'Per-base-provider tile API keys, keyed by provider id.', 'kntnt-gpx-blocks' ),
				'sanitize_callback' => [ self::class, 'sanitize_keys' ],
				'default'           => [],
				'show_in_rest'      => false,
			],
		);

		// Build the "Base providers" sub-section under the Tile API
		// Keys heading rendered by render_page().
		add_settings_section(
			self::SECTION_BASE_PROVIDERS,
			__( 'Base providers', 'kntnt-gpx-blocks' ),
			[ $this, 'render_base_section_intro' ],
			self::MENU_SLUG,
		);

		// Emit one field per key-required base provider in registry
		// order. The registry is keyed by provider id; the iteration
		// order is therefore the order the validated default set (or
		// a filter-replacement set) declared.
		$registry = $this->registry ?? new Tile_Layer_Registry();
		foreach ( $registry->get_providers() as $provider_id => $record ) {
			if ( $record['requiresKey'] !== true ) {
				continue;
			}
			add_settings_field(
				self::OPTION_NAME . '_' . $provider_id,
				$record['label'],
				[ $this, 'render_provider_field' ],
				self::MENU_SLUG,
				self::SECTION_BASE_PROVIDERS,
				[
					'provider_id' => $provider_id,
					'record'      => $record,
				],
			);
		}

	}

	/**
	 * Sanitizes the submitted option value.
	 *
	 * Rejects non-array input, drops non-string entries, trims string
	 * values, drops entries that trim to the empty string (storing
	 * "absence" rather than `""`), and drops entries whose key is not
	 * a known base-provider id in the validated registry. The result
	 * is the map persisted to the option table.
	 *
	 * The provider-registry intersection is computed against a fresh
	 * `Tile_Layer_Registry` so the live filter state drives the prune,
	 * not whatever instance was passed to the constructor (which may
	 * have been built earlier in the request lifecycle).
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input Raw POSTed value from `options.php`.
	 *
	 * @return array<string, string> Sanitized map ready to persist.
	 */
	public static function sanitize_keys( mixed $input ): array {

		// Non-array input collapses to the empty map. WordPress submits
		// `''` for an entirely missing option; defensive coercion
		// covers tampered POSTs that ship a scalar or a nested array.
		if ( ! is_array( $input ) ) {
			return [];
		}

		// Resolve the validated provider registry once so the prune
		// runs against the current filter state, not the page's
		// constructor-time instance.
		$valid_provider_ids = array_keys( ( new Tile_Layer_Registry() )->get_providers() );

		// Walk the input map and accept every entry that satisfies all
		// three rules: known provider id, string value, non-empty after
		// trimming. Empty-after-trim is dropped so absence is the
		// canonical "no key" state in the persisted map.
		$out = [];
		foreach ( $input as $key => $value ) {
			if ( ! is_string( $key ) || ! in_array( $key, $valid_provider_ids, true ) ) {
				continue;
			}
			if ( ! is_string( $value ) ) {
				continue;
			}
			$trimmed = trim( $value );
			if ( $trimmed === '' ) {
				continue;
			}
			$out[ $key ] = $trimmed;
		}

		return $out;

	}

	/**
	 * Renders the wrapping settings page.
	 *
	 * Emits the WP-conventional `wrap > h1 > form` shell with the page
	 * heading "Tile API Keys", followed by `settings_fields()` and
	 * `do_settings_sections()` so core dispatches to the section
	 * intro callback and to every registered field's render callback.
	 *
	 * @since 1.0.0
	 */
	public function render_page(): void {

		// Capability re-check — `add_options_page()` already gates the
		// menu, but defence-in-depth against direct URL navigation by
		// users whose capability set changed mid-session.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Tile API Keys', 'kntnt-gpx-blocks' ) . '</h1>';
		echo '<form action="' . esc_url( admin_url( 'options.php' ) ) . '" method="post">';

		settings_fields( self::OPTION_GROUP );
		do_settings_sections( self::MENU_SLUG );
		submit_button();

		echo '</form>';
		echo '</div>';

	}

	/**
	 * Renders the "Base providers" section introduction.
	 *
	 * Surfaces a single paragraph explaining what the fields below do.
	 * `add_settings_section()` calls this once, immediately under the
	 * section heading and above the first field.
	 *
	 * @since 1.0.0
	 */
	public function render_base_section_intro(): void {
		echo '<p>';
		echo esc_html__(
			'Configure one API key per base-tile provider. The same key is used by every GPX Map block on the site that selects this provider.',
			'kntnt-gpx-blocks',
		);
		echo '</p>';
	}

	/**
	 * Renders a single per-provider input field.
	 *
	 * Called by `do_settings_sections()` for each provider registered
	 * via `add_settings_field()`. Reads the current value from the
	 * stored option (so a fresh page load reflects the persisted state),
	 * not from anywhere else.
	 *
	 * PHP-engaged providers (validated record carries `apiKey`) render
	 * with `disabled` and a read-only notice — the field exists so the
	 * administrator sees the provider in the list and understands why
	 * the key is unreachable from the UI, but the input cannot be
	 * edited. The disabled input also never POSTs, so the existing
	 * option entry for that provider survives untouched across saves
	 * — exactly the desired no-op semantics.
	 *
	 * @since 1.0.0
	 *
	 * @param array{provider_id: string, record: array<string, mixed>} $args
	 *        Arguments forwarded by `add_settings_field()`. `provider_id`
	 *        is the registry key; `record` is the validated provider
	 *        record (used here for `signupUrl` and `apiKey` engagement).
	 */
	public function render_provider_field( array $args ): void {

		$provider_id  = $args['provider_id'];
		$record       = $args['record'];
		$option_value = self::get_stored_keys();
		$current      = $option_value[ $provider_id ] ?? '';

		// `isset($record['apiKey'])` is the canonical PHP-path-engaged
		// signal across the codebase. The settings page mirrors that
		// invariant: presence (not value) hides the editability of the
		// field and surfaces the read-only notice.
		$php_engaged = isset( $record['apiKey'] );

		$field_name = self::OPTION_NAME . '[' . $provider_id . ']';
		$field_id   = self::OPTION_NAME . '_' . $provider_id;

		printf(
			'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text"%4$s />',
			esc_attr( $field_id ),
			esc_attr( $field_name ),
			esc_attr( $php_engaged ? '' : $current ),
			$php_engaged ? ' disabled="disabled"' : '',
		);

		// PHP-engaged providers get an explicit read-only notice so the
		// administrator understands that the field is disabled by
		// design, not by accident.
		if ( $php_engaged ) {
			echo ' <span class="description">';
			echo esc_html__( 'Supplied by code; this field is read-only.', 'kntnt-gpx-blocks' );
			echo '</span>';
		}

		// Surface the provider's signup URL as a help link below the
		// field so a fresh installation knows where to obtain a key.
		// Absent on providers whose registry record omits `signupUrl`.
		if ( isset( $record['signupUrl'] ) && is_string( $record['signupUrl'] ) && $record['signupUrl'] !== '' ) {
			printf(
				'<p class="description"><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
				esc_url( $record['signupUrl'] ),
				esc_html__( 'Get an API key', 'kntnt-gpx-blocks' ),
			);
		}

	}

	/**
	 * Returns the persisted per-base-provider key map.
	 *
	 * Central read accessor so the rest of the plugin reaches the
	 * option through a single named entry point rather than scattering
	 * `get_option()` calls and re-implementing the array-shape check.
	 * Returns the empty map when the option is missing, malformed, or
	 * any sanitize-callback prerequisite fails — callers can treat the
	 * result as the documented `array<string, string>` shape without
	 * further defensive checks.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> Keyed by base-provider id; never null.
	 */
	public static function get_stored_keys(): array {

		$raw = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $raw ) ) {
			return [];
		}

		// Defensive shape coercion — the sanitize callback already
		// drops non-string entries on the write path, but the option
		// table could have been edited directly (WP-CLI, SQL, a
		// migration) so the read path re-validates.
		$out = [];
		foreach ( $raw as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) ) {
				$out[ $key ] = $value;
			}
		}

		return $out;

	}

}
