/**
 * Mount-time helpers for the GPX Map block's frontend module.
 *
 * `bootMount` in `view.ts` orchestrates a Leaflet mount that spans seven
 * independent phases: build the map instance, render the polyline and the
 * invisible fat hit-layer, fit the viewport to the track bounds and
 * constrain panning, add the optional control overlays, apply the
 * interaction-handler toggles, create the cursor marker, and add the
 * waypoint markers. Each helper here owns one phase. The phases stay
 * imperative — Leaflet's lifecycle is imperative — but live behind named
 * functions so `bootMount` reads as a linear sequence of named steps.
 *
 * @since 1.0.0
 */

import L from 'leaflet';
import { applyMaxBoundsIfSafe, paddedBoundsFromBox } from './bounds';
import { fractionToLatLng, type LatLng } from './geometry';

/**
 * Map-level settings hydrated from PHP block attributes.
 *
 * Mirrors the `MapSettings` interface in `view.ts`. Kept narrow — each
 * helper imports the subset it needs.
 *
 * @since 1.0.0
 */
export interface MapControlSettings {
	readonly showZoomButtons: boolean;
	readonly showScale: boolean;
	readonly showFullscreen: boolean;
	readonly showDownload: boolean;
	readonly enableDrag: boolean;
	readonly enablePinchZoom: boolean;
	readonly enableDoubleClickZoom: boolean;
	readonly enableKeyboard: boolean;
	readonly tooltipShowName: boolean;
	readonly tooltipShowDesc: boolean;
}

/**
 * Track-layer pair returned by `renderTrackLayers`.
 *
 * The visible `layer` carries the actual stroke; the invisible `hitLayer`
 * widens the pointer hit zone without affecting visual appearance.
 *
 * @since 1.0.0
 */
export interface TrackLayers {
	/** Visible polyline rendered through the map's canvas renderer. */
	readonly layer: L.GeoJSON;
	/** Invisible fat overlay sharing the visible polyline's geometry. */
	readonly hitLayer: L.GeoJSON;
	/**
	 * Shared SVG renderer used by the hit layer and the waypoint markers.
	 * Returning it from this helper lets `addWaypointMarkers` reuse the
	 * same `<svg>` so DOM order alone decides stacking and pointer-event
	 * priority — later additions sit on top.
	 */
	readonly svgRenderer: L.Renderer;
}

/**
 * Read a CSS custom property from an element's computed style.
 *
 * Returns the fallback when the property is not set or resolves to an
 * empty string. CSS variables cannot be applied directly to canvas-
 * rendered Leaflet shapes (polyline, CircleMarker), so we read them once
 * at mount time and pass the resolved value through Leaflet's path
 * options.
 *
 * @since 1.0.0
 *
 * @param element  - The element whose computed style is queried.
 * @param property - CSS custom property name.
 * @param fallback - Value returned when the property resolves to empty.
 * @return Resolved value or fallback.
 */
