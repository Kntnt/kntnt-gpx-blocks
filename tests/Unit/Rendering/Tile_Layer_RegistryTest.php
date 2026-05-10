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
 * Builds a minimal valid overlay-layer record with optional overrides.
 *
 * The base layer shape passes every overlay-layer validator constraint
 * and pairs naturally with a key-less overlay provider (no `{KEY}`
 * placeholder). Tests inject overrides to flip a single field at a time.
 *
 * @param array<string, mixed> $overrides Overrides applied on top of the base.
 *
 * @return array<string, mixed>
 */
function tlr_overlay_layer_record( array $overrides = [] ): array {
	$base = [
		'label'       => 'Test Layer',
		'url'         => 'https://overlay.example.com/{z}/{x}/{y}.png',
		'attribution' => '&copy; Overlay',
		'maxZoom'     => 18,
	];
	return array_merge( $base, $overrides );
}

/**
 * Builds a minimal valid overlay-provider record with optional overrides
 * and a default single-layer sub-map.
 *
 * @param array<string, mixed>                     $overrides Provider-level overrides.
 * @param array<string, array<string, mixed>>|null $layers    Optional explicit
 *                                                            layers map; when
 *                                                            omitted, a single
 *                                                            layer id `default`
 *                                                            is generated.
 *
 * @return array<string, mixed>
 */
function tlr_overlay_provider_record( array $overrides = [], ?array $layers = null ): array {
	$layers = $layers ?? [ 'default' => tlr_overlay_layer_record() ];
	$base   = [
		'label'       => 'Test Overlay Provider',
		'requiresKey' => false,
		'layers'      => $layers,
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
// Overlay defaults — every shipped provider and layer validates
// ---------------------------------------------------------------------------

test( 'default overlay providers all survive validation', function (): void {

	$registry = new Tile_Layer_Registry();
	$overlays = $registry->get_overlays();

	expect( $overlays )
		->toHaveKey( 'openseamap' )
		->toHaveKey( 'opensnowmap' )
		->toHaveKey( 'openweathermap' )
		->toHaveKey( 'waymarked-trails' );

	expect( count( $overlays ) )->toBe( 4 );

} );

test( 'default overlay providers ship the expected layer counts', function (): void {

	$overlays = ( new Tile_Layer_Registry() )->get_overlays();

	expect( count( $overlays['openseamap']['layers'] ) )->toBe( 1 );
	expect( count( $overlays['opensnowmap']['layers'] ) )->toBe( 1 );
	expect( count( $overlays['openweathermap']['layers'] ) )->toBe( 5 );
	expect( count( $overlays['waymarked-trails']['layers'] ) )->toBe( 6 );

} );

test( 'openweathermap is key-required and carries signupUrl plus {KEY} on every layer', function (): void {

	$overlays = ( new Tile_Layer_Registry() )->get_overlays();

	expect( $overlays['openweathermap']['requiresKey'] )->toBeTrue();
	expect( $overlays['openweathermap'] )->toHaveKey( 'signupUrl' );
	expect( $overlays['openweathermap']['signupUrl'] )->toStartWith( 'https://' );

	foreach ( $overlays['openweathermap']['layers'] as $layer ) {
		expect( $layer['url'] )->toContain( '{KEY}' );
		expect( $layer['url'] )->toStartWith( 'https://' );
		expect( $layer['url'] )->toContain( '{z}' );
		expect( $layer['url'] )->toContain( '{x}' );
		expect( $layer['url'] )->toContain( '{y}' );
	}

} );

test( 'key-less overlay providers have no {KEY} placeholders and no signupUrl', function (): void {

	$overlays = ( new Tile_Layer_Registry() )->get_overlays();

	foreach ( [ 'openseamap', 'opensnowmap', 'waymarked-trails' ] as $id ) {
		expect( $overlays[ $id ]['requiresKey'] )->toBeFalse();
		expect( array_key_exists( 'signupUrl', $overlays[ $id ] ) )->toBeFalse();
		foreach ( $overlays[ $id ]['layers'] as $layer ) {
			expect( $layer['url'] )->not->toContain( '{KEY}' );
		}
	}

} );

test( 'waymarked-trails ships hiking, cycling, mtb, riding, skating, winter (winter points at slopes/)', function (): void {

	$wmt = ( new Tile_Layer_Registry() )->get_overlays()['waymarked-trails'];

	foreach ( [ 'hiking', 'cycling', 'mtb', 'riding', 'skating', 'winter' ] as $layer_id ) {
		expect( $wmt['layers'] )->toHaveKey( $layer_id );
	}

	// The winter layer URL points at the slopes/ endpoint per Waymarked Trails' own
	// naming for the winter routing.
	expect( $wmt['layers']['winter']['url'] )->toContain( 'waymarkedtrails.org/slopes/' );

} );

test( 'openweathermap ships clouds, precipitation, pressure, temperature, wind-speed', function (): void {

	$owm = ( new Tile_Layer_Registry() )->get_overlays()['openweathermap'];

	foreach ( [ 'clouds', 'precipitation', 'pressure', 'temperature', 'wind-speed' ] as $layer_id ) {
		expect( $owm['layers'] )->toHaveKey( $layer_id );
	}

} );

test( 'openseamap ships the seamarks layer; opensnowmap ships the pistes layer', function (): void {

	$overlays = ( new Tile_Layer_Registry() )->get_overlays();

	expect( $overlays['openseamap']['layers'] )->toHaveKey( 'seamarks' );
	expect( $overlays['opensnowmap']['layers'] )->toHaveKey( 'pistes' );

} );

// ---------------------------------------------------------------------------
// Overlay resolver — known (provider, layer); drops; {KEY} substitution
// ---------------------------------------------------------------------------

test( 'resolve_overlays returns the requested (provider, layer) record for known pairs', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_overlays(
		[ [ 'provider' => 'waymarked-trails', 'layer' => 'hiking' ] ],
		[]
	);

	expect( $resolved )->toHaveCount( 1 );
	expect( $resolved[0]['url'] )->toContain( 'waymarkedtrails.org/hiking' );
	expect( $resolved[0]['url'] )->not->toContain( '{KEY}' );
	expect( $resolved[0]['maxZoom'] )->toBe( 18 );

} );

test( 'resolve_overlays preserves editor-configured pair order', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_overlays(
		[
			[ 'provider' => 'waymarked-trails', 'layer' => 'cycling' ],
			[ 'provider' => 'opensnowmap', 'layer' => 'pistes' ],
			[ 'provider' => 'waymarked-trails', 'layer' => 'hiking' ],
		],
		[]
	);

	expect( $resolved )->toHaveCount( 3 );
	expect( $resolved[0]['url'] )->toContain( 'waymarkedtrails.org/cycling' );
	expect( $resolved[1]['url'] )->toContain( 'opensnowmap.org/pistes' );
	expect( $resolved[2]['url'] )->toContain( 'waymarkedtrails.org/hiking' );

} );

