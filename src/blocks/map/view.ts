/**
 * GPX Map frontend Interactivity API module.
 *
 * Registers the `kntnt-gpx-blocks` store and implements:
 *
 * - `callbacks.initMap` — mounts Leaflet directly into the block element when
 *   the consent contract permits it. The contract is exposed by the inline
 *   stub on `window.kntnt_gpx_blocks` (see `js/consent-stub.js` and
 *   `docs/consent.md`). The single category is `'external_media'`. The default
 *   is permitted: an absent signal does not block tile loading. Subscribes to
 *   subsequent transitions via `onConsentChanged` so a denying signal tears
 *   the map down and a granting signal re-mounts it.
 * - `callbacks.onMapCursorChange` — reacts to `state[mapId].fraction` changes
 *   (from Map itself or from GPX Elevation) by moving the cursor marker along
 *   the polyline without re-emitting the fraction. Namespaced per block so the
 *   Elevation module's own watch callback is not overwritten when both modules
 *   register into the shared `kntnt-gpx-blocks` store.
 *
 * Editor bypass: when PHP detects a REST `block-renderer` request from a user
 * with `edit_posts`, it sets `bypassConsent: true` in the per-map state slice.
 * The view module then mounts Leaflet immediately and skips the consent
 * subscription — the editor authoring surface always shows a working map.
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
		 * The plugin's CMP-neutral consent contract, exposed by the inline
		 * stub in `js/consent-stub.js`. The stub is enqueued unconditionally
		 * in <head>, so this object is normally always present on the
		 * frontend. Optional in the type to keep the JS robust against
		 * optimisation plugins that strip inline scripts. See `docs/consent.md`.
		 */
		kntnt_gpx_blocks?: {
			getConsent: ( category: string ) => boolean | null;
			mayProceed: ( category: string ) => boolean;
			onConsentChanged: (
				handler: ( category: string, granted: boolean | null ) => void
			) => () => void;
		};
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
	/** Enable pinch-to-zoom on touch devices. */
	readonly enablePinchZoom: boolean;
	/** Enable double-click zoom. */
	readonly enableDoubleClickZoom: boolean;
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
	/**
	 * Translated overlay messages shown when the user scrolls a mouse wheel
	 * over the map without holding the modifier key. Pre-translated by PHP
	 * because `@wordpress/i18n` is not available inside view-script modules.
	 */
	scrollHint: {
		/** Apple-platform variant — uses the Cmd glyph. */
		apple: string;
		/** Non-Apple variant — uses the Ctrl key name. */
		other: string;
	};
	/**
	 * True when PHP detected an editor render context (REST block-renderer
	 * with `edit_posts`). When true, the view module mounts Leaflet
	 * immediately and skips every consent check.
	 */
	bypassConsent: boolean;
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
	/** Aborts document-level listeners (scrub end, hint timer) on tear-down. */
	disposer: AbortController;
}

// ─── Module constants ────────────────────────────────────────────────────────

/**
 * The single consent category the plugin queries.
 *
 * Documented in `docs/consent.md`. The plugin uses this category and only this
 * category; the site builder's glue translates their CMP's category name to
 * this one.
 *
 * @since 1.0.0
 */
const CONSENT_CATEGORY = 'external_media';

// ─── Module state ─────────────────────────────────────────────────────────────

/**
 * Tracks which block elements already have a Leaflet instance mounted so that
 * re-hydration (e.g. in the editor's ServerSideRender) never double-mounts.
 *
 * Keyed by the block wrapper element; the value carries the map, cursor marker,
 * and pre-computed coordinate array needed by `onMapCursorChange`.
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
 * Find the fraction along a coordinate array that lies nearest to a given
 * lat/lng using a linear nearest-vertex scan.
 *
 * For typical simplified tracks (~300 vertices) the linear scan is fast enough
 * to run on every pointermove without throttling.
 *
 * @since 1.0.0
 *
 * @param coords - Flat [lat, lng] array.
 * @param latlng - Pointer position.
 * @param map    - Leaflet map used for haversine distance.
 * @return Fraction in [0, 1].
 */
