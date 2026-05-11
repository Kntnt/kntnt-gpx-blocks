/**
 * Jest tests for the GPX Map maxBounds helper.
 *
 * Asserts the contract documented in `bounds.ts`: the padded bbox is
 * proportional to the input span, degenerate bboxes are inflated to a
 * minimum span before padding, and structurally bad input returns `null`.
 *
 * @since 1.0.0
 */

import {
	DEFAULT_PADDING_FRACTION,
	MIN_SPAN_DEGREES,
	applyMaxBoundsIfSafe,
	canApplyMaxBounds,
	isCenterUsableForMaxBounds,
	paddedBoundsFromBox,
	type BoundingBox,
} from './bounds';

describe( 'paddedBoundsFromBox', () => {
	it( 'pads a normal bbox by the requested fraction on each side', () => {
		// A 1°×2° box at the origin. Padding 0.5 expands each axis by half its
		// span: lat span 1° → ±0.5° padding; lng span 2° → ±1° padding.
		const bbox: BoundingBox = {
			southWest: [ 0, 0 ],
			northEast: [ 1, 2 ],
		};

		const padded = paddedBoundsFromBox( bbox, 0.5 );

		expect( padded ).not.toBeNull();
		const [ south, west ] = padded!.southWest;
		const [ north, east ] = padded!.northEast;
		expect( south ).toBeCloseTo( -0.5, 10 );
		expect( north ).toBeCloseTo( 1.5, 10 );
		expect( west ).toBeCloseTo( -1, 10 );
		expect( east ).toBeCloseTo( 3, 10 );
	} );

	it( 'uses DEFAULT_PADDING_FRACTION when no fraction is supplied', () => {
		const bbox: BoundingBox = {
			southWest: [ 10, 20 ],
			northEast: [ 12, 24 ],
		};

		const explicit = paddedBoundsFromBox( bbox, DEFAULT_PADDING_FRACTION );
		const implicit = paddedBoundsFromBox( bbox );

		expect( implicit ).toEqual( explicit );
	} );

	it( 'returns a bbox with positive span for a single-point track', () => {
		// Both corners coincide — a degenerate input that without the
		// minimum-span inflation would yield a zero-area maxBounds and trap
		// the viewport at the point. The output should at least be the
		// MIN_SPAN_DEGREES rectangle scaled up by (1 + 2 × paddingFraction).
		const bbox: BoundingBox = {
			southWest: [ 50, 10 ],
			northEast: [ 50, 10 ],
		};

		const padded = paddedBoundsFromBox( bbox, 0.5 );

		expect( padded ).not.toBeNull();
		const [ south, west ] = padded!.southWest;
		const [ north, east ] = padded!.northEast;
		const expectedHalfSpan = ( MIN_SPAN_DEGREES / 2 ) * ( 1 + 2 * 0.5 );
		expect( north - south ).toBeGreaterThan( 0 );
		expect( east - west ).toBeGreaterThan( 0 );
		expect( south ).toBeCloseTo( 50 - expectedHalfSpan, 10 );
		expect( north ).toBeCloseTo( 50 + expectedHalfSpan, 10 );
		expect( west ).toBeCloseTo( 10 - expectedHalfSpan, 10 );
		expect( east ).toBeCloseTo( 10 + expectedHalfSpan, 10 );
	} );

	it( 'inflates only the collapsed axis when one span is zero', () => {
		// Track along a single meridian — lat span > 0, lng span = 0. The
		// lat axis pads from its real span; the lng axis falls back to
		// MIN_SPAN_DEGREES before padding.
		const bbox: BoundingBox = {
			southWest: [ 0, 5 ],
			northEast: [ 1, 5 ],
		};

		const padded = paddedBoundsFromBox( bbox, 0.5 );

		expect( padded ).not.toBeNull();
		const [ south, west ] = padded!.southWest;
		const [ north, east ] = padded!.northEast;
		expect( south ).toBeCloseTo( -0.5, 10 );
		expect( north ).toBeCloseTo( 1.5, 10 );
		const expectedHalfLng = ( MIN_SPAN_DEGREES / 2 ) * ( 1 + 2 * 0.5 );
		expect( west ).toBeCloseTo( 5 - expectedHalfLng, 10 );
		expect( east ).toBeCloseTo( 5 + expectedHalfLng, 10 );
	} );

	it( 'pads a continental-scale bbox proportionally', () => {
		// A 20°×60° box (very rough Europe) gets a sensible margin rather
		// than a fixed degree count that disappears at this scale.
		const bbox: BoundingBox = {
			southWest: [ 35, -10 ],
			northEast: [ 55, 50 ],
		};

		const padded = paddedBoundsFromBox( bbox, 0.5 );

		expect( padded ).not.toBeNull();
		const [ south, west ] = padded!.southWest;
		const [ north, east ] = padded!.northEast;
		expect( south ).toBeCloseTo( 25, 10 );
		expect( north ).toBeCloseTo( 65, 10 );
		expect( west ).toBeCloseTo( -40, 10 );
		expect( east ).toBeCloseTo( 80, 10 );
	} );

	it( 'clamps latitudes to [-90, 90] after padding', () => {
		// A polar track whose padded south corner would otherwise fall
		// below -90. Latitudes are physical and must clamp; longitudes
		// wrap and are not clamped here.
		const bbox: BoundingBox = {
			southWest: [ -85, 0 ],
			northEast: [ -80, 10 ],
		};

		const padded = paddedBoundsFromBox( bbox, 1 );

		expect( padded ).not.toBeNull();
		const [ south ] = padded!.southWest;
		const [ north ] = padded!.northEast;
		expect( south ).toBe( -90 );
		expect( north ).toBeLessThanOrEqual( 90 );
	} );

	it( 'returns null when any coordinate is non-finite', () => {
		const cases: BoundingBox[] = [
			{ southWest: [ Number.NaN, 0 ], northEast: [ 1, 1 ] },
			{ southWest: [ 0, 0 ], northEast: [ Number.POSITIVE_INFINITY, 1 ] },
			{ southWest: [ 0, Number.NEGATIVE_INFINITY ], northEast: [ 1, 1 ] },
		];

		for ( const bbox of cases ) {
			expect( paddedBoundsFromBox( bbox ) ).toBeNull();
		}
	} );

	it( 'produces a bbox that strictly contains the original track bbox', () => {
		// Sanity check the "at least part of the track stays visible" contract:
		// any positive padding keeps the original track bbox strictly inside
		// the padded one.
		const bbox: BoundingBox = {
			southWest: [ 59, 17 ],
			northEast: [ 60, 19 ],
		};

		const padded = paddedBoundsFromBox( bbox, 0.25 );

		expect( padded ).not.toBeNull();
		const [ south, west ] = padded!.southWest;
		const [ north, east ] = padded!.northEast;
		expect( south ).toBeLessThan( bbox.southWest[ 0 ] );
		expect( west ).toBeLessThan( bbox.southWest[ 1 ] );
		expect( north ).toBeGreaterThan( bbox.northEast[ 0 ] );
		expect( east ).toBeGreaterThan( bbox.northEast[ 1 ] );
	} );
} );