export function getCssVar(
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
 * Build the `L.map` instance for the GPX Map block.
 *
 * Picks the canvas renderer for the polyline (the only path the visible
 * polyline goes through) and suppresses Leaflet's own scrollWheelZoom and
 * boxZoom — both are replaced by the custom wheel handler in `view.ts`.
 * `maxBoundsViscosity: 1.0` makes the eventual `setMaxBounds` a rigid
 * constraint; the option has no effect until `setMaxBounds` is actually
 * called, so it is safe to include unconditionally here.
 *
 * @since 1.0.0
 *
 * @param blockEl - The block wrapper element used as Leaflet's container.
 * @return Configured Leaflet map instance.
 */
export function createLeafletMap( blockEl: HTMLElement ): L.Map {
	return L.map( blockEl, {
		renderer: L.canvas(),
		zoomControl: false,
		attributionControl: true,
		scrollWheelZoom: false,
		boxZoom: false,
		maxBoundsViscosity: 1.0,
	} );
}

/**
 * Render the visible polyline and the invisible fat hit-layer on the map.
 *
 * The visible `layer` carries the stroke colour resolved from the block's
 * CSS custom property. The `hitLayer` stacks an `opacity: 0`,
 * `weight: 30` overlay on the same geometry so the pointer hit zone is
 * wide enough to feel smooth without changing the visible appearance. A
 * single shared `L.svg()` renderer hosts the hit layer and every
 * subsequent waypoint marker so they all live in one `<svg>`, where DOM
 * order alone decides stacking and pointer-event priority.
 *
 * @since 1.0.0
 *
 * @param map     - Leaflet map instance.
 * @param geojson - The hydrated track GeoJSON.
 * @param blockEl - Block wrapper element, source of the CSS custom
 *                properties for the track colour.
 * @return Pair of layers plus the shared SVG renderer.
 */
export function renderTrackLayers(
	map: L.Map,
	geojson: GeoJSON.GeoJsonObject,
	blockEl: HTMLElement
): TrackLayers {
	// Read the track colour CSS variable once at mount time. Leaflet
	// canvas-rendered shapes receive their colour through JS options —
	// they cannot be styled via CSS — so we resolve the computed value
	// here and pass it through the style callback.
	const trackColor = getCssVar(
		blockEl,
		'--kntnt-gpx-blocks-track-color',
		'#0073aa'
	);

	const layer = L.geoJSON( geojson, {
		style: () => ( {
			color: trackColor,
			weight: 4,
			opacity: 1,
		} ),
	} );
	layer.addTo( map );

	// Shared SVG renderer used by every interactive overlay that needs a
	// real DOM element: the hit-layer below and the waypoint markers
	// further down. Sharing one renderer keeps everything in a single
	// `<svg>` so DOM order alone decides stacking and pointer-event
	// priority — later additions sit on top. Canvas would force routing
	// through Leaflet's mouse-event emulation (slow on touch — see the
	// hit-layer commit) and would split the overlays across two stacked
	// elements, where the SVG would then steal pointer events from
	// canvas-rendered markers below.
	const svgRenderer = L.svg();

	// Invisible fat overlay sharing the visible polyline's geometry.
	// Stacking a transparent weight: 30 layer above the visible 4 px
	// stroke widens the pointer hit zone to ~15 px on each side without
	// changing the visible appearance.
	const hitLayer = L.geoJSON( geojson, {
		style: () => ( {
			weight: 30,
			opacity: 0,
			fillOpacity: 0,
			className: 'kntnt-gpx-blocks-track-hit',
		} ),
		interactive: true,
		renderer: svgRenderer,
	} );
	hitLayer.addTo( map );

	return { layer, hitLayer, svgRenderer };
}

/**
 * Fit the viewport to the track bounds and apply the panning constraint.
 *
 * Calls `invalidateSize` first so Leaflet re-measures the container
 * after the IntersectionObserver-driven visibility transition or any
 * parent flex/grid layout that hadn't assigned the wrapper its definite
 * width yet — without that, `fitBounds` would see zero dimensions and
 * compute a NaN center / NaN zoom (issues #116 and #117). The
 * `applyMaxBoundsIfSafe` guard skips the constraint in the rare bad-state
 * branch where the wrapper still has zero width past this point,
 * preserving the polyline at the cost of unconstrained panning.
 *
 * @since 1.0.0
 *
 * @param map   - Leaflet map instance.
 * @param layer - Visible polyline layer; its bounds drive the fit.
 */
export function fitAndConstrainBounds( map: L.Map, layer: L.GeoJSON ): void {
	// Force Leaflet to re-measure the container before fitting the view.
	// The block became visible just before mount (a consent transition
	// from denying to granting, an editor SSR re-render mid-iframe-layout,
	// or any parent layout — flex/grid — that hadn't assigned the wrapper
	// its definite width yet). Running `invalidateSize` first makes
	// `fitBounds` see real dimensions, which is what prevents the
	// `(NaN, NaN)` center that crashed `setMaxBounds` in issue #116.
	map.invalidateSize( false );

	const bounds = layer.getBounds();
	if ( ! bounds.isValid() ) {
		return;
	}

	map.fitBounds( bounds, { padding: [ 16, 16 ] } );

	// Constrain panning so at least part of the track stays in view
	// (issue #110). The padded bbox plus the rigid `maxBoundsViscosity:
	// 1.0` set on the map options keep the viewport centre inside a
	// comfortable margin around the track without stopping the user from
	// zooming out to the configured minimum zoom. `paddedBoundsFromBox`
	// handles degenerate single-point tracks by inflating the bbox to a
	// minimum span before padding; structurally invalid input returns
	// `null` and is skipped.
	//
	// The post-`fitBounds` state is also guarded: if the container still
	// has zero width at this point (some flex or grid parents hold off
	// width assignment past the IntersectionObserver callback), Leaflet's
	// fitBounds math goes to `scale = -Infinity` and produces a NaN
	// center or a NaN zoom (and sometimes both — issue #116 first
	// surfaced the center half, issue #117 the zoom half). Calling
	// `setMaxBounds` while either is non-finite trips Leaflet's internal
	// `_panInsideMaxBounds`, which unprojects the bad value and throws
	// "Invalid LatLng object: (NaN, NaN)". This guard is the backstop —
	// `Dimensions_Defaults` already prevents the zero-size state from
	// arising during a normal page render — and should never fire on a
	// healthy page. Skipping the constraint in the rare bad-state branch
	// keeps the polyline visible at the cost of unconstrained panning,
	// which is the pre-#110 behaviour.
	const sw = bounds.getSouthWest();
	const ne = bounds.getNorthEast();
	const padded = paddedBoundsFromBox( {
		southWest: [ sw.lat, sw.lng ],
		northEast: [ ne.lat, ne.lng ],
	} );
	if ( padded ) {
		applyMaxBoundsIfSafe( map, [ padded.southWest, padded.northEast ] );
	}
}

/**
 * Add the configured control overlays to the map.
 *
 * Each control is opt-in via the hydrated settings — the user toggled
 * them in the Inspector. The Download control is also gated on the
 * presence of `gpxFileUrl`: when no file URL was emitted (e.g. the
 * attachment metadata was stale), the control is omitted rather than
 * rendered with a broken anchor.
 *
 * @since 1.0.0
 *
 * @param map        - Leaflet map instance.
 * @param settings   - Hydrated control / interaction settings.
 * @param gpxFileUrl - URL of the original `.gpx` attachment, or `null`.
 */
export function addMapControls(
	map: L.Map,
	settings: MapControlSettings,
	gpxFileUrl: string | null
): void {
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
		// leaflet.fullscreen registers L.control.fullscreen as a side
		// effect of the import at the top of `view.ts`.
		(
			L.control as Record<
				string,
				( opts: Record< string, unknown > ) => L.Control
			>
		 )
			.fullscreen( { position: 'topleft' } )
			.addTo( map );
	}
	if ( settings.showDownload && gpxFileUrl ) {
		// Derive a download filename from the URL's last path segment.
		const filename = gpxFileUrl.split( '/' ).pop() ?? 'track.gpx';

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
				anchor.href = gpxFileUrl;
				anchor.download = filename;
				anchor.title = 'Download GPX';
				anchor.setAttribute( 'aria-label', 'Download GPX' );
				anchor.innerHTML =
					'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M8 2v8m-4-4l4 4l4-4M3 13h10"/></svg>';
				L.DomEvent.disableClickPropagation( container );
				return container;
			},
		} );
		new DownloadControl( { position: 'topleft' } ).addTo( map );
	}
}

