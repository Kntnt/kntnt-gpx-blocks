/**
 * useful-value wrapper layer for inspector controls.
 *
 * Bridges the three-state attribute shape Gutenberg inspector controls
 * produce (missing, present-but-empty, present-and-non-default) to the
 * two-state contract the renderer needs (a usable value plus a presence
 * flag that drives the `ToolsPanelItem`'s `hasValue` / `onDeselect`
 * pair). Without this layer every `edit.tsx` call-site would have to
 * branch on `value === undefined || value === ''` before passing the
 * value to inline styles, and the inspector ResetAll path would have
 * to re-implement the same emptiness check.
 *
 * The wrapper is a pure function — no React state, no closures over
 * callbacks — so it can be called inside or outside render, and the
 * caller chooses when to read `raw` / `resolved` / `hasValue` and when
 * to invoke `set` / `reset`.
 *
 * Call-sites supply their own fallback values and, where the default
 * empty-detection doesn't fit, a value-specific `isEmpty` predicate
 * (e.g. for boolean or numeric attributes). The API surface — the
 * `usefulValue` function and the `UsefulValue<T>` return type — is the
 * commitment.
 *
 * @since 1.0.0
 */

/**
 * Return shape of the wrapper.
 *
 * `raw` is the verbatim attribute value (including `undefined` when
 * the attribute is missing). `resolved` is `raw` when it is non-empty,
 * otherwise the configured fallback. `hasValue` is the negation of
 * the emptiness check — `true` means the user has set the control to
 * a non-default value. `set` writes a new value to the attribute key;
 * `reset` writes the canonical empty marker (`''`).
 *
 * @since 1.0.0
 */
export interface UsefulValue< T > {
	readonly raw: T | undefined;
	readonly resolved: T;
	readonly hasValue: boolean;
	readonly set: ( value: T ) => void;
	readonly reset: () => void;
}

/**
 * Default emptiness predicate.
 *
 * Treats `undefined` and the empty string as empty. Callers with a
 * different empty shape (e.g. `'0'`, `'auto'`, `null`) pass a custom
 * predicate via the `isEmpty` parameter of {@link usefulValue}.
 *
 * @since 1.0.0
 *
 * @param value Raw attribute value.
 * @return `true` when the value should be treated as not set.
 */
function defaultIsEmpty< T >( value: T | undefined ): boolean {
	return value === undefined || ( value as unknown ) === '';
}

/**
 * Wraps a single attribute key with fallback / presence semantics.
 *
 * @since 1.0.0
 *
 * @param attributes    Block attribute bag forwarded from `edit.tsx`.
 * @param setAttributes Block setter forwarded from `edit.tsx`.
 * @param key           Attribute name to read and write.
 * @param fallback      Value substituted for `resolved` when the
 *                      attribute reads as empty.
 * @param isEmpty       Optional predicate. Defaults to "undefined or
 *                      empty string". Pass a custom predicate when the
 *                      attribute's empty shape differs.
 * @return The {@link UsefulValue} bundle described above.
 */
export function usefulValue< T >(
	attributes: Record< string, unknown >,
	setAttributes: ( next: Record< string, unknown > ) => void,
	key: string,
	fallback: T,
	isEmpty: ( value: T | undefined ) => boolean = defaultIsEmpty
): UsefulValue< T > {
	const raw = attributes[ key ] as T | undefined;
	const empty = isEmpty( raw );

	return {
		raw,
		resolved: empty ? fallback : ( raw as T ),
		hasValue: ! empty,
		set: ( value: T ) => setAttributes( { [ key ]: value } ),
		reset: () => setAttributes( { [ key ]: '' } ),
	};
}
