<?php
/**
 * Per-attachment cache for parsed GPX data.
 *
 * Reads and writes the five `_kntnt_gpx_blocks_*` post-meta keys that hold
 * the GeoJSON FeatureCollection, the precomputed statistics, the cache
 * version, the source-file MD5, and the last error code. Provides lazy
 * regeneration on version mismatch, hash mismatch, or first read of an
 * uncached attachment. See docs/caching.md for the full lifecycle.
 *
 * The class is the only place in the plugin that touches WordPress filters
 * for parser limits — every other layer (Gpx_Parser, Statistics_Calculator,
 * Geo_Json_Converter) takes its limits as constructor or method arguments
 * so it stays framework-agnostic and unit-testable in isolation.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Cache;

use Kntnt\Gpx_Blocks\Conversion\Geo_Json_Converter;
use Kntnt\Gpx_Blocks\Conversion\Gpx_Parser;
use Kntnt\Gpx_Blocks\Conversion\Parser_Exception;
use Kntnt\Gpx_Blocks\Conversion\Statistics_Calculator;
use Kntnt\Gpx_Blocks\Plugin;
use Kntnt\Gpx_Blocks\Rendering\Render_Error;

/**
 * Reads, validates, and (when stale) regenerates the per-attachment cache.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Attachment_Cache {

	/**
	 * Default trackpoint cap when the
	 * kntnt_gpx_blocks_max_track_points filter returns a non-integer.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const DEFAULT_MAX_TRACK_POINTS = 50000;

	/**
	 * Default file-size cap (10 MB) when the
	 * kntnt_gpx_blocks_max_file_size_bytes filter returns a non-integer.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const DEFAULT_MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;

	/**
	 * Default climb-hysteresis threshold in metres when the
	 * kntnt_gpx_blocks_climb_threshold_meters filter returns a non-numeric.
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private const DEFAULT_CLIMB_THRESHOLD_METERS = 3.0;

	/**
	 * Constructs the cache with its three injectable collaborators.
	 *
	 * Defaults make the class ergonomic in production wiring; tests inject
	 * their own instances or Mockery doubles via this constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Gpx_Parser            $parser     Parser used during regeneration.
	 * @param Geo_Json_Converter    $converter  GeoJSON converter.
	 * @param Statistics_Calculator $calculator Statistics calculator.
	 */
	public function __construct(
		private readonly Gpx_Parser $parser = new Gpx_Parser(),
		private readonly Geo_Json_Converter $converter = new Geo_Json_Converter(),
		private readonly Statistics_Calculator $calculator = new Statistics_Calculator(),
	) {}

	/**
	 * Returns the cached payload for an attachment, regenerating if stale.
	 *
	 * Read order:
	 *   1. If `_kntnt_gpx_blocks_error` is set, return a Render_Error.
	 *   2. If version meta is missing or below Cache_Version::CURRENT,
	 *      regenerate and re-read.
	 *   3. If source-hash meta does not match the file's current MD5,
	 *      regenerate and re-read.
	 *   4. Decode the stored GeoJSON string and return the shaped array.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id WordPress attachment post ID.
	 *
	 * @return array{
	 *     geojson: array<int|string,mixed>,
	 *     statistics: array<string,float|null>,
	 *     attachment_id: int
	 * }|Render_Error
	 */
	public function get( int $attachment_id ): array|Render_Error {

		// Surface a stored error before doing any other work.
		$error = $this->read_error( $attachment_id );
		if ( null !== $error ) {
			return $error;
		}

		// Regenerate when the cache version is missing or older than the current contract.
		$stored_version = $this->read_version( $attachment_id );
		if ( null === $stored_version || $stored_version < Cache_Version::CURRENT ) {
			$this->regenerate( $attachment_id );
			$error = $this->read_error( $attachment_id );
			if ( null !== $error ) {
				return $error;
			}
		}

		// Regenerate when the file's bytes have changed since the last conversion.
		$file_path = $this->resolve_file_path( $attachment_id );
		if ( null === $file_path ) {
			return new Render_Error( 'file-missing', $this->error_message_for( 'file-missing' ) );
		}
		$stored_hash = $this->read_source_hash( $attachment_id );
		$current_hash = md5_file( $file_path );
		if ( false === $current_hash || $stored_hash !== $current_hash ) {
			$this->regenerate( $attachment_id );
			$error = $this->read_error( $attachment_id );
			if ( null !== $error ) {
				return $error;
			}
		}

		// Decode the cached payload into the shape that render functions consume.
		return $this->compose_payload( $attachment_id );

	}

	/**
	 * Runs the full conversion pipeline and persists the result.
	 *
	 * On parser failure, sets `_kntnt_gpx_blocks_error` to the exception's
	 * error code and logs at ERROR level. On success, writes the four data
	 * meta keys atomically (delete-then-set order on the error key so it does
	 * not survive a successful regeneration) and logs at INFO level.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id WordPress attachment post ID.
	 */
	public function regenerate( int $attachment_id ): void {

		// Resolve filter-driven limits once per regeneration so the parser, size
		// guard, and statistics calculator all see the same configuration.
		$points_default = self::DEFAULT_MAX_TRACK_POINTS;
		$size_default   = self::DEFAULT_MAX_FILE_SIZE_BYTES;
		$climb_default  = self::DEFAULT_CLIMB_THRESHOLD_METERS;
		$points_raw = apply_filters( 'kntnt_gpx_blocks_max_track_points', $points_default );
		$size_raw   = apply_filters( 'kntnt_gpx_blocks_max_file_size_bytes', $size_default );
		$climb_raw  = apply_filters( 'kntnt_gpx_blocks_climb_threshold_meters', $climb_default );
		$max_points = is_int( $points_raw ) ? $points_raw : $points_default;
		$max_file_size = is_int( $size_raw ) ? $size_raw : $size_default;
		$climb_threshold = is_numeric( $climb_raw ) ? (float) $climb_raw : $climb_default;

		// Locate the file. Missing attachments and missing files share a single error code.
		$file_path = $this->resolve_file_path( $attachment_id );
		if ( null === $file_path ) {
			$this->record_error( $attachment_id, 'file-missing' );
			return;
		}

		// Reject files above the configured cap before any parsing work.
		$size = filesize( $file_path );
		if ( false === $size || $size > $max_file_size ) {
			$this->record_error( $attachment_id, 'too-large' );
			return;
		}

		// Capture the file's MD5 once per regeneration so a concurrent FTP overwrite
		// doesn't poison the cached hash.
		$hash = md5_file( $file_path );
		if ( false === $hash ) {
			$this->record_error( $attachment_id, 'file-missing' );
			return;
		}

		// Run the conversion pipeline; parser failures get persisted and logged.
		try {
			$track       = $this->parser->parse( $file_path, $max_points );
			$geojson     = $this->converter->convert( $track );
			$statistics  = $this->calculator->calculate( $track, $climb_threshold );
		} catch ( Parser_Exception $exception ) {
			$this->record_error( $attachment_id, $exception->getErrorCode() );
			return;
		}

		// Persist the four data keys and clear any stale error from a previous failure.
		$this->persist_success( $attachment_id, $geojson, $statistics, $hash );

		// One info-level line per successful regeneration; keeps the log greppable.
		Plugin::info( sprintf( 'Regenerated cache for attachment %d (hash %s)', $attachment_id, $hash ) );

	}

	/**
	 * Persists a successful conversion to post-meta.
	 *
	 * @since 1.0.0
	 *
	 * @param int                      $attachment_id Attachment ID.
	 * @param array<string,mixed>      $geojson       FeatureCollection from the converter.
	 * @param array<string,float|null> $statistics    Statistics from the calculator.
	 * @param string                   $hash          MD5 of the file at conversion time.
	 */
	private function persist_success( int $attachment_id, array $geojson, array $statistics, string $hash ): void {

		// Encode the GeoJSON for storage as a string. Attachments with NaN/Inf
		// values would already have been rejected upstream, so a JSON-encode
		// failure here is unexpected; treat as 'parse-failed' for symmetry.
		$encoded = wp_json_encode( $geojson );
		if ( false === $encoded ) {
			$this->record_error( $attachment_id, 'parse-failed' );
			return;
		}

		// Write the four data meta keys, then drop any stale error from a prior failure.
		update_post_meta( $attachment_id, '_kntnt_gpx_blocks_geojson', $encoded );
		update_post_meta( $attachment_id, '_kntnt_gpx_blocks_statistics', $statistics );
		update_post_meta( $attachment_id, '_kntnt_gpx_blocks_version', Cache_Version::CURRENT );
		update_post_meta( $attachment_id, '_kntnt_gpx_blocks_source_hash', $hash );
		delete_post_meta( $attachment_id, '_kntnt_gpx_blocks_error' );

	}

	/**
	 * Records a regeneration failure on the attachment and logs it.
	 *
	 * Leaves the four data meta keys untouched, per docs/caching.md
	 * § "Error states": the previous successful conversion's data, if any,
	 * remains so the render layer can decide how to surface the error.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $code          Error code from the cache vocabulary.
	 */
	private function record_error( int $attachment_id, string $code ): void {

		update_post_meta( $attachment_id, '_kntnt_gpx_blocks_error', $code );
		Plugin::error( sprintf( 'Cache regeneration failed for attachment %d: %s', $attachment_id, $code ) );

	}

	/**
	 * Reads `_kntnt_gpx_blocks_error` and wraps it in a Render_Error when set.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return Render_Error|null
	 */
	private function read_error( int $attachment_id ): ?Render_Error {

		$raw = get_post_meta( $attachment_id, '_kntnt_gpx_blocks_error', true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		return new Render_Error( $raw, $this->error_message_for( $raw ) );

	}

	/**
	 * Reads `_kntnt_gpx_blocks_version` as a positive integer, or null when absent.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return int|null
	 */
	private function read_version( int $attachment_id ): ?int {

		$raw = get_post_meta( $attachment_id, '_kntnt_gpx_blocks_version', true );
		if ( '' === $raw || null === $raw ) {
			return null;
		}

		return is_numeric( $raw ) ? (int) $raw : null;

	}

	/**
	 * Reads `_kntnt_gpx_blocks_source_hash` as a 32-char string, or null when absent.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return string|null
	 */
	private function read_source_hash( int $attachment_id ): ?string {

		$raw = get_post_meta( $attachment_id, '_kntnt_gpx_blocks_source_hash', true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		return $raw;

	}

	/**
	 * Decodes the stored GeoJSON and bundles it with the statistics array.
	 *
	 * Falls through to a 'parse-failed' Render_Error when the stored JSON is
	 * malformed — it should not be, but the safety net keeps the render layer
	 * from blowing up on a corrupt meta value.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return array{
	 *     geojson: array<int|string,mixed>,
	 *     statistics: array<string,float|null>,
	 *     attachment_id: int
	 * }|Render_Error
	 */
	private function compose_payload( int $attachment_id ): array|Render_Error {

		// Read both data meta keys, then validate their shapes before composing.
		$geojson_raw  = get_post_meta( $attachment_id, '_kntnt_gpx_blocks_geojson', true );
		$statistics   = get_post_meta( $attachment_id, '_kntnt_gpx_blocks_statistics', true );

		if ( ! is_string( $geojson_raw ) || '' === $geojson_raw ) {
			return new Render_Error( 'parse-failed', $this->error_message_for( 'parse-failed' ) );
		}
		if ( ! is_array( $statistics ) ) {
			return new Render_Error( 'parse-failed', $this->error_message_for( 'parse-failed' ) );
		}

		// Decode the GeoJSON; a corrupt payload is exposed as a parse failure.
		$decoded = json_decode( $geojson_raw, true );
		if ( ! is_array( $decoded ) ) {
			return new Render_Error( 'parse-failed', $this->error_message_for( 'parse-failed' ) );
		}

		// Narrow the statistics array to the documented shape; an unexpected
		// shape (e.g. legacy meta from a pre-1.0 install) surfaces as a
		// parse failure so the render layer reports it instead of crashing.
		$normalised = $this->normalise_statistics( $statistics );
		if ( null === $normalised ) {
			return new Render_Error( 'parse-failed', $this->error_message_for( 'parse-failed' ) );
		}

		return [
			'geojson'       => $decoded,
			'statistics'    => $normalised,
			'attachment_id' => $attachment_id,
		];

	}

	/**
	 * Validates a stored statistics array against the documented shape.
	 *
	 * Returns the same array narrowed to `array<string,float|null>` when the
	 * five expected keys are present and each carries a float-or-null. Returns
	 * null when the input does not match — the render layer treats that as a
	 * parse failure.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int|string,mixed> $raw Raw post-meta value.
	 *
	 * @return array<string,float|null>|null
	 */
	private function normalise_statistics( array $raw ): ?array {

		// Walk the five expected keys and verify each value is float-or-null.
		$keys       = [ 'distance', 'min_elevation', 'max_elevation', 'ascent', 'descent' ];
		$normalised = [];
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				return null;
			}
			$value = $raw[ $key ];
			if ( null !== $value && ! is_float( $value ) && ! is_int( $value ) ) {
				return null;
			}
			$normalised[ $key ] = null === $value ? null : (float) $value;
		}

		return $normalised;

	}

	/**
	 * Resolves the absolute file path for an attachment, or null when absent.
	 *
	 * `get_attached_file()` returns the canonicalised path managed by
	 * WordPress; the existence check is the I/O boundary and the only place
	 * the cache layer touches the filesystem directly.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return string|null
	 */
	private function resolve_file_path( int $attachment_id ): ?string {

		$path = get_attached_file( $attachment_id );
		if ( ! is_string( $path ) || '' === $path ) {
			return null;
		}
		if ( ! is_file( $path ) ) {
			return null;
		}

		return $path;

	}

	/**
	 * Returns a localised, human-readable diagnostic for a cache error code.
	 *
	 * The render layer is expected to map codes to richer presentation; the
	 * message returned here is a stable fallback usable in logs and editor
	 * previews.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code Cache error code.
	 *
	 * @return string
	 */
	private function error_message_for( string $code ): string {

		// One match expression covers the documented vocabulary; the default
		// arm guards against an unknown future code surviving in stored meta.
		return match ( $code ) {
			'no-track'        => __( 'The GPX file contains no track or route.', 'kntnt-gpx-blocks' ),
			'too-few-points'  => __( 'The GPX file has too few valid points to render.', 'kntnt-gpx-blocks' ),
			'too-large'       => __( 'The GPX file is too large to process.', 'kntnt-gpx-blocks' ),
			'file-missing'    => __( 'The GPX file is missing on disk.', 'kntnt-gpx-blocks' ),
			'parse-failed'    => __( 'The GPX file could not be parsed.', 'kntnt-gpx-blocks' ),
			'wrong-mime'      => __( 'The file is not a valid GPX document.', 'kntnt-gpx-blocks' ),
			default           => __( 'The GPX file could not be processed.', 'kntnt-gpx-blocks' ),
		};

	}

}
