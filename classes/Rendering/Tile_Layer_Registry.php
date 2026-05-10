<?php
/**
 * Server-side registry of Leaflet tile-layer providers and overlay layers.
 *
 * Owns the canonical list of base providers (OpenStreetMap, OpenTopoMap,
 * Thunderforest, Stadia Maps, MapTiler, Mapbox, …) and overlay layers
 * (Waymarked Trails Hiking) that the GPX Map block can choose between, and
 * exposes two public PHP filters — `kntnt_gpx_blocks_tile_providers` and
 * `kntnt_gpx_blocks_tile_overlays` — for site builders to add, replace, or
 * remove records. The registry is **PHP-canonical**: the JS view module reads
 * the resolved record from the per-block Interactivity state, never from a
 * JS-side registry.
 *
 * Validation is deterministic and runs at filter-application time. Every
 * surviving record is guaranteed to have an `https://` URL containing the
 * `{z}/{x}/{y}` placeholders; for base providers the `{KEY}` placeholder is
 * present iff `requiresKey === true`. Invalid records are dropped with a
 * `Plugin::warning()` log so a misconfigured filter callback fails loudly in
 * the log without breaking the page.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rendering;

use Kntnt\Gpx_Blocks\Plugin;

/**
 * Registry of base-tile providers and overlay layers for the GPX Map block.
 *
 * Caches the validated arrays per request so the filter chain runs once per
 * registry instance even when the registry is consulted multiple times during
 * a single render pass.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 *
 * @phpstan-type ProviderRecord array{
 *     label: string,
 *     url: string,
 *     attribution: string,
 *     maxZoom: int,
 *     requiresKey: bool,
 *     signupUrl?: string,
 *     subdomains?: list<string>,
 * }
 * @phpstan-type OverlayRecord array{
 *     label: string,
 *     url: string,
 *     attribution: string,
 *     maxZoom: int,
 *     subdomains?: list<string>,
 * }
 * @phpstan-type ResolvedProvider array{
 *     id: string,
 *     url: string,
 *     attribution: string,
 *     maxZoom: int,
 *     subdomains?: list<string>,
 * }
 * @phpstan-type ResolvedOverlay array{
 *     id: string,
 *     url: string,
 *     attribution: string,
 *     maxZoom: int,
 *     subdomains?: list<string>,
 * }
 */
final class Tile_Layer_Registry {

	/**
	 * Identifier for the canonical fallback provider.
	 *
	 * Always present in the validated provider list. `resolve_provider()`
	 * falls back to this id (with a warning log) when an unknown provider is
	 * requested or when, as a last resort, the configured filter has dropped
	 * every default record.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const FALLBACK_PROVIDER_ID = 'osm-standard';

	/**
	 * Regex matching a valid provider identifier.
	 *
	 * Lowercase letters, digits, and hyphens only — the same alphabet as the
	 * plugin's CSS class prefix and REST namespace segments.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const ID_REGEX = '/^[a-z0-9-]+$/';

	/**
	 * Hard upper bound on `maxZoom` accepted by the validator.
	 *
	 * Leaflet supports up to zoom 22 in practice. Values above this cap are
	 * rejected because no real tile provider serves them and they likely
	 * indicate a typo.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const MAX_ZOOM_LIMIT = 22;

	/**
	 * Cached, validated provider list for the current request.
	 *
	 * Lazily populated on the first call to `get_providers()`. `null` means
	 * "not yet resolved"; an empty array means "filter dropped every record"
	 * but is never observed in practice because the registry restores the
	 * fallback provider before returning.
	 *
	 * @since 1.0.0
	 * @var array<string, ProviderRecord>|null
	 */
	private ?array $providers = null;

	/**
	 * Cached, validated overlay list for the current request.
	 *
	 * Same lazy-resolution contract as `$providers`.
	 *
	 * @since 1.0.0
	 * @var array<string, OverlayRecord>|null
	 */
	private ?array $overlays = null;

