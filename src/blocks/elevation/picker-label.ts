/**
 * Three-tier label resolver for the Data Source picker.
 *
 * The Data Source `SelectControl` shows one entry per configured GPX Map
 * block on the page. Each entry's user-facing label is resolved through
 * a three-tier fallback:
 *
 *   1. The block's user-given name — `attributes.metadata.name` (the
 *      value WordPress 6.5+ writes when the user invokes "Rename" from
 *      the List View). Used when present and non-whitespace.
 *   2. The block's HTML anchor — `attributes.anchor` (set via the block
 *      inspector's Advanced panel). Used when tier 1 is unset and the
 *      anchor is non-empty.
 *   3. A translatable generic fallback — `GPX Map #N`, where `N` is the
 *      1-based index of this block among **all** GPX Map blocks on the
 *      page in document order (configured or not). Counting *all* maps
 *      so the number the user sees in the picker matches what they see
 *      when they scroll through the post — counting only configured
 *      blocks would skip-number any unconfigured one above.
 *
 * Tiers 1 and 2 do not auto-disambiguate — two blocks the user has
 * named identically (or sharing an anchor) appear identically in the
 * picker. That is the user's own choice; the picker reflects what the
 * user typed.
 *
 * @since 1.0.0
 */

import { __, sprintf } from '@wordpress/i18n';

/**
 * Minimal attribute shape the resolver reads. Reflects how the live
 * editor surfaces the two metadata sources — both are optional and
 * either may be absent on any given Map block.
 *
 * @since 1.0.0
 */
export interface PickerLabelAttributes {
	readonly metadata?: { readonly name?: string };
	readonly anchor?: string;
}

/**
 * Resolves the picker label for a single GPX Map block.
 *
 * Pure function — no React, no state, no side effects. Exercised
 * directly by `picker-label.test.ts` and consumed by
 * `use-map-blocks.ts` when building the `SelectControl` option list.
 *
 * @since 1.0.0
 *
 * @param attributes Map block attributes. Only `metadata.name` and
 *                   `anchor` are read; everything else is ignored.
 * @param index      1-based index of this block among **all** GPX Map
 *                   blocks on the page (configured or not). Used as
 *                   the `N` in the tier-3 fallback string `GPX Map #N`.
 * @return The user-facing label, already translated through `__()`.
 */
export function pickerLabel(
	attributes: PickerLabelAttributes,
	index: number
): string {
	const name = attributes.metadata?.name;
	if ( typeof name === 'string' && name.trim() !== '' ) {
		return name;
	}

	const anchor = attributes.anchor;
	if ( typeof anchor === 'string' && anchor !== '' ) {
		return anchor;
	}

	return sprintf(
		/* translators: %d is the 1-based index of a GPX Map block on the page. */
		__( 'GPX Map #%d', 'kntnt-gpx-blocks' ),
		index
	);
}
