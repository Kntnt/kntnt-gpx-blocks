<?php
/**
 * Block Bindings source that exposes GPX track statistics to bound paragraphs.
 *
 * Registers the `kntnt-gpx-blocks/statistics` source so any `core/paragraph`
 * (typically inside the bundled `kntnt-gpx-blocks/statistics` pattern) can
 * pull a single formatted statistic — distance, elevation extreme, or total
 * ascent/descent — from the GPX Map on the same post. Layout lives in the
 * pattern; the values come from here.
 *
 * Each pattern instance triggers five `get_value()` calls (one per binding
 * key) with identical post/map context. A per-request memo collapses those
 * down to one block-tree walk and one cache fetch; the same memo also
 * deduplicates error logging across the five keys.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Bindings;

use Kntnt\Gpx_Blocks\Cache\Attachment_Cache;
use Kntnt\Gpx_Blocks\Format\Value_Formatter;
use Kntnt\Gpx_Blocks\Plugin;
use Kntnt\Gpx_Blocks\Rendering\Render_Error;
use Kntnt\Gpx_Blocks\Rendering\Resolve_Map_Id;

/**
 * Provides the `kntnt-gpx-blocks/statistics` Block Bindings source.
 *
 * Held as a singleton-by-construction on the Plugin and registered on `init`.
 * The class is intentionally framework-thin: it dispatches the bound key to
 * the appropriate Value_Formatter call, after delegating map resolution to
 * Resolve_Map_Id and cache reads to Attachment_Cache. All collaborators are
 * constructor-injectable so the source can be unit-tested without touching
 * `register_block_bindings_source()`.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Statistics_Source {

	/**
	 * Allow-list of binding keys this source recognises.
	 *
	 * Mirrors the cache shape produced by Statistics_Calculator and
	 * persisted under `_kntnt_gpx_blocks_statistics`. Anything outside
	 * this set is treated as a pattern-authoring bug — logged as a
	 * warning and resolved to an empty string.
	 *
	 * @since 1.0.0
	 * @var array<int, string>
	 */
	private const ALLOWED_KEYS = [
		'distance',
		'min_elevation',
		'max_elevation',
		'ascent',
		'descent',
	];

	/**
	 * Per-request memo of resolved (post, map) pairs.
	 *
	 * Keyed by `"$post_id|$map_id"`. Holds either the success-shaped
	 * statistics array or the first encountered Render_Error so that the
	 * five binding-key calls per pattern instance share one resolve and
	 * one cache fetch — and one error-log line.
	 *
	 * @since 1.0.0
	 * @var array<string, array{statistics: array<string, float|null>}|Render_Error>
	 */
	private array $resolution_memo = [];

	/**
	 * Constructs the source with its three injectable collaborators.
	 *
	 * Defaults provide ergonomic production wiring; tests inject Mockery
	 * doubles or pre-seeded instances via the same constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Attachment_Cache $cache     Cache reader used after a successful map resolution.
	 * @param Resolve_Map_Id   $resolver  Resolver that maps `'auto'` or an explicit mapId to an attachment ID.
	 * @param Value_Formatter  $formatter Locale-aware metric formatter.
	 */
	public function __construct(
		private readonly Attachment_Cache $cache = new Attachment_Cache(),
		private readonly Resolve_Map_Id $resolver = new Resolve_Map_Id(),
		private readonly Value_Formatter $formatter = new Value_Formatter(),
	) {}

	/**
	 * Registers the source with WordPress.
	 *
	 * Must be called on or after the `init` action. Declares
	 * `uses_context: [ 'postId' ]` so the bound paragraph receives the
	 * host post's ID even when its own block.json does not declare it
	 * — which `core/paragraph` does not.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {

		register_block_bindings_source( 'kntnt-gpx-blocks/statistics', [
			'label'              => __( 'GPX statistics', 'kntnt-gpx-blocks' ),
			'get_value_callback' => [ $this, 'get_value' ],
			'uses_context'       => [ 'postId' ],
		] );

	}

	/**
	 * Resolves a single binding to the formatted statistic value.
	 *
	 * Bound to `register_block_bindings_source()`'s `get_value_callback`.
	 * Returns an empty string on any error so visitors see a blank value
	 * (loud signal) rather than the paragraph's placeholder text (silent
	 * misleading values).
	 *
	 * The $block parameter is typed as object — not \WP_Block — so unit
	 * tests can pass anonymous-class doubles with a public $context
	 * property. WordPress always supplies a genuine \WP_Block at runtime.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $source_args    Arguments declared on the binding,
	 *                                             carrying `key` (required) and
	 *                                             optionally `mapId` (defaults to `'auto'`).
	 * @param object               $block          Bound block instance with `$context['postId']`.
	 * @param string               $attribute_name Bound attribute name (unused — the source
	 *                                             is content-only and always returns text).
	 *
	 * @return string Formatted statistic (e.g. `"5.5 km"`, `"-8 m"`) or `''` on any error.
	 */
	public function get_value( array $source_args, object $block, string $attribute_name ): string {

		// Validate the key against the cache-shape allow-list. An unknown key
		// is a pattern-authoring bug and never originates from user input,
		// so a single warning is enough — no need to surface to visitors.
		$raw_key = $source_args['key'] ?? '';
		if ( ! is_string( $raw_key ) || ! in_array( $raw_key, self::ALLOWED_KEYS, true ) ) {
			Plugin::warning( sprintf(
				'Statistics_Source: unknown binding key "%s"',
				is_string( $raw_key ) ? $raw_key : '(non-string)',
			) );
			return '';
		}
		$key = $raw_key;

		// Resolve mapId from args; default to 'auto' when absent or empty.
		$raw_map_id = $source_args['mapId'] ?? 'auto';
		$map_id     = is_string( $raw_map_id ) && '' !== $raw_map_id ? $raw_map_id : 'auto';

		// Pull postId from the block context. uses_context='postId' guarantees
		// it is present in any sane render path; defensive coercion handles
		// the rare REST/preview edge cases where it isn't.
		$context     = property_exists( $block, 'context' ) && is_array( $block->context ) ? $block->context : [];
		$raw_post_id = $context['postId'] ?? 0;
		$post_id     = is_numeric( $raw_post_id ) ? (int) $raw_post_id : 0;
		if ( $post_id <= 0 ) {
			return '';
		}

		// Look up the memo or populate it. The memo holds either the
		// statistics payload or a Render_Error — either way, all five
		// binding-key calls per pattern instance see the same outcome
		// and incur the work only once.
		$memo_key = $post_id . '|' . $map_id;
		if ( ! array_key_exists( $memo_key, $this->resolution_memo ) ) {
			$this->resolution_memo[ $memo_key ] = $this->resolve_and_fetch( $map_id, $post_id );
		}
		$entry = $this->resolution_memo[ $memo_key ];

		// On any error path, return empty. The error has already been logged
		// once at memo-population time; we do not re-log on subsequent reads.
		if ( $entry instanceof Render_Error ) {
			return '';
		}

		// Format the requested statistic. Null values mean the track has no
		// elevation data (Statistics_Calculator returns null for the four
		// elevation keys in that case); render as empty so the paragraph
		// degrades silently row-by-row instead of showing "0 m".
		$raw_value = $entry['statistics'][ $key ] ?? null;
		if ( null === $raw_value ) {
			return '';
		}

		return 'distance' === $key
			? $this->formatter->format_distance( $raw_value )
			: $this->formatter->format_elevation( $raw_value );

	}

	/**
	 * Resolves a (mapId, postId) pair to a statistics payload or a Render_Error.
	 *
	 * Map resolution first, then cache read. Logs once at error time so
	 * the memo can short-circuit subsequent calls without spamming the log.
	 *
	 * @since 1.0.0
	 *
	 * @param string $map_id  Map ID from the binding args; `'auto'` when unset.
	 * @param int    $post_id Host post ID supplied by the block context.
	 *
	 * @return array{statistics: array<string, float|null>}|Render_Error
	 */
	private function resolve_and_fetch( string $map_id, int $post_id ): array|Render_Error {

		// Resolve the map; surface the error sentinel and log once when it fails.
		$resolved = $this->resolver->resolve( $map_id, $post_id );
		if ( $resolved instanceof Render_Error ) {
			Plugin::error( sprintf(
				'Statistics_Source: error resolving map (post %d, mapId %s), code=%s',
				$post_id,
				$map_id,
				$resolved->code,
			) );
			return $resolved;
		}

		// Read the cached statistics; cache may also lazy-regenerate on stale version/hash.
		$payload = $this->cache->get( $resolved['attachment_id'] );
		if ( $payload instanceof Render_Error ) {
			Plugin::error( sprintf(
				'Statistics_Source: error reading cache for attachment %d, code=%s',
				$resolved['attachment_id'],
				$payload->code,
			) );
			return $payload;
		}

		// Drop the GeoJSON; only the statistics array is needed for bindings.
		return [ 'statistics' => $payload['statistics'] ];

	}

}
