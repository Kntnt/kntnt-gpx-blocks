<?php
/**
 * Shared predicate for detecting the editor's block-renderer context.
 *
 * Both `Render_Map` and `Render_Elevation` need to know whether their render
 * callback is being invoked by the editor's `<ServerSideRender>` REST endpoint
 * (which proxies the dynamic render under a `REST_REQUEST` with the calling
 * user authenticated and capable of `edit_posts`) so they can emit
 * editor-only behaviour: the Map block sets `bypassConsent: true` in its
 * Interactivity state slice so the JS view module would mount Leaflet without
 * waiting on the consent contract, and the Elevation block server-renders the
 * cursor group visible at fraction = 0.5 with the midpoint sample pre-filled
 * into the tooltip so the editor user gets live feedback for the cursor /
 * tooltip controls. The predicate must pivot in lockstep across both blocks;
 * centralising it here makes that guarantee structural.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rendering;

/**
 * Detects whether the current request is the editor's block-renderer.
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Request_Context {

	/**
	 * Returns true when the current request is the editor's block-renderer.
	 *
	 * The REST block-renderer endpoint invokes dynamic render callbacks inside
	 * a REST request; gating on `edit_posts` excludes anonymous REST callers
	 * from the bypass. All three conditions must hold:
	 *
	 * 1. `REST_REQUEST` is defined.
	 * 2. `REST_REQUEST` is truthy.
	 * 3. The current user has the `edit_posts` capability.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when called from the editor's block-renderer.
	 */
	public static function is_editor_request(): bool {
		return defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_posts' );
	}

}