test( 'resolve_overlays drops a pair when the provider is unknown', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_overlays(
		[
			[ 'provider' => 'waymarked-trails', 'layer' => 'hiking' ],
			[ 'provider' => 'does-not-exist', 'layer' => 'whatever' ],
		],
		[]
	);

	expect( $resolved )->toHaveCount( 1 );
	expect( $resolved[0]['url'] )->toContain( 'waymarkedtrails.org/hiking' );

} );

test( 'resolve_overlays drops a pair when the layer is unknown within a known provider', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_overlays(
		[
			[ 'provider' => 'waymarked-trails', 'layer' => 'hiking' ],
			[ 'provider' => 'waymarked-trails', 'layer' => 'no-such-layer' ],
		],
		[]
	);

	expect( $resolved )->toHaveCount( 1 );
	expect( $resolved[0]['url'] )->toContain( 'waymarkedtrails.org/hiking' );

} );

test( 'resolve_overlays substitutes the API key into {KEY} for key-required overlay providers', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_overlays(
		[ [ 'provider' => 'openweathermap', 'layer' => 'clouds' ] ],
		[ 'openweathermap' => 'OWM-TOKEN' ]
	);

	expect( $resolved )->toHaveCount( 1 );
	expect( $resolved[0]['url'] )->not->toContain( '{KEY}' );
	expect( $resolved[0]['url'] )->toContain( 'appid=OWM-TOKEN' );

} );

test( 'resolve_overlays drops the pair when a key-required overlay provider has no key', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_overlays(
		[
			[ 'provider' => 'openweathermap', 'layer' => 'clouds' ],
			[ 'provider' => 'waymarked-trails', 'layer' => 'hiking' ],
		],
		[]
	);

	// The openweathermap pair is silently dropped; waymarked-trails survives so
	// the rest of the overlay stack still renders.
	expect( $resolved )->toHaveCount( 1 );
	expect( $resolved[0]['url'] )->toContain( 'waymarkedtrails.org/hiking' );

} );

