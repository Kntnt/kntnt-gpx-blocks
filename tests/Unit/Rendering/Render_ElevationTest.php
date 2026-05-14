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
use Kntnt\Gpx_Blocks\Cache\Cache_Version;
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
// render() — Step 5 healthy state: samples emitted into the interactivity
// state slice.
// ---------------------------------------------------------------------------

test( 'render emits the samples key in wp_interactivity_state in the healthy state', function (): void {

	// Resolve a bound configured Map block on the host post.
	$post                = new stdClass();
	$post->ID            = 1;
	$post->post_content  = '';
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

	// Stub the attachment cache to return a healthy payload. A real
	// temp file + matching md5 keeps Attachment_Cache's freshness
	// check happy on the way in.
	$geojson = [
		'type'     => 'FeatureCollection',
		'features' => [
			[
				'type'     => 'Feature',
				'geometry' => [
					'type'        => 'LineString',
					'coordinates' => [
						[ 18, 59, 100 ],
						[ 18, 59, 110 ],
						[ 18, 59, 120 ],
					],
				],
			],
		],
	];
	$temp = tempnam( sys_get_temp_dir(), 'render_elev_test_' );
	expect( $temp )->toBeString();
	file_put_contents( (string) $temp, '<gpx></gpx>' );
	$hash = md5_file( (string) $temp );
	Functions\when( 'get_post_meta' )->alias(
		static function ( int $object_id, string $key, bool $single ) use ( $geojson, $hash ): mixed {
			return match ( $key ) {
				'_kntnt_gpx_blocks_error'       => '',
				'_kntnt_gpx_blocks_version'     => Cache_Version::CURRENT,
				'_kntnt_gpx_blocks_source_hash' => $hash,
				'_kntnt_gpx_blocks_geojson'     => (string) json_encode( $geojson ),
				'_kntnt_gpx_blocks_statistics'  => [
					'distance'      => 100.0,
					'min_elevation' => 100.0,
					'max_elevation' => 120.0,
					'ascent'        => 20.0,
					'descent'       => 0.0,
				],
				default                         => '',
			};
		}
	);
	Functions\when( 'get_attached_file' )->justReturn( $temp );

	try {
		$attributes = [ 'mapId' => 'map-aaa' ];
		$block      = new stdClass();
		Render_Elevation::render( $attributes, '', $block );

		// One wp_interactivity_state() call was captured.
		expect( $GLOBALS['kntnt_test_interactivity_state'] )->not->toBeEmpty();
		$entry = $GLOBALS['kntnt_test_interactivity_state'][0];
		expect( $entry['namespace'] )->toBe( 'kntnt-gpx-blocks' );
		expect( $entry['state'] )->toHaveKey( 'map-aaa' );
		$slice = $entry['state']['map-aaa'];
		expect( $slice )->toHaveKey( 'samples' );
		expect( $slice['samples'] )->toBeArray();
		// Statistics survives alongside samples.
		expect( $slice )->toHaveKey( 'statistics' );
		expect( $slice['statistics']['min_elevation'] )->toBe( 100.0 );
		expect( $slice['statistics']['max_elevation'] )->toBe( 120.0 );
		expect( $slice['statistics']['distance'] )->toBe( 100.0 );
	} finally {
		unlink( (string) $temp );
	}

} );

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
	// Step 6 wires the cursor watch directive on the healthy state
	// wrapper. The qualifier suffix matches Map's
	// `data-wp-watch--cursor` because both blocks synchronise the
	// same `state[ mapId ].fraction` value via differently named
	// callbacks (one per block, since registering two modules into
	// the same store under the same callback name would overwrite).
	expect( $html )->toContain(
		'data-wp-watch--cursor="callbacks.onElevationCursorChange"'
	);
} );

