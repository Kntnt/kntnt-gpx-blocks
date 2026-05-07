<?php
/**
 * Accessibility tests for Render_Map and Render_Elevation.
 *
 * Verifies that the rendered HTML carries the ARIA attributes and <noscript>
 * fallback text required by issue #22. Brain Monkey stubs all WordPress
 * functions so the classes run without a live WordPress install.
 *
 * Coverage:
 * - Render_Map output contains role="application" and an aria-label string.
 * - Render_Map output contains a <noscript> with the translated fallback text.
 * - Render_Elevation output contains role="img" on the SVG element.
 * - Render_Elevation SVG has aria-labelledby pointing to the <desc> element's id.
 * - Render_Elevation output's <noscript> contains the elevation summary text.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Cache\Cache_Version;
use Kntnt\Gpx_Blocks\Rendering\Render_Elevation;
use Kntnt\Gpx_Blocks\Rendering\Render_Map;

// ---------------------------------------------------------------------------
// Helpers — shared across both render paths
// ---------------------------------------------------------------------------

/**
 * Builds a fake WP_Block object with no $context (Render_Map never reads it).
 *
 * @return object
 */
function a11y_map_fake_block(): object {
	return new class() {

		/**
		 * Block context values (empty — Render_Map does not read context).
		 *
		 * @var array<string, mixed>
		 */
		public array $context = [];
	};
}

/**
 * Builds a fake WP_Block-like object exposing $context['postId'].
 *
 * @param int $post_id The post ID to expose.
 *
 * @return object
 */
function a11y_elev_fake_block( int $post_id ): object {
	return new class( $post_id ) {

		/**
		 * Block context values, keyed by context name.
		 *
		 * @var array<string, mixed>
		 */
		public array $context;

		/**
		 * Initialises the context with the supplied post ID.
		 *
		 * @param int $post_id The post ID to expose via context['postId'].
		 */
		public function __construct( int $post_id ) {
			$this->context = [ 'postId' => $post_id ];
		}
	};
}

/**
 * Wires Brain Monkey meta stubs against an in-memory store.
 *
 * @param array<int, array<string, mixed>> $store Meta store keyed by attachment ID.
 */
function a11y_bind_meta( array &$store ): void {

	Functions\when( 'get_post_meta' )->alias(
		static function ( int $id, string $key, bool $single ) use ( &$store ): mixed {
			if ( ! $single ) {
				return [];
			}
			return $store[ $id ][ $key ] ?? '';
		}
	);

	Functions\when( 'update_post_meta' )->alias(
		static function ( int $id, string $key, mixed $value ) use ( &$store ): bool {
			$store[ $id ][ $key ] = $value;
			return true;
		}
	);

	Functions\when( 'delete_post_meta' )->alias(
		static function ( int $id, string $key ) use ( &$store ): bool {
			unset( $store[ $id ][ $key ] );
			return true;
		}
	);

}

/**
 * Returns the absolute path to a GPX fixture file.
 *
 * @param string $name Filename inside tests/Unit/fixtures/gpx/.
 *
 * @return string
 */
function a11y_fixture_path( string $name ): string {
	return __DIR__ . '/fixtures/gpx/' . $name;
}

/**
 * Builds an in-memory meta store pre-seeded with a current-version cache entry.
 *
 * @param int                           $attachment_id Attachment ID.
 * @param array<int, array<int, float>> $coordinates   GeoJSON [lon,lat,ele?] array.
 * @param array<string, float|null>     $statistics    Statistics array.
 * @param string                        $fixture       Fixture filename.
 *
 * @return array<int, array<string, mixed>>
 */
function a11y_seeded_store(
	int $attachment_id,
	array $coordinates,
	array $statistics,
	string $fixture = 'happy-path.gpx',
): array {

	$path = a11y_fixture_path( $fixture );
	$hash = md5_file( $path );

	$geojson = [
		'type'     => 'FeatureCollection',
		'features' => [
			[
				'type'       => 'Feature',
				'properties' => (object) [],
				'geometry'   => [
					'type'        => 'LineString',
					'coordinates' => $coordinates,
				],
			],
		],
	];

	return [
		$attachment_id => [
			'_kntnt_gpx_blocks_geojson'     => json_encode( $geojson ),
			'_kntnt_gpx_blocks_statistics'  => $statistics,
			'_kntnt_gpx_blocks_version'     => Cache_Version::CURRENT,
			'_kntnt_gpx_blocks_source_hash' => $hash,
		],
	];

}