describe( 'isCenterUsableForMaxBounds (issue #116)', () => {
	it( 'accepts a finite ordinary center', () => {
		expect( isCenterUsableForMaxBounds( { lat: 59.33, lng: 18.07 } ) ).toBe(
			true
		);
	} );

	it( 'accepts the origin', () => {
		expect( isCenterUsableForMaxBounds( { lat: 0, lng: 0 } ) ).toBe( true );
	} );

	it( 'rejects a center with NaN lat (post-fitBounds against 0-size container)', () => {
		expect(
			isCenterUsableForMaxBounds( { lat: Number.NaN, lng: 18.07 } )
		).toBe( false );
	} );

	it( 'rejects a center with NaN lng', () => {
		expect(
			isCenterUsableForMaxBounds( { lat: 59.33, lng: Number.NaN } )
		).toBe( false );
	} );

	it( 'rejects a center with both components NaN (the reported issue #116 state)', () => {
		expect(
			isCenterUsableForMaxBounds( {
				lat: Number.NaN,
				lng: Number.NaN,
			} )
		).toBe( false );
	} );

	it( 'rejects infinite components', () => {
		expect(
			isCenterUsableForMaxBounds( {
				lat: Number.POSITIVE_INFINITY,
				lng: 0,
			} )
		).toBe( false );
		expect(
			isCenterUsableForMaxBounds( {
				lat: 0,
				lng: Number.NEGATIVE_INFINITY,
			} )
		).toBe( false );
	} );
} );