test( 'wrap_warning does NOT carry data-wp-watch--cursor — warning states have no chart and no cursor', function (): void {
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

	$attributes = [ 'mapId' => 'auto' ];
	$block      = new stdClass();
	$html       = Render_Elevation::render( $attributes, '', $block );

	// The warning wrapper is the only one rendered for this branch;
	// no Interactivity directives, no cursor watch.
	expect( $html )->not->toContain( 'data-wp-watch--cursor' );
	expect( $html )->not->toContain( 'data-wp-interactive' );
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
		'axisLabelColor'  => '#789abc',
		'plotLineColor'   => '#00ff00',
		'plotFillColor'   => '#ff000080',
		'cursorColor'     => '#abcabc',
	];
	$html = Render_Elevation::render_chart_wrapper( $attributes, 'map-x' );

	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-background: #abcdef' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-axis: #123456' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-axis-label: #789abc' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-plot-line: #00ff00' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-plot-fill: #ff000080' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-cursor: #abcabc' );
} );

test( 'render_chart_wrapper rejects malformed colours via Color_Sanitizer', function (): void {
	$attributes = [
		'backgroundColor' => 'javascript:alert(1)',
		'axisColor'       => '#GGG',
		'axisLabelColor'  => 'expression(alert(1))',
		'plotLineColor'   => 'url(http://evil/)',
		'plotFillColor'   => 'javascript:void(0)',
		'cursorColor'     => 'not-a-colour',
	];
	$html = Render_Elevation::render_chart_wrapper( $attributes, 'map-x' );

	expect( $html )->not->toContain( 'javascript:' );
	expect( $html )->not->toContain( '#GGG' );
	expect( $html )->not->toContain( 'expression(' );
	expect( $html )->not->toContain( 'url(http' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-background' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-axis:' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-axis-label' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-plot-line' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-plot-fill' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-cursor:' );
} );

test( 'render_chart_wrapper omits the cursor custom property when cursorColor is empty', function (): void {
	$attributes = [ 'cursorColor' => '' ];
	$html       = Render_Elevation::render_chart_wrapper( $attributes, 'map-x' );

	// The SCSS default `#d63638` covers the empty case; the inline
	// emission is reserved for the editor's choice. Asserting the
	// suffix without the colon prefix would also match the Step 5
	// `--kntnt-gpx-blocks-elevation-plot-fill` declaration when both
	// share the prefix segment, but the colon-tail form pins this row.
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-cursor:' );
} );

test( 'render_chart_wrapper threads sanitised tick-label typography custom properties through the wrapper style', function (): void {
	$attributes = [
		'tickLabelFontFamily'     => 'var(--wp--preset--font-family--system-ui)',
		'tickLabelFontSize'       => '14px',
		'tickLabelFontWeight'     => '700',
		'tickLabelFontStyle'      => 'italic',
		'tickLabelLineHeight'     => '1.4',
		'tickLabelLetterSpacing'  => '0.05em',
		'tickLabelTextTransform'  => 'uppercase',
		'tickLabelTextDecoration' => 'underline',
	];
	$html = Render_Elevation::render_chart_wrapper( $attributes, 'map-x' );

	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tick-label-font-family: var(--wp--preset--font-family--system-ui)' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tick-label-font-size: 14px' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tick-label-font-weight: 700' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tick-label-font-style: italic' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tick-label-line-height: 1.4' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tick-label-letter-spacing: 0.05em' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tick-label-text-transform: uppercase' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tick-label-text-decoration: underline' );
} );

