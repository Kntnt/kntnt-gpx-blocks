/**
 * GPX Map frontend Interactivity API module.
 *
 * Registers the `kntnt-gpx-blocks` store and implements:
 *
 * - `callbacks.initMap` — checks consent state, then either mounts a Leaflet
 *   map immediately (consent granted) or defers mount until consent is granted.
 *   The consent check queries `window.wp_has_consent`; when the Consent API
 *   plugin is absent, the block starts with the placeholder visible until the
 *   visitor clicks "Activate map".
 * - `callbacks.onCursorChange` — reacts to `state[mapId].fraction` changes
 *   (from Map itself or from GPX Elevation) by moving the cursor marker along
 *   the polyline without re-emitting the fraction (no feedback loop).
 * - `callbacks.onConsentChange` — reacts to `state[mapId].consent` changes.
 *   Mounts Leaflet when consent transitions to 'granted'; tears down the map
 *   and shows the placeholder when consent transitions to 'denied'.
 * - `actions.grantConsent` — sets `state[mapId].consent = 'granted'` for this
 *   block only; does NOT call into the Consent API to grant site-wide consent.
 *
 * Consent API integration:
 * - `wp_has_consent` (if present) is called once at `initMap` time to set the
 *   initial consent state from whatever the visitor has already decided.
 * - `wp_listen_for_consent_change` events update state when consent changes
 *   site-wide (both grant and revoke).
 * - The custom DOM event `kntnt-gpx-blocks/grant-consent` on the block element
 *   dispatches `grantConsent` so non-Consent-API plugins can trigger activation.
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

// ─── Global type augmentation ────────────────────────────────────────────────

declare global {
	interface Window {
		/**
		 * WordPress Consent API — returns true when the visitor has granted
		 * consent for the given category (and optional service). Provided by
		 * consent-management plugins; absent when no such plugin is active.
		 */
		wp_has_consent?: ( category: string, service?: string ) => boolean;
	}
}

// ─── Types ───────────────────────────────────────────────────────────────────

/**
 * The map control settings hydrated from PHP block attributes.
 *
 * Each flag corresponds to an `InspectorControls` toggle in `edit.tsx` and a
 * Leaflet control added (or omitted) during Leaflet mount.
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
	/** Enable drag-to-pan. */
	readonly enableDrag: boolean;
	/** Enable scroll-wheel zoom. Disabled by default to prevent scroll hijacking. */
	readonly enableScrollWheelZoom: boolean;
	/** Enable pinch-to-zoom on touch devices. */
	readonly enablePinchZoom: boolean;
	/** Enable double-click zoom. */
	readonly enableDoubleClickZoom: boolean;
	/** Enable shift+drag box zoom. */
	readonly enableBoxZoom: boolean;
	/** Enable keyboard navigation. Required for accessibility. */
	readonly enableKeyboard: boolean;
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
	/** Consent API category to check; comes from the kntnt_gpx_blocks_consent_category filter. */
	consentCategory: string;
	/** Consent API service identifier; comes from the kntnt_gpx_blocks_consent_service filter. */
	consentService: string;
	/** Placeholder text to display before consent is granted. */
	placeholderText: string;
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
 * Keyed by the block's canvas child element; the value carries the map, cursor
 * marker, and pre-computed coordinate array needed by `onCursorChange`.
 *
 * @since 1.0.0
 */
const mountedMaps = new WeakMap< Element, MapEntry >();

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Read a CSS custom property from an element's computed style.
 *
 * Returns the fallback when the property is not set or resolves to an empty
 * string. CSS variables cannot be applied directly to canvas-rendered Leaflet
 * shapes (polyline, CircleMarker), so we read them once at mount time and pass
 * the resolved value through Leaflet's path options.
 *
 * @since 1.0.0
 *
 * @param element  - The element whose computed style is queried.
 * @param property - CSS custom property name, e.g. `'--kntnt-gpx-blocks-track-color'`.
 * @param fallback - Value returned when the property resolves to empty.
 * @return Resolved value or fallback.
 */
