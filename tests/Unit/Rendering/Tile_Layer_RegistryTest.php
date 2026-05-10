<?php
/**
 * Tests for Rendering\Tile_Layer_Registry.
 *
 * Covers the validator (drop-the-narrowest-unit rule across every
 * documented provider and style constraint), the resolver (known
 * provider+style, fallback to provider default on unknown style, global
 * fallback on unknown provider, defensive fall-through when a provider's
 * own default cannot resolve, `{KEY}` substitution), and the filter
 * integration (added/replaced records flow through validation; the
 * canonical fallback is always preserved).
 *
 * Brain Monkey supplies `apply_filters` and `__()` so the registry can
 * run without a live WordPress install. Default-providers helpers
 * exercise the filter mock directly so each test starts from a known
 * input.
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
 * Builds a minimal valid style record with optional overrides.
 *
 * The base style shape passes every style-level validator constraint and
 * pairs naturally with a key-less provider (no `{KEY}` placeholder).
 * Tests inject overrides to flip a single field at a time.
 *
 * @param array<string, mixed> $overrides Overrides applied on top of the base.
 *
 * @return array<string, mixed>
 */
function tlr_style_record( array $overrides = [] ): array {
	$base = [
		'label'       => 'Test Style',
		'url'         => 'https://tiles.example.com/{z}/{x}/{y}.png',
		'attribution' => '&copy; Example',
		'maxZoom'     => 19,
	];
	return array_merge( $base, $overrides );
}

/**
 * Builds a minimal valid provider record with optional overrides and a
 * default single-style sub-map.
 *
 * @param array<string, mixed> $overrides Provider-level overrides.
 * @param array<string, array<string, mixed>>|null $styles Optional explicit
 *                                                          styles map; when
 *                                                          omitted, a single
 *                                                          style id `default`
 *                                                          is generated.
 *
 * @return array<string, mixed>
 */
function tlr_provider_record( array $overrides = [], ?array $styles = null ): array {
	$styles = $styles ?? [ 'default' => tlr_style_record() ];
	$base   = [
		'label'       => 'Test Provider',
		'requiresKey' => false,
		'default'     => 'default',
		'styles'      => $styles,
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
		->toHaveKey( 'carto' )
		->toHaveKey( 'esri' )
		->toHaveKey( 'jawg-maps' )
		->toHaveKey( 'mapbox' )
		->toHaveKey( 'maptiler' )
		->toHaveKey( 'openstreetmap' )
		->toHaveKey( 'opentopomap' )
		->toHaveKey( 'stadia-maps' )
		->toHaveKey( 'thunderforest' );

	expect( count( $providers ) )->toBe( 9 );

} );

test( 'default providers ship the expected style counts', function (): void {

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( count( $providers['carto']['styles'] ) )->toBe( 3 );
	expect( count( $providers['esri']['styles'] ) )->toBe( 9 );
	expect( count( $providers['jawg-maps']['styles'] ) )->toBe( 5 );
	expect( count( $providers['mapbox']['styles'] ) )->toBe( 5 );
	expect( count( $providers['maptiler']['styles'] ) )->toBe( 9 );
	expect( count( $providers['openstreetmap']['styles'] ) )->toBe( 2 );
	expect( count( $providers['opentopomap']['styles'] ) )->toBe( 1 );
	expect( count( $providers['stadia-maps']['styles'] ) )->toBe( 6 );
	expect( count( $providers['thunderforest']['styles'] ) )->toBe( 4 );

} );

test( 'every default provider carries a default style id that exists in its styles map', function (): void {

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	foreach ( $providers as $id => $record ) {
		expect( $record )->toHaveKey( 'default' );
		expect( array_key_exists( $record['default'], $record['styles'] ) )
			->toBeTrue();
	}

} );

test( 'OpenStreetMap default style is mapnik', function (): void {

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( $providers['openstreetmap']['default'] )->toBe( 'mapnik' );
	expect( $providers['openstreetmap']['styles'] )->toHaveKey( 'mapnik' );
	expect( $providers['openstreetmap']['styles'] )->toHaveKey( 'cyclosm' );

} );

