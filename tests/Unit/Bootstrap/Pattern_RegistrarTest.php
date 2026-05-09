<?php
/**
 * Tests for Bootstrap\Pattern_Registrar.
 *
 * Covers the registrar's two responsibilities: registering the custom `kntnt`
 * pattern category, and registering the bundled pattern file with title,
 * description, and keywords routed through `__()`. Also covers the defensive
 * guard for a missing pattern file.
 *
 * Brain Monkey expectations are used so tearDown verifies that the WordPress
 * registration functions were called the right number of times with the
 * right arguments.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Bootstrap\Pattern_Registrar;

beforeEach( function (): void {

	// Stubs for the i18n and escape functions invoked at registration time
	// and inside the pattern file's body when included.
	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'esc_html__' )->alias(
		static fn ( string $text, string $domain ): string => $text
	);

	// Faithful in-process emulation of WP core's get_file_data() so the
	// test exercises the real pattern file's headers rather than hard-coded
	// test data. Reads the file's first 8 KB and extracts each requested
	// header via the same regex shape core uses.
	Functions\when( 'get_file_data' )->alias(
		static function ( string $file, array $default_headers ): array {

			$raw = is_file( $file ) ? (string) file_get_contents( $file ) : '';
			if ( '' === $raw ) {
				return array_fill_keys( array_keys( $default_headers ), '' );
			}
			$raw = str_replace( "\r", '', substr( $raw, 0, 8192 ) );

			$result = [];
			foreach ( $default_headers as $field => $name ) {
				if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':(.*)$/mi', $raw, $m ) ) {
					$result[ $field ] = trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $m[1] ) );
				} else {
					$result[ $field ] = '';
				}
			}

			return $result;

		}
	);

} );

// ---------------------------------------------------------------------------
// Happy path: real bundled pattern file
// ---------------------------------------------------------------------------

test( 'registers the kntnt pattern category', function (): void {

	Functions\expect( 'register_block_pattern_category' )
		->once()
		->with(
			'kntnt',
			Mockery::on(
				static fn ( array $args ): bool => isset( $args['label'] ) && 'Kntnt' === $args['label']
			),
		);

	// Allow register_block_pattern to be called without further assertion in
	// this test — coverage of its arguments lives in the next test.
	Functions\when( 'register_block_pattern' )->justReturn( null );

	$registrar = new Pattern_Registrar( __DIR__ . '/../../../patterns' );
	$registrar->register();

} );

test( 'registers the bundled pattern with translated title and description', function (): void {

	Functions\when( 'register_block_pattern_category' )->justReturn( true );

	Functions\expect( 'register_block_pattern' )
		->once()
		->with(
			'kntnt-gpx-blocks/statistics',
			Mockery::on( static function ( array $args ): bool {

				// Title must be the header value, run through __() (returnArg).
				if ( ( $args['title'] ?? '' ) !== 'GPX Statistics' ) {
					return false;
				}

				// Categories must include 'kntnt' from the file header.
				if ( ! is_array( $args['categories'] ?? null ) || ! in_array( 'kntnt', $args['categories'], true ) ) {
					return false;
				}

				// Keywords must be a non-empty list pulled from the file header.
				if ( ! is_array( $args['keywords'] ?? null ) || count( $args['keywords'] ) < 4 ) {
					return false;
				}

				// Viewport width comes from the header as 800.
				if ( ( $args['viewportWidth'] ?? 0 ) !== 800 ) {
					return false;
				}

				// Content must be non-empty and contain a bound paragraph
				// referencing the source slug — proves the include captured
				// the file body.
				$content = (string) ( $args['content'] ?? '' );
				if ( ! str_contains( $content, 'kntnt-gpx-blocks/statistics' ) ) {
					return false;
				}
				if ( ! str_contains( $content, '"key":"distance"' ) ) {
					return false;
				}

				return true;
			} ),
		);

	$registrar = new Pattern_Registrar( __DIR__ . '/../../../patterns' );
	$registrar->register();

} );

test( 'pattern body translates the static labels via esc_html__', function (): void {

	Functions\when( 'register_block_pattern_category' )->justReturn( true );

	$captured = null;
	Functions\when( 'register_block_pattern' )->alias(
		static function ( string $slug, array $args ) use ( &$captured ): void {
			$captured = $args['content'] ?? '';
		}
	);

	$registrar = new Pattern_Registrar( __DIR__ . '/../../../patterns' );
	$registrar->register();

	expect( $captured )->toBeString();
	expect( $captured )
		->toContain( 'Total length' )
		->toContain( 'Lowest elevation' )
		->toContain( 'Highest elevation' )
		->toContain( 'Total ascent' )
		->toContain( 'Total descent' );

} );

// ---------------------------------------------------------------------------
// Defensive guard: missing pattern file
// ---------------------------------------------------------------------------

test( 'logs a warning and skips registration when the pattern file is missing', function (): void {

	// The category still registers — that step has no file dependency.
	Functions\expect( 'register_block_pattern_category' )->once();

	// register_block_pattern must NOT be called when the file is missing.
	Functions\expect( 'register_block_pattern' )->never();

	$registrar = new Pattern_Registrar( '/tmp/kntnt-gpx-blocks-nonexistent-path-' . uniqid() );
	$registrar->register();

} );