function getCssVar(
	element: Element,
	property: string,
	fallback: string
): string {
	return (
		getComputedStyle( element ).getPropertyValue( property ).trim() ||
		fallback
	);
}

/**
 * Build and add the OSM tile layer to the given map.
 *
 * Extracted to keep `bootMount` readable; the tile URL and attribution are the
 * same for every instance.
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

/**
 * Mount the Leaflet map into the canvas child element.
 *
 * Defers actual mount until the canvas element enters the viewport via
 * IntersectionObserver so the map has real layout dimensions at init time.
 * The `mountedMaps` WeakMap is checked before entering the observer, but the
 * observer callback also guards against redundant mounts in case of race
 * conditions between the observer and a direct consent grant.
 *
 * @since 1.0.0
 *
 * @param canvas   - The `.kntnt-gpx-blocks-map-canvas` child element.
 * @param mapId    - The mapId string key in the Interactivity state.
 * @param mapState - The hydrated state slice for this map.
 * @param blockEl  - The outer block wrapper, used for CSS variable resolution.
 */
function bootMount(
	canvas: HTMLElement,
	mapId: string,
	mapState: MapState,
	blockEl: Element
): void {
	// Guard against double-mount before entering the observer.
	if ( mountedMaps.has( canvas ) ) {
		return;
	}

	// Defer Leaflet initialisation until the canvas is visible so the
	// map has real layout dimensions when it mounts.
	const observer = new IntersectionObserver(
		( entries, obs ) => {
			const entry = entries[ 0 ];
			if ( ! entry?.isIntersecting ) {
				return;
			}

			// Stop observing now that we're about to mount.
			obs.disconnect();

			// Guard again in case consent was revoked between the observer
			// callback firing and this point in execution.
			if ( mountedMaps.has( canvas ) ) {
				return;
			}

			// Build the Leaflet map with canvas renderer for performance.
			// Suppress the default zoomControl here because the settings-driven
			// path below adds it conditionally.
			const map = L.map( canvas, {
				renderer: L.canvas(),
				zoomControl: false,
				attributionControl: true,
			} );

			// Add the OSM tile layer — only reached when consent is granted.
			addTileLayer( map );

			// Read the track colour CSS variables once at mount time.
			// Leaflet canvas-rendered shapes receive their colour through JS
			// options — they cannot be styled via CSS — so we resolve the
			// computed value here and pass it through the style callback.
			const trackColor = getCssVar(
				blockEl,
				'--kntnt-gpx-blocks-track-color',
				'#0073aa'
			);

			// Render the simplified track from the hydrated GeoJSON, using
			// the resolved track colour for the polyline.
			const layer = L.geoJSON( mapState.geojson, {
				style: () => ( {
					color: trackColor,
					weight: 4,
					opacity: 1,
				} ),
			} );
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
				const filename = gpxUrl.split( '/' ).pop() ?? 'track.gpx';

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
						anchor.setAttribute( 'aria-label', 'Download GPX' );
						anchor.innerHTML =
							'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M12 16l-5-5 1.4-1.4 2.6 2.6V4h2v8.2l2.6-2.6L17 11l-5 5zm-7 2h14v2H5v-2z"/></svg>';
						L.DomEvent.disableClickPropagation( container );
						return container;
					},
				} );
				new DownloadControl( { position: 'topleft' } ).addTo( map );
			}

			// Apply each interaction handler per the hydrated settings.
			// Using explicit enable/disable calls rather than L.map init
			// options keeps the settings as the single source of truth.
			if ( settings.enableDrag ) {
				map.dragging.enable();
			} else {
				map.dragging.disable();
			}
			if ( settings.enableScrollWheelZoom ) {
				map.scrollWheelZoom.enable();
			} else {
				map.scrollWheelZoom.disable();
			}
			if ( settings.enablePinchZoom ) {
				map.touchZoom.enable();
			} else {
				map.touchZoom.disable();
			}
			if ( settings.enableDoubleClickZoom ) {
				map.doubleClickZoom.enable();
			} else {
				map.doubleClickZoom.disable();
			}
			if ( settings.enableBoxZoom ) {
				map.boxZoom.enable();
			} else {
				map.boxZoom.disable();
			}
			if ( settings.enableKeyboard ) {
				map.keyboard.enable();
			} else {
				map.keyboard.disable();
			}

			// Pre-compute the flat [lat, lng] coordinate array for
			// fraction→position resolution in onCursorChange.
			const coords = extractCoords( mapState.geojson );

			// Read the remaining colour CSS variables once at mount time.
			const trackCursorColor = getCssVar(
				blockEl,
				'--kntnt-gpx-blocks-track-cursor-color',
				'#d63638'
			);
			const waypointColor = getCssVar(
				blockEl,
				'--kntnt-gpx-blocks-waypoint-color',
				'#d63638'
			);

			// Create the cursor marker at the track midpoint, initially
			// invisible. Opacity is set to 1 on the first non-null fraction.
			const midLatLng = fractionToLatLng( coords, 0.5 ) ?? [ 0, 0 ];
			const cursor = L.circleMarker( midLatLng, {
				radius: 6,
				color: trackCursorColor,
				weight: 2,
				fillColor: trackCursorColor,
				fillOpacity: 1,
				interactive: false,
				opacity: 0,
			} );
			cursor.addTo( map );

			// Add a circleMarker for each waypoint from the hydrated GeoJSON.
			const waypointsData = mapState.waypoints as GeoJSON.GeoJsonObject;
			if ( waypointsData?.type === 'FeatureCollection' ) {
				const wfc = waypointsData as GeoJSON.FeatureCollection;
				for ( const feature of wfc.features ) {
					if ( feature.geometry?.type !== 'Point' ) {
						continue;
					}

					// GeoJSON stores Point coordinates as [lon, lat, ele?].
					const pt = feature.geometry as GeoJSON.Point;
					const lon = pt.coordinates[ 0 ];
					const lat = pt.coordinates[ 1 ];
					if ( lon === undefined || lat === undefined ) {
						continue;
					}

					const marker = L.circleMarker( [ lat, lon ], {
						radius: 6,
						color: waypointColor,
						fillColor: waypointColor,
						fillOpacity: 1,
						weight: 2,
						interactive: true,
					} );

					// Build the tooltip label from name and optional desc.
					const props = feature.properties ?? {};
					const name =
						typeof props.name === 'string' ? props.name : '';
					const desc =
						typeof props.desc === 'string' ? props.desc : '';

					if ( name || desc ) {
						// Build the tooltip DOM element using text nodes so
						// that no GPX content can inject HTML.
						const tooltipEl = document.createElement( 'div' );
						const labelLines: string[] = [];
						if ( name ) {
							labelLines.push( name );
						}
						if ( desc ) {
							labelLines.push( desc );
						}
						labelLines.forEach( ( line, i ) => {
							if ( i > 0 ) {
								tooltipEl.appendChild(
									document.createElement( 'br' )
								);
							}
							tooltipEl.appendChild(
								document.createTextNode( line )
							);
						} );

						marker.bindTooltip( tooltipEl, {
							direction: 'top',
							permanent: false,
							sticky: false,
							opacity: 1,
						} );
					}

					marker.addTo( map );
				}
			}

			// Record the instance so subsequent init calls are no-ops and
			// onCursorChange can access the cursor and coords.
			mountedMaps.set( canvas, { map, cursor, coords } );

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
				state[ mapId ].fraction = nearest / ( coords.length - 1 );
			} );

			// Null the fraction when the pointer leaves the canvas so both
			// cursors hide.
			canvas.addEventListener( 'pointerleave', () => {
				state[ mapId ].fraction = null;
			} );
		},
		{ threshold: 0 }
	);

	observer.observe( canvas );
}

