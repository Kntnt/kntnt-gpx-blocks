<?php
/**
 * Title:          GPX Statistics
 * Slug:           kntnt-gpx-blocks/statistics
 * Categories:     kntnt
 * Keywords:       gpx, statistics, distance, elevation, ascent, descent
 * Description:    Renders a server-side HTML summary of GPX track statistics: distance, elevation range, and total ascent/descent.
 * Viewport Width: 800
 *
 * Two-column grid pattern of label/value pairs. Each value paragraph is
 * bound to the `kntnt-gpx-blocks/statistics` Block Bindings source, which
 * pulls the corresponding statistic from the GPX Map block on the page.
 * The first row spans both columns to keep the total-length entry visually
 * dominant.
 *
 * Labels are wrapped in `esc_html__()` so they extract to the project's
 * `.po` file; the call is interpolated at insertion time, freezing the
 * locale-of-the-day into the inserted block markup. This is the standard
 * WordPress pattern-translation contract.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

?>
<!-- wp:group {"metadata":{"name":"GPX Statistics"},"style":{"spacing":{"blockGap":"var:preset|spacing|small"}},"layout":{"type":"grid","columnCount":2,"minimumColumnWidth":null}} -->
<div class="wp-block-group">

<!-- wp:group {"metadata":{"name":"Total length"},"style":{"layout":{"columnSpan":2},"spacing":{"blockGap":"0.5em"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
<div class="wp-block-group">
<!-- wp:paragraph -->
<p><strong><?php echo esc_html__( 'Total length', 'kntnt-gpx-blocks' ); ?>:</strong></p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"kntnt-gpx-blocks/statistics","args":{"key":"distance"}}}}} -->
<p>40 km</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->

<!-- wp:group {"metadata":{"name":"Lowest elevation"},"style":{"spacing":{"blockGap":"0.5em"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
<div class="wp-block-group">
<!-- wp:paragraph -->
<p><strong><?php echo esc_html__( 'Lowest elevation', 'kntnt-gpx-blocks' ); ?>:</strong></p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"kntnt-gpx-blocks/statistics","args":{"key":"min_elevation"}}}}} -->
<p>-8 m</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->

<!-- wp:group {"metadata":{"name":"Highest elevation"},"style":{"spacing":{"blockGap":"0.5em"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
<div class="wp-block-group">
<!-- wp:paragraph -->
<p><strong><?php echo esc_html__( 'Highest elevation', 'kntnt-gpx-blocks' ); ?>:</strong></p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"kntnt-gpx-blocks/statistics","args":{"key":"max_elevation"}}}}} -->
<p>89 m</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->

<!-- wp:group {"metadata":{"name":"Total ascent"},"style":{"spacing":{"blockGap":"0.5em"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
<div class="wp-block-group">
<!-- wp:paragraph -->
<p><strong><?php echo esc_html__( 'Total ascent', 'kntnt-gpx-blocks' ); ?>:</strong></p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"kntnt-gpx-blocks/statistics","args":{"key":"ascent"}}}}} -->
<p>157 m</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->

<!-- wp:group {"metadata":{"name":"Total descent"},"style":{"spacing":{"blockGap":"0.5em"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
<div class="wp-block-group">
<!-- wp:paragraph -->
<p><strong><?php echo esc_html__( 'Total descent', 'kntnt-gpx-blocks' ); ?>:</strong></p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"kntnt-gpx-blocks/statistics","args":{"key":"descent"}}}}} -->
<p>158 m</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->

</div>
<!-- /wp:group -->