/**
 * Builds a minimal parsed-block array for a GPX Map block.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $map_id        mapId attribute.
 *
 * @return array<string, mixed>
 */
function a11y_map_block( int $attachment_id, string $map_id = 'map-a11y' ): array {
	return [
		'blockName'   => 'kntnt-gpx-blocks/map',
		'attrs'       => [
			'attachmentId' => $attachment_id,
			'mapId'        => $map_id,
		],
		'innerBlocks' => [],
	];
}

/**
 * Builds a synthetic 2D coordinate list near Stockholm.
 *
 * @param int $count Number of points.
 *
 * @return array<int, array<int, float>>
 */
function a11y_coords_2d( int $count ): array {
	$out = [];
	for ( $i = 0; $i < $count; $i++ ) {
		$r     = $count > 1 ? $i / ( $count - 1 ) : 0.0;
		$out[] = [ 18.0 + 0.05 * $r, 59.0 + 0.05 * $r ];
	}
	return $out;
}

/**
 * Builds a synthetic 3D coordinate list (with elevation rising linearly).
 *
 * @param int $count Number of points.
 *
 * @return array<int, array<int, float>>
 */
function a11y_coords_3d( int $count ): array {
	$out = [];
	for ( $i = 0; $i < $count; $i++ ) {
		$r     = $count > 1 ? $i / ( $count - 1 ) : 0.0;
		$out[] = [ 18.0 + 0.05 * $r, 59.0 + 0.05 * $r, 100.0 + 100.0 * $r ];
	}
	return $out;
}

// ---------------------------------------------------------------------------
// Default test setup
// ---------------------------------------------------------------------------

beforeEach( function (): void {

	Functions\when( 'apply_filters' )->alias(
		static function ( string $filter, mixed $fallback ): mixed {
			return $fallback;
		}
	);

	Functions\when( 'esc_html' )->returnArg( 1 );
	Functions\when( 'esc_attr' )->returnArg( 1 );
	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'esc_html__' )->alias(
		static fn ( string $text, string $domain ): string => $text
	);
	Functions\when( 'esc_attr__' )->alias(
		static fn ( string $text, string $domain ): string => $text
	);
	Functions\when( 'wp_json_encode' )->alias(
		static fn ( mixed $v ): string|false => json_encode( $v )
	);
	Functions\when( 'current_user_can' )->justReturn( false );
	Functions\when( 'is_admin' )->justReturn( false );
	Functions\when( 'number_format_i18n' )->alias(
		static fn ( float|int $n, int $d = 0 ): string => number_format( (float) $n, $d, '.', '' )
	);
	Functions\when( 'wp_interactivity_state' )->justReturn( null );

} );

// ---------------------------------------------------------------------------
// Render_Map: role="application" and aria-label
// ---------------------------------------------------------------------------

test( 'Render_Map output contains role="application" on the wrapper div', function (): void {

	$coords = a11y_coords_2d( 10 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];
	$store  = a11y_seeded_store( 101, $coords, $stats );
	a11y_bind_meta( $store );

	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => $id === 101 ? a11y_fixture_path( 'happy-path.gpx' ) : false
	);
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );
	Functions\when( 'sanitize_hex_color' )->justReturn( null );

	$html = Render_Map::render(
		[
			'attachmentId' => 101,
			'mapId'        => 'map-a11y-role',
		],
		'',
		a11y_map_fake_block(),
	);

	expect( $html )->toContain( 'role="application"' );

} );

test( 'Render_Map output contains an aria-label attribute on the wrapper div', function (): void {

	$coords = a11y_coords_2d( 10 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];
	$store  = a11y_seeded_store( 102, $coords, $stats );
	a11y_bind_meta( $store );

	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => $id === 102 ? a11y_fixture_path( 'happy-path.gpx' ) : false
	);
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );
	Functions\when( 'sanitize_hex_color' )->justReturn( null );

	$html = Render_Map::render(
		[
			'attachmentId' => 102,
			'mapId'        => 'map-a11y-label',
		],
		'',
		a11y_map_fake_block(),
	);

	expect( $html )->toContain( 'aria-label=' );

} );

// ---------------------------------------------------------------------------
// Render_Map: <noscript> fallback
// ---------------------------------------------------------------------------

