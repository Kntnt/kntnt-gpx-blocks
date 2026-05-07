/**
 * GPX Map frontend Interactivity API module.
 *
 * Registers the `kntnt-gpx-blocks` store and implements:
 *
 * - `callbacks.initMap` — mounts a Leaflet map with a canvas-rendered track
 *   polyline, pre-computes the flat coordinate array for fraction→position
 *   resolution, creates an invisible cursor marker, and attaches the
 *   pointermove/pointerleave handlers that write to the shared
 *   `state[mapId].fraction`.
 * - `callbacks.onCursorChange` — reacts to `state[mapId].fraction` changes
 *   (from Map itself or from GPX Elevation) by moving the cursor marker along
 *   the polyline without re-emitting the fraction (no feedback loop).
 *
 * The map is deferred until the container enters the viewport via
 * IntersectionObserver. A WeakMap guards against double-mounting on
 * ServerSideRender re-renders in the editor.
 *
 * Leaflet's CSS is imported here so `@wordpress/scripts` bundles it into the
 * view-side CSS asset. The block's own styles live in style.scss.
 *
 * @since 1.0.0
 */

import { getContext, getElement, store } from '@wordpress/interactivity';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet.fullscreen';
import 'leaflet.fullscreen/Control.FullScreen.css';

// ─── Types ───────────────────────────────────────────────────────────────────

/**
 * The map control settings hydrated from PHP block attributes.
 *
 * Each flag corresponds to an `InspectorControls` toggle in `edit.tsx` and a
 * Leaflet control added (or omitted) during `initMap`.
 *
 * @since 1.0.0
 */
interface MapSettings {
	/** Show Leaflet's built-in zoom-in/zoom-out buttons. */
	readonly showZoomButtons: boolean;
	/** Show the Leaflet scale bar. */
	readonly showScale: boolean;
	/** Show the leaflet.fullscreen button. */
	readonly showFullscreen: boolean;
	/** Show the custom download-GPX control button. */
	readonly showDownload: boolean;
}

/**
 * Shape of the per-map state slice hydrated from PHP via wp_interactivity_state.
 *
 * @since 1.0.0
 */
interface MapState {
	attachmentId: number;
	geojson: GeoJSON.GeoJsonObject;
	waypoints: GeoJSON.GeoJsonObject;
	/** URL to the original .gpx attachment; null when unavailable. */
	gpxFileUrl: string | null;
	settings: MapSettings;
	fraction: number | null;
	consent: 'unknown' | 'granted' | 'denied';
}

/**
 * The namespaced state keyed by mapId.
 *
 * @since 1.0.0
 */
interface PluginState {
	[ mapId: string ]: MapState;
}

/**
 * Context shape set via data-wp-context on the block element.
 *
 * @since 1.0.0
 */
interface MapContext {
	mapId: string;
}

/**
 * Per-element runtime data kept alongside the Leaflet map instance.
 *
 * @since 1.0.0
 */
interface MapEntry {
	/** Leaflet map instance. */
	map: L.Map;
	/** Cursor marker drawn on the polyline. Opacity 0 until first fraction. */
	cursor: L.CircleMarker;
	/** Flat [lat, lng] array extracted from the simplified LineString. */
	coords: Array< [ number, number ] >;
}

// ─── Module state ─────────────────────────────────────────────────────────────

/**
 * Tracks which container elements already have a Leaflet instance mounted so
 * that re-hydration (e.g. in the editor's ServerSideRender) never double-mounts.
 *
 * Keyed by the block's root element; the value carries the map, cursor marker,
 * and pre-computed coordinate array needed by `onCursorChange`.
 *
 * @since 1.0.0
 */
const mountedMaps = new WeakMap< Element, MapEntry >();

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Build and add the OSM tile layer to the given map.
 *
 * Extracted to keep initMap readable; the tile URL and attribution are the same
 * for every instance.
 *
 * @since 1.0.0
 *
 * @param map - Leaflet map instance to add the layer to.
 * @return The added tile layer.
 */
function addTileLayer( map: L.Map ): L.TileLayer {
	return L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
		maxZoom: 19,
		attribution:
			'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
	} ).addTo( map );
}

/**
 * Extract a flat [[lat, lng], ...] array from a GeoJSON FeatureCollection's
 * first LineString feature. GeoJSON stores coordinates as [lon, lat]; this
 * helper swaps them for Leaflet.
 *
 * @since 1.0.0
 *
 * @param geojson - The GeoJSON object from state[mapId].geojson.
 * @return Flat coordinate array in Leaflet [lat, lng] order.
 */
