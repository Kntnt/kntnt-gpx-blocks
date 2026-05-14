/**
 * Unit tests for the elevation cursor's pointer-input layer.
 *
 * Pins the pointer protocol on the chart's hit-rect: hover-on-mouse,
 * press-and-drag (with `setPointerCapture`), and a wrapper-level
 * `pointerleave` that nulls the fraction so the cursor disappears when
 * the mouse moves on. The matrix the implementation enforces lives in
 * the header of `./cursor-input.ts`; this file pins it.
 *
 * @since 1.0.0
 */
import {
	bindPointerHandlers,
	bindPointerHandlersWhenVisible,
	clientXToFraction,
	type FractionSink,
} from './cursor-input';

const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * Creates a fresh `<rect>` element backed by a stub
 * `getBoundingClientRect` so the conversion math is deterministic.
 *
 * @since 1.0.0
 *
 * @param left  Left edge in CSS pixels.
 * @param width Rect width in CSS pixels.
 * @return The `<rect>` element with the stub installed.
 */
function makeHitRect( left: number, width: number ): SVGRectElement {
	const rect = document.createElementNS( SVG_NS, 'rect' ) as SVGRectElement;
	(
		rect as unknown as {
			getBoundingClientRect: () => DOMRect;
		}
	 ).getBoundingClientRect = (): DOMRect =>
		( {
			x: left,
			y: 0,
			width,
			height: 100,
			top: 0,
			bottom: 100,
			left,
			right: left + width,
			toJSON: () => ( {} ),
		} ) as DOMRect;
	// jsdom doesn't implement the pointer-capture surface; stub it so
	// the handler call sites do not throw.
	(
		rect as unknown as {
			setPointerCapture: ( id: number ) => void;
		}
	 ).setPointerCapture = (): void => undefined;
	(
		rect as unknown as {
			releasePointerCapture: ( id: number ) => void;
		}
	 ).releasePointerCapture = (): void => undefined;
	(
		rect as unknown as {
			hasPointerCapture: ( id: number ) => boolean;
		}
	 ).hasPointerCapture = (): boolean => true;
	return rect;
}

/**
 * Returns a fresh sink that records every `setFraction` call.
 *
 * @since 1.0.0
 *
 * @return The sink plus a recorder array the test asserts against.
 */
function makeSink(): {
	readonly sink: FractionSink;
	readonly calls: Array< number | null >;
} {
	const calls: Array< number | null > = [];
	const sink: FractionSink = {
		setFraction( value: number | null ): void {
			calls.push( value );
		},
	};
	return { sink, calls };
}

/**
 * Synthesises a PointerEvent-like object the handlers can consume.
 * jsdom's `PointerEvent` accepts the standard `PointerEventInit` but
 * its `pointerType` defaults to `''`, so an explicit value is set on
 * every event the tests dispatch.
 *
 * @since 1.0.0
 *
 * @param type             Event type, e.g. `'pointerdown'`.
 * @param init             Pointer-event-init bag.
 * @param init.pointerType
 * @param init.clientX
 * @param init.pointerId
 * @return The event ready for dispatch.
 */
function makePointerEvent(
	type: string,
	init: {
		pointerType: 'mouse' | 'touch';
		clientX: number;
		pointerId?: number;
	}
): PointerEvent {
	const event = new MouseEvent( type, {
		bubbles: true,
		cancelable: true,
		clientX: init.clientX,
	} ) as unknown as PointerEvent;
	Object.defineProperty( event, 'pointerType', {
		value: init.pointerType,
		configurable: true,
	} );
	Object.defineProperty( event, 'pointerId', {
		value: init.pointerId ?? 1,
		configurable: true,
	} );
	return event;
}

describe( 'clientXToFraction', () => {
	it( 'maps a midpoint clientX to fraction 0.5', () => {
		const rect = makeHitRect( 100, 200 );
		expect( clientXToFraction( 200, rect ) ).toBeCloseTo( 0.5, 6 );
	} );

	it( 'returns 0 when clientX equals the rect left', () => {
		const rect = makeHitRect( 100, 200 );
		expect( clientXToFraction( 100, rect ) ).toBeCloseTo( 0, 6 );
	} );

	it( 'returns 1 when clientX equals the rect right', () => {
		const rect = makeHitRect( 100, 200 );
		expect( clientXToFraction( 300, rect ) ).toBeCloseTo( 1, 6 );
	} );

	it( 'clamps to 0 when clientX is to the left of the rect', () => {
		const rect = makeHitRect( 100, 200 );
		expect( clientXToFraction( 50, rect ) ).toBe( 0 );
	} );

	it( 'clamps to 1 when clientX is to the right of the rect', () => {
		const rect = makeHitRect( 100, 200 );
		expect( clientXToFraction( 999, rect ) ).toBe( 1 );
	} );

	it( 'returns 0 for a zero-width rect (transient pre-layout state)', () => {
		const rect = makeHitRect( 0, 0 );
		expect( clientXToFraction( 50, rect ) ).toBe( 0 );
	} );
} );