function nearestFraction(
	coords: Array< [ number, number ] >,
	latlng: L.LatLng,
	map: L.Map
): number {
	let nearest = 0;
	let best = Infinity;
	for ( let i = 0; i < coords.length; i++ ) {
		const d = map.distance( latlng, coords[ i ] as [ number, number ] );
		if ( d < best ) {
			best = d;
			nearest = i;
		}
	}
	return nearest / ( coords.length - 1 );
}

/**
 * Wire pointer tracking on the GeoJSON polyline.
 *
 * Hover over the polyline writes `state[mapId].fraction` so the cursor follows
 * the pointer without any click. A press on the polyline begins a scrub: the
 * map's drag handler is suspended for the duration of the press, and a
 * map-level mousemove listener follows the pointer (even if it leaves the
 * polyline) to update fraction continuously. A document-level pointerup ends
 * the scrub and re-enables drag.
 *
 * Pointerleave on the block element nulls the fraction so both cursors hide,
 * unless a scrub is in progress — in that case the cursor stays visible and
 * tracks the pointer until release.
 *
 * @since 1.0.0
 *
 * @param map     - Leaflet map instance.
 * @param layer   - GeoJSON polyline layer with hit-testable mouse events.
 * @param blockEl - Block wrapper element used for the leave handler.
 * @param coords  - Flat [lat, lng] array shared with the cursor sync.
 * @param mapId   - Interactivity store key for this map.
 * @param signal  - AbortSignal that releases document-level listeners on tear-down.
 */
function attachScrubHandlers(
	map: L.Map,
	layer: L.GeoJSON,
	blockEl: HTMLElement,
	coords: Array< [ number, number ] >,
	mapId: string,
	signal: AbortSignal
): void {
	if ( coords.length < 2 ) {
		return;
	}

	let scrubbing = false;

	// Hover (no button pressed) — cursor follows the pointer along the track.
	layer.on( 'mousemove', ( e: L.LeafletMouseEvent ) => {
		state[ mapId ].fraction = nearestFraction( coords, e.latlng, map );
	} );

	// Press on the polyline — start scrubbing. Disable map drag so the map
	// stays put and the cursor moves instead.
	layer.on( 'mousedown', ( e: L.LeafletMouseEvent ) => {
		scrubbing = true;
		map.dragging.disable();
		state[ mapId ].fraction = nearestFraction( coords, e.latlng, map );
	} );

	// While scrubbing, follow the pointer over the entire map — not just the
	// polyline — so the cursor keeps tracking even if the pointer drifts off.
	map.on( 'mousemove', ( e: L.LeafletMouseEvent ) => {
		if ( ! scrubbing ) {
			return;
		}
		state[ mapId ].fraction = nearestFraction( coords, e.latlng, map );
	} );

	// Release ends the scrub, regardless of where it happens. Document-level
	// listeners catch releases that occur outside the map element. Both
	// pointerup and pointercancel are needed: the latter fires on touch when
	// the OS interrupts the gesture (alert, fullscreen exit, etc.).
	const endScrub = () => {
		if ( ! scrubbing ) {
			return;
		}
		scrubbing = false;
		map.dragging.enable();
	};
	document.addEventListener( 'pointerup', endScrub, { signal } );
	document.addEventListener( 'pointercancel', endScrub, { signal } );

	// Pointer leaves the block — hide both cursors. Skip while scrubbing so
	// brief excursions outside the block (e.g., the cursor strays off-map
	// during a fast scrub) do not flicker the cursor off.
	blockEl.addEventListener(
		'pointerleave',
		() => {
			if ( scrubbing ) {
				return;
			}
			state[ mapId ].fraction = null;
		},
		{ signal }
	);
}

/**
 * Classify a wheel event into the action it should trigger on the map.
 *
 * On macOS, trackpad pinch gestures are delivered as wheel events with
 * `ctrlKey: true` regardless of whether Ctrl is physically pressed; the same
 * shortcut is used by mouse-wheel zoom on every other platform. Trackpad
 * two-finger pan delivers wheel events with `deltaMode === 0` (pixel deltas)
 * and no modifier. Mouse wheels deliver `deltaMode === 1` (line deltas) on
 * most browsers; even when they emit pixel deltas, the per-tick delta is
 * coarse and not paired with the small horizontal pans a trackpad emits.
 *
 * @since 1.0.0
 *
 * @param event - The wheel event.
 * @return One of `'zoom'`, `'pan'`, or `'hint'`.
 */
