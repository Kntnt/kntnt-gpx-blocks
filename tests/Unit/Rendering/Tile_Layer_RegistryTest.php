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
		->toHaveKey( 'thunderforest-atlas' )
		->toHaveKey( 'thunderforest-opencyclemap' )
		->toHaveKey( 'stadia-outdoors' )
		->toHaveKey( 'maptiler-outdoor' )
		->toHaveKey( 'maptiler-base' )
		->toHaveKey( 'maptiler-landscape' )
		->toHaveKey( 'maptiler-openstreetmap' )
		->toHaveKey( 'maptiler-streets' )
		->toHaveKey( 'maptiler-topo' )
		->toHaveKey( 'maptiler-satellite' )
		->toHaveKey( 'maptiler-hybrid' )
		->toHaveKey( 'mapbox-outdoors' )
		->toHaveKey( 'mapbox-streets' )
		->toHaveKey( 'mapbox-satellite-streets' )
		->toHaveKey( 'mapbox-light' )
		->toHaveKey( 'mapbox-dark' );

	expect( count( $providers ) )->toBe( 21 );

} );

test( 'paid tile providers added in #103 carry the expected url, label, attribution, maxZoom, and signupUrl', function (): void {

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	// Each entry is locked field-by-field against silent regressions in URL
	// template, brand attribution, zoom cap, or signup link. Sourced from the
	// provider's current public documentation; see commit body for refs.
	$expected = [
		'thunderforest-atlas'        => [
			'label'        => 'Thunderforest Atlas',
			'url'          => 'https://tile.thunderforest.com/atlas/{z}/{x}/{y}.png?apikey={KEY}',
			'attribution'  => 'Thunderforest',
			'signupUrl'    => 'https://www.thunderforest.com/',
		],
		'thunderforest-opencyclemap' => [
			'label'        => 'Thunderforest OpenCycleMap',
			'url'          => 'https://tile.thunderforest.com/cycle/{z}/{x}/{y}.png?apikey={KEY}',
			'attribution'  => 'Thunderforest',
			'signupUrl'    => 'https://www.thunderforest.com/',
		],
		'maptiler-base'              => [
			'label'        => 'MapTiler Base',
			'url'          => 'https://api.maptiler.com/maps/basic-v2/{z}/{x}/{y}.png?key={KEY}',
			'attribution'  => 'MapTiler',
			'signupUrl'    => 'https://www.maptiler.com/',
		],
		'maptiler-landscape'         => [
			'label'        => 'MapTiler Landscape',
			'url'          => 'https://api.maptiler.com/maps/landscape/{z}/{x}/{y}.png?key={KEY}',
			'attribution'  => 'MapTiler',
			'signupUrl'    => 'https://www.maptiler.com/',
		],
		'maptiler-openstreetmap'     => [
			'label'        => 'MapTiler OpenStreetMap',
			'url'          => 'https://api.maptiler.com/maps/openstreetmap/{z}/{x}/{y}.jpg?key={KEY}',
			'attribution'  => 'MapTiler',
			'signupUrl'    => 'https://www.maptiler.com/',
		],
		'maptiler-streets'           => [
			'label'        => 'MapTiler Streets',
			'url'          => 'https://api.maptiler.com/maps/streets-v2/{z}/{x}/{y}.png?key={KEY}',
			'attribution'  => 'MapTiler',
			'signupUrl'    => 'https://www.maptiler.com/',
		],
		'maptiler-topo'              => [
			'label'        => 'MapTiler Topo',
			'url'          => 'https://api.maptiler.com/maps/topo-v2/{z}/{x}/{y}.png?key={KEY}',
			'attribution'  => 'MapTiler',
			'signupUrl'    => 'https://www.maptiler.com/',
		],
		'maptiler-satellite'         => [
			'label'        => 'MapTiler Satellite Plain',
			'url'          => 'https://api.maptiler.com/maps/satellite/{z}/{x}/{y}.jpg?key={KEY}',
			'attribution'  => 'MapTiler',
			'signupUrl'    => 'https://www.maptiler.com/',
		],
		'maptiler-hybrid'            => [
			'label'        => 'MapTiler Satellite Hybrid',
			'url'          => 'https://api.maptiler.com/maps/hybrid/{z}/{x}/{y}.jpg?key={KEY}',
			'attribution'  => 'MapTiler',
			'signupUrl'    => 'https://www.maptiler.com/',
		],
		'mapbox-streets'             => [
			'label'        => 'Mapbox Streets',
			'url'          => 'https://api.mapbox.com/styles/v1/mapbox/streets-v12/tiles/{z}/{x}/{y}?access_token={KEY}',
			'attribution'  => 'Mapbox',
			'signupUrl'    => 'https://www.mapbox.com/',
		],
		'mapbox-satellite-streets'   => [
			'label'        => 'Mapbox Satellite Streets',
			'url'          => 'https://api.mapbox.com/styles/v1/mapbox/satellite-streets-v12/tiles/{z}/{x}/{y}?access_token={KEY}',
			'attribution'  => 'Mapbox',
			'signupUrl'    => 'https://www.mapbox.com/',
		],
		'mapbox-light'               => [
			'label'        => 'Mapbox Light',
			'url'          => 'https://api.mapbox.com/styles/v1/mapbox/light-v11/tiles/{z}/{x}/{y}?access_token={KEY}',
			'attribution'  => 'Mapbox',
			'signupUrl'    => 'https://www.mapbox.com/',
		],
		'mapbox-dark'                => [
			'label'        => 'Mapbox Dark',
			'url'          => 'https://api.mapbox.com/styles/v1/mapbox/dark-v11/tiles/{z}/{x}/{y}?access_token={KEY}',
			'attribution'  => 'Mapbox',
			'signupUrl'    => 'https://www.mapbox.com/',
		],
	];

	foreach ( $expected as $id => $fields ) {
		expect( $providers )->toHaveKey( $id );
		expect( $providers[ $id ]['label'] )->toBe( $fields['label'] );
		expect( $providers[ $id ]['url'] )->toBe( $fields['url'] );
		expect( $providers[ $id ]['attribution'] )->toContain( $fields['attribution'] );
		expect( $providers[ $id ]['attribution'] )->toContain( 'OpenStreetMap' );
		expect( $providers[ $id ]['maxZoom'] )->toBe( 22 );
		expect( $providers[ $id ]['requiresKey'] )->toBeTrue();
		expect( $providers[ $id ]['signupUrl'] )->toBe( $fields['signupUrl'] );
	}

} );