/**
 * Apply each interaction handler per the hydrated settings.
 *
 * Using explicit enable/disable calls rather than `L.map` init options
 * keeps the settings as the single source of truth — the Inspector
 * toggles map directly to handler state without an intermediate option
 * object.
 *
 * @since 1.0.0
 *
 * @param map      - Leaflet map instance.
 * @param settings - Hydrated control / interaction settings.
 */
export function applyInteractionSettings(
	map: L.Map,
	settings: MapControlSettings
): void {
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
}

/**
 * Create the cursor marker at the track midpoint, initially invisible.
 *
 * The cursor is a non-interactive circleMarker positioned at the track
 * midpoint so it lands somewhere sensible before the first real fraction
 * arrives. Opacity starts at `0` and is bumped to `1` on the first
 * non-null fraction by `onMapCursorChange`.
 *
 * @since 1.0.0
 *
 * @param map           - Leaflet map instance.
 * @param coords        - Flat [lat, lng] coordinate array for the track.
 * @param trackCumDist  - Per-vertex original-cumulative distances aligned
 *                      1:1 with `coords`.
 * @param totalDistance - Total track distance in metres.
 * @param blockEl       - Block wrapper element, source of the cursor's
 *                      CSS custom property.
 * @return The cursor marker, already added to the map.
 */
export function createCursorMarker(
	map: L.Map,
	coords: Array< [ number, number ] >,
	trackCumDist: number[],
	totalDistance: number,
	blockEl: HTMLElement
): L.CircleMarker {
	const trackCursorColor = getCssVar(
		blockEl,
		'--kntnt-gpx-blocks-track-cursor-color',
		'#d63638'
	);

	const midLatLng = fractionToLatLng(
		coords as ReadonlyArray< LatLng >,
		trackCumDist,
		totalDistance,
		0.5
	) ?? [ 0, 0 ];
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
	return cursor;
}

