/**
 * Editor-side React preview for the GPX Map block.
 *
 * The Interactivity API runtime does not bootstrap inside ServerSideRender's
 * injected DOM in the block editor, so view.ts's data-wp-init handler never
 * fires there and the SSR'd map container stays empty. This component replaces
 * the ServerSideRender preview with a native React + Leaflet mount that runs
 * inside the editor iframe.
 *
 * The preview is intentionally narrower than the frontend: no consent gating,
 * no IntersectionObserver lazy mount, no cursor sync, no controls, no waypoint
 * label typography. The point is to give the editor a visual reference for
 * "is the right GPX file selected and roughly how does it look?". Frontend
 * fidelity stays in view.ts where it belongs.
 *
 * Data flows through the plugin's REST endpoint kntnt-gpx-blocks/v1/preview/<id>,
 * gated to edit_posts. The endpoint returns the same cached GeoJSON the
 * frontend gets via wp_interactivity_state(); we just retrieve it through a
 * different channel because the editor cannot use Interactivity hydration.
 *
 * @since 1.0.0
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

/**
 * Shape of the REST endpoint's success response.
 *
 * Matches Preview_Controller::get_preview()'s WP_REST_Response payload.
 *
 * @since 1.0.0
 */
interface PreviewPayload {
	geojson: GeoJSON.GeoJsonObject;
}

/**
 * Shape of a WordPress REST error object as surfaced by `@wordpress/api-fetch`.
 *
 * The `code` field carries the same Render_Error code the server-side path
 * uses ('no-attachment', 'parse-failed', 'wrong-mime', etc.) so the editor
 * can show a localised message that matches the frontend Error_Renderer.
 *
 * @since 1.0.0
 */
interface ApiError {
	code: string;
	message: string;
}

/**
 * Attributes the preview reads. A subset of the Map block's attribute set —
 * only the ones that affect preview appearance.
 *
 * @since 1.0.0
 */
interface PreviewAttributes {
	attachmentId: number;
	aspectRatio: string;
	minHeight: string;
	maxHeight: string;
	trackColor: string;
	trackCursorColor: string;
	waypointColor: string;
}

/**
 * Props for the MapEditorPreview component.
 *
 * @since 1.0.0
 */
interface MapEditorPreviewProps {
	attributes: PreviewAttributes;
}

/**
 * Default polyline colour when the trackColor attribute is empty.
 *
 * Matches the SCSS default in src/blocks/map/style.scss so that an
 * unconfigured block looks identical between editor and frontend.
 *
 * @since 1.0.0
 */
const DEFAULT_TRACK_COLOR = '#0073aa';

/**
 * Default waypoint marker colour when the waypointColor attribute is empty.
 *
 * @since 1.0.0
 */
const DEFAULT_WAYPOINT_COLOR = '#d63638';

/**
 * Tile URL template for the OSM tile layer used in the editor preview.
 *
 * Identical to the URL used in view.ts so the editor preview matches the
 * frontend's visual appearance.
 *
 * @since 1.0.0
 */
const TILE_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';

/**
 * Tile layer attribution string. Required by OSM's usage policy.
 *
 * @since 1.0.0
 */
const TILE_ATTRIBUTION =
	'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

/**
 * Build the inline style object that mirrors what Render_Map sets on the
 * frontend wrapper, so the editor preview occupies the same dimensions and
 * inherits the same CSS custom properties.
 *
 * @since 1.0.0
 *
 * @param attributes Preview attribute subset.
 * @return React inline-style object for the wrapper div.
 */
function buildWrapperStyle(
	attributes: PreviewAttributes
): React.CSSProperties {
	const style: Record< string, string | number > = {
		'--kntnt-gpx-blocks-aspect-ratio': attributes.aspectRatio || '16/9',
		'--kntnt-gpx-blocks-min-height': attributes.minHeight || '240px',
	};

	if ( attributes.maxHeight ) {
		style.maxHeight = attributes.maxHeight;
	}
	if ( attributes.trackColor ) {
		style[ '--kntnt-gpx-blocks-track-color' ] = attributes.trackColor;
	}
	if ( attributes.trackCursorColor ) {
		style[ '--kntnt-gpx-blocks-track-cursor-color' ] =
			attributes.trackCursorColor;
	}
	if ( attributes.waypointColor ) {
		style[ '--kntnt-gpx-blocks-waypoint-color' ] = attributes.waypointColor;
	}

	return style as React.CSSProperties;
}

/**
 * Editor preview for the GPX Map block.
 *
 * Fetches the cached GeoJSON for the given attachmentId via the plugin's
 * REST endpoint, then mounts a Leaflet instance into a div ref. Re-fits the
 * map when the GeoJSON changes; tears the map down on unmount.
 *
 * The polyline colour reacts to trackColor attribute changes without a full
 * remount because Leaflet's canvas-rendered polyline cannot be styled by
 * CSS — we rebuild only the GeoJSON layer when the colour changes.
 *
 * @since 1.0.0
 *
 * @param props            Component props.
 * @param props.attributes The preview attribute subset.
 */
