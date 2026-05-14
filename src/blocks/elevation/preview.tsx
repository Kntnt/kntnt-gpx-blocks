/**
 * Editor preview surface for the Elevation block.
 *
 * Routes the resolved binding state to the right preview body. Pure
 * React: no Interactivity runtime, no `<ServerSideRender>`, no DOM
 * polling — see `docs/elevation-rebuild.md` § *Rendering architecture*
 * for the rationale.
 *
 * The component is a discriminated union on the resolved binding state.
 * From Step 3 onward the union carries six warning kinds, a transient
 * `loading` kind that renders nothing, and the `healthy` kind that
 * forwards the cached statistics into {@link Chart}:
 *
 *   - `'no-map'`             → no configured Map on the page.
 *   - `'bound-deleted'`      → bound `mapId` does not match any
 *                               configured Map.
 *   - `'bound-unconfigured'` → bound block exists but has no GPX
 *                               file selected.
 *   - `'no-elevation-data'`  → bound track has no `<ele>` data
 *                               (Step 3 Case A).
 *   - `'zero-distance'`      → bound track has zero distance
 *                               (Step 3 Case C).
 *   - `'payload-error'`      → REST fetch for the bound Map failed
 *                               (editor-only; the frontend cannot
 *                               reach this state because PHP server-
 *                               renders the state payload).
 *   - `'loading'`            → bound Map's payload is being fetched
 *                               or the auto-pick effect is about to
 *                               fire. Renders nothing; the wrapper
 *                               still occupies its `min-height: 15vh`
 *                               slot.
 *   - `'healthy'`            → binding resolved and the cached
 *                               statistics are usable; renders
 *                               {@link Chart} with the data and the
 *                               Tick-labels typography.
 *
 * Warning strings are translated through the `kntnt-gpx-blocks` text
 * domain. The boxes are coloured via inline styles so the surface
 * needs no companion stylesheet beyond the new wrapper baseline.
 *
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';

import { Chart, type ElevationSample } from './chart';
import type { MarginsInput } from './geometry/margins';
import type { TypographyAttributes } from './geometry/measure';

/**
 * Warning kinds the editor preview surfaces.
 *
 * @since 1.0.0
 */
export type WarningKind =
	| 'no-map'
	| 'bound-deleted'
	| 'bound-unconfigured'
	| 'no-elevation-data'
	| 'zero-distance'
	| 'payload-error';

/**
 * Discriminated union of the binding states the preview renders.
 *
 * @since 1.0.0
 */
export type PreviewState =
	| { readonly kind: WarningKind }
	| { readonly kind: 'loading' }
	| {
			readonly kind: 'healthy';
			readonly data: MarginsInput;
			readonly samples: readonly ElevationSample[];
			readonly typography: TypographyAttributes;
			readonly showCursor: boolean;
			readonly showVerticalGuide: boolean;
			readonly showHorizontalGuide: boolean;
			readonly tooltipShowDistance: boolean;
			readonly tooltipShowHeight: boolean;
	  };

/**
 * Inline style for the warning box (light red background with a red
 * left border).
 *
 * @since 1.0.0
 */
const WARNING_STYLE: React.CSSProperties = {
	padding: '0.75em 1em',
	backgroundColor: '#fdecea',
	borderLeft: '4px solid #d93025',
	color: '#5f2120',
};

/**
 * Returns the warning string for one of the six warning kinds.
 *
 * Exported so tests can pin each string against the spec table
 * without re-running the full component.
 *
 * @since 1.0.0
 *
 * @param kind The warning kind to format.
 * @return The localised warning string.
 */
export function warningMessage( kind: WarningKind ): string {
	switch ( kind ) {
		case 'no-map':
			return __(
				'There is no GPX Map block with a selected GPX file on this page. Add a GPX Map block before this one.',
				'kntnt-gpx-blocks'
			);
		case 'bound-deleted':
			return __(
				'The GPX Map block this block was bound to is no longer on the page. Pick another from the dropdown.',
				'kntnt-gpx-blocks'
			);
		case 'bound-unconfigured':
			return __(
				'The GPX Map block this block is bound to has no GPX file selected.',
				'kntnt-gpx-blocks'
			);
		case 'no-elevation-data':
			return __(
				'The bound GPX track has no elevation data. The elevation profile cannot be rendered.',
				'kntnt-gpx-blocks'
			);
		case 'zero-distance':
			return __(
				'The bound GPX track has no distance (all points are at the same location).',
				'kntnt-gpx-blocks'
			);
		case 'payload-error':
			return __(
				'Could not fetch data for the bound GPX track. Try reloading the page.',
				'kntnt-gpx-blocks'
			);
	}
}

/**
 * Renders the appropriate placeholder body for the given preview state.
 *
 * The `loading` branch returns `null` deliberately — the outer wrapper
 * retains its `min-height: 15vh` slot, and the chart pops in as soon as
 * the bound Map's payload lands. Sub-100 ms fetch latency is invisible
 * to the eye and a spinner / skeleton would only add visual noise.
 *
 * @since 1.0.0
 *
 * @param props       Render props.
 * @param props.state Resolved binding state.
 */
export function ElevationPreview( {
	state,
}: {
	readonly state: PreviewState;
} ): JSX.Element | null {
	switch ( state.kind ) {
		case 'no-map':
		case 'bound-deleted':
		case 'bound-unconfigured':
		case 'no-elevation-data':
		case 'zero-distance':
		case 'payload-error':
			return (
				<div
					className="kntnt-gpx-blocks-elevation-preview-warning"
					style={ WARNING_STYLE }
				>
					{ warningMessage( state.kind ) }
				</div>
			);
		case 'loading':
			return null;
		case 'healthy':
			return (
				<Chart
					data={ state.data }
					samples={ state.samples }
					typography={ state.typography }
					showCursor={ state.showCursor }
					showVerticalGuide={ state.showVerticalGuide }
					showHorizontalGuide={ state.showHorizontalGuide }
					tooltipShowDistance={ state.tooltipShowDistance }
					tooltipShowHeight={ state.tooltipShowHeight }
				/>
			);
	}
}
