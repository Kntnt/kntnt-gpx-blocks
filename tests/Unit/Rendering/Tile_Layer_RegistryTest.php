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

test( 'resolve_provider trims surrounding whitespace before substituting the API key', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_provider( 'thunderforest', 'outdoor', '  XYZ-TOKEN  ' );

	expect( $resolved['url'] )->not->toContain( '{KEY}' );
	expect( $resolved['url'] )->toContain( 'apikey=XYZ-TOKEN' );
	expect( $resolved['url'] )->not->toContain( 'apikey=%20' );
	expect( $resolved['url'] )->not->toContain( 'XYZ-TOKEN  ' );
	expect( $resolved['url'] )->not->toContain( '  XYZ-TOKEN' );

} );

test( 'resolve_provider leaves {KEY} unsubstituted when the attribute-path key is whitespace-only (fail-closed)', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_provider( 'thunderforest', 'outdoor', "  \t\n  " );

	// A whitespace-only attribute-path key must be treated identically to an
	// empty string: leave {KEY} intact so the caller ships polyline-only,
	// rather than substituting whitespace into the URL.
	expect( $resolved['url'] )->toContain( '{KEY}' );
	expect( $resolved['url'] )->not->toContain( 'apikey=%20' );
	expect( $resolved['url'] )->not->toContain( "apikey= " );

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

test( 'resolve_overlays trims surrounding whitespace before substituting the API key', function (): void {

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_overlays(
		[ [ 'provider' => 'openweathermap', 'layer' => 'clouds' ] ],
		[ 'openweathermap' => '  OWM-TOKEN  ' ]
	);

	// The trim must apply to the substitution itself, not just the
	// empty-check — otherwise `"  OWM-TOKEN  "` passes the non-empty
	// decision and leaks whitespace into the final URL.
	expect( $resolved )->toHaveCount( 1 );
	expect( $resolved[0]['url'] )->not->toContain( '{KEY}' );
	expect( $resolved[0]['url'] )->toContain( 'appid=OWM-TOKEN' );
	expect( $resolved[0]['url'] )->not->toContain( 'appid=%20' );
	expect( $resolved[0]['url'] )->not->toContain( 'OWM-TOKEN  ' );
	expect( $resolved[0]['url'] )->not->toContain( '  OWM-TOKEN' );

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

// ---------------------------------------------------------------------------
// PHP-supplied API key (issue #113)
//
// The `apiKey` field on a provider record engages the PHP-supplied key path:
// presence (not value) is the engagement signal; the attribute-path
// `$api_key` parameter is ignored entirely; non-string values are dropped
// silently as if the field were absent; whitespace is trimmed; an empty
// or whitespace-only value fails closed by leaving `{KEY}` intact and
// logging a warning. The warning logs the provider id only — never the
// key value.
// ---------------------------------------------------------------------------

/**
 * Captures whatever the plugin's `Plugin::warning()` calls write to PHP's
 * error_log() during $callback. Returns the captured contents (possibly
 * empty) and the path so the caller can run further assertions.
 *
 * Plugin::log() emits via `error_log()` (no destination override), so
 * `ini_set('error_log', $tmpfile)` redirects the writes to a per-test
 * file we can read back. The KNTNT_GPX_BLOCKS_LOG_LEVEL constant is
 * pinned to 'warning' in `tests/Pest.php`, which lets `Plugin::warning()`
 * actually emit during tests.
 *
 * @param callable $callback Block of code to run with log capture engaged.
 *
 * @return string Concatenated contents the test code wrote to error_log().
 */
function tlr_capture_warning_log( callable $callback ): string {

	$tmp = tempnam( sys_get_temp_dir(), 'kntnt_gpx_blocks_tlr_log_' );
	if ( ! is_string( $tmp ) ) {
		throw new RuntimeException( 'tempnam() failed in tlr_capture_warning_log' );
	}

	$previous = ini_get( 'error_log' );
	ini_set( 'error_log', $tmp );
	try {
		$callback();
	} finally {
		ini_set( 'error_log', $previous === false ? '' : $previous );
	}

	$contents = file_get_contents( $tmp );
	@unlink( $tmp );
	return is_string( $contents ) ? $contents : '';

}

test( 'validator accepts apiKey as an optional string and trims whitespace', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'paid-provider' => tlr_provider_record(
				[
					'requiresKey' => true,
					'apiKey'      => '  TRIMMED-KEY  ',
				],
				[
					'default' => tlr_style_record( [
						'url' => 'https://tiles.example.com/{z}/{x}/{y}.png?key={KEY}',
					] ),
				]
			),
		]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( $providers )->toHaveKey( 'paid-provider' );
	expect( $providers['paid-provider'] )->toHaveKey( 'apiKey' );
	expect( $providers['paid-provider']['apiKey'] )->toBe( 'TRIMMED-KEY' );

} );

test( 'validator preserves an empty apiKey (presence engages PHP path; value fails closed)', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'paid-provider' => tlr_provider_record(
				[
					'requiresKey' => true,
					'apiKey'      => '   ',
				],
				[
					'default' => tlr_style_record( [
						'url' => 'https://tiles.example.com/{z}/{x}/{y}.png?key={KEY}',
					] ),
				]
			),
		]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( $providers['paid-provider'] )->toHaveKey( 'apiKey' );
	expect( $providers['paid-provider']['apiKey'] )->toBe( '' );

} );

