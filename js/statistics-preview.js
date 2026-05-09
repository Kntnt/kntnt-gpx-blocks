/**
 * Editor-only preview for GPX Statistics bound paragraphs.
 *
 * Each paragraph inside the GPX Statistics variation is bound to the
 * `kntnt-gpx-blocks/statistics` Block Bindings source. By default the editor
 * shows the source's `label` ("GPX statistics") whenever the bound value is
 * empty in the editor — uninformative, and the same string for every bound
 * paragraph. This script wraps `core/paragraph`'s edit component, fetches
 * the real resolved values from the editor-only REST endpoint
 * `kntnt-gpx-blocks/v1/statistics-preview`, and renders the formatted value
 * (or an actionable fallback hint) in the same purple Gutenberg uses for
 * synced/bound attributes — `var(--wp-block-synced-color)`.
 *
 * Resolution semantics mirror the front-end:
 *   - The same Resolve_Map_Id + Attachment_Cache + Value_Formatter chain runs
 *     server-side; this script just calls the REST wrapper.
 *   - On success: render the formatted value wrapped in a purple span.
 *   - On failure (no map, multiple maps, missing cache, deleted attachment):
 *     render a short purple italic hint so the builder gets actionable feedback.
 *   - Null statistic (track has no elevation): render a purple em-dash.
 *
 * The script is plain ES2022 reading `window.wp.*` directly — no
 * `@wordpress/scripts` build step needed.
 *
 * @since 1.0.0
 */

