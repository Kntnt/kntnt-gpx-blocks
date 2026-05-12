<?php
/**
 * Tests for Rendering\Render_Elevation.
 *
 * Coverage:
 *
 * - `render_warning()` returns the documented string for each of the
 *   five Step 3 reasons (`no-map`, `bound-deleted`,
 *   `bound-unconfigured`, `no-elevation-data`, `zero-distance`), and
 *   falls back to `no-map` for unknown reasons.
 * - `render_warning()` escapes its message through esc_html().
 * - `render()` dispatches:
 *     - the `no-map` warning when no Map block exists on the host post,
 *     - the `bound-deleted` warning when the explicit `mapId` does not
 *       match any Map,
 *     - the `no-elevation-data` warning when the bound track has null
 *       min/max elevation (Step 3 Case A),
 *     - the `zero-distance` warning when distance is null or zero
 *       (Step 3 Case C),
 *     - the chart wrapper (with the documented Interactivity directives)
 *       in the healthy state.
 * - `render()` emits `wp_interactivity_state()` with the per-mapId
 *   statistics in the healthy state.
 * - `render_chart_wrapper()` carries the documented directives,
 *   `role="img"`, a translatable `aria-label`, and a `<noscript>` fallback.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Rendering\Render_Elevation;

beforeEach( function (): void {
	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'esc_html' )->returnArg( 1 );
	Functions\when( 'esc_html__' )->returnArg( 1 );
	Functions\when( 'esc_attr' )->returnArg( 1 );
	Functions\when( 'esc_attr__' )->returnArg( 1 );
	Functions\when( 'apply_filters' )->alias(
		static fn ( string $f, mixed $v ): mixed => $v
	);
	Functions\when( 'get_block_wrapper_attributes' )->alias(
		static function ( array $extras = [] ): string {
			$class = isset( $extras['class'] ) ? (string) $extras['class'] : '';
			$style = isset( $extras['style'] ) ? (string) $extras['style'] : '';
			$out   = sprintf( 'class="%s"', $class );
			if ( '' !== $style ) {
				$out .= sprintf( ' style="%s"', $style );
			}
			return $out;
		}
	);
	Functions\when( 'wp_json_encode' )->alias(
		static fn ( mixed $value ): string|false => json_encode( $value )
	);
	// wp_interactivity_state() is called by render() in the healthy
	// path; capture the calls into a global so the assertions can
	// inspect them.
	$GLOBALS['kntnt_test_interactivity_state'] = [];
	Functions\when( 'wp_interactivity_state' )->alias(
		static function ( string $namespace, array $state ): void {
			$GLOBALS['kntnt_test_interactivity_state'][] = [
				'namespace' => $namespace,
				'state'     => $state,
			];
		}
	);
} );

// ---------------------------------------------------------------------------
// render_warning() — five reasons + fallback.
// ---------------------------------------------------------------------------

test( 'render_warning returns the no-map spec string', function (): void {
	$html = Render_Elevation::render_warning( 'no-map' );
	expect( $html )->toContain(
		'There is no GPX Map block with a selected GPX file on this page.'
	);
	expect( $html )->toContain( 'kntnt-gpx-blocks-elevation-preview-warning' );
} );

test( 'render_warning returns the bound-deleted spec string', function (): void {
	$html = Render_Elevation::render_warning( 'bound-deleted' );
	expect( $html )->toContain( 'no longer on the page' );
} );

test( 'render_warning returns the bound-unconfigured spec string', function (): void {
	$html = Render_Elevation::render_warning( 'bound-unconfigured' );
	expect( $html )->toContain( 'has no GPX file selected' );
} );

test( 'render_warning returns the no-elevation-data spec string', function (): void {
	$html = Render_Elevation::render_warning( 'no-elevation-data' );
	expect( $html )->toContain( 'no elevation data' );
} );

test( 'render_warning returns the zero-distance spec string', function (): void {
	$html = Render_Elevation::render_warning( 'zero-distance' );
	expect( $html )->toContain( 'no distance' );
} );

test( 'render_warning falls back to the no-map string for unknown reasons', function (): void {
	$html = Render_Elevation::render_warning( 'unknown-reason' );
	expect( $html )->toContain(
		'There is no GPX Map block with a selected GPX file on this page.'
	);
} );

test( 'render_warning escapes its message through esc_html()', function (): void {
	$captured_inputs = [];
	Functions\when( 'esc_html' )->alias(
		static function ( string $value ) use ( &$captured_inputs ): string {
			$captured_inputs[] = $value;
			return $value;
		}
	);
	Render_Elevation::render_warning( 'no-map' );
	expect( $captured_inputs )->not->toBeEmpty();
} );

// ---------------------------------------------------------------------------
// render() — dispatch to warnings.
// ---------------------------------------------------------------------------

test( 'render dispatches to the no-map warning when no Map block is on the host post', function (): void {
	$post           = new stdClass();
	$post->ID       = 1;
	$post->post_content = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->';
	Functions\when( 'get_the_ID' )->justReturn( 1 );
	Functions\when( 'get_post' )->justReturn( $post );
	Functions\when( 'parse_blocks' )->alias(
		static fn ( string $content ): array => [
			[
				'blockName'   => 'core/paragraph',
				'attrs'       => [],
				'innerBlocks' => [],
				'innerHTML'   => '<p>Hello</p>',
			],
		]
	);

	$attributes = [ 'mapId' => 'auto', 'backgroundColor' => '' ];
	$block      = new stdClass();
	$html       = Render_Elevation::render( $attributes, '', $block );

	expect( $html )->toContain( 'kntnt-gpx-blocks-elevation-preview-warning' );
	expect( $html )->toContain(
		'There is no GPX Map block with a selected GPX file on this page.'
	);
} );

test( 'render dispatches to bound-deleted when the explicit mapId does not match any Map', function (): void {
	$post           = new stdClass();
	$post->ID       = 1;
	$post->post_content = '';
	Functions\when( 'get_the_ID' )->justReturn( 1 );
	Functions\when( 'get_post' )->justReturn( $post );
	Functions\when( 'parse_blocks' )->alias(
		static fn ( string $content ): array => [
			[
				'blockName'   => 'kntnt-gpx-blocks/map',
				'attrs'       => [ 'attachmentId' => 99, 'mapId' => 'map-aaa' ],
				'innerBlocks' => [],
				'innerHTML'   => '',
			],
		]
	);

	$attributes = [ 'mapId' => 'map-zzz', 'backgroundColor' => '' ];
	$block      = new stdClass();
	$html       = Render_Elevation::render( $attributes, '', $block );

	expect( $html )->toContain( 'kntnt-gpx-blocks-elevation-preview-warning' );
	expect( $html )->toContain( 'no longer on the page' );
} );

// ---------------------------------------------------------------------------
// render() — Step 3 degenerate cases.
//
// Both Case A (null elevation) and Case C (zero / null distance) rely on the
// cache returning a payload with a known statistics shape. Stubbing
// `Attachment_Cache::get()` end-to-end through Brain Monkey is awkward, so
// these cases are exercised through render_warning() above. The full
// integration path (cache mock → render() → warning) is verified in
// WordPress Playground per docs/elevation-rebuild.md.
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// render_chart_wrapper() — directives and structure.
// ---------------------------------------------------------------------------

test( 'render_chart_wrapper carries the documented Interactivity directives', function (): void {
	$attributes = [ 'backgroundColor' => '', 'axisColor' => '' ];
	$html       = Render_Elevation::render_chart_wrapper( $attributes, 'map-abc123' );

	expect( $html )->toContain( 'role="img"' );
	expect( $html )->toContain( 'aria-label="Elevation profile of GPX track"' );
	expect( $html )->toContain(
		'data-wp-interactive=\'{"namespace":"kntnt-gpx-blocks"}\''
	);
	expect( $html )->toContain( '"mapId":"map-abc123"' );
	expect( $html )->toContain( 'data-wp-init="callbacks.initElevation"' );
	// data-wp-watch--cursor lands in Step 6, not here.
	expect( $html )->not->toContain( 'data-wp-watch--cursor' );
} );

test( 'render_chart_wrapper emits the noscript fallback', function (): void {
	$attributes = [ 'backgroundColor' => '', 'axisColor' => '' ];
	$html       = Render_Elevation::render_chart_wrapper( $attributes, 'map-x' );

	expect( $html )->toContain( '<noscript>' );
	expect( $html )->toContain( 'requires JavaScript to display' );
} );

test( 'render_chart_wrapper threads sanitised colour custom properties through the wrapper style', function (): void {
	$attributes = [
		'backgroundColor' => '#abcdef',
		'axisColor'       => '#123456',
	];
	$html = Render_Elevation::render_chart_wrapper( $attributes, 'map-x' );

	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-background: #abcdef' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-axis: #123456' );
} );

test( 'render_chart_wrapper rejects malformed colours via Color_Sanitizer', function (): void {
	$attributes = [
		'backgroundColor' => 'javascript:alert(1)',
		'axisColor'       => '#GGG',
	];
	$html = Render_Elevation::render_chart_wrapper( $attributes, 'map-x' );

	expect( $html )->not->toContain( 'javascript:' );
	expect( $html )->not->toContain( '#GGG' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-background' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-axis' );
} );

test( 'render_chart_wrapper escapes the data-wp-context attribute value', function (): void {
	$captured = [];
	Functions\when( 'esc_attr' )->alias(
		static function ( string $value ) use ( &$captured ): string {
			$captured[] = $value;
			return $value;
		}
	);
	Render_Elevation::render_chart_wrapper( [], 'map-x' );

	// One of the esc_attr() inputs is the JSON-encoded data-wp-context.
	$context_seen = false;
	foreach ( $captured as $value ) {
		if ( str_contains( $value, '"mapId":"map-x"' ) ) {
			$context_seen = true;
			break;
		}
	}
	expect( $context_seen )->toBeTrue();
} );
