/**
 * Unit tests for {@link useBoundMapPayload}.
 *
 * Drives the hook through a tiny `@wordpress/element` shim:
 *
 *   - `useState` lives in a per-test slot array so subsequent hook calls
 *     read the latest state. The cursor resets at the start of every
 *     render.
 *   - `useEffect` runs the effect body only when the dep array changes,
 *     just like React. The previous effect's cleanup fires first.
 *
 * Jest hoists `jest.mock()` factories above all imports, so any
 * variables the factory references must be declared with the `mock`
 * prefix (Jest's allowlist for hoisted factories) or live inside the
 * factory itself. The `mockHook*` names below carry that prefix; the
 * test bodies reach the same state slots through getter helpers.
 *
 * Coverage:
 *
 * - `attachmentId <= 0` clears state and does not call `apiFetch`.
 * - loading → ready transitions update `data` and clear `isLoading`.
 * - loading → error transitions populate `error` and clear `data`.
 * - The same `attachmentId` calls `apiFetch` exactly once across
 *   multiple renders (effect deps prevent re-firing).
 * - A changed `attachmentId` triggers a refetch.
 *
 * @since 1.0.0
 */

jest.mock(
	'@wordpress/element',
	() => {
		const state: {
			slots: unknown[];
			cursor: number;
			effects: Array< {
				deps: unknown[];
				cleanup?: () => void;
			} >;
			effectCursor: number;
		} = {
			slots: [],
			cursor: 0,
			effects: [],
			effectCursor: 0,
		};

		const reset = (): void => {
			for ( const rec of state.effects ) {
				rec.cleanup?.();
			}
			state.slots = [];
			state.effects = [];
			state.cursor = 0;
			state.effectCursor = 0;
		};

		const renderStart = (): void => {
			state.cursor = 0;
			state.effectCursor = 0;
		};

		const depsChanged = (
			a: unknown[] | undefined,
			b: unknown[]
		): boolean => {
			if ( ! a ) {
				return true;
			}
			if ( a.length !== b.length ) {
				return true;
			}
			for ( let i = 0; i < a.length; i++ ) {
				if ( a[ i ] !== b[ i ] ) {
					return true;
				}
			}
			return false;
		};

		const moduleExports = {
			__esModule: true,
			useState: < T >( initial: T ): [ T, ( next: T ) => void ] => {
				const slot = state.cursor++;
				if ( state.slots.length <= slot ) {
					state.slots[ slot ] = initial;
				}
				return [
					state.slots[ slot ] as T,
					( next: T ): void => {
						state.slots[ slot ] = next;
					},
				];
			},
			useEffect: (
				fn: () => void | ( () => void ),
				deps: unknown[]
			): void => {
				const slot = state.effectCursor++;
				const prev = state.effects[ slot ];
				if ( ! depsChanged( prev?.deps, deps ) ) {
					return;
				}
				prev?.cleanup?.();
				const cleanup = fn();
				state.effects[ slot ] = {
					deps,
					cleanup:
						typeof cleanup === 'function' ? cleanup : undefined,
				};
			},
			__resetMockHookEnv: reset,
			__renderStart: renderStart,
		};

		return moduleExports;
	},
	{ virtual: true }
);

jest.mock(
	'@wordpress/api-fetch',
	() => {
		const mock = jest.fn();
		return {
			__esModule: true,
			default: ( ...args: unknown[] ) => mock( ...args ),
			__mockApiFetch: mock,
		};
	},
	{ virtual: true }
);

import {
	useBoundMapPayload,
	type UseBoundMapPayloadResult,
} from './use-bound-map-payload';
import * as wpElement from '@wordpress/element';
import * as apiFetchModule from '@wordpress/api-fetch';

const resetEnv = ( wpElement as unknown as { __resetMockHookEnv: () => void } )
	.__resetMockHookEnv;
const renderStart = ( wpElement as unknown as { __renderStart: () => void } )
	.__renderStart;
const apiFetchMock = (
	apiFetchModule as unknown as { __mockApiFetch: jest.Mock }
 ).__mockApiFetch;

beforeEach( () => {
	resetEnv();
	apiFetchMock.mockReset();
} );