function classifyWheel( event: WheelEvent ): 'zoom' | 'pan' | 'hint' {
	if ( event.ctrlKey || event.metaKey ) {
		return 'zoom';
	}
	if ( event.deltaMode === 0 ) {
		return 'pan';
	}
	return 'hint';
}

/**
 * Pick the platform-appropriate scroll-zoom hint string from the pre-translated
 * pair carried on the state slice.
 *
 * Uses the apple variant on macOS / iOS / iPadOS and the non-apple variant
 * elsewhere. Translation happens server-side because view-script modules
 * cannot import `@wordpress/i18n`.
 *
 * @since 1.0.0
 *
 * @param hint - The pre-translated `{ apple, other }` pair.
 * @return The variant matching the current user-agent.
 */
function pickScrollHintMessage( hint: MapState[ 'scrollHint' ] ): string {
	const platform = navigator.platform || '';
	const userAgent = navigator.userAgent || '';
	const isApple =
		/Mac|iPhone|iPad|iPod/i.test( platform ) ||
		/Mac OS X|iPhone|iPad|iPod/i.test( userAgent );
	return isApple ? hint.apple : hint.other;
}

/**
 * Attach the custom wheel handler that replaces Leaflet's scrollWheelZoom.
 *
 * Sets `passive: false` so the handler can `preventDefault()` on zoom and pan
 * events; the hint branch never preventDefault()s, letting the page scroll
 * normally. The hint overlay is created lazily and reused; a single timer
 * ensures the overlay disappears after roughly one second of wheel idleness.
 *
 * @since 1.0.0
 *
 * @param map     - Leaflet map instance.
 * @param blockEl - Block wrapper element receiving wheel events.
 * @param hint    - Pre-translated `{ apple, other }` hint pair.
 * @param signal  - AbortSignal that releases listeners on tear-down.
 */
function attachWheelHandler(
	map: L.Map,
	blockEl: HTMLElement,
	hint: MapState[ 'scrollHint' ],
	signal: AbortSignal
): void {
	let hintEl: HTMLElement | null = null;
	let hintTimer: number | null = null;

	const showHint = (): void => {
		// Lazy-create the overlay once per map; reuse across hint events.
		if ( ! hintEl ) {
			hintEl = document.createElement( 'div' );
			hintEl.className = 'kntnt-gpx-blocks-map-scroll-hint';
			hintEl.textContent = pickScrollHintMessage( hint );
			hintEl.setAttribute( 'role', 'status' );
			hintEl.setAttribute( 'aria-live', 'polite' );
			blockEl.appendChild( hintEl );
		}

		hintEl.classList.add( 'is-visible' );

		// Reset the auto-dismiss timer on every wheel tick so the overlay
		// stays up while the user keeps scrolling.
		if ( hintTimer !== null ) {
			window.clearTimeout( hintTimer );
		}
		hintTimer = window.setTimeout( () => {
			hintEl?.classList.remove( 'is-visible' );
			hintTimer = null;
		}, 1200 );
	};

	blockEl.addEventListener(
		'wheel',
		( event: WheelEvent ) => {
			const action = classifyWheel( event );

			if ( action === 'zoom' ) {
				event.preventDefault();

				// Step matches Leaflet's default scroll-wheel zoom feel:
				// one delta tick equals one zoom level, scaled by deltaY sign.
				const step = event.deltaY < 0 ? 1 : -1;
				const center = map.mouseEventToLatLng( event );
				const next = Math.max(
					map.getMinZoom(),
					Math.min( map.getMaxZoom(), map.getZoom() + step )
				);
				map.setZoomAround( center, next );
				return;
			}

			if ( action === 'pan' ) {
				event.preventDefault();
				map.panBy( [ event.deltaX, event.deltaY ], { animate: false } );
				return;
			}

			// `hint` — do NOT preventDefault. Page scrolls past the map
			// while the overlay surfaces the modifier-key requirement.
			showHint();
		},
		{ passive: false, signal }
	);
}

