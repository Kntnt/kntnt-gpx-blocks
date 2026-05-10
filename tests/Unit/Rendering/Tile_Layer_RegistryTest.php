<?php
/**
 * Tests for Rendering\Tile_Layer_Registry.
 *
 * Covers the validator (rejection of malformed records on every documented
 * constraint), the resolver (known id, fallback on unknown id, `{KEY}`
 * substitution), and the filter integration (added/replaced records flow
 * through validation; the canonical fallback is always preserved).
 *
 * Brain Monkey supplies `apply_filters` and `__()` so the registry can run
 * without a live WordPress install. Default-providers helpers exercise the
 * filter mock directly so each test starts from a known input.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Rendering\Tile_Layer_Registry;

// ---------------------------------------------------------------------------
// Default Brain Monkey wiring
// ---------------------------------------------------------------------------

beforeEach( function (): void {

	// __() simply returns the source string so labels survive the registry's
	// translation-aware default builder. The tests never assert on translated
	// content; they assert on validated structure.
	Functions\when( '__' )->returnArg( 1 );

	// Default apply_filters passthrough — tests that need to override a
	// specific filter call alias() again locally with their own logic.
	Functions\when( 'apply_filters' )->alias(
		static function ( string $filter, mixed $value ): mixed {
			return $value;
		}
	);

} );

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Builds a minimal valid base-provider record with optional overrides.
 *
 * The base shape passes every validator constraint. Tests inject one or
 * more `$overrides` to flip a single field at a time so the failure cause
 * is unambiguous.
 *
 * @param array<string, mixed> $overrides Overrides applied on top of the base.
 *
 * @return array<string, mixed>
 */
function tlr_provider_record( array $overrides = [] ): array {
	$base = [
		'label'       => 'Test Provider',
		'url'         => 'https://tiles.example.com/{z}/{x}/{y}.png',
		'attribution' => '&copy; Example',
		'maxZoom'     => 19,
		'requiresKey' => false,
	];
	return array_merge( $base, $overrides );
}

/**
 * Builds a minimal valid overlay record with optional overrides.
 *
 * @param array<string, mixed> $overrides Overrides applied on top of the base.
 *
 * @return array<string, mixed>
 */
function tlr_overlay_record( array $overrides = [] ): array {
	$base = [
		'label'       => 'Test Overlay',
		'url'         => 'https://overlay.example.com/{z}/{x}/{y}.png',
		'attribution' => '&copy; Overlay',
		'maxZoom'     => 18,
	];
	return array_merge( $base, $overrides );
}

/**
 * Stubs apply_filters so the named filter returns the supplied set; every
 * other filter passes through untouched.
 *
 * @param string                                    $filter_name Filter name to override.
 * @param array<int|string, array<string, mixed>>   $set         Replacement set.
 */
function tlr_filter_returns( string $filter_name, array $set ): void {
	Functions\when( 'apply_filters' )->alias(
		static function ( string $name, mixed $value ) use ( $filter_name, $set ): mixed {
			return $name === $filter_name ? $set : $value;
		}
	);
}

// ---------------------------------------------------------------------------
// Defaults — every shipped provider and overlay validates
// ---------------------------------------------------------------------------

test( 'default providers all survive validation', function (): void {

	$registry  = new Tile_Layer_Registry();
	$providers = $registry->get_providers();

	expect( $providers )
		->toHaveKey( 'osm-standard' )
		->toHaveKey( 'opentopomap' )
		->toHaveKey( 'cyclosm' )
		->toHaveKey( 'thunderforest-outdoors' )
		->toHaveKey( 'thunderforest-landscape' )
		->toHaveKey( 'stadia-outdoors' )
		->toHaveKey( 'maptiler-outdoor' )
		->toHaveKey( 'mapbox-outdoors' );

	expect( count( $providers ) )->toBe( 8 );

} );

test( 'default overlays all survive validation', function (): void {

	$registry = new Tile_Layer_Registry();
	$overlays = $registry->get_overlays();

	expect( $overlays )->toHaveKey( 'wmt-hiking' );
	expect( count( $overlays ) )->toBe( 1 );

} );

// ---------------------------------------------------------------------------
// Validator — every documented constraint rejects, and only it
// ---------------------------------------------------------------------------

test( 'validator rejects URL without {z}/{x}/{y} placeholders', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[ 'bad-id' => tlr_provider_record( [ 'url' => 'https://example.com/no-placeholders.png' ] ) ]
	);

	$registry = new Tile_Layer_Registry();
	$providers = $registry->get_providers();

	expect( $providers )->not->toHaveKey( 'bad-id' );

} );

