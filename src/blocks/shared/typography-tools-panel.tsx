/**
 * Shared TypographyToolsPanel component.
 *
 * Renders a unified Typography `ToolsPanel` matching the surface used
 * by core Paragraph / Group blocks: a per-aspect dropdown lets editors
 * enable or disable each aspect individually, and "Reset all" returns
 * every aspect to the inherited theme default.
 *
 * The seven inspector controls (Font, Size, Appearance, Line height,
 * Letter spacing, Decoration, Letter case) map to eight attribute
 * suffixes — Appearance pairs `FontWeight` + `FontStyle`. The component
 * reads and writes each attribute through `attributes[ ${prefix}${suffix} ]`
 * /  `setAttributes( { [ ${prefix}${suffix} ]: value } )`, so hosts
 * differ only in the `prefix` they pass in.
 *
 * The `defaultVisibility` prop controls which items show at the top of
 * the panel vs. living behind the ellipsis menu, but core remembers the
 * editor's per-item preference once they reveal an item, so the prop
 * only seeds the first-mount state.
 *
 * @since 1.0.0
 */

import {
	useSettings,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalFontFamilyControl as FontFamilyControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalFontAppearanceControl as FontAppearanceControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalLetterSpacingControl as LetterSpacingControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalTextDecorationControl as TextDecorationControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalTextTransformControl as TextTransformControl,
	LineHeightControl,
} from '@wordpress/block-editor';
import {
	FontSizePicker,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToolsPanel as ToolsPanel,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToolsPanelItem as ToolsPanelItem,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { flattenPresets } from './flatten-presets';

/**
 * Logical aspects exposed by the typography panel. Used as the key set
 * for the `defaultVisibility` prop.
 *
 * @since 1.0.0
 */
export type TypographyAspect =
	| 'font'
	| 'size'
	| 'appearance'
	| 'lineHeight'
	| 'letterSpacing'
	| 'letterCase'
	| 'decoration';

/**
 * Theme preset entry shape returned by `useSettings('typography.fontFamilies')`.
 *
 * @since 1.0.0
 */
interface FontFamilyPreset {
	name: string;
	slug: string;
	fontFamily: string;
}

/**
 * Theme preset entry shape returned by `useSettings('typography.fontSizes')`.
 *
 * @since 1.0.0
 */
interface FontSizePreset {
	name: string;
	slug: string;
	size: string;
}

/**
 * Props for {@link TypographyToolsPanel}.
 *
 * @since 1.0.0
 */
interface TypographyToolsPanelProps {
	title: string;
	prefix: string;
	attributes: Record< string, unknown >;
	setAttributes: ( next: Record< string, unknown > ) => void;
	defaultVisibility: Partial< Record< TypographyAspect, boolean > >;
	panelId: string;
}

/**
 * Reads `attributes[ ${prefix}${suffix} ]` and coerces it to a string.
 *
 * Non-string entries (and missing keys) become `''`, which the
 * controls treat as "not set". Coercing here keeps the per-control
 * value props uncluttered.
 *
 * @since 1.0.0
 *
 * @param attributes Saved block attribute bag.
 * @param prefix     Attribute-name prefix (e.g. `tickLabel`).
 * @param suffix     Attribute-name suffix (e.g. `FontFamily`).
 * @return The composed-key value, or `''` when missing / non-string.
 */
function readAttr(
	attributes: Record< string, unknown >,
	prefix: string,
	suffix: string
): string {
	const value = attributes[ `${ prefix }${ suffix }` ];
	return typeof value === 'string' ? value : '';
}

/**
 * Resolves the visibility of a single aspect.
 *
 * Aspects not listed in `defaultVisibility` fall back to hidden, which
 * matches the spec's `-` notation for items the user must reveal from
 * the ellipsis menu.
 *
 * @since 1.0.0
 *
 * @param defaults Caller-supplied `defaultVisibility` map.
 * @param aspect   Aspect to look up.
 * @return `true` when the aspect should be shown at first mount.
 */
function isVisible(
	defaults: Partial< Record< TypographyAspect, boolean > >,
	aspect: TypographyAspect
): boolean {
	return defaults[ aspect ] === true;
}

/**
 * Renders the unified Typography ToolsPanel.
 *
 * @since 1.0.0
 *
 * @param props                   See {@link TypographyToolsPanelProps}.
 * @param props.title             Translated panel title.
 * @param props.prefix            Attribute-name prefix (e.g. `tickLabel`).
 * @param props.attributes        Saved block attribute bag.
 * @param props.setAttributes     Standard Gutenberg attribute setter.
 * @param props.defaultVisibility Per-aspect visibility seed.
 * @param props.panelId           Stable id used by ToolsPanel to scope
 *                                its per-item ResetAll behaviour.
 */
export function TypographyToolsPanel( {
	title,
	prefix,
	attributes,
	setAttributes,
	defaultVisibility,
	panelId,
}: TypographyToolsPanelProps ): JSX.Element {
	// Pull the merged theme typography presets so the panel exposes the same
	// Standard/preset choices as core Paragraph/Group. `useSettings` returns
	// the origin-keyed `{ default, theme, custom }` shape for multi-origin
	// settings; the underlying controls iterate with `.map()`, so flatten to
	// a plain array before forwarding.
	const [ themeFontFamilies, themeFontSizes ] = useSettings(
		'typography.fontFamilies',
		'typography.fontSizes'
	);
	const fontFamilies =
		flattenPresets< FontFamilyPreset >( themeFontFamilies );
	const fontSizes = flattenPresets< FontSizePreset >( themeFontSizes );

	// Current attribute values for every aspect this panel manages. The
	// component is the single source of truth for the prefix → attribute
	// mapping — every read and write goes through `${prefix}${suffix}`.
	const fontFamily = readAttr( attributes, prefix, 'FontFamily' );
	const fontSize = readAttr( attributes, prefix, 'FontSize' );
	const fontWeight = readAttr( attributes, prefix, 'FontWeight' );
	const fontStyle = readAttr( attributes, prefix, 'FontStyle' );
	const lineHeight = readAttr( attributes, prefix, 'LineHeight' );
	const letterSpacing = readAttr( attributes, prefix, 'LetterSpacing' );
	const textDecoration = readAttr( attributes, prefix, 'TextDecoration' );
	const textTransform = readAttr( attributes, prefix, 'TextTransform' );
	const hasAppearance = fontWeight !== '' || fontStyle !== '';

	// Per-aspect setter. Centralising the write here keeps the prefix →
	// attribute mapping in one place, so the Step 8 Map migration only
	// needs to flip the `prefix` value through and is otherwise a no-op.
	const writeOne = ( suffix: string, value: string ): void => {
		setAttributes( { [ `${ prefix }${ suffix }` ]: value } );
	};
	const writeAppearance = ( weight: string, style: string ): void => {
		setAttributes( {
			[ `${ prefix }FontWeight` ]: weight,
			[ `${ prefix }FontStyle` ]: style,
		} );
	};

	return (
		// @ts-ignore — ToolsPanel typings lag the runtime API.
		<ToolsPanel
			label={ title }
			panelId={ panelId }
			resetAll={ () => {
				setAttributes( {
					[ `${ prefix }FontFamily` ]: '',
					[ `${ prefix }FontSize` ]: '',
					[ `${ prefix }FontWeight` ]: '',
					[ `${ prefix }FontStyle` ]: '',
					[ `${ prefix }LineHeight` ]: '',
					[ `${ prefix }LetterSpacing` ]: '',
					[ `${ prefix }TextDecoration` ]: '',
					[ `${ prefix }TextTransform` ]: '',
				} );
			} }
		>
			{ /* @ts-ignore — ToolsPanelItem typings lag the runtime API. */ }
			<ToolsPanelItem
				panelId={ panelId }
				hasValue={ () => fontFamily !== '' }
				label={ __( 'Font', 'kntnt-gpx-blocks' ) }
				onDeselect={ () => writeOne( 'FontFamily', '' ) }
				isShownByDefault={ isVisible( defaultVisibility, 'font' ) }
			>
				<FontFamilyControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					value={ fontFamily }
					fontFamilies={ fontFamilies }
					onChange={ ( value: string | undefined ) =>
						writeOne( 'FontFamily', value ?? '' )
					}
				/>
			</ToolsPanelItem>
			{ /* @ts-ignore — ToolsPanelItem typings lag the runtime API. */ }
			<ToolsPanelItem
				panelId={ panelId }
				hasValue={ () => fontSize !== '' }
				label={ __( 'Size', 'kntnt-gpx-blocks' ) }
				onDeselect={ () => writeOne( 'FontSize', '' ) }
				isShownByDefault={ isVisible( defaultVisibility, 'size' ) }
			>
				<FontSizePicker
					__next40pxDefaultSize
					value={ fontSize || undefined }
					fontSizes={ fontSizes }
					onChange={ ( value: number | string | undefined ) =>
						writeOne(
							'FontSize',
							value !== undefined && value !== ''
								? String( value )
								: ''
						)
					}
					withReset={ false }
				/>
			</ToolsPanelItem>
			{ /* @ts-ignore — ToolsPanelItem typings lag the runtime API. */ }
			<ToolsPanelItem
				panelId={ panelId }
				hasValue={ () => hasAppearance }
				label={ __( 'Appearance', 'kntnt-gpx-blocks' ) }
				onDeselect={ () => writeAppearance( '', '' ) }
				isShownByDefault={ isVisible(
					defaultVisibility,
					'appearance'
				) }
			>
				<FontAppearanceControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					hasFontWeights
					hasFontStyles
					value={ {
						fontWeight: fontWeight || undefined,
						fontStyle: fontStyle || undefined,
					} }
					onChange={ ( value: {
						fontWeight?: string;
						fontStyle?: string;
					} ) =>
						writeAppearance(
							value?.fontWeight ?? '',
							value?.fontStyle ?? ''
						)
					}
				/>
			</ToolsPanelItem>
			{ /* @ts-ignore — ToolsPanelItem typings lag the runtime API. */ }
			<ToolsPanelItem
				panelId={ panelId }
				hasValue={ () => lineHeight !== '' }
				label={ __( 'Line height', 'kntnt-gpx-blocks' ) }
				onDeselect={ () => writeOne( 'LineHeight', '' ) }
				isShownByDefault={ isVisible(
					defaultVisibility,
					'lineHeight'
				) }
			>
				<LineHeightControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					__unstableInputWidth="auto"
					value={ lineHeight }
					onChange={ ( value: string | undefined ) =>
						writeOne( 'LineHeight', value ?? '' )
					}
				/>
			</ToolsPanelItem>
			{ /* @ts-ignore — ToolsPanelItem typings lag the runtime API. */ }
			<ToolsPanelItem
				panelId={ panelId }
				hasValue={ () => letterSpacing !== '' }
				label={ __( 'Letter spacing', 'kntnt-gpx-blocks' ) }
				onDeselect={ () => writeOne( 'LetterSpacing', '' ) }
				isShownByDefault={ isVisible(
					defaultVisibility,
					'letterSpacing'
				) }
			>
				<LetterSpacingControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					value={ letterSpacing }
					onChange={ ( value: string | undefined ) =>
						writeOne( 'LetterSpacing', value ?? '' )
					}
				/>
			</ToolsPanelItem>
			{ /* @ts-ignore — ToolsPanelItem typings lag the runtime API. */ }
			<ToolsPanelItem
				panelId={ panelId }
				hasValue={ () => textDecoration !== '' }
				label={ __( 'Decoration', 'kntnt-gpx-blocks' ) }
				onDeselect={ () => writeOne( 'TextDecoration', '' ) }
				isShownByDefault={ isVisible(
					defaultVisibility,
					'decoration'
				) }
			>
				<TextDecorationControl
					value={ textDecoration }
					onChange={ ( value: string | undefined ) =>
						writeOne( 'TextDecoration', value ?? '' )
					}
				/>
			</ToolsPanelItem>
			{ /* @ts-ignore — ToolsPanelItem typings lag the runtime API. */ }
			<ToolsPanelItem
				panelId={ panelId }
				hasValue={ () => textTransform !== '' }
				label={ __( 'Letter case', 'kntnt-gpx-blocks' ) }
				onDeselect={ () => writeOne( 'TextTransform', '' ) }
				isShownByDefault={ isVisible(
					defaultVisibility,
					'letterCase'
				) }
			>
				<TextTransformControl
					value={ textTransform }
					onChange={ ( value: string | undefined ) =>
						writeOne( 'TextTransform', value ?? '' )
					}
				/>
			</ToolsPanelItem>
		</ToolsPanel>
	);
}
