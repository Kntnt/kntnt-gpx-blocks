<?php
/**
 * Registers the bundled `kntnt` block-pattern category and the
 * `kntnt-gpx-blocks/statistics` pattern.
 *
 * The pattern markup lives in `patterns/statistics.php` (WP-canonical pattern
 * format with header comments and a PHP-rendered body that wraps each label
 * in `esc_html__()` so they extract to the project's `.po` file). This class
 * parses the file's headers via `get_file_data()`, captures the rendered HTML
 * via output buffering, and hands both to `register_block_pattern()` with the
 * title, description, and keywords routed through `__()` for translation.
 *
 * The `kntnt` pattern category is a separate registry from the existing
 * `kntnt` block category (`Block_Registrar::register_category()`); the two
 * mirror each other under the same human-facing label so the inserter
 * sidebar reads consistently.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Bootstrap;

use Kntnt\Gpx_Blocks\Plugin;

/**
 * Registers the plugin's pattern category and pattern files.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Pattern_Registrar {

	/**
	 * Slug of the pattern category this registrar manages.
	 *
	 * Mirrors the block category registered by Block_Registrar but lives
	 * in the separate pattern-category registry; the duplication of slug
	 * is intentional for visual consistency in the inserter sidebar.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const CATEGORY_SLUG = 'kntnt';

	/**
	 * Filename of the single pattern shipped with the plugin.
	 *
	 * Lives at `patterns/<filename>` relative to the plugin root. When the
	 * plugin grows a second pattern this constant becomes a list and the
	 * registration loop scans the directory.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const PATTERN_FILE = 'statistics.php';

	/**
	 * Constructs the registrar with an injectable patterns directory.
	 *
	 * The default resolves the plugin's own `patterns/` directory at
	 * runtime via `Plugin::get_plugin_file()`. Tests pass an explicit
	 * temp-dir path so they don't need to mutate Plugin's static state.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $patterns_dir Absolute path to the patterns directory,
	 *                                  or null to derive from Plugin::get_plugin_file().
	 */
	public function __construct( private readonly ?string $patterns_dir = null ) {}

	/**
	 * Registers the pattern category and the bundled pattern file.
	 *
	 * Wired to the `init` action by Plugin. Both registrations must
	 * happen on or after `init` because the underlying core registries
	 * are initialised on that hook.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {

		// Register the custom pattern category before any pattern that
		// references it, so the category lookup in register_block_pattern()
		// finds the entry.
		register_block_pattern_category( self::CATEGORY_SLUG, [
			'label' => __( 'Kntnt', 'kntnt-gpx-blocks' ),
		] );

		// Resolve and validate the pattern file. The plugin ships exactly
		// one pattern; a missing file is a packaging error worth surfacing.
		$base = $this->patterns_dir ?? dirname( Plugin::get_plugin_file() ) . '/patterns';
		$file = $base . '/' . self::PATTERN_FILE;
		if ( ! is_file( $file ) ) {
			Plugin::warning( sprintf( 'Pattern_Registrar: pattern file missing at %s', $file ) );
			return;
		}

		// Parse the pattern's header comments via the same key set
		// WordPress core uses for theme patterns.
		$headers = get_file_data( $file, [
			'title'         => 'Title',
			'slug'          => 'Slug',
			'description'   => 'Description',
			'categories'    => 'Categories',
			'keywords'      => 'Keywords',
			'viewportWidth' => 'Viewport Width',
		] );

		// A pattern without a slug cannot be registered; a missing slug is
		// a defect in the pattern file itself, not a runtime condition.
		$slug = $headers['slug'] ?? '';
		if ( '' === $slug ) {
			Plugin::warning( sprintf( 'Pattern_Registrar: pattern file %s has no Slug header', $file ) );
			return;
		}

		// Capture the file's PHP-rendered body. The file echoes block
		// markup with embedded `esc_html__()` calls for the labels;
		// ob_start/include is the conventional way WP core extracts
		// pattern content from its own pattern files.
		ob_start();
		include $file;
		$content = (string) ob_get_clean();

		// Split comma-separated header values into trimmed lists.
		$categories = self::split_csv( $headers['categories'] ?? '' );
		$keywords   = self::split_csv( $headers['keywords'] ?? '' );

		// Build the args bundle. Title, description, and keywords flow
		// through __() so .po translations apply when the pattern is
		// inserted; the body's labels were already translated at output
		// time during the include above. The header strings are dynamic
		// by design — the i18n sniff is suppressed for the block.
		// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- header values are intentionally dynamic.
		$args = [
			'title'   => __( $headers['title'] ?? $slug, 'kntnt-gpx-blocks' ),
			'content' => $content,
		];
		if ( '' !== ( $headers['description'] ?? '' ) ) {
			$args['description'] = __( $headers['description'], 'kntnt-gpx-blocks' );
		}
		if ( [] !== $categories ) {
			$args['categories'] = $categories;
		}
		if ( [] !== $keywords ) {
			$args['keywords'] = array_map(
				static fn ( string $k ): string => __( $k, 'kntnt-gpx-blocks' ),
				$keywords,
			);
		}
		// phpcs:enable WordPress.WP.I18n.NonSingularStringLiteralText
		if ( '' !== ( $headers['viewportWidth'] ?? '' ) && is_numeric( $headers['viewportWidth'] ) ) {
			$args['viewportWidth'] = (int) $headers['viewportWidth'];
		}

		register_block_pattern( $slug, $args );

	}

	/**
	 * Splits a comma-separated header value into a clean string list.
	 *
	 * Pattern header values like `Categories: text, kntnt` are stored as a
	 * single string by `get_file_data()`; both `register_block_pattern()`
	 * and the inserter expect arrays. Empty entries are dropped.
	 *
	 * @since 1.0.0
	 *
	 * @param string $raw Raw header value.
	 *
	 * @return array<int, string>
	 */
	private static function split_csv( string $raw ): array {

		if ( '' === $raw ) {
			return [];
		}

		$parts = array_map( 'trim', explode( ',', $raw ) );
		return array_values( array_filter( $parts, static fn ( string $p ): bool => '' !== $p ) );

	}

}
