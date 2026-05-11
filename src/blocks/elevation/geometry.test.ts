/**
 * Jest tests for the GPX Elevation geometry helpers.
 *
 * @since 0.2.0
 */

import {
	interpolateSample,
	sampleToSvg,
	type ChartBounds,
	type DistanceElevation,
} from './geometry';

describe( 'interpolateSample', () => {
	const series: ReadonlyArray< DistanceElevation > = [
		[ 0, 100 ],
		[ 100, 200 ],
		[ 200, 150 ],
	];
	const total = 200;

	it( 'returns null for an empty series', () => {
		expect( interpolateSample( [], 0, 0.5 ) ).toBeNull();
	} );

	it( 'returns the start sample at fraction 0', () => {
		expect( interpolateSample( series, total, 0 ) ).toEqual( [ 0, 100 ] );
	} );

	it( 'returns the end sample at fraction 1', () => {
		expect( interpolateSample( series, total, 1 ) ).toEqual( [ 200, 150 ] );
	} );

	it( 'interpolates linearly between two samples', () => {
		const out = interpolateSample( series, total, 0.25 );
		expect( out ).not.toBeNull();
		const [ d, e ] = out!;
		// Fraction 0.25 → distance 50, halfway across the [0, 100] segment;
		// elevation lerps 100 → 200 by half = 150.
		expect( d ).toBeCloseTo( 50, 10 );
		expect( e ).toBeCloseTo( 150, 10 );
	} );

	it( 'handles a zero-length segment without dividing by zero', () => {
		const flat: ReadonlyArray< DistanceElevation > = [
			[ 100, 50 ],
			[ 100, 50 ],
		];
		expect( interpolateSample( flat, 100, 0.5 ) ).toEqual( [ 100, 50 ] );
	} );

	it( 'returns the lone sample for a single-point series', () => {
		expect( interpolateSample( [ [ 0, 42 ] ], 0, 0.7 ) ).toEqual( [
			0, 42,
		] );
	} );
} );

describe( 'sampleToSvg', () => {
	const chart: ChartBounds = {
		left: 56,
		right: 1184,
		top: 16,
		bottom: 272,
	};

	it( 'maps a sample at the start to the chart left edge and bottom-aligned y', () => {
		const out = sampleToSvg( [ 0, 100 ], 200, 100, 200, chart );
		expect( out.cx ).toBeCloseTo( 56, 10 );
		// Elevation 100 = yMin → bottom of chart.
		expect( out.cy ).toBeCloseTo( 272, 10 );
	} );

	it( 'maps a sample at the end to the chart right edge and top-aligned y', () => {
		const out = sampleToSvg( [ 200, 200 ], 200, 100, 200, chart );
		expect( out.cx ).toBeCloseTo( 1184, 10 );
		// Elevation 200 = yMax → top of chart.
		expect( out.cy ).toBeCloseTo( 16, 10 );
	} );

	it( 'snaps cx to the chart left edge for a zero or negative totalDistance', () => {
		const out = sampleToSvg( [ 0, 100 ], 0, 100, 200, chart );
		expect( out.cx ).toBeCloseTo( 56, 10 );
	} );

	it( 'snaps cy to the chart bottom for a flat y range', () => {
		const out = sampleToSvg( [ 100, 50 ], 200, 50, 50, chart );
		expect( out.cy ).toBeCloseTo( 272, 10 );
	} );

	it( 'uses padded yMin/yMax — the cursor sits on the polyline, not on raw min/max', () => {
		// The polyline is rendered against [80, 220]; an elevation of 100 maps
		// to ratio 20/140 ≈ 0.142857 of the chart's vertical range.
		const out = sampleToSvg( [ 100, 100 ], 200, 80, 220, chart );
		const ratio = ( 100 - 80 ) / ( 220 - 80 );
		const expected = chart.bottom - ratio * ( chart.bottom - chart.top );
		expect( out.cy ).toBeCloseTo( expected, 10 );
	} );
} );
