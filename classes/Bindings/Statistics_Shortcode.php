<?php
/**
 * Shortcode that exposes GPX track statistics anywhere shortcodes resolve.
 *
 * Registers `[kntnt-gpx <key>]` so any paragraph, heading, list item, classic
 * block, or widget can surface a single formatted statistic — distance, an
 * elevation extreme, or total ascent/descent — from the GPX Map on the same
 * post. Replaces the earlier `kntnt-gpx-blocks/statistics` Block Bindings
 * source: bindings args are per-paragraph and force editors to retarget five
 * rows individually when a multi-Map page demands an explicit `mapId`; a
 * shortcode lets the same `map="…"` attribute travel with the inline reference
 * to a single statistic.
 *
 * The inserted GPX Statistics block-variation now ships five `core/paragraph`s
 * whose `content` contains `[kntnt-gpx <key>]` directly — no bindings args, no
 * editor preview HOC, no editor-only REST endpoint. The shortcode renders on
 * standard `do_shortcode()` dispatch the same way every other shortcode does.
 *
 * Five inline shortcodes per inserted variation trigger five `render()` calls
 * with identical post/map context. A per-request memo collapses those down to
 * one block-tree walk and one cache fetch; the same memo also deduplicates
 * error logging across the five keys.
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
 * Registers and resolves the `[kntnt-gpx <key>]` shortcode.
 *
 * Held as a singleton-by-construction on the Plugin and registered on `init`.
 * The class is intentionally framework-thin: it dispatches the supplied key
 * to the appropriate Value_Formatter call after delegating map resolution to
 * Resolve_Map_Id and cache reads to Attachment_Cache. All collaborators are
 * constructor-injectable so the shortcode can be unit-tested without touching
 * `add_shortcode()`.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Statistics_Shortcode {

	/**
	 * Shortcode tag registered with WordPress.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const TAG = 'kntnt-gpx';

	/**
	 * Hyphenated public key → underscored cache key.
	 *
	 * Mirrors the cache shape produced by Statistics_Calculator and persisted
	 * under `_kntnt_gpx_blocks_statistics`. The hyphen variants are the only
	 * forms accepted from `[kntnt-gpx <key>]` — shortcode keys conventionally
	 * use hyphens, so the public surface matches that idiom while the cache
	 * keeps its existing underscore shape.
	 *
	 * @since 1.0.0
	 * @var array<string, string>
	 */
	private const KEY_MAP = [
		'distance'      => 'distance',
		'min-elevation' => 'min_elevation',
		'max-elevation' => 'max_elevation',
		'ascent'        => 'ascent',
		'descent'       => 'descent',
	];

	/**
	 * Per-request memo of resolved (post, map) pairs.
	 *
	 * Keyed by `"$post_id|$map_id"`. Holds either the success-shaped statistics
	 * array or the first encountered Render_Error so that the five inline
	 * shortcodes per inserted variation share one resolve and one cache fetch
	 * — and one error-log line.
	 *
	 * @since 1.0.0
	 * @var array<string, array{statistics: array<string, float|null>}|Render_Error>
	 */
	private array $resolution_memo = [];

	/**
	 * Constructs the shortcode handler with its three injectable collaborators.
	 *
	 * Defaults provide ergonomic production wiring; tests inject pre-seeded
	 * instances via the same constructor.
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
	 * Registers the shortcode with WordPress.
	 *
	 * Must be called on or after the `init` action. The shortcode is keyed by
	 * its first positional attribute, so `[kntnt-gpx distance]` and
	 * `[kntnt-gpx max-elevation map="map-abc"]` are both valid forms.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		add_shortcode( self::TAG, [ $this, 'render' ] );
	}

	/**
	 * Resolves a single shortcode invocation to the formatted statistic value.
	 *
	 * Bound to `add_shortcode()`. Returns an empty string on any error so
	 * visitors see no surface (loud signal) rather than a misleading placeholder.
	 *
	 * The `$atts` parameter is typed as mixed because WordPress passes
	 * `string` (`''`) when the shortcode has no attributes at all, but
	 * `array<string, string>` when it does. Both shapes coerce cleanly to the
	 * documented contract.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int|string, string>|string $atts Shortcode attributes — `array` in the
	 *                                               normal case, `string` (empty) when none
	 *                                               are supplied. The first positional value
	 *                                               carries the statistic key; the optional
	 *                                               `map="…"` attribute selects the map.
	 *
	 * @return string Formatted statistic (e.g. `"5.5 km"`, `"-8 m"`) or `''` on any error.
	 */
	public function render( array|string $atts ): string {

		// Normalise the attribute shape. shortcode_atts() expects an array.
		$normalised = is_array( $atts ) ? $atts : [];

		// Validate the positional key against the hyphen-keyed allow-list. An unknown
		// or missing key is a content-authoring bug, never user input — a single
		// warning is enough, no need to surface to visitors.
		$raw_key = $normalised[0] ?? '';
		if ( ! is_string( $raw_key ) || ! array_key_exists( $raw_key, self::KEY_MAP ) ) {
			Plugin::warning( sprintf(
				'Statistics_Shortcode: unknown statistics key "%s"',
				is_string( $raw_key ) ? $raw_key : '(non-string)',
			) );
			return '';
		}
		$cache_key = self::KEY_MAP[ $raw_key ];

		// Resolve mapId from the optional `map="…"` attribute; coerce empty/non-string
		// values to 'auto' the same way the bindings source used to.
		$raw_map_id = $normalised['map'] ?? 'auto';
		$map_id     = is_string( $raw_map_id ) && '' !== $raw_map_id ? $raw_map_id : 'auto';

		// Resolve the host post from the loop context. Shortcodes run inside the_content
		// (or do_shortcode() on an arbitrary string in widgets), so the global post is
		// the right anchor for "which page am I on?".
		$post_id = $this->resolve_post_id();
		if ( $post_id <= 0 ) {
			return '';
		}

		// Look up the memo or populate it. The memo holds either the statistics
		// payload or a Render_Error — either way, all five inline shortcodes per
		// inserted variation see the same outcome and incur the work only once.
		$memo_key = $post_id . '|' . $map_id;
		if ( ! array_key_exists( $memo_key, $this->resolution_memo ) ) {
			$this->resolution_memo[ $memo_key ] = $this->resolve_and_fetch( $map_id, $post_id );
		}
		$entry = $this->resolution_memo[ $memo_key ];

		// On any error path, return empty. The error has already been logged once
		// at memo-population time; subsequent reads do not re-log.
		if ( $entry instanceof Render_Error ) {
			return '';
		}

		// Format the requested statistic. Null values mean the track has no
		// elevation data (Statistics_Calculator returns null for the four
		// elevation keys in that case); render as empty so each row degrades
		// silently instead of showing "0 m".
		$raw_value = $entry['statistics'][ $cache_key ] ?? null;
		if ( null === $raw_value ) {
			return '';
		}

		return 'distance' === $cache_key
			? $this->formatter->format_distance( $raw_value )
			: $this->formatter->format_elevation( $raw_value );

	}

	/**
	 * Returns the host post ID from the current loop context, or 0 when unavailable.
	 *
	 * Uses `get_the_ID()` so the shortcode follows the same "current post"
	 * resolution that every other shortcode (and template tag) uses. Returns 0
	 * when called outside the loop — in which case the shortcode renders empty.
	 *
	 * @since 1.0.0
	 *
	 * @return int Host post ID, or 0 when no post is currently being rendered.
	 */
	private function resolve_post_id(): int {

		$id = function_exists( 'get_the_ID' ) ? get_the_ID() : false;
		return is_int( $id ) && $id > 0 ? $id : 0;

	}

	/**
	 * Resolves a (mapId, postId) pair to a statistics payload or a Render_Error.
	 *
	 * Map resolution first, then cache read. Logs once at error time so the
	 * memo can short-circuit subsequent calls without spamming the log.
	 *
	 * @since 1.0.0
	 *
	 * @param string $map_id  Map ID from the shortcode args; `'auto'` when unset.
	 * @param int    $post_id Host post ID from the loop context.
	 *
	 * @return array{statistics: array<string, float|null>}|Render_Error
	 */
	private function resolve_and_fetch( string $map_id, int $post_id ): array|Render_Error {

		// Resolve the map; surface the error sentinel and log once when it fails.
		$resolved = $this->resolver->resolve( $map_id, $post_id );
		if ( $resolved instanceof Render_Error ) {
			Plugin::error( sprintf(
				'Statistics_Shortcode: error resolving map (post %d, mapId %s), code=%s',
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
				'Statistics_Shortcode: error reading cache for attachment %d, code=%s',
				$resolved['attachment_id'],
				$payload->code,
			) );
			return $payload;
		}

		// Drop the GeoJSON; only the statistics array is needed.
		return [ 'statistics' => $payload['statistics'] ];

	}

}
