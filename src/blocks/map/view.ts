/**
 * GPX Map frontend Interactivity API module.
 *
 * Registers the block's client-side store with the WordPress Interactivity
 * API. At this stub stage the store is empty — the map initialisation callback
 * (`callbacks.initMap`) and cursor-sync logic arrive in a later issue once
 * block attributes and the Leaflet integration are in place.
 *
 * This file is loaded as an ES module via `viewScriptModule` in block.json,
 * which is required by the Interactivity API.
 *
 * @since 1.0.0
 */

import { store } from '@wordpress/interactivity';

// Register the empty store under the plugin's interactivity namespace.
store( 'kntnt-gpx-blocks', {} );
