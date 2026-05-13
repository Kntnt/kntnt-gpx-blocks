/**
 * Hook that fetches the bound GPX Map's cached payload through the
 * plugin's editor-only REST endpoint.
 *
 * Wraps `Rest\Preview_Controller` (`kntnt-gpx-blocks/v1/preview/<id>`)
 * via `@wordpress/api-fetch`. The endpoint returns
 * `{ geojson, statistics }`; this hook surfaces both fields to its
 * caller along with the loading / error state needed to drive the
 * Step 2 placeholder boxes.
 *
 * Single-fetch semantics: each `attachmentId` is fetched at most once
 * per editor session because `@wordpress/api-fetch` keys its internal
 * cache by URL. Switching the attachment triggers a new fetch; switching
 * back hits the cache without a server round-trip.
 *
 * From Step 3 onward the chart consumes `data.geojson` from the same
 * hook without modifying it, so the GeoJSON arrives in the editor
 * preview through the same path as the Step 2 statistics.
 *
 * @since 1.0.0
 */

import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Shape of the REST endpoint's success response.
 *
 * Mirrors `Preview_Controller::get_preview()`'s `WP_REST_Response`
 * payload as updated in Step 2 (`{ geojson, statistics }`). The
 * statistics shape matches `Statistics_Calculator`'s contract: every
 * numeric value may be `null` when the track lacks the underlying data
 * (e.g. `min_elevation === null` on a track without `<ele>` tags).
 *
 * @since 1.0.0
 */
export interface BoundMapPayload {
	readonly geojson: Record< string, unknown >;
	readonly statistics: {
		readonly distance: number | null;
		readonly min_elevation: number | null;
		readonly max_elevation: number | null;
		readonly ascent: number | null;
		readonly descent: number | null;
	};
	readonly samples: ReadonlyArray< readonly [ number, number ] >;
}

/**
 * Shape of a WordPress REST error object surfaced by `apiFetch`.
 *
 * @since 1.0.0
 */
export interface BoundMapPayloadError {
	readonly code?: string;
	readonly message?: string;
}

/**
 * Result of {@link useBoundMapPayload}.
 *
 * Exactly one of `data`, `isLoading`, or `error` is "active" at any
 * given moment:
 *
 *   - `attachmentId <= 0`             → all three are nullish/false.
 *   - In flight                       → `isLoading === true`.
 *   - Fetch succeeded                 → `data !== null`.
 *   - Fetch failed                    → `error !== null`.
 *
 * @since 1.0.0
 */
export interface UseBoundMapPayloadResult {
	readonly data: BoundMapPayload | null;
	readonly isLoading: boolean;
	readonly error: BoundMapPayloadError | null;
}

/**
 * Fetches the bound Map's cached payload.
 *
 * Cancellation guards against late responses overwriting newer state
 * when the editor switches attachments rapidly. An `attachmentId` of
 * `0` or less (the "no Map bound yet" state) clears the data and skips
 * the network call entirely.
 *
 * @since 1.0.0
 *
 * @param attachmentId Attachment ID of the bound GPX file. Pass `0`
 *                     when no Map is bound (the hook then returns the
 *                     empty result).
 * @return Loading / data / error tuple; see
 *         {@link UseBoundMapPayloadResult}.
 */
export function useBoundMapPayload(
	attachmentId: number
): UseBoundMapPayloadResult {
	const [ data, setData ] = useState< BoundMapPayload | null >( null );
	const [ isLoading, setLoading ] = useState< boolean >( false );
	const [ error, setError ] = useState< BoundMapPayloadError | null >( null );

	useEffect( () => {
		if ( attachmentId <= 0 ) {
			setData( null );
			setLoading( false );
			setError( null );
			return;
		}

		let cancelled = false;
		setLoading( true );
		setError( null );

		apiFetch< BoundMapPayload >( {
			path: `/kntnt-gpx-blocks/v1/preview/${ attachmentId }`,
		} )
			.then( ( payload ) => {
				if ( cancelled ) {
					return;
				}
				setData( payload );
				setLoading( false );
			} )
			.catch( ( err: BoundMapPayloadError ) => {
				if ( cancelled ) {
					return;
				}
				setError( err );
				setData( null );
				setLoading( false );
			} );

		return () => {
			cancelled = true;
		};
	}, [ attachmentId ] );

	return { data, isLoading, error };
}
