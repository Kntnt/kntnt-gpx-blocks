/**
 * GPX Elevation frontend Interactivity API module.
 *
 * Registers a no-op `callbacks.initElevation` so the block's
 * `data-wp-init="callbacks.initElevation"` directive resolves cleanly. The SVG
 * chart is fully server-rendered, so there is no DOM building to do here. The
 * cursor-sync watch (`callbacks.onCursorChange`) and the pointermove handler
 * arrive in issue #12.
 *
 * This file is loaded as an ES module via `viewScriptModule` in block.json,
 * which is required by the Interactivity API.
 *
 * @since 1.0.0
 */

import { store } from '@wordpress/interactivity';

// Register the namespace store with a no-op init callback. Both Map and
// Elevation share this namespace; calling store() twice with the same name is
// safe — Interactivity merges their callback dictionaries.
store( 'kntnt-gpx-blocks', {
	callbacks: {
		/**
		 * Mount hook for the elevation chart container.
		 *
		 * The chart itself is server-rendered SVG, so this hook has no DOM
		 * work to do in this slice. It exists so the data-wp-init directive
		 * has a callable target; cursor wiring lands in #12.
		 *
		 * @since 1.0.0
		 */
		initElevation(): void {
			// Intentionally empty — see file-level docblock.
		},
	},
} );