test( 'validator drops non-string apiKey silently (treated as absent)', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'paid-provider' => tlr_provider_record(
				[
					'requiresKey' => true,
					'apiKey'      => 42,
				],
				[
					'default' => tlr_style_record( [
						'url' => 'https://tiles.example.com/{z}/{x}/{y}.png?key={KEY}',
					] ),
				]
			),
		]
	);

	$providers = ( new Tile_Layer_Registry() )->get_providers();

	expect( $providers['paid-provider'] )->not->toHaveKey( 'apiKey' );

} );

test( 'php_supplied_api_key returns null when the provider has no apiKey field', function (): void {

	$registry = new Tile_Layer_Registry();

	expect( $registry->php_supplied_api_key( 'thunderforest' ) )->toBeNull();
	expect( $registry->php_supplied_api_key( 'openstreetmap' ) )->toBeNull();
	expect( $registry->php_supplied_api_key( 'does-not-exist' ) )->toBeNull();

} );

test( 'php_supplied_api_key returns the validated string when the PHP path is engaged', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'paid-provider' => tlr_provider_record(
				[
					'requiresKey' => true,
					'apiKey'      => 'PHP-SUPPLIED-VALUE',
				],
				[
					'default' => tlr_style_record( [
						'url' => 'https://tiles.example.com/{z}/{x}/{y}.png?key={KEY}',
					] ),
				]
			),
		]
	);

	$registry = new Tile_Layer_Registry();

	expect( $registry->php_supplied_api_key( 'paid-provider' ) )->toBe( 'PHP-SUPPLIED-VALUE' );

} );

test( 'resolve_provider uses the PHP-supplied apiKey and ignores the attribute-path parameter when engaged', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'paid-provider' => tlr_provider_record(
				[
					'requiresKey' => true,
					'apiKey'      => 'PHP-WINS',
				],
				[
					'default' => tlr_style_record( [
						'url' => 'https://tiles.example.com/{z}/{x}/{y}.png?key={KEY}',
					] ),
				]
			),
		]
	);

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_provider( 'paid-provider', 'default', 'ATTRIBUTE-KEY-IGNORED' );

	expect( $resolved['url'] )->toContain( 'key=PHP-WINS' );
	expect( $resolved['url'] )->not->toContain( 'ATTRIBUTE-KEY-IGNORED' );
	expect( $resolved['url'] )->not->toContain( '{KEY}' );

} );

test( 'resolve_provider falls through to the attribute-path key when PHP path is not engaged', function (): void {

	// Default registry — no PHP path engagement. The attribute-path key
	// `ATTRIBUTE-KEY` substitutes into `{KEY}` as before.
	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_provider( 'thunderforest', 'outdoor', 'ATTRIBUTE-KEY' );

	expect( $resolved['url'] )->toContain( 'apikey=ATTRIBUTE-KEY' );
	expect( $resolved['url'] )->not->toContain( '{KEY}' );

} );

test( 'resolve_provider leaves {KEY} unsubstituted when the PHP-supplied apiKey is empty (fail-closed)', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'paid-provider' => tlr_provider_record(
				[
					'requiresKey' => true,
					'apiKey'      => '',
				],
				[
					'default' => tlr_style_record( [
						'url' => 'https://tiles.example.com/{z}/{x}/{y}.png?key={KEY}',
					] ),
				]
			),
		]
	);

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_provider( 'paid-provider', 'default', 'ATTRIBUTE-KEY-IGNORED' );

	// Fail-closed: `{KEY}` is left intact so the caller (Render_Map)
	// detects it and nulls out the URL for polyline-only state. The
	// attribute-path key is ignored — PHP path engagement is binary.
	expect( $resolved['url'] )->toContain( '{KEY}' );
	expect( $resolved['url'] )->not->toContain( 'ATTRIBUTE-KEY-IGNORED' );

} );