/**
 * Mount the Leaflet map directly into the block element.
 *
 * Defers actual mount until the element enters the viewport via
 * IntersectionObserver so the map has real layout dimensions at init time.
 * The `mountedMaps` WeakMap is checked before entering the observer; the
 * observer callback also guards against redundant mounts in case of race
 * conditions.
 *
 * After fitBounds, calls `map.invalidateSize()` once to force Leaflet to
 * re-measure the container — necessary when the element became visible just
 * before mount (e.g. a consent transition from denying to granting).
 *
 * @since 1.0.0
 *
 * @param blockEl  - The `.kntnt-gpx-blocks-map` block wrapper element.
 * @param mapId    - The mapId string key in the Interactivity state.
 * @param mapState - The hydrated state slice for this map.
 */
function bootMount(
	blockEl: HTMLElement,
	mapId: string,
	mapState: MapState
): void {
	// Guard against double-mount before entering the observer.
	if ( mountedMaps.has( blockEl ) ) {
		return;
	}

	// Defer Leaflet initialisation until the block is visible so the
	// map has real layout dimensions when it mounts.
	const observer = new IntersectionObserver(
		( entries, obs ) => {
			const entry = entries[ 0 ];
			if ( ! entry?.isIntersecting ) {
				return;
			}

			// Stop observing now that we're about to mount.
			obs.disconnect();

			// Guard again in case state mutated between the observer
			// callback firing and this point in execution.
			if ( mountedMaps.has( blockEl ) ) {
				return;
			}

			// Build the Leaflet map with canvas renderer for performance.
			// Suppress Leaflet's own scrollWheelZoom (replaced by a custom
			// wheel handler below) and boxZoom (removed entirely — see
			// docs/architecture.md). The default zoomControl is also off
			// because the settings-driven path adds it conditionally.
			const map = L.map( blockEl, {
				renderer: L.canvas(),
				zoomControl: false,
				attributionControl: true,
				scrollWheelZoom: false,
				boxZoom: false,
			} );

			// Add the OSM tile layer — only reached when the consent contract
			// permits proceeding (or in editor bypass).
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

			// Force Leaflet to re-measure the container. Necessary in two
			// situations: the block became visible just before mount (a
			// denying-to-granting consent transition), or the editor's
			// ServerSideRender re-rendered the block while its iframe was
			// still settling layout.
			map.invalidateSize( false );

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
							'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M12 3v12m-5-5l5 5l5-5M5 19h14" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
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
			if ( settings.enableKeyboard ) {
				map.keyboard.enable();
			} else {
				map.keyboard.disable();
			}

			// AbortController carrying every document-level listener attached
			// below so tearDown() can release them in one call. Stored in the
			// MapEntry so the cleanup path stays mechanical.
			const disposer = new AbortController();

			// Replace Leaflet's scrollWheelZoom with a wheel handler that
			// distinguishes pinch / Cmd / Ctrl (zoom), trackpad two-finger
			// pan (deltaMode 0, no modifier), and mouse wheel (deltaMode 1+,
			// no modifier — show a hint and let the page scroll).
			attachWheelHandler(
				map,
				blockEl,
				mapState.scrollHint,
				disposer.signal
			);

			// Pre-compute the flat [lat, lng] coordinate array for
			// fraction→position resolution in onMapCursorChange.
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
			// onMapCursorChange can access the cursor and coords.
			mountedMaps.set( blockEl, { map, cursor, coords, disposer } );

			// Wire pointer tracking on the polyline. Hover over the track
			// updates fraction; press-and-drag on the track scrubs the cursor
			// without panning the map. Pointerup anywhere ends the scrub.
			attachScrubHandlers(
				map,
				layer,
				blockEl,
				coords,
				mapId,
				disposer.signal
			);
		},
		// rootMargin pre-triggers the observer 200 px before the block enters
		// the viewport, giving Leaflet time to initialise tiles before the
		// block scrolls into view. threshold: 0 fires as soon as any pixel is
		// visible (or pre-visible via rootMargin).
		{ rootMargin: '200px 0px', threshold: 0 }
	);

	observer.observe( blockEl );
}

/**
 * Tear down the Leaflet instance attached to the given block element, if any.
 *
 * Called when the consent contract reports a denying signal for
 * `'external_media'`. The block element is left in the DOM with no visible
 * content; the active CMP's content blocker is expected to reclaim the visual
 * area. The plugin renders no placeholder of its own — see `docs/consent.md`.
 *
 * @since 1.0.0
 *
 * @param blockEl - The block wrapper element.
 */
