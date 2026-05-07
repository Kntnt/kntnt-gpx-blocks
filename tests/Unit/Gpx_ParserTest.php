<?php
/**
 * Tests for Conversion\Gpx_Parser.
 *
 * Covers the streaming GPX parser end-to-end — happy paths for each accepted
 * source structure (track, route, multi-segment), error cases for each
 * documented error code, and security regressions for XXE and Billion-laughs
 * style entity attacks. The parser is framework-agnostic by design, so these
 * tests do not stub any WordPress functions.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Kntnt\Gpx_Blocks\Conversion\Gpx_Parser;
use Kntnt\Gpx_Blocks\Conversion\Parser_Exception;
use Kntnt\Gpx_Blocks\Conversion\Track_Data;
use Kntnt\Gpx_Blocks\Conversion\Track_Point;
use Kntnt\Gpx_Blocks\Conversion\Waypoint;

/**
 * Returns the absolute path to a fixture file.
 *
 * @param string $name Fixture filename relative to tests/Unit/fixtures/gpx/.
 * @return string Absolute path.
 */
function fixture_path( string $name ): string {
	return __DIR__ . '/fixtures/gpx/' . $name;
}

// ---------------------------------------------------------------------------
// Happy path
// ---------------------------------------------------------------------------

test( 'parses a simple single-track GPX into Track_Data', function (): void {

	$parser = new Gpx_Parser();
	$result = $parser->parse( fixture_path( 'happy-path.gpx' ) );

	expect( $result )->toBeInstanceOf( Track_Data::class )
		->and( $result->points )->toHaveCount( 3 )
		->and( $result->waypoints )->toHaveCount( 0 );

	$first = $result->points[0];
	expect( $first )->toBeInstanceOf( Track_Point::class )
		->and( $first->lat )->toBe( 59.3293 )
		->and( $first->lon )->toBe( 18.0686 )
		->and( $first->ele )->toBe( 10.5 );

} );

test( 'uses only the first <trk> when multiple are present', function (): void {

	$parser = new Gpx_Parser();
	$result = $parser->parse( fixture_path( 'multiple-trk.gpx' ) );

	expect( $result->points )->toHaveCount( 2 )
		->and( $result->points[0]->lat )->toBe( 59.3293 )
		->and( $result->points[1]->lat )->toBe( 59.3300 );

} );

test( 'concatenates trackpoints across multiple <trkseg> elements', function (): void {

	$parser = new Gpx_Parser();
	$result = $parser->parse( fixture_path( 'multiple-trkseg.gpx' ) );

	expect( $result->points )->toHaveCount( 5 )
		->and( $result->points[0]->lat )->toBe( 59.3293 )
		->and( $result->points[2]->lat )->toBe( 59.3310 )
		->and( $result->points[4]->lat )->toBe( 59.3330 );

} );

test( 'uses first <rte> when no <trk> is present', function (): void {

	$parser = new Gpx_Parser();
	$result = $parser->parse( fixture_path( 'route-only.gpx' ) );

	expect( $result->points )->toHaveCount( 3 )
		->and( $result->points[0]->lat )->toBe( 59.3293 )
		->and( $result->points[0]->ele )->toBe( 10.0 )
		->and( $result->points[2]->lat )->toBe( 59.3310 );

} );

test( 'preserves null elevation on points without <ele>', function (): void {

	$parser = new Gpx_Parser();
	$result = $parser->parse( fixture_path( 'mixed-elevation.gpx' ) );

	expect( $result->points )->toHaveCount( 4 )
		->and( $result->points[0]->ele )->toBe( 10.0 )
		->and( $result->points[1]->ele )->toBeNull()
		->and( $result->points[2]->ele )->toBe( 15.0 )
		->and( $result->points[3]->ele )->toBeNull();

} );

test( 'returns null elevation on every point when no <ele> exists in source', function (): void {

	$parser = new Gpx_Parser();
	$result = $parser->parse( fixture_path( 'no-elevation.gpx' ) );

	expect( $result->points )->toHaveCount( 3 );
	foreach ( $result->points as $point ) {
		expect( $point->ele )->toBeNull();
	}

} );

test( 'silently drops trackpoints with invalid lat/lon attributes', function (): void {

	$parser = new Gpx_Parser();
	$result = $parser->parse( fixture_path( 'invalid-coordinates.gpx' ) );

	// Of the eight source <trkpt>, only two carry valid coordinates.
	expect( $result->points )->toHaveCount( 2 )
		->and( $result->points[0]->lat )->toBe( 59.3293 )
		->and( $result->points[0]->ele )->toBe( 10.0 )
		->and( $result->points[1]->lat )->toBe( 59.3320 )
		->and( $result->points[1]->ele )->toBe( 17.0 );

} );