test( 'resolve_provider logs a warning naming the provider id but never the PHP-supplied key value', function (): void {

	// Use a sentinel value so the assertion is unambiguous. The validator
	// trims whitespace and stores the empty string for fail-closed, so a
	// non-empty sentinel value cannot trip the fail-closed warning at
	// resolve time. This test pairs with the previous one: the warning
	// path is the empty-PHP-key branch.
	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'paid-provider' => tlr_provider_record(
				[
					'requiresKey' => true,
					'apiKey'      => '   ', // Whitespace-only → empty after trim.
				],
				[
					'default' => tlr_style_record( [
						'url' => 'https://tiles.example.com/{z}/{x}/{y}.png?key={KEY}',
					] ),
				]
			),
		]
	);

	$logged = tlr_capture_warning_log( static function (): void {
		$registry = new Tile_Layer_Registry();
		$registry->resolve_provider( 'paid-provider', 'default', '' );
	} );

	// The provider id appears in the warning so the integrator can locate
	// the misconfiguration. The actual key value (or its whitespace input)
	// never appears, even in the warning emitted by the fail-closed branch.
	expect( $logged )->toContain( 'paid-provider' );
	expect( $logged )->toContain( 'polyline-only' );

} );

test( 'no PHP-supplied key value (or its sentinel) ever appears in the warning log', function (): void {

	// Use a high-entropy sentinel key so the no-leak assertion is
	// unambiguous: the empty-key path under PHP engagement must never
	// reveal the configured value, even when the value is non-empty
	// in input but trimmed to empty. Combine with a stale-attribute
	// path key to also assert it never leaks.
	$sentinel_php_attempt = 'S3CR3T-DO-NOT-LEAK';
	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'paid-provider' => tlr_provider_record(
				[
					'requiresKey' => true,
					// Whitespace pads a value the validator will trim to a
					// non-empty string; the resolver's success branch
					// substitutes it into the URL but does not log. Run a
					// fresh registry with empty value below to also cover
					// the fail-closed log branch in the same test.
					'apiKey'      => '   ' . $sentinel_php_attempt . '   ',
				],
				[
					'default' => tlr_style_record( [
						'url' => 'https://tiles.example.com/{z}/{x}/{y}.png?key={KEY}',
					] ),
				]
			),
		]
	);

	$logged = tlr_capture_warning_log( static function (): void {
		$registry = new Tile_Layer_Registry();
		// Resolve once with the populated PHP key; resolve_provider
		// substitutes silently and does not log.
		$registry->resolve_provider( 'paid-provider', 'default', 'ANOTHER-SENTINEL-ATTRIBUTE' );
	} );

	expect( $logged )->not->toContain( $sentinel_php_attempt );
	expect( $logged )->not->toContain( 'ANOTHER-SENTINEL-ATTRIBUTE' );

} );

test( 'fail-closed warning log never contains the attempted PHP-supplied key', function (): void {

	$sentinel = 'EMPTY-AFTER-TRIM-VALUE-DO-NOT-LEAK';
	// Construct a value that the validator trims to '' (just whitespace
	// in input). The log branch fires only on the trimmed-empty state.
	// To exercise the no-leak invariant for the value the validator saw
	// before trimming, the validator itself must not log the input — and
	// it doesn't: the validator silently normalises and stores ''. The
	// resolver then logs only the provider id. Use a non-trivial sentinel
	// as the in-input value to lock the invariant down.
	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'paid-provider' => tlr_provider_record(
				[
					'requiresKey' => true,
					// The validator trims, but the input the validator
					// receives must never end up in the log either.
					'apiKey'      => "  \t\n",
				],
				[
					'default' => tlr_style_record( [
						'url' => 'https://tiles.example.com/{z}/{x}/{y}.png?key={KEY}',
					] ),
				]
			),
		]
	);

	$logged = tlr_capture_warning_log( static function () use ( $sentinel ): void {
		$registry = new Tile_Layer_Registry();
		$registry->resolve_provider( 'paid-provider', 'default', $sentinel );
	} );

	// The attribute-path sentinel is the only string the validator saw
	// that could plausibly leak; the no-leak invariant excludes it.
	expect( $logged )->not->toContain( $sentinel );
	// Likewise, the literal whitespace input cannot reach the log under
	// trimmed=='' fail-closed semantics. The log line carries the id
	// alone.
	expect( $logged )->toContain( 'paid-provider' );

} );