describe( 'bindPointerHandlers', () => {
	it( 'writes a fraction on pointerdown and calls preventDefault + setPointerCapture', () => {
		const rect = makeHitRect( 100, 200 );
		const wrapper = document.createElement( 'div' );
		const { sink, calls } = makeSink();
		const setCapture = jest.fn();
		(
			rect as unknown as { setPointerCapture: ( id: number ) => void }
		 ).setPointerCapture = setCapture;
		bindPointerHandlers( rect, wrapper, sink );

		const event = makePointerEvent( 'pointerdown', {
			pointerType: 'mouse',
			clientX: 200,
			pointerId: 7,
		} );
		const preventDefault = jest.spyOn( event, 'preventDefault' );
		rect.dispatchEvent( event );

		expect( calls ).toEqual( [ 0.5 ] );
		expect( preventDefault ).toHaveBeenCalledTimes( 1 );
		expect( setCapture ).toHaveBeenCalledWith( 7 );
	} );

	it( 'updates fraction on pointermove during a scrub', () => {
		const rect = makeHitRect( 100, 200 );
		const wrapper = document.createElement( 'div' );
		const { sink, calls } = makeSink();
		bindPointerHandlers( rect, wrapper, sink );

		// Touch pointerdown opens a scrub.
		rect.dispatchEvent(
			makePointerEvent( 'pointerdown', {
				pointerType: 'touch',
				clientX: 100,
				pointerId: 2,
			} )
		);
		rect.dispatchEvent(
			makePointerEvent( 'pointermove', {
				pointerType: 'touch',
				clientX: 150,
				pointerId: 2,
			} )
		);

		expect( calls.length ).toBeGreaterThanOrEqual( 2 );
		expect( calls[ 0 ] ).toBeCloseTo( 0, 6 );
		expect( calls[ calls.length - 1 ] ).toBeCloseTo( 0.25, 6 );
	} );

	it( 'updates fraction on a mouse pointermove without a prior pointerdown (hover branch)', () => {
		const rect = makeHitRect( 100, 200 );
		const wrapper = document.createElement( 'div' );
		const { sink, calls } = makeSink();
		bindPointerHandlers( rect, wrapper, sink );

		rect.dispatchEvent(
			makePointerEvent( 'pointermove', {
				pointerType: 'mouse',
				clientX: 150,
			} )
		);
		expect( calls ).toEqual( [ 0.25 ] );
	} );

	it( 'does NOT update fraction on a touch pointermove without a scrub', () => {
		const rect = makeHitRect( 100, 200 );
		const wrapper = document.createElement( 'div' );
		const { sink, calls } = makeSink();
		bindPointerHandlers( rect, wrapper, sink );

		rect.dispatchEvent(
			makePointerEvent( 'pointermove', {
				pointerType: 'touch',
				clientX: 150,
			} )
		);
		expect( calls ).toEqual( [] );
	} );

	it( 'pointerup releases capture and ends the scrub WITHOUT writing a null fraction', () => {
		const rect = makeHitRect( 100, 200 );
		const wrapper = document.createElement( 'div' );
		const { sink, calls } = makeSink();
		const release = jest.fn();
		(
			rect as unknown as {
				releasePointerCapture: ( id: number ) => void;
			}
		 ).releasePointerCapture = release;
		bindPointerHandlers( rect, wrapper, sink );

		rect.dispatchEvent(
			makePointerEvent( 'pointerdown', {
				pointerType: 'mouse',
				clientX: 200,
				pointerId: 3,
			} )
		);
		rect.dispatchEvent(
			makePointerEvent( 'pointerup', {
				pointerType: 'mouse',
				clientX: 200,
				pointerId: 3,
			} )
		);

		expect( release ).toHaveBeenCalledWith( 3 );
		// The cursor stays at its last position — no null write.
		expect( calls ).toEqual( [ 0.5 ] );
	} );

	it( 'pointercancel ends the scrub the same way as pointerup', () => {
		const rect = makeHitRect( 100, 200 );
		const wrapper = document.createElement( 'div' );
		const { sink, calls } = makeSink();
		bindPointerHandlers( rect, wrapper, sink );

		rect.dispatchEvent(
			makePointerEvent( 'pointerdown', {
				pointerType: 'touch',
				clientX: 100,
				pointerId: 4,
			} )
		);
		rect.dispatchEvent(
			makePointerEvent( 'pointercancel', {
				pointerType: 'touch',
				clientX: 100,
				pointerId: 4,
			} )
		);
		// After a cancel, a touch pointermove without a fresh scrub
		// must not write fraction.
		rect.dispatchEvent(
			makePointerEvent( 'pointermove', {
				pointerType: 'touch',
				clientX: 200,
			} )
		);
		expect( calls ).toEqual( [ 0 ] );
	} );

	it( 'pointerleave on the wrapper writes null fraction on a mouse pointer when no scrub is active', () => {
		const rect = makeHitRect( 100, 200 );
		const wrapper = document.createElement( 'div' );
		const { sink, calls } = makeSink();
		bindPointerHandlers( rect, wrapper, sink );

		const event = makePointerEvent( 'pointerleave', {
			pointerType: 'mouse',
			clientX: 50,
		} );
		wrapper.dispatchEvent( event );
		expect( calls ).toEqual( [ null ] );
	} );

	it( 'pointerleave on the wrapper does NOT write null fraction when a scrub is active', () => {
		const rect = makeHitRect( 100, 200 );
		const wrapper = document.createElement( 'div' );
		const { sink, calls } = makeSink();
		bindPointerHandlers( rect, wrapper, sink );

		rect.dispatchEvent(
			makePointerEvent( 'pointerdown', {
				pointerType: 'mouse',
				clientX: 200,
				pointerId: 5,
			} )
		);
		wrapper.dispatchEvent(
			makePointerEvent( 'pointerleave', {
				pointerType: 'mouse',
				clientX: 50,
			} )
		);
		// First call is the pointerdown's fraction; the leave is suppressed.
		expect( calls ).toEqual( [ 0.5 ] );
	} );

	it( 'pointerleave on the wrapper does NOT write null fraction on a touch pointer', () => {
		const rect = makeHitRect( 100, 200 );
		const wrapper = document.createElement( 'div' );
		const { sink, calls } = makeSink();
		bindPointerHandlers( rect, wrapper, sink );

		const event = makePointerEvent( 'pointerleave', {
			pointerType: 'touch',
			clientX: 50,
		} );
		wrapper.dispatchEvent( event );
		expect( calls ).toEqual( [] );
	} );

	it( 'a second pointerdown during an active scrub is ignored (no setFraction, no setPointerCapture)', () => {
		const rect = makeHitRect( 100, 200 );
		const wrapper = document.createElement( 'div' );
		const { sink, calls } = makeSink();
		const setCapture = jest.fn();
		(
			rect as unknown as { setPointerCapture: ( id: number ) => void }
		 ).setPointerCapture = setCapture;
		bindPointerHandlers( rect, wrapper, sink );

		rect.dispatchEvent(
			makePointerEvent( 'pointerdown', {
				pointerType: 'mouse',
				clientX: 200,
				pointerId: 6,
			} )
		);
		rect.dispatchEvent(
			makePointerEvent( 'pointerdown', {
				pointerType: 'touch',
				clientX: 100,
				pointerId: 7,
			} )
		);

		expect( calls ).toEqual( [ 0.5 ] );
		expect( setCapture ).toHaveBeenCalledTimes( 1 );
		expect( setCapture ).toHaveBeenCalledWith( 6 );
	} );
} );