test( 'validator rejects URL whose scheme is http://', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[ 'plain-http' => tlr_provider_record( [ 'url' => 'http://tiles.example.com/{z}/{x}/{y}.png' ] ) ]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( $providers )->not->toHaveKey( 'plain-http' );

} );

test( 'validator rejects URL whose scheme is neither http nor https', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[ 'odd-scheme' => tlr_provider_record( [ 'url' => 'ftp://tiles.example.com/{z}/{x}/{y}.png' ] ) ]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( $providers )->not->toHaveKey( 'odd-scheme' );

} );

test( 'validator rejects requiresKey=true when URL lacks {KEY}', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'needs-key' => tlr_provider_record( [
				'url'         => 'https://tiles.example.com/{z}/{x}/{y}.png',
				'requiresKey' => true,
			] ),
		]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( $providers )->not->toHaveKey( 'needs-key' );

} );

test( 'validator rejects requiresKey=false when URL contains {KEY}', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'has-orphan-key' => tlr_provider_record( [
				'url'         => 'https://tiles.example.com/{z}/{x}/{y}.png?apikey={KEY}',
				'requiresKey' => false,
			] ),
		]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( $providers )->not->toHaveKey( 'has-orphan-key' );

} );

test( 'validator rejects malformed id with uppercase letters', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[ 'BadId' => tlr_provider_record() ]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( $providers )->not->toHaveKey( 'BadId' );

} );

test( 'validator rejects malformed id with underscores', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[ 'bad_id' => tlr_provider_record() ]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( $providers )->not->toHaveKey( 'bad_id' );

} );

test( 'validator rejects empty id', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[ '' => tlr_provider_record() ]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( $providers )->not->toHaveKey( '' );

} );

test( 'validator rejects numeric-key entries', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[ 0 => tlr_provider_record() ]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( $providers )->not->toHaveKey( 0 );

} );

test( 'validator rejects maxZoom below zero', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[ 'low-zoom' => tlr_provider_record( [ 'maxZoom' => -1 ] ) ]
	);

	expect( ( new Tile_Layer_Registry() )->get_providers() )->not->toHaveKey( 'low-zoom' );

} );

test( 'validator rejects maxZoom above 22', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[ 'high-zoom' => tlr_provider_record( [ 'maxZoom' => 23 ] ) ]
	);

	expect( ( new Tile_Layer_Registry() )->get_providers() )->not->toHaveKey( 'high-zoom' );

} );

test( 'validator rejects non-integer maxZoom (float)', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[ 'frac-zoom' => tlr_provider_record( [ 'maxZoom' => 18.5 ] ) ]
	);

	expect( ( new Tile_Layer_Registry() )->get_providers() )->not->toHaveKey( 'frac-zoom' );

} );

test( 'validator rejects empty label', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[ 'no-label' => tlr_provider_record( [ 'label' => '' ] ) ]
	);

	expect( ( new Tile_Layer_Registry() )->get_providers() )->not->toHaveKey( 'no-label' );

} );

test( 'validator rejects empty attribution', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[ 'no-attr' => tlr_provider_record( [ 'attribution' => '' ] ) ]
	);

	expect( ( new Tile_Layer_Registry() )->get_providers() )->not->toHaveKey( 'no-attr' );

} );

test( 'validator rejects non-bool requiresKey', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[ 'bad-bool' => tlr_provider_record( [ 'requiresKey' => 1 ] ) ]
	);

	expect( ( new Tile_Layer_Registry() )->get_providers() )->not->toHaveKey( 'bad-bool' );

} );

test( 'validator rejects non-string subdomains entries', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[ 'bad-subs' => tlr_provider_record( [ 'subdomains' => [ 'a', 1, 'c' ] ] ) ]
	);

	expect( ( new Tile_Layer_Registry() )->get_providers() )->not->toHaveKey( 'bad-subs' );

} );

test( 'validator rejects non-https signupUrl', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'bad-signup' => tlr_provider_record( [
				'url'         => 'https://tiles.example.com/{z}/{x}/{y}.png?key={KEY}',
				'requiresKey' => true,
				'signupUrl'   => 'http://example.com/signup',
			] ),
		]
	);

	expect( ( new Tile_Layer_Registry() )->get_providers() )->not->toHaveKey( 'bad-signup' );

} );

// ---------------------------------------------------------------------------
// Overlay-specific constraints
// ---------------------------------------------------------------------------

