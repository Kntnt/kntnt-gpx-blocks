<?php
/**
 * Tests for Rendering\Render_Elevation.
 *
 * Coverage:
 *
 * - `render_warning()` returns the documented string for each of the
 *   three Step 2 reasons (`no-map`, `bound-deleted`, `bound-unconfigured`),
 *   and falls back to the no-map message for unknown reasons.
 * - `render_warning()` escapes its message through esc_html().
 * - `render_info()` formats the bound label and integer min/max into
 *   the Step 2 spec string.
 * - `render_info()` escapes its message.
 * - `render()` dispatches to the warning placeholder when no Map block
 *   exists on the host post.
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
	Functions\when( 'esc_attr' )->returnArg( 1 );
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
} );

// ---------------------------------------------------------------------------
// render_warning()
// ---------------------------------------------------------------------------

test( 'render_warning returns the no-map spec string', function (): void {
	$html = Render_Elevation::render_warning( 'no-map' );
	expect( $html )->toContain( 'There is no GPX Map block with a selected GPX file on this page.' );
	expect( $html )->toContain( 'kntnt-gpx-blocks-elevation-preview-warning' );
} );

test( 'render_warning returns the bound-deleted spec string', function (): void {
	$html = Render_Elevation::render_warning( 'bound-deleted' );
	expect( $html )->toContain( 'no longer on the page' );
	expect( $html )->toContain( 'kntnt-gpx-blocks-elevation-preview-warning' );
} );

test( 'render_warning returns the bound-unconfigured spec string', function (): void {
	$html = Render_Elevation::render_warning( 'bound-unconfigured' );
	expect( $html )->toContain( 'has no GPX file selected' );
	expect( $html )->toContain( 'kntnt-gpx-blocks-elevation-preview-warning' );
} );

test( 'render_warning falls back to the no-map string for unknown reasons', function (): void {
	$html = Render_Elevation::render_warning( 'unknown-reason' );
	expect( $html )->toContain( 'There is no GPX Map block with a selected GPX file on this page.' );
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
// render_info()
// ---------------------------------------------------------------------------

test( 'render_info formats the bound label and integer min/max', function (): void {
	$html = Render_Elevation::render_info( 'Northern loop', 12, 345 );
	expect( $html )->toContain( 'Bound to Northern loop. Min: 12 m, Max: 345 m.' );
	expect( $html )->toContain( 'kntnt-gpx-blocks-elevation-preview-info' );
} );

test( 'render_info escapes its message through esc_html()', function (): void {
	$captured_inputs = [];
	Functions\when( 'esc_html' )->alias(
		static function ( string $value ) use ( &$captured_inputs ): string {
			$captured_inputs[] = $value;
			return $value;
		}
	);
	Render_Elevation::render_info( 'Loop A', 0, 100 );
	expect( $captured_inputs )->not->toBeEmpty();
} );

// ---------------------------------------------------------------------------
// render() — dispatch
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
	expect( $html )->toContain( 'There is no GPX Map block with a selected GPX file on this page.' );
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
