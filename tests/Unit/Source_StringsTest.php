<?php
/**
 * Regression lock against British-English spellings in user-facing strings.
 *
 * WordPress core's source-string convention is American English, and the
 * plugin's `.po` workflow relies on that match so translators can reuse
 * core's existing translations. This test scans every PHP, TypeScript, and
 * JavaScript file in the plugin and fails if a British spelling appears
 * inside any translation call (`__`, `_e`, `_x`, `_n`, `esc_html__`, etc.)
 * or inside a `translators:` translator comment. See issue #90.
 *
 * Identifiers (variable names, class names, attribute keys, file names,
 * hook names, CSS class names) and internal code comments are explicitly
 * out of scope — only strings the visitor or editor sees are checked.
 *
 * @package Kntnt\Gpx_Blocks
 * @since   1.0.0
 */

declare( strict_types = 1 );

/**
 * Returns every PHP, TypeScript, and JavaScript file under the source tree.
 *
 * Limited to directories that can contain user-facing strings: `classes/`,
 * `src/`, and `js/`. The build output, vendor, node_modules, and tests
 * themselves are intentionally excluded — the latter so that this very
 * file's British-spelling table does not trip the assertion.
 *
 * @return list<string> Absolute paths.
 */
function source_strings_files(): array {

	$root  = dirname( __DIR__, 2 );
	$dirs  = [ '/classes', '/src', '/js' ];
	$files = [];

	// Walk every source directory recursively, collecting only the file
	// extensions that carry translation calls.
	foreach ( $dirs as $dir ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				$root . $dir,
				RecursiveDirectoryIterator::SKIP_DOTS,
			),
		);
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$ext = $file->getExtension();
			if ( in_array( $ext, [ 'php', 'ts', 'tsx', 'js' ], true ) ) {
				$files[] = $file->getPathname();
			}
		}
	}

	return $files;

}

/**
 * British-English source-string spellings that must not appear in the
 * plugin's user-facing strings.
 *
 * The list intentionally errs on the side of completeness over brevity —
 * adding a word here is cheap, and every false negative would be a
 * translator-facing regression.
 *
 * @return list<string>
 */
function source_strings_british_words(): array {
	return [
		'colour',
		'colours',
		'customise',
		'customised',
		'behaviour',
		'behavioural',
		'organise',
		'organised',
		'organisation',
		'centre',
		'centred',
		'flavour',
		'flavours',
		'favourite',
		'favourites',
		'recognise',
		'recognised',
		'analyse',
		'analysed',
		'utilise',
		'utilised',
		'synchronise',
		'synchronisation',
		'optimise',
		'optimisation',
		'visualise',
		'visualisation',
		'visualising',
		'initialise',
		'initialised',
		'summarise',
		'summarised',
		'summarising',
		'travelled',
		'travelling',
		'cancelled',
		'cancelling',
		'programme',
		'aluminium',
		'dialled',
		'modelling',
		'signalling',
		'defence',
		'cheque',
		'mould',
		'honour',
		'neighbour',
		'fibre',
		'theatre',
		'whilst',
		'amongst',
	];
}

test( 'no British spellings in translation calls or translator comments', function (): void {

	$files          = source_strings_files();
	$british        = source_strings_british_words();
	$alternation    = implode( '|', $british );
	$translation_fn = '(?:__|_e|_x|_ex|_n|_nx|_n_noop|_nx_noop|esc_html__|esc_html_e|esc_html_x|esc_attr__|esc_attr_e|esc_attr_x)';

	// Match British words inside a translation-function call's first
	// (string-literal) argument. The pattern tolerates either quote style
	// and arbitrary leading whitespace before the opening quote.
	$call_pattern = sprintf(
		'/%s\s*\(\s*["\'][^"\']*\b(%s)\b/i',
		$translation_fn,
		$alternation,
	);

	// Match British words inside a translator hint comment — both the
	// `/* translators: ... */` and `// translators: ...` forms WordPress
	// recognises.
	$translator_comment_pattern = sprintf(
		'#(?:/\*\s*translators:|//\s*translators:)[^*\n]*\b(%s)\b#i',
		$alternation,
	);

	$violations = [];

	// Scan every collected source file, recording violations with file +
	// line number so the failure message points the maintainer at the
	// exact location.
	foreach ( $files as $path ) {
		$lines = file( $path, FILE_IGNORE_NEW_LINES );
		if ( $lines === false ) {
			continue;
		}
		foreach ( $lines as $index => $line ) {
			if ( preg_match( $call_pattern, $line, $matches ) === 1 ) {
				$violations[] = sprintf( '%s:%d (translation call: "%s")', $path, $index + 1, $matches[1] );
			}
			if ( preg_match( $translator_comment_pattern, $line, $matches ) === 1 ) {
				$violations[] = sprintf( '%s:%d (translator comment: "%s")', $path, $index + 1, $matches[1] );
			}
		}
	}

	expect( $violations )->toBe(
		[],
		"British spellings found in user-facing strings:\n" . implode( "\n", $violations ),
	);

} );

test( 'no British spellings in block.json title or description', function (): void {

	$root         = dirname( __DIR__, 2 );
	$block_jsons  = [
		$root . '/src/blocks/map/block.json',
		$root . '/src/blocks/elevation/block.json',
	];
	$british      = source_strings_british_words();
	$alternation  = implode( '|', $british );
	$pattern      = sprintf( '/\b(%s)\b/i', $alternation );
	$violations   = [];

	// The `title`, `description`, and `keywords` fields are surfaced in
	// the block inserter and the block's sidebar header, so they share
	// the same American-English contract as `__()` strings.
	foreach ( $block_jsons as $path ) {
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $decoded ) ) {
			continue;
		}
		foreach ( [ 'title', 'description' ] as $key ) {
			$value = $decoded[ $key ] ?? '';
			if ( is_string( $value ) && preg_match( $pattern, $value, $matches ) === 1 ) {
				$violations[] = sprintf( '%s (%s: "%s")', $path, $key, $matches[1] );
			}
		}
		$keywords = $decoded['keywords'] ?? [];
		if ( is_array( $keywords ) ) {
			foreach ( $keywords as $keyword ) {
				if ( is_string( $keyword ) && preg_match( $pattern, $keyword, $matches ) === 1 ) {
					$violations[] = sprintf( '%s (keyword: "%s")', $path, $matches[1] );
				}
			}
		}
	}

	expect( $violations )->toBe(
		[],
		"British spellings found in block.json metadata:\n" . implode( "\n", $violations ),
	);

} );
