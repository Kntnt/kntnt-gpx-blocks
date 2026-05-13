/**
 * Locale-aware label formatting for the elevation chart axes.
 *
 * Pure functions — no DOM, no React, no SVG. Convert nice-tick numeric
 * values into the strings shown next to the X and Y axes, and produce
 * the worst-case reference string the margin algorithm measures to
 * size `wRight` and the tick-count algorithm to derive `N_x`.
 *
 * The Y axis always carries metres; the X axis switches between metres
 * and kilometres on a deterministic distance-only threshold pinned by
 * Step 4 of `docs/elevation-rebuild.md`:
 *
 *   - `distance < 2000`  → metres mode.
 *   - `distance >= 2000` → kilometres mode (intermediate labels
 *                          unitless, last label suffixed `" km"`).
 *
 * Locale handling mirrors the PHP-side `Value_Formatter`: decimal and
 * thousand separators follow the site's locale (Swedish in this
 * project), so the editor preview matches what the visitor sees on the
 * rendered page. The locale is read from the `<html lang>` attribute by
 * default but is overridable per call for testability.
 *
 * @since 1.0.0
 */

/**
 * Discriminates the X-axis unit choice. Y is always metres.
 *
 * @since 1.0.0
 */
export type XAxisUnit = 'm' | 'km';

/**
 * Fallback locale used when `document.documentElement.lang` is empty or
 * the call sites does not pass an explicit locale.
 *
 * @since 1.0.0
 */
const FALLBACK_LOCALE = 'sv-SE';

/**
 * Threshold (in metres) at which the X axis flips from metres to
 * kilometres. Tracks shorter than this stay in metres; tracks of
 * exactly this length or longer convert to km. Locked by Step 4's
 * Q2 grilling — decoupling the unit choice from the eventual tick
 * count is what makes the worst-case reference string deterministic.
 *
 * @since 1.0.0
 */
const KM_THRESHOLD = 2000;

/**
 * Resolves the locale used for formatting.
 *
 * Reads `<html lang>` (the standard WordPress front-end + editor
 * attribute) and falls back to {@link FALLBACK_LOCALE} when the
 * attribute is missing or empty. Tests pass an explicit locale and
 * bypass this lookup entirely.
 *
 * @since 1.0.0
 *
 * @param explicit Locale string passed by the caller, or `undefined`
 *                 to fall through to the document and the fallback.
 * @return The resolved locale string.
 */
function resolveLocale( explicit: string | undefined ): string {
	if ( typeof explicit === 'string' && explicit !== '' ) {
		return explicit;
	}
	if ( typeof document !== 'undefined' ) {
		const lang = document.documentElement?.lang;
		if ( typeof lang === 'string' && lang !== '' ) {
			return lang;
		}
	}
	return FALLBACK_LOCALE;
}

/**
 * Formats a single numeric value with the supplied number of fraction
 * digits, using locale-aware decimal separators and no thousands
 * grouping.
 *
 * Grouping is suppressed because tick labels are short and grouped
 * digits add visual noise that distracts from the axis scale. The
 * fraction-digits parameter is both the minimum and the maximum so the
 * caller controls precision exactly.
 *
 * @since 1.0.0
 *
 * @param value          Numeric value to format.
 * @param fractionDigits Number of fraction digits to show (min and max).
 * @param locale         Optional locale override.
 * @return Locale-formatted numeric string.
 */
export function formatNumber(
	value: number,
	fractionDigits: number,
	locale?: string
): string {
	return new Intl.NumberFormat( resolveLocale( locale ), {
		minimumFractionDigits: fractionDigits,
		maximumFractionDigits: fractionDigits,
		useGrouping: false,
	} ).format( value );
}

/**
 * Derives the number of fraction digits to show on a tick label from
 * the nice-step size.
 *
 * Steps ≥ 1 show no decimals; steps in `[0.1, 1)` show one decimal;
 * everything smaller shows two. The thresholds map to the nice-step
 * series `[1, 2, 5] × 10^n` so that adjacent ticks differ in the
 * last visible digit at most.
 *
 * @since 1.0.0
 *
 * @param niceStep The nice-step size in the same unit as the values.
 * @return The number of fraction digits to render for that step.
 */
function fractionDigitsForStep( niceStep: number ): number {
	const absStep = Math.abs( niceStep );
	if ( absStep >= 1 ) {
		return 0;
	}
	if ( absStep >= 0.1 ) {
		return 1;
	}
	return 2;
}