/**
 * Add a circleMarker for each waypoint and wire the sticky-tooltip
 * behaviour.
 *
 * Markers share the SVG renderer passed in so they sit later in the same
 * `<svg>` as the 30 px hit-zone path and therefore receive pointer events
 * first. `bubblingMouseEvents: false` keeps a click on a marker from also
 * firing `map.on('click')`, which would otherwise dismiss the tooltip we
 * just opened.
 *
 * Sticky-tooltip state is shared across all waypoint markers in this
 * map: at most one marker is sticky at a time. The returned
 * `closeSticky` callback dismisses the current sticky and is handed to
 * `attachScrubHandlers` so a tap on the track or empty map area dismisses
 * it the same way.
 *
 * @since 1.0.0
 *
 * @param map         - Leaflet map instance.
 * @param waypoints   - GeoJSON FeatureCollection of waypoint Points.
 * @param settings    - Hydrated settings; gates the per-line tooltip toggles.
 * @param svgRenderer - Shared SVG renderer from `renderTrackLayers`.
 * @param blockEl     - Block wrapper element, source of the waypoint's
 *                    CSS custom property.
 * @return The `closeSticky` callback dismissing any open sticky tooltip.
 */
export function addWaypointMarkers(
	map: L.Map,
	waypoints: GeoJSON.GeoJsonObject,
	settings: Pick< MapControlSettings, 'tooltipShowName' | 'tooltipShowDesc' >,
	svgRenderer: L.Renderer,
	blockEl: HTMLElement
): () => void {
	// Sticky-tooltip state shared across all waypoint markers in this
	// map. `closeSticky` nulls the reference *before* calling
	// `closeTooltip`, so the `tooltipclose` handler below sees no sticky
	// and skips its auto-reopen path. The same helper is also handed to
	// `attachScrubHandlers` so a tap on the track or on empty map area
	// dismisses any open sticky.
	let stickyMarker: L.CircleMarker | null = null;
	const closeSticky = (): void => {
		if ( stickyMarker ) {
			const previous = stickyMarker;
			stickyMarker = null;
			previous.closeTooltip();
		}
	};

	if ( waypoints?.type !== 'FeatureCollection' ) {
		return closeSticky;
	}

	const waypointColor = getCssVar(
		blockEl,
		'--kntnt-gpx-blocks-waypoint-color',
		'#d63638'
	);

	const wfc = waypoints as GeoJSON.FeatureCollection;
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
			bubblingMouseEvents: false,
			renderer: svgRenderer,
		} );

		// Build the tooltip body from name and optional desc, gated by
		// the per-line toggles. Each surviving line lives in its own
		// `<div>` so the stylesheet can address it via the
		// `kntnt-gpx-blocks-tooltip-{name,desc}` class. The `name` and
		// `desc` strings come from GPX content and are inserted via
		// `textContent`, so no markup in the source ever reaches the DOM
		// as HTML.
		const props = feature.properties ?? {};
		const rawName = typeof props.name === 'string' ? props.name : '';
		const rawDesc = typeof props.desc === 'string' ? props.desc : '';
		const name = settings.tooltipShowName ? rawName : '';
		const desc = settings.tooltipShowDesc ? rawDesc : '';

		if ( name || desc ) {
			const tooltipEl = document.createElement( 'div' );
			if ( name ) {
				const nameEl = document.createElement( 'div' );
				nameEl.className = 'kntnt-gpx-blocks-tooltip-name';
				nameEl.textContent = name;
				tooltipEl.appendChild( nameEl );
			}
			if ( desc ) {
				const descEl = document.createElement( 'div' );
				descEl.className = 'kntnt-gpx-blocks-tooltip-desc';
				descEl.textContent = desc;
				tooltipEl.appendChild( descEl );
			}

			// Bind with `permanent: false` so Leaflet's built-in hover
			// handlers drive the transient open-on-mouseover /
			// close-on-mouseout behaviour. Sticky mode is layered on top
			// by intercepting `tooltipclose` and re-opening when this
			// marker is the sticky one.
			marker.bindTooltip( tooltipEl, {
				direction: 'top',
				permanent: false,
				sticky: false,
				opacity: 1,
			} );

			// Click toggles sticky on this marker, swapping out any
			// sticky on a different marker. The transient hover tooltip
			// is already open at this point (a click implies the pointer
			// is over the marker), so `openTooltip()` is idempotent on
			// the open case and is only doing real work after a swap.
			marker.on( 'click', () => {
				if ( stickyMarker === marker ) {
					closeSticky();
					return;
				}

				closeSticky();
				stickyMarker = marker;
				marker.openTooltip();
			} );

			// Leaflet auto-closes the tooltip on `mouseout`. When this
			// marker is the sticky one, re-open in a microtask so
			// Leaflet finishes its close cleanup first and the recursive
			// close→open→close chain breaks cleanly. The reference check
			// inside the microtask guards against the user dismissing
			// before the microtask runs.
			marker.on( 'tooltipclose', () => {
				if ( stickyMarker !== marker ) {
					return;
				}
				queueMicrotask( () => {
					if ( stickyMarker === marker ) {
						marker.openTooltip();
					}
				} );
			} );
		}

		marker.addTo( map );
	}

	return closeSticky;
}
