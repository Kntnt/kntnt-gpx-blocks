/**
 * GPX Map frontend Interactivity API module.
 *
 * Registers the `kntnt-gpx-blocks` store and implements `callbacks.initMap`,
 * which mounts a Leaflet map with a canvas-rendered track polyline into the
 * block's container element. The map is deferred until the container enters
 * the viewport via IntersectionObserver, then mounted once per element.
 *
 * All Leaflet controls and interaction modes are disabled here — toggling them
 * on/off based on block attributes arrives in issue #7.
 *
 * Tile loading is unconditional in this slice. The consent gate arrives in #10.
 *
 * Leaflet's CSS is imported here so `@wordpress/scripts` bundles it into the
 * view-side CSS asset. The block's own styles live in style.scss.
 *
 * @since 1.0.0
 */

import { getContext, getElement, store } from '@wordpress/interactivity';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

// ─── Types ───────────────────────────────────────────────────────────────────

/**
 * Shape of the per-map state slice hydrated from PHP via wp_interactivity_state.
 *
 * @since 1.0.0
 */
interface MapState {
	attachmentId: number;
	geojson: object;
	waypoints: object;
	settings: Record< string, unknown >;
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

// ─── Module state ─────────────────────────────────────────────────────────────

/**
 * Tracks which container elements already have a Leaflet instance mounted so
 * that re-hydration (e.g. in the editor's ServerSideRender) never double-mounts.
 *
 * WeakMap is used so the entry is garbage-collected when the element is removed
 * from the DOM.
 *
 * @since 1.0.0
 */
const mountedMaps = new WeakMap< Element, L.Map >();

// ─── Store ────────────────────────────────────────────────────────────────────

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

					// Build the Leaflet map. All controls and interaction modes
					// are disabled here; they will be enabled per-attribute in #7.
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
					const layer = L.geoJSON(
						mapState.geojson as GeoJSON.GeoJsonObject
					);
					layer.addTo( map );

					// Fit the viewport to the track bounds with small padding so
					// the polyline never touches the container edge.
					const bounds = layer.getBounds();
					if ( bounds.isValid() ) {
						map.fitBounds( bounds, { padding: [ 16, 16 ] } );
					}

					// Record the instance so subsequent init calls are no-ops.
					mountedMaps.set( ref, map );
				},
				{ threshold: 0 }
			);

			observer.observe( ref );
		},
	},
} );