	/**
	 * Returns the validated provider registry, applying the filter on first call.
	 *
	 * The result is keyed by provider id. Each value is the validated record
	 * with `label`, `url`, `attribution`, `maxZoom`, `requiresKey`, and the
	 * optional `signupUrl` and `subdomains` keys. The `{KEY}` placeholder is
	 * left as-is in the URL; substitution happens in `resolve_provider()`.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, ProviderRecord>
	 */
	public function get_providers(): array {

		// Return the cached result so the filter chain runs at most once.
		if ( $this->providers !== null ) {
			return $this->providers;
		}

		// Apply the filter, validate, and ensure the fallback survives.
		$raw           = apply_filters( 'kntnt_gpx_blocks_tile_providers', self::default_providers() );
		$valid         = self::validate_provider_set( is_array( $raw ) ? $raw : [] );
		$with_fallback = self::ensure_fallback_provider( $valid );

		$this->providers = $with_fallback;

		return $this->providers;

	}

	/**
	 * Returns the validated overlay registry, applying the filter on first call.
	 *
	 * Result shape mirrors `get_providers()`, minus `requiresKey` and
	 * `signupUrl` — overlays in v1 do not carry an API key.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, OverlayRecord>
	 */
	public function get_overlays(): array {

		if ( $this->overlays !== null ) {
			return $this->overlays;
		}

		$raw   = apply_filters( 'kntnt_gpx_blocks_tile_overlays', self::default_overlays() );
		$valid = self::validate_overlay_set( is_array( $raw ) ? $raw : [] );

		$this->overlays = $valid;

		return $this->overlays;

	}

	/**
	 * Resolves a saved provider id to a runtime tile-layer record.
	 *
	 * Returns the validated record with `{KEY}` substituted for the supplied
	 * API key. Unknown ids fall back silently to the canonical OSM provider
	 * with a `Plugin::warning()` log so the rendered map keeps working while
	 * the misconfiguration is surfaced.
	 *
	 * The returned record carries an `id` key in addition to the validated
	 * provider fields so the caller can write it into Interactivity state
	 * without re-deriving the id from the surrounding context.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id      Provider id from saved block attributes.
	 * @param string $api_key Per-block tile API key (empty when not required).
	 *
	 * @return ResolvedProvider
	 */
	public function resolve_provider( string $id, string $api_key ): array {

		// Look up the validated record; fall back to OSM on any miss. The
		// fallback is guaranteed to exist by `ensure_fallback_provider()`.
		$providers   = $this->get_providers();
		$record      = $providers[ $id ] ?? null;
		$resolved_id = $id;
		if ( $record === null ) {
			Plugin::warning(
				sprintf( 'Tile_Layer_Registry: unknown tile provider "%s"; falling back to %s.', $id, self::FALLBACK_PROVIDER_ID )
			);
			$resolved_id = self::FALLBACK_PROVIDER_ID;
			$record      = $providers[ self::FALLBACK_PROVIDER_ID ];
		}

		// Substitute the API key into the URL when the provider requires one.
		// `requiresKey === true` guarantees the URL contains a literal `{KEY}`
		// (validator contract); when it is false, the URL has no `{KEY}` and
		// `str_replace()` is a no-op.
		$url = $record['url'];
		if ( $record['requiresKey'] ) {
			$url = str_replace( '{KEY}', $api_key, $url );
		}

		// Compose the runtime record. Every field that survives validation is
		// passed through verbatim except for the substituted URL and the
		// embedded id — `subdomains` survives only when present in the
		// validated record so the JS side can branch on it.
		$out = [
			'id'          => $resolved_id,
			'url'         => $url,
			'attribution' => $record['attribution'],
			'maxZoom'     => $record['maxZoom'],
		];
		if ( isset( $record['subdomains'] ) ) {
			$out['subdomains'] = $record['subdomains'];
		}

		return $out;

	}

	/**
	 * Resolves a list of saved overlay ids to runtime tile-layer records.
	 *
	 * Unknown ids are dropped silently with a `Plugin::warning()` log per id.
	 * The returned array preserves the input order so the JS view module can
	 * stack overlays in the same order the editor configured them.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, mixed> $ids Overlay ids from saved block attributes.
	 *                               `mixed` because block attributes are
	 *                               JSON-decoded; per-entry coercion happens
	 *                               inside the loop.
	 *
	 * @return list<ResolvedOverlay>
	 */
	public function resolve_overlays( array $ids ): array {

		$overlays = $this->get_overlays();
		$out      = [];

		foreach ( $ids as $id ) {

			// Coerce defensively — block attributes are JSON-decoded mixed.
			if ( ! is_string( $id ) || '' === $id ) {
				continue;
			}

			$record = $overlays[ $id ] ?? null;
			if ( $record === null ) {
				Plugin::warning(
					sprintf( 'Tile_Layer_Registry: unknown tile overlay "%s"; dropping.', $id )
				);
				continue;
			}

			// Compose the runtime record. Overlays never substitute a key in v1.
			$entry = [
				'id'          => $id,
				'url'         => $record['url'],
				'attribution' => $record['attribution'],
				'maxZoom'     => $record['maxZoom'],
			];
			if ( isset( $record['subdomains'] ) ) {
				$entry['subdomains'] = $record['subdomains'];
			}
			$out[] = $entry;

		}

		return $out;

	}

