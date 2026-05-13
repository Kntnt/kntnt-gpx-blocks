/**
 * Jest tests for the GPX Map keyboard filter.
 *
 * Exercises the capture-phase listener that swallows the key groups whose
 * owning result (Pan or Zoom) is disabled, before Leaflet's bubble-phase
 * keyboard handler can run. The filter is the mechanism that lets the two
 * result-named Inspector toggles split Leaflet's monolithic keyboard handler
 * into the two key groups the visitor sees as Pan and Zoom.
 *
 * @since 0.13.5
 */

import { attachKeyFilter } from './keyboard';

/**
 * Build a synthetic container, attach the filter against it under the given
 * settings, dispatch a sequence of `keydown` events at the bubble-phase
 * listener registered after the filter, and return the keys that survived
 * propagation. The bubble-phase listener stands in for Leaflet's own
 * handler: it sees exactly the events the filter did not swallow.
 *
 * @param settings            - The two result-named toggles.
 * @param settings.enablePan  - Whether the Pan result is enabled.
 * @param settings.enableZoom - Whether the Zoom result is enabled.
 * @param keys                - Sequence of `KeyboardEvent.key` values to dispatch.
 * @return The subset of `keys` that bubbled past the capture-phase filter.
 */
function dispatch(
	settings: { enablePan: boolean; enableZoom: boolean },
	keys: readonly string[]
): string[] {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const controller = new AbortController();
	attachKeyFilter( container, settings, controller.signal );

	// Bubble-phase listener stands in for Leaflet's own handler — records
	// every key that survives the capture-phase filter above.
	const survivors: string[] = [];
	container.addEventListener( 'keydown', ( event: KeyboardEvent ) => {
		survivors.push( event.key );
	} );

	for ( const key of keys ) {
		container.dispatchEvent(
			new KeyboardEvent( 'keydown', {
				key,
				bubbles: true,
				cancelable: true,
			} )
		);
	}

	controller.abort();
	container.remove();
	return survivors;
}

describe( 'attachKeyFilter', () => {
	it( 'stops arrow keys when enablePan is false', () => {
		const survivors = dispatch( { enablePan: false, enableZoom: true }, [
			'ArrowUp',
			'ArrowDown',
			'ArrowLeft',
			'ArrowRight',
		] );
		expect( survivors ).toEqual( [] );
	} );

	it( 'lets the Zoom keys through when only enablePan is false', () => {
		// Cross-check: the filter must not be over-broad. With Pan off but
		// Zoom on, the `+` / `-` / `=` keys still reach Leaflet's handler.
		const survivors = dispatch( { enablePan: false, enableZoom: true }, [
			'+',
			'-',
			'=',
		] );
		expect( survivors ).toEqual( [ '+', '-', '=' ] );
	} );

	it( 'stops +, -, and = when enableZoom is false', () => {
		const survivors = dispatch( { enablePan: true, enableZoom: false }, [
			'+',
			'-',
			'=',
		] );
		expect( survivors ).toEqual( [] );
	} );

	it( 'lets the Pan keys through when only enableZoom is false', () => {
		// Cross-check: the filter must not be over-broad. With Zoom off but
		// Pan on, the arrow keys still reach Leaflet's handler.
		const survivors = dispatch( { enablePan: true, enableZoom: false }, [
			'ArrowUp',
			'ArrowDown',
			'ArrowLeft',
			'ArrowRight',
		] );
		expect( survivors ).toEqual( [
			'ArrowUp',
			'ArrowDown',
			'ArrowLeft',
			'ArrowRight',
		] );
	} );

	it( 'lets unrelated keys through regardless of the toggles', () => {
		// A keystroke outside both gated groups must always pass — a
		// host-page slideshow or modal that listens for keys like Escape
		// or Enter on the bubble path keeps working.
		const survivors = dispatch( { enablePan: false, enableZoom: false }, [
			'Escape',
			'Enter',
			'Tab',
			'a',
		] );
		expect( survivors ).toEqual( [ 'Escape', 'Enter', 'Tab', 'a' ] );
	} );

	it( 'releases its listener when the signal aborts', () => {
		// After the controller aborts, a fresh keystroke must reach the
		// bubble listener even when the filter would otherwise swallow it.
		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const controller = new AbortController();
		attachKeyFilter(
			container,
			{ enablePan: false, enableZoom: false },
			controller.signal
		);

		const survivors: string[] = [];
		container.addEventListener( 'keydown', ( event: KeyboardEvent ) => {
			survivors.push( event.key );
		} );

		controller.abort();
		container.dispatchEvent(
			new KeyboardEvent( 'keydown', {
				key: 'ArrowUp',
				bubbles: true,
				cancelable: true,
			} )
		);

		container.remove();
		expect( survivors ).toEqual( [ 'ArrowUp' ] );
	} );
} );