/**
 * Show the canvas child and hide the placeholder.
 *
 * Mutates inline display style imperatively — consistent with Leaflet's own
 * DOM manipulation and simpler than data-wp-bind for a one-shot show/hide.
 *
 * @since 1.0.0
 *
 * @param blockEl - The outer block wrapper element.
 */
function showCanvas( blockEl: Element ): void {
	const canvas = blockEl.querySelector< HTMLElement >(
		'.kntnt-gpx-blocks-map-canvas'
	);
	const placeholder = blockEl.querySelector< HTMLElement >(
		'.kntnt-gpx-blocks-map-placeholder'
	);
	if ( canvas ) {
		canvas.style.display = '';
	}
	if ( placeholder ) {
		placeholder.style.display = 'none';
	}
}

/**
 * Hide the canvas child and show the placeholder.
 *
 * @since 1.0.0
 *
 * @param blockEl - The outer block wrapper element.
 */
function showPlaceholder( blockEl: Element ): void {
	const canvas = blockEl.querySelector< HTMLElement >(
		'.kntnt-gpx-blocks-map-canvas'
	);
	const placeholder = blockEl.querySelector< HTMLElement >(
		'.kntnt-gpx-blocks-map-placeholder'
	);
	if ( canvas ) {
		canvas.style.display = 'none';
	}
	if ( placeholder ) {
		placeholder.style.display = '';
	}
}