	/**
	 * Validates a raw provider set as supplied by the filter.
	 *
	 * Walks the input, drops every record that fails the validator, and
	 * returns the surviving records keyed by id. The id is taken from the
	 * array key when the key is a non-empty string; otherwise the record is
	 * rejected (numeric keys cannot satisfy the `^[a-z0-9-]+$` rule).
	 *
	 * @since 1.0.0
	 *
	 * @param array<int|string, mixed> $raw Raw provider set from the filter.
	 *
	 * @return array<string, ProviderRecord>
	 */
	private static function validate_provider_set( array $raw ): array {

		$out = [];

		foreach ( $raw as $id => $record ) {

			if ( ! is_string( $id ) || ! is_array( $record ) ) {
				Plugin::warning(
					'Tile_Layer_Registry: provider record dropped — key must be a string id and value must be an array.'
				);
				continue;
			}

			if ( ! self::is_valid_id( $id ) ) {
				Plugin::warning(
					sprintf( 'Tile_Layer_Registry: provider id "%s" rejected — must match %s.', $id, self::ID_REGEX )
				);
				continue;
			}

			$normalised = self::validate_and_normalise_provider_record( $id, $record );
			if ( $normalised === null ) {
				continue;
			}

			$out[ $id ] = $normalised;

		}

		return $out;

	}

	/**
	 * Validates a raw overlay set as supplied by the filter.
	 *
	 * Same contract as `validate_provider_set()` but with the overlay record
	 * shape.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int|string, mixed> $raw Raw overlay set from the filter.
	 *
	 * @return array<string, OverlayRecord>
	 */
	private static function validate_overlay_set( array $raw ): array {

		$out = [];

		foreach ( $raw as $id => $record ) {

			if ( ! is_string( $id ) || ! is_array( $record ) ) {
				Plugin::warning(
					'Tile_Layer_Registry: overlay record dropped — key must be a string id and value must be an array.'
				);
				continue;
			}

			if ( ! self::is_valid_id( $id ) ) {
				Plugin::warning(
					sprintf( 'Tile_Layer_Registry: overlay id "%s" rejected — must match %s.', $id, self::ID_REGEX )
				);
				continue;
			}

			$normalised = self::validate_and_normalise_overlay_record( $id, $record );
			if ( $normalised === null ) {
				continue;
			}

			$out[ $id ] = $normalised;

		}

		return $out;

	}

