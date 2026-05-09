<?php
/**
 * Tests for Rendering\Resolve_Map_Id.
 *
 * Brain Monkey stubs get_post() and parse_blocks() so the resolver can run
 * without a WordPress install. All tests exercise the documented algorithm:
 * auto-resolution, explicit-id resolution, recursive descent, and the three
 * error conditions (no-map, multiple-maps, map-not-found).
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Gpx_Blocks\Rendering\Render_Error;
use Kntnt\Gpx_Blocks\Rendering\Resolve_Map_Id;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Builds a minimal parsed-block array for a GPX Map block.
 *
 * @param int    $attachment_id Attachment ID to embed as the attachmentId attr.
 * @param string $map_id        mapId attribute value.
 *
 * @return array<string, mixed>
 */
function map_block( int $attachment_id, string $map_id = 'map-aaa111' ): array {
	return [
		'blockName'   => 'kntnt-gpx-blocks/map',
		'attrs'       => [
			'attachmentId' => $attachment_id,
			'mapId'        => $map_id,
		],
		'innerBlocks' => [],
	];
}

/**
 * Builds a non-map block (e.g. a group) that wraps inner blocks.
 *
 * @param array<int, array<string, mixed>> $inner_blocks Blocks to nest inside.
 *
 * @return array<string, mixed>
 */
function group_block( array $inner_blocks ): array {
	return [
		'blockName'   => 'core/group',
		'attrs'       => [],
		'innerBlocks' => $inner_blocks,
	];
}

/**
 * Stubs get_post() for a given post ID, returning an object whose
 * post_content equals the supplied string.
 *
 * @param int    $post_id      The post ID to match.
 * @param string $post_content Raw post content.
 */
function stub_post( int $post_id, string $post_content ): void {
	$post               = new stdClass();
	$post->ID           = $post_id;
	$post->post_content = $post_content;

	Functions\when( 'get_post' )->alias(
		static fn ( int $id ): ?object => $id === $post_id ? $post : null
	);
}

/**
 * Stubs parse_blocks() to return a fixed block array regardless of input.
 *
 * @param array<int, array<string, mixed>> $blocks The parsed block tree to return.
 */
function stub_parse_blocks( array $blocks ): void {
	Functions\when( 'parse_blocks' )->justReturn( $blocks );
}

/**
 * Stubs __() to pass the source string through unchanged.
 */
function stub_translate_resolve(): void {
	Functions\when( '__' )->returnArg( 1 );
}

// ---------------------------------------------------------------------------
// Auto-resolve: single map
// ---------------------------------------------------------------------------

test( 'auto resolves to the single configured map', function (): void {

	stub_post( 1, '<!-- wp:kntnt-gpx-blocks/map /-->' );
	stub_parse_blocks( [ map_block( 42, 'map-abc123' ) ] );
	stub_translate_resolve();

	$resolver = new Resolve_Map_Id();
	$result   = $resolver->resolve( 'auto', 1 );

	expect( $result )->toBeArray()
		->and( $result['attachment_id'] )->toBe( 42 )
		->and( $result['map_id'] )->toBe( 'map-abc123' );

} );

// ---------------------------------------------------------------------------
// Auto-resolve with empty string behaves identically to 'auto'
// ---------------------------------------------------------------------------

test( 'empty string resolves like auto when one map exists', function (): void {

	stub_post( 2, '' );
	stub_parse_blocks( [ map_block( 7, 'map-xyz' ) ] );
	stub_translate_resolve();

	$resolver = new Resolve_Map_Id();
	$result   = $resolver->resolve( '', 2 );

	expect( $result )->toBeArray()
		->and( $result['attachment_id'] )->toBe( 7 );

} );

// ---------------------------------------------------------------------------
// Auto-resolve: no maps
// ---------------------------------------------------------------------------

test( 'auto returns no-map when post has no Map block', function (): void {

	stub_post( 3, '' );
	stub_parse_blocks( [] );
	stub_translate_resolve();

	$resolver = new Resolve_Map_Id();
	$result   = $resolver->resolve( 'auto', 3 );

	expect( $result )->toBeInstanceOf( Render_Error::class )
		->and( $result->code )->toBe( 'no-map' );

} );

// ---------------------------------------------------------------------------
// Auto-resolve: multiple maps
// ---------------------------------------------------------------------------