test( 'overlay validator rejects URL without {z}/{x}/{y}', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[ 'bad-overlay' => tlr_overlay_record( [ 'url' => 'https://overlay.example.com/no-placeholders.png' ] ) ]
	);

	expect( ( new Tile_Layer_Registry() )->get_overlays() )->not->toHaveKey( 'bad-overlay' );

} );

test( 'overlay validator rejects http:// URL', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[ 'plain-http-overlay' => tlr_overlay_record( [ 'url' => 'http://overlay.example.com/{z}/{x}/{y}.png' ] ) ]
	);

	expect( ( new Tile_Layer_Registry() )->get_overlays() )->not->toHaveKey( 'plain-http-overlay' );

} );

test( 'overlay validator rejects URL containing {KEY} (overlays carry no key in v1)', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[ 'keyed-overlay' => tlr_overlay_record( [ 'url' => 'https://overlay.example.com/{z}/{x}/{y}.png?key={KEY}' ] ) ]
	);

	expect( ( new Tile_Layer_Registry() )->get_overlays() )->not->toHaveKey( 'keyed-overlay' );

} );

test( 'overlay validator rejects empty label', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[ 'no-label-overlay' => tlr_overlay_record( [ 'label' => '' ] ) ]
	);

	expect( ( new Tile_Layer_Registry() )->get_overlays() )->not->toHaveKey( 'no-label-overlay' );

} );

test( 'overlay validator rejects empty attribution', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[ 'no-attr-overlay' => tlr_overlay_record( [ 'attribution' => '' ] ) ]
	);

	expect( ( new Tile_Layer_Registry() )->get_overlays() )->not->toHaveKey( 'no-attr-overlay' );

} );

test( 'overlay validator rejects malformed id (uppercase)', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[ 'BadOverlay' => tlr_overlay_record() ]
	);

	expect( ( new Tile_Layer_Registry() )->get_overlays() )->not->toHaveKey( 'BadOverlay' );

} );

test( 'overlay validator rejects maxZoom out of range', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[ 'big-overlay' => tlr_overlay_record( [ 'maxZoom' => 100 ] ) ]
	);

	expect( ( new Tile_Layer_Registry() )->get_overlays() )->not->toHaveKey( 'big-overlay' );

} );

// ---------------------------------------------------------------------------
// Resolver — known id, unknown id (fallback), {KEY} substitution
// ---------------------------------------------------------------------------

test( 'resolve_provider returns the requested provider for a known id', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_provider( 'opentopomap', '' );

	expect( $resolved['id'] )->toBe( 'opentopomap' );
	expect( $resolved['url'] )->toContain( 'opentopomap.org' );
	expect( $resolved['url'] )->not->toContain( '{KEY}' );
	expect( $resolved['maxZoom'] )->toBe( 17 );
	expect( $resolved )->toHaveKey( 'subdomains' );

} );

test( 'resolve_provider falls back to osm-standard for an unknown id', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_provider( 'does-not-exist', '' );

	expect( $resolved['id'] )->toBe( 'osm-standard' );
	expect( $resolved['url'] )->toContain( 'tile.openstreetmap.org' );

} );

test( 'resolve_provider substitutes the API key into {KEY}', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_provider( 'thunderforest-outdoors', 'XYZ-token' );

	expect( $resolved['id'] )->toBe( 'thunderforest-outdoors' );
	expect( $resolved['url'] )->not->toContain( '{KEY}' );
	expect( $resolved['url'] )->toContain( 'apikey=XYZ-token' );

} );

test( 'resolve_provider does not substitute when provider does not require a key', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_provider( 'osm-standard', 'spurious-key-value' );

	expect( $resolved['url'] )->not->toContain( 'spurious-key-value' );

} );

// ---------------------------------------------------------------------------
// Filter integration — added/replaced records flow through validation
// ---------------------------------------------------------------------------

test( 'filter can add a new valid provider', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'osm-standard'    => tlr_provider_record( [
				'url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
			] ),
			'custom-provider' => tlr_provider_record( [
				'url' => 'https://custom.example.com/{z}/{x}/{y}.png',
			] ),
		]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( $providers )->toHaveKey( 'custom-provider' );
	expect( $providers['custom-provider']['url'] )->toBe( 'https://custom.example.com/{z}/{x}/{y}.png' );

} );

test( 'filter can replace an existing provider record', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'osm-standard' => tlr_provider_record( [
				'url'   => 'https://my-mirror.example.com/{z}/{x}/{y}.png',
				'label' => 'My OSM mirror',
			] ),
		]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( $providers['osm-standard']['url'] )->toBe( 'https://my-mirror.example.com/{z}/{x}/{y}.png' );
	expect( $providers['osm-standard']['label'] )->toBe( 'My OSM mirror' );

} );