// ---------------------------------------------------------------------------
// Error cases
// ---------------------------------------------------------------------------

test( 'throws no-track when only waypoints are present', function (): void {

	$parser = new Gpx_Parser();

	try {
		$parser->parse( fixture_path( 'waypoints-only.gpx' ) );
		$this->fail( 'Expected Parser_Exception was not thrown' );
	} catch ( Parser_Exception $e ) {
		expect( $e->getErrorCode() )->toBe( 'no-track' );
	}

} );

test( 'throws too-few-points when fewer than two valid trackpoints remain', function (): void {

	$parser = new Gpx_Parser();

	try {
		$parser->parse( fixture_path( 'single-point.gpx' ) );
		$this->fail( 'Expected Parser_Exception was not thrown' );
	} catch ( Parser_Exception $e ) {
		expect( $e->getErrorCode() )->toBe( 'too-few-points' );
	}

} );

test( 'throws parse-failed on malformed XML', function (): void {

	$parser = new Gpx_Parser();

	try {
		$parser->parse( fixture_path( 'malformed.gpx' ) );
		$this->fail( 'Expected Parser_Exception was not thrown' );
	} catch ( Parser_Exception $e ) {
		expect( $e->getErrorCode() )->toBe( 'parse-failed' );
	}

} );

test( 'throws wrong-mime when the root element is not <gpx>', function (): void {

	$parser = new Gpx_Parser();

	try {
		$parser->parse( fixture_path( 'wrong-root.gpx' ) );
		$this->fail( 'Expected Parser_Exception was not thrown' );
	} catch ( Parser_Exception $e ) {
		expect( $e->getErrorCode() )->toBe( 'wrong-mime' );
	}

} );

test( 'throws file-missing when the path does not exist', function (): void {

	$parser = new Gpx_Parser();

	try {
		$parser->parse( fixture_path( 'does-not-exist.gpx' ) );
		$this->fail( 'Expected Parser_Exception was not thrown' );
	} catch ( Parser_Exception $e ) {
		expect( $e->getErrorCode() )->toBe( 'file-missing' );
	}

} );

test( 'throws too-large when trackpoint count exceeds the cap', function (): void {

	$parser = new Gpx_Parser();

	try {
		// Cap of 2 forces the third <trkpt> in the happy-path fixture to abort.
		$parser->parse( fixture_path( 'happy-path.gpx' ), max_points: 2 );
		$this->fail( 'Expected Parser_Exception was not thrown' );
	} catch ( Parser_Exception $e ) {
		expect( $e->getErrorCode() )->toBe( 'too-large' );
	}

} );

// ---------------------------------------------------------------------------
// Security regressions: XXE and entity-expansion attacks
// ---------------------------------------------------------------------------

test( 'XXE attempt does not resolve the external entity', function (): void {

	// Build the attacker payload pointing at a secret file inside the fixtures.
	$secret_path  = fixture_path( 'secret.txt' );
	$secret_url   = 'file://' . $secret_path;
	$secret_bytes = (string) file_get_contents( $secret_path );
	$xxe_path     = fixture_path( 'xxe-attempt.gpx' );
	$xxe_xml      = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE foo [
	<!ENTITY xxe SYSTEM "{$secret_url}">
]>
<gpx version="1.1" creator="test" xmlns="http://www.topografix.com/GPX/1/1">
	<wpt lat="59.3293" lon="18.0686">
		<name>&xxe;</name>
		<desc>&xxe;</desc>
	</wpt>
	<trk>
		<trkseg>
			<trkpt lat="59.3293" lon="18.0686"><ele>10.0</ele></trkpt>
			<trkpt lat="59.3300" lon="18.0700"><ele>11.0</ele></trkpt>
		</trkseg>
	</trk>
</gpx>
XML;
	file_put_contents( $xxe_path, $xxe_xml );

	$parser = new Gpx_Parser();

	// Acceptable outcomes: a parse-failed exception OR a Track_Data that does
	// not contain the secret content anywhere. Both prove the entity did not
	// resolve to the file's contents.
	try {

		$result = $parser->parse( $xxe_path );

		$haystack = '';
		foreach ( $result->waypoints as $waypoint ) {
			$haystack .= ( $waypoint->name ?? '' ) . "\n" . ( $waypoint->desc ?? '' ) . "\n"
				. ( $waypoint->sym ?? '' ) . "\n" . ( $waypoint->type ?? '' ) . "\n";
		}
		foreach ( $result->points as $point ) {
			$haystack .= $point->lat . ' ' . $point->lon . ' ' . ( $point->ele ?? '' ) . "\n";
		}

		expect( $haystack )->not->toContain( 'SECRET_CONTENT_FROM_LOCAL_FILE_SHOULD_NEVER_LEAK' )
			->and( $haystack )->not->toContain( 'root:x:' )
			->and( $haystack )->not->toContain( $secret_bytes );

	} catch ( Parser_Exception $e ) {

		// Bailing out is also acceptable; only parse-failed is meaningful here
		// because the file contains a valid <gpx> root and at least two valid
		// trackpoints when entities are not expanded.
		expect( $e->getErrorCode() )->toBe( 'parse-failed' );

	} finally {

		@unlink( $xxe_path );

	}

} );

