/**
 * Editor preview surface for the Elevation block in Step 2.
 *
 * Renders the warning / info placeholder boxes the block exposes
 * before the SVG chart lands in Step 3. Pure React: no Interactivity
 * runtime, no `<ServerSideRender>`, no DOM polling — see
 * `docs/elevation-rebuild.md` § *Editor preview architecture (locked by
 * the Step 2 grilling)* for the rationale.
 *
 * The component is a discriminated union on the resolved binding state:
 *
 *   - `'no-map'`            → no configured Map on the page.
 *   - `'bound-deleted'`     → the bound `mapId` does not match any
 *                              configured Map (deleted or its `mapId`
 *                              was changed away).
 *   - `'bound-unconfigured'`→ the bound block exists but has no GPX
 *                              file selected (`attachmentId === 0`).
 *   - `'loading'`           → the bound Map's payload is being fetched.
 *   - `'error'`             → the REST fetch for the bound Map's
 *                              payload failed (`Preview_Controller`
 *                              surfaced a cache error).
 *   - `'healthy'`           → the binding resolved and the cached
 *                              statistics are available; the info-box
 *                              shows the bound label plus min/max
 *                              elevation rounded to integers.
 *
 * All three Step 2 warning strings are translated through the
 * `kntnt-gpx-blocks` text domain. The boxes are coloured via inline
 * styles so Step 2 needs no companion stylesheet — Step 3 promotes
 * the styling into `style.scss` when the SVG appears.
 *
 * @since 1.0.0
 */

import { __, sprintf } from '@wordpress/i18n';

/**
 * Discriminated union of the binding states the preview renders.
 *
 * @since 1.0.0
 */
export type PreviewState =
	| { readonly kind: 'no-map' }
	| { readonly kind: 'bound-deleted' }
	| { readonly kind: 'bound-unconfigured' }
	| { readonly kind: 'loading' }
	| { readonly kind: 'error'; readonly message?: string }
	| {
			readonly kind: 'healthy';
			readonly label: string;
			readonly min: number;
			readonly max: number;
	  };

/**
 * Inline style for the warning box (light red background with a red
 * left border). Kept inline so Step 2 needs no companion stylesheet.
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
 * Inline style for the info box (light blue background with a blue
 * left border).
 *
 * @since 1.0.0
 */
const INFO_STYLE: React.CSSProperties = {
	padding: '0.75em 1em',
	backgroundColor: '#e8f0fe',
	borderLeft: '4px solid #1a73e8',
	color: '#0b3d91',
};

/**
 * Returns the warning string for one of the three "broken binding"
 * states. Exported so tests can pin each string against the spec table
 * without re-running the full component.
 *
 * @since 1.0.0
 *
 * @param kind The warning kind to format.
 * @return The localised warning string.
 */
export function warningMessage(
	kind: 'no-map' | 'bound-deleted' | 'bound-unconfigured'
): string {
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
	}
}

/**
 * Returns the info-box message for the healthy state.
 *
 * @since 1.0.0
 *
 * @param label The bound Map's picker label (three-tier rule).
 * @param min   Minimum elevation, rounded to an integer.
 * @param max   Maximum elevation, rounded to an integer.
 * @return The localised "Bound to …" string.
 */
export function healthyMessage(
	label: string,
	min: number,
	max: number
): string {
	return sprintf(
		/* translators: 1: bound Map label, 2: minimum elevation in metres, 3: maximum elevation in metres. */
		__( 'Bound to %1$s. Min: %2$d m, Max: %3$d m.', 'kntnt-gpx-blocks' ),
		label,
		min,
		max
	);
}

/**
 * Renders the appropriate placeholder box for the given preview state.
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
} ): JSX.Element {
	switch ( state.kind ) {
		case 'no-map':
		case 'bound-deleted':
		case 'bound-unconfigured':
			return (
				<div
					className="kntnt-gpx-blocks-elevation-preview-warning"
					style={ WARNING_STYLE }
				>
					{ warningMessage( state.kind ) }
				</div>
			);
		case 'error':
			return (
				<div
					className="kntnt-gpx-blocks-elevation-preview-warning"
					style={ WARNING_STYLE }
				>
					{ state.message ??
						__(
							'The bound GPX Map could not be loaded.',
							'kntnt-gpx-blocks'
						) }
				</div>
			);
		case 'loading':
			return (
				<div
					className="kntnt-gpx-blocks-elevation-preview-info"
					style={ INFO_STYLE }
				>
					{ __( 'Loading bound GPX Map…', 'kntnt-gpx-blocks' ) }
				</div>
			);
		case 'healthy':
			return (
				<div
					className="kntnt-gpx-blocks-elevation-preview-info"
					style={ INFO_STYLE }
				>
					{ healthyMessage( state.label, state.min, state.max ) }
				</div>
			);
	}
}