test( 'Render_Map output contains a <noscript> element with fallback text', function (): void {

	$coords = a11y_coords_2d( 10 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];
	$store  = a11y_seeded_store( 103, $coords, $stats );
	a11y_bind_meta( $store );

	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => $id === 103 ? a11y_fixture_path( 'happy-path.gpx' ) : false
	);
	Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/track.gpx' );
	Functions\when( 'sanitize_hex_color' )->justReturn( null );

	$html = Render_Map::render(
		[
			'attachmentId' => 103,
			'mapId'        => 'map-a11y-noscript',
		],
		'',
		a11y_map_fake_block(),
	);

	expect( $html )
		->toContain( '<noscript>' )
		->toContain( 'kntnt-gpx-blocks-map-noscript' )
		->toContain( 'This map requires JavaScript to display.' );

} );

// ---------------------------------------------------------------------------
// Render_Elevation: role="img" and aria-labelledby
// ---------------------------------------------------------------------------

test( 'Render_Elevation SVG contains role="img"', function (): void {

	$coords = a11y_coords_3d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];
	$store  = a11y_seeded_store( 201, $coords, $stats );
	a11y_bind_meta( $store );

	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => $id === 201 ? a11y_fixture_path( 'happy-path.gpx' ) : false
	);
	Functions\when( 'get_the_ID' )->justReturn( 50 );
	Functions\when( 'get_post' )->alias(
		static function ( int $id ): ?object {
			$p               = new stdClass();
			$p->ID           = $id;
			$p->post_content = '';
			return $p;
		}
	);
	Functions\when( 'parse_blocks' )->justReturn( [ a11y_map_block( 201, 'map-elev-role' ) ] );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', a11y_elev_fake_block( 50 ) );

	expect( $html )->toContain( 'role="img"' );

} );

test( 'Render_Elevation SVG has aria-labelledby pointing to a desc element id', function (): void {

	$coords = a11y_coords_3d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];
	$store  = a11y_seeded_store( 202, $coords, $stats );
	a11y_bind_meta( $store );

	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => $id === 202 ? a11y_fixture_path( 'happy-path.gpx' ) : false
	);
	Functions\when( 'get_the_ID' )->justReturn( 51 );
	Functions\when( 'get_post' )->alias(
		static function ( int $id ): ?object {
			$p               = new stdClass();
			$p->ID           = $id;
			$p->post_content = '';
			return $p;
		}
	);
	Functions\when( 'parse_blocks' )->justReturn( [ a11y_map_block( 202, 'map-elev-labelledby' ) ] );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', a11y_elev_fake_block( 51 ) );

	// The SVG must have aria-labelledby.
	expect( $html )->toContain( 'aria-labelledby=' );

	// The referenced id must appear on the <desc> element.
	expect( $html )->toMatch( '/aria-labelledby="([^"]+)"/' );
	preg_match( '/aria-labelledby="([^"]+)"/', $html, $m );
	$referenced_id = $m[1] ?? '';

	expect( $html )->toContain( sprintf( '<desc id="%s">', $referenced_id ) );

} );

// ---------------------------------------------------------------------------
// Render_Elevation: <noscript> fallback with summary text
// ---------------------------------------------------------------------------

test( 'Render_Elevation output contains a <noscript> element with elevation summary', function (): void {

	$coords = a11y_coords_3d( 200 );
	$stats  = [
		'distance'      => 5500.0,
		'min_elevation' => 100.0,
		'max_elevation' => 200.0,
		'ascent'        => 100.0,
		'descent'       => 0.0,
	];
	$store  = a11y_seeded_store( 203, $coords, $stats );
	a11y_bind_meta( $store );

	Functions\when( 'get_attached_file' )->alias(
		static fn ( int $id ): string|false => $id === 203 ? a11y_fixture_path( 'happy-path.gpx' ) : false
	);
	Functions\when( 'get_the_ID' )->justReturn( 52 );
	Functions\when( 'get_post' )->alias(
		static function ( int $id ): ?object {
			$p               = new stdClass();
			$p->ID           = $id;
			$p->post_content = '';
			return $p;
		}
	);
	Functions\when( 'parse_blocks' )->justReturn( [ a11y_map_block( 203, 'map-elev-noscript' ) ] );

	$html = Render_Elevation::render( [ 'mapId' => 'auto' ], '', a11y_elev_fake_block( 52 ) );

	expect( $html )
		->toContain( '<noscript>' )
		->toContain( 'kntnt-gpx-blocks-elevation-noscript' )
		->toContain( 'Elevation profile from' );

} );