test( 'billion-laughs attempt does not exhaust memory or hang', function (): void {

	// Classic entity-expansion attack: 9 levels of 10x expansion = 10^9 chars.
	$bomb_path = fixture_path( 'billion-laughs.gpx' );
	$bomb_xml  = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE gpx [
	<!ENTITY lol "lol">
	<!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">
	<!ENTITY lol3 "&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;">
	<!ENTITY lol4 "&lol3;&lol3;&lol3;&lol3;&lol3;&lol3;&lol3;&lol3;&lol3;&lol3;">
	<!ENTITY lol5 "&lol4;&lol4;&lol4;&lol4;&lol4;&lol4;&lol4;&lol4;&lol4;&lol4;">
	<!ENTITY lol6 "&lol5;&lol5;&lol5;&lol5;&lol5;&lol5;&lol5;&lol5;&lol5;&lol5;">
	<!ENTITY lol7 "&lol6;&lol6;&lol6;&lol6;&lol6;&lol6;&lol6;&lol6;&lol6;&lol6;">
	<!ENTITY lol8 "&lol7;&lol7;&lol7;&lol7;&lol7;&lol7;&lol7;&lol7;&lol7;&lol7;">
	<!ENTITY lol9 "&lol8;&lol8;&lol8;&lol8;&lol8;&lol8;&lol8;&lol8;&lol8;&lol8;">
]>
<gpx version="1.1" creator="test" xmlns="http://www.topografix.com/GPX/1/1">
	<wpt lat="59.3293" lon="18.0686">
		<name>&lol9;</name>
	</wpt>
	<trk>
		<trkseg>
			<trkpt lat="59.3293" lon="18.0686"><ele>10.0</ele></trkpt>
			<trkpt lat="59.3300" lon="18.0700"><ele>11.0</ele></trkpt>
		</trkseg>
	</trk>
</gpx>
XML;
	file_put_contents( $bomb_path, $bomb_xml );

	$parser             = new Gpx_Parser();
	$peak_memory_before = memory_get_peak_usage();
	$start_time         = microtime( true );

	try {

		// Either return cleanly or throw — both are acceptable. What matters is
		// that we do not hang and do not balloon memory.
		try {
			$parser->parse( $bomb_path );
		} catch ( Parser_Exception $e ) {
			expect( $e->getErrorCode() )->toBeIn( [ 'parse-failed', 'no-track', 'too-few-points' ] );
		}

		$elapsed       = microtime( true ) - $start_time;
		$memory_growth = memory_get_peak_usage() - $peak_memory_before;

		// Generous bounds: parsing the bomb itself is a few KB. If we somehow
		// expanded to gigabytes or hung in a loop, these bounds will catch it.
		expect( $elapsed )->toBeLessThan( 2.0 )
			->and( $memory_growth )->toBeLessThan( 50 * 1024 * 1024 );

	} finally {

		@unlink( $bomb_path );

	}

} );

// ---------------------------------------------------------------------------
// Waypoint metadata
// ---------------------------------------------------------------------------

test( 'preserves all metadata fields on a fully populated waypoint', function (): void {

	$parser = new Gpx_Parser();
	$result = $parser->parse( fixture_path( 'waypoint-full.gpx' ) );

	expect( $result->waypoints )->toHaveCount( 1 );

	$wpt = $result->waypoints[0];
	expect( $wpt )->toBeInstanceOf( Waypoint::class )
		->and( $wpt->lat )->toBe( 59.3293 )
		->and( $wpt->lon )->toBe( 18.0686 )
		->and( $wpt->name )->toBe( 'Sergels torg' )
		->and( $wpt->sym )->toBe( 'Flag, Blue' )
		->and( $wpt->type )->toBe( 'Landmark' )
		->and( $wpt->desc )->toBe( 'Central square in Stockholm.' );

} );