( function () {

	'use strict';

	const { addFilter } = window.wp.hooks;
	const { createElement, useState, useEffect, useMemo } = window.wp.element;
	const { createHigherOrderComponent } = window.wp.compose;
	const { useSelect } = window.wp.data;
	const { __ } = window.wp.i18n;
	const apiFetch = window.wp.apiFetch;

	const SOURCE_NAME = 'kntnt-gpx-blocks/statistics';
	const REST_PATH = '/kntnt-gpx-blocks/v1/statistics-preview';

	// Module-level cache keyed by `${postId}|${mapId}` so multiple bound
	// paragraphs in the same variation share one HTTP round-trip. Values are
	// promises so concurrent first-renders coalesce on the same in-flight
	// request rather than spawning one per paragraph.
	const requestCache = new Map();

	/**
	 * Builds the request-cache key for a (postId, mapId) pair.
	 *
	 * @param {number} postId Host post ID.
	 * @param {string} mapId  Map ID, normalised to 'auto' when empty.
	 * @return {string} Cache key.
	 */
	function cacheKey( postId, mapId ) {
		return postId + '|' + mapId;
	}

	/**
	 * Fetches the formatted statistics for a (postId, mapId) pair.
	 *
	 * Returns a Promise that resolves with `{ values }` on success or
	 * `{ error: { code, message } }` on failure. Errors are not thrown —
	 * the caller renders different UI for each shape.
	 *
	 * @param {number} postId Host post ID.
	 * @param {string} mapId  Map ID, or 'auto' to resolve the only Map on the page.
	 * @return {Promise<{values?: Record<string, string|null>, error?: {code: string, message: string}}>}
	 */
	function fetchStatistics( postId, mapId ) {

		// Coalesce concurrent fetches for the same (postId, mapId) onto one
		// in-flight promise; later calls hit the cached resolved value.
		const key = cacheKey( postId, mapId );
		if ( requestCache.has( key ) ) {
			return requestCache.get( key );
		}

		const params = new URLSearchParams( {
			postId: String( postId ),
			mapId,
		} );

		const promise = apiFetch( { path: REST_PATH + '?' + params.toString() } )
			.then( ( response ) => ( { values: response && response.values ? response.values : {} } ) )
			.catch( ( error ) => {
				const code = error && typeof error.code === 'string' ? error.code : 'unknown-error';
				const message = error && typeof error.message === 'string'
					? error.message
					: __( 'GPX statistics could not be loaded.', 'kntnt-gpx-blocks' );
				return { error: { code, message } };
			} );

		requestCache.set( key, promise );
		return promise;

	}

	/**
	 * Invalidates a (postId, mapId) entry in the request cache.
	 *
	 * Called when the editor's block tree changes in a way that could affect
	 * resolution (e.g. a Map block was added, removed, or its attachmentId
	 * was changed). Subsequent fetches re-hit the REST endpoint.
	 *
	 * @param {number} postId Host post ID.
	 * @param {string} mapId  Map ID.
	 */
	function invalidateCache( postId, mapId ) {
		requestCache.delete( cacheKey( postId, mapId ) );
	}

	/**
	 * React hook that returns the latest statistics value for a (postId, mapId, key) triple.
	 *
	 * Returns `{ status: 'loading' }`, `{ status: 'value', value: string|null }`, or
	 * `{ status: 'error', code: string }`. The hook re-fetches when the editor's
	 * fingerprint of relevant Map attributes changes — see `useMapsFingerprint`.
	 *
	 * @param {number}      postId      Host post ID.
	 * @param {string}      mapId       Map ID, or 'auto'.
	 * @param {string}      bindingKey  One of the five binding keys.
	 * @param {string}      fingerprint Map-tree fingerprint that changes when resolution may change.
	 * @return {{status: 'loading'} | {status: 'value', value: string|null} | {status: 'error', code: string}}
	 */
	function useStatisticsValue( postId, mapId, bindingKey, fingerprint ) {

		const [ state, setState ] = useState( { status: 'loading' } );

		useEffect( () => {

			// Bail without fetching when there is no host post yet (e.g. during
			// the very first render of a brand-new draft). The bindings source
			// would have nothing to resolve against either.
			if ( ! postId ) {
				setState( { status: 'error', code: 'invalid-post' } );
				return undefined;
			}

			let cancelled = false;
			setState( { status: 'loading' } );

			fetchStatistics( postId, mapId ).then( ( result ) => {
				if ( cancelled ) {
					return;
				}
				if ( result.error ) {
					setState( { status: 'error', code: result.error.code } );
					return;
				}
				const value = Object.prototype.hasOwnProperty.call( result.values, bindingKey )
					? result.values[ bindingKey ]
					: null;
				setState( { status: 'value', value } );
			} );

			return () => {
				cancelled = true;
			};

		}, [ postId, mapId, bindingKey, fingerprint ] );

		return state;

	}

	/**
	 * Builds a fingerprint of the editor's GPX Map blocks.
	 *
	 * Collapses the live block tree into a small string that changes whenever
	 * something resolution-relevant changes (a Map was added/removed, its
	 * attachmentId or mapId attribute was edited). Re-renders that don't
	 * affect resolution don't bust the request cache.
	 *
	 * @return {string} Fingerprint string.
	 */
	function useMapsFingerprint() {

		return useSelect( ( select ) => {

			const editor = select( 'core/block-editor' );
			if ( ! editor || typeof editor.getBlocks !== 'function' ) {
				return '';
			}

			const parts = [];
			const walk = ( blocks ) => {
				if ( ! Array.isArray( blocks ) ) {
					return;
				}
				for ( const block of blocks ) {
					if ( ! block || typeof block !== 'object' ) {
						continue;
					}
					if ( block.name === 'kntnt-gpx-blocks/map' ) {
						const attrs = block.attributes || {};
						parts.push( ( attrs.attachmentId || 0 ) + ':' + ( attrs.mapId || '' ) );
					}
					if ( Array.isArray( block.innerBlocks ) && block.innerBlocks.length > 0 ) {
						walk( block.innerBlocks );
					}
				}
			};
			walk( editor.getBlocks() );
			return parts.join( '|' );

		}, [] );

	}

	/**
	 * Maps a Render_Error code to an editor-side hint string.
	 *
	 * The codes mirror the cache + resolver vocabulary documented in
	 * docs/caching.md and docs/blocks.md. Unknown codes fall back to a
	 * generic message.
	 *
	 * @param {string} code Error code from the REST endpoint.
	 * @return {string} Translated, action-oriented hint.
	 */
	function hintForCode( code ) {
		switch ( code ) {
			case 'no-map':
				return __( 'Add a GPX Map to see values', 'kntnt-gpx-blocks' );
			case 'multiple-maps':
				return __( 'Multiple GPX Maps — set an explicit mapId', 'kntnt-gpx-blocks' );
			case 'map-not-found':
				return __( 'GPX Map not found', 'kntnt-gpx-blocks' );
			case 'file-missing':
				return __( 'GPX file missing', 'kntnt-gpx-blocks' );
			case 'parse-failed':
				return __( 'GPX file could not be parsed', 'kntnt-gpx-blocks' );
			case 'no-track':
				return __( 'GPX file has no track', 'kntnt-gpx-blocks' );
			case 'too-few-points':
				return __( 'GPX file has too few points', 'kntnt-gpx-blocks' );
			case 'too-large':
				return __( 'GPX file is too large', 'kntnt-gpx-blocks' );
			case 'wrong-mime':
				return __( 'File is not a valid GPX', 'kntnt-gpx-blocks' );
			case 'invalid-post':
				return __( 'GPX statistics unavailable in this context', 'kntnt-gpx-blocks' );
			default:
				return __( 'GPX statistics unavailable', 'kntnt-gpx-blocks' );
		}
	}

	/**
	 * Builds the display content (an HTML string) for a paragraph's bound preview.
	 *
	 * The string is fed to the wrapped paragraph's edit component as the
	 * `attributes.content` value. The paragraph's RichText preserves the
	 * inline `<span>` so the purple styling sticks. The wrapper class lets
	 * the editor stylesheet override colour and font-style without touching
	 * the paragraph's own theme styles.
	 *
	 * @param {{status: string, value?: string|null, code?: string}} state Current fetch state.
	 * @return {string} HTML to embed as paragraph content in the editor.
	 */
	function renderContent( state ) {

		const baseClass = 'kntnt-gpx-blocks-statistics-preview';

		if ( state.status === 'loading' ) {
			// Empty content during loading lets the bindings system show its
			// own "GPX statistics" label briefly — preferable to a flash of
			// custom HTML that the user might mistake for a real value.
			return '';
		}

		if ( state.status === 'error' ) {
			const escapedHint = escapeHtml( hintForCode( state.code ) );
			return '<span class="' + baseClass + ' ' + baseClass + '--hint">' + escapedHint + '</span>';
		}

		// status === 'value'
		if ( state.value === null || state.value === '' ) {
			// Em-dash for null statistics (e.g. no-elevation track) so the row
			// remains visible during editing.
			return '<span class="' + baseClass + ' ' + baseClass + '--null">' + '—' + '</span>';
		}

		const escapedValue = escapeHtml( state.value );
		return '<span class="' + baseClass + ' ' + baseClass + '--value">' + escapedValue + '</span>';

	}

	/**
	 * Escapes a string for safe insertion into HTML text content / attribute values.
	 *
	 * Sufficient for the three constructor inputs we have: REST-formatted
	 * values (digits, units, separators), translated hint strings (latin
	 * letters and a few punctuation marks), and the em-dash literal. No HTML
	 * is ever concatenated from untrusted data.
	 *
	 * @param {string} input String to escape.
	 * @return {string} HTML-safe string.
	 */
	function escapeHtml( input ) {
		return String( input )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	/**
	 * Higher-order component that injects the resolved value into a bound paragraph.
	 *
	 * Pass-through for every block other than `core/paragraph`, and for any
	 * paragraph not bound to our source. Bound paragraphs receive an
	 * `attributes.content` override that the inner BlockEdit renders inside
	 * RichText — the value displays where the "GPX statistics" placeholder
	 * used to. The original block attributes are not mutated; the override
	 * lives only on the props handed to the wrapped component.
	 */
	const withStatisticsPreview = createHigherOrderComponent( ( BlockEdit ) => {
		return ( props ) => {

			// Pass through everything except bound paragraphs.
			if ( props.name !== 'core/paragraph' ) {
				return createElement( BlockEdit, props );
			}
			const binding = props.attributes
				&& props.attributes.metadata
				&& props.attributes.metadata.bindings
				&& props.attributes.metadata.bindings.content;
			if ( ! binding || binding.source !== SOURCE_NAME ) {
				return createElement( BlockEdit, props );
			}

			// Resolve the binding key and mapId from the persisted args.
			const args = binding.args || {};
			const bindingKey = typeof args.key === 'string' ? args.key : '';
			const mapId = typeof args.mapId === 'string' && args.mapId !== '' ? args.mapId : 'auto';

			// Pick the host post id from the editor store. The HOC runs inside
			// the editor only, so `core/editor` is always present.
			const postId = useSelect(
				( select ) => {
					const editor = select( 'core/editor' );
					if ( ! editor || typeof editor.getCurrentPostId !== 'function' ) {
						return 0;
					}
					return editor.getCurrentPostId() || 0;
				},
				[],
			);

			// Re-fetch when the editor's Map blocks change in a way that could
			// affect resolution. The fingerprint is also the cache-bust signal
			// for the module-level requestCache.
			const fingerprint = useMapsFingerprint();
			useEffect( () => {
				invalidateCache( postId, mapId );
			}, [ postId, mapId, fingerprint ] );

			const state = useStatisticsValue( postId, mapId, bindingKey, fingerprint );

			// Hand the wrapped BlockEdit a shallow-cloned attributes object
			// with `content` overridden. The bindings system on the front end
			// keeps using the empty string from saved post_content; this
			// override never reaches setAttributes so it cannot persist.
			const overrideContent = useMemo( () => renderContent( state ), [ state ] );
			const previewProps = Object.assign( {}, props, {
				attributes: Object.assign( {}, props.attributes, {
					content: overrideContent,
				} ),
			} );

			return createElement( BlockEdit, previewProps );

		};
	}, 'withGpxStatisticsPreview' );

	addFilter(
		'editor.BlockEdit',
		'kntnt-gpx-blocks/statistics-preview',
		withStatisticsPreview,
	);

} )();
