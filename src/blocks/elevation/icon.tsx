/**
 * Inline SVG icon for the GPX Elevation block.
 *
 * A mountain-profile polyline rising and falling across the width of the icon,
 * sitting on a thin horizontal baseline — the same shape the block actually
 * renders. Drawn as `currentColor` strokes so it adapts to the editor's
 * light/dark chrome and to selected/active states. The 24x24 viewBox, 1.5
 * stroke width, round caps/joins, and overall optical density match the GPX
 * Map and GPX Statistics icons so the three read as one cohesive family in
 * the inserter, List View, breadcrumb, and Document Outline.
 *
 * Authored as a React element (not a Dashicon string) so it inherits
 * `currentColor` and ships inline, with no editor-time HTTP fetch.
 *
 * @since 0.4.4
 */

export const ElevationIcon = (
	<svg
		xmlns="http://www.w3.org/2000/svg"
		viewBox="0 0 24 24"
		width="24"
		height="24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.5"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
		focusable="false"
	>
		<path d="M3 17 L7 11 L10 14 L14 5 L18 12 L21 17" />
		<path d="M3 20 H21" />
	</svg>
);
