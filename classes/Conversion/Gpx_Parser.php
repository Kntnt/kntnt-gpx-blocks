<?php
/**
 * Streaming GPX parser hardened against XXE and entity-expansion attacks.
 *
 * Uses XMLReader so memory stays constant regardless of file size. The parser
 * is framework-agnostic: it does not call any WordPress functions and does
 * not read any filters. Caller policy — file size cap, attachment lookup,
 * filter-based trackpoint cap — is applied by Cache\Attachment_Cache (issue
 * #7); this class only knows how to turn a path into a Track_Data or to fail
 * with a precise reason. See docs/security.md § "XML parsing" and
 * docs/caching.md § "Conversion in five steps".
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Conversion;

use XMLReader;

/**
 * Parses a .gpx file into a sanitised Track_Data structure.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Gpx_Parser {

	/**
	 * Parses a GPX file at the given path into a Track_Data.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file_path  Absolute path to a .gpx file on disk.
	 * @param int    $max_points Hard cap on the number of trackpoints accepted.
	 *                           When exceeded, the parser throws 'too-large'.
	 *
	 * @return Track_Data
	 *
	 * @throws Parser_Exception When the file is missing, malformed, has the
	 *                          wrong root element, lacks a track or route,
	 *                          has fewer than two valid points, or exceeds
	 *                          the trackpoint cap.
	 */
	public function parse( string $file_path, int $max_points = 50000 ): Track_Data {

		// Guard the I/O boundary: missing files are a separate failure mode from malformed XML.
		if ( ! is_file( $file_path ) ) {
			throw new Parser_Exception( 'file-missing' );
		}

		// Build the points and waypoints arrays by streaming the document.
		[ $points, $waypoints ] = $this->stream( $file_path, $max_points );

		// Enforce the post-stream invariants documented in docs/caching.md.
		if ( count( $points ) < 2 ) {
			throw new Parser_Exception( 'too-few-points' );
		}

		return new Track_Data( $points, $waypoints );

	}

	/**
	 * Walks the GPX document with XMLReader and collects sanitised records.
	 *
	 * Returns a two-tuple: [ list<Track_Point>, list<Waypoint> ]. Encapsulates
	 * the XMLReader lifecycle and libxml internal-error toggling so that the
	 * caller in parse() stays focused on policy.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file_path  Absolute path to a .gpx file on disk.
	 * @param int    $max_points Hard cap; triggers 'too-large' when exceeded.
	 *
	 * @return array{0: array<int, Track_Point>, 1: array<int, Waypoint>}
	 *
	 * @throws Parser_Exception When parsing fails for any documented reason.
	 */
	private function stream( string $file_path, int $max_points ): array {

		// Capture libxml errors instead of letting them surface as warnings.
		$prior_internal_errors = libxml_use_internal_errors( true );
		libxml_clear_errors();

		// Suppress E_WARNINGs that XMLReader emits via the PHP error mechanism
		// (separate from libxml errors) — e.g. when open() fails on permissions.
		// We detect those failures via the method's return value instead.
		set_error_handler( static fn (): bool => true );

		$reader = new XMLReader();

		try {

			// Opening the file is a separate failure mode from a malformed body.
			$opened = $reader->open( $file_path, null, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR );
			if ( false === $opened ) {
				throw new Parser_Exception( 'parse-failed' );
			}

			// XXE belt-and-suspenders: never substitute entities, never load DTDs.
			$reader->setParserProperty( XMLReader::SUBST_ENTITIES, false );
			$reader->setParserProperty( XMLReader::LOADDTD, false );

			// Advance to the first element node so we can verify the root.
			$this->advance_to_root( $reader );
			if ( 'gpx' !== $reader->localName ) {
				throw new Parser_Exception( 'wrong-mime' );
			}

			// Walk children of <gpx> and dispatch to the per-element collectors.
			[ $points, $waypoints, $saw_track_or_route ] = $this->walk_gpx( $reader, $max_points );

			// A file with only <wpt> and no <trk>/<rte> is meaningless for visualisation.
			if ( ! $saw_track_or_route ) {
				throw new Parser_Exception( 'no-track' );
			}

			// Promote any libxml fatal errors collected during the walk into a parse-failed.
			$this->assert_no_fatal_libxml_errors();

			return [ $points, $waypoints ];

		} finally {

			$reader->close();
			libxml_clear_errors();
			libxml_use_internal_errors( $prior_internal_errors );
			restore_error_handler();

		}

	}

	/**
	 * Advances the reader to the first element node — the root.
	 *
	 * Skips the XML prolog, any DOCTYPE, comments, and whitespace.
	 *
	 * @since 1.0.0
	 *
	 * @param XMLReader $reader Open reader.
	 *
	 * @throws Parser_Exception When reading produces a fatal libxml error.
	 */
	private function advance_to_root( XMLReader $reader ): void {

		while ( $this->safe_read( $reader ) ) {
			if ( XMLReader::ELEMENT === $reader->nodeType ) {
				return;
			}
		}

		// No element node at all — empty or comments-only document.
		throw new Parser_Exception( 'parse-failed' );

	}

	/**
	 * Walks the children of <gpx>, collecting trackpoints and waypoints.
	 *
	 * Returns [ points, waypoints, sawTrackOrRoute ]. Only the first <trk> (or
	 * first <rte> if no <trk> appears) contributes points; subsequent ones are
	 * skipped.
	 *
	 * @since 1.0.0
	 *
	 * @param XMLReader $reader     Reader positioned on <gpx>.
	 * @param int       $max_points Hard cap; triggers 'too-large' when exceeded.
	 *
	 * @return array{0: array<int, Track_Point>, 1: array<int, Waypoint>, 2: bool}
	 *
	 * @throws Parser_Exception When parsing fails for any documented reason.
	 */
	private function walk_gpx( XMLReader $reader, int $max_points ): array {

		// Output accumulators; collect_*() helpers append to them by reference.
		$points    = [];
		$waypoints = [];

		// Track which point-source element we have committed to: 'trk', 'rte', or null.
		$source_kind        = null;
		$saw_track_or_route = false;

		// Descend into <gpx> so the next reads land on its child elements.
		if ( ! $this->safe_read( $reader ) ) {
			return [ $points, $waypoints, false ];
		}

		do {

			// Stop when we re-emerge at the </gpx> close.
			if ( XMLReader::END_ELEMENT === $reader->nodeType && 'gpx' === $reader->localName ) {
				break;
			}

			if ( XMLReader::ELEMENT !== $reader->nodeType ) {
				continue;
			}

			$name = $reader->localName;

			// First <trk> wins. Ignore subsequent <trk> and any <rte> seen later.
			if ( 'trk' === $name ) {

				$saw_track_or_route = true;
				if ( null === $source_kind ) {
					$source_kind = 'trk';
					$this->collect_trk( $reader, $points, $max_points );
					continue;
				}
				$reader->next();
				continue;

			}

			// First <rte> contributes only when no <trk> has been committed to.
			if ( 'rte' === $name ) {

				$saw_track_or_route = true;
				if ( null === $source_kind ) {
					$source_kind = 'rte';
					$this->collect_rte( $reader, $points, $max_points );
					continue;
				}
				$reader->next();
				continue;

			}

			if ( 'wpt' === $name ) {
				$this->collect_wpt( $reader, $waypoints );
				continue;
			}

			// Unknown child of <gpx> (extensions, metadata, etc.) — skip the subtree.
			$reader->next();

		} while ( $this->safe_read( $reader ) );

		return [ $points, $waypoints, $saw_track_or_route ];

	}

	/**
	 * Collects all <trkpt> children under the current <trk>, across <trkseg>s.
	 *
	 * Reader is on the <trk> open tag at entry, and on the matching </trk> at
	 * return. Mutates $points in place to avoid copying the growing list.
	 *
	 * @since 1.0.0
	 *
	 * @param XMLReader               $reader     Reader positioned on <trk>.
	 * @param array<int, Track_Point> $points     Output list, mutated in place.
	 * @param int                     $max_points Hard cap; triggers 'too-large'.
	 *
	 * @throws Parser_Exception When parsing fails for any documented reason.
	 */
	private function collect_trk( XMLReader $reader, array &$points, int $max_points ): void {

		// Empty <trk/> short-circuits without descending.
		if ( $reader->isEmptyElement ) {
			return;
		}

		while ( $this->safe_read( $reader ) ) {

			if ( XMLReader::END_ELEMENT === $reader->nodeType && 'trk' === $reader->localName ) {
				return;
			}

			if ( XMLReader::ELEMENT === $reader->nodeType && 'trkpt' === $reader->localName ) {
				$this->collect_point_into( $reader, $points, $max_points );
			}
		}

	}

	/**
	 * Collects all <rtept> children under the current <rte>.
	 *
	 * @since 1.0.0
	 *
	 * @param XMLReader               $reader     Reader positioned on <rte>.
	 * @param array<int, Track_Point> $points     Output list, mutated in place.
	 * @param int                     $max_points Hard cap; triggers 'too-large'.
	 *
	 * @throws Parser_Exception When parsing fails for any documented reason.
	 */
	private function collect_rte( XMLReader $reader, array &$points, int $max_points ): void {

		if ( $reader->isEmptyElement ) {
			return;
		}

		while ( $this->safe_read( $reader ) ) {

			if ( XMLReader::END_ELEMENT === $reader->nodeType && 'rte' === $reader->localName ) {
				return;
			}

			if ( XMLReader::ELEMENT === $reader->nodeType && 'rtept' === $reader->localName ) {
				$this->collect_point_into( $reader, $points, $max_points );
			}
		}

	}

	/**
	 * Reads attributes and the optional <ele> child of a point element, and
	 * appends a Track_Point when the point is valid. Drops the point silently
	 * otherwise.
	 *
	 * @since 1.0.0
	 *
	 * @param XMLReader               $reader     Reader on <trkpt> or <rtept>.
	 * @param array<int, Track_Point> $points     Output list, mutated in place.
	 * @param int                     $max_points Hard cap; triggers 'too-large'.
	 *
	 * @throws Parser_Exception When parsing fails for any documented reason.
	 */
	private function collect_point_into( XMLReader $reader, array &$points, int $max_points ): void {

		$tag      = $reader->localName;
		$lat_attr = $reader->getAttribute( 'lat' );
		$lon_attr = $reader->getAttribute( 'lon' );

		// The cap applies to every <trkpt>/<rtept> seen, valid or not, to bound work.
		if ( count( $points ) >= $max_points ) {
			throw new Parser_Exception( 'too-large' );
		}

		// Read <ele> when present. The reader may already be on an empty element.
		$ele = $reader->isEmptyElement ? null : $this->read_ele_within( $reader, $tag );

		// Validate coordinates after reading children, so we always advance past the element.
		$lat = $this->parse_coordinate( $lat_attr, -90.0, 90.0 );
		$lon = $this->parse_coordinate( $lon_attr, -180.0, 180.0 );
		if ( null === $lat || null === $lon ) {
			return;
		}

		$points[] = new Track_Point( $lat, $lon, $ele );

	}

	/**
	 * Reads the optional <ele> child of the current point element.
	 *
	 * Reader enters at the open tag of the point and exits at its closing tag.
	 * Returns null when no <ele> child exists or when the value is not a finite
	 * number — the parser silently discards malformed elevation data rather
	 * than rejecting the trackpoint outright.
	 *
	 * @since 1.0.0
	 *
	 * @param XMLReader $reader     Reader on the point element.
	 * @param string    $point_name 'trkpt' or 'rtept' — the matching close tag.
	 *
	 * @return float|null
	 *
	 * @throws Parser_Exception When parsing fails for any documented reason.
	 */
	private function read_ele_within( XMLReader $reader, string $point_name ): ?float {

		$ele = null;

		while ( $this->safe_read( $reader ) ) {

			if ( XMLReader::END_ELEMENT === $reader->nodeType && $point_name === $reader->localName ) {
				return $ele;
			}

			if ( XMLReader::ELEMENT === $reader->nodeType && 'ele' === $reader->localName && null === $ele ) {
				$ele = $this->parse_finite_float( (string) $reader->readString() );
			}
		}

		return $ele;

	}

	/**
	 * Reads a <wpt> element into the waypoints array.
	 *
	 * Drops the waypoint silently when lat/lon are missing or out of range.
	 *
	 * @since 1.0.0
	 *
	 * @param XMLReader            $reader    Reader on <wpt>.
	 * @param array<int, Waypoint> $waypoints Output list, mutated in place.
	 *
	 * @throws Parser_Exception When parsing fails for any documented reason.
	 */
	private function collect_wpt( XMLReader $reader, array &$waypoints ): void {

		$lat_attr = $reader->getAttribute( 'lat' );
		$lon_attr = $reader->getAttribute( 'lon' );
		$lat      = $this->parse_coordinate( $lat_attr, -90.0, 90.0 );
		$lon      = $this->parse_coordinate( $lon_attr, -180.0, 180.0 );

		// Empty <wpt/> with valid attrs still counts; only metadata fields are absent.
		if ( $reader->isEmptyElement ) {
			if ( null !== $lat && null !== $lon ) {
				$waypoints[] = new Waypoint( $lat, $lon, null, null, null, null );
			}
			return;
		}

		$name = null;
		$sym  = null;
		$type = null;
		$desc = null;

		while ( $this->safe_read( $reader ) ) {

			if ( XMLReader::END_ELEMENT === $reader->nodeType && 'wpt' === $reader->localName ) {
				break;
			}

			if ( XMLReader::ELEMENT !== $reader->nodeType ) {
				continue;
			}

			// Match each metadata child against its slot. Unknown children are skipped.
			// readString() yields '' on an empty element, so no special-case is needed.
			$child = $reader->localName;
			$value = (string) $reader->readString();

			match ( $child ) {
				'name' => $name = $value,
				'sym'  => $sym  = $value,
				'type' => $type = $value,
				'desc' => $desc = $value,
				default => null,
			};
		}

		if ( null === $lat || null === $lon ) {
			return;
		}

		$waypoints[] = new Waypoint( $lat, $lon, $name, $sym, $type, $desc );

	}

	/**
	 * Wraps XMLReader::read() so libxml errors during streaming surface as
	 * Parser_Exception rather than warnings.
	 *
	 * @since 1.0.0
	 *
	 * @param XMLReader $reader Open reader.
	 *
	 * @return bool True when a node was read; false at end of document.
	 *
	 * @throws Parser_Exception When reading produces a fatal libxml error.
	 */
	private function safe_read( XMLReader $reader ): bool {

		$ok = $reader->read();
		if ( false === $ok ) {
			$this->assert_no_fatal_libxml_errors();
		}

		return $ok;

	}

	/**
	 * Promotes any pending fatal libxml error into a 'parse-failed' exception.
	 *
	 * @since 1.0.0
	 *
	 * @throws Parser_Exception When parsing fails for any documented reason.
	 */
	private function assert_no_fatal_libxml_errors(): void {

		foreach ( libxml_get_errors() as $error ) {
			if ( LIBXML_ERR_FATAL === $error->level ) {
				libxml_clear_errors();
				throw new Parser_Exception( 'parse-failed' );
			}
		}

	}

	/**
	 * Parses an attribute value into a coordinate float.
	 *
	 * Returns null when the value is missing, non-numeric, NaN, infinite, or
	 * outside the inclusive range. The caller drops the corresponding point or
	 * waypoint when null is returned.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $raw Raw attribute value.
	 * @param float       $min Inclusive lower bound.
	 * @param float       $max Inclusive upper bound.
	 *
	 * @return float|null
	 */
	private function parse_coordinate( ?string $raw, float $min, float $max ): ?float {

		$value = $this->parse_finite_float( $raw ?? '' );
		if ( null === $value ) {
			return null;
		}

		if ( $value < $min || $value > $max ) {
			return null;
		}

		return $value;

	}

	/**
	 * Parses a string into a finite float, or null when invalid.
	 *
	 * Rejects empty strings, NaN, and infinite values. Accepts standard
	 * decimal notation with optional sign and exponent — exactly what
	 * is_numeric() accepts, minus surrounding whitespace which is trimmed
	 * first.
	 *
	 * @since 1.0.0
	 *
	 * @param string $raw Raw value.
	 *
	 * @return float|null
	 */
	private function parse_finite_float( string $raw ): ?float {

		$trimmed = trim( $raw );
		if ( '' === $trimmed || ! is_numeric( $trimmed ) ) {
			return null;
		}

		$value = (float) $trimmed;
		if ( is_nan( $value ) || is_infinite( $value ) ) {
			return null;
		}

		return $value;

	}

}