	/**
	 * Validates and normalises a single base-provider record.
	 *
	 * Performs the per-field type checks documented in `docs/hooks.md` and
	 * returns the canonical typed shape on success, or `null` when the record
	 * is rejected. Rejection emits a `Plugin::warning()` log naming the
	 * offending id and the failing constraint so the integrator can find
	 * their mistake quickly.
	 *
	 * @since 1.0.0
	 *
	 * @param string                  $id     Provider id (already shape-validated).
	 * @param array<int|string, mixed> $record Record to validate.
	 *
	 * @return ProviderRecord|null
	 */
	private static function validate_and_normalise_provider_record( string $id, array $record ): ?array {

		// label, url, attribution must be non-empty strings.
		$label = $record['label'] ?? null;
		if ( ! is_string( $label ) || '' === $label ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" rejected — label must be a non-empty string.', $id ) );
			return null;
		}
		$url = $record['url'] ?? null;
		if ( ! is_string( $url ) || '' === $url ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" rejected — url must be a non-empty string.', $id ) );
			return null;
		}
		$attribution = $record['attribution'] ?? null;
		if ( ! is_string( $attribution ) || '' === $attribution ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" rejected — attribution must be a non-empty string.', $id ) );
			return null;
		}

		// URL scheme must be https; relative or http URLs leak visitor IPs without TLS.
		if ( ! str_starts_with( $url, 'https://' ) ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" rejected — url must start with https://.', $id ) );
			return null;
		}

		// URL must contain Leaflet's tile-coordinate placeholders verbatim.
		if ( ! self::url_has_xyz_placeholders( $url ) ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" rejected — url must contain {z}, {x}, and {y} placeholders.', $id ) );
			return null;
		}

		// requiresKey must be a bool, and the URL contains {KEY} iff requiresKey is true.
		$requires_key = $record['requiresKey'] ?? null;
		if ( ! is_bool( $requires_key ) ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" rejected — requiresKey must be a bool.', $id ) );
			return null;
		}
		$has_key_placeholder = str_contains( $url, '{KEY}' );
		if ( $requires_key && ! $has_key_placeholder ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" rejected — requiresKey is true but url has no {KEY} placeholder.', $id ) );
			return null;
		}
		if ( ! $requires_key && $has_key_placeholder ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" rejected — url has {KEY} placeholder but requiresKey is false.', $id ) );
			return null;
		}

		// maxZoom must be an int in [0, MAX_ZOOM_LIMIT].
		$max_zoom = $record['maxZoom'] ?? null;
		if ( ! self::is_valid_max_zoom( $max_zoom ) ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" rejected — maxZoom must be int in [0, %d].', $id, self::MAX_ZOOM_LIMIT ) );
			return null;
		}

		// Compose the canonical typed shape. Optional fields are added only
		// when they pass their per-field validators below.
		$out = [
			'label'       => $label,
			'url'         => $url,
			'attribution' => $attribution,
			'maxZoom'     => $max_zoom,
			'requiresKey' => $requires_key,
		];

		// Optional signupUrl, when present, must be an https string.
		if ( array_key_exists( 'signupUrl', $record ) ) {
			$signup_url = $record['signupUrl'];
			if ( ! is_string( $signup_url ) || ! str_starts_with( $signup_url, 'https://' ) ) {
				Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" rejected — signupUrl must be an https:// string when present.', $id ) );
				return null;
			}
			$out['signupUrl'] = $signup_url;
		}

		// Optional subdomains, when present, must be a list of non-empty strings.
		if ( array_key_exists( 'subdomains', $record ) ) {
			$subdomains = self::normalise_subdomains( $record['subdomains'] );
			if ( $subdomains === null ) {
				Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" rejected — subdomains must be a non-empty list of non-empty strings.', $id ) );
				return null;
			}
			$out['subdomains'] = $subdomains;
		}

		return $out;

	}

	/**
	 * Validates and normalises a single overlay record.
	 *
	 * @since 1.0.0
	 *
	 * @param string                  $id     Overlay id (already shape-validated).
	 * @param array<int|string, mixed> $record Record to validate.
	 *
	 * @return OverlayRecord|null
	 */
	private static function validate_and_normalise_overlay_record( string $id, array $record ): ?array {

		$label = $record['label'] ?? null;
		if ( ! is_string( $label ) || '' === $label ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: overlay "%s" rejected — label must be a non-empty string.', $id ) );
			return null;
		}
		$url = $record['url'] ?? null;
		if ( ! is_string( $url ) || '' === $url ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: overlay "%s" rejected — url must be a non-empty string.', $id ) );
			return null;
		}
		$attribution = $record['attribution'] ?? null;
		if ( ! is_string( $attribution ) || '' === $attribution ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: overlay "%s" rejected — attribution must be a non-empty string.', $id ) );
			return null;
		}

		if ( ! str_starts_with( $url, 'https://' ) ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: overlay "%s" rejected — url must start with https://.', $id ) );
			return null;
		}

		if ( ! self::url_has_xyz_placeholders( $url ) ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: overlay "%s" rejected — url must contain {z}, {x}, and {y} placeholders.', $id ) );
			return null;
		}

		// Overlays in v1 do not carry an API key; reject any URL that asks for one.
		if ( str_contains( $url, '{KEY}' ) ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: overlay "%s" rejected — overlays must not contain {KEY} (no per-block API key for overlays in v1).', $id ) );
			return null;
		}

		$max_zoom = $record['maxZoom'] ?? null;
		if ( ! self::is_valid_max_zoom( $max_zoom ) ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: overlay "%s" rejected — maxZoom must be int in [0, %d].', $id, self::MAX_ZOOM_LIMIT ) );
			return null;
		}

		$out = [
			'label'       => $label,
			'url'         => $url,
			'attribution' => $attribution,
			'maxZoom'     => $max_zoom,
		];

		if ( array_key_exists( 'subdomains', $record ) ) {
			$subdomains = self::normalise_subdomains( $record['subdomains'] );
			if ( $subdomains === null ) {
				Plugin::warning( sprintf( 'Tile_Layer_Registry: overlay "%s" rejected — subdomains must be a non-empty list of non-empty strings.', $id ) );
				return null;
			}
			$out['subdomains'] = $subdomains;
		}

		return $out;

	}