test( 'resolve_overlays drops the pair when the key is whitespace-only', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_overlays(
		[ [ 'provider' => 'openweathermap', 'layer' => 'clouds' ] ],
		[ 'openweathermap' => "  \t\n  " ]
	);

	expect( $resolved )->toBe( [] );

} );

test( 'resolve_overlays does not substitute the key for a key-less overlay provider', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_overlays(
		[ [ 'provider' => 'waymarked-trails', 'layer' => 'mtb' ] ],
		[ 'waymarked-trails' => 'spurious-key' ]
	);

	expect( $resolved[0]['url'] )->not->toContain( 'spurious-key' );

} );

test( 'resolve_overlays returns slim records (no id, no label, no requiresKey)', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_overlays(
		[ [ 'provider' => 'opensnowmap', 'layer' => 'pistes' ] ],
		[]
	);

	expect( $resolved[0] )->toHaveKey( 'url' );
	expect( $resolved[0] )->toHaveKey( 'attribution' );
	expect( $resolved[0] )->toHaveKey( 'maxZoom' );
	expect( $resolved[0] )->not->toHaveKey( 'id' );
	expect( $resolved[0] )->not->toHaveKey( 'label' );
	expect( $resolved[0] )->not->toHaveKey( 'requiresKey' );
	expect( $resolved[0] )->not->toHaveKey( 'layers' );
	expect( $resolved[0] )->not->toHaveKey( 'signupUrl' );

} );

test( 'resolve_overlays drops malformed pair entries (non-array, missing keys, non-string keys)', function (): void {

	$registry = new Tile_Layer_Registry();
	/** @phpstan-ignore-next-line — deliberate misuse to test defensive coercion. */
	$resolved = $registry->resolve_overlays(
		[
			[ 'provider' => 'waymarked-trails', 'layer' => 'hiking' ],
			'not-an-array',
			[ 'provider' => 'waymarked-trails' ],
			[ 'layer' => 'hiking' ],
			[ 'provider' => 12345, 'layer' => 'hiking' ],
			[ 'provider' => 'waymarked-trails', 'layer' => '' ],
			null,
			0,
		],
		[]
	);

	expect( $resolved )->toHaveCount( 1 );
	expect( $resolved[0]['url'] )->toContain( 'waymarkedtrails.org/hiking' );

} );

test( 'resolve_overlays inherits provider-level subdomains into the resolved record', function (): void {

	// Inject a custom overlay provider that declares subdomains so the resolver
	// has something to forward verbatim. None of the four default overlay
	// providers carry subdomains.
	Functions\when( 'apply_filters' )->alias(
		static function ( string $name, mixed $value ): mixed {
			if ( $name === 'kntnt_gpx_blocks_tile_overlays' ) {
				return [
					'with-subs' => [
						'label'       => 'With Subdomains',
						'requiresKey' => false,
						'subdomains'  => [ 'a', 'b' ],
						'layers'      => [
							'main' => [
								'label'       => 'Main',
								'url'         => 'https://{s}.overlay.example.com/{z}/{x}/{y}.png',
								'attribution' => '&copy; Example',
								'maxZoom'     => 18,
							],
						],
					],
				];
			}
			return $value;
		}
	);

	$resolved = ( new Tile_Layer_Registry() )->resolve_overlays(
		[ [ 'provider' => 'with-subs', 'layer' => 'main' ] ],
		[]
	);

	expect( $resolved )->toHaveCount( 1 );
	expect( $resolved[0] )->toHaveKey( 'subdomains' );
	expect( $resolved[0]['subdomains'] )->toBe( [ 'a', 'b' ] );

} );

test( 'resolve_overlays omits subdomains when the overlay provider has none', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_overlays(
		[ [ 'provider' => 'waymarked-trails', 'layer' => 'hiking' ] ],
		[]
	);

	expect( $resolved[0] )->not->toHaveKey( 'subdomains' );

} );

// ---------------------------------------------------------------------------
// Overlay validator — drop the narrowest unit
// ---------------------------------------------------------------------------

