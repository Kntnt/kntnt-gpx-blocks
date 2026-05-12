<?php
/**
 * Accessibility tests for Render_Map.
 *
 * Verifies that the rendered HTML carries the ARIA attributes and <noscript>
 * fallback text required by issue #22. Brain Monkey stubs all WordPress
 * functions so the class runs without a live WordPress install.
 *
 * Coverage:
 * - Render_Map output contains role="application" and an aria-label string.
 * - Render_Map output contains a <noscript> with the translated fallback text.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Cache\Cache_Version;
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

	// Minimal get_block_wrapper_attributes stub: emits the class+style passed
	// in plus the namespaced wp-block class, mirroring core's wrapper output.
	// The accessibility tests do not exercise alignment, anchor, or
	// className, so the stub does not need to read any global attribute
	// context — those branches are covered by Render_MapTest.
	Functions\when( 'get_block_wrapper_attributes' )->alias(
		static function ( array $extras = [] ): string {
			$class_parts = [];
			if ( isset( $extras['class'] ) && '' !== $extras['class'] ) {
				$class_parts[] = $extras['class'];
			}
			$out = '';
			if ( count( $class_parts ) > 0 ) {
				$out .= sprintf( 'class="%s"', implode( ' ', $class_parts ) );
			}
			if ( isset( $extras['style'] ) && '' !== $extras['style'] ) {
				$out .= ( '' !== $out ? ' ' : '' ) . sprintf( 'style="%s"', $extras['style'] );
			}
			return $out;
		}
	);

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
