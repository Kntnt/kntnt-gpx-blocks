/**
 * GPX Elevation block registration entry point.
 *
 * Imports the Edit component, the block's shared and editor-only
 * stylesheets, and registers the block type with WordPress.
 *
 * Stylesheets are imported here so that @wordpress/scripts' webpack
 * config picks them up as part of the `editorScript` entry and
 * extracts them via MiniCSSExtractPlugin into the files declared in
 * `block.json` (`style-index.css`, `index.css`). The save callback
 * returns `null` because this is a dynamic block — the server-side
 * `render.php` produces the frontend HTML on every page load, and
 * the frontend `view.ts` mounts the chart's SVG client-side.
 *
 * @since 1.0.0
 */

import { registerBlockType } from '@wordpress/blocks';

import { ElevationEdit } from './edit';
import { ElevationIcon } from './icon';
import metadata from './block.json';

// Import stylesheets so webpack extracts them to the build directory.
import './style.scss';
import './editor.scss';

registerBlockType( metadata.name, {
	edit: ElevationEdit,
	icon: ElevationIcon,
	save: () => null,
} );