test( 'every paid tile provider URL contains both {z}/{x}/{y} and {KEY} placeholders', function (): void {

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	// Every requiresKey=true entry — the seven added in #103 plus the five
	// that pre-existed — must have a complete tile-coordinate substitution
	// set and a key placeholder so `resolve_provider()` can substitute it.
	foreach ( $providers as $id => $record ) {
		if ( ! $record['requiresKey'] ) {
			continue;
		}
		expect( $record['url'] )->toStartWith( 'https://' );
		expect( $record['url'] )->toContain( '{z}' );
		expect( $record['url'] )->toContain( '{x}' );
		expect( $record['url'] )->toContain( '{y}' );
		expect( $record['url'] )->toContain( '{KEY}' );
		expect( $record )->toHaveKey( 'signupUrl' );
		expect( $record['signupUrl'] )->toStartWith( 'https://' );
	}

} );

test( 'resolve_provider substitutes the API key for every paid provider added in #103', function (): void {

	$registry = new Tile_Layer_Registry();

	// Every new entry must be reachable via the #102 per-provider-key path:
	// `resolve_provider()` looks up the record, substitutes `{KEY}` for the
	// supplied key, and returns the runtime record. Tested across all 13 to
	// catch any entry whose URL template was wired without `{KEY}` in place.
	$new_ids = [
		'thunderforest-atlas',
		'thunderforest-opencyclemap',
		'maptiler-base',
		'maptiler-landscape',
		'maptiler-openstreetmap',
		'maptiler-streets',
		'maptiler-topo',
		'maptiler-satellite',
		'maptiler-hybrid',
		'mapbox-streets',
		'mapbox-satellite-streets',
		'mapbox-light',
		'mapbox-dark',
	];

	foreach ( $new_ids as $id ) {
		$resolved = $registry->resolve_provider( $id, 'TEST-KEY-103' );
		expect( $resolved['id'] )->toBe( $id );
		expect( $resolved['url'] )->not->toContain( '{KEY}' );
		expect( $resolved['url'] )->toContain( 'TEST-KEY-103' );
	}

} );

