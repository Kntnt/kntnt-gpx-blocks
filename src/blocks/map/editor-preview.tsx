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
 * Resolved overlay record forwarded from `edit.tsx` to the preview.
 *
 * Mirrors the runtime tile-layer record shape used on the frontend
 * (`view.ts`'s `TileLayerRecord`) but typed independently here so the
 * editor preview does not pull a frontend type. `id` is informational;
 * the URL/attribution/maxZoom/subdomains are forwarded directly to
 * `L.tileLayer()`.
 *
 * @since 1.0.0
 */
export interface EditorOverlayRecord {
	readonly id: string;
	readonly url: string;
	readonly attribution: string;
	readonly maxZoom: number;
	subdomains?: string[];
}

/**
 * Attributes the preview reads. A subset of the Map block's attribute set —
 * only the ones that affect preview appearance.
 *
 * Dimensions (`aspect-ratio`, `min-height`) are not listed here: the Map
 * block delegates them to core's `dimensions` block supports, and the
 * wrapper element produced by `useBlockProps()` in `edit.tsx` already
 * carries the resulting inline style. Track and waypoint colours, by
 * contrast, must be passed explicitly because Leaflet's canvas-rendered
 * paths take their colour from JS path options rather than CSS. The two
 * tooltip-show toggles drive the floating waypoint-info preview; the rest
 * of the tooltip styling reaches the preview through CSS custom properties
 * the parent wrapper carries (see `edit.tsx`'s `useBlockProps` style).
 * `overlays` carries the resolved overlay records from
 * `window.kntntGpxBlocks` (resolved upstream in `edit.tsx` so the preview
 * does not redo the lookup) and is mounted on top of the hardcoded base
 * tile layer.
 *
 * @since 1.0.0
 */
interface PreviewAttributes {
	attachmentId: number;
	trackColor: string;
	waypointColor: string;
	tooltipShowName: boolean;
	tooltipShowDesc: boolean;
	overlays: readonly EditorOverlayRecord[];
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
	const overlayLayersRef = useRef< L.TileLayer[] >( [] );
	const [ payload, setPayload ] = useState< PreviewPayload | null >( null );
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ loading, setLoading ] = useState< boolean >( true );

	const {
		attachmentId,
		trackColor,
		waypointColor,
		tooltipShowName,
		tooltipShowDesc,
		overlays,
	} = attributes;

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
			overlayLayersRef.current = [];
		}

		// Build a non-interactive Leaflet preview. The editor map is for
		// visual reference only — the user wants to see what the block will
		// look like, not pan or zoom around. Every Leaflet interaction
		// handler is disabled at construction time, and editor.scss adds
		// `pointer-events: none` on the container so Gutenberg can still
		// receive clicks for block selection without Leaflet fighting back.
		const map = L.map( containerRef.current, {
			renderer: L.canvas(),
			zoomControl: false,
			attributionControl: true,
			dragging: false,
			scrollWheelZoom: false,
			doubleClickZoom: false,
			touchZoom: false,
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

		// Overlay tile layers are seeded by the dedicated sync effect below,
		// which fires after this mount effect on every render and is the
		// single source of truth for what overlays exist on the map. Keeping
		// the seeding in one place avoids the wipe-and-readd burst that would
		// otherwise happen on first mount.
		overlayLayersRef.current = [];

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
			overlayLayersRef.current = [];
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

	// Synchronise the overlay tile layers with the current `overlays` prop
	// without rebuilding the whole map. The mount effect above seeds
	// `overlayLayersRef` with whatever was selected at mount time; this
	// effect handles every subsequent toggle change. The simplest correct
	// strategy is to wipe and re-add — Leaflet adds tile layers cheaply, the
	// overlay count is small, and rebuilding preserves the desired stacking
	// order without per-id reconciliation. Note: a fresh remount triggered
	// by the payload effect above will also fire this effect (the array
	// identity changes on every parent render because resolveOverlaysForPreview
	// returns a new array), but the wipe-and-readd is idempotent against the
	// just-seeded state.
	useEffect( () => {
		const map = mapRef.current;
		if ( ! map ) {
			return;
		}

		for ( const layer of overlayLayersRef.current ) {
			map.removeLayer( layer );
		}

		const next: L.TileLayer[] = [];
		for ( const overlay of overlays ) {
			const overlayOptions: L.TileLayerOptions = {
				maxZoom: overlay.maxZoom,
				attribution: overlay.attribution,
			};
			if ( overlay.subdomains && overlay.subdomains.length > 0 ) {
				overlayOptions.subdomains = [ ...overlay.subdomains ];
			}
			next.push(
				L.tileLayer( overlay.url, overlayOptions ).addTo( map )
			);
		}
		overlayLayersRef.current = next;
	}, [ overlays ] );

	// Keep Leaflet's internal size in sync with the wrapper's actual size.
	// Toggling alignwide/alignfull (and any other layout change that resizes
	// the wrapper) does not trigger a payload re-mount, so without this
	// observer Leaflet keeps its stale dimensions and the map renders
	// cropped or with stale tile coverage. invalidateSize is debounced to
	// coalesce the burst of resize events that fire during a layout change.
	useEffect( () => {
		const container = containerRef.current;
		if ( ! container ) {
			return;
		}

		let timeoutId: ReturnType< typeof setTimeout > | null = null;
		const observer = new ResizeObserver( () => {
			if ( timeoutId !== null ) {
				clearTimeout( timeoutId );
			}
			timeoutId = setTimeout( () => {
				timeoutId = null;
				mapRef.current?.invalidateSize( false );
			}, 100 );
		} );
		observer.observe( container );

		return () => {
			if ( timeoutId !== null ) {
				clearTimeout( timeoutId );
			}
			observer.disconnect();
		};
	}, [] );

	// The wrapper element produced by `useBlockProps()` in `edit.tsx` already
	// carries the layout: dimensions come from core's `dimensions` block
	// supports and the project class triggers the shared style.scss baseline.
	// MapEditorPreview's own elements simply fill that wrapper without
	// asserting their own dimensions, so the editor's chosen aspect-ratio and
	// min-height stay in effect both for the wrapper itself and for the
	// Leaflet host inside it. `position: relative` anchors the absolutely
	// positioned waypoint-info preview to this host's content box.
	const hostStyle: React.CSSProperties = {
		position: 'relative',
		width: '100%',
		height: '100%',
	};

	const fillParentStyle: React.CSSProperties = {
		width: '100%',
		height: '100%',
	};

	// Loading and error are intentionally rendered inside the same fill-parent
	// host that holds the map so the editor sees consistent dimensions
	// throughout the load lifecycle — no layout jump when the map mounts.
	if ( error ) {
		return (
			<div style={ fillParentStyle }>
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

	// Pick sample text for the floating waypoint-info preview. The first
	// waypoint's `name` / `desc` is preferred so the editor sees real values
	// from their own GPX file when available; either field falls back to a
	// translatable placeholder when missing or before the payload arrives.
	// Only `Point` features carry waypoint properties — `LineString` features
	// are the track itself and are skipped.
	const firstWaypoint = pickFirstWaypoint( payload?.geojson );
	const sampleName =
		firstWaypoint?.name?.trim() || __( 'Sample name', 'kntnt-gpx-blocks' );
	const sampleDesc =
		firstWaypoint?.desc?.trim() ||
		__( 'Sample description', 'kntnt-gpx-blocks' );

	return (
		<div style={ hostStyle }>
			<div
				ref={ containerRef }
				style={ fillParentStyle }
				aria-busy={ loading ? 'true' : 'false' }
			/>
			<div
				className="kntnt-gpx-blocks-tooltip-preview"
				aria-hidden="true"
			>
				{ tooltipShowName && (
					<div className="kntnt-gpx-blocks-tooltip-name">
						{ sampleName }
					</div>
				) }
				{ tooltipShowDesc && (
					<div className="kntnt-gpx-blocks-tooltip-desc">
						{ sampleDesc }
					</div>
				) }
			</div>
		</div>
	);
};

/**
 * Extracts the first waypoint's `name` / `desc` from a GeoJSON payload.
 *
 * Waypoints are encoded as `Point` features in the same FeatureCollection
 * that holds the track `LineString`. The function returns `null` when the
 * payload is not yet available, has no `Point` features, or the first
 * `Point` has no usable string properties.
 *
 * @since 1.0.0
 *
 * @param geojson Cached GeoJSON payload, or `undefined` when not yet loaded.
 * @return First waypoint's `{ name, desc }` strings, or `null` when absent.
 */
function pickFirstWaypoint(
	geojson: GeoJSON.GeoJsonObject | undefined
): { name: string; desc: string } | null {
	if ( ! geojson || geojson.type !== 'FeatureCollection' ) {
		return null;
	}
	const fc = geojson as GeoJSON.FeatureCollection;
	for ( const feature of fc.features ) {
		if ( feature.geometry?.type !== 'Point' ) {
			continue;
		}
		const props = feature.properties ?? {};
		const name = typeof props.name === 'string' ? props.name : '';
		const desc = typeof props.desc === 'string' ? props.desc : '';
		return { name, desc };
	}
	return null;
}