test( 'filter dropping the fallback provider triggers re-injection', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[ 'only-custom' => tlr_provider_record( [ 'url' => 'https://x.example.com/{z}/{x}/{y}.png' ] ) ]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( $providers )->toHaveKey( 'osm-standard' );
	expect( $providers )->toHaveKey( 'only-custom' );

} );

test( 'filter can add a new valid overlay', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'wmt-hiking'   => tlr_overlay_record( [
				'url' => 'https://tile.waymarkedtrails.org/hiking/{z}/{x}/{y}.png',
			] ),
			'custom-grid'  => tlr_overlay_record( [
				'url' => 'https://grid.example.com/{z}/{x}/{y}.png',
			] ),
		]
	);

	$overlays = ( new Tile_Layer_Registry() )->get_overlays();

	expect( $overlays )->toHaveKey( 'custom-grid' );

} );

// ---------------------------------------------------------------------------
// resolve_overlays — preserves order, drops unknown ids, rejects non-string
// ---------------------------------------------------------------------------

test( 'resolve_overlays returns records in editor-configured order', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'first'  => tlr_overlay_record( [ 'url' => 'https://a.example.com/{z}/{x}/{y}.png' ] ),
			'second' => tlr_overlay_record( [ 'url' => 'https://b.example.com/{z}/{x}/{y}.png' ] ),
		]
	);

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_overlays( [ 'second', 'first' ] );

	expect( $resolved )->toHaveCount( 2 );
	expect( $resolved[0]['id'] )->toBe( 'second' );
	expect( $resolved[1]['id'] )->toBe( 'first' );

} );

test( 'resolve_overlays drops unknown ids silently', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_overlays( [ 'wmt-hiking', 'does-not-exist' ] );

	expect( $resolved )->toHaveCount( 1 );
	expect( $resolved[0]['id'] )->toBe( 'wmt-hiking' );

} );

test( 'resolve_overlays drops non-string entries', function (): void {

	$registry = new Tile_Layer_Registry();
	/** @phpstan-ignore-next-line — deliberate misuse to test defensive coercion. */
	$resolved = $registry->resolve_overlays( [ 'wmt-hiking', 0, '', null ] );

	expect( $resolved )->toHaveCount( 1 );
	expect( $resolved[0]['id'] )->toBe( 'wmt-hiking' );

} );

test( 'overlay filter surfaces a custom overlay alongside the default wmt-hiking', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'wmt-hiking'  => tlr_overlay_record( [
				'label' => 'Waymarked Trails — Hiking',
				'url'   => 'https://tile.waymarkedtrails.org/hiking/{z}/{x}/{y}.png',
			] ),
			'custom-grid' => tlr_overlay_record( [
				'label' => 'Custom Grid',
				'url'   => 'https://grid.example.com/{z}/{x}/{y}.png',
			] ),
		]
	);

	$registry = new Tile_Layer_Registry();

	$overlays = $registry->get_overlays();
	expect( $overlays )->toHaveKey( 'wmt-hiking' );
	expect( $overlays )->toHaveKey( 'custom-grid' );
	expect( count( $overlays ) )->toBe( 2 );

	// Resolution preserves the editor-configured order, so requesting
	// custom-grid first and wmt-hiking second yields exactly that order.
	$resolved = $registry->resolve_overlays( [ 'custom-grid', 'wmt-hiking' ] );
	expect( $resolved )->toHaveCount( 2 );
	expect( $resolved[0]['id'] )->toBe( 'custom-grid' );
	expect( $resolved[0]['url'] )->toBe( 'https://grid.example.com/{z}/{x}/{y}.png' );
	expect( $resolved[1]['id'] )->toBe( 'wmt-hiking' );

} );

// ---------------------------------------------------------------------------
// Caching — filter is applied at most once per registry instance
// ---------------------------------------------------------------------------

test( 'get_providers caches the validated set within a single instance', function (): void {

	$call_count = 0;
	Functions\when( 'apply_filters' )->alias(
		static function ( string $name, mixed $value ) use ( &$call_count ): mixed {
			if ( $name === 'kntnt_gpx_blocks_tile_providers' ) {
				++$call_count;
			}
			return $value;
		}
	);

	$registry = new Tile_Layer_Registry();
	$registry->get_providers();
	$registry->get_providers();
	$registry->get_providers();

	expect( $call_count )->toBe( 1 );

} );