test( 'resolve_provider produces the fully substituted URL for a representative new entry (edge case)', function (): void {

	// Edge case for the #102 per-provider-key mechanism: a Mapbox-style URL
	// where `{KEY}` lives in a query-string parameter named `access_token`,
	// distinct from MapTiler's `key=` and Thunderforest's `apikey=`. Verifies
	// the substitution is a literal swap of the placeholder, with no special
	// treatment for the surrounding querystring shape.
	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_provider( 'mapbox-dark', 'pk.fake.token' );

	expect( $resolved['id'] )->toBe( 'mapbox-dark' );
	expect( $resolved['url'] )->toBe( 'https://api.mapbox.com/styles/v1/mapbox/dark-v11/tiles/{z}/{x}/{y}?access_token=pk.fake.token' );
	expect( $resolved['maxZoom'] )->toBe( 22 );
	expect( $resolved['attribution'] )->toContain( 'Mapbox' );

} );

test( 'default overlays all survive validation', function (): void {

	$registry = new Tile_Layer_Registry();
	$overlays = $registry->get_overlays();

	expect( $overlays )
		->toHaveKey( 'wmt-hiking' )
		->toHaveKey( 'wmt-cycling' )
		->toHaveKey( 'wmt-mtb' )
		->toHaveKey( 'openseamap' )
		->toHaveKey( 'opensnowmap' );

	expect( count( $overlays ) )->toBe( 5 );

} );

test( 'default overlay records carry the expected url, label, attribution, and maxZoom', function (): void {

	$registry = new Tile_Layer_Registry();
	$overlays = $registry->get_overlays();

	// Each shipped free-overlay entry is verified field-by-field so the URL
	// template, the brand attribution, and the zoom cap are locked against
	// silent regressions.
	expect( $overlays['wmt-hiking']['url'] )->toBe( 'https://tile.waymarkedtrails.org/hiking/{z}/{x}/{y}.png' );
	expect( $overlays['wmt-hiking']['label'] )->toBe( 'Waymarked Trails — Hiking' );
	expect( $overlays['wmt-hiking']['attribution'] )->toContain( 'Waymarked Trails' );
	expect( $overlays['wmt-hiking']['maxZoom'] )->toBe( 18 );

	expect( $overlays['wmt-cycling']['url'] )->toBe( 'https://tile.waymarkedtrails.org/cycling/{z}/{x}/{y}.png' );
	expect( $overlays['wmt-cycling']['label'] )->toBe( 'Waymarked Trails — Cycling' );
	expect( $overlays['wmt-cycling']['attribution'] )->toContain( 'Waymarked Trails' );
	expect( $overlays['wmt-cycling']['maxZoom'] )->toBe( 18 );

	expect( $overlays['wmt-mtb']['url'] )->toBe( 'https://tile.waymarkedtrails.org/mtb/{z}/{x}/{y}.png' );
	expect( $overlays['wmt-mtb']['label'] )->toBe( 'Waymarked Trails — MTB' );
	expect( $overlays['wmt-mtb']['attribution'] )->toContain( 'Waymarked Trails' );
	expect( $overlays['wmt-mtb']['maxZoom'] )->toBe( 18 );

	expect( $overlays['openseamap']['url'] )->toBe( 'https://tiles.openseamap.org/seamark/{z}/{x}/{y}.png' );
	expect( $overlays['openseamap']['label'] )->toBe( 'OpenSeaMap (sea marks)' );
	expect( $overlays['openseamap']['attribution'] )->toContain( 'OpenSeaMap' );
	expect( $overlays['openseamap']['maxZoom'] )->toBe( 18 );

	expect( $overlays['opensnowmap']['url'] )->toBe( 'https://tiles.opensnowmap.org/pistes/{z}/{x}/{y}.png' );
	expect( $overlays['opensnowmap']['label'] )->toBe( 'OpenSnowMap (pistes)' );
	expect( $overlays['opensnowmap']['attribution'] )->toContain( 'OpenSnowMap' );
	expect( $overlays['opensnowmap']['maxZoom'] )->toBe( 18 );

} );