test( 'auto returns multiple-maps when two Map blocks exist', function (): void {

	stub_post( 4, '' );
	stub_parse_blocks( [
		map_block( 10, 'map-aaa' ),
		map_block( 11, 'map-bbb' ),
	] );
	stub_translate_resolve();

	$resolver = new Resolve_Map_Id();
	$result   = $resolver->resolve( 'auto', 4 );

	expect( $result )->toBeInstanceOf( Render_Error::class )
		->and( $result->code )->toBe( 'multiple-maps' );

} );

// ---------------------------------------------------------------------------
// Explicit mapId: match found
// ---------------------------------------------------------------------------

test( 'explicit mapId returns the matching map', function (): void {

	stub_post( 5, '' );
	stub_parse_blocks( [
		map_block( 10, 'map-aaa' ),
		map_block( 11, 'map-bbb' ),
	] );
	stub_translate_resolve();

	$resolver = new Resolve_Map_Id();
	$result   = $resolver->resolve( 'map-bbb', 5 );

	expect( $result )->toBeArray()
		->and( $result['attachment_id'] )->toBe( 11 )
		->and( $result['map_id'] )->toBe( 'map-bbb' );

} );

// ---------------------------------------------------------------------------
// Explicit mapId: no match
// ---------------------------------------------------------------------------

test( 'explicit mapId returns map-not-found when nothing matches', function (): void {

	stub_post( 6, '' );
	stub_parse_blocks( [ map_block( 10, 'map-aaa' ) ] );
	stub_translate_resolve();

	$resolver = new Resolve_Map_Id();
	$result   = $resolver->resolve( 'map-zzz', 6 );

	expect( $result )->toBeInstanceOf( Render_Error::class )
		->and( $result->code )->toBe( 'map-not-found' );

} );

// ---------------------------------------------------------------------------
// Recursion: map nested inside a group block
// ---------------------------------------------------------------------------

test( 'recursion finds a Map block nested inside a group', function (): void {

	stub_post( 7, '' );
	stub_parse_blocks( [
		group_block( [
			map_block( 55, 'map-nested' ),
		] ),
	] );
	stub_translate_resolve();

	$resolver = new Resolve_Map_Id();
	$result   = $resolver->resolve( 'auto', 7 );

	expect( $result )->toBeArray()
		->and( $result['attachment_id'] )->toBe( 55 )
		->and( $result['map_id'] )->toBe( 'map-nested' );

} );

// ---------------------------------------------------------------------------
// Recursion: deeply nested map
// ---------------------------------------------------------------------------

test( 'recursion finds a Map block two levels deep', function (): void {

	stub_post( 8, '' );
	stub_parse_blocks( [
		group_block( [
			group_block( [
				map_block( 77, 'map-deep' ),
			] ),
		] ),
	] );
	stub_translate_resolve();

	$resolver = new Resolve_Map_Id();
	$result   = $resolver->resolve( 'auto', 8 );

	expect( $result )->toBeArray()
		->and( $result['attachment_id'] )->toBe( 77 );

} );

// ---------------------------------------------------------------------------
// Maps with attachmentId 0 are skipped
// ---------------------------------------------------------------------------

test( 'map blocks with attachmentId 0 are not counted', function (): void {

	stub_post( 9, '' );
	// One unconfigured block (id 0) and one configured block (id 42).
	stub_parse_blocks( [
		map_block( 0, 'map-empty' ),
		map_block( 42, 'map-real' ),
	] );
	stub_translate_resolve();

	$resolver = new Resolve_Map_Id();
	$result   = $resolver->resolve( 'auto', 9 );

	expect( $result )->toBeArray()
		->and( $result['attachment_id'] )->toBe( 42 );

} );

// ---------------------------------------------------------------------------
// Empty post content
// ---------------------------------------------------------------------------

test( 'empty post content returns no-map', function (): void {

	stub_post( 10, '' );
	stub_parse_blocks( [] );
	stub_translate_resolve();

	$resolver = new Resolve_Map_Id();
	$result   = $resolver->resolve( 'auto', 10 );

	expect( $result )->toBeInstanceOf( Render_Error::class )
		->and( $result->code )->toBe( 'no-map' );

} );

// ---------------------------------------------------------------------------
// Invalid post ID
// ---------------------------------------------------------------------------