describe( 'canApplyMaxBounds (issue #117 — also reject NaN zoom)', () => {
	it( 'D1: accepts a finite center together with a finite zoom', () => {
		expect( canApplyMaxBounds( { lat: 59.33, lng: 18.07 }, 12 ) ).toBe(
			true
		);
	} );

	it( 'D2: rejects a finite center when zoom is NaN (post-fitBounds against 0-size container)', () => {
		expect(
			canApplyMaxBounds( { lat: 59.33, lng: 18.07 }, Number.NaN )
		).toBe( false );
	} );

	it( 'D3: rejects when zoom is ±Infinity', () => {
		expect(
			canApplyMaxBounds( { lat: 0, lng: 0 }, Number.POSITIVE_INFINITY )
		).toBe( false );
		expect(
			canApplyMaxBounds( { lat: 0, lng: 0 }, Number.NEGATIVE_INFINITY )
		).toBe( false );
	} );

	it( 'D4: rejects when center.lat is NaN even if zoom is finite', () => {
		expect( canApplyMaxBounds( { lat: Number.NaN, lng: 18.07 }, 10 ) ).toBe(
			false
		);
	} );

	it( 'D5: rejects when center.lng is NaN even if zoom is finite', () => {
		expect( canApplyMaxBounds( { lat: 59.33, lng: Number.NaN }, 10 ) ).toBe(
			false
		);
	} );
} );

describe( 'applyMaxBoundsIfSafe (issue #117 — E1 integration check)', () => {
	it( 'applies setMaxBounds and returns true when center and zoom are both finite', () => {
		const calls: Array< readonly [ LatLngTuple, LatLngTuple ] > = [];
		const map = {
			getCenter: () => ( { lat: 59.33, lng: 18.07 } ),
			getZoom: () => 12,
			setMaxBounds: ( b: readonly [ LatLngTuple, LatLngTuple ] ) => {
				calls.push( b );
			},
		};
		const bounds: readonly [ LatLngTuple, LatLngTuple ] = [
			[ 59, 17 ],
			[ 60, 19 ],
		];

		const applied = applyMaxBoundsIfSafe( map, bounds );

		expect( applied ).toBe( true );
		expect( calls ).toHaveLength( 1 );
		expect( calls[ 0 ] ).toBe( bounds );
	} );

	it( 'E1: skips setMaxBounds and returns false when getZoom() returns NaN (0-size container)', () => {
		// Reproduces the post-fitBounds state described in issue #117:
		// the container is 0×0, fitBounds ran anyway, and getZoom() now
		// reports NaN even though getCenter() looks finite. Calling
		// setMaxBounds in that state would crash Leaflet.
		let setMaxBoundsCalled = false;
		const map = {
			getSize: () => ( { x: 0, y: 0 } ),
			getCenter: () => ( { lat: 0, lng: 0 } ),
			getZoom: () => Number.NaN,
			setMaxBounds: () => {
				setMaxBoundsCalled = true;
			},
		};
		const bounds: readonly [ LatLngTuple, LatLngTuple ] = [
			[ 59, 17 ],
			[ 60, 19 ],
		];

		const applied = applyMaxBoundsIfSafe( map, bounds );

		expect( applied ).toBe( false );
		expect( setMaxBoundsCalled ).toBe( false );
	} );

	it( 'also skips when getCenter() reports NaN even though zoom is finite', () => {
		let setMaxBoundsCalled = false;
		const map = {
			getCenter: () => ( {
				lat: Number.NaN,
				lng: Number.NaN,
			} ),
			getZoom: () => 12,
			setMaxBounds: () => {
				setMaxBoundsCalled = true;
			},
		};
		const bounds: readonly [ LatLngTuple, LatLngTuple ] = [
			[ 59, 17 ],
			[ 60, 19 ],
		];

		const applied = applyMaxBoundsIfSafe( map, bounds );

		expect( applied ).toBe( false );
		expect( setMaxBoundsCalled ).toBe( false );
	} );
} );

// Helper alias used by the integration tests above.
type LatLngTuple = readonly [ number, number ];