// ─── Store ────────────────────────────────────────────────────────────────────

const { state } = store< { state: PluginState } >( 'kntnt-gpx-blocks', {
	state: {} as PluginState,
	actions: {
		/**
		 * Grant consent for the current block only.
		 *
		 * Sets `state[mapId].consent = 'granted'` for this map's state slice.
		 * This activates tile loading for this block in this page view only —
		 * it does NOT call into the Consent API to grant site-wide consent.
		 * That is the consent plugin's job.
		 *
		 * @since 1.0.0
		 */
		grantConsent() {
			const { mapId } = getContext< MapContext >();
			const mapState = state[ mapId ];
			if ( mapState ) {
				mapState.consent = 'granted';
			}
		},
	},
	callbacks: {
		/**
		 * Initialise the block's consent state and mount Leaflet if already granted.
		 *
		 * Called by the data-wp-init directive on the block's root element. Queries
		 * the WordPress Consent API (`wp_has_consent`) when it is available to set
		 * the initial consent state. When consent is granted, calls bootMount to
		 * initialise Leaflet. When consent is unknown and the API is absent, the
		 * placeholder remains visible until the visitor clicks "Activate map".
		 *
		 * Also:
		 * - Registers the site-wide `wp_listen_for_consent_change` listener (once
		 *   per page, deduplicated by a module-level flag set in the listener itself).
		 * - Registers a `kntnt-gpx-blocks/grant-consent` custom event listener on
		 *   the block element for non-Consent-API plugin integrations.
		 *
		 * @since 1.0.0
		 */
		initMap() {
			const { ref } = getElement();

			// No element reference means we're outside a valid directive scope;
			// bail silently.
			if ( ! ref ) {
				return;
			}

			const { mapId } = getContext< MapContext >();
			const mapState = state[ mapId ];

			// State slice not yet populated — defensive bail; wp_interactivity_state
			// should always populate it before the module runs.
			if ( ! mapState ) {
				return;
			}

			// Query the Consent API for the current consent state when available.
			// If the API is absent (no consent plugin active), leave the state as-is:
			// 'granted' stays granted (server-side bypass), 'unknown' stays unknown
			// and the placeholder remains visible.
			if (
				mapState.consent === 'unknown' &&
				typeof window.wp_has_consent === 'function'
			) {
				mapState.consent = window.wp_has_consent(
					mapState.consentCategory
				)
					? 'granted'
					: 'denied';
			} else if ( mapState.consent === 'unknown' ) {
				// No Consent API present — default to denied so no tiles are requested.
				mapState.consent = 'denied';
			}

			// Apply initial visibility based on the resolved consent state.
			if ( mapState.consent === 'granted' ) {
				showCanvas( ref );
			} else {
				showPlaceholder( ref );
			}

			// Mount Leaflet immediately when consent is already granted.
			if ( mapState.consent === 'granted' ) {
				const canvas = ref.querySelector< HTMLElement >(
					'.kntnt-gpx-blocks-map-canvas'
				);
				if ( canvas ) {
					bootMount( canvas, mapId, mapState, ref );
				}
			}

			// Listen for the custom grant-consent DOM event so non-Consent-API
			// plugins can activate this block by dispatching on its element.
			// The mapId is captured in the closure from the initMap call.
			ref.addEventListener( 'kntnt-gpx-blocks/grant-consent', () => {
				const currentState = state[ mapId ];
				if ( currentState ) {
					currentState.consent = 'granted';
				}
			} );

			// Register the site-wide consent change listener once for this mapId.
			// Each block instance subscribes independently so all maps on the page
			// react to a single consent change event.
			document.addEventListener(
				'wp_listen_for_consent_change',
				( event: Event ) => {
					const detail = (
						event as CustomEvent< Record< string, string > >
					 ).detail;
					if ( ! detail ) {
						return;
					}

					// Mirror the category change into this block's state.
					const category = state[ mapId ]?.consentCategory;
					if ( ! category ) {
						return;
					}
					if ( detail[ category ] === 'allow' ) {
						state[ mapId ].consent = 'granted';
					} else if ( detail[ category ] === 'deny' ) {
						state[ mapId ].consent = 'denied';
					}
				}
			);
		},

		/**
		 * React to changes in state[mapId].consent.
		 *
		 * Called by data-wp-watch on the block's root element. Mounts Leaflet when
		 * consent transitions to 'granted', and tears it down when it transitions
		 * to 'denied'. The canvas/placeholder visibility is updated imperatively.
		 *
		 * @since 1.0.0
		 */
		onConsentChange() {
			const { ref } = getElement();
			if ( ! ref ) {
				return;
			}

			const { mapId } = getContext< MapContext >();
			const mapState = state[ mapId ];
			if ( ! mapState ) {
				return;
			}

			// Grant: show canvas, mount Leaflet (deferred until intersection).
			if ( mapState.consent === 'granted' ) {
				showCanvas( ref );
				const canvas = ref.querySelector< HTMLElement >(
					'.kntnt-gpx-blocks-map-canvas'
				);
				if ( canvas ) {
					bootMount( canvas, mapId, mapState, ref );
				}
				return;
			}

			// Deny: tear down the Leaflet instance if mounted, show placeholder.
			if ( mapState.consent === 'denied' ) {
				const canvas = ref.querySelector< HTMLElement >(
					'.kntnt-gpx-blocks-map-canvas'
				);
				if ( canvas ) {
					const entry = mountedMaps.get( canvas );
					if ( entry ) {
						entry.map.remove();
						mountedMaps.delete( canvas );
					}
				}
				showPlaceholder( ref );
			}
		},

		/**
		 * React to changes in state[mapId].fraction and move the cursor marker.
		 *
		 * Bound via data-wp-watch on the `.kntnt-gpx-blocks-map-canvas` child
		 * element, so `ref` here is the canvas element itself. Does NOT write
		 * back to state — that would create a feedback loop.
		 *
		 * @since 1.0.0
		 */
		onCursorChange() {
			const { ref } = getElement();
			if ( ! ref ) {
				return;
			}

			// ref is the canvas child element; look up the Leaflet entry directly.
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