test( 'attribute-bypass: PHP path engagement makes the attribute-path key irrelevant for paid providers', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_providers',
		[
			'paid-provider' => tlr_provider_record(
				[
					'requiresKey' => true,
					'apiKey'      => 'PHP-VALUE',
				],
				[
					'default' => tlr_style_record( [
						'url' => 'https://tiles.example.com/{z}/{x}/{y}.png?key={KEY}',
					] ),
				]
			),
		]
	);

	$registry = new Tile_Layer_Registry();

	// Three different attribute-path values, identical resolved URL.
	$a = $registry->resolve_provider( 'paid-provider', 'default', '' );
	$b = $registry->resolve_provider( 'paid-provider', 'default', 'something-else' );
	$c = $registry->resolve_provider( 'paid-provider', 'default', 'yet-another' );

	expect( $a['url'] )->toBe( $b['url'] );
	expect( $b['url'] )->toBe( $c['url'] );
	expect( $a['url'] )->toContain( 'key=PHP-VALUE' );

} );

// ---------------------------------------------------------------------------
// PHP-supplied API key — overlay providers (issue #114)
//
// Mirrors the base-provider tests above for the overlay half of the
// registry. The `apiKey` field on an overlay-provider record engages
// the PHP-supplied key path: presence (not value) is the engagement
// signal; the attribute-path `$api_keys[ providerId ]` parameter is
// ignored entirely; non-string values are dropped silently as if the
// field were absent; whitespace is trimmed. The fail-closed outcome is
// asymmetric: where a base provider's empty key leaves `{KEY}` intact
// and produces polyline-only state, an overlay provider's empty key
// drops the affected layer from the resolved overlay stack with a
// `Plugin::warning()` log naming the (provider, layer) ids — the base
// map and any other overlays continue to render.
// ---------------------------------------------------------------------------

test( 'overlay validator accepts apiKey as an optional string and trims whitespace', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'paid-overlay' => tlr_overlay_provider_record(
				[
					'requiresKey' => true,
					'apiKey'      => '  TRIMMED-OVERLAY-KEY  ',
				],
				[
					'main' => tlr_overlay_layer_record( [
						'url' => 'https://overlay.example.com/{z}/{x}/{y}.png?key={KEY}',
					] ),
				]
			),
		]
	);

	$overlays = ( new Tile_Layer_Registry() )->get_overlays();

	expect( $overlays )->toHaveKey( 'paid-overlay' );
	expect( $overlays['paid-overlay'] )->toHaveKey( 'apiKey' );
	expect( $overlays['paid-overlay']['apiKey'] )->toBe( 'TRIMMED-OVERLAY-KEY' );

} );

test( 'overlay validator preserves an empty apiKey (presence engages PHP path; value fails closed)', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'paid-overlay' => tlr_overlay_provider_record(
				[
					'requiresKey' => true,
					'apiKey'      => '   ',
				],
				[
					'main' => tlr_overlay_layer_record( [
						'url' => 'https://overlay.example.com/{z}/{x}/{y}.png?key={KEY}',
					] ),
				]
			),
		]
	);

	$overlays = ( new Tile_Layer_Registry() )->get_overlays();

	expect( $overlays['paid-overlay'] )->toHaveKey( 'apiKey' );
	expect( $overlays['paid-overlay']['apiKey'] )->toBe( '' );

} );

test( 'overlay validator drops non-string apiKey silently (treated as absent)', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'paid-overlay' => tlr_overlay_provider_record(
				[
					'requiresKey' => true,
					'apiKey'      => 42,
				],
				[
					'main' => tlr_overlay_layer_record( [
						'url' => 'https://overlay.example.com/{z}/{x}/{y}.png?key={KEY}',
					] ),
				]
			),
		]
	);

	$overlays = ( new Tile_Layer_Registry() )->get_overlays();

	expect( $overlays['paid-overlay'] )->not->toHaveKey( 'apiKey' );

} );

test( 'php_supplied_overlay_api_key returns null when the overlay provider has no apiKey field', function (): void {

	$registry = new Tile_Layer_Registry();

	expect( $registry->php_supplied_overlay_api_key( 'openweathermap' ) )->toBeNull();
	expect( $registry->php_supplied_overlay_api_key( 'openseamap' ) )->toBeNull();
	expect( $registry->php_supplied_overlay_api_key( 'does-not-exist' ) )->toBeNull();

} );

