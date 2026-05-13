/**
 * GPX Map frontend Interactivity API module.
 *
 * Registers the `kntnt-gpx-blocks` store and implements:
 *
 * - `callbacks.initMap` — mounts Leaflet directly into the block element. The
 *   polyline, cursor, controls, and waypoint markers mount unconditionally
 *   (they are local SVG/canvas drawn from cached GeoJSON and are not subject
 *   to the consent contract). Tile layers are added or removed independently
 *   based on the consent contract exposed by the inline stub on
 *   `window.kntnt_gpx_blocks` (see `js/consent-stub.js` and `docs/consent.md`).
 *   The single category is `'external_media'`. The default is permitted: an
 *   absent signal does not block tile loading. The callback subscribes to
 *   subsequent transitions via `onConsentChanged` so a denying signal removes
 *   tiles (`removeTiles`) and a granting signal restores them (`addTiles`).
 *   The polyline et al. survive both transitions.
 * - `callbacks.onMapCursorChange` — reacts to `state[mapId].fraction` changes
 *   (from Map itself or from GPX Elevation) by moving the cursor marker along
 *   the polyline without re-emitting the fraction. Namespaced per block so the
 *   Elevation module's own watch callback is not overwritten when both modules
 *   register into the shared `kntnt-gpx-blocks` store.
 *
 * Editor bypass: when PHP detects a REST `block-renderer` request from a user
 * with `edit_posts`, it sets `bypassConsent: true` in the per-map state slice.
 * The view module then attempts to add tiles immediately and skips the
 * consent subscription — the editor authoring surface always shows a working
 * map. (The polyline mounts regardless; the bypass only affects whether the
 * tile-add gate is consulted.)
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
import { clickToFraction, fractionToLatLng, type LatLng } from './geometry';
import {
	addMapControls,
	addWaypointMarkers,
	applyInteractionSettings,
	createLeafletMap,
	fitAndConstrainBounds,
	maybeCreateCursorMarker,
	renderTrackLayers,
} from './mount';
import {
	addTiles,
	createEmptyTileRefs,
	hasUsableUrl,
	removeTiles,
	type TileLayerRecord,
	type TileLayerRefs,
} from './tile-lifecycle';
import { classifyWheel } from './wheel';

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
	/**
	 * Show the Map-side track cursor that mirrors the shared fraction
	 * (issue #118). When `false`, the cursor marker is not created, the
	 * track scrub handlers are not wired, and `onMapCursorChange` is a
	 * no-op for this map — the Elevation block's own pointer / hover
	 * behaviour stays intact, only the Map-side reflection is suppressed.
	 */
	readonly enableTrackPositionCursor: boolean;
	/** Show the waypoint name as the first line of the tooltip when present. */
	readonly tooltipShowName: boolean;
	/** Show the waypoint description as the second line of the tooltip when present. */
	readonly tooltipShowDesc: boolean;
}

/**
 * Shape of the per-map state slice hydrated from PHP via wp_interactivity_state.
 *
 * @since 1.0.0
 */