	/**
	 * Returns the canonical default provider set shipped with the plugin.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, ProviderRecord>
	 */
	private static function default_providers(): array {

		return [
			'osm-standard'            => [
				'label'       => __( 'OpenStreetMap (Standard)', 'kntnt-gpx-blocks' ),
				'url'         => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
				'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				'maxZoom'     => 19,
				'requiresKey' => false,
				'subdomains'  => [ 'a', 'b', 'c' ],
			],
			'opentopomap'             => [
				'label'       => __( 'OpenTopoMap', 'kntnt-gpx-blocks' ),
				'url'         => 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',
				'attribution' => 'Map data: &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, <a href="https://viewfinderpanoramas.org">SRTM</a> | Map style: &copy; <a href="https://opentopomap.org">OpenTopoMap</a> (<a href="https://creativecommons.org/licenses/by-sa/3.0/">CC-BY-SA</a>)',
				'maxZoom'     => 17,
				'requiresKey' => false,
				'subdomains'  => [ 'a', 'b', 'c' ],
			],
			'cyclosm'                 => [
				'label'       => __( 'CyclOSM', 'kntnt-gpx-blocks' ),
				'url'         => 'https://{s}.tile-cyclosm.openstreetmap.fr/cyclosm/{z}/{x}/{y}.png',
				'attribution' => '<a href="https://github.com/cyclosm/cyclosm-cartocss-style/releases">CyclOSM</a> | Map data: &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				'maxZoom'     => 20,
				'requiresKey' => false,
				'subdomains'  => [ 'a', 'b', 'c' ],
			],
			'thunderforest-outdoors'  => [
				'label'       => __( 'Thunderforest Outdoors', 'kntnt-gpx-blocks' ),
				'url'         => 'https://tile.thunderforest.com/outdoors/{z}/{x}/{y}.png?apikey={KEY}',
				'attribution' => 'Maps &copy; <a href="https://www.thunderforest.com/">Thunderforest</a>, Data &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				'maxZoom'     => 22,
				'requiresKey' => true,
				'signupUrl'   => 'https://www.thunderforest.com/',
			],
			'thunderforest-landscape' => [
				'label'       => __( 'Thunderforest Landscape', 'kntnt-gpx-blocks' ),
				'url'         => 'https://tile.thunderforest.com/landscape/{z}/{x}/{y}.png?apikey={KEY}',
				'attribution' => 'Maps &copy; <a href="https://www.thunderforest.com/">Thunderforest</a>, Data &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				'maxZoom'     => 22,
				'requiresKey' => true,
				'signupUrl'   => 'https://www.thunderforest.com/',
			],
			'thunderforest-atlas'     => [
				'label'       => __( 'Thunderforest Atlas', 'kntnt-gpx-blocks' ),
				'url'         => 'https://tile.thunderforest.com/atlas/{z}/{x}/{y}.png?apikey={KEY}',
				'attribution' => 'Maps &copy; <a href="https://www.thunderforest.com/">Thunderforest</a>, Data &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				'maxZoom'     => 22,
				'requiresKey' => true,
				'signupUrl'   => 'https://www.thunderforest.com/',
			],
			'thunderforest-opencyclemap' => [
				'label'       => __( 'Thunderforest OpenCycleMap', 'kntnt-gpx-blocks' ),
				'url'         => 'https://tile.thunderforest.com/cycle/{z}/{x}/{y}.png?apikey={KEY}',
				'attribution' => 'Maps &copy; <a href="https://www.thunderforest.com/">Thunderforest</a>, Data &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				'maxZoom'     => 22,
				'requiresKey' => true,
				'signupUrl'   => 'https://www.thunderforest.com/',
			],
			'stadia-outdoors'         => [
				'label'       => __( 'Stadia Maps Outdoors', 'kntnt-gpx-blocks' ),
				'url'         => 'https://tiles.stadiamaps.com/tiles/outdoors/{z}/{x}/{y}.png?api_key={KEY}',
				'attribution' => '&copy; <a href="https://stadiamaps.com/">Stadia Maps</a> &copy; <a href="https://openmaptiles.org/">OpenMapTiles</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				'maxZoom'     => 20,
				'requiresKey' => true,
				'signupUrl'   => 'https://stadiamaps.com/',
			],
			'maptiler-outdoor'        => [
				'label'       => __( 'MapTiler Outdoor', 'kntnt-gpx-blocks' ),
				'url'         => 'https://api.maptiler.com/maps/outdoor-v2/{z}/{x}/{y}.png?key={KEY}',
				'attribution' => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				'maxZoom'     => 22,
				'requiresKey' => true,
				'signupUrl'   => 'https://www.maptiler.com/',
			],
			'maptiler-base'           => [
				'label'       => __( 'MapTiler Base', 'kntnt-gpx-blocks' ),
				'url'         => 'https://api.maptiler.com/maps/basic-v2/{z}/{x}/{y}.png?key={KEY}',
				'attribution' => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				'maxZoom'     => 22,
				'requiresKey' => true,
				'signupUrl'   => 'https://www.maptiler.com/',
			],
			'maptiler-landscape'      => [
				'label'       => __( 'MapTiler Landscape', 'kntnt-gpx-blocks' ),
				'url'         => 'https://api.maptiler.com/maps/landscape/{z}/{x}/{y}.png?key={KEY}',
				'attribution' => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				'maxZoom'     => 22,
				'requiresKey' => true,
				'signupUrl'   => 'https://www.maptiler.com/',
			],
			'maptiler-openstreetmap'  => [
				'label'       => __( 'MapTiler OpenStreetMap', 'kntnt-gpx-blocks' ),
				'url'         => 'https://api.maptiler.com/maps/openstreetmap/{z}/{x}/{y}.jpg?key={KEY}',
				'attribution' => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				'maxZoom'     => 22,
				'requiresKey' => true,
				'signupUrl'   => 'https://www.maptiler.com/',
			],
			'maptiler-streets'        => [
				'label'       => __( 'MapTiler Streets', 'kntnt-gpx-blocks' ),
				'url'         => 'https://api.maptiler.com/maps/streets-v2/{z}/{x}/{y}.png?key={KEY}',
				'attribution' => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				'maxZoom'     => 22,
				'requiresKey' => true,
				'signupUrl'   => 'https://www.maptiler.com/',
			],
			'maptiler-topo'           => [
				'label'       => __( 'MapTiler Topo', 'kntnt-gpx-blocks' ),
				'url'         => 'https://api.maptiler.com/maps/topo-v2/{z}/{x}/{y}.png?key={KEY}',
				'attribution' => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				'maxZoom'     => 22,
				'requiresKey' => true,
				'signupUrl'   => 'https://www.maptiler.com/',
			],
			'maptiler-satellite'      => [
				'label'       => __( 'MapTiler Satellite Plain', 'kntnt-gpx-blocks' ),
				'url'         => 'https://api.maptiler.com/maps/satellite/{z}/{x}/{y}.jpg?key={KEY}',
				'attribution' => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				'maxZoom'     => 22,
				'requiresKey' => true,
				'signupUrl'   => 'https://www.maptiler.com/',
			],
			'maptiler-hybrid'         => [
				'label'       => __( 'MapTiler Satellite Hybrid', 'kntnt-gpx-blocks' ),
				'url'         => 'https://api.maptiler.com/maps/hybrid/{z}/{x}/{y}.jpg?key={KEY}',
				'attribution' => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				'maxZoom'     => 22,
				'requiresKey' => true,
				'signupUrl'   => 'https://www.maptiler.com/',
			],
			'mapbox-outdoors'         => [
				'label'       => __( 'Mapbox Outdoors', 'kntnt-gpx-blocks' ),
				'url'         => 'https://api.mapbox.com/styles/v1/mapbox/outdoors-v12/tiles/{z}/{x}/{y}?access_token={KEY}',
				'attribution' => '&copy; <a href="https://www.mapbox.com/about/maps/">Mapbox</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors <a href="https://www.mapbox.com/map-feedback/">Improve this map</a>',
				'maxZoom'     => 22,
				'requiresKey' => true,
				'signupUrl'   => 'https://www.mapbox.com/',
			],
			'mapbox-streets'          => [
				'label'       => __( 'Mapbox Streets', 'kntnt-gpx-blocks' ),
				'url'         => 'https://api.mapbox.com/styles/v1/mapbox/streets-v12/tiles/{z}/{x}/{y}?access_token={KEY}',
				'attribution' => '&copy; <a href="https://www.mapbox.com/about/maps/">Mapbox</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors <a href="https://www.mapbox.com/map-feedback/">Improve this map</a>',
				'maxZoom'     => 22,
				'requiresKey' => true,
				'signupUrl'   => 'https://www.mapbox.com/',
			],
			'mapbox-satellite-streets' => [
				'label'       => __( 'Mapbox Satellite Streets', 'kntnt-gpx-blocks' ),
				'url'         => 'https://api.mapbox.com/styles/v1/mapbox/satellite-streets-v12/tiles/{z}/{x}/{y}?access_token={KEY}',
				'attribution' => '&copy; <a href="https://www.mapbox.com/about/maps/">Mapbox</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors <a href="https://www.mapbox.com/map-feedback/">Improve this map</a>',
				'maxZoom'     => 22,
				'requiresKey' => true,
				'signupUrl'   => 'https://www.mapbox.com/',
			],
			'mapbox-light'            => [
				'label'       => __( 'Mapbox Light', 'kntnt-gpx-blocks' ),
				'url'         => 'https://api.mapbox.com/styles/v1/mapbox/light-v11/tiles/{z}/{x}/{y}?access_token={KEY}',
				'attribution' => '&copy; <a href="https://www.mapbox.com/about/maps/">Mapbox</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors <a href="https://www.mapbox.com/map-feedback/">Improve this map</a>',
				'maxZoom'     => 22,
				'requiresKey' => true,
				'signupUrl'   => 'https://www.mapbox.com/',
			],
			'mapbox-dark'             => [
				'label'       => __( 'Mapbox Dark', 'kntnt-gpx-blocks' ),
				'url'         => 'https://api.mapbox.com/styles/v1/mapbox/dark-v11/tiles/{z}/{x}/{y}?access_token={KEY}',
				'attribution' => '&copy; <a href="https://www.mapbox.com/about/maps/">Mapbox</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors <a href="https://www.mapbox.com/map-feedback/">Improve this map</a>',
				'maxZoom'     => 22,
				'requiresKey' => true,
				'signupUrl'   => 'https://www.mapbox.com/',
			],
		];

	}