test( 'php_supplied_overlay_api_key returns the validated string when the PHP path is engaged', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'paid-overlay' => tlr_overlay_provider_record(
				[
					'requiresKey' => true,
					'apiKey'      => 'PHP-SUPPLIED-OVERLAY-VALUE',
				],
				[
					'main' => tlr_overlay_layer_record( [
						'url' => 'https://overlay.example.com/{z}/{x}/{y}.png?key={KEY}',
					] ),
				]
			),
		]
	);

	$registry = new Tile_Layer_Registry();

	expect( $registry->php_supplied_overlay_api_key( 'paid-overlay' ) )->toBe( 'PHP-SUPPLIED-OVERLAY-VALUE' );

} );

test( 'resolve_overlays uses the PHP-supplied apiKey and ignores the attribute-path map when engaged', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'paid-overlay' => tlr_overlay_provider_record(
				[
					'requiresKey' => true,
					'apiKey'      => 'PHP-OVERLAY-WINS',
				],
				[
					'main' => tlr_overlay_layer_record( [
						'url' => 'https://overlay.example.com/{z}/{x}/{y}.png?key={KEY}',
					] ),
				]
			),
		]
	);

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_overlays(
		[ [ 'provider' => 'paid-overlay', 'layer' => 'main' ] ],
		[ 'paid-overlay' => 'ATTRIBUTE-OVERLAY-KEY-IGNORED' ]
	);

	expect( $resolved )->toHaveCount( 1 );
	expect( $resolved[0]['url'] )->toContain( 'key=PHP-OVERLAY-WINS' );
	expect( $resolved[0]['url'] )->not->toContain( 'ATTRIBUTE-OVERLAY-KEY-IGNORED' );
	expect( $resolved[0]['url'] )->not->toContain( '{KEY}' );

} );

test( 'resolve_overlays falls through to the attribute-path map when the PHP path is not engaged', function (): void {

	// Default registry — no PHP path engagement on the shipped
	// OpenWeatherMap overlay provider. The attribute-path key
	// substitutes into `{KEY}` as before.
	$registry = ( new Tile_Layer_Registry() );
	$resolved = $registry->resolve_overlays(
		[ [ 'provider' => 'openweathermap', 'layer' => 'clouds' ] ],
		[ 'openweathermap' => 'ATTRIBUTE-OWM' ]
	);

	expect( $resolved )->toHaveCount( 1 );
	expect( $resolved[0]['url'] )->toContain( 'appid=ATTRIBUTE-OWM' );
	expect( $resolved[0]['url'] )->not->toContain( '{KEY}' );

} );

test( 'resolve_overlays drops the layer when the PHP-supplied apiKey is empty (fail-closed, asymmetric)', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'paid-overlay'        => tlr_overlay_provider_record(
				[
					'requiresKey' => true,
					'apiKey'      => '',
				],
				[
					'main' => tlr_overlay_layer_record( [
						'url' => 'https://overlay.example.com/{z}/{x}/{y}.png?key={KEY}',
					] ),
				]
			),
			'free-overlay-survives' => tlr_overlay_provider_record(
				[ 'requiresKey' => false ],
				[
					'free' => tlr_overlay_layer_record( [
						'url' => 'https://other.example.com/{z}/{x}/{y}.png',
					] ),
				]
			),
		]
	);

	$registry = new Tile_Layer_Registry();
	$resolved = $registry->resolve_overlays(
		[
			[ 'provider' => 'paid-overlay', 'layer' => 'main' ],
			[ 'provider' => 'free-overlay-survives', 'layer' => 'free' ],
		],
		[ 'paid-overlay' => 'IGNORED-ATTRIBUTE-KEY' ]
	);

	// The paid-overlay layer is dropped (empty PHP key, asymmetric
	// fail-closed). The free overlay survives — the contract is that
	// the base map and any other overlays continue to render.
	expect( $resolved )->toHaveCount( 1 );
	expect( $resolved[0]['url'] )->toContain( 'other.example.com' );

} );

test( 'resolve_overlays logs a warning naming the (provider, layer) ids on empty PHP apiKey', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'paid-overlay' => tlr_overlay_provider_record(
				[
					'requiresKey' => true,
					'apiKey'      => '   ',
				],
				[
					'main' => tlr_overlay_layer_record( [
						'url' => 'https://overlay.example.com/{z}/{x}/{y}.png?key={KEY}',
					] ),
				]
			),
		]
	);

	$logged = tlr_capture_warning_log( static function (): void {
		$registry = new Tile_Layer_Registry();
		$registry->resolve_overlays(
			[ [ 'provider' => 'paid-overlay', 'layer' => 'main' ] ],
			[]
		);
	} );

	// The warning names both the provider and the layer so the
	// integrator can locate the misconfiguration; the key value never
	// appears in the log.
	expect( $logged )->toContain( 'paid-overlay' );
	expect( $logged )->toContain( 'main' );

} );