function tearDown( blockEl: Element ): void {
	const entry = mountedMaps.get( blockEl );
	if ( ! entry ) {
		return;
	}
	entry.disposer.abort();
	entry.map.remove();
	mountedMaps.delete( blockEl );
}

// ─── Store ────────────────────────────────────────────────────────────────────

const { state } = store< { state: PluginState } >( 'kntnt-gpx-blocks', {
	state: {} as PluginState,
	callbacks: {
		/**
		 * Initialise the block: consult the consent contract and mount Leaflet
		 * when permitted, then subscribe to subsequent consent transitions.
		 *
		 * Called by `data-wp-init` on the block's root element. The decision
		 * tree is:
		 *
		 *  1. If `bypassConsent` is true (editor preview), mount immediately
		 *     and skip the subscription — the editor authoring surface always
		 *     shows a working map.
		 *  2. Otherwise consult `window.kntnt_gpx_blocks.mayProceed` for the
		 *     `'external_media'` category. Mount when it returns true (the
		 *     default-allow rule means absent signal also returns true).
		 *  3. Subscribe to `onConsentChanged`. On a denying transition, tear
		 *     down via `map.remove()`. On a granting transition, re-mount
		 *     (idempotent — guarded by `mountedMaps`). An absent transition
		 *     (handler called with `null`) is a no-op: the contract's
		 *     default-allow rule keeps any already-mounted map running.
		 *
		 * When the inline stub is unexpectedly missing — for example when an
		 * optimisation plugin has stripped it — the contract's default-allow
		 * rule applies and Leaflet mounts normally. A console warning is
		 * emitted so the misconfiguration is visible.
		 *
		 * @since 1.0.0
		 */
		initMap() {
			const { ref } = getElement();
			if ( ! ref || ! ( ref instanceof HTMLElement ) ) {
				return;
			}

			const { mapId } = getContext< MapContext >();
			const mapState = state[ mapId ];
			if ( ! mapState ) {
				return;
			}

			// Editor short-circuit: when PHP flagged this render as an editor
			// preview, mount immediately and do not subscribe to consent.
			if ( mapState.bypassConsent ) {
				bootMount( ref, mapId, mapState );
				return;
			}

			// Resolve the consent contract from the inline stub. The stub is
			// enqueued unconditionally in <head>; if it is missing here, an
			// optimisation plugin has stripped it. Default-allow keeps the
			// map functional in that case, but the misconfiguration is worth
			// surfacing.
			const consent = window.kntnt_gpx_blocks;
			if ( ! consent ) {
				// eslint-disable-next-line no-console
				console.warn(
					'[kntnt-gpx-blocks] window.kntnt_gpx_blocks is missing; mounting under default-allow. See docs/consent.md.'
				);
				bootMount( ref, mapId, mapState );
				return;
			}

			// Mount when the contract permits proceeding. mayProceed returns
			// true on granting and on absent — only literal denying blocks.
			if ( consent.mayProceed( CONSENT_CATEGORY ) ) {
				bootMount( ref, mapId, mapState );
			}

			// Subscribe to subsequent transitions. Granting re-mounts (the
			// WeakMap guards against duplicates). Denying tears down. Absent
			// (null) is a no-op — the contract has no defined revoke-to-absent
			// transition, and falling back to default-allow on absent would
			// fight any pending tear-down.
			consent.onConsentChanged( ( category, granted ) => {
				if ( category !== CONSENT_CATEGORY ) {
					return;
				}
				if ( granted === true ) {
					bootMount( ref, mapId, mapState );
					return;
				}
				if ( granted === false ) {
					tearDown( ref );
				}
			} );
		},

		/**
		 * React to changes in `state[mapId].fraction` and move the cursor marker.
		 *
		 * Bound via `data-wp-watch--cursor` on the block's root element. Does
		 * NOT write back to state — that would create a feedback loop.
		 *
		 * Named per block (rather than the generic `onCursorChange`) so that
		 * registering both Map and Elevation modules into the same
		 * `kntnt-gpx-blocks` store does not overwrite each other's callbacks.
		 *
		 * @since 1.0.0
		 */
		onMapCursorChange() {
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
