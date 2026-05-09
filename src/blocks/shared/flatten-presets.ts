/**
 * Shared utility for normalising preset arrays returned by `useSettings`.
 *
 * Several `useSettings` paths — notably `typography.fontFamilies` and
 * `typography.fontSizes` — return an origin-keyed object of the shape
 * `{ default, theme, custom }` rather than a flat array, mirroring how WP
 * core stores theme.json presets internally. Components such as
 * `__experimentalFontFamilyControl` and `FontSizePicker` expect a flat
 * array and call `.map()` on the prop, which throws
 * `TypeError: r.map is not a function` for the origin-keyed shape.
 *
 * The flatten order — `custom → theme → default` — matches WordPress
 * core's `getMergedFontFamiliesPresets` helper, so user-defined presets
 * win over theme presets, which in turn win over defaults.
 *
 * @since 0.4.1
 */

/**
 * Origin-keyed shape returned by `useSettings` for multi-origin settings.
 *
 * @since 0.4.1
 */
interface OriginKeyedPresets< T > {
	default?: T[];
	theme?: T[];
	custom?: T[];
}

/**
 * Type guard that recognises the origin-keyed shape.
 *
 * @since 0.4.1
 *
 * @param value Candidate value from `useSettings`.
 * @return True when the value is a non-array object exposing any of the
 *         known origin buckets.
 */
function isOriginKeyed< T >(
	value: unknown
): value is OriginKeyedPresets< T > {
	if (
		typeof value !== 'object' ||
		value === null ||
		Array.isArray( value )
	) {
		return false;
	}
	const candidate = value as Record< string, unknown >;
	return (
		Array.isArray( candidate.custom ) ||
		Array.isArray( candidate.theme ) ||
		Array.isArray( candidate.default )
	);
}

/**
 * Flattens a preset value coming from `useSettings` into a plain array,
 * regardless of whether the underlying setting is exposed as an array or
 * as an origin-keyed object.
 *
 * The `useSettings` typings in `@wordpress/block-editor` are effectively
 * `unknown`, so the helper accepts `unknown` and narrows internally.
 *
 * @since 0.4.1
 *
 * @param value Raw value from `useSettings`. May be an array, an
 *              origin-keyed object, or `undefined`/`null` when the
 *              setting is not configured.
 * @return Flat array of presets. Empty array when no presets exist.
 */
export function flattenPresets< T >( value: unknown ): T[] {
	if ( ! value ) {
		return [];
	}

	if ( Array.isArray( value ) ) {
		return value as T[];
	}

	if ( isOriginKeyed< T >( value ) ) {
		return [
			...( value.custom ?? [] ),
			...( value.theme ?? [] ),
			...( value.default ?? [] ),
		];
	}

	return [];
}
