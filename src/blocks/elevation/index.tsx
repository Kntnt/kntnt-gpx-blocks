/**
 * GPX Elevation block registration entry point.
 *
 * Imports the Edit component, the block's shared and editor-only stylesheets,
 * and registers the block type with WordPress. The save callback returns null
 * because this is a dynamic block — the server-side render.php produces the
 * frontend HTML on every page load.
 *
 * Stylesheets are imported here so that @wordpress/scripts' webpack config
 * picks them up as part of the editorScript entry and extracts them via
 * MiniCSSExtractPlugin into the files declared in block.json.
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

// Register the block type, wiring the edit component, the inline SVG icon,
// and a null save. The icon overrides the default generic-block placeholder
// in the inserter, List View, breadcrumb, and Document Outline.
registerBlockType( metadata.name, {
	edit: ElevationEdit,
	icon: ElevationIcon,
	save: () => null,
} );