describe( 'bindPointerHandlersWhenVisible', () => {
	it( 'invokes bind() immediately when IntersectionObserver is undefined', () => {
		const original = ( globalThis as { IntersectionObserver?: unknown } )
			.IntersectionObserver;
		delete ( globalThis as { IntersectionObserver?: unknown } )
			.IntersectionObserver;

		try {
			const target = document.createElement( 'div' );
			const bind = jest.fn();
			bindPointerHandlersWhenVisible( target, bind );
			expect( bind ).toHaveBeenCalledTimes( 1 );
		} finally {
			if ( original !== undefined ) {
				(
					globalThis as { IntersectionObserver?: unknown }
				 ).IntersectionObserver = original;
			}
		}
	} );

	it( 'defers bind() until the first intersection callback fires', () => {
		const callbacks: Array<
			( entries: IntersectionObserverEntry[] ) => void
		> = [];
		class StubObserver {
			constructor(
				cb: ( entries: IntersectionObserverEntry[] ) => void
			) {
				callbacks.push( cb );
			}
			observe(): void {
				/* no-op */
			}
			unobserve(): void {
				/* no-op */
			}
			disconnect(): void {
				/* no-op */
			}
			takeRecords(): IntersectionObserverEntry[] {
				return [];
			}
		}
		const original = ( globalThis as { IntersectionObserver?: unknown } )
			.IntersectionObserver;
		(
			globalThis as { IntersectionObserver?: unknown }
		 ).IntersectionObserver = StubObserver as unknown;

		try {
			const target = document.createElement( 'div' );
			const bind = jest.fn();
			bindPointerHandlersWhenVisible( target, bind );

			expect( bind ).not.toHaveBeenCalled();

			// Fire the observer with a non-intersecting entry first.
			callbacks[ 0 ]?.( [
				{ isIntersecting: false } as IntersectionObserverEntry,
			] );
			expect( bind ).not.toHaveBeenCalled();

			// Then with an intersecting entry — bind() runs.
			callbacks[ 0 ]?.( [
				{ isIntersecting: true } as IntersectionObserverEntry,
			] );
			expect( bind ).toHaveBeenCalledTimes( 1 );

			// And it stays one — the observer disconnects after the
			// first hit.
			callbacks[ 0 ]?.( [
				{ isIntersecting: true } as IntersectionObserverEntry,
			] );
			expect( bind ).toHaveBeenCalledTimes( 1 );
		} finally {
			if ( original !== undefined ) {
				(
					globalThis as { IntersectionObserver?: unknown }
				 ).IntersectionObserver = original;
			} else {
				delete ( globalThis as { IntersectionObserver?: unknown } )
					.IntersectionObserver;
			}
		}
	} );
} );
