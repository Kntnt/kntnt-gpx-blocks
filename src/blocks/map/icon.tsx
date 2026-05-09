/**
 * Inline SVG icon for the GPX Map block.
 *
 * A rounded map-marker pin sitting above a short winding track segment, drawn
 * as `currentColor` strokes so the icon adapts to the editor's light/dark
 * chrome and to selected/active states. The 24x24 viewBox, 1.5 stroke width,
 * round caps/joins, and overall optical density match the GPX Elevation and
 * GPX Statistics icons so the three read as one cohesive family in the
 * inserter, List View, breadcrumb, and Document Outline.
 *
 * Authored as a React element (not a Dashicon string) so it inherits
 * `currentColor` and ships inline, with no editor-time HTTP fetch.
 *
 * @since 0.4.4
 */

export const MapIcon = (
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
		<path d="M12 14 C12 14 17 9.5 17 6.5 A5 5 0 0 0 7 6.5 C7 9.5 12 14 12 14 Z" />
		<circle cx="12" cy="6.5" r="1.5" />
		<path d="M3 19 C6.5 17.5 9.5 21 13 19 C16 17.5 18.5 19.5 21 18.5" />
	</svg>
);
