<?php
/**
 * Server-side registry of Leaflet tile-layer providers and overlay layers.
 *
 * Owns the canonical list of base providers (OpenStreetMap, OpenTopoMap,
 * Carto, Esri, Stadia Maps, Jawg Maps, MapTiler, Mapbox, Thunderforest) and
 * overlay layers (Waymarked Trails, OpenSeaMap, OpenSnowMap) that the GPX
 * Map block can choose between, and exposes two public PHP filters —
 * `kntnt_gpx_blocks_tile_providers` and `kntnt_gpx_blocks_tile_overlays` —
 * for site builders to add, replace, or remove records. The registry is
 * **PHP-canonical**: the JS view module reads the resolved record from the
 * per-block Interactivity state, never from a JS-side registry.
 *
 * The provider registry is a two-level hierarchy: each provider carries a
 * shared `label`, `requiresKey` flag (one API key per provider, shared
 * across all that provider's styles), `default` style id, optional
 * `signupUrl`, optional `subdomains` (inherited by every style of that
 * provider whose URL contains `{s}`), and a `styles` map keyed by style id
 * with per-style `label`, `url`, `attribution`, `maxZoom`. Overlays remain
 * a single-level map keyed by overlay id.
 *
 * Validation is deterministic and follows a drop-the-narrowest-unit rule.
 * A bad single style drops just that style; provider-level failures
 * (missing required field, `default` resolves to a dropped style, empty
 * styles map, `{s}`-using style without provider-level `subdomains`) drop
 * the whole provider. Every drop emits one `Plugin::warning()` log naming
 * the failing id and the failing constraint so the integrator can locate
 * the mistake quickly.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rendering;

use Kntnt\Gpx_Blocks\Plugin;

/**
 * Registry of base-tile providers (provider/style hierarchy) and overlay
 * layers for the GPX Map block.
 *
 * Caches the validated arrays per request so the filter chain runs once
 * per registry instance even when the registry is consulted multiple
 * times during a single render pass.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 *
 * @phpstan-type StyleRecord array{
 *     label: string,
 *     url: string,
 *     attribution: string,
 *     maxZoom: int,
 * }
 * @phpstan-type ProviderRecord array{
 *     label: string,
 *     requiresKey: bool,
 *     default: string,
 *     styles: array<string, StyleRecord>,
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
	 * falls back to this id (with a warning log) when an unknown provider
	 * id is requested, or — defensively — when a provider's own `default`
	 * style cannot be resolved.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const FALLBACK_PROVIDER_ID = 'openstreetmap';

	/**
	 * Regex matching a valid provider or style identifier.
	 *
	 * Lowercase letters, digits, and hyphens only — the same alphabet as
	 * the plugin's CSS class prefix and REST namespace segments.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const ID_REGEX = '/^[a-z0-9-]+$/';

	/**
	 * Hard upper bound on `maxZoom` accepted by the validator.
	 *
	 * Leaflet supports up to zoom 22 in practice. Values above this cap
	 * are rejected because no real tile provider serves them and they
	 * likely indicate a typo.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const MAX_ZOOM_LIMIT = 22;

	/**
	 * Cached, validated provider list for the current request.
	 *
	 * Lazily populated on the first call to `get_providers()`. `null`
	 * means "not yet resolved"; an empty array means "filter dropped
	 * every record" but is never observed in practice because the
	 * registry restores the fallback provider before returning.
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
	 * The result is keyed by provider id. Each value is the validated
	 * provider record carrying `label`, `requiresKey`, `default` style
	 * id, the per-style `styles` map, and the optional `signupUrl` and
	 * `subdomains` keys. The `{KEY}` placeholder is left as-is in the
	 * per-style URL; substitution happens in `resolve_provider()`.
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
	 * Result shape mirrors the per-style record, minus `requiresKey` and
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
	 * Resolves a saved (provider id, style id) pair to a runtime tile-layer record.
	 *
	 * Returns the validated record with `{KEY}` substituted for the
	 * supplied API key. The resolved record carries only the four
	 * Leaflet-facing fields (`url`, `attribution`, `maxZoom`, optional
	 * `subdomains`) — the input ids are not embedded because the caller
	 * already has them in the block attributes.
	 *
	 * Resolution fall-backs, in order:
	 *
	 * 1. Unknown provider id → fall back to the global
	 *    `FALLBACK_PROVIDER_ID` and its `default` style.
	 * 2. Known provider, unknown style id → fall back to the provider's
	 *    own `default` style.
	 * 3. Defensive: if the provider's own `default` style is missing
	 *    (should not happen post-validation, but `resolve_provider`
	 *    doesn't trust the impossible) → fall back to the global
	 *    fallback provider's default style.
	 *
	 * Every fall-back emits one `Plugin::warning()` log so the
	 * misconfiguration surfaces in the log without breaking the page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $provider_id Provider id from saved block attributes.
	 * @param string $style_id    Style id from saved block attributes.
	 * @param string $api_key     Per-provider tile API key (empty when
	 *                            the provider does not require one).
	 *
	 * @return ResolvedProvider
	 */
	public function resolve_provider( string $provider_id, string $style_id, string $api_key ): array {

		$providers = $this->get_providers();

		// Locate the provider record; fall back to the global fallback on miss.
		$provider = $providers[ $provider_id ] ?? null;
		if ( $provider === null ) {
			Plugin::warning(
				sprintf(
					'Tile_Layer_Registry: unknown tile provider "%s"; falling back to %s.',
					$provider_id,
					self::FALLBACK_PROVIDER_ID
				)
			);
			$provider = $providers[ self::FALLBACK_PROVIDER_ID ];
			$style_id = $provider['default'];
		}

		// Locate the style record within the provider; fall back to the provider's default on miss.
		$style = $provider['styles'][ $style_id ] ?? null;
		if ( $style === null ) {
			Plugin::warning(
				sprintf(
					'Tile_Layer_Registry: unknown style "%s" for provider "%s"; falling back to %s.',
					$style_id,
					$provider_id,
					$provider['default']
				)
			);
			$style = $provider['styles'][ $provider['default'] ] ?? null;
		}

		// Defensive: if the provider's own default cannot be resolved
		// (shouldn't happen post-validation, but a future filter mutation
		// could break the invariant), fall through to the global fallback.
		if ( $style === null ) {
			Plugin::warning(
				sprintf(
					'Tile_Layer_Registry: provider "%s" has unresolvable default style; falling back to %s.',
					$provider_id,
					self::FALLBACK_PROVIDER_ID
				)
			);
			$provider = $providers[ self::FALLBACK_PROVIDER_ID ];
			$style    = $provider['styles'][ $provider['default'] ];
		}

		// Substitute the API key when the provider requires one. The
		// validator's contract guarantees the URL contains a literal
		// `{KEY}` when `requiresKey === true`; on the other branch the
		// URL has no `{KEY}` and `str_replace()` is a no-op.
		$url = $style['url'];
		if ( $provider['requiresKey'] ) {
			$url = str_replace( '{KEY}', $api_key, $url );
		}

		// Compose the runtime record. `subdomains` is inherited from the
		// provider (validator contract: a `{s}`-using style requires its
		// provider to declare `subdomains`) and is forwarded only when
		// present so the JS side can branch on `record.subdomains`.
		$out = [
			'url'         => $url,
			'attribution' => $style['attribution'],
			'maxZoom'     => $style['maxZoom'],
		];
		if ( isset( $provider['subdomains'] ) ) {
			$out['subdomains'] = $provider['subdomains'];
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
	 * Drop-the-narrowest-unit: a bad single style drops just that style
	 * with a warning; a provider-level failure (no surviving styles, bad
	 * `default`, etc.) drops the whole provider with a separate warning.
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
	 * Same contract as `validate_provider_set()` but with the overlay
	 * record shape (a single tile layer, no provider/style hierarchy).
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
	 * Validates and normalises a single provider record.
	 *
	 * Enforces the provider-level constraints — non-empty `label`, bool
	 * `requiresKey`, non-empty `default` style id, non-empty `styles`
	 * map, optional `signupUrl` is https, optional `subdomains` is a
	 * non-empty list of non-empty strings — and then walks the `styles`
	 * sub-map, dropping individual bad styles with a warning. After per-
	 * style validation, the provider is rejected when no styles survive,
	 * when its `default` was dropped, or when any surviving style uses
	 * `{s}` but the provider declared no `subdomains`. Returns the
	 * canonical typed shape on success, `null` on rejection.
	 *
	 * @since 1.0.0
	 *
	 * @param string                  $id     Provider id (already shape-validated).
	 * @param array<int|string, mixed> $record Record to validate.
	 *
	 * @return ProviderRecord|null
	 */
	private static function validate_and_normalise_provider_record( string $id, array $record ): ?array {

		// label, requiresKey, default, styles are required provider-level fields.
		$label = $record['label'] ?? null;
		if ( ! is_string( $label ) || '' === $label ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" rejected — label must be a non-empty string.', $id ) );
			return null;
		}
		$requires_key = $record['requiresKey'] ?? null;
		if ( ! is_bool( $requires_key ) ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" rejected — requiresKey must be a bool.', $id ) );
			return null;
		}
		$default_style_id = $record['default'] ?? null;
		if ( ! is_string( $default_style_id ) || '' === $default_style_id ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" rejected — default must be a non-empty string style id.', $id ) );
			return null;
		}
		$raw_styles = $record['styles'] ?? null;
		if ( ! is_array( $raw_styles ) || count( $raw_styles ) === 0 ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" rejected — styles must be a non-empty map.', $id ) );
			return null;
		}

		// Optional signupUrl, when present, must be an https string.
		$signup_url = null;
		if ( array_key_exists( 'signupUrl', $record ) ) {
			$candidate = $record['signupUrl'];
			if ( ! is_string( $candidate ) || ! str_starts_with( $candidate, 'https://' ) ) {
				Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" rejected — signupUrl must be an https:// string when present.', $id ) );
				return null;
			}
			$signup_url = $candidate;
		}

		// Optional subdomains, when present, must be a non-empty list of non-empty strings.
		$subdomains = null;
		if ( array_key_exists( 'subdomains', $record ) ) {
			$candidate = self::normalise_subdomains( $record['subdomains'] );
			if ( $candidate === null ) {
				Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" rejected — subdomains must be a non-empty list of non-empty strings.', $id ) );
				return null;
			}
			$subdomains = $candidate;
		}

		// Validate each style with the drop-the-narrowest-unit rule. A
		// style that fails its own validator is dropped with a warning;
		// the provider survives so long as at least one style remains.
		$styles = [];
		foreach ( $raw_styles as $style_id => $raw_style ) {

			if ( ! is_string( $style_id ) || ! is_array( $raw_style ) ) {
				Plugin::warning(
					sprintf( 'Tile_Layer_Registry: provider "%s" style dropped — style key must be a string id and value must be an array.', $id )
				);
				continue;
			}

			if ( ! self::is_valid_id( $style_id ) ) {
				Plugin::warning(
					sprintf( 'Tile_Layer_Registry: provider "%s" style id "%s" rejected — must match %s.', $id, $style_id, self::ID_REGEX )
				);
				continue;
			}

			$normalised_style = self::validate_and_normalise_style_record( $id, $style_id, $raw_style, $requires_key, $subdomains );
			if ( $normalised_style === null ) {
				continue;
			}

			$styles[ $style_id ] = $normalised_style;

		}

		// Reject the provider when no styles survive — there's nothing left to resolve.
		if ( count( $styles ) === 0 ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" rejected — no styles survived validation.', $id ) );
			return null;
		}

		// Reject the provider when its declared `default` style is not in the surviving set.
		if ( ! isset( $styles[ $default_style_id ] ) ) {
			Plugin::warning(
				sprintf( 'Tile_Layer_Registry: provider "%s" rejected — default style "%s" is not in the surviving styles set.', $id, $default_style_id )
			);
			return null;
		}

		// Compose the canonical typed shape. Optional fields are added only when they passed.
		$out = [
			'label'       => $label,
			'requiresKey' => $requires_key,
			'default'     => $default_style_id,
			'styles'      => $styles,
		];
		if ( $signup_url !== null ) {
			$out['signupUrl'] = $signup_url;
		}
		if ( $subdomains !== null ) {
			$out['subdomains'] = $subdomains;
		}

		return $out;

	}

	/**
	 * Validates and normalises a single style record within a provider.
	 *
	 * Per-style constraints: non-empty `label`, `attribution`, https `url`
	 * containing `{z}`, `{x}`, `{y}`, `maxZoom` integer in [0, 22]. The
	 * `{KEY}` placeholder is required iff `$provider_requires_key === true`
	 * (same iff rule the v0.x flat registry enforced, now scoped to the
	 * containing provider's flag). When the URL contains `{s}`, the
	 * provider must have declared `subdomains` — a `{s}`-using style on a
	 * provider without `subdomains` is dropped.
	 *
	 * @since 1.0.0
	 *
	 * @param string                       $provider_id           Containing provider id (for warning context).
	 * @param string                       $style_id              Style id (already shape-validated).
	 * @param array<int|string, mixed>     $record                Style record to validate.
	 * @param bool                         $provider_requires_key Provider-level `requiresKey` flag.
	 * @param list<string>|null            $provider_subdomains   Provider-level `subdomains`, if declared.
	 *
	 * @return StyleRecord|null
	 */
	private static function validate_and_normalise_style_record(
		string $provider_id,
		string $style_id,
		array $record,
		bool $provider_requires_key,
		?array $provider_subdomains,
	): ?array {

		$label = $record['label'] ?? null;
		if ( ! is_string( $label ) || '' === $label ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" style "%s" dropped — label must be a non-empty string.', $provider_id, $style_id ) );
			return null;
		}
		$url = $record['url'] ?? null;
		if ( ! is_string( $url ) || '' === $url ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" style "%s" dropped — url must be a non-empty string.', $provider_id, $style_id ) );
			return null;
		}
		$attribution = $record['attribution'] ?? null;
		if ( ! is_string( $attribution ) || '' === $attribution ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" style "%s" dropped — attribution must be a non-empty string.', $provider_id, $style_id ) );
			return null;
		}

		// URL scheme must be https; relative or http URLs leak visitor IPs without TLS.
		if ( ! str_starts_with( $url, 'https://' ) ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" style "%s" dropped — url must start with https://.', $provider_id, $style_id ) );
			return null;
		}

		// URL must contain Leaflet's tile-coordinate placeholders verbatim.
		if ( ! self::url_has_xyz_placeholders( $url ) ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" style "%s" dropped — url must contain {z}, {x}, and {y} placeholders.', $provider_id, $style_id ) );
			return null;
		}

		// {KEY} placeholder must match the provider's `requiresKey` flag.
		$has_key_placeholder = str_contains( $url, '{KEY}' );
		if ( $provider_requires_key && ! $has_key_placeholder ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" style "%s" dropped — provider requiresKey=true but url has no {KEY} placeholder.', $provider_id, $style_id ) );
			return null;
		}
		if ( ! $provider_requires_key && $has_key_placeholder ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" style "%s" dropped — provider requiresKey=false but url contains {KEY} placeholder.', $provider_id, $style_id ) );
			return null;
		}

		// A {s}-using style requires its provider to declare subdomains.
		if ( str_contains( $url, '{s}' ) && $provider_subdomains === null ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" style "%s" dropped — url contains {s} but provider declares no subdomains.', $provider_id, $style_id ) );
			return null;
		}

		// maxZoom must be an int in [0, MAX_ZOOM_LIMIT].
		$max_zoom = $record['maxZoom'] ?? null;
		if ( ! self::is_valid_max_zoom( $max_zoom ) ) {
			Plugin::warning( sprintf( 'Tile_Layer_Registry: provider "%s" style "%s" dropped — maxZoom must be int in [0, %d].', $provider_id, $style_id, self::MAX_ZOOM_LIMIT ) );
			return null;
		}

		return [
			'label'       => $label,
			'url'         => $url,
			'attribution' => $attribution,
			'maxZoom'     => $max_zoom,
		];

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
	 * Nine providers, ordered alphabetically by display label: Carto,
	 * Esri, Jawg Maps, Mapbox, MapTiler, OpenStreetMap, OpenTopoMap,
	 * Stadia Maps, Thunderforest. The first two and the OpenStreetMap /
	 * OpenTopoMap entries are key-less; the remaining five require a
	 * per-provider API key shared across all that provider's styles.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, ProviderRecord>
	 */
	private static function default_providers(): array {

		return [
			'carto'         => [
				'label'       => __( 'Carto', 'kntnt-gpx-blocks' ),
				'requiresKey' => false,
				'default'     => 'voyager',
				'subdomains'  => [ 'a', 'b', 'c', 'd' ],
				'styles'      => [
					'dark-matter' => [
						'label'       => __( 'Dark Matter', 'kntnt-gpx-blocks' ),
						'url'         => 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png',
						'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
						'maxZoom'     => 20,
					],
					'positron'    => [
						'label'       => __( 'Positron', 'kntnt-gpx-blocks' ),
						'url'         => 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png',
						'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
						'maxZoom'     => 20,
					],
					'voyager'     => [
						'label'       => __( 'Voyager', 'kntnt-gpx-blocks' ),
						'url'         => 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png',
						'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
						'maxZoom'     => 20,
					],
				],
			],
			'esri'          => [
				'label'       => __( 'Esri', 'kntnt-gpx-blocks' ),
				'requiresKey' => false,
				'default'     => 'topographic',
				'styles'      => [
					'dark-gray-canvas'  => [
						'label'       => __( 'Dark Gray Canvas', 'kntnt-gpx-blocks' ),
						'url'         => 'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}',
						'attribution' => 'Tiles &copy; Esri &mdash; Esri, DeLorme, NAVTEQ',
						'maxZoom'     => 19,
					],
					'imagery'           => [
						'label'       => __( 'Imagery', 'kntnt-gpx-blocks' ),
						'url'         => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
						'attribution' => 'Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community',
						'maxZoom'     => 19,
					],
					'imagery-hybrid'    => [
						'label'       => __( 'Imagery Hybrid', 'kntnt-gpx-blocks' ),
						'url'         => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
						'attribution' => 'Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community',
						'maxZoom'     => 19,
					],
					'light-gray-canvas' => [
						'label'       => __( 'Light Gray Canvas', 'kntnt-gpx-blocks' ),
						'url'         => 'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Base/MapServer/tile/{z}/{y}/{x}',
						'attribution' => 'Tiles &copy; Esri &mdash; Esri, DeLorme, NAVTEQ',
						'maxZoom'     => 19,
					],
					'navigation'        => [
						'label'       => __( 'Navigation', 'kntnt-gpx-blocks' ),
						'url'         => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}',
						'attribution' => 'Tiles &copy; Esri &mdash; Source: Esri, HERE, Garmin, USGS, Intermap, INCREMENT P, NRCan, Esri Japan, METI, Esri China (Hong Kong), Esri Korea, Esri (Thailand), NGCC, &copy; OpenStreetMap contributors, and the GIS User Community',
						'maxZoom'     => 19,
					],
					'openstreetmap'     => [
						'label'       => __( 'OpenStreetMap', 'kntnt-gpx-blocks' ),
						'url'         => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}',
						'attribution' => 'Tiles &copy; Esri &mdash; Source: Esri, &copy; OpenStreetMap contributors',
						'maxZoom'     => 19,
					],
					'outdoor'           => [
						'label'       => __( 'Outdoors', 'kntnt-gpx-blocks' ),
						'url'         => 'https://server.arcgisonline.com/ArcGIS/rest/services/NatGeo_World_Map/MapServer/tile/{z}/{y}/{x}',
						'attribution' => 'Tiles &copy; Esri &mdash; National Geographic, Esri, DeLorme, NAVTEQ, UNEP-WCMC, USGS, NASA, ESA, METI, NRCAN, GEBCO, NOAA, iPC',
						'maxZoom'     => 16,
					],
					'streets'           => [
						'label'       => __( 'Streets', 'kntnt-gpx-blocks' ),
						'url'         => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}',
						'attribution' => 'Tiles &copy; Esri &mdash; Source: Esri, DeLorme, NAVTEQ, USGS, Intermap, iPC, NRCAN, Esri Japan, METI, Esri China (Hong Kong), Esri (Thailand), TomTom, 2012',
						'maxZoom'     => 19,
					],
					'topographic'       => [
						'label'       => __( 'Topographic', 'kntnt-gpx-blocks' ),
						'url'         => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{z}/{y}/{x}',
						'attribution' => 'Tiles &copy; Esri &mdash; Esri, DeLorme, NAVTEQ, TomTom, Intermap, iPC, USGS, FAO, NPS, NRCAN, GeoBase, Kadaster NL, Ordnance Survey, Esri Japan, METI, Esri China (Hong Kong), and the GIS User Community',
						'maxZoom'     => 19,
					],
				],
			],
			'jawg-maps'     => [
				'label'       => __( 'Jawg Maps', 'kntnt-gpx-blocks' ),
				'requiresKey' => true,
				'default'     => 'streets',
				'signupUrl'   => 'https://www.jawg.io/',
				'styles'      => [
					'streets' => [
						'label'       => __( 'Streets', 'kntnt-gpx-blocks' ),
						'url'         => 'https://tile.jawg.io/jawg-streets/{z}/{x}/{y}.png?access-token={KEY}',
						'attribution' => '<a href="https://www.jawg.io" title="Tiles Courtesy of Jawg Maps">&copy; Jawg Maps</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 22,
					],
					'terrain' => [
						'label'       => __( 'Terrain', 'kntnt-gpx-blocks' ),
						'url'         => 'https://tile.jawg.io/jawg-terrain/{z}/{x}/{y}.png?access-token={KEY}',
						'attribution' => '<a href="https://www.jawg.io" title="Tiles Courtesy of Jawg Maps">&copy; Jawg Maps</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 22,
					],
					'sunny'   => [
						'label'       => __( 'Sunny', 'kntnt-gpx-blocks' ),
						'url'         => 'https://tile.jawg.io/jawg-sunny/{z}/{x}/{y}.png?access-token={KEY}',
						'attribution' => '<a href="https://www.jawg.io" title="Tiles Courtesy of Jawg Maps">&copy; Jawg Maps</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 22,
					],
					'light'   => [
						'label'       => __( 'Light', 'kntnt-gpx-blocks' ),
						'url'         => 'https://tile.jawg.io/jawg-light/{z}/{x}/{y}.png?access-token={KEY}',
						'attribution' => '<a href="https://www.jawg.io" title="Tiles Courtesy of Jawg Maps">&copy; Jawg Maps</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 22,
					],
					'dark'    => [
						'label'       => __( 'Dark', 'kntnt-gpx-blocks' ),
						'url'         => 'https://tile.jawg.io/jawg-dark/{z}/{x}/{y}.png?access-token={KEY}',
						'attribution' => '<a href="https://www.jawg.io" title="Tiles Courtesy of Jawg Maps">&copy; Jawg Maps</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 22,
					],
				],
			],
			'mapbox'        => [
				'label'       => __( 'Mapbox', 'kntnt-gpx-blocks' ),
				'requiresKey' => true,
				'default'     => 'outdoors',
				'signupUrl'   => 'https://www.mapbox.com/',
				'styles'      => [
					'outdoors'          => [
						'label'       => __( 'Outdoors', 'kntnt-gpx-blocks' ),
						'url'         => 'https://api.mapbox.com/styles/v1/mapbox/outdoors-v12/tiles/{z}/{x}/{y}?access_token={KEY}',
						'attribution' => '&copy; <a href="https://www.mapbox.com/about/maps/">Mapbox</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors <a href="https://www.mapbox.com/map-feedback/">Improve this map</a>',
						'maxZoom'     => 22,
					],
					'streets'           => [
						'label'       => __( 'Streets', 'kntnt-gpx-blocks' ),
						'url'         => 'https://api.mapbox.com/styles/v1/mapbox/streets-v12/tiles/{z}/{x}/{y}?access_token={KEY}',
						'attribution' => '&copy; <a href="https://www.mapbox.com/about/maps/">Mapbox</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors <a href="https://www.mapbox.com/map-feedback/">Improve this map</a>',
						'maxZoom'     => 22,
					],
					'satellite-streets' => [
						'label'       => __( 'Satellite Streets', 'kntnt-gpx-blocks' ),
						'url'         => 'https://api.mapbox.com/styles/v1/mapbox/satellite-streets-v12/tiles/{z}/{x}/{y}?access_token={KEY}',
						'attribution' => '&copy; <a href="https://www.mapbox.com/about/maps/">Mapbox</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors <a href="https://www.mapbox.com/map-feedback/">Improve this map</a>',
						'maxZoom'     => 22,
					],
					'light'             => [
						'label'       => __( 'Light', 'kntnt-gpx-blocks' ),
						'url'         => 'https://api.mapbox.com/styles/v1/mapbox/light-v11/tiles/{z}/{x}/{y}?access_token={KEY}',
						'attribution' => '&copy; <a href="https://www.mapbox.com/about/maps/">Mapbox</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors <a href="https://www.mapbox.com/map-feedback/">Improve this map</a>',
						'maxZoom'     => 22,
					],
					'dark'              => [
						'label'       => __( 'Dark', 'kntnt-gpx-blocks' ),
						'url'         => 'https://api.mapbox.com/styles/v1/mapbox/dark-v11/tiles/{z}/{x}/{y}?access_token={KEY}',
						'attribution' => '&copy; <a href="https://www.mapbox.com/about/maps/">Mapbox</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors <a href="https://www.mapbox.com/map-feedback/">Improve this map</a>',
						'maxZoom'     => 22,
					],
				],
			],
			'maptiler'      => [
				'label'       => __( 'MapTiler', 'kntnt-gpx-blocks' ),
				'requiresKey' => true,
				'default'     => 'outdoor',
				'signupUrl'   => 'https://www.maptiler.com/',
				'styles'      => [
					'outdoor'       => [
						'label'       => __( 'Outdoor', 'kntnt-gpx-blocks' ),
						'url'         => 'https://api.maptiler.com/maps/outdoor-v2/{z}/{x}/{y}.png?key={KEY}',
						'attribution' => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 22,
					],
					'base'          => [
						'label'       => __( 'Base', 'kntnt-gpx-blocks' ),
						'url'         => 'https://api.maptiler.com/maps/basic-v2/{z}/{x}/{y}.png?key={KEY}',
						'attribution' => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 22,
					],
					'landscape'     => [
						'label'       => __( 'Landscape', 'kntnt-gpx-blocks' ),
						'url'         => 'https://api.maptiler.com/maps/landscape/{z}/{x}/{y}.png?key={KEY}',
						'attribution' => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 22,
					],
					'openstreetmap' => [
						'label'       => __( 'OpenStreetMap', 'kntnt-gpx-blocks' ),
						'url'         => 'https://api.maptiler.com/maps/openstreetmap/{z}/{x}/{y}.jpg?key={KEY}',
						'attribution' => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 22,
					],
					'streets'       => [
						'label'       => __( 'Streets', 'kntnt-gpx-blocks' ),
						'url'         => 'https://api.maptiler.com/maps/streets-v2/{z}/{x}/{y}.png?key={KEY}',
						'attribution' => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 22,
					],
					'topo'          => [
						'label'       => __( 'Topo', 'kntnt-gpx-blocks' ),
						'url'         => 'https://api.maptiler.com/maps/topo-v2/{z}/{x}/{y}.png?key={KEY}',
						'attribution' => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 22,
					],
					'satellite'     => [
						'label'       => __( 'Satellite', 'kntnt-gpx-blocks' ),
						'url'         => 'https://api.maptiler.com/maps/satellite/{z}/{x}/{y}.jpg?key={KEY}',
						'attribution' => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 22,
					],
					'hybrid'        => [
						'label'       => __( 'Hybrid', 'kntnt-gpx-blocks' ),
						'url'         => 'https://api.maptiler.com/maps/hybrid/{z}/{x}/{y}.jpg?key={KEY}',
						'attribution' => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 22,
					],
					'dataviz'       => [
						'label'       => __( 'Dataviz', 'kntnt-gpx-blocks' ),
						'url'         => 'https://api.maptiler.com/maps/dataviz/{z}/{x}/{y}.png?key={KEY}',
						'attribution' => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 22,
					],
				],
			],
			'openstreetmap' => [
				'label'       => __( 'OpenStreetMap', 'kntnt-gpx-blocks' ),
				'requiresKey' => false,
				'default'     => 'mapnik',
				'subdomains'  => [ 'a', 'b', 'c' ],
				'styles'      => [
					'mapnik'  => [
						'label'       => __( 'Mapnik', 'kntnt-gpx-blocks' ),
						'url'         => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
						'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 19,
					],
					'cyclosm' => [
						'label'       => __( 'CyclOSM', 'kntnt-gpx-blocks' ),
						'url'         => 'https://{s}.tile-cyclosm.openstreetmap.fr/cyclosm/{z}/{x}/{y}.png',
						'attribution' => '<a href="https://github.com/cyclosm/cyclosm-cartocss-style/releases">CyclOSM</a> | Map data: &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 20,
					],
				],
			],
			'opentopomap'   => [
				'label'       => __( 'OpenTopoMap', 'kntnt-gpx-blocks' ),
				'requiresKey' => false,
				'default'     => 'standard',
				'subdomains'  => [ 'a', 'b', 'c' ],
				'styles'      => [
					'standard' => [
						'label'       => __( 'Standard', 'kntnt-gpx-blocks' ),
						'url'         => 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',
						'attribution' => 'Map data: &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, <a href="https://viewfinderpanoramas.org">SRTM</a> | Map style: &copy; <a href="https://opentopomap.org">OpenTopoMap</a> (<a href="https://creativecommons.org/licenses/by-sa/3.0/">CC-BY-SA</a>)',
						'maxZoom'     => 17,
					],
				],
			],
			'stadia-maps'   => [
				'label'       => __( 'Stadia Maps', 'kntnt-gpx-blocks' ),
				'requiresKey' => true,
				'default'     => 'outdoors',
				'signupUrl'   => 'https://stadiamaps.com/',
				'styles'      => [
					'alidade-smooth'      => [
						'label'       => __( 'Alidade Smooth', 'kntnt-gpx-blocks' ),
						'url'         => 'https://tiles.stadiamaps.com/tiles/alidade_smooth/{z}/{x}/{y}.png?api_key={KEY}',
						'attribution' => '&copy; <a href="https://stadiamaps.com/">Stadia Maps</a> &copy; <a href="https://openmaptiles.org/">OpenMapTiles</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 20,
					],
					'alidade-smooth-dark' => [
						'label'       => __( 'Alidade Smooth Dark', 'kntnt-gpx-blocks' ),
						'url'         => 'https://tiles.stadiamaps.com/tiles/alidade_smooth_dark/{z}/{x}/{y}.png?api_key={KEY}',
						'attribution' => '&copy; <a href="https://stadiamaps.com/">Stadia Maps</a> &copy; <a href="https://openmaptiles.org/">OpenMapTiles</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 20,
					],
					'outdoors'            => [
						'label'       => __( 'Outdoors', 'kntnt-gpx-blocks' ),
						'url'         => 'https://tiles.stadiamaps.com/tiles/outdoors/{z}/{x}/{y}.png?api_key={KEY}',
						'attribution' => '&copy; <a href="https://stadiamaps.com/">Stadia Maps</a> &copy; <a href="https://openmaptiles.org/">OpenMapTiles</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 20,
					],
					'osm-bright'          => [
						'label'       => __( 'OSM Bright', 'kntnt-gpx-blocks' ),
						'url'         => 'https://tiles.stadiamaps.com/tiles/osm_bright/{z}/{x}/{y}.png?api_key={KEY}',
						'attribution' => '&copy; <a href="https://stadiamaps.com/">Stadia Maps</a> &copy; <a href="https://openmaptiles.org/">OpenMapTiles</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 20,
					],
					'stamen-toner'        => [
						'label'       => __( 'Stamen Toner', 'kntnt-gpx-blocks' ),
						'url'         => 'https://tiles.stadiamaps.com/tiles/stamen_toner/{z}/{x}/{y}.png?api_key={KEY}',
						'attribution' => '&copy; <a href="https://stadiamaps.com/">Stadia Maps</a> &copy; <a href="https://stamen.com/">Stamen Design</a> &copy; <a href="https://openmaptiles.org/">OpenMapTiles</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 20,
					],
					'stamen-watercolor'   => [
						'label'       => __( 'Stamen Watercolor', 'kntnt-gpx-blocks' ),
						'url'         => 'https://tiles.stadiamaps.com/tiles/stamen_watercolor/{z}/{x}/{y}.jpg?api_key={KEY}',
						'attribution' => '&copy; <a href="https://stadiamaps.com/">Stadia Maps</a> &copy; <a href="https://stamen.com/">Stamen Design</a> &copy; <a href="https://openmaptiles.org/">OpenMapTiles</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 16,
					],
				],
			],
			'thunderforest' => [
				'label'       => __( 'Thunderforest', 'kntnt-gpx-blocks' ),
				'requiresKey' => true,
				'default'     => 'outdoor',
				'signupUrl'   => 'https://www.thunderforest.com/',
				'styles'      => [
					'atlas'        => [
						'label'       => __( 'Atlas', 'kntnt-gpx-blocks' ),
						'url'         => 'https://tile.thunderforest.com/atlas/{z}/{x}/{y}.png?apikey={KEY}',
						'attribution' => 'Maps &copy; <a href="https://www.thunderforest.com/">Thunderforest</a>, Data &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 22,
					],
					'landscape'    => [
						'label'       => __( 'Landscape', 'kntnt-gpx-blocks' ),
						'url'         => 'https://tile.thunderforest.com/landscape/{z}/{x}/{y}.png?apikey={KEY}',
						'attribution' => 'Maps &copy; <a href="https://www.thunderforest.com/">Thunderforest</a>, Data &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 22,
					],
					'opencyclemap' => [
						'label'       => __( 'OpenCycleMap', 'kntnt-gpx-blocks' ),
						'url'         => 'https://tile.thunderforest.com/cycle/{z}/{x}/{y}.png?apikey={KEY}',
						'attribution' => 'Maps &copy; <a href="https://www.thunderforest.com/">Thunderforest</a>, Data &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 22,
					],
					'outdoor'      => [
						'label'       => __( 'Outdoors', 'kntnt-gpx-blocks' ),
						'url'         => 'https://tile.thunderforest.com/outdoors/{z}/{x}/{y}.png?apikey={KEY}',
						'attribution' => 'Maps &copy; <a href="https://www.thunderforest.com/">Thunderforest</a>, Data &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						'maxZoom'     => 22,
					],
				],
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
	 * The fallback exists so `resolve_provider()` can always return a
	 * record, even when a misconfigured filter callback has dropped every
	 * default record. Logs a warning when re-injection happens because
	 * losing the fallback indicates a serious misconfiguration upstream.
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
		$defaults  = self::default_providers();
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