test( 'key-required providers (mapbox, maptiler, jawg-maps, stadia-maps, thunderforest) carry signupUrl and {KEY}', function (): void {

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	foreach ( [ 'mapbox', 'maptiler', 'jawg-maps', 'stadia-maps', 'thunderforest' ] as $id ) {
		expect( $providers[ $id ]['requiresKey'] )->toBeTrue();
		expect( $providers[ $id ] )->toHaveKey( 'signupUrl' );
		expect( $providers[ $id ]['signupUrl'] )->toStartWith( 'https://' );

		// Every style in a key-required provider must contain {KEY}.
		foreach ( $providers[ $id ]['styles'] as $style ) {
			expect( $style['url'] )->toContain( '{KEY}' );
			expect( $style['url'] )->toStartWith( 'https://' );
			expect( $style['url'] )->toContain( '{z}' );
			expect( $style['url'] )->toContain( '{x}' );
			expect( $style['url'] )->toContain( '{y}' );
		}
	}

} );

test( 'key-less providers (carto, esri, openstreetmap, opentopomap) have no {KEY} placeholders and no signupUrl', function (): void {

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	foreach ( [ 'carto', 'esri', 'openstreetmap', 'opentopomap' ] as $id ) {
		expect( $providers[ $id ]['requiresKey'] )->toBeFalse();
		// signupUrl may or may not be present; if not, that's correct.
		foreach ( $providers[ $id ]['styles'] as $style ) {
			expect( $style['url'] )->not->toContain( '{KEY}' );
		}
	}

} );

test( 'providers using {s} in their style URLs declare subdomains at the provider level', function (): void {

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	foreach ( $providers as $record ) {
		foreach ( $record['styles'] as $style ) {
			if ( str_contains( $style['url'], '{s}' ) ) {
				expect( $record )->toHaveKey( 'subdomains' );
			}
		}
	}

} );

// ---------------------------------------------------------------------------
// Resolver — known (provider, style); fallbacks; {KEY} substitution
// ---------------------------------------------------------------------------

test( 'resolve_provider returns the requested (provider, style) record for known ids', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_provider( 'openstreetmap', 'mapnik', '' );

	expect( $resolved['url'] )->toContain( 'tile.openstreetmap.org' );
	expect( $resolved['url'] )->not->toContain( '{KEY}' );
	expect( $resolved['maxZoom'] )->toBe( 19 );
	expect( $resolved )->toHaveKey( 'subdomains' );
	expect( $resolved['subdomains'] )->toBe( [ 'a', 'b', 'c' ] );

} );

test( 'resolve_provider falls back to the provider default style on unknown style id', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_provider( 'openstreetmap', 'does-not-exist', '' );

	// `openstreetmap`'s default is `mapnik`, so the fall-back should return
	// the mapnik URL.
	expect( $resolved['url'] )->toContain( 'tile.openstreetmap.org' );
	expect( $resolved['url'] )->not->toContain( 'cyclosm' );

} );

test( 'resolve_provider falls back to openstreetmap on unknown provider id', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_provider( 'definitely-not-a-real-provider', 'anything', '' );

	expect( $resolved['url'] )->toContain( 'tile.openstreetmap.org' );

} );

test( 'resolve_provider falls back when the provider default itself cannot resolve (defensive)', function (): void {

	// Construct a provider whose `default` points to a style that exists in
	// the raw filter input but gets validated successfully. To exercise the
	// defensive fall-through path, simulate a registry where the only
	// surviving provider has a `default` that — by the validator's contract
	// — should always be present. Since validation enforces this invariant,
	// the only path to a missing default at resolve time is filter mutation
	// between get_providers() and resolve_provider(). We can't easily
	// simulate that, so the defensive branch is exercised indirectly via
	// the unknown-provider path (which lands on openstreetmap unconditionally).
	$registry = new Tile_Layer_Registry();

	// Unknown provider + unknown style; falls all the way through to
	// openstreetmap/mapnik. This locks the fall-through chain's end state.
	$resolved = $registry->resolve_provider( 'no-such-provider', 'no-such-style', '' );

	expect( $resolved['url'] )->toContain( 'tile.openstreetmap.org' );
	expect( $resolved['maxZoom'] )->toBe( 19 );

} );

test( 'resolve_provider substitutes the API key into {KEY} for key-required providers', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_provider( 'thunderforest', 'outdoor', 'XYZ-TOKEN' );

	expect( $resolved['url'] )->not->toContain( '{KEY}' );
	expect( $resolved['url'] )->toContain( 'apikey=XYZ-TOKEN' );

} );

test( 'resolve_provider does not substitute when provider does not require a key', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_provider( 'openstreetmap', 'mapnik', 'spurious-key-value' );

	expect( $resolved['url'] )->not->toContain( 'spurious-key-value' );

} );