	/**
	 * Returns the canonical default overlay set shipped with the plugin.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, OverlayRecord>
	 */
	private static function default_overlays(): array {

		return [
			'wmt-hiking'   => [
				'label'       => __( 'Waymarked Trails — Hiking', 'kntnt-gpx-blocks' ),
				'url'         => 'https://tile.waymarkedtrails.org/hiking/{z}/{x}/{y}.png',
				'attribution' => '&copy; <a href="https://hiking.waymarkedtrails.org/">Waymarked Trails</a> | Map data: &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				'maxZoom'     => 18,
			],
			'wmt-cycling'  => [
				'label'       => __( 'Waymarked Trails — Cycling', 'kntnt-gpx-blocks' ),
				'url'         => 'https://tile.waymarkedtrails.org/cycling/{z}/{x}/{y}.png',
				'attribution' => '&copy; <a href="https://cycling.waymarkedtrails.org/">Waymarked Trails</a> | Map data: &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				'maxZoom'     => 18,
			],
			'wmt-mtb'      => [
				'label'       => __( 'Waymarked Trails — MTB', 'kntnt-gpx-blocks' ),
				'url'         => 'https://tile.waymarkedtrails.org/mtb/{z}/{x}/{y}.png',
				'attribution' => '&copy; <a href="https://mtb.waymarkedtrails.org/">Waymarked Trails</a> | Map data: &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				'maxZoom'     => 18,
			],
			'openseamap'   => [
				'label'       => __( 'OpenSeaMap (sea marks)', 'kntnt-gpx-blocks' ),
				'url'         => 'https://tiles.openseamap.org/seamark/{z}/{x}/{y}.png',
				'attribution' => '&copy; <a href="https://www.openseamap.org/">OpenSeaMap</a> contributors',
				'maxZoom'     => 18,
			],
			'opensnowmap'  => [
				'label'       => __( 'OpenSnowMap (pistes)', 'kntnt-gpx-blocks' ),
				'url'         => 'https://tiles.opensnowmap.org/pistes/{z}/{x}/{y}.png',
				'attribution' => '&copy; <a href="https://www.opensnowmap.org/">OpenSnowMap</a> | Map data: &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors (<a href="https://creativecommons.org/licenses/by-sa/3.0/">CC-BY-SA</a>)',
				'maxZoom'     => 18,
			],
		];

	}