interface MapState {
	attachmentId: number;
	geojson: GeoJSON.GeoJsonObject;
	/**
	 * Cumulative-distance value (metres) at every simplified vertex, aligned
	 * 1:1 with the LineString's `coordinates`. Source of truth for resolving
	 * a fraction back to a position on the rendered polyline.
	 */
	trackCumDist: number[];
	/** Total track distance in metres, taken from the cached statistics. */
	totalDistance: number;
	waypoints: GeoJSON.GeoJsonObject;
	/** URL to the original .gpx attachment; null when unavailable. */
	gpxFileUrl: string | null;
	/**
	 * Resolved base-tile provider record. The record itself is always present
	 * on the frontend, but its `url` is `null` exactly when the resolved
	 * provider requires an API key (`requiresKey === true` server-side) and
	 * the per-provider entry in `tileApiKeys` is empty — the documented polyline-only
	 * state. The view module checks `hasUsableUrl(tileProvider)` before
	 * calling `addTiles` and skips the base layer otherwise.
	 */
	tileProvider: TileLayerRecord;
	/** Resolved overlay records in editor-configured order. */
	tileOverlays: readonly TileLayerRecord[];
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
	/**
	 * Cursor marker drawn on the polyline. Opacity 0 until first fraction.
	 * `null` when the editor disabled the Map-side cursor via
	 * `settings.enableTrackPositionCursor` (issue #118); callers that read this field
	 * must early-return on null rather than treating it as a mounting bug.
	 */
	cursor: L.CircleMarker | null;
	/** Flat [lat, lng] array extracted from the simplified LineString. */
	coords: Array< [ number, number ] >;
	/** Per-vertex cumulative distance (metres) along the original full track. */
	trackCumDist: number[];
	/** Total track distance in metres. */
	totalDistance: number;
	/**
	 * Aborts document-level listeners (scrub end, hint timer) when a future
	 * block-detach path needs to release them in one call. Consent
	 * transitions do not consult this — they only add or remove tiles via
	 * `tile-lifecycle` helpers above.
	 */
	disposer: AbortController;
	/**
	 * References to the tile layers currently attached to the map. Mutated by
	 * `addTiles` / `removeTiles` (see `./tile-lifecycle`) so consent
	 * transitions can add or detach the third-party tile requests without
	 * touching the polyline, cursor, controls, or waypoint markers above.
	 */
	tiles: TileLayerRefs;
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
 * Wire pointer tracking on the SVG hit-layer `<path>`.
 *
 * Native Pointer Events fire directly on the path element, sidestepping
 * Leaflet's mouse-event emulation. `pointerdown` claims the gesture as a
 * scrub: it disables map dragging, calls `setPointerCapture` so the path
 * keeps receiving `pointermove` even when the finger drifts off it, and
 * writes `state[mapId].fraction` immediately so the cursor jumps under the
 * finger on first touch. `pointermove` updates the fraction continuously —
 * during a scrub by virtue of capture, and on plain mouse hover by virtue of
 * the path receiving the event when the mouse is over the stroke.
 * `pointerup` and `pointercancel` end the scrub and release capture.
 *
 * On the path, `touch-action: none` (CSS) prevents the browser from
 * interpreting a touch on the track as scroll or pan; on the rest of the
 * map, the browser default is preserved so two-finger pan and pinch still
 * pan and pinch the map.
 *
 * Two dismissal paths null the fraction so the cursor disappears:
 * - `map.on('click')` fires on a tap or click outside the hit-layer that
 *   is not part of a drag (Leaflet's click event filters drags out). Same
 *   gesture for mouse and touch.
 * - `pointerleave` on the block fires when a mouse leaves the block area;
 *   on touch it fires automatically when the finger lifts (the touch
 *   pointer ceases), and we deliberately skip it there so the cursor stays
 *   at its last position — touch users have no hover and need the cursor
 *   to persist after the gesture so they can read the elevation profile.
 *
 * @since 1.0.0
 *
 * @param map           - Leaflet map instance.
 * @param hitLayer      - SVG-rendered GeoJSON layer whose first inner layer
 *                      exposes the `<path>` we attach pointer events to.
 * @param blockEl       - Block wrapper element used for the mouse-leave handler.
 * @param coords        - Flat [lat, lng] array shared with the cursor sync.
 * @param trackCumDist  - Per-vertex original-cumulative distances aligned 1:1
 *                      with `coords`; used to project a click onto the track
 *                      and translate the projection back to fraction.
 * @param totalDistance - Total track distance in metres, used as the divisor
 *                      when converting projected distance to fraction.
 * @param mapId         - Interactivity store key for this map.
 * @param onOutsideTap  - Fired at the start of a track scrub and on a map
 *                      click outside the hit-layer. Used to dismiss any
 *                      sticky waypoint tooltip when the user's attention
 *                      moves to the track or to empty map area.
 * @param signal        - AbortSignal that releases listeners on tear-down.
 */
function attachScrubHandlers(
	map: L.Map,
	hitLayer: L.GeoJSON,
	blockEl: HTMLElement,
	coords: Array< [ number, number ] >,
	trackCumDist: number[],
	totalDistance: number,
	mapId: string,
	onOutsideTap: () => void,
	signal: AbortSignal
): void {
	if ( coords.length < 2 ) {
		return;
	}

	// Resolve the underlying SVG <path>. The hit layer is added to the map
	// before this call, so the renderer has materialised the element by now.
	const innerLayer = hitLayer.getLayers()[ 0 ] as L.Polyline | undefined;
	const pathEl = innerLayer?.getElement() as SVGPathElement | null;
	if ( ! pathEl ) {
		return;
	}

	// Stop the synthesised click on the path from bubbling to the map below;
	// otherwise the dismissal handler (`map.on('click')`) would null the
	// fraction we just set during the scrub.
	L.DomEvent.disableClickPropagation( pathEl );

	// Project a pointer event onto the polyline using the geometry helper.
	// Lat/lng come from the map's pixel→geographic projection; the helper
	// then runs the segment-projection in flat-Cartesian space, which is
	// accurate enough at the metre scales involved.
	const fractionForEvent = ( event: PointerEvent ): number => {
		const latlng = map.mouseEventToLatLng( event );
		const click: LatLng = [ latlng.lat, latlng.lng ];
		return clickToFraction( coords, trackCumDist, totalDistance, click )
			.fraction;
	};

	let scrubbing = false;

	pathEl.addEventListener(
		'pointerdown',
		( event: PointerEvent ) => {
			// Ignore secondary pointers during an active scrub so a stray
			// second finger cannot hijack capture mid-gesture.
			if ( scrubbing ) {
				return;
			}

			event.preventDefault();
			onOutsideTap();
			scrubbing = true;
			map.dragging.disable();
			pathEl.setPointerCapture( event.pointerId );
			state[ mapId ].fraction = fractionForEvent( event );
		},
		{ signal }
	);

	pathEl.addEventListener(
		'pointermove',
		( event: PointerEvent ) => {
			// Capture during a scrub keeps this firing even when the pointer
			// is off the path; for plain mouse hover the event also fires
			// when the mouse is over the stroke. Touch has no hover, so the
			// `mouse` branch is a no-op for touch in practice.
			if ( scrubbing || event.pointerType === 'mouse' ) {
				state[ mapId ].fraction = fractionForEvent( event );
			}
		},
		{ signal }
	);

	const endScrub = ( event: PointerEvent ) => {
		if ( ! scrubbing ) {
			return;
		}
		scrubbing = false;
		map.dragging.enable();
		if ( pathEl.hasPointerCapture( event.pointerId ) ) {
			pathEl.releasePointerCapture( event.pointerId );
		}
	};

	pathEl.addEventListener( 'pointerup', endScrub, { signal } );
	pathEl.addEventListener( 'pointercancel', endScrub, { signal } );

	// Tap or click on the map outside the hit-layer dismisses the cursor and
	// any sticky waypoint tooltip. Symmetric for mouse and touch. Leaflet's
	// `click` event only fires when there was no significant drag, so a
	// panning gesture on empty map area does not dismiss. Waypoint markers
	// set `bubblingMouseEvents: false`, so a click on a marker does not
	// reach this handler — the marker's own click handler owns that gesture.
	map.on( 'click', () => {
		state[ mapId ].fraction = null;
		onOutsideTap();
	} );

	// Mouse leaves the block — null the fraction so the cursor disappears.
	// Skip while scrubbing so brief excursions outside the block during a
	// fast scrub do not flicker the cursor off. Skip on touch entirely: a
	// finger lift fires `pointerleave` automatically (the touch pointer
	// ceases to exist), and we want the cursor to stay at its last position
	// so the user can read the elevation profile after pointing.
	blockEl.addEventListener(
		'pointerleave',
		( event: PointerEvent ) => {
			if ( scrubbing || event.pointerType === 'touch' ) {
				return;
			}
			state[ mapId ].fraction = null;
		},
		{ signal }
	);
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
 * `enableDrag` is forwarded to `classifyWheel` so the trackpad two-finger
 * pan modality respects the same toggle that gates mouse and single-touch
 * drag — see issue #66.
 *
 * @since 1.0.0
 *
 * @param map        - Leaflet map instance.
 * @param blockEl    - Block wrapper element receiving wheel events.
 * @param hint       - Pre-translated `{ apple, other }` hint pair.
 * @param enableDrag - Whether drag-to-pan is enabled. Gates the `'pan'` branch.
 * @param signal     - AbortSignal that releases listeners on tear-down.
 */
function attachWheelHandler(
	map: L.Map,
	blockEl: HTMLElement,
	hint: MapState[ 'scrollHint' ],
	enableDrag: boolean,
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
			const action = classifyWheel( event, { enableDrag } );

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
 * conditions. Idempotent: re-entry returns early when `mountedMaps` already
 * has an entry for the block element.
 *
 * Mounts only the local pieces — polyline, hit layer, cursor, waypoint
 * markers, and Leaflet controls. **Tile layers are not added here**: they are
 * added (and removed) by `addTiles` / `removeTiles` in response to consent
 * transitions and tile-config validity. The polyline therefore renders
 * regardless of consent state, while the third-party tile requests gate
 * separately. After mount, the caller (`initMap`) decides whether to bring
 * up tiles immediately based on consent and on whether the resolved
 * provider has a usable URL.
 *
 * Before fitBounds, calls `map.invalidateSize()` once to force Leaflet to
 * re-measure the container — necessary when the element became visible just
 * before mount (e.g. a consent transition from denying to granting) or when
 * a flex/grid parent had not yet assigned the wrapper its definite width.
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

			// Build the Leaflet map. Tile layers are NOT added here —
			// `initMap` calls `addTiles` after mount when consent permits
			// and the resolved provider has a usable URL. Keeping tile
			// lifecycle out of `bootMount` means the polyline et al. always
			// mount regardless of consent state.
			const map = createLeafletMap( blockEl );

			// Render the visible polyline plus the invisible fat hit-layer
			// on a shared SVG renderer; the waypoint markers further down
			// reuse the same renderer so DOM order alone decides stacking.
			const { layer, hitLayer, svgRenderer } = renderTrackLayers(
				map,
				mapState.geojson,
				blockEl
			);

			// Fit the viewport to the track bounds and constrain panning.
			// `invalidateSize` runs first so Leaflet re-measures the
			// container after the IntersectionObserver-driven visibility
			// transition — without that, `fitBounds` would see zero
			// dimensions and compute a NaN center / NaN zoom (#116, #117).
			fitAndConstrainBounds( map, layer );

			// Add the configured control overlays and apply each
			// interaction handler per the hydrated settings.
			const settings = mapState.settings;
			addMapControls( map, settings, mapState.gpxFileUrl );
			applyInteractionSettings( map, settings );

			// AbortController carrying every document-level listener
			// attached below so a future block-detach path can release them
			// in one call. Stored on the MapEntry so the cleanup contract
			// is mechanical when needed. Consent transitions do not abort
			// the disposer — they only add or remove tile layers via the
			// helpers below.
			const disposer = new AbortController();

			// Replace Leaflet's scrollWheelZoom with a wheel handler that
			// distinguishes pinch / Cmd / Ctrl (zoom), trackpad two-finger
			// pan (deltaMode 0, no modifier — only when enableDrag is true),
			// and mouse wheel (deltaMode 1+, no modifier — show a hint and
			// let the page scroll). Forwarding enableDrag here is what makes
			// the trackpad-pan gesture honour the "Drag to pan" toggle.
			attachWheelHandler(
				map,
				blockEl,
				mapState.scrollHint,
				settings.enableDrag,
				disposer.signal
			);

			// Pre-compute the flat [lat, lng] coordinate array for
			// fraction→position resolution in onMapCursorChange. The
			// per-vertex cumulative-distance array and total distance
			// PHP put on state are snapshotted into the MapEntry so the
			// scrub handlers and the cursor watch avoid re-reading state.
			const coords = extractCoords( mapState.geojson );
			const { trackCumDist, totalDistance } = mapState;

			// Create the cursor marker at the track midpoint, initially
			// invisible. Opacity is set to 1 on the first non-null fraction.
			// Gated by the editor's `enableTrackPositionCursor` toggle (issue
			// #118): when disabled, the helper returns `null` and the scrub
			// handlers below are skipped, so the Map ships with no Map-side
			// cursor reflection at all.
			const cursor = maybeCreateCursorMarker(
				settings.enableTrackPositionCursor,
				map,
				coords,
				trackCumDist,
				totalDistance,
				blockEl
			);

			// Add waypoint markers and wire the sticky-tooltip behaviour.
			// The returned `closeSticky` callback is handed to the scrub
			// handlers so a tap on the track or on empty map area dismisses
			// any open sticky tooltip — "tap outside to close" extended to
			// the two outside surfaces this map exposes.
			const closeSticky = addWaypointMarkers(
				map,
				mapState.waypoints,
				settings,
				svgRenderer,
				blockEl
			);

			// Record the instance so subsequent init calls are no-ops and
			// onMapCursorChange can access the cursor, coords, and the
			// distance arrays needed to glide the cursor between vertices.
			// `tiles` starts empty — `addTiles` populates it on first call.
			mountedMaps.set( blockEl, {
				map,
				cursor,
				coords,
				trackCumDist,
				totalDistance,
				disposer,
				tiles: createEmptyTileRefs(),
			} );

			// Bring up tile layers when the configuration permits. The editor
			// bypass mounts unconditionally; the frontend consults the
			// consent contract directly. Either way, `ensureTilesForState`
			// already short-circuits when the resolved provider has a `null`
			// URL (the documented polyline-only state for paid providers
			// without a key), so a missing-key block ships polyline-only
			// regardless of consent.
			if ( mapState.bypassConsent || consentPermitsTiles() ) {
				ensureTilesForState( blockEl, mapState );
			}

			// Wire pointer tracking on the invisible fat overlay so the hit
			// zone for hover and press is wide enough to feel smooth. Hover
			// over the track updates fraction; press-and-drag on the track
			// scrubs the cursor without panning the map. Pointerup anywhere
			// ends the scrub. The `closeSticky` callback wired in here lets
			// a track tap or empty-area click dismiss any sticky waypoint
			// tooltip — the convention "tap outside to close" extended to
			// the two outside surfaces this map exposes.
			//
			// Skipped entirely when `enableTrackPositionCursor === false` (issue
			// #118): a Map without a paired Elevation block has no use for
			// Map-driven scrubbing, and the editor opted out of the cursor
			// reflection altogether.
			if ( cursor !== null ) {
				attachScrubHandlers(
					map,
					hitLayer,
					blockEl,
					coords,
					trackCumDist,
					totalDistance,
					mapId,
					closeSticky,
					disposer.signal
				);
			}
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
 * Resolve the consent contract once and return whether tiles may proceed.
 *
 * Centralised so both the IntersectionObserver callback inside `bootMount`
 * (which decides whether to bring tiles up at first paint) and `initMap`
 * (which uses the same answer to decide whether to subscribe and how to act
 * on the initial state) see the same truth. The default-allow rule is the
 * stub's responsibility: `mayProceed` returns `true` for granting and
 * absent and `false` only for denying. When the inline stub is missing
 * entirely (an optimisation plugin stripped it), the function returns
 * `true` and the caller is expected to log the misconfiguration once.
 *
 * @since 1.0.0
 *
 * @return `true` when tiles may load, `false` only on a literal denying
 *         signal from the contract.
 */
function consentPermitsTiles(): boolean {
	const consent = window.kntnt_gpx_blocks;
	if ( ! consent ) {
		return true;
	}
	return consent.mayProceed( CONSENT_CATEGORY );
}

/**
 * Bring up tile layers for a freshly-mounted map when the configuration permits.
 *
 * Called immediately after `bootMount` populates `mountedMaps` and from the
 * granting branch of the consent observer. The function inspects the
 * resolved provider record on `mapState.tileProvider` and only calls
 * `addTiles` when the URL is usable — a `null` URL signals the documented
 * polyline-only state (paid provider with empty `tileApiKeys` entry) and ships
 * forever without tiles. Idempotent via `addTiles`'s own guard.
 *
 * @since 1.0.0
 *
 * @param blockEl  - Block wrapper element keyed in `mountedMaps`.
 * @param mapState - Hydrated state slice carrying the resolved tile records.
 */
function ensureTilesForState( blockEl: Element, mapState: MapState ): void {
	const entry = mountedMaps.get( blockEl );
	if ( ! entry ) {
		return;
	}

	// `null`-URL records short-circuit; `addTiles` itself also gates on
	// `hasUsableUrl`, but checking here first keeps the call site self-
	// documenting at the level of "did this block ever get tiles?".
	const baseRecord = hasUsableUrl( mapState.tileProvider )
		? mapState.tileProvider
		: null;
	addTiles( entry.map, baseRecord, mapState.tileOverlays, entry.tiles );
}

/**
 * Detach the tile layers from a mounted map without touching the rest of it.
 *
 * Called from the denying branch of the consent observer. Removes the base
 * tile layer and any overlay tile layers; leaves the map instance, polyline,
 * cursor, controls, and waypoint markers intact. A subsequent granting
 * signal restores tiles via `ensureTilesForState`. Idempotent: when no tiles
 * are attached the call is a no-op.
 *
 * @since 1.0.0
 *
 * @param blockEl - Block wrapper element keyed in `mountedMaps`.
 */
function removeTilesForBlock( blockEl: Element ): void {
	const entry = mountedMaps.get( blockEl );
	if ( ! entry ) {
		return;
	}
	removeTiles( entry.map, entry.tiles );
}

// ─── Store ────────────────────────────────────────────────────────────────────

const { state } = store< { state: PluginState } >( 'kntnt-gpx-blocks', {
	state: {} as PluginState,
	callbacks: {
		/**
		 * Initialise the block.
		 *
		 * Mounts the local pieces (polyline, cursor, controls, waypoint
		 * markers) unconditionally — those are SVG/canvas drawn from cached
		 * GeoJSON and reach no third party. Tile layers, by contrast, are
		 * gated by the JS-only consent contract (`window.kntnt_gpx_blocks`)
		 * because tile requests transmit the visitor's IP to OpenStreetMap
		 * (or the configured paid provider). The decision tree is:
		 *
		 *  1. Always call `bootMount`. It schedules an IntersectionObserver;
		 *     when the observer fires, the rest of the map mounts and the
		 *     observer's own callback decides whether to bring tiles up,
		 *     based on `bypassConsent` (editor preview) or
		 *     `consentPermitsTiles()` (frontend).
		 *  2. If `bypassConsent` is true (editor preview), skip the consent
		 *     subscription — the editor authoring surface always shows tiles
		 *     when the resolved provider has a usable URL.
		 *  3. Otherwise subscribe to `onConsentChanged`. On `granted === true`,
		 *     call `ensureTilesForState` (idempotent — `addTiles` no-ops
		 *     when tiles are already up). On `granted === false`, call
		 *     `removeTilesForBlock` (idempotent the same way). An absent
		 *     transition (`null`) is a no-op: there is no defined
		 *     revoke-to-absent transition in the contract, and falling back
		 *     to default-allow on absent would fight any pending denial.
		 *
		 * Crucially, neither consent branch tears down the rest of the map —
		 * the polyline, cursor, controls, and waypoints all survive a denying
		 * signal. See `docs/consent.md` for the polyline-always render
		 * contract.
		 *
		 * When the inline stub is unexpectedly missing — e.g. an optimisation
		 * plugin stripped it — the default-allow rule applies and tiles
		 * mount normally. A console warning surfaces the misconfiguration.
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

			// Mount the local map pieces unconditionally — polyline, cursor,
			// controls, and waypoint markers are not subject to the consent
			// contract. The IntersectionObserver inside `bootMount` defers
			// the heavy work, and the observer callback consults
			// `consentPermitsTiles()` itself before adding tiles, so the
			// synchronous-page-load case where consent is already permitting
			// brings tiles up at first paint. Idempotent via the
			// `mountedMaps` guard.
			bootMount( ref, mapId, mapState );

			// Editor short-circuit: PHP flagged this as an editor preview, so
			// the consent contract does not apply. The polyline mounts above;
			// tiles mount via `ensureTilesForState` already called inside the
			// observer. Skip the consent subscription entirely.
			if ( mapState.bypassConsent ) {
				return;
			}

			// Resolve the consent contract from the inline stub. The stub is
			// enqueued unconditionally in <head>; if it is missing here, an
			// optimisation plugin has stripped it. Default-allow keeps tile
			// loading functional in that case, but the misconfiguration is
			// worth surfacing once.
			const consent = window.kntnt_gpx_blocks;
			if ( ! consent ) {
				// eslint-disable-next-line no-console
				console.warn(
					'[kntnt-gpx-blocks] window.kntnt_gpx_blocks is missing; mounting under default-allow. See docs/consent.md.'
				);
				return;
			}

			// Subscribe to subsequent transitions. Granting adds tiles
			// (idempotent), denying removes them (idempotent), absent (null)
			// is a no-op. The polyline, cursor, controls, and waypoints
			// survive both transitions.
			consent.onConsentChanged( ( category, granted ) => {
				if ( category !== CONSENT_CATEGORY ) {
					return;
				}
				if ( granted === true ) {
					ensureTilesForState( ref, mapState );
					return;
				}
				if ( granted === false ) {
					removeTilesForBlock( ref );
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
		 * The fraction is read at the very top of the function, before any
		 * guard, so the Interactivity API's signal-tracking establishes the
		 * subscription on the very first watch run. `mountedMaps` is populated
		 * inside an `IntersectionObserver` and is therefore typically empty
		 * the first time this watch fires — returning before reading state
		 * would skip the subscription and the watch would never re-fire when
		 * the GPX Elevation block writes a new fraction.
		 *
		 * @since 1.0.0
		 */
		onMapCursorChange() {
			const { mapId } = getContext< MapContext >();
			const fraction = state[ mapId ]?.fraction;

			const { ref } = getElement();
			if ( ! ref ) {
				return;
			}
			const entry = mountedMaps.get( ref );
			if ( ! entry ) {
				return;
			}

			// No-op when the editor disabled the Map-side cursor (issue
			// #118). The Elevation block keeps writing the shared fraction;
			// this map simply does not reflect it.
			if ( entry.cursor === null ) {
				return;
			}

			// Null fraction means "pointer left" — hide the marker.
			if ( fraction === null || fraction === undefined ) {
				entry.cursor.setStyle( { opacity: 0, fillOpacity: 0 } );
				return;
			}

			// Resolve fraction to a lat/lng on the rendered polyline by
			// binary-searching the per-vertex cumulative-distance array PHP
			// emitted, then linearly interpolating between adjacent vertices.
			// The cursor therefore glides smoothly along the track instead of
			// jumping from one simplified vertex to the next.
			const latLng = fractionToLatLng(
				entry.coords as ReadonlyArray< LatLng >,
				entry.trackCumDist,
				entry.totalDistance,
				fraction
			);
			if ( ! latLng ) {
				return;
			}
			entry.cursor.setLatLng( latLng );
			entry.cursor.setStyle( { opacity: 1, fillOpacity: 1 } );
		},
	},
} );