test( 'post_id <= 0 returns no-map without calling get_post', function (): void {

	stub_translate_resolve();
	// get_post should not be called; Brain Monkey will throw if it is called
	// without a stub. We intentionally do not stub it here.

	$resolver = new Resolve_Map_Id();
	$result   = $resolver->resolve( 'auto', 0 );

	expect( $result )->toBeInstanceOf( Render_Error::class )
		->and( $result->code )->toBe( 'no-map' );

} );

// ---------------------------------------------------------------------------
// Non-existent post
// ---------------------------------------------------------------------------

test( 'non-existent post returns no-map', function (): void {

	Functions\when( 'get_post' )->justReturn( null );
	stub_translate_resolve();

	$resolver = new Resolve_Map_Id();
	$result   = $resolver->resolve( 'auto', 999 );

	expect( $result )->toBeInstanceOf( Render_Error::class )
		->and( $result->code )->toBe( 'no-map' );

} );

// ---------------------------------------------------------------------------
// resolve_from_blocks: matches the same algorithm as resolve() but bypasses
// post-content parsing. Used by the editor SSR path to feed in the live block
// tree from the editor — see classes/Rendering/Render_Elevation.php and
// docs/architecture.md.
// ---------------------------------------------------------------------------

test( 'resolve_from_blocks auto resolves to the single configured map', function (): void {

	stub_translate_resolve();

	$resolver = new Resolve_Map_Id();
	$result   = $resolver->resolve_from_blocks( 'auto', [ map_block( 42, 'map-abc123' ) ] );

	expect( $result )->toBeArray()
		->and( $result['attachment_id'] )->toBe( 42 )
		->and( $result['map_id'] )->toBe( 'map-abc123' );

} );

test( 'resolve_from_blocks returns no-map when the tree contains no Map block', function (): void {

	stub_translate_resolve();

	$resolver = new Resolve_Map_Id();
	$result   = $resolver->resolve_from_blocks( 'auto', [] );

	expect( $result )->toBeInstanceOf( Render_Error::class )
		->and( $result->code )->toBe( 'no-map' );

} );

test( 'resolve_from_blocks returns multiple-maps when two configured Maps exist', function (): void {

	stub_translate_resolve();

	$resolver = new Resolve_Map_Id();
	$result   = $resolver->resolve_from_blocks( 'auto', [
		map_block( 10, 'map-aaa' ),
		map_block( 11, 'map-bbb' ),
	] );

	expect( $result )->toBeInstanceOf( Render_Error::class )
		->and( $result->code )->toBe( 'multiple-maps' );

} );

test( 'resolve_from_blocks returns the matching map for an explicit mapId', function (): void {

	stub_translate_resolve();

	$resolver = new Resolve_Map_Id();
	$result   = $resolver->resolve_from_blocks( 'map-bbb', [
		map_block( 10, 'map-aaa' ),
		map_block( 11, 'map-bbb' ),
	] );

	expect( $result )->toBeArray()
		->and( $result['attachment_id'] )->toBe( 11 );

} );

test( 'resolve_from_blocks returns map-not-found for an unknown explicit mapId', function (): void {

	stub_translate_resolve();

	$resolver = new Resolve_Map_Id();
	$result   = $resolver->resolve_from_blocks( 'map-zzz', [ map_block( 10, 'map-aaa' ) ] );

	expect( $result )->toBeInstanceOf( Render_Error::class )
		->and( $result->code )->toBe( 'map-not-found' );

} );

test( 'resolve_from_blocks recurses into innerBlocks', function (): void {

	stub_translate_resolve();

	$resolver = new Resolve_Map_Id();
	$result   = $resolver->resolve_from_blocks( 'auto', [
		group_block( [
			group_block( [
				map_block( 77, 'map-deep' ),
			] ),
		] ),
	] );

	expect( $result )->toBeArray()
		->and( $result['attachment_id'] )->toBe( 77 );

} );

test( 'resolve_from_blocks does not call get_post or parse_blocks', function (): void {

	stub_translate_resolve();
	// Intentionally do NOT stub get_post() or parse_blocks(). Brain Monkey
	// throws if a real WordPress function is called without a stub, so this
	// asserts the editor path is genuinely independent of post lookup.

	$resolver = new Resolve_Map_Id();
	$result   = $resolver->resolve_from_blocks( 'auto', [ map_block( 42, 'map-abc' ) ] );

	expect( $result )->toBeArray()
		->and( $result['attachment_id'] )->toBe( 42 );

} );
