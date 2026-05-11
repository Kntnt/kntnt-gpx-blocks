/**
 * Hook that surfaces a `Render_Elevation`-emitted error message to the
 * Inspector sidebar.
 *
 * `ServerSideRender` always renders the PHP output in the canvas, but error
 * states (no map, missing attachment, …) are easy to miss when the wrapper
 * collapses to a thin notice. The hook polls the SSR wrapper's DOM after
 * each render — there is no React signal for "ServerSideRender finished" —
 * and lifts any `.kntnt-gpx-blocks-error` text into React state so the
 * Inspector can mirror it as a dismissible Notice.
 *
 * @since 1.0.0
 */

import { useRef, useState, useEffect } from '@wordpress/element';
import type { RefObject } from 'react';

/**
 * Aggregate result of `useSsrErrorMessage`.
 *
 * @since 1.0.0
 */
export interface UseSsrErrorMessageResult {
	/** Latest error message text (empty when none). */
	readonly errorMessage: string;
	/** Wrapper ref the caller attaches to the SSR-hosting `<div>`. */
	readonly ssrWrapperRef: RefObject< HTMLDivElement >;
}

/**
 * Surface a `Render_Elevation`-emitted error message to React state.
 *
 * The hook runs on every render — `ServerSideRender` may have swapped in
 * new HTML — but the `prevErrorRef` guard limits `setErrorMessage` calls
 * to actual DOM transitions, which prevents the infinite update loop a
 * naive empty-deps `useEffect` would otherwise trigger.
 *
 * @since 1.0.0
 *
 * @return Aggregate result; see {@link UseSsrErrorMessageResult}.
 */
export function useSsrErrorMessage(): UseSsrErrorMessageResult {
	const [ errorMessage, setErrorMessage ] = useState< string >( '' );
	const ssrWrapperRef = useRef< HTMLDivElement >( null );
	const prevErrorRef = useRef< string >( '' );

	// Inspect the SSR output after each render; look for the error notice.
	// No dependency array: we want to re-read the DOM whenever React renders,
	// because ServerSideRender may have swapped in new HTML. prevErrorRef guards
	// the setErrorMessage call so it only fires when the DOM actually changes,
	// which prevents the infinite update loop the linter is guarding against.
	// eslint-disable-next-line react-hooks/exhaustive-deps
	useEffect( () => {
		if ( ! ssrWrapperRef.current ) {
			return;
		}
		const errorEl = ssrWrapperRef.current.querySelector< HTMLElement >(
			'.kntnt-gpx-blocks-error'
		);
		const next = errorEl ? errorEl.textContent ?? '' : '';
		if ( next !== prevErrorRef.current ) {
			prevErrorRef.current = next;
			setErrorMessage( next );
		}
	} );

	return { errorMessage, ssrWrapperRef };
}