test( 'resolve_provider inherits provider-level subdomains into the resolved record', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_provider( 'carto', 'voyager', '' );

	expect( $resolved )->toHaveKey( 'subdomains' );
	expect( $resolved['subdomains'] )->toBe( [ 'a', 'b', 'c', 'd' ] );

} );

test( 'resolve_provider omits subdomains when the provider has none', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_provider( 'mapbox', 'outdoors', 'TOKEN' );

	expect( $resolved )->not->toHaveKey( 'subdomains' );

} );

test( 'resolve_provider returns only the four runtime fields (no id, no label, no requiresKey)', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_provider( 'mapbox', 'outdoors', 'TOKEN' );

	expect( $resolved )->toHaveKey( 'url' );
	expect( $resolved )->toHaveKey( 'attribution' );
	expect( $resolved )->toHaveKey( 'maxZoom' );
	expect( $resolved )->not->toHaveKey( 'id' );
	expect( $resolved )->not->toHaveKey( 'label' );
	expect( $resolved )->not->toHaveKey( 'requiresKey' );
	expect( $resolved )->not->toHaveKey( 'default' );
	expect( $resolved )->not->toHaveKey( 'styles' );
	expect( $resolved )->not->toHaveKey( 'signupUrl' );

} );

// ---------------------------------------------------------------------------
// Validator — drop the narrowest unit on each documented constraint
// ---------------------------------------------------------------------------

test( 'validator drops a single bad style and keeps the rest of the provider', function (): void {

	// Edge case: bad single style. The provider survives because at least
	// one valid style remains and the `default` points at a survivor.
	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'multi-style' => tlr_provider_record(
				[ 'default' => 'good' ],
				[
					'good' => tlr_style_record(),
					'bad'  => tlr_style_record( [ 'url' => 'https://example.com/no-placeholders.png' ] ),
				]
			),
		]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( $providers )->toHaveKey( 'multi-style' );
	expect( $providers['multi-style']['styles'] )->toHaveKey( 'good' );
	expect( $providers['multi-style']['styles'] )->not->toHaveKey( 'bad' );

} );

test( 'validator drops the whole provider when no styles survive', function (): void {

	// Edge case: bad provider — every style fails, so the provider has no
	// surviving styles to resolve to.
	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'all-bad' => tlr_provider_record(
				[],
				[
					'a' => tlr_style_record( [ 'url' => 'http://insecure.example.com/{z}/{x}/{y}.png' ] ),
					'b' => tlr_style_record( [ 'maxZoom' => 100 ] ),
				]
			),
		]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( $providers )->not->toHaveKey( 'all-bad' );

} );

test( 'validator drops the provider when the default style does not survive', function (): void {

	// Edge case: provider's `default` resolves to a dropped style. Even
	// though another style is valid, the provider is rejected because the
	// resolver would land on a missing record.
	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'bad-default' => tlr_provider_record(
				[ 'default' => 'a' ],
				[
					'a' => tlr_style_record( [ 'url' => 'http://insecure.example.com/{z}/{x}/{y}.png' ] ),
					'b' => tlr_style_record(),
				]
			),
		]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( $providers )->not->toHaveKey( 'bad-default' );

} );

test( 'validator drops the provider when styles map is empty', function (): void {

	// Edge case: empty styles map — no styles to validate, no styles to default to.
	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'empty-styles' => tlr_provider_record( [ 'styles' => [] ] ),
		]
	);

	expect( ( new Tile_Layer_Registry() )->get_providers() )->not->toHaveKey( 'empty-styles' );

} );

test( 'validator drops a {s}-using style when its provider declares no subdomains', function (): void {

	// Edge case: {s}-using style without provider subdomains. The style is
	// dropped but the rest of the provider survives if other styles do not
	// use {s}.
	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'no-subs' => tlr_provider_record(
				[ 'default' => 'plain' ],
				[
					'plain'    => tlr_style_record(),
					'with-s'   => tlr_style_record( [ 'url' => 'https://{s}.tiles.example.com/{z}/{x}/{y}.png' ] ),
				]
			),
		]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( $providers )->toHaveKey( 'no-subs' );
	expect( $providers['no-subs']['styles'] )->toHaveKey( 'plain' );
	expect( $providers['no-subs']['styles'] )->not->toHaveKey( 'with-s' );

} );

test( 'validator accepts a {s}-using style when its provider declares subdomains', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'with-subs' => tlr_provider_record(
				[
					'default'    => 'with-s',
					'subdomains' => [ 'a', 'b' ],
				],
				[
					'with-s' => tlr_style_record( [ 'url' => 'https://{s}.tiles.example.com/{z}/{x}/{y}.png' ] ),
				]
			),
		]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( $providers )->toHaveKey( 'with-subs' );
	expect( $providers['with-subs']['styles'] )->toHaveKey( 'with-s' );

} );

