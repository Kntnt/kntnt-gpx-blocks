/**
 * In-editor cursor bridge between the Map and Elevation editor previews.
 *
 * Both blocks share a `fraction ∈ [0, 1]` value on the frontend via the
 * WordPress Interactivity API store, keyed by `mapId`. The Interactivity
 * runtime does not bootstrap inside the block editor iframe, so the two
 * editor previews cannot use the same channel — but a parallel
 * editor-only bus, scoped to the iframe's own `document`, mirrors the
 * exact same contract: publish a fraction keyed by `mapId`, subscribe
 * to fractions keyed by `mapId`.
 *
 * The bus is a `CustomEvent` dispatched on `document` with an
 * implementation-private event name. Publishers do not import the event
 * name; subscribers do not either. Both go through the two exports
 * below. The bus is therefore opaque, replaceable, and only visible
 * inside this module.
 *
 * Lifetime: the bus is stateless. Subscribers receive only events that
 * fire *after* they subscribe. The published fraction is never
 * memoised — a subscriber that mounts after a publish will see no
 * value until the next publish. This matches the frontend Interactivity
 * watch contract (`onMapCursorChange` only fires on changes) and keeps
 * the editor preview free of replay-state plumbing.
 *
 * Scope: a single editor iframe owns one `document`, so the bus is
 * naturally scoped to that iframe. Multi-iframe editors (split-screen,
 * preview-pane) get one bus per iframe, with no cross-talk — which is
 * the correct behaviour because each iframe renders an independent
 * block tree.
 *
 * @since 1.0.0
 */

/**
 * Event name used by the bus. Implementation detail — callers do not
 * import this constant. The `kntnt-gpx-blocks` prefix matches the
 * project's CSS / hook naming convention (see
 * `docs/coding-standards.md`).
 *
 * @since 1.0.0
 */
const EDITOR_CURSOR_EVENT = 'kntnt-gpx-blocks:editor-cursor';

/**
 * Payload shape carried on the `detail` field of the dispatched
 * `CustomEvent`. Subscribers filter on `mapId` and consume `fraction`.
 *
 * `fraction` is `null` exactly when the publisher signals "pointer
 * left" — the mirror of the frontend `state[mapId].fraction = null`
 * convention. Subscribers must treat `null` as "hide the cursor".
 *
 * @since 1.0.0
 */
interface EditorCursorDetail {
	readonly mapId: string;
	readonly fraction: number | null;
}

/**
 * Publish a fraction for the given `mapId`.
 *
 * The call is silently a no-op when the iframe has no `document`
 * (server-side rendering, very early hydration). Subscribers receive
 * the event synchronously; React effects scheduled by handlers therefore
 * fire on the same task, the same as the frontend Interactivity
 * `data-wp-watch` path.
 *
 * An empty `mapId` is a no-op as well — the auto-pick sentinel `"auto"`
 * and the post-auto-pick concrete id both reach this function, and we
 * do not want pre-auto-pick frames to publish empty-id events that no
 * subscriber would match anyway.
 *
 * @since 1.0.0
 *
 * @param mapId    The `mapId` the publishing block is bound to (Map
 *                 block's `mapId` attribute, Elevation block's resolved
 *                 mapId). Must be the same value on both sides for the
 *                 sync to wire up.
 * @param fraction Fraction in `[0, 1]`, or `null` to dismiss.
 */
export function publishEditorCursor(
	mapId: string,
	fraction: number | null
): void {
	if ( typeof document === 'undefined' ) {
		return;
	}
	if ( mapId === '' ) {
		return;
	}
	const detail: EditorCursorDetail = { mapId, fraction };
	document.dispatchEvent(
		new CustomEvent< EditorCursorDetail >( EDITOR_CURSOR_EVENT, { detail } )
	);
}

/**
 * Subscribe to fractions published for the given `mapId`.
 *
 * The returned function unsubscribes. The handler is invoked once per
 * matching publish, in dispatch order. Events for other `mapId`s are
 * filtered out at the bus level so the handler never sees them.
 *
 * Subscribing with an empty `mapId` is a no-op that returns a no-op
 * unsubscribe. This mirrors `publishEditorCursor`'s empty-id handling
 * and lets the Map preview pass `attributes.mapId` straight through
 * without an extra branch in the caller.
 *
 * @since 1.0.0
 *
 * @param mapId   The `mapId` to listen on.
 * @param handler Callback invoked with the published fraction.
 * @return Unsubscribe function.
 */
export function subscribeEditorCursor(
	mapId: string,
	handler: ( fraction: number | null ) => void
): () => void {
	if ( typeof document === 'undefined' || mapId === '' ) {
		return () => {};
	}

	const listener = ( event: Event ): void => {
		const detail = ( event as CustomEvent< EditorCursorDetail > ).detail;
		if ( ! detail || detail.mapId !== mapId ) {
			return;
		}
		handler( detail.fraction );
	};

	document.addEventListener( EDITOR_CURSOR_EVENT, listener );
	return () => document.removeEventListener( EDITOR_CURSOR_EVENT, listener );
}