function render( attachmentId: number ): UseBoundMapPayloadResult {
	renderStart();
	// eslint-disable-next-line react-hooks/rules-of-hooks -- intentional: this is a test driver that simulates a React render cycle.
	return useBoundMapPayload( attachmentId );
}

async function flushMicrotasks(): Promise< void > {
	await Promise.resolve();
	await Promise.resolve();
	await Promise.resolve();
}

describe( 'useBoundMapPayload', () => {
	it( 'does not call apiFetch when attachmentId is 0', () => {
		render( 0 );
		expect( apiFetchMock ).not.toHaveBeenCalled();
	} );

	it( 'returns the empty result when attachmentId is 0', () => {
		const result = render( 0 );
		expect( result.data ).toBeNull();
		expect( result.error ).toBeNull();
		expect( result.isLoading ).toBe( false );
	} );

	it( 'transitions loading → ready and exposes the payload', async () => {
		const payload = {
			geojson: { type: 'FeatureCollection', features: [] },
			statistics: {
				distance: 5000,
				min_elevation: 0,
				max_elevation: 50,
				ascent: 25,
				descent: 25,
			},
		};
		apiFetchMock.mockReturnValueOnce( Promise.resolve( payload ) );

		// First render fires the effect; second render (before the
		// microtask flush) reads the in-flight loading flag the effect
		// just wrote.
		render( 42 );
		const inFlight = render( 42 );
		expect( inFlight.isLoading ).toBe( true );

		await flushMicrotasks();

		const settled = render( 42 );
		expect( settled.isLoading ).toBe( false );
		expect( settled.data ).toEqual( payload );
		expect( settled.error ).toBeNull();
	} );

	it( 'transitions loading → error and exposes the error', async () => {
		const err = { code: 'no-track', message: 'No track in this file' };
		apiFetchMock.mockReturnValueOnce( Promise.reject( err ) );

		render( 42 );
		await flushMicrotasks();
		const settled = render( 42 );

		expect( settled.isLoading ).toBe( false );
		expect( settled.data ).toBeNull();
		expect( settled.error ).toEqual( err );
	} );

	it( 'calls apiFetch with the documented URL', () => {
		apiFetchMock.mockReturnValueOnce(
			Promise.resolve( {
				geojson: {},
				statistics: {
					distance: null,
					min_elevation: null,
					max_elevation: null,
					ascent: null,
					descent: null,
				},
			} )
		);
		render( 17 );
		expect( apiFetchMock ).toHaveBeenCalledWith( {
			path: '/kntnt-gpx-blocks/v1/preview/17',
		} );
	} );

	it( 'fetches once across multiple renders with the same attachmentId', async () => {
		apiFetchMock.mockReturnValue(
			Promise.resolve( {
				geojson: {},
				statistics: {
					distance: null,
					min_elevation: null,
					max_elevation: null,
					ascent: null,
					descent: null,
				},
			} )
		);
		render( 42 );
		await flushMicrotasks();
		render( 42 );
		render( 42 );
		expect( apiFetchMock ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'refetches when attachmentId changes', async () => {
		const first = {
			geojson: {},
			statistics: {
				distance: 100,
				min_elevation: 0,
				max_elevation: 10,
				ascent: 5,
				descent: 5,
			},
		};
		const second = {
			geojson: {},
			statistics: {
				distance: 500,
				min_elevation: 10,
				max_elevation: 100,
				ascent: 50,
				descent: 50,
			},
		};
		apiFetchMock
			.mockReturnValueOnce( Promise.resolve( first ) )
			.mockReturnValueOnce( Promise.resolve( second ) );

		render( 42 );
		await flushMicrotasks();
		render( 99 );
		await flushMicrotasks();
		const settled = render( 99 );

		expect( apiFetchMock ).toHaveBeenCalledTimes( 2 );
		expect( apiFetchMock ).toHaveBeenNthCalledWith( 1, {
			path: '/kntnt-gpx-blocks/v1/preview/42',
		} );
		expect( apiFetchMock ).toHaveBeenNthCalledWith( 2, {
			path: '/kntnt-gpx-blocks/v1/preview/99',
		} );
		expect( settled.data ).toEqual( second );
	} );
} );