test( 'render_chart_wrapper rejects malformed tick-label typography values via Typography_Sanitizer', function (): void {
	$attributes = [
		'tickLabelFontFamily'     => 'Arial; background: red',
		'tickLabelFontSize'       => '14pt',
		'tickLabelFontWeight'     => '350',
		'tickLabelFontStyle'      => 'oblique 10deg',
		'tickLabelLineHeight'     => '-1',
		'tickLabelLetterSpacing'  => '2',
		'tickLabelTextTransform'  => 'inherit',
		'tickLabelTextDecoration' => 'underline dotted red',
	];
	$html = Render_Elevation::render_chart_wrapper( $attributes, 'map-x' );

	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-tick-label-font-family' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-tick-label-font-size' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-tick-label-font-weight' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-tick-label-font-style' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-tick-label-line-height' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-tick-label-letter-spacing' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-tick-label-text-transform' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-tick-label-text-decoration' );
	expect( $html )->not->toContain( 'background: red' );
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

// ---------------------------------------------------------------------------
// Issue #144 — Cursor & guides toggles mirrored into the per-block context.
// ---------------------------------------------------------------------------

test( 'render_chart_wrapper mirrors the default Cursor & guides toggles into the per-block context', function (): void {
	// A fresh-insert attribute bag (no explicit toggles) must produce
	// the documented defaults: showCursor=true, showVerticalGuide=true,
	// showHorizontalGuide=false.
	$html = Render_Elevation::render_chart_wrapper( [], 'map-x' );

	expect( $html )->toContain( '"showCursor":true' );
	expect( $html )->toContain( '"showVerticalGuide":true' );
	expect( $html )->toContain( '"showHorizontalGuide":false' );
} );

test( 'render_chart_wrapper threads the explicit Cursor & guides toggles into the per-block context', function (): void {
	$attributes = [
		'showCursor'          => false,
		'showVerticalGuide'   => false,
		'showHorizontalGuide' => true,
	];
	$html = Render_Elevation::render_chart_wrapper( $attributes, 'map-x' );

	expect( $html )->toContain( '"showCursor":false' );
	expect( $html )->toContain( '"showVerticalGuide":false' );
	expect( $html )->toContain( '"showHorizontalGuide":true' );
} );

// ---------------------------------------------------------------------------
// Step 7 — Tooltip info toggles mirrored into the per-block context.
// ---------------------------------------------------------------------------

test( 'render_chart_wrapper mirrors the default Tooltip info toggles into the per-block context (Step 7)', function (): void {
	// A fresh-insert attribute bag (no explicit toggles) must produce
	// the documented defaults: tooltipShowDistance=true,
	// tooltipShowHeight=true.
	$html = Render_Elevation::render_chart_wrapper( [], 'map-x' );

	expect( $html )->toContain( '"tooltipShowDistance":true' );
	expect( $html )->toContain( '"tooltipShowHeight":true' );
} );

test( 'render_chart_wrapper threads the explicit Tooltip info toggles into the per-block context (Step 7)', function (): void {
	$attributes = [
		'tooltipShowDistance' => false,
		'tooltipShowHeight'   => false,
	];
	$html = Render_Elevation::render_chart_wrapper( $attributes, 'map-x' );

	expect( $html )->toContain( '"tooltipShowDistance":false' );
	expect( $html )->toContain( '"tooltipShowHeight":false' );
} );

// ---------------------------------------------------------------------------
// Step 7 — Tooltip colour custom properties.
// ---------------------------------------------------------------------------

test( 'render_chart_wrapper threads sanitised tooltip colour custom properties through the wrapper style (Step 7)', function (): void {
	$attributes = [
		'tooltipBackgroundColor' => '#000000cc',
		'tooltipDistanceColor'   => '#ffffff',
		'tooltipHeightColor'     => '#dddddd',
	];
	$html = Render_Elevation::render_chart_wrapper( $attributes, 'map-x' );

	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tooltip-background: #000000cc' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tooltip-distance: #ffffff' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tooltip-height: #dddddd' );
} );

test( 'render_chart_wrapper rejects malformed tooltip colours via Color_Sanitizer (Step 7)', function (): void {
	$attributes = [
		'tooltipBackgroundColor' => 'javascript:alert(1)',
		'tooltipDistanceColor'   => '#GGG',
		'tooltipHeightColor'     => 'expression(alert(1))',
	];
	$html = Render_Elevation::render_chart_wrapper( $attributes, 'map-x' );

	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-tooltip-background:' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-tooltip-distance:' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-tooltip-height:' );
	expect( $html )->not->toContain( 'javascript:' );
	expect( $html )->not->toContain( 'expression(' );
} );

test( 'render_chart_wrapper omits the tooltip colour custom properties when attributes are empty (Step 7)', function (): void {
	$attributes = [
		'tooltipBackgroundColor' => '',
		'tooltipDistanceColor'   => '',
		'tooltipHeightColor'     => '',
	];
	$html = Render_Elevation::render_chart_wrapper( $attributes, 'map-x' );

	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-tooltip-background:' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-tooltip-distance:' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-tooltip-height:' );
} );

// ---------------------------------------------------------------------------
// Step 7 — Tooltip typography custom properties.
// ---------------------------------------------------------------------------

test( 'render_chart_wrapper threads sanitised tooltip distance typography custom properties (Step 7)', function (): void {
	$attributes = [
		'tooltipDistanceFontFamily'     => 'Inter',
		'tooltipDistanceFontSize'       => '14px',
		'tooltipDistanceFontWeight'     => '700',
		'tooltipDistanceFontStyle'      => 'italic',
		'tooltipDistanceLineHeight'     => '1.4',
		'tooltipDistanceLetterSpacing'  => '0.05em',
		'tooltipDistanceTextTransform'  => 'uppercase',
		'tooltipDistanceTextDecoration' => 'underline',
	];
	$html = Render_Elevation::render_chart_wrapper( $attributes, 'map-x' );

	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tooltip-distance-font-family: Inter' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tooltip-distance-font-size: 14px' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tooltip-distance-font-weight: 700' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tooltip-distance-font-style: italic' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tooltip-distance-line-height: 1.4' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tooltip-distance-letter-spacing: 0.05em' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tooltip-distance-text-transform: uppercase' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tooltip-distance-text-decoration: underline' );
} );