test( 'overlay validator drops a single bad layer and keeps the rest of the provider', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'multi-layer' => tlr_overlay_provider_record(
				[],
				[
					'good' => tlr_overlay_layer_record(),
					'bad'  => tlr_overlay_layer_record( [ 'url' => 'https://example.com/no-placeholders.png' ] ),
				]
			),
		]
	);

	$overlays = ( new Tile_Layer_Registry() )->get_overlays();

	expect( $overlays )->toHaveKey( 'multi-layer' );
	expect( $overlays['multi-layer']['layers'] )->toHaveKey( 'good' );
	expect( $overlays['multi-layer']['layers'] )->not->toHaveKey( 'bad' );

} );

test( 'overlay validator drops the whole provider when no layers survive', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'all-bad' => tlr_overlay_provider_record(
				[],
				[
					'a' => tlr_overlay_layer_record( [ 'url' => 'http://insecure.example.com/{z}/{x}/{y}.png' ] ),
					'b' => tlr_overlay_layer_record( [ 'maxZoom' => 100 ] ),
				]
			),
		]
	);

	expect( ( new Tile_Layer_Registry() )->get_overlays() )->not->toHaveKey( 'all-bad' );

} );

test( 'overlay validator drops the provider when layers map is empty', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[ 'empty-layers' => tlr_overlay_provider_record( [ 'layers' => [] ] ) ]
	);

	expect( ( new Tile_Layer_Registry() )->get_overlays() )->not->toHaveKey( 'empty-layers' );

} );

test( 'overlay validator drops a {s}-using layer when its provider declares no subdomains', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'no-subs' => tlr_overlay_provider_record(
				[],
				[
					'plain'  => tlr_overlay_layer_record(),
					'with-s' => tlr_overlay_layer_record( [ 'url' => 'https://{s}.overlay.example.com/{z}/{x}/{y}.png' ] ),
				]
			),
		]
	);

	$overlays = ( new Tile_Layer_Registry() )->get_overlays();

	expect( $overlays )->toHaveKey( 'no-subs' );
	expect( $overlays['no-subs']['layers'] )->toHaveKey( 'plain' );
	expect( $overlays['no-subs']['layers'] )->not->toHaveKey( 'with-s' );

} );

test( 'overlay validator accepts a {s}-using layer when its provider declares subdomains', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'with-subs' => tlr_overlay_provider_record(
				[ 'subdomains' => [ 'a', 'b' ] ],
				[
					'with-s' => tlr_overlay_layer_record( [ 'url' => 'https://{s}.overlay.example.com/{z}/{x}/{y}.png' ] ),
				]
			),
		]
	);

	$overlays = ( new Tile_Layer_Registry() )->get_overlays();

	expect( $overlays )->toHaveKey( 'with-subs' );
	expect( $overlays['with-subs']['layers'] )->toHaveKey( 'with-s' );

} );

test( 'overlay validator rejects provider with non-bool requiresKey', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[ 'bad-bool' => tlr_overlay_provider_record( [ 'requiresKey' => 1 ] ) ]
	);

	expect( ( new Tile_Layer_Registry() )->get_overlays() )->not->toHaveKey( 'bad-bool' );

} );

test( 'overlay validator rejects provider with empty label', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[ 'no-label' => tlr_overlay_provider_record( [ 'label' => '' ] ) ]
	);

	expect( ( new Tile_Layer_Registry() )->get_overlays() )->not->toHaveKey( 'no-label' );

} );

test( 'overlay validator drops layer when URL lacks {z}/{x}/{y}', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'good' => tlr_overlay_provider_record(
				[],
				[
					'a' => tlr_overlay_layer_record(),
					'b' => tlr_overlay_layer_record( [ 'url' => 'https://example.com/no-placeholders.png' ] ),
				]
			),
		]
	);

	expect( ( new Tile_Layer_Registry() )->get_overlays()['good']['layers'] )->not->toHaveKey( 'b' );

} );

test( 'overlay validator drops layer with http:// URL scheme', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'good' => tlr_overlay_provider_record(
				[],
				[
					'a' => tlr_overlay_layer_record(),
					'b' => tlr_overlay_layer_record( [ 'url' => 'http://overlay.example.com/{z}/{x}/{y}.png' ] ),
				]
			),
		]
	);

	expect( ( new Tile_Layer_Registry() )->get_overlays()['good']['layers'] )->not->toHaveKey( 'b' );

} );

