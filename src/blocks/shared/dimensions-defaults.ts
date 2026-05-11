/**
 * Editor-side normaliser for the plugin's per-block `min-height` default.
 *
 * Mirrors `Kntnt\Gpx_Blocks\Rendering\Dimensions_Defaults` on the editor
 * side. Both surfaces apply the same rule: when both
 * `style.dimensions.minHeight` and `style.dimensions.aspectRatio` are
 * blank or missing on a Map or Elevation block, treat
 * `style.dimensions.minHeight` as the per-block default value before
 * any downstream consumer (`useBlockProps()`, the SCSS baseline, the
 * editor preview wrapper) reads it. The editor never writes back to
 * the attribute store — the user still sees a blank Minimum height
 * field in the Dimensions panel.
 *
 * Issue #117. Replaces the per-component inline `minHeightDefault`
 * spread / conditional that used to live in `src/blocks/map/edit.tsx`
 * and `src/blocks/elevation/edit.tsx`.
 *
 * Two functions are exported:
 *
 *  - `normaliseDimensionsAttributes()` — returns a copy of the
 *    attribute object with the default written into
 *    `style.dimensions.minHeight` when the rule fires. Returns the
 *    same reference (`===`) when nothing needs to change, so React
 *    memos downstream do not see spurious churn. Suited to callers
 *    that want a fully-normalised attribute object — for example a
 *    `editor.BlockEdit` HOC filter that forwards the substituted
 *    attributes onward.
 *
 *  - `getDefaultMinHeight()` — returns the per-block default string
 *    (or `undefined` when the block is not one of the plugin's two
 *    blocks). Used by `MapEdit` / `ElevationEdit` to inject the
 *    default inline on the wrapper only when both
 *    `style.dimensions.minHeight` and `style.dimensions.aspectRatio`
 *    are blank or missing; otherwise the inline injection is skipped
 *    and the editor's block-supports machinery is left to surface
 *    any user-set values.
 *
 * @since 1.0.0
 */

/**
 * Per-block default `min-height` value applied when both `minHeight`
 * and `aspectRatio` are blank or missing on the saved attributes.
 *
 * Kept in lock-step with `Dimensions_Defaults::DEFAULTS` on the PHP
 * side. Drifting from the PHP map would let the editor preview render
 * at one size while the frontend renders at another.
 *
 * @since 1.0.0
 */
const DEFAULTS: Readonly< Record< string, string > > = {
	'kntnt-gpx-blocks/map': '30vh',
	'kntnt-gpx-blocks/elevation': '15vh',
};

/**
 * Decides whether a saved dimensions value should be treated as
 * "not set".
 *
 * The editor stores an unset dimensions field as either the literal
 * empty string or as the absence of the key altogether. Any other
 * shape is treated as a real user-chosen value.
 *
 * @since 1.0.0
 *
 * @param value Raw value pulled from `attrs.style.dimensions.*`.
 * @return `true` when the value should be treated as blank.
 */
function isBlank( value: unknown ): boolean {
	return value === undefined || value === null || value === '';
}

/**
 * Reads `attributes.style.dimensions.{minHeight,aspectRatio}` without
 * coercing missing path segments into existence.
 *
 * @since 1.0.0
 *
 * @param attributes Saved attribute object as forwarded by Gutenberg.
 * @return The two values, each `undefined` when the segment is absent.
 */
function readDimensions( attributes: Record< string, unknown > ): {
	readonly minHeight: unknown;
	readonly aspectRatio: unknown;
} {
	const style = ( attributes as { style?: unknown } ).style;
	const dimensions =
		style && typeof style === 'object'
			? ( style as { dimensions?: unknown } ).dimensions
			: undefined;
	const dimsObject =
		dimensions && typeof dimensions === 'object'
			? ( dimensions as { minHeight?: unknown; aspectRatio?: unknown } )
			: {};
	return {
		minHeight: dimsObject.minHeight,
		aspectRatio: dimsObject.aspectRatio,
	};
}

/**
 * Returns the per-block default `min-height` value, or `undefined`
 * when the block is unknown or the default does not apply to the
 * saved attribute shape.
 *
 * The rule fires only when both `style.dimensions.minHeight` and
 * `style.dimensions.aspectRatio` are blank or missing. The two-field
 * gate matches the server-side `Dimensions_Defaults` filter exactly,
 * so the editor preview and the frontend wrapper always agree on
 * which path is taken.
 *
 * @since 1.0.0
 *
 * @param blockName  Block name from `block.json`'s `name` field.
 * @param attributes Saved attribute object as forwarded by Gutenberg.
 * @return The default string when it should be applied; otherwise
 *         `undefined`.
 */
export function getDefaultMinHeight(
	blockName: string,
	attributes: Record< string, unknown >
): string | undefined {
	const defaultMinHeight = DEFAULTS[ blockName ];
	if ( defaultMinHeight === undefined ) {
		return undefined;
	}

	const { minHeight, aspectRatio } = readDimensions( attributes );
	if ( ! isBlank( minHeight ) || ! isBlank( aspectRatio ) ) {
		return undefined;
	}

	return defaultMinHeight;
}

/**
 * Returns the attribute object with the plugin's `min-height` default
 * applied when the block is one of the plugin's two blocks and both
 * `style.dimensions.minHeight` and `style.dimensions.aspectRatio` are
 * blank or missing.
 *
 * When the rule does not trigger — unrelated block name, user has set
 * a minHeight, user has set a non-Original aspectRatio — returns the
 * input object referentially identical so React `useBlockProps` memos
 * downstream are not invalidated by the wrapping.
 *
 * The returned object is structurally a shallow clone of the input
 * with only `style.dimensions.minHeight` patched; the original input
 * is never mutated.
 *
 * @since 1.0.0
 *
 * @param blockName  Block name from `block.json`'s `name` field.
 * @param attributes Saved attribute object as forwarded by Gutenberg.
 * @return Effective attributes for the editor's render pipeline.
 */
export function normaliseDimensionsAttributes<
	T extends Record< string, unknown >,
>( blockName: string, attributes: T ): T {
	const defaultMinHeight = getDefaultMinHeight( blockName, attributes );
	if ( defaultMinHeight === undefined ) {
		return attributes;
	}

	const style = ( attributes as { style?: unknown } ).style;
	const dimensions =
		style && typeof style === 'object'
			? ( style as { dimensions?: unknown } ).dimensions
			: undefined;

	const baseStyle =
		style && typeof style === 'object' ? ( style as object ) : {};
	const baseDimensions =
		dimensions && typeof dimensions === 'object'
			? ( dimensions as object )
			: {};

	return {
		...( attributes as object ),
		style: {
			...baseStyle,
			dimensions: {
				...baseDimensions,
				minHeight: defaultMinHeight,
			},
		},
	} as T;
}