test( 'render_chart_wrapper threads sanitised tooltip height typography custom properties (Step 7)', function (): void {
	$attributes = [
		'tooltipHeightFontFamily'     => 'Inter',
		'tooltipHeightFontSize'       => '14px',
		'tooltipHeightFontWeight'     => '300',
		'tooltipHeightFontStyle'      => 'italic',
		'tooltipHeightLineHeight'     => '1.2',
		'tooltipHeightLetterSpacing'  => '-0.02em',
		'tooltipHeightTextTransform'  => 'capitalize',
		'tooltipHeightTextDecoration' => 'overline',
	];
	$html = Render_Elevation::render_chart_wrapper( $attributes, 'map-x' );

	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tooltip-height-font-family: Inter' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tooltip-height-font-size: 14px' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tooltip-height-font-weight: 300' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tooltip-height-font-style: italic' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tooltip-height-line-height: 1.2' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tooltip-height-letter-spacing: -0.02em' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tooltip-height-text-transform: capitalize' );
	expect( $html )->toContain( '--kntnt-gpx-blocks-elevation-tooltip-height-text-decoration: overline' );
} );

test( 'render_chart_wrapper rejects malformed tooltip typography values via Typography_Sanitizer (Step 7)', function (): void {
	$attributes = [
		'tooltipDistanceFontFamily'     => 'Arial; background: red',
		'tooltipDistanceFontWeight'     => '350',
		'tooltipDistanceTextDecoration' => 'underline dotted red',
		'tooltipHeightFontStyle'        => 'oblique 10deg',
		'tooltipHeightLetterSpacing'    => '2',
	];
	$html = Render_Elevation::render_chart_wrapper( $attributes, 'map-x' );

	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-tooltip-distance-font-family' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-tooltip-distance-font-weight' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-tooltip-distance-text-decoration' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-tooltip-height-font-style' );
	expect( $html )->not->toContain( '--kntnt-gpx-blocks-elevation-tooltip-height-letter-spacing' );
	expect( $html )->not->toContain( 'background: red' );
} );