function extractCoords(
	geojson: GeoJSON.GeoJsonObject
): Array< [ number, number ] > {
	if ( geojson.type !== 'FeatureCollection' ) {
		return [];
	}
	const fc = geojson as GeoJSON.FeatureCollection;
	for ( const feature of fc.features ) {
		if ( feature.geometry?.type === 'LineString' ) {
			const ls = feature.geometry as GeoJSON.LineString;
			// GeoJSON is [lon, lat, ele?] → Leaflet wants [lat, lng].
			return ls.coordinates.map(
				( c ) => [ c[ 1 ], c[ 0 ] ] as [ number, number ]
			);
		}
	}
	return [];
}

/**
 * Return the [lat, lng] position corresponding to a given fraction along a
 * coordinate array using the nearest-vertex approach.
 *
 * Returns null when coords is empty or fraction is null.
 *
 * @since 1.0.0
 *
 * @param coords   - Flat [lat, lng] array.
 * @param fraction - Position in [0, 1].
 * @return Leaflet LatLng or null.
 */
function fractionToLatLng(
	coords: Array< [ number, number ] >,
	fraction: number
): L.LatLngExpression | null {
	if ( coords.length === 0 ) {
		return null;
	}
	const index = Math.round( fraction * ( coords.length - 1 ) );
	const clamped = Math.max( 0, Math.min( coords.length - 1, index ) );
	return coords[ clamped ] as [ number, number ];
}

// ─── Store ────────────────────────────────────────────────────────────────────

