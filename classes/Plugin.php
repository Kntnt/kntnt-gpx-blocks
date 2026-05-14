<?php
/**
 * Plugin singleton — entry point and logging API.
 *
 * Wires all components, exposes static helpers required by Updater, and
 * provides the four logging methods that every class in the plugin uses
 * instead of calling error_log() directly.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks;

/**
 * Singleton entry point for the kntnt-gpx-blocks plugin.
 *
 * Holds the absolute path to the main plugin file, caches the parsed plugin
 * header, and gates all log output through a single, filterable level check.
 *
 * Usage from outside:
 *   Plugin::get_instance()          — bootstrap; idempotent after first call.
 *   Plugin::get_plugin_file()       — absolute path to kntnt-gpx-blocks.php.
 *   Plugin::get_plugin_data()       — parsed plugin header (array).
 *   Plugin::error( $msg )           — log at ERROR level.
 *   Plugin::warning( $msg )         — log at WARNING level.
 *   Plugin::info( $msg )            — log at INFO level.
 *   Plugin::debug( $msg )           — log at DEBUG level.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Plugin {

	/**
	 * Log-level hierarchy: maps each level name to its numeric severity.
	 *
	 * Lower numbers are more severe. A message is written when its severity
	 * is less than or equal to the configured threshold.
	 *
	 * @since 1.0.0
	 * @var array<string,int>
	 */
	private const LOG_LEVELS = [
		'none'    => -1,
		'error'   => 0,
		'warning' => 1,
		'info'    => 2,
		'debug'   => 3,
	];

	/**
	 * The sole instance of this class.
	 *
	 * @since 1.0.0
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Absolute path to the main plugin file (kntnt-gpx-blocks.php).
	 *
	 * Set once during bootstrap and used by get_plugin_file() and
	 * get_plugin_data().
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private static string $plugin_file = '';

	/**
	 * Cached return value of get_file_data() / get_plugin_data().
	 *
	 * Populated lazily on the first call to get_plugin_data(). WordPress's
	 * get_plugin_data() returns a typed shape (mostly strings, with Network as
	 * bool); get_file_data() returns string[]. Both are accepted as array<mixed>.
	 *
	 * @since 1.0.0
	 * @var array<mixed>|null
	 */
	private static ?array $plugin_data = null;

	/**
	 * Returns (and on first call, creates) the singleton instance.
	 *
	 * Stores the path to the main plugin file so that get_plugin_file() and
	 * get_plugin_data() can work without globals. Calling this method a second
	 * time is a no-op and returns the existing instance.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin_file Absolute path to kntnt-gpx-blocks.php.
	 *                            Ignored on subsequent calls.
	 * @return self
	 */
	public static function get_instance( string $plugin_file = '' ): self {

		// Return early when already bootstrapped.
		if ( self::$instance !== null ) {
			return self::$instance;
		}

		// Capture the plugin file path and initialise the singleton.
		self::$plugin_file = $plugin_file;
		self::$instance    = new self();

		return self::$instance;

	}

	/**
	 * Returns the absolute path to the main plugin file.
	 *
	 * Required by Updater::check_for_updates() to build the plugin slug path
	 * via plugin_basename().
	 *
	 * @since 1.0.0
	 *
	 * @return string Absolute path, e.g. /var/www/wp-content/plugins/kntnt-gpx-blocks/kntnt-gpx-blocks.php
	 */
	public static function get_plugin_file(): string {
		return self::$plugin_file;
	}

	/**
	 * Returns the parsed plugin header, cached after the first call.
	 *
	 * The array keys match what get_file_data() / get_plugin_data() return:
	 * 'Name', 'Version', 'PluginURI', 'Description', 'Author', 'AuthorURI',
	 * 'TextDomain', 'DomainPath', 'Network', 'RequiresWP', 'RequiresPHP'.
	 *
	 * Required by Updater::check_for_updates() to read 'Version' and 'PluginURI'.
	 *
	 * @since 1.0.0
	 *
	 * @return array<mixed>
	 */
	public static function get_plugin_data(): array {

		// Return the cached result to avoid repeated file reads.
		if ( self::$plugin_data !== null ) {
			return self::$plugin_data;
		}

		// Parse the plugin header from the main plugin file.
		$default_headers = [
			'Name'        => 'Plugin Name',
			'PluginURI'   => 'Plugin URI',
			'Version'     => 'Version',
			'Description' => 'Description',
			'Author'      => 'Author',
			'AuthorURI'   => 'Author URI',
			'TextDomain'  => 'Text Domain',
			'DomainPath'  => 'Domain Path',
			'Network'     => 'Network',
			'RequiresWP'  => 'Requires at least',
			'RequiresPHP' => 'Requires PHP',
		];

		// Prefer the WordPress function when available; fall back to get_file_data()
		// for contexts where the full plugin API isn't loaded yet.
		if ( function_exists( 'get_plugin_data' ) ) {
			self::$plugin_data = get_plugin_data( self::$plugin_file, false, false );
		} else {
			self::$plugin_data = get_file_data( self::$plugin_file, $default_headers );
		}

		return self::$plugin_data;

	}

	/**
	 * Logs a message at ERROR level.
	 *
	 * Always writes when the configured log level is 'error' (the default)
	 * or more verbose. Silenced only by 'none'.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Human-readable log message. No stack trace appended.
	 */
	public static function error( string $message ): void {
		self::log( 'error', $message );
	}

	/**
	 * Logs a message at WARNING level.
	 *
	 * Written when the configured level is 'warning', 'info', or 'debug'.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Human-readable log message.
	 */
	public static function warning( string $message ): void {
		self::log( 'warning', $message );
	}

	/**
	 * Logs a message at INFO level.
	 *
	 * Written when the configured level is 'info' or 'debug'.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Human-readable log message.
	 */
	public static function info( string $message ): void {
		self::log( 'info', $message );
	}

	/**
	 * Logs a message at DEBUG level.
	 *
	 * Written only when the configured level is 'debug'. Should not be left
	 * on in production — the debug stream contains operational detail
	 * (attachment IDs, file hashes, timings).
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Human-readable log message.
	 */
	public static function debug( string $message ): void {
		self::log( 'debug', $message );
	}

	/**
	 * Writes a log line to PHP's error_log() when the message's level passes
	 * the configured threshold.
	 *
	 * Format: [kntnt-gpx-blocks] [LEVEL] message
	 *
	 * The threshold is read from the KNTNT_GPX_BLOCKS_LOG_LEVEL constant.
	 * If undefined, it defaults to 'error'. The value 'none' suppresses all
	 * output.
	 *
	 * @since 1.0.0
	 *
	 * @param string $level   One of 'error', 'warning', 'info', 'debug'.
	 * @param string $message The text to log.
	 */
	private static function log( string $level, string $message ): void {

		// Resolve the configured threshold, defaulting to 'error'.
		// constant() returns mixed; we use is_string() to safely narrow the type.
		$constant_value = defined( 'KNTNT_GPX_BLOCKS_LOG_LEVEL' ) ? constant( 'KNTNT_GPX_BLOCKS_LOG_LEVEL' ) : null;
		$raw_threshold  = is_string( $constant_value ) ? $constant_value : 'error';
		$threshold_key  = array_key_exists( $raw_threshold, self::LOG_LEVELS ) ? $raw_threshold : 'error';

		// Bail when the threshold is 'none' or the message level is too verbose.
		$threshold_value = self::LOG_LEVELS[ $threshold_key ];
		if ( $threshold_value < 0 || self::LOG_LEVELS[ $level ] > $threshold_value ) {
			return;
		}

		// Write the formatted line to the PHP error log.
		error_log( '[kntnt-gpx-blocks] [' . strtoupper( $level ) . '] ' . $message );

	}

	/**
	 * Wires all plugin components and registers their WordPress hooks.
	 *
	 * Instantiated once by get_instance(). Components are created in dependency
	 * order; each registers its own actions and filters here so the constructor
	 * remains the single authoritative place to trace the hook graph.
	 *
	 * Component instances are local variables. The global `$wp_filter` array
	 * (and `$shortcode_tags` for the shortcode handler) holds the bound
	 * array callable `[$object, 'method']`, which keeps the object alive for
	 * the lifetime of the request.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {

		// Bootstrap block registration and the custom block category.
		$block_registrar = new Bootstrap\Block_Registrar();
		add_action( 'init', [ $block_registrar, 'register' ] );
		add_filter( 'block_categories_all', [ $block_registrar, 'register_category' ], 10, 2 );

		// Register the GPX MIME type and correct finfo's detection result for .gpx files.
		$mime_registrar = new Bootstrap\Mime_Registrar();
		add_filter( 'upload_mimes', [ $mime_registrar, 'add_gpx' ] );
		add_filter( 'wp_check_filetype_and_ext', [ $mime_registrar, 'override_check' ], 10, 5 );

		// Enforce the GPX file-size cap before WordPress processes the upload.
		$upload_guard = new Bootstrap\Upload_Guard();
		add_filter( 'wp_handle_upload_prefilter', [ $upload_guard, 'enforce_size_cap' ] );

		// The cache layer is shared between the upload-lifecycle hooks and the
		// WP-CLI command, so it is constructed once and injected into both.
		$attachment_cache = new Cache\Attachment_Cache();

		// Wire the upload lifecycle to the cache: regenerate on add, and on
		// updates that actually change the file's bytes.
		$conversion_hooks = new Bootstrap\Conversion_Hooks( $attachment_cache );
		add_action( 'add_attachment', [ $conversion_hooks, 'on_added' ] );
		add_action( 'attachment_updated', [ $conversion_hooks, 'on_updated' ] );

		// Register the WP-CLI command only when running under WP_CLI to keep the
		// web request path free of CLI dependencies.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'kntnt-gpx regenerate', new Cli\Regenerate_Command( $attachment_cache ) );
		}

		// Wire the update checker to the WordPress update transient.
		$updater = new Updater();
		add_filter( 'pre_set_site_transient_update_plugins', [ $updater, 'check_for_updates' ] );

		// Inline the consent-contract stub in <head> on every frontend request.
		// docs/consent.md requires the stub to load before any block view module
		// reads window.kntnt_gpx_blocks, which wp_enqueue_scripts at default
		// priority guarantees.
		$consent_stub = new Consent\Consent_Stub();
		add_action( 'wp_enqueue_scripts', [ $consent_stub, 'enqueue' ] );

		// Register the editor-only preview REST endpoint so the GPX Map block's
		// Edit component can fetch cached GeoJSON without going through
		// ServerSideRender. The Interactivity API does not bootstrap inside
		// SSR-injected DOM in the editor, so the editor mounts Leaflet via a
		// React useEffect against this endpoint.
		$preview_controller = new Rest\Preview_Controller( $attachment_cache );
		add_action( 'rest_api_init', [ $preview_controller, 'register_routes' ] );

		// Register the [kntnt-gpx <key>] shortcode that exposes the cached
		// GPX statistics anywhere shortcodes resolve. The GPX Statistics
		// block-variation ships five `core/paragraph`s whose `content`
		// contains the shortcode inline; the same shortcode is equally
		// available in any other paragraph, heading, list item, classic
		// block, or widget on the page. The shortcode's per-request memo
		// of resolved (post, map) pairs survives across the five inline
		// calls because add_shortcode() retains the same array callable
		// — and therefore the same object — in the global $shortcode_tags
		// registry for the lifetime of the request.
		$statistics_shortcode = new Bindings\Statistics_Shortcode( $attachment_cache );
		add_action( 'init', [ $statistics_shortcode, 'register' ] );

		// Enqueue the editor-only script that registers the GPX Statistics
		// block variation of core/group. The variation surfaces the layout
		// in the main block inserter (under the kntnt category), with the
		// same bindings on the inner paragraphs as a manual insertion.
		$variation_registrar = new Bootstrap\Variation_Registrar();
		add_action( 'enqueue_block_editor_assets', [ $variation_registrar, 'enqueue' ] );

		// Inline `window.kntntGpxBlocks` with the validated tile-provider and
		// overlay registries on every editor request so the GPX Map block's
		// Inspector controls can enumerate them. Both the per-block tile
		// dropdown (issue #79) and the overlay toggles (issue #80) read from
		// this single global.
		$editor_data_enqueuer = new Bootstrap\Editor_Data_Enqueuer();
		add_action( 'enqueue_block_editor_assets', [ $editor_data_enqueuer, 'enqueue' ] );

		// Opt the Map and Elevation blocks into the editor's Border panel via
		// the theme.json data layer (issue #87). The block.json declarations
		// alone aren't enough on themes that don't enable appearanceTools or
		// per-feature border settings; the per-block opt-in here surfaces
		// the panel regardless of the active theme.
		$theme_json_border_optin = new Bootstrap\Theme_Json_Border_Optin();
		add_filter( 'wp_theme_json_data_theme', [ $theme_json_border_optin, 'filter' ] );

		// Extend the editor's Dimensions → Aspect ratio dropdown for the Map
		// and Elevation blocks with six panorama-friendly presets (issue #108).
		// Core's WP_Theme_JSON::merge_lists() deduplicates by slug, so the
		// kntnt-prefixed entries append to whatever the active theme exposes.
		// Other blocks see the dropdown unchanged.
		$theme_json_aspect_ratios = new Bootstrap\Theme_Json_Aspect_Ratios();
		add_filter( 'wp_theme_json_data_theme', [ $theme_json_aspect_ratios, 'filter' ] );

		// Normalise the per-corner `style.border.radius` shape on the two
		// blocks before the wrapper is rendered (issue #109). Gutenberg
		// occasionally saves the object form with one corner stored as the
		// empty string while the editor preview still shows all four corners
		// rounded; the style engine then drops that corner on the frontend.
		// Collapsing the object to the unified string when every non-empty
		// corner agrees produces the four-rounded-corner output the editor
		// preview promised.
		$border_radius_normalizer = new Bootstrap\Border_Radius_Normalizer();
		add_filter( 'render_block_data', [ $border_radius_normalizer, 'filter' ] );

		// Normalise the plugin-defined `min-height` default on the two
		// blocks before the wrapper is rendered (issue #117). When both
		// `style.dimensions.minHeight` and `style.dimensions.aspectRatio`
		// are blank or missing, write the per-block default (`30vh` Map,
		// `15vh` Elevation) onto the parsed block's `attrs`. Every
		// downstream consumer — `get_block_wrapper_attributes()`, the
		// SCSS baseline, the editor's `useBlockProps()` style merge —
		// then sees a concrete value through the standard block-supports
		// pipeline, the same path an explicit user value would take.
		$dimensions_defaults = new Rendering\Dimensions_Defaults();
		add_filter( 'render_block_data', [ $dimensions_defaults, 'filter' ] );

		// Register the Settings → Kntnt GPX Blocks admin page that
		// surfaces the central per-base-provider tile-API-key option
		// (issue #149). The page is the canonical UI for the option;
		// site administrators rotate one entry per provider instead of
		// editing every Map block in turn. The overlay-provider half
		// (#150) plugs a parallel sub-section into the same page.
		$settings_page = new Admin\Settings_Page();
		add_action( 'admin_menu', [ $settings_page, 'register_menu' ] );
		add_action( 'admin_init', [ $settings_page, 'register_settings' ] );

	}

}
