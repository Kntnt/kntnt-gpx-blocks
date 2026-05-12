<?php
/**
 * Server-side render handler for the GPX Elevation block.
 *
 * Step 2 home for the Elevation block's frontend HTML. The class never
 * emits `<svg>` markup — chart geometry is rendered client-side from
 * Step 3 onward (see the *Rendering architecture* section at the top of
 * `docs/elevation-rebuild.md`). In Step 2 it carries two
 * responsibilities:
 *
 *   - `render()` dispatches to either the warning placeholder or the
 *     info placeholder after walking the block tree (via
 *     `Resolve_Map_Id::resolve()`) and reading the cache (via
 *     `Cache\Attachment_Cache::get()`).
 *   - `render_warning()` / `render_info()` produce the small coloured
 *     boxes the frontend shows in place of the chart.
 *
 * `render_info()` is temporary — it disappears in Step 3 when the SVG
 * lands. `render_warning()` survives as the no-data fallback. This
 * class is **not** a revival of v0.12.0's SVG-rendering
 * `Render_Elevation` (removed in commit aeb367f); it occupies the same
 * name in a different role.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rendering;

use Kntnt\Gpx_Blocks\Cache\Attachment_Cache;
use Kntnt\Gpx_Blocks\Plugin;

/**
 * Produces the frontend HTML for the GPX Elevation block.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Render_Elevation {

	/**
	 * Returns the rendered HTML for a single Elevation block instance.
	 *
	 * Resolves the bound `mapId` server-side via `Resolve_Map_Id` and
	 * either reads the cached statistics for the info-box or composes
	 * the appropriate warning-box string. The block wrapper is emitted
	 * through `get_block_wrapper_attributes()` so align, anchor, custom
	 * class, dimensions, border, and box-shadow block-supports propagate
	 * exactly as in Step 1.
	 *
	 * Visitors without `edit_posts` capability would normally see
	 * nothing on a broken binding, but the Step 2 placeholders are
	 * harmless plain text so we emit them unconditionally — Step 3
	 * tightens the no-data fallback to mirror Map's error-renderer
	 * pattern.
	 *
	 * @since 1.0.0
	 *
	 * The $block parameter is typed as object (not \WP_Block) so unit
	 * tests can pass anonymous objects.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $content    Inner block HTML (always empty).
	 * @param object              $block      The block instance.
	 *
	 * @return string Escaped HTML ready for output.
	 */
	public static function render( array $attributes, string $content, object $block ): string {

		// Resolve the bound `mapId` against the host post's block tree.
		// The Elevation block is always rendered inside a host post; the
		// resolver returns a `Render_Error` when no post context exists.
		$map_id = is_string( $attributes['mapId'] ?? null ) ? (string) $attributes['mapId'] : 'auto';
		$post_id = get_the_ID();
		$resolver = new Resolve_Map_Id();
		$resolved = $resolver->resolve( $map_id, is_int( $post_id ) ? $post_id : 0 );

		// Translate the resolver's error codes into the three Step 2
		// warning reasons. The map-not-found code covers both the
		// "deleted" and "deconfigured" subcases — `Resolve_Map_Id`
		// itself only sees configured Map blocks, so an unconfigured
		// bound Map looks like map-not-found from its perspective.
		if ( $resolved instanceof Render_Error ) {
			$reason = match ( $resolved->code ) {
				'no-map'        => 'no-map',
				'multiple-maps' => 'no-map',
				'map-not-found' => 'bound-deleted',
				default         => 'no-map',
			};
			return self::wrap( $attributes, self::render_warning( $reason ) );
		}

		// Read the cached statistics for the bound attachment.
		$cache   = new Attachment_Cache();
		$payload = $cache->get( $resolved['attachment_id'] );
		if ( $payload instanceof Render_Error ) {
			Plugin::error(
				sprintf( 'Render_Elevation: cache error for attachment %d, code=%s', $resolved['attachment_id'], $payload->code )
			);
			return self::wrap( $attributes, self::render_warning( 'bound-deleted' ) );
		}

		$min_raw = $payload['statistics']['min_elevation'] ?? null;
		$max_raw = $payload['statistics']['max_elevation'] ?? null;
		if ( null === $min_raw || null === $max_raw ) {
			return self::wrap( $attributes, self::render_warning( 'bound-deleted' ) );
		}

		// Compose the healthy-state info-box. The Step 2 label uses the
		// same three-tier rule as the editor picker; server-side we can
		// only read the metadata/anchor that survives `parse_blocks()`,
		// which is the published shape.
		$label = self::resolve_label( $resolved['map_id'], $post_id );
		$min   = (int) round( (float) $min_raw );
		$max   = (int) round( (float) $max_raw );

		return self::wrap( $attributes, self::render_info( $label, $min, $max ) );

	}

	/**
	 * Returns the warning-box HTML for one of the three error reasons.
	 *
	 * The reasons mirror the editor preview's discriminated union:
	 * `'no-map'`, `'bound-deleted'`, `'bound-unconfigured'`. Any other
	 * input falls back to the generic no-map message so a future
	 * extension does not produce an empty placeholder.
	 *
	 * @since 1.0.0
	 *
	 * @param string $reason One of the documented warning reasons.
	 *
	 * @return string The warning-box HTML, escaped and ready for output.
	 */
	public static function render_warning( string $reason ): string {

		$message = match ( $reason ) {
			'no-map' => __(
				'There is no GPX Map block with a selected GPX file on this page. Add a GPX Map block before this one.',
				'kntnt-gpx-blocks'
			),
			'bound-deleted' => __(
				'The GPX Map block this block was bound to is no longer on the page. Pick another from the dropdown.',
				'kntnt-gpx-blocks'
			),
			'bound-unconfigured' => __(
				'The GPX Map block this block is bound to has no GPX file selected.',
				'kntnt-gpx-blocks'
			),
			default => __(
				'There is no GPX Map block with a selected GPX file on this page. Add a GPX Map block before this one.',
				'kntnt-gpx-blocks'
			),
		};

		return sprintf(
			'<div class="kntnt-gpx-blocks-elevation-preview-warning" style="padding:0.75em 1em;background-color:#fdecea;border-left:4px solid #d93025;color:#5f2120;">%s</div>',
			esc_html( $message )
		);

	}

	/**
	 * Returns the info-box HTML for the healthy state.
	 *
	 * @since 1.0.0
	 *
	 * @param string $label Bound Map's user-facing label (already
	 *                      resolved through the three-tier rule).
	 * @param int    $min   Minimum elevation in metres, rounded.
	 * @param int    $max   Maximum elevation in metres, rounded.
	 *
	 * @return string The info-box HTML, escaped and ready for output.
	 */
	public static function render_info( string $label, int $min, int $max ): string {

		$message = sprintf(
			/* translators: 1: bound Map label, 2: minimum elevation in metres, 3: maximum elevation in metres. */
			__( 'Bound to %1$s. Min: %2$d m, Max: %3$d m.', 'kntnt-gpx-blocks' ),
			$label,
			$min,
			$max
		);

		return sprintf(
			'<div class="kntnt-gpx-blocks-elevation-preview-info" style="padding:0.75em 1em;background-color:#e8f0fe;border-left:4px solid #1a73e8;color:#0b3d91;">%s</div>',
			esc_html( $message )
		);

	}

	/**
	 * Resolves the picker label for a Map block by `mapId` against the
	 * host post's block tree.
	 *
	 * Server-side counterpart of the JS `pickerLabel()` resolver: walks
	 * the parsed block tree once, collecting every Map block (configured
	 * or not) so the fallback index counts the way the editor displays
	 * it. Tier 1 reads `attributes.metadata.name`, tier 2 reads
	 * `attributes.anchor`, tier 3 yields `GPX Map #N`.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bound_map_id The bound `mapId` to label.
	 * @param mixed  $post_id      Host post ID. `false` outside the loop
	 *                              short-circuits to the tier-3 fallback.
	 *
	 * @return string The resolved label, already localised.
	 */
	private static function resolve_label( string $bound_map_id, mixed $post_id ): string {

		if ( ! is_int( $post_id ) || $post_id <= 0 ) {
			return self::generic_fallback( 1 );
		}
		$post = get_post( $post_id );
		if ( null === $post ) {
			return self::generic_fallback( 1 );
		}

		$blocks = parse_blocks( $post->post_content );
		$flat   = self::collect_map_blocks( $blocks );
		foreach ( $flat as $index => $block ) {
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			$id    = is_string( $attrs['mapId'] ?? null ) ? (string) $attrs['mapId'] : '';
			if ( $id !== $bound_map_id ) {
				continue;
			}
			$metadata_raw = $attrs['metadata'] ?? null;
			$metadata     = is_array( $metadata_raw ) ? $metadata_raw : [];
			$name_raw     = $metadata['name'] ?? null;
			if ( is_string( $name_raw ) && '' !== trim( $name_raw ) ) {
				return $name_raw;
			}
			$anchor_raw = $attrs['anchor'] ?? null;
			if ( is_string( $anchor_raw ) && '' !== $anchor_raw ) {
				return $anchor_raw;
			}
			return self::generic_fallback( $index + 1 );
		}

		return self::generic_fallback( 1 );

	}

	/**
	 * Generic "GPX Map #N" fallback string.
	 *
	 * @since 1.0.0
	 *
	 * @param int $index The 1-based index.
	 *
	 * @return string The localised fallback.
	 */
	private static function generic_fallback( int $index ): string {

		return sprintf(
			/* translators: %d is the 1-based index of a GPX Map block on the page. */
			__( 'GPX Map #%d', 'kntnt-gpx-blocks' ),
			$index
		);

	}

	/**
	 * Recursively walks a parsed block tree and collects every GPX Map
	 * block, configured or not, in pre-order document traversal.
	 *
	 * Mirrors the JS `collectMapBlocks()` walk so editor and frontend
	 * eligibility for the fallback index agree.
	 *
	 * @since 1.0.0
	 *
	 * @param array<mixed,mixed> $blocks Parsed block array.
	 *
	 * @return list<array<mixed,mixed>>
	 */
	private static function collect_map_blocks( array $blocks ): array {

		$result = [];
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( ( $block['blockName'] ?? null ) === 'kntnt-gpx-blocks/map' ) {
				$result[] = $block;
			}
			$inner = $block['innerBlocks'] ?? null;
			if ( is_array( $inner ) && count( $inner ) > 0 ) {
				$result = array_merge( $result, self::collect_map_blocks( $inner ) );
			}
		}
		return $result;

	}

	/**
	 * Wraps an inner HTML fragment in the block's outer `<div>` via
	 * `get_block_wrapper_attributes()`.
	 *
	 * The Background attribute still drives
	 * `--kntnt-gpx-blocks-elevation-background` in the same shape Step 1
	 * established, so the wrapper styling is unchanged from the
	 * Step-1 baseline. The Step-1 inline `<style>` rule for the
	 * background fallback is kept here so the wrapper retains visual
	 * parity with the editor.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $inner_html Pre-escaped HTML to wrap.
	 *
	 * @return string Final HTML.
	 */
	private static function wrap( array $attributes, string $inner_html ): string {

		$background = Color_Sanitizer::sanitize( is_string( $attributes['backgroundColor'] ?? null ) ? (string) $attributes['backgroundColor'] : '' );
		$inline_style = '' !== $background
			? '--kntnt-gpx-blocks-elevation-background: ' . $background . ';'
			: '';

		$wrapper_attributes = get_block_wrapper_attributes(
			[
				'class' => 'kntnt-gpx-blocks-elevation',
				'style' => $inline_style,
			]
		);

		// One-rule inline stylesheet matching the Step-1 contract.
		// Step 3 introduces `style.scss` and this echo migrates into it.
		$style_tag = '<style>.kntnt-gpx-blocks-elevation { background-color: var( --kntnt-gpx-blocks-elevation-background, transparent ); }</style>';

		return $style_tag . sprintf( '<div %s>%s</div>', $wrapper_attributes, $inner_html );

	}

}
