<?php
/**
 * Renders error notices for the three GPX blocks.
 *
 * Centralises the visibility policy: editors with `edit_posts` see a styled
 * notice with the error message and code; visitors without the capability see
 * nothing.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Gpx_Blocks\Rendering;

/**
 * Converts a Render_Error into editor-visible HTML (or empty string for visitors).
 *
 * @package Kntnt\Gpx_Blocks
 * @since 1.0.0
 */
final class Error_Renderer {

	/**
	 * Renders the error as a styled notice for editors, or empty for visitors.
	 *
	 * Users without `edit_posts` capability see an empty string — the block is
	 * invisible on the frontend. Users with the capability see a
	 * `.kntnt-gpx-blocks-error` div with role="alert" containing the message
	 * and the machine-readable code.
	 *
	 * @since 1.0.0
	 *
	 * @param Render_Error $error The error to surface.
	 *
	 * @return string Escaped HTML or empty string.
	 */
	public function render( Render_Error $error ): string {

		// Non-editors see nothing — error details must not leak to visitors.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		// Emit a visually distinct, accessible error notice for the editor.
		return sprintf(
			'<div class="kntnt-gpx-blocks-error" role="alert">'
				. '<p><strong>Kntnt GPX Blocks:</strong> %s <code>(code: %s)</code></p>'
				. '</div>',
			esc_html( $error->message ),
			esc_html( $error->code ),
		);

	}

}
