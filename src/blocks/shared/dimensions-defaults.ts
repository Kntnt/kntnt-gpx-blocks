/**
 * Editor-side normaliser for the plugin's per-block `min-height` default.
 *
 * Mirrors `Kntnt\Gpx_Blocks\Rendering\Dimensions_Defaults` on the editor
 * side. Both blocks follow the same symmetric rule: the default fires
 * whenever `style.dimensions.minHeight` is blank, regardless of
 * `style.dimensions.aspectRatio`. The injected value acts as a single
 * height floor that any user-set `aspectRatio` stacks alongside via the
 * normal CSS cascade. The editor never writes back to the attribute
 * store — the user still sees a blank Minimum height field in the
 * Dimensions panel.
 *
 * Issue #117 introduced the filter; issue #146 simplified Map's gate
 * to match Elevation's, removing the previous dual-mechanism baseline.
 *
 * `getDefaultMinHeight()` returns the per-block default string (or
 * `undefined` when the block is not one of the plugin's two blocks).
 * Used by `MapEdit` / `ElevationEdit` to inject the default inline on
 * the wrapper whenever `style.dimensions.minHeight` is blank.
 *
 * @since 1.0.0
 */

/**
 * Per-block default `min-height` configuration.
 *
 * `value` is the CSS string injected onto `style.dimensions.minHeight`
 * whenever that attribute is blank or missing. Kept in lock-step with
 * `Dimensions_Defaults` on the PHP side.
 *
 * @since 1.0.0
 */
interface BlockDefault {
	readonly value: string;
}

/**
 * Per-block default table. Drifting from
 * `Dimensions_Defaults::DEFAULTS` on the PHP side would make the
 * editor preview and the frontend wrapper render at different sizes.
 *
 * @since 1.0.0
 */
const DEFAULTS: Readonly< Record< string, BlockDefault > > = {
	'kntnt-gpx-blocks/map': {
		value: '30vh',
	},
	'kntnt-gpx-blocks/elevation': {
		value: '15vh',
	},
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
 * Reads `attributes.style.dimensions.minHeight` without coercing
 * missing path segments into existence.
 *
 * @since 1.0.0
 *
 * @param attributes Saved attribute object as forwarded by Gutenberg.
 * @return The value, or `undefined` when the segment is absent.
 */
function readMinHeight( attributes: Record< string, unknown > ): unknown {
	const style = ( attributes as { style?: unknown } ).style;
	const dimensions =
		style && typeof style === 'object'
			? ( style as { dimensions?: unknown } ).dimensions
			: undefined;
	const dimsObject =
		dimensions && typeof dimensions === 'object'
			? ( dimensions as { minHeight?: unknown } )
			: {};
	return dimsObject.minHeight;
}

/**
 * Returns the per-block default `min-height` value, or `undefined`
 * when the block is unknown or the user has set their own minimum
 * height.
 *
 * The rule is symmetric across both blocks: the default fires
 * whenever `style.dimensions.minHeight` is blank, regardless of
 * `style.dimensions.aspectRatio`. Matches the server-side
 * `Dimensions_Defaults` filter exactly, so the editor preview and
 * the frontend wrapper always agree on which path is taken.
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
	const entry = DEFAULTS[ blockName ];
	if ( entry === undefined ) {
		return undefined;
	}

	if ( ! isBlank( readMinHeight( attributes ) ) ) {
		return undefined;
	}

	return entry.value;
}
