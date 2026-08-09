// Project stylelint config.
//
// Reproduces the config @wordpress/scripts applies by default — the same
// `scss-stylistic` base and the same `selector-class-pattern` opt-out — so
// defining this file changes nothing except the one deviation below. Without
// it, wp-scripts falls back to its bundled copy and the deviation is lost.
module.exports = {
	extends: '@wordpress/stylelint-config/scss-stylistic',
	rules: {
		// wp-scripts' own opt-out, carried over verbatim.
		'selector-class-pattern': null,

		// Deliberate deviation: a bare `//` line separates paragraphs inside
		// the block comments the coding standard asks for. The rule reads
		// those separators as empty comments; the paragraph style wins.
		'scss/comment-no-empty': null,
	},
};