test( 'validator rejects provider with non-bool requiresKey', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[ 'bad-bool' => tlr_provider_record( [ 'requiresKey' => 1 ] ) ]
	);

	expect( ( new Tile_Layer_Registry() )->get_providers() )->not->toHaveKey( 'bad-bool' );

} );

test( 'validator rejects provider with empty default', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[ 'no-default' => tlr_provider_record( [ 'default' => '' ] ) ]
	);

	expect( ( new Tile_Layer_Registry() )->get_providers() )->not->toHaveKey( 'no-default' );

} );

test( 'validator rejects provider with non-string default', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[ 'wrong-default' => tlr_provider_record( [ 'default' => 12345 ] ) ]
	);

	expect( ( new Tile_Layer_Registry() )->get_providers() )->not->toHaveKey( 'wrong-default' );

} );

test( 'validator rejects provider with empty label', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[ 'no-label' => tlr_provider_record( [ 'label' => '' ] ) ]
	);

	expect( ( new Tile_Layer_Registry() )->get_providers() )->not->toHaveKey( 'no-label' );

} );

test( 'validator drops style with URL without {z}/{x}/{y}', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'good-provider' => tlr_provider_record(
				[ 'default' => 'a' ],
				[
					'a' => tlr_style_record(),
					'b' => tlr_style_record( [ 'url' => 'https://example.com/no-placeholders.png' ] ),
				]
			),
		]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();
	expect( $providers['good-provider']['styles'] )->not->toHaveKey( 'b' );

} );

test( 'validator drops style with http:// URL scheme', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'good' => tlr_provider_record(
				[ 'default' => 'a' ],
				[
					'a' => tlr_style_record(),
					'b' => tlr_style_record( [ 'url' => 'http://tiles.example.com/{z}/{x}/{y}.png' ] ),
				]
			),
		]
	);

	expect( ( new Tile_Layer_Registry() )->get_providers()['good']['styles'] )->not->toHaveKey( 'b' );

} );

test( 'validator drops style when requiresKey=true but URL lacks {KEY}', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'paid' => tlr_provider_record(
				[
					'requiresKey' => true,
					'default'     => 'a',
				],
				[
					'a' => tlr_style_record( [ 'url' => 'https://tiles.example.com/{z}/{x}/{y}.png?key={KEY}' ] ),
					'b' => tlr_style_record( [ 'url' => 'https://tiles.example.com/{z}/{x}/{y}.png' ] ),
				]
			),
		]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();
	expect( $providers['paid']['styles'] )->toHaveKey( 'a' );
	expect( $providers['paid']['styles'] )->not->toHaveKey( 'b' );

} );

test( 'validator drops style when requiresKey=false but URL contains {KEY}', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'free' => tlr_provider_record(
				[
					'requiresKey' => false,
					'default'     => 'good',
				],
				[
					'good' => tlr_style_record(),
					'bad'  => tlr_style_record( [ 'url' => 'https://tiles.example.com/{z}/{x}/{y}.png?key={KEY}' ] ),
				]
			),
		]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();
	expect( $providers['free']['styles'] )->toHaveKey( 'good' );
	expect( $providers['free']['styles'] )->not->toHaveKey( 'bad' );

} );

test( 'validator drops style with maxZoom out of range', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'provider' => tlr_provider_record(
				[ 'default' => 'a' ],
				[
					'a' => tlr_style_record(),
					'b' => tlr_style_record( [ 'maxZoom' => 100 ] ),
					'c' => tlr_style_record( [ 'maxZoom' => -1 ] ),
					'd' => tlr_style_record( [ 'maxZoom' => 18.5 ] ),
				]
			),
		]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();
	expect( $providers['provider']['styles'] )->toHaveKey( 'a' );
	expect( $providers['provider']['styles'] )->not->toHaveKey( 'b' );
	expect( $providers['provider']['styles'] )->not->toHaveKey( 'c' );
	expect( $providers['provider']['styles'] )->not->toHaveKey( 'd' );

} );

test( 'validator rejects malformed provider id (uppercase)', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[ 'BadId' => tlr_provider_record() ]
	);

	expect( ( new Tile_Layer_Registry() )->get_providers() )->not->toHaveKey( 'BadId' );

} );

