/**
 * Editor-side normaliser for the plugin's per-block `min-height` default.
 *
 * Mirrors `Kntnt\Gpx_Blocks\Rendering\Dimensions_Defaults` on the editor
 * side. Both surfaces apply per-block rules: Map's default fires only
 * when both `style.dimensions.minHeight` *and* `style.dimensions
 * .aspectRatio` are blank or missing (so an explicit aspect ratio is
 * not fought by a hidden min-height); Elevation's default fires
 * whenever `style.dimensions.minHeight` is blank, regardless of
 * `aspectRatio` (the Step 3 wrapper has no aspect-ratio baseline of
 * its own, so the default acts as a harmless floor). The editor never
 * writes back to the attribute store — the user still sees a blank
 * Minimum height field in the Dimensions panel.
 *
 * Issue #117. Replaces the per-component inline `minHeightDefault`
 * spread / conditional that used to live in `src/blocks/map/edit.tsx`
 * and `src/blocks/elevation/edit.tsx`.
 *
 * `getDefaultMinHeight()` returns the per-block default string (or
 * `undefined` when the block is not one of the plugin's two blocks).
 * Used by `MapEdit` / `ElevationEdit` to inject the default inline on
 * the wrapper only when both `style.dimensions.minHeight` and
 * `style.dimensions.aspectRatio` are blank or missing; otherwise the
 * inline injection is skipped and the editor's block-supports
 * machinery is left to surface any user-set values.
 *
 * @since 1.0.0
 */

/**
 * Per-block default `min-height` configuration.
 *
 * `value` is the CSS string injected onto `style.dimensions.minHeight`
 * when the block's gate condition holds. `requireBlankAspectRatio`
 * controls the gate: when `true`, the default applies only when both
 * `minHeight` and `aspectRatio` are blank — the legacy Map condition
 * that protects against fighting an explicit aspect ratio. When
 * `false`, the default applies whenever `minHeight` is blank,
 * regardless of `aspectRatio` — the simpler Elevation rule introduced
 * in Step 3 of `docs/elevation-rebuild.md`, where the wrapper's
 * baseline is `min-height: 15vh` with no aspect-ratio of its own, so
 * the default acts as a floor that coexists harmlessly with any
 * user-set aspect ratio.
 *
 * Kept in lock-step with `Dimensions_Defaults` on the PHP side.
 *
 * @since 1.0.0
 */
interface BlockDefault {
	readonly value: string;
	readonly requireBlankAspectRatio: boolean;
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
		requireBlankAspectRatio: true,
	},
	'kntnt-gpx-blocks/elevation': {
		value: '15vh',
		requireBlankAspectRatio: false,
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
 * Decides whether a saved `aspectRatio` value should be treated as
 * "no ratio set".
 *
 * Extends `isBlank` with the CSS keyword `'auto'`. WordPress writes
 * `'auto'` to `attrs.style.dimensions.aspectRatio` when the user picks
 * the "Original" option in the aspect-ratio dropdown after having
 * selected another ratio first. Semantically `'auto'` means "no
 * aspect-ratio constraint" — the same end-state as a blank or missing
 * value — so the per-block default `min-height` should still apply.
 *
 * @since 1.0.0
 *
 * @param value Raw value pulled from `attrs.style.dimensions.aspectRatio`.
 * @return `true` when the value should be treated as no ratio set.
 */
function isBlankAspectRatio( value: unknown ): boolean {
	return isBlank( value ) || value === 'auto';
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
 * The rule fires per-block: Map gates on both `minHeight` *and*
 * `aspectRatio` being blank or missing; Elevation gates on
 * `minHeight` alone. Both gates match the server-side
 * `Dimensions_Defaults` filter exactly, so the editor preview and the
 * frontend wrapper always agree on which path is taken.
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

	const { minHeight, aspectRatio } = readDimensions( attributes );
	if ( ! isBlank( minHeight ) ) {
		return undefined;
	}
	if (
		entry.requireBlankAspectRatio &&
		! isBlankAspectRatio( aspectRatio )
	) {
		return undefined;
	}

	return entry.value;
}