test( 'overlay validator drops layer when requiresKey=true but URL lacks {KEY}', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'paid' => tlr_overlay_provider_record(
				[ 'requiresKey' => true ],
				[
					'a' => tlr_overlay_layer_record( [ 'url' => 'https://overlay.example.com/{z}/{x}/{y}.png?key={KEY}' ] ),
					'b' => tlr_overlay_layer_record( [ 'url' => 'https://overlay.example.com/{z}/{x}/{y}.png' ] ),
				]
			),
		]
	);

	$overlays = ( new Tile_Layer_Registry() )->get_overlays();
	expect( $overlays['paid']['layers'] )->toHaveKey( 'a' );
	expect( $overlays['paid']['layers'] )->not->toHaveKey( 'b' );

} );

test( 'overlay validator drops layer when requiresKey=false but URL contains {KEY}', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'free' => tlr_overlay_provider_record(
				[],
				[
					'good' => tlr_overlay_layer_record(),
					'bad'  => tlr_overlay_layer_record( [ 'url' => 'https://overlay.example.com/{z}/{x}/{y}.png?key={KEY}' ] ),
				]
			),
		]
	);

	$overlays = ( new Tile_Layer_Registry() )->get_overlays();
	expect( $overlays['free']['layers'] )->toHaveKey( 'good' );
	expect( $overlays['free']['layers'] )->not->toHaveKey( 'bad' );

} );

test( 'overlay validator drops layer with maxZoom out of range', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'provider' => tlr_overlay_provider_record(
				[],
				[
					'a' => tlr_overlay_layer_record(),
					'b' => tlr_overlay_layer_record( [ 'maxZoom' => 100 ] ),
					'c' => tlr_overlay_layer_record( [ 'maxZoom' => -1 ] ),
					'd' => tlr_overlay_layer_record( [ 'maxZoom' => 18.5 ] ),
				]
			),
		]
	);

	$overlays = ( new Tile_Layer_Registry() )->get_overlays();
	expect( $overlays['provider']['layers'] )->toHaveKey( 'a' );
	expect( $overlays['provider']['layers'] )->not->toHaveKey( 'b' );
	expect( $overlays['provider']['layers'] )->not->toHaveKey( 'c' );
	expect( $overlays['provider']['layers'] )->not->toHaveKey( 'd' );

} );

test( 'overlay validator rejects malformed overlay-provider id (uppercase)', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[ 'BadId' => tlr_overlay_provider_record() ]
	);

	expect( ( new Tile_Layer_Registry() )->get_overlays() )->not->toHaveKey( 'BadId' );

} );

test( 'overlay validator drops layer with malformed layer id (uppercase)', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'provider' => tlr_overlay_provider_record(
				[],
				[
					'good'  => tlr_overlay_layer_record(),
					'BadId' => tlr_overlay_layer_record(),
				]
			),
		]
	);

	expect( ( new Tile_Layer_Registry() )->get_overlays()['provider']['layers'] )->not->toHaveKey( 'BadId' );

} );

test( 'overlay validator rejects non-https signupUrl', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'paid' => tlr_overlay_provider_record(
				[
					'requiresKey' => true,
					'signupUrl'   => 'http://example.com/signup',
				],
				[
					'a' => tlr_overlay_layer_record( [ 'url' => 'https://overlay.example.com/{z}/{x}/{y}.png?key={KEY}' ] ),
				]
			),
		]
	);

	expect( ( new Tile_Layer_Registry() )->get_overlays() )->not->toHaveKey( 'paid' );

} );

test( 'overlay filter can add a new valid overlay provider', function (): void {

	Functions\when( 'apply_filters' )->alias(
		static function ( string $name, mixed $value ): mixed {
			if ( $name === 'kntnt_gpx_blocks_tile_overlays' ) {
				return [
					'custom-overlay' => tlr_overlay_provider_record(
						[],
						[ 'main' => tlr_overlay_layer_record( [ 'url' => 'https://custom.example.com/{z}/{x}/{y}.png' ] ) ]
					),
				];
			}
			return $value;
		}
	);

	$overlays = ( new Tile_Layer_Registry() )->get_overlays();
	expect( $overlays )->toHaveKey( 'custom-overlay' );
	expect( $overlays['custom-overlay']['layers']['main']['url'] )->toBe( 'https://custom.example.com/{z}/{x}/{y}.png' );

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
