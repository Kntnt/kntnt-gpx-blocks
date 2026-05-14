/**
 * Locale-aware label formatting for the elevation chart's tooltip.
 *
 * Pure functions — no DOM, no React, no SVG. Two exports:
 *
 *   - {@link formatDistance} — chooses the unit on the same m/km
 *     threshold the x-axis ticks use (see `chooseXUnit` in
 *     `./format.ts`), so the tooltip's distance unit and the x-axis
 *     tick labels' unit always agree. Below 2000 m → metres with 0
 *     decimals (and locale-aware digit grouping); 2000 m and above →
 *     km with 1 decimal.
 *   - {@link formatElevation} — always metres, 0 decimals, locale-aware
 *     digit grouping. Step 7 of `docs/elevation-rebuild.md` *Design
 *     rationale* documents the deliberate departure from the spec's
 *     literal m/km switching for line 2: GPX elevation values are
 *     typically 0–4 000 m and users mentally compute elevations in
 *     metres even when a peak is at 2 800 m, so switching to km on
 *     high tracks is actively confusing and breaks the universal
 *     convention in cycling and hiking apps.
 *
 * Both functions reach `Intl.NumberFormat` directly. They take the
 * locale string as an explicit argument so the host (the editor's
 * `chart.tsx` and the frontend's `view.ts`) can pass the resolved
 * locale rather than the formatter having to fall through to
 * `document.documentElement.lang`. That keeps the function pure and
 * trivially testable.
 *
 * @since 1.0.0
 */

/**
 * Threshold (in metres) at which the tooltip's distance label flips
 * from metres to kilometres. Below this value → metres; at or above →
 * kilometres. Same threshold the x-axis uses through `chooseXUnit`.
 *
 * @since 1.0.0
 */
const KM_THRESHOLD = 2000;

/**
 * Formats a distance value for the tooltip's distance row.
 *
 * @since 1.0.0
 *
 * @param distance Distance in metres.
 * @param locale   Locale string (e.g. `'sv-SE'`, `'en-US'`).
 * @return The formatted distance label, with the unit suffix.
 */
export function formatDistance( distance: number, locale: string ): string {
	// Below the m/km threshold: metres with 0 decimals. Digit grouping
	// is enabled so a track at 1234 m reads as `"1 234 m"` (sv-SE) or
	// `"1,234 m"` (en-US) — the locale's natural thousands separator.
	if ( distance < KM_THRESHOLD ) {
		const num = new Intl.NumberFormat( locale, {
			minimumFractionDigits: 0,
			maximumFractionDigits: 0,
		} ).format( distance );
		return `${ num } m`;
	}

	// At or above the threshold: km with 1 decimal. Division by 1000
	// happens here so the formatter sees the kilometre value directly.
	const num = new Intl.NumberFormat( locale, {
		minimumFractionDigits: 1,
		maximumFractionDigits: 1,
	} ).format( distance / 1000 );
	return `${ num } km`;
}

/**
 * Formats an elevation value for the tooltip's elevation row.
 *
 * Always metres, 0 decimals, with locale-aware digit grouping. Never
 * switches to km — see module header for the rationale.
 *
 * @since 1.0.0
 *
 * @param elevation Elevation in metres. Negative values are valid
 *                  (coastal tracks below sea level).
 * @param locale    Locale string (e.g. `'sv-SE'`, `'en-US'`).
 * @return The formatted elevation label, with the ` m` suffix.
 */
export function formatElevation( elevation: number, locale: string ): string {
	const num = new Intl.NumberFormat( locale, {
		minimumFractionDigits: 0,
		maximumFractionDigits: 0,
	} ).format( elevation );
	return `${ num } m`;
}