/**
 * Chooses the X-axis unit for a given track distance.
 *
 * The unit is determined by `distance` alone — independent of the
 * eventual tick count — so the margin algorithm can pre-compute a
 * worst-case reference string before `niceTicks` (in `ticks.ts`) is
 * called. See Step 4 *Reference strings* in
 * `docs/elevation-rebuild.md` for the rationale.
 *
 * @since 1.0.0
 *
 * @param distance Track distance in metres.
 * @return The chosen unit for the whole axis.
 */
export function chooseXUnit( distance: number ): XAxisUnit {
	return distance < KM_THRESHOLD ? 'm' : 'km';
}

/**
 * Returns a worst-case X-axis label string keyed on `distance`.
 *
 * The returned string is typographically ≥ the widest label any
 * `niceTicks` call (see `ticks.ts`) against the same `distance` can
 * produce, so measuring it once gives a safe upper bound for `wRight`
 * (in `margins.ts`) and for `N_x` (via the additive `computeTickCount`
 * in `ticks.ts`). All `"8"` digits are chosen typographically because
 * `8` is the widest digit in the proportional fonts the project
 * supports (Inter, Source Sans, Roboto, Open Sans, …).
 *
 * m-mode buffer logic (Step 4 spec):
 *
 *   - `digits = min(4, floor(log10(max(distance, 1))) + 2)` — the `+2`
 *     buffer covers `niceTicks` rounding up to the next decade (e.g.
 *     `distance = 888` → last tick `1000`). The `4` cap is the
 *     hard bound implied by `distance < 2000` ⇒ last tick ≤ `2000`.
 *
 * km-mode buffer logic (Step 4 spec):
 *
 *   - `n = max(1, floor(log10(distance)) - 1)` — the `-1` buffer
 *     covers `niceTicks` rounding past the next power of ten (e.g.
 *     `distance = 9999` → last tick `10 000` → label `"10,0 km"`).
 *
 * @since 1.0.0
 *
 * @param distance Track distance in metres.
 * @param locale   Optional locale override.
 * @return The reference label string.
 */
export function xReferenceString( distance: number, locale?: string ): string {
	if ( distance < KM_THRESHOLD ) {
		const safeDistance = Math.max( distance, 1 );
		const digits = Math.min(
			4,
			Math.floor( Math.log10( safeDistance ) ) + 2
		);
		const num = formatNumber(
			Number.parseInt( '8'.repeat( digits ), 10 ),
			0,
			locale
		);
		return `${ num } m`;
	}

	const n = Math.max( 1, Math.floor( Math.log10( distance ) ) - 1 );
	const num = formatNumber(
		Number.parseFloat( `${ '8'.repeat( n ) }.8` ),
		1,
		locale
	);
	return `${ num } km`;
}

/**
 * Formats the Y-axis tick labels.
 *
 * Y is always metres; precision is derived from the nice step. Every
 * label carries the `" m"` suffix.
 *
 * @since 1.0.0
 *
 * @param values   Nice-tick values in metres.
 * @param niceStep The nice-step size used to generate the values.
 * @param locale   Optional locale override.
 * @return Array of formatted label strings, parallel to `values`.
 */
export function formatYLabels(
	values: readonly number[],
	niceStep: number,
	locale?: string
): readonly string[] {
	const digits = fractionDigitsForStep( niceStep );
	return values.map( ( v ) => `${ formatNumber( v, digits, locale ) } m` );
}

/**
 * Formats the X-axis tick labels.
 *
 * Switches the whole axis to kilometres when {@link chooseXUnit}
 * returns `'km'` for the given `distance`. In metres mode every label
 * carries `" m"`. In kilometres mode values are divided by 1000, shown
 * with one decimal, and only the last label carries the `" km"`
 * suffix — intermediate labels are unitless to keep the axis readable.
 *
 * @since 1.0.0
 *
 * @param values   Nice-tick values in metres, in ascending order.
 * @param niceStep The nice-step size used to generate the values, in
 *                 metres. Used to pick precision in metres mode.
 * @param distance Track distance in metres. Drives the unit choice.
 * @param locale   Optional locale override.
 * @return Array of formatted label strings, parallel to `values`.
 */
export function formatXLabels(
	values: readonly number[],
	niceStep: number,
	distance: number,
	locale?: string
): readonly string[] {
	const unit = chooseXUnit( distance );
	if ( unit === 'm' ) {
		const digits = fractionDigitsForStep( niceStep );
		return values.map(
			( v ) => `${ formatNumber( v, digits, locale ) } m`
		);
	}
	const lastIndex = values.length - 1;
	return values.map( ( v, i ) => {
		const text = formatNumber( v / 1000, 1, locale );
		return i === lastIndex ? `${ text } km` : text;
	} );
}