	/**
	 * Re-injects the canonical fallback provider when the validated set lacks it.
	 *
	 * The fallback exists so `resolve_provider()` can always return a record,
	 * even when a misconfigured filter callback has dropped every default
	 * record. Logs a warning when re-injection happens because losing the
	 * fallback indicates a serious misconfiguration upstream.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, ProviderRecord> $valid Validated provider set.
	 *
	 * @return array<string, ProviderRecord>
	 */
	private static function ensure_fallback_provider( array $valid ): array {

		if ( isset( $valid[ self::FALLBACK_PROVIDER_ID ] ) ) {
			return $valid;
		}

		Plugin::warning(
			sprintf( 'Tile_Layer_Registry: filter dropped fallback provider "%s"; re-injecting canonical record.', self::FALLBACK_PROVIDER_ID )
		);

		// Re-validate the canonical default in case the constants drift over
		// time. The default set is hard-coded above and will normally pass.
		$defaults = self::default_providers();
		$canonical = $defaults[ self::FALLBACK_PROVIDER_ID ] ?? null;
		if ( $canonical === null ) {
			return $valid;
		}
		$normalised = self::validate_and_normalise_provider_record( self::FALLBACK_PROVIDER_ID, $canonical );
		if ( $normalised === null ) {
			return $valid;
		}

		$valid[ self::FALLBACK_PROVIDER_ID ] = $normalised;

		return $valid;

	}