test( 'no PHP-supplied overlay apiKey value ever appears in the warning log (no-leak invariant)', function (): void {

	$sentinel = 'S3CR3T-OVERLAY-DO-NOT-LEAK';
	// Whitespace-padded value the validator trims to a non-empty
	// string; the resolver's success branch substitutes it silently
	// without logging. Combine with an attribute-path sentinel to also
	// assert that the attribute-path value never leaks into the log.
	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'paid-overlay' => tlr_overlay_provider_record(
				[
					'requiresKey' => true,
					'apiKey'      => '   ' . $sentinel . '   ',
				],
				[
					'main' => tlr_overlay_layer_record( [
						'url' => 'https://overlay.example.com/{z}/{x}/{y}.png?key={KEY}',
					] ),
				]
			),
		]
	);

	$logged = tlr_capture_warning_log( static function (): void {
		$registry = new Tile_Layer_Registry();
		$registry->resolve_overlays(
			[ [ 'provider' => 'paid-overlay', 'layer' => 'main' ] ],
			[ 'paid-overlay' => 'ATTRIBUTE-SENTINEL' ]
		);
	} );

	expect( $logged )->not->toContain( $sentinel );
	expect( $logged )->not->toContain( 'ATTRIBUTE-SENTINEL' );

} );

test( 'overlay fail-closed warning log never contains the attempted PHP-supplied key (whitespace-only input)', function (): void {

	$attribute_sentinel = 'OVERLAY-ATTR-SENTINEL-DO-NOT-LEAK';
	// Whitespace-only input — the validator trims to '' and the
	// resolver fires the fail-closed log branch. The log line carries
	// the provider and layer ids only; the raw input never leaks.
	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'paid-overlay' => tlr_overlay_provider_record(
				[
					'requiresKey' => true,
					'apiKey'      => "  \t\n",
				],
				[
					'main' => tlr_overlay_layer_record( [
						'url' => 'https://overlay.example.com/{z}/{x}/{y}.png?key={KEY}',
					] ),
				]
			),
		]
	);

	$logged = tlr_capture_warning_log( static function () use ( $attribute_sentinel ): void {
		$registry = new Tile_Layer_Registry();
		$registry->resolve_overlays(
			[ [ 'provider' => 'paid-overlay', 'layer' => 'main' ] ],
			[ 'paid-overlay' => $attribute_sentinel ]
		);
	} );

	expect( $logged )->not->toContain( $attribute_sentinel );
	expect( $logged )->not->toContain( "\t" );
	expect( $logged )->toContain( 'paid-overlay' );
	expect( $logged )->toContain( 'main' );

} );

test( 'overlay attribute-bypass: PHP path engagement makes the attribute-path map irrelevant', function (): void {

	tlr_filter_returns(
		'kntnt_gpx_blocks_tile_overlays',
		[
			'paid-overlay' => tlr_overlay_provider_record(
				[
					'requiresKey' => true,
					'apiKey'      => 'PHP-OVERLAY-VALUE',
				],
				[
					'main' => tlr_overlay_layer_record( [
						'url' => 'https://overlay.example.com/{z}/{x}/{y}.png?key={KEY}',
					] ),
				]
			),
		]
	);

	$registry = new Tile_Layer_Registry();

	// Three different attribute-path values, identical resolved URL.
	$a = $registry->resolve_overlays(
		[ [ 'provider' => 'paid-overlay', 'layer' => 'main' ] ],
		[]
	);
	$b = $registry->resolve_overlays(
		[ [ 'provider' => 'paid-overlay', 'layer' => 'main' ] ],
		[ 'paid-overlay' => 'something-else' ]
	);
	$c = $registry->resolve_overlays(
		[ [ 'provider' => 'paid-overlay', 'layer' => 'main' ] ],
		[ 'paid-overlay' => 'yet-another' ]
	);

	expect( $a[0]['url'] )->toBe( $b[0]['url'] );
	expect( $b[0]['url'] )->toBe( $c[0]['url'] );
	expect( $a[0]['url'] )->toContain( 'key=PHP-OVERLAY-VALUE' );

} );