export const MapEditorPreview = ( {
	attributes,
}: MapEditorPreviewProps ): JSX.Element => {
	const containerRef = useRef< HTMLDivElement >( null );
	const mapRef = useRef< L.Map | null >( null );
	const layerRef = useRef< L.GeoJSON | null >( null );
	const [ payload, setPayload ] = useState< PreviewPayload | null >( null );
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ loading, setLoading ] = useState< boolean >( true );

	const { attachmentId, trackColor, waypointColor } = attributes;

	// Fetch the preview payload whenever the attachment changes. Cancellation
	// guards against late responses overwriting newer state when the editor
	// rapidly switches attachments.
	useEffect( () => {
		if ( attachmentId <= 0 ) {
			setPayload( null );
			setError( null );
			setLoading( false );
			return;
		}

		let cancelled = false;
		setLoading( true );
		setError( null );

		apiFetch< PreviewPayload >( {
			path: `/kntnt-gpx-blocks/v1/preview/${ attachmentId }`,
		} )
			.then( ( data ) => {
				if ( cancelled ) {
					return;
				}
				setPayload( data );
				setLoading( false );
			} )
			.catch( ( err: ApiError ) => {
				if ( cancelled ) {
					return;
				}
				setError( err );
				setPayload( null );
				setLoading( false );
			} );

		return () => {
			cancelled = true;
		};
	}, [ attachmentId ] );

	// Mount Leaflet once payload is available. Re-runs only when the payload
	// or attachment changes; colour-only changes go through the colour effect
	// below so we don't rebuild the whole map on every colour-picker tick.
	useEffect( () => {
		if ( ! containerRef.current || ! payload ) {
			return;
		}

		// Tear down any pre-existing map instance — the effect re-runs on
		// payload change, and Leaflet does not handle being re-initialised
		// on the same element gracefully.
		if ( mapRef.current ) {
			mapRef.current.remove();
			mapRef.current = null;
			layerRef.current = null;
		}

		// Build a Leaflet map that mirrors view.ts's frontend mount, minus
		// the consent gate, observer, controls, and cursor sync.
		const map = L.map( containerRef.current, {
			renderer: L.canvas(),
			zoomControl: false,
			attributionControl: true,
			dragging: true,
			scrollWheelZoom: false,
			doubleClickZoom: true,
			touchZoom: true,
			boxZoom: false,
			keyboard: false,
		} );
		mapRef.current = map;

		// Add the OSM tile layer. The editor preview ignores the consent
		// contract by design — see docs/consent.md "Editor behaviour".
		L.tileLayer( TILE_URL, {
			maxZoom: 19,
			attribution: TILE_ATTRIBUTION,
		} ).addTo( map );

		// Render the polyline + waypoints from the cached GeoJSON.
		const layer = L.geoJSON( payload.geojson, {
			style: () => ( {
				color: trackColor || DEFAULT_TRACK_COLOR,
				weight: 4,
				opacity: 1,
			} ),
			pointToLayer: ( _feature, latlng ) =>
				L.circleMarker( latlng, {
					radius: 6,
					color: waypointColor || DEFAULT_WAYPOINT_COLOR,
					fillColor: waypointColor || DEFAULT_WAYPOINT_COLOR,
					fillOpacity: 1,
					weight: 2,
				} ),
		} );
		layer.addTo( map );
		layerRef.current = layer;

		// Fit the viewport to the track bounds with a small padding so the
		// polyline never touches the container edges.
		const bounds = layer.getBounds();
		if ( bounds.isValid() ) {
			map.fitBounds( bounds, { padding: [ 16, 16 ] } );
		}

		// Force Leaflet to remeasure the container after mount; the editor
		// iframe occasionally settles layout slightly after the React commit.
		map.invalidateSize( false );

		return () => {
			map.remove();
			mapRef.current = null;
			layerRef.current = null;
		};
		// payload + colours intentionally drive a fresh remount; React's hook
		// lint rule wants every dep listed but trackColor/waypointColor are
		// handled by the dedicated effect below to avoid a costly rebuild on
		// every colour-picker frame. Listing them anyway keeps the lint quiet
		// without changing behaviour because the dedicated effect runs first.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ payload ] );

	// Apply colour changes without rebuilding the map. Leaflet's canvas paths
	// take their colours from JS options, not CSS, so we restyle the layer
	// in place when the user drags a colour picker.
	useEffect( () => {
		const layer = layerRef.current;
		if ( ! layer ) {
			return;
		}
		layer.setStyle( {
			color: trackColor || DEFAULT_TRACK_COLOR,
		} );
	}, [ trackColor ] );

	// Build the wrapper style once per render so attribute changes (aspect
	// ratio, min-height, max-height) take effect immediately.
	const wrapperStyle = buildWrapperStyle( attributes );

	// Loading and error are intentionally rendered inside the same wrapper
	// that hosts the map so the editor sees consistent dimensions throughout
	// the load lifecycle — no layout jump when the map mounts.
	if ( error ) {
		return (
			<div
				className="wp-block-kntnt-gpx-blocks-map kntnt-gpx-blocks-map"
				style={ wrapperStyle }
			>
				<div className="kntnt-gpx-blocks-error">
					<p>
						<strong>
							{ __( 'Kntnt GPX Blocks:', 'kntnt-gpx-blocks' ) }
						</strong>{ ' ' }
						{ error.message }{ ' ' }
						<code>
							({ __( 'code:', 'kntnt-gpx-blocks' ) }{ ' ' }
							{ error.code })
						</code>
					</p>
				</div>
			</div>
		);
	}

	return (
		<div
			ref={ containerRef }
			className="wp-block-kntnt-gpx-blocks-map kntnt-gpx-blocks-map"
			style={ wrapperStyle }
			aria-busy={ loading ? 'true' : 'false' }
		/>
	);
};