	/**
	 * Returns true when the URL contains every Leaflet tile-coordinate placeholder.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url Tile URL template.
	 *
	 * @return bool
	 */
	private static function url_has_xyz_placeholders( string $url ): bool {
		return str_contains( $url, '{z}' ) && str_contains( $url, '{x}' ) && str_contains( $url, '{y}' );
	}

	/**
	 * Returns true (with the value narrowed to int) when the value is a
	 * maxZoom int in the accepted range.
	 *
	 * Strictly typed: floats and numeric strings are rejected so callers do
	 * not accidentally ship a fractional zoom.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Candidate maxZoom value.
	 *
	 * @phpstan-assert-if-true int $value
	 *
	 * @return bool
	 */
	private static function is_valid_max_zoom( mixed $value ): bool {
		return is_int( $value ) && $value >= 0 && $value <= self::MAX_ZOOM_LIMIT;
	}

	/**
	 * Returns true when the id matches the strict id alphabet.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Candidate id.
	 *
	 * @return bool
	 */
	private static function is_valid_id( string $id ): bool {
		return '' !== $id && preg_match( self::ID_REGEX, $id ) === 1;
	}

	/**
	 * Normalises a candidate subdomains value into a typed list, or returns
	 * `null` when the value cannot be coerced.
	 *
	 * Accepts only a non-empty array whose every entry is a non-empty string.
	 * The output drops the input keys (Leaflet does not care) so the result
	 * is a plain list ready for the JS view module.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Candidate subdomains value.
	 *
	 * @return list<string>|null
	 */
	private static function normalise_subdomains( mixed $value ): ?array {

		if ( ! is_array( $value ) || count( $value ) === 0 ) {
			return null;
		}

		$out = [];
		foreach ( $value as $entry ) {
			if ( ! is_string( $entry ) || '' === $entry ) {
				return null;
			}
			$out[] = $entry;
		}

		return $out;

	}

}