const { state } = store< { state: PluginState } >( 'kntnt-gpx-blocks', {
	state: {} as PluginState,
	callbacks: {
		/**
		 * Mount the Leaflet map into the block container element.
		 *
		 * Called by the data-wp-init directive on the block's root element. Reads
		 * the mapId from context, locates the container via getElement, and lazily
		 * defers actual mount until the element enters the viewport. A WeakMap
		 * guard prevents double-mounting on repeated init calls.
		 *
		 * After mounting:
		 * - Extracts a flat coords array from the simplified LineString.
		 * - Creates a cursor CircleMarker (initially hidden).
		 * - Attaches pointermove on the GeoJSON layer to write fraction.
		 * - Attaches pointerleave on the map container to null the fraction.
		 *
		 * @since 1.0.0
		 */
		initMap() {
			const { ref } = getElement();

			// No element reference means we're outside a valid directive scope;
			// bail silently so the store doesn't crash.
			if ( ! ref ) {
				return;
			}

			// Guard against re-entry — ServerSideRender in the editor may call
			// data-wp-init more than once for the same element.
			if ( mountedMaps.has( ref ) ) {
				return;
			}

			const { mapId } = getContext< MapContext >();
			const mapState = state[ mapId ];

			// State slice not yet populated — defensive bail; wp_interactivity_state
			// should always populate it before the module runs.
			if ( ! mapState ) {
				return;
			}

			// Defer Leaflet initialisation until the container is visible so the
			// map has real layout dimensions when it mounts.
			const observer = new IntersectionObserver(
				( entries, obs ) => {
					const entry = entries[ 0 ];
					if ( ! entry?.isIntersecting ) {
						return;
					}

					// Stop observing now that we're about to mount.
					obs.disconnect();

					// Build the Leaflet map with canvas renderer for performance.
					const map = L.map( ref as HTMLElement, {
						renderer: L.canvas(),
						zoomControl: false,
						dragging: false,
						scrollWheelZoom: false,
						touchZoom: false,
						doubleClickZoom: false,
						boxZoom: false,
						keyboard: false,
						attributionControl: true,
					} );

					// Add the OSM tile layer.
					addTileLayer( map );

					// Render the simplified track from the hydrated GeoJSON.
					const layer = L.geoJSON( mapState.geojson );
					layer.addTo( map );

					// Fit the viewport to the track bounds with small padding so
					// the polyline never touches the container edge.
					const bounds = layer.getBounds();
					if ( bounds.isValid() ) {
						map.fitBounds( bounds, { padding: [ 16, 16 ] } );
					}

					// Add the configured control overlays based on the hydrated settings.
					const settings = mapState.settings;
					if ( settings.showZoomButtons ) {
						L.control.zoom( { position: 'topleft' } ).addTo( map );
					}
					if ( settings.showScale ) {
						L.control
							.scale( {
								position: 'bottomleft',
								metric: true,
								imperial: false,
							} )
							.addTo( map );
					}
					if ( settings.showFullscreen ) {
						// leaflet.fullscreen registers L.control.fullscreen as a
						// side effect of the import at the top of this module.
						(
							L.control as Record<
								string,
								( opts: Record< string, unknown > ) => L.Control
							>
						 )
							.fullscreen( { position: 'topleft' } )
							.addTo( map );
					}
					if ( settings.showDownload && mapState.gpxFileUrl ) {
						const gpxUrl = mapState.gpxFileUrl;

						// Derive a download filename from the URL's last path segment.
						const filename =
							gpxUrl.split( '/' ).pop() ?? 'track.gpx';

						// Build a custom Leaflet control with a download anchor.
						const DownloadControl = L.Control.extend( {
							onAdd() {
								const container = L.DomUtil.create(
									'div',
									'leaflet-bar leaflet-control kntnt-gpx-blocks-map-download-control'
								);
								const anchor = L.DomUtil.create(
									'a',
									'',
									container
								) as HTMLAnchorElement;
								anchor.href = gpxUrl;
								anchor.download = filename;
								anchor.title = 'Download GPX';
								anchor.setAttribute(
									'aria-label',
									'Download GPX'
								);
								anchor.innerHTML =
									'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M12 16l-5-5 1.4-1.4 2.6 2.6V4h2v8.2l2.6-2.6L17 11l-5 5zm-7 2h14v2H5v-2z"/></svg>';
								L.DomEvent.disableClickPropagation( container );
								return container;
							},
						} );
						new DownloadControl( { position: 'topleft' } ).addTo(
							map
						);
					}

					// Pre-compute the flat [lat, lng] coordinate array for
					// fraction→position resolution in onCursorChange.
					const coords = extractCoords( mapState.geojson );

					// Create the cursor marker at the track midpoint, initially
					// invisible. Opacity is set to 1 on the first non-null fraction.
					const midLatLng = fractionToLatLng( coords, 0.5 ) ?? [
						0, 0,
					];
					const cursor = L.circleMarker( midLatLng, {
						radius: 6,
						color: '#000',
						weight: 2,
						fillColor: '#fff',
						fillOpacity: 1,
						interactive: false,
						opacity: 0,
					} );
					cursor.addTo( map );

					// Record the instance so subsequent init calls are no-ops
					// and onCursorChange can access the cursor and coords.
					mountedMaps.set( ref, { map, cursor, coords } );

					// Attach pointer tracking to the GeoJSON polyline layer:
					// write fraction when moving over the track, null on leave.
					layer.on( 'mousemove', ( e: L.LeafletMouseEvent ) => {
						if ( coords.length < 2 ) {
							return;
						}

						// Find the nearest vertex by linear scan. For typical
						// simplified tracks (~300 pts) this is fast enough.
						const latlng = e.latlng;
						let nearest = 0;
						let best = Infinity;
						for ( let i = 0; i < coords.length; i++ ) {
							const d = map.distance(
								latlng,
								coords[ i ] as [ number, number ]
							);
							if ( d < best ) {
								best = d;
								nearest = i;
							}
						}

						// Fraction is index / (total - 1); write to shared state.
						state[ mapId ].fraction =
							nearest / ( coords.length - 1 );
					} );

					// Null the fraction when the pointer leaves the map container
					// so both cursors hide.
					ref.addEventListener( 'pointerleave', () => {
						state[ mapId ].fraction = null;
					} );
				},
				{ threshold: 0 }
			);

			observer.observe( ref );
		},

		/**
		 * React to changes in state[mapId].fraction and move the cursor marker.
		 *
		 * Called by data-wp-watch whenever the fraction value changes (written
		 * by either the Map's own pointermove handler or the Elevation block).
		 * Does NOT write back to state — that would create a feedback loop.
		 *
		 * @since 1.0.0
		 */
		onCursorChange() {
			const { ref } = getElement();
			if ( ! ref ) {
				return;
			}

			const entry = mountedMaps.get( ref );
			if ( ! entry ) {
				return;
			}

			const { mapId } = getContext< MapContext >();
			const fraction = state[ mapId ]?.fraction;

			// Null fraction means "pointer left" — hide the marker.
			if ( fraction === null || fraction === undefined ) {
				entry.cursor.setStyle( { opacity: 0, fillOpacity: 0 } );
				return;
			}

			// Resolve fraction to a lat/lng and move the marker.
			const latLng = fractionToLatLng( entry.coords, fraction );
			if ( ! latLng ) {
				return;
			}

			entry.cursor.setLatLng( latLng );
			entry.cursor.setStyle( { opacity: 1, fillOpacity: 1 } );
		},
	},
} );