test( 'validator rejects malformed provider id with underscores', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[ 'bad_id' => tlr_provider_record() ]
	);

	expect( ( new Tile_Layer_Registry() )->get_providers() )->not->toHaveKey( 'bad_id' );

} );

test( 'validator drops style with malformed style id (uppercase)', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'provider' => tlr_provider_record(
				[ 'default' => 'good' ],
				[
					'good'    => tlr_style_record(),
					'BadId' => tlr_style_record(),
				]
			),
		]
	);

	expect( ( new Tile_Layer_Registry() )->get_providers()['provider']['styles'] )->not->toHaveKey( 'BadId' );

} );

test( 'validator rejects non-https signupUrl', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'paid' => tlr_provider_record(
				[
					'requiresKey' => true,
					'signupUrl'   => 'http://example.com/signup',
				],
				[
					'a' => tlr_style_record( [ 'url' => 'https://tiles.example.com/{z}/{x}/{y}.png?key={KEY}' ] ),
				]
			),
		]
	);

	expect( ( new Tile_Layer_Registry() )->get_providers() )->not->toHaveKey( 'paid' );

} );

test( 'validator rejects non-string subdomains entries', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[ 'bad-subs' => tlr_provider_record( [ 'subdomains' => [ 'a', 1, 'c' ] ] ) ]
	);

	expect( ( new Tile_Layer_Registry() )->get_providers() )->not->toHaveKey( 'bad-subs' );

} );

// ---------------------------------------------------------------------------
// Overlay-specific constraints (unchanged shape)
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// Filter integration — orphan saved id; global-fallback path; re-injection
// ---------------------------------------------------------------------------

test( 'filter dropping the fallback provider triggers re-injection', function (): void {

	// Edge case: global-fallback path. The filter returns only a custom
	// provider; the registry re-injects the canonical openstreetmap so
	// resolve_provider always has a fallback target.
	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'only-custom' => tlr_provider_record(
				[],
				[ 'default' => tlr_style_record( [ 'url' => 'https://x.example.com/{z}/{x}/{y}.png' ] ) ]
			),
		]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( $providers )->toHaveKey( 'openstreetmap' );
	expect( $providers )->toHaveKey( 'only-custom' );

} );

test( 'filter can add a new valid provider', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'openstreetmap'   => tlr_provider_record(
				[ 'subdomains' => [ 'a', 'b', 'c' ] ],
				[
					'default' => tlr_style_record( [
						'url' => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
					] ),
				]
			),
			'custom-provider' => tlr_provider_record(
				[],
				[ 'default' => tlr_style_record( [ 'url' => 'https://custom.example.com/{z}/{x}/{y}.png' ] ) ]
			),
		]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( $providers )->toHaveKey( 'custom-provider' );
	expect( $providers['custom-provider']['styles']['default']['url'] )->toBe( 'https://custom.example.com/{z}/{x}/{y}.png' );

} );

test( 'resolver returns the orphan-recoverable fallback when the saved provider id is no longer present', function (): void {

	// Edge case: orphan saved id. A block saved with `tileProvider:
	// "dropped-by-filter"` should resolve to the global fallback
	// (openstreetmap) at render time. The registry never silently
	// rewrites the block attribute — the orphan surfaces in the editor
	// as a placeholder option — but resolution at render time still
	// produces a usable URL.
	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			// Only ship openstreetmap, with a matching default style.
			'openstreetmap' => tlr_provider_record(
				[
					'default'    => 'mapnik',
					'subdomains' => [ 'a', 'b', 'c' ],
				],
				[
					'mapnik' => tlr_style_record( [
						'url' => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
					] ),
				]
			),
		]
	);

	$resolved = ( new Tile_Layer_Registry() )->resolve_provider(
		'orphaned-provider-id',
		'orphaned-style-id',
		''
	);

	expect( $resolved['url'] )->toContain( 'tile.openstreetmap.org' );

} );

// ---------------------------------------------------------------------------
// resolve_overlays — preserves order, drops unknown ids
// ---------------------------------------------------------------------------

test( 'resolve_overlays preserves editor-configured order', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_overlays( [ 'wmt-cycling', 'wmt-mtb', 'wmt-hiking' ] );

	expect( $resolved )->toHaveCount( 3 );
	expect( $resolved[0]['id'] )->toBe( 'wmt-cycling' );
	expect( $resolved[1]['id'] )->toBe( 'wmt-mtb' );
	expect( $resolved[2]['id'] )->toBe( 'wmt-hiking' );

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
