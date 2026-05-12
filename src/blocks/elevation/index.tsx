/**
 * GPX Elevation block registration entry point.
 *
 * Registers the block type with WordPress, wiring the minimal Edit component
 * and the inline SVG icon. The block is currently being rebuilt step by step
 * per `docs/elevation-rebuild.md`; this file is the empty-slate baseline of
 * Step 0. The save callback returns null because this is a dynamic block —
 * the server-side render.php produces the frontend HTML.
 *
 * @since 1.0.0
 */

import { registerBlockType } from '@wordpress/blocks';

import { ElevationEdit } from './edit';
import { ElevationIcon } from './icon';
import metadata from './block.json';

registerBlockType( metadata.name, {
	edit: ElevationEdit,
	icon: ElevationIcon,
	save: () => null,
} );