test( 'default overlay URLs all use https and contain the {z}/{x}/{y} placeholders', function (): void {

	$overlays = ( new Tile_Layer_Registry() )->get_overlays();

	// Each surviving entry must already satisfy the validator's URL contract;
	// re-asserting it here locks the rule against accidental relaxation in
	// the default-set composition.
	foreach ( $overlays as $id => $record ) {
		expect( $record['url'] )->toStartWith( 'https://' );
		expect( $record['url'] )->toContain( '{z}' );
		expect( $record['url'] )->toContain( '{x}' );
		expect( $record['url'] )->toContain( '{y}' );
		expect( $record['url'] )->not->toContain( '{KEY}' );
	}

} );

test( 'default overlays without a {KEY} placeholder would still be rejected if one were inserted', function (): void {

	// Lock the existing "overlays in v1 carry no API key" rule by simulating
	// the same shipped overlays with a synthetic {KEY} appended; every record
	// must be dropped by the validator.
	$keyed_set = [
		'wmt-hiking-keyed'  => tlr_overlay_record( [ 'url' => 'https://tile.waymarkedtrails.org/hiking/{z}/{x}/{y}.png?key={KEY}' ] ),
		'wmt-cycling-keyed' => tlr_overlay_record( [ 'url' => 'https://tile.waymarkedtrails.org/cycling/{z}/{x}/{y}.png?key={KEY}' ] ),
		'wmt-mtb-keyed'     => tlr_overlay_record( [ 'url' => 'https://tile.waymarkedtrails.org/mtb/{z}/{x}/{y}.png?key={KEY}' ] ),
		'openseamap-keyed'  => tlr_overlay_record( [ 'url' => 'https://tiles.openseamap.org/seamark/{z}/{x}/{y}.png?key={KEY}' ] ),
		'opensnowmap-keyed' => tlr_overlay_record( [ 'url' => 'https://tiles.opensnowmap.org/pistes/{z}/{x}/{y}.png?key={KEY}' ] ),
	];

	tlr_filter_returns( 'kntnt_gpx_blocks_tile_overlays', $keyed_set );

	$overlays = ( new Tile_Layer_Registry() )->get_overlays();

	expect( $overlays )->toBe( [] );

} );

test( 'default overlays resolve in editor-configured order without an explicit filter override', function (): void {

	// Reaches through `resolve_overlays()` so the runtime path that the GPX
	// Map block actually uses is exercised against the shipped defaults.
	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_overlays( [ 'wmt-cycling', 'wmt-mtb', 'openseamap', 'opensnowmap', 'wmt-hiking' ] );

	expect( $resolved )->toHaveCount( 5 );
	expect( $resolved[0]['id'] )->toBe( 'wmt-cycling' );
	expect( $resolved[1]['id'] )->toBe( 'wmt-mtb' );
	expect( $resolved[2]['id'] )->toBe( 'openseamap' );
	expect( $resolved[3]['id'] )->toBe( 'opensnowmap' );
	expect( $resolved[4]['id'] )->toBe( 'wmt-hiking' );

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
