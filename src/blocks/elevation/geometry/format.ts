/**
 * Locale-aware label formatting for the elevation chart axes.
 *
 * Pure functions — no DOM, no React, no SVG. Convert nice-tick numeric
 * values into the strings shown next to the X and Y axes.
 *
 * The Y axis always carries metres; the X axis switches between metres
 * and kilometres on the per-axis rule from Step 4 of
 * `docs/elevation-rebuild.md`: if more than half of the non-zero tick
 * values are ≥ 1000 m, the whole axis converts to kilometres, only the
 * last label carries the `" km"` suffix, and intermediate labels are
 * unitless.
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
 * Chooses the X-axis unit for a set of nice-tick values.
 *
 * Counts the non-zero values whose magnitude is ≥ 1000 m. The whole
 * axis converts to kilometres iff more than half of the non-zero
 * values pass the threshold. When the value list is empty or contains
 * only zero, the axis stays in metres so a degenerate (zero-distance)
 * chart still renders consistent labels.
 *
 * @since 1.0.0
 *
 * @param values Nice-tick values in metres, in ascending order.
 * @return The chosen unit for the whole axis.
 */
export function chooseXUnit( values: readonly number[] ): XAxisUnit {
	const nonZero = values.filter( ( v ) => v !== 0 );
	if ( nonZero.length === 0 ) {
		return 'm';
	}
	const big = nonZero.filter( ( v ) => Math.abs( v ) >= 1000 ).length;
	return big > nonZero.length / 2 ? 'km' : 'm';
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
 * returns `'km'`. In metres mode every label carries `" m"`. In
 * kilometres mode values are divided by 1000, shown with one decimal,
 * and only the last label carries the `" km"` suffix — intermediate
 * labels are unitless to keep the axis readable.
 *
 * @since 1.0.0
 *
 * @param values   Nice-tick values in metres, in ascending order.
 * @param niceStep The nice-step size used to generate the values, in
 *                 metres. Used to pick precision in metres mode.
 * @param locale   Optional locale override.
 * @return Array of formatted label strings, parallel to `values`.
 */
export function formatXLabels(
	values: readonly number[],
	niceStep: number,
	locale?: string
): readonly string[] {
	const unit = chooseXUnit( values );
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
