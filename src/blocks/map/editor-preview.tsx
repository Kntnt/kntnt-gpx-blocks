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
import { Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
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
 * Resolved base-tile provider record forwarded from `edit.tsx` to the preview.
 *
 * Same Leaflet-facing fields as `EditorOverlayRecord` minus the `id` —
 * `MapEditorPreview` does not need the id because there is only one base
 * layer at a time and the preview cannot surface "unknown provider"
 * notices anyway (that's #82). `{KEY}` is already substituted by
 * `edit.tsx`'s `resolveProviderForPreview()` before the record reaches
 * this component, mirroring how `Render_Map` substitutes for the
 * frontend.
 *
 * @since 1.0.0
 */
export interface EditorProviderRecord {
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
	/**
	 * Resolved base-tile provider with `{KEY}` already substituted client-side
	 * from `attributes.tileApiKeys[ tileProvider ]`. `null` in two cases: the
	 * editor-data global is unavailable, or the resolved provider requires a
	 * key and the per-provider entry in `tileApiKeys` is empty (the documented
	 * polyline-only state — issue #81). Either way the preview renders polyline + waypoints over
	 * an empty tile background; the editor surface ships polyline-only
	 * rather than issuing failing tile requests.
	 */
	provider: EditorProviderRecord | null;
	overlays: readonly EditorOverlayRecord[];
}

/**
 * Props for the MapEditorPreview component.
 *
 * `unknownProviderFallbackLabel` and `missingKey` are detection flags
 * computed in `edit.tsx` via `detectPreviewNotices()` (see
 * `preview-notices.ts`). They drive editor-only warning Notices rendered
 * above the preview canvas; the canvas itself stays unchanged below the
 * Notices, so the editor sees both the diagnostic and the resulting
 * (fallback / failing) preview at the same time.
 *
 * Both flags are optional — when omitted, neither Notice renders. This
 * keeps the component safe to call from contexts that have not yet wired
 * the detection (none in v1, but it makes the Notice surface a strict
 * superset of the previous API).
 *
 * @since 1.0.0
 */
interface MapEditorPreviewProps {
	attributes: PreviewAttributes;
	/**
	 * Display label of the canonical fallback provider when the saved
	 * `tileProvider` id is no longer in the registry; `null` when the
	 * saved id is recognised. Carries the registry's label rather than a
	 * hardcoded string so a renamed OSM entry surfaces correctly in the
	 * Notice copy.
	 */
	unknownProviderFallbackLabel?: string | null;
	/**
	 * `true` when the resolved provider requires an API key
	 * (`requiresKey === true`) and the per-provider entry in `tileApiKeys`
	 * is empty.
	 */
	missingKey?: boolean;
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
 * Earth radius in metres for Haversine summation along the track polyline.
 *
 * Matches the value used by Render_Elevation::build_distance_elevation_series
 * and Statistics_Calculator so the editor's midpoint computation lines up
 * exactly with the server's distance figures.
 *
 * @since 1.0.0
 */
const EARTH_RADIUS_METERS = 6371000.0;

/**
 * Pixel offset applied between the projected anchor point and the tooltip's
 * top-left corner so the preview sits next to (rather than over) the marker.
 *
 * @since 1.0.0
 */
const ANCHOR_OFFSET_X = 10;

/**
 * Pixel offset applied on the Y axis between the projected anchor point and
 * the tooltip. A small negative value visually centres the tooltip beside
 * the marker for typical line heights.
 *
 * @since 1.0.0
 */
const ANCHOR_OFFSET_Y = -8;

/**
 * Editor preview for the GPX Map block.
 *
 * Fetches the cached GeoJSON for the given attachmentId via the plugin's
 * REST endpoint, then mounts a Leaflet instance into a div ref. Re-fits the
 * map when the GeoJSON changes; tears the map down on unmount.
 *
 * The polyline colour, the base tile provider, and the overlay layers each
 * react to attribute changes without a full remount. The polyline is
 * canvas-rendered and is restyled in place; the base tile layer is removed
 * and re-added (with `bringToBack()` so it sits beneath any overlays);
 * overlays are wiped and re-added in editor-configured order. Each lives
 * in its own dedicated `useEffect` so a colour-picker drag, a provider
 * dropdown change, or an overlay toggle does not rebuild the whole Leaflet
 * map.
 *
 * @since 1.0.0
 *
 * @param props                              Component props.
 * @param props.attributes                   The preview attribute subset.
 * @param props.unknownProviderFallbackLabel Fallback provider's display
 *                                           label when the saved provider
 *                                           id is unknown; `null` when it
 *                                           is recognised. Drives the
 *                                           dismissible "Unknown tile
 *                                           provider" Notice rendered
 *                                           above the canvas.
 * @param props.missingKey                   `true` when the resolved
 *                                           provider requires an API key
 *                                           and the per-block key is
 *                                           empty. Drives the dismissible
 *                                           "missing API key" Notice.
 */
export const MapEditorPreview = ( {
	attributes,
	unknownProviderFallbackLabel = null,
	missingKey = false,
}: MapEditorPreviewProps ): JSX.Element => {
	const containerRef = useRef< HTMLDivElement >( null );
	const mapRef = useRef< L.Map | null >( null );
	const layerRef = useRef< L.GeoJSON | null >( null );
	const baseTileLayerRef = useRef< L.TileLayer | null >( null );
	const overlayLayersRef = useRef< L.TileLayer[] >( [] );
	const [ payload, setPayload ] = useState< PreviewPayload | null >( null );
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ loading, setLoading ] = useState< boolean >( true );

	// Pixel position of the waypoint-info preview within the map host, driven
	// by re-projecting the chosen geographic anchor on every Leaflet move/zoom.
	// `null` while the map is still mounting or no anchor could be resolved;
	// the preview keeps its previous position until the first projection
	// resolves so it does not flash at (0, 0) on first paint.
	const [ tooltipPosition, setTooltipPosition ] = useState< {
		left: number;
		top: number;
	} | null >( null );

	// Per-Notice dismissal state, scoped to this block instance for the
	// remainder of the editor session. Each Notice tracks its own flag so
	// dismissing one does not affect the other. Saving the post with a
	// fresh provider/key choice naturally clears the underlying detection,
	// so no extra logic is needed to reset these on save — the detection
	// flag flips to `false` and the Notice has no reason to render.
	const [ unknownDismissed, setUnknownDismissed ] = useState( false );
	const [ missingKeyDismissed, setMissingKeyDismissed ] = useState( false );

	const {
		attachmentId,
		trackColor,
		waypointColor,
		tooltipShowName,
		tooltipShowDesc,
		provider,
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
			baseTileLayerRef.current = null;
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

		// The base tile layer and overlay tile layers are added by their
		// dedicated sync effects below, which fire after this mount effect on
		// every render and are the single source of truth for which tile
		// layers exist on the map. Keeping the seeding in one place avoids
		// the wipe-and-readd burst that would otherwise happen on first
		// mount, and lets a provider change after mount swap layers in place
		// without rebuilding the whole map. The editor preview ignores the
		// consent contract by design — see docs/consent.md "Editor behaviour".
		baseTileLayerRef.current = null;
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
			baseTileLayerRef.current = null;
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

	// Anchor the floating waypoint-info preview on real track geometry.
	// Resolves the geographic anchor (first waypoint when present, otherwise
	// the polyline midpoint at fraction = 0.5), projects it to container-pixel
	// coordinates via Leaflet's `latLngToContainerPoint`, and registers
	// `move` / `zoom` listeners so the preview re-projects whenever the map
	// pans or zooms. Without those listeners the tooltip would "swim" off the
	// anchor on any viewport change; Leaflet's fit-to-bounds on first mount is
	// itself a zoom + move and is captured by the same handlers.
	useEffect( () => {
		const map = mapRef.current;
		if ( ! map || ! payload ) {
			setTooltipPosition( null );
			return;
		}

		const anchor = pickPreviewAnchor( payload.geojson );
		if ( ! anchor ) {
			setTooltipPosition( null );
			return;
		}

		const project = (): void => {
			const point = map.latLngToContainerPoint( [
				anchor.lat,
				anchor.lon,
			] );
			setTooltipPosition( {
				left: point.x + ANCHOR_OFFSET_X,
				top: point.y + ANCHOR_OFFSET_Y,
			} );
		};

		project();
		map.on( 'move', project );
		map.on( 'zoom', project );

		return () => {
			map.off( 'move', project );
			map.off( 'zoom', project );
		};
	}, [ payload ] );

	// Synchronise the base tile layer with the current `provider` prop without
	// rebuilding the whole map. Leaflet does not expose a "swap base layer"
	// primitive, so we remove the previous base layer (if any) and add the
	// new one in its place. `bringToBack()` keeps the freshly added base
	// beneath the overlays already on the map, so a provider change does not
	// flicker overlays above the new base. When the provider is null
	// (registry global stripped, or the documented polyline-only state for
	// paid providers without a key — issue #81), the previous base layer is
	// removed and no replacement is added; the polyline renders over an
	// empty tile background.
	useEffect( () => {
		const map = mapRef.current;
		if ( ! map ) {
			return;
		}

		if ( baseTileLayerRef.current ) {
			map.removeLayer( baseTileLayerRef.current );
			baseTileLayerRef.current = null;
		}

		if ( ! provider ) {
			return;
		}

		const options: L.TileLayerOptions = {
			maxZoom: provider.maxZoom,
			attribution: provider.attribution,
		};
		if ( provider.subdomains && provider.subdomains.length > 0 ) {
			options.subdomains = [ ...provider.subdomains ];
		}
		const layer = L.tileLayer( provider.url, options ).addTo( map );
		layer.bringToBack();
		baseTileLayerRef.current = layer;
		// `provider` is memoized by the caller (`edit.tsx`'s `useMemo` keyed
		// on tileProvider / tileStyle / tileApiKeys), so referential equality
		// here is the same as field equality and the effect stays quiet on
		// unrelated parent re-renders. `payload` is listed too because the
		// mount effect creates the `L.Map` only after the REST fetch resolves;
		// without re-firing on first payload arrival, the base tile layer is
		// never attached when `provider` is unchanged between the initial
		// render and the post-fetch render (issue #100).
	}, [ provider, payload ] );

	// Synchronise the overlay tile layers with the current `overlays` prop
	// without rebuilding the whole map. The mount effect above seeds
	// `overlayLayersRef` with whatever was selected at mount time; this
	// effect handles every subsequent toggle change. The simplest correct
	// strategy is to wipe and re-add — Leaflet adds tile layers cheaply, the
	// overlay count is small, and rebuilding preserves the desired stacking
	// order without per-id reconciliation. The `overlays` array is memoized
	// upstream in `edit.tsx` (keyed on the saved overlay pairs and the per-
	// provider key map), so unrelated parent re-renders keep the same array
	// reference and this effect stays quiet; a fresh remount triggered by the
	// payload effect above still re-fires it, and the wipe-and-readd is
	// idempotent against the just-seeded state.
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
	// Leaflet host inside it.
	//
	// The inner host is pinned to all four sides of the wrapper instead of
	// relying on `width: 100%; height: 100%`. With `aspect-ratio` from the
	// project class plus an inline `min-height` from core's `dimensions`
	// block supports, `height: 100%` resolved to zero in Chrome and Safari
	// — Leaflet then mounted into a 0 × 0 container and the editor preview
	// vanished the moment the editor entered any value in the Mått panel
	// (issue #86). The wrapper is `position: relative` (style.scss) so the
	// host's `inset: 0` anchors to the wrapper's padding-box and the box
	// stays definite regardless of how the wrapper's height is computed.
	// The host also remains the positioned ancestor that the absolutely
	// positioned waypoint-info preview anchors to.
	const hostStyle: React.CSSProperties = {
		position: 'absolute',
		inset: 0,
	};

	const fillParentStyle: React.CSSProperties = {
		width: '100%',
		height: '100%',
	};

	// Compose the warning Notices that sit above the preview canvas. Each
	// Notice is rendered conditionally on its detection flag AND its local
	// dismissal flag, so dismissing one within the editor session keeps it
	// hidden until the underlying condition clears (e.g. the editor picks a
	// different provider in the Inspector). The unknown-id template
	// interpolates the registry's fallback label via `sprintf` so a renamed
	// OSM entry surfaces with its renamed name — the literal "OpenStreetMap"
	// is not hardcoded here.
	const showUnknownNotice =
		unknownProviderFallbackLabel !== null && ! unknownDismissed;
	const showMissingKeyNotice = missingKey && ! missingKeyDismissed;
	const notices =
		showUnknownNotice || showMissingKeyNotice ? (
			<div className="kntnt-gpx-blocks-editor-notices">
				{ showUnknownNotice && (
					<Notice
						status="warning"
						isDismissible
						onRemove={ () => setUnknownDismissed( true ) }
					>
						{ sprintf(
							/* translators: %s: display label of the fallback tile provider, sourced from the registry. */
							__(
								'Unknown tile provider. Falling back to %s.',
								'kntnt-gpx-blocks'
							),
							unknownProviderFallbackLabel
						) }
					</Notice>
				) }
				{ showMissingKeyNotice && (
					<Notice
						status="warning"
						isDismissible
						onRemove={ () => setMissingKeyDismissed( true ) }
					>
						{ __(
							'This provider requires an API key. Set it in the Inspector.',
							'kntnt-gpx-blocks'
						) }
					</Notice>
				) }
			</div>
		) : null;

	// Loading and error are intentionally rendered inside the same fill-parent
	// host that holds the map so the editor sees consistent dimensions
	// throughout the load lifecycle — no layout jump when the map mounts.
	// The error branch reuses `hostStyle` rather than the fill-parent
	// helper so an error notice is anchored against the wrapper the same
	// way the map host is — staying visible inside the wrapper's
	// `overflow: hidden` clip even when the wrapper's height is computed
	// via `aspect-ratio` and clamped by an inline `min-height` from core's
	// `dimensions` block supports (see the `hostStyle` comment above).
	// Notices render above the error host as well so a stale `tileProvider`
	// surfaces even when the GPX payload itself fails to load.
	if ( error ) {
		return (
			<>
				{ notices }
				<div style={ hostStyle }>
					<div className="kntnt-gpx-blocks-error">
						<p>
							<strong>
								{ __(
									'Kntnt GPX Blocks:',
									'kntnt-gpx-blocks'
								) }
							</strong>{ ' ' }
							{ error.message }{ ' ' }
							<code>
								({ __( 'code:', 'kntnt-gpx-blocks' ) }{ ' ' }
								{ error.code })
							</code>
						</p>
					</div>
				</div>
			</>
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

	// Build the inline style for the tooltip-preview wrapper. Once the
	// projection effect resolves a pixel position, the tooltip is anchored
	// at `left` / `top` driven by JS; before that, `visibility: hidden` keeps
	// the element out of view so it does not flash at the wrapper's
	// top-left corner on first paint.
	const tooltipPreviewStyle: React.CSSProperties = tooltipPosition
		? {
				left: `${ tooltipPosition.left }px`,
				top: `${ tooltipPosition.top }px`,
		  }
		: { visibility: 'hidden' };

	return (
		<>
			{ notices }
			<div style={ hostStyle }>
				<div
					ref={ containerRef }
					style={ fillParentStyle }
					aria-busy={ loading ? 'true' : 'false' }
				/>
				<div
					className="kntnt-gpx-blocks-tooltip-preview"
					aria-hidden="true"
					style={ tooltipPreviewStyle }
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
		</>
	);
};

/**
 * Result of the first-waypoint lookup over a cached GeoJSON payload.
 *
 * `lat` / `lon` are the projected coordinates of the waypoint so the editor
 * preview can anchor the floating tooltip on the same geographic point the
 * runtime tooltip would attach to. `name` / `desc` are the corresponding
 * label strings, with empty strings substituted when the property is
 * missing — callers fall back to translatable placeholders.
 *
 * @since 1.0.0
 */
interface FirstWaypoint {
	readonly lat: number;
	readonly lon: number;
	readonly name: string;
	readonly desc: string;
}

/**
 * Geographic anchor for the editor's waypoint-info preview.
 *
 * Either the first waypoint's projected position (when at least one `Point`
 * feature exists in the cached GeoJSON) or the LineString midpoint at
 * fraction = 0.5 (when no waypoints exist). Returning `null` means neither
 * could be resolved — the preview is hidden in that case.
 *
 * @since 1.0.0
 */
interface PreviewAnchor {
	readonly lat: number;
	readonly lon: number;
}

/**
 * Extracts the first waypoint's `name` / `desc` / coordinates from a
 * GeoJSON payload.
 *
 * Waypoints are encoded as `Point` features in the same FeatureCollection
 * that holds the track `LineString`. The function returns `null` when the
 * payload is not yet available, has no `Point` features, or the first
 * `Point` has malformed coordinates.
 *
 * @since 1.0.0
 *
 * @param geojson Cached GeoJSON payload, or `undefined` when not yet loaded.
 * @return First waypoint's `{ lat, lon, name, desc }`, or `null` when absent.
 */
export function pickFirstWaypoint(
	geojson: GeoJSON.GeoJsonObject | undefined
): FirstWaypoint | null {
	if ( ! geojson || geojson.type !== 'FeatureCollection' ) {
		return null;
	}
	const fc = geojson as GeoJSON.FeatureCollection;
	for ( const feature of fc.features ) {
		if ( feature.geometry?.type !== 'Point' ) {
			continue;
		}
		const point = feature.geometry as GeoJSON.Point;
		const coords = point.coordinates;
		if (
			! Array.isArray( coords ) ||
			coords.length < 2 ||
			typeof coords[ 0 ] !== 'number' ||
			typeof coords[ 1 ] !== 'number'
		) {
			continue;
		}
		const props = feature.properties ?? {};
		const name = typeof props.name === 'string' ? props.name : '';
		const desc = typeof props.desc === 'string' ? props.desc : '';
		return { lat: coords[ 1 ], lon: coords[ 0 ], name, desc };
	}
	return null;
}

/**
 * Computes the projected position halfway along the track's LineString.
 *
 * Walks the first LineString found in the FeatureCollection, summing
 * Haversine distances between consecutive points to obtain the total
 * length, then steps along the chain again to locate the segment that
 * contains 50 % of that length and linearly interpolates the latitude /
 * longitude within it. Mirrors the math in
 * `Render_Elevation::build_distance_elevation_series` but stays
 * client-side — distances are computed in JS so the editor never crosses
 * the PHP boundary at preview time.
 *
 * Edge cases handled:
 * - Empty / non-FeatureCollection payload → returns `null`.
 * - LineString missing or with zero coordinates → returns `null`.
 * - Single-point LineString → returns that point (no segment to bisect).
 * - Two-point LineString → returns the segment's true midpoint.
 * - Total distance of zero (all points colocated) → returns the first point.
 *
 * @since 1.0.0
 *
 * @param geojson Cached GeoJSON payload, or `undefined` when not yet loaded.
 * @return Midpoint `{ lat, lon }`, or `null` when the polyline is absent.
 */
export function computeLineMidpoint(
	geojson: GeoJSON.GeoJsonObject | undefined
): PreviewAnchor | null {
	if ( ! geojson || geojson.type !== 'FeatureCollection' ) {
		return null;
	}
	const fc = geojson as GeoJSON.FeatureCollection;

	// Locate the first LineString feature; tracks always live in a single
	// LineString in this plugin's GeoJSON output.
	let coords: GeoJSON.Position[] | null = null;
	for ( const feature of fc.features ) {
		if ( feature.geometry?.type === 'LineString' ) {
			const ls = feature.geometry as GeoJSON.LineString;
			if ( Array.isArray( ls.coordinates ) ) {
				coords = ls.coordinates;
				break;
			}
		}
	}
	if ( ! coords || coords.length === 0 ) {
		return null;
	}

	// Normalise each coordinate to a `[lon, lat]` numeric pair, dropping
	// anything malformed so the walker does not need to revalidate.
	const valid: Array< { lat: number; lon: number } > = [];
	for ( const entry of coords ) {
		if (
			Array.isArray( entry ) &&
			entry.length >= 2 &&
			typeof entry[ 0 ] === 'number' &&
			typeof entry[ 1 ] === 'number'
		) {
			valid.push( { lon: entry[ 0 ], lat: entry[ 1 ] } );
		}
	}
	if ( valid.length === 0 ) {
		return null;
	}
	if ( valid.length === 1 ) {
		return { lat: valid[ 0 ].lat, lon: valid[ 0 ].lon };
	}

	// First pass: sum the cumulative distance along the chain.
	let total = 0;
	for ( let i = 1; i < valid.length; i++ ) {
		total += haversineMeters(
			valid[ i - 1 ].lat,
			valid[ i - 1 ].lon,
			valid[ i ].lat,
			valid[ i ].lon
		);
	}
	if ( total <= 0 ) {
		return { lat: valid[ 0 ].lat, lon: valid[ 0 ].lon };
	}

	// Second pass: walk until the cumulative distance crosses the halfway
	// mark, then linearly interpolate within the bracketing segment.
	const target = total * 0.5;
	let accumulated = 0;
	for ( let i = 1; i < valid.length; i++ ) {
		const segment = haversineMeters(
			valid[ i - 1 ].lat,
			valid[ i - 1 ].lon,
			valid[ i ].lat,
			valid[ i ].lon
		);
		if ( accumulated + segment >= target ) {
			const t = segment > 0 ? ( target - accumulated ) / segment : 0;
			const lat =
				valid[ i - 1 ].lat +
				( valid[ i ].lat - valid[ i - 1 ].lat ) * t;
			const lon =
				valid[ i - 1 ].lon +
				( valid[ i ].lon - valid[ i - 1 ].lon ) * t;
			return { lat, lon };
		}
		accumulated += segment;
	}

	// Fallback: floating-point drift left the loop without picking a segment.
	// Pin to the last point so the preview still resolves to a real geometry.
	const last = valid[ valid.length - 1 ];
	return { lat: last.lat, lon: last.lon };
}

/**
 * Picks the geographic anchor for the editor's waypoint-info preview.
 *
 * Returns the first waypoint's position when at least one `Point` feature
 * exists in the cached GeoJSON; otherwise falls back to the polyline's
 * midpoint at fraction = 0.5. Returns `null` only when neither is
 * resolvable (no Point features, no LineString, or both are malformed) —
 * the caller then hides the preview.
 *
 * @since 1.0.0
 *
 * @param geojson Cached GeoJSON payload, or `undefined` when not yet loaded.
 * @return Anchor `{ lat, lon }`, or `null` when nothing usable was found.
 */
export function pickPreviewAnchor(
	geojson: GeoJSON.GeoJsonObject | undefined
): PreviewAnchor | null {
	const waypoint = pickFirstWaypoint( geojson );
	if ( waypoint ) {
		return { lat: waypoint.lat, lon: waypoint.lon };
	}
	return computeLineMidpoint( geojson );
}

/**
 * Great-circle distance between two lat/lon pairs in metres.
 *
 * Mirrors `Render_Elevation::haversine_meters` so the editor's midpoint
 * computation lines up exactly with the server's distance figures.
 *
 * @since 1.0.0
 *
 * @param lat1 Latitude of point 1, decimal degrees.
 * @param lon1 Longitude of point 1, decimal degrees.
 * @param lat2 Latitude of point 2, decimal degrees.
 * @param lon2 Longitude of point 2, decimal degrees.
 * @return Great-circle distance in metres.
 */
function haversineMeters(
	lat1: number,
	lon1: number,
	lat2: number,
	lon2: number
): number {
	const toRad = ( deg: number ): number => ( deg * Math.PI ) / 180;
	const phi1 = toRad( lat1 );
	const phi2 = toRad( lat2 );
	const dPhi = toRad( lat2 - lat1 );
	const dLambda = toRad( lon2 - lon1 );

	const a =
		Math.sin( dPhi / 2 ) ** 2 +
		Math.cos( phi1 ) * Math.cos( phi2 ) * Math.sin( dLambda / 2 ) ** 2;

	return (
		EARTH_RADIUS_METERS *
		2 *
		Math.atan2( Math.sqrt( a ), Math.sqrt( 1 - a ) )
	);
}
