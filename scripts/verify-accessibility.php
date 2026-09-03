<?php
/**
 * Phase 43: Accessibility contract audit (static checks).
 *
 * The full WCAG 2.2 AA / best-practice matrix is exercised at
 * runtime by `@axe-core/playwright` in `tests-e2e/a11y/` (wired
 * into the e2e CI workflow). This verifier adds the *static*
 * checks: the ones that fail the build before a single page is
 * rendered, because the markup itself is wrong.
 *
 * Contract — every shipped rule is a release blocker if violated:
 *
 *   1. Every `<input>`, `<select>`, `<textarea>` has an accessible
 *      name (a `<label for>` or `aria-label`/`aria-labelledby`).
 *   2. Every `<button>` has either visible text content, an
 *      `aria-label`, or `aria-labelledby`.
 *   3. Every inline `<svg>` has `aria-hidden="true"` or carries
 *      `role="img"` plus an `aria-label`.
 *   4. Every `aria-controls="x"` reference points to an element
 *      with `id="x"` in the same file.
 *   5. No positive `tabindex` (anti-pattern that breaks the
 *      natural reading order).
 *   6. No inline event handlers (`onclick=`, `onkeydown=`, …).
 *   7. No skipped heading levels (`<h1>` then `<h4>` with no
 *      intermediate `<h2>`/`<h3>`).
 *   8. The shipped CSS contains visible `:focus` / `:focus-visible`
 *      styles so keyboard focus is never invisible.
 *
 * The verifier parses each admin partial as HTML and applies the
 * rules. The integration test plants mutations and confirms the
 * gate rejects each one.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir = dirname( __DIR__ );

$partials_dir = $root_dir . '/admin/partials';
$css_dir      = $root_dir . '/admin/css';
$manifest_path = $root_dir . '/dist/accessibility-manifest.json';

$errors = [];

if ( ! is_dir( $partials_dir ) ) {
	fwrite( STDERR, "admin/partials/ not found.\n" );
	exit( 1 );
}

/**
 * Pull the first translatable string literal out of a PHP block.
 * Matches `esc_html_e('X')`, `esc_html__('X')`, `esc_attr_e('X')`,
 * `__('X')`, `_e('X')`, `_x('X')`, plain echo/print of a literal,
 * etc. Single- and double-quoted PHP strings are both supported.
 *
 * Returns the literal as it would appear in the rendered output,
 * with PHP single-quoted escapes (`\'` → `'`, `\\` → `\`) decoded
 * and double-quoted C-style escapes decoded via `stripcslashes`.
 *
 * @return string|null
 */
$first_php_string_literal = static function ( string $block ): ?string {
	// First try double-quoted: "..." with C-style escapes.
	if ( preg_match( '/"((?:\\\\.|[^"\\\\])*)"/', $block, $m ) ) {
		$decoded = stripcslashes( $m[1] );
		return rtrim( $decoded );
	}
	// Then single-quoted: '...' with only \\ and \' escapes.
	if ( preg_match( "/'((?:\\\\.|[^'\\\\])*)'/", $block, $m ) ) {
		$decoded = str_replace( [ "\\'", '\\\\' ], [ "'", '\\' ], $m[1] );
		return $decoded;
	}
	return null;
};

/**
 * Replace each `<?php ... ?>` block with the visible string it
 * would emit. That keeps DOMDocument's `textContent` extraction
 * honest: `<button><?php esc_html_e('Export') ?></button>` parses
 * to a button whose text is "Export", exactly as the browser sees
 * it after PHP executes.
 *
 * When the block contains no quoted literal (a print_r, a function
 * call with no static arg, a comment, etc.) we collapse it to a
 * single space so it doesn't glue surrounding text together.
 */
$php_to_visible = static function ( string $raw ) use ( $first_php_string_literal ): string {
	return (string) preg_replace_callback(
		'/<\?php[\s\S]*?\?>/',
		static function ( array $m ) use ( $first_php_string_literal ): string {
			$literal = $first_php_string_literal( $m[0] );
			return null === $literal ? ' ' : $literal;
		},
		$raw
	);
};

/**
 * Load HTML as a DOMDocument with libxml warnings suppressed so
 * WordPress admin markup (often missing optional tags) does not
 * abort the parse.
 */
$load_html = static function ( string $path ) use ( $php_to_visible ): ?DOMDocument {
	$raw   = (string) file_get_contents( $path );
	$html  = $php_to_visible( $raw );
	// Strip HTML comments too — they would otherwise pull label
	// text from docblock-style "<!-- TODO -->" noise.
	$html  = (string) preg_replace( '/<!--[\s\S]*?-->/', ' ', $html );
	$dom   = new DOMDocument();
	libxml_use_internal_errors( true );
	// Force UTF-8 handling for the parse.
	$loaded = $dom->loadHTML(
		'<?xml encoding="utf-8" ?>' . $html,
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
	);
	libxml_clear_errors();
	if ( ! $loaded ) {
		return null;
	}
	return $dom;
};

/**
 * Compute the accessible name of a node: `aria-labelledby` first,
 * then `aria-label`, then the visible text content.
 *
 * @return string
 */
$accessible_name = static function ( DOMNode $node, DOMDocument $dom ): string {
	if ( $node instanceof DOMElement ) {
		$by_id = $node->getAttribute( 'aria-labelledby' );
		if ( '' !== $by_id ) {
			$parts = [];
			foreach ( preg_split( '/\s+/', $by_id ) as $ref ) {
				$target = $dom->getElementById( $ref );
				if ( $target instanceof DOMElement ) {
					$parts[] = trim( $target->textContent );
				}
			}
			return trim( implode( ' ', $parts ) );
		}
		$aria = trim( $node->getAttribute( 'aria-label' ) );
		if ( '' !== $aria ) {
			return $aria;
		}
	}
	// Fallback: visible text content (skip <script>/<style>).
	$text = preg_replace( '/\s+/', ' ', $node->textContent );
	return trim( (string) $text );
};

/**
 * Walk every interactive element in the DOM and check the
 * accessible-name + label rules.
 *
 * @param string $file Relative file path (for error messages).
 * @param DOMDocument $dom
 */
$check_form_fields = static function ( string $file, DOMDocument $dom ) use ( &$errors, $accessible_name ) {
	$xpath = new DOMXPath( $dom );
	$nodes = $xpath->query( '//input | //select | //textarea' );
	if ( ! $nodes ) {
		return;
	}
	foreach ( $nodes as $node ) {
		if ( ! $node instanceof DOMElement ) {
			continue;
		}
		$type   = strtolower( (string) $node->getAttribute( 'type' ) );
		// `type="hidden"` inputs are deliberately invisible to
		// screen readers and don't need accessible names.
		if ( 'hidden' === $type ) {
			continue;
		}
		$id     = (string) $node->getAttribute( 'id' );
		$name   = (string) $node->getAttribute( 'aria-label' );
		$by_id  = (string) $node->getAttribute( 'aria-labelledby' );

		$has_label = false;
		if ( '' !== $id ) {
			$labels = $xpath->query( sprintf( '//label[@for="%s"]', $id ) );
			if ( $labels && $labels->length > 0 ) {
				$has_label = true;
			}
		}
		// Wrapping <label> (any ancestor, not just the direct
		// parent — the markup legitimately nests the input inside
		// a span.sscribe-toggle-switch inside the label).
		if ( ! $has_label ) {
			$cursor = $node->parentNode;
			while ( $cursor instanceof DOMElement ) {
				if ( 'label' === strtolower( $cursor->tagName ) ) {
					$has_label = true;
					break;
				}
				$cursor = $cursor->parentNode;
			}
		}

		if ( ! $has_label && '' === $name && '' === $by_id ) {
			$errors[] = sprintf(
				'[%s] <%s id="%s"> has no accessible label (missing <label for="%s"> or aria-label/aria-labelledby).',
				$file,
				$node->tagName,
				$id,
				$id
			);
		}
	}
};

$check_buttons = static function ( string $file, DOMDocument $dom ) use ( &$errors, $accessible_name ) {
	$xpath = new DOMXPath( $dom );
	$nodes = $xpath->query( '//button' );
	if ( ! $nodes ) {
		return;
	}
	foreach ( $nodes as $node ) {
		if ( ! $node instanceof DOMElement ) {
			continue;
		}
		$name = $accessible_name( $node, $dom );
		if ( '' === $name ) {
			$errors[] = sprintf(
				'[%s] <button> with id "%s" has no accessible name (empty text content and no aria-label/aria-labelledby).',
				$file,
				(string) $node->getAttribute( 'id' )
			);
		}
	}
};

$check_svgs = static function ( string $file, DOMDocument $dom ) use ( &$errors ) {
	$xpath = new DOMXPath( $dom );
	$nodes = $xpath->query( '//svg' );
	if ( ! $nodes ) {
		return;
	}
	foreach ( $nodes as $node ) {
		if ( ! $node instanceof DOMElement ) {
			continue;
		}
		$aria_hidden = strtolower( (string) $node->getAttribute( 'aria-hidden' ) );
		$role        = strtolower( (string) $node->getAttribute( 'role' ) );
		$aria_label  = trim( (string) $node->getAttribute( 'aria-label' ) );
		// Either decorative (aria-hidden="true") or semantic
		// (role="img" + accessible label).
		$is_decorative = 'true' === $aria_hidden;
		$is_semantic   = 'img' === $role && '' !== $aria_label;
		if ( ! $is_decorative && ! $is_semantic ) {
			$errors[] = sprintf(
				'[%s] <svg> on line "%s" needs aria-hidden="true" (decorative) or role="img" + aria-label (semantic). Got aria-hidden="%s", role="%s", aria-label="%s".',
				$file,
				(string) $node->getAttribute( 'class' ),
				$aria_hidden,
				$role,
				$aria_label
			);
		}
	}
};

$check_aria_controls = static function ( string $file, DOMDocument $dom ) use ( &$errors ) {
	$xpath = new DOMXPath( $dom );
	$nodes = $xpath->query( '//*[@aria-controls]' );
	if ( ! $nodes ) {
		return;
	}
	foreach ( $nodes as $node ) {
		if ( ! $node instanceof DOMElement ) {
			continue;
		}
		$targets = preg_split( '/\s+/', trim( (string) $node->getAttribute( 'aria-controls' ) ) );
		foreach ( (array) $targets as $target_id ) {
			if ( '' === $target_id ) {
				continue;
			}
			if ( ! $dom->getElementById( $target_id ) instanceof DOMElement ) {
				$errors[] = sprintf(
					'[%s] aria-controls="%s" on <%s> does not resolve to any element in the same file.',
					$file,
					$target_id,
					$node->tagName
				);
			}
		}
	}
};

$check_no_positive_tabindex = static function ( string $file, DOMDocument $dom ) use ( &$errors ) {
	$xpath = new DOMXPath( $dom );
	$nodes = $xpath->query( '//*[@tabindex]' );
	if ( ! $nodes ) {
		return;
	}
	foreach ( $nodes as $node ) {
		if ( ! $node instanceof DOMElement ) {
			continue;
		}
		$tabindex = (int) $node->getAttribute( 'tabindex' );
		if ( $tabindex > 0 ) {
			$errors[] = sprintf(
				'[%s] <%s> uses tabindex="%d"; positive tabindex is an anti-pattern that breaks the natural reading order.',
				$file,
				$node->tagName,
				$tabindex
			);
		}
	}
};

$check_no_inline_handlers = static function ( string $file, DOMDocument $dom ) use ( &$errors ) {
	$xpath = new DOMXPath( $dom );
	$events = [
		'onclick', 'onkeydown', 'onkeyup', 'onkeypress',
		'onmousedown', 'onmouseup', 'onmouseover', 'onmouseout',
		'onfocus', 'onblur', 'onchange', 'onsubmit', 'onload',
		'onerror', 'oninput',
	];
	foreach ( $events as $event ) {
		$nodes = $xpath->query( '//*[@' . $event . ']' );
		if ( ! $nodes || $nodes->length === 0 ) {
			continue;
		}
		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			$errors[] = sprintf(
				'[%s] <%s id="%s"> has an inline %s handler; use addEventListener / unobtrusive JS instead.',
				$file,
				$node->tagName,
				(string) $node->getAttribute( 'id' ),
				$event
			);
		}
	}
};

/**
 * Verify the document has a coherent heading hierarchy (no
 * skipped levels). We tolerate any starting level (the WP admin
 * chrome already supplies an <h1>) and flag every skip.
 */
$check_heading_hierarchy = static function ( string $file, DOMDocument $dom ) use ( &$errors ) {
	$xpath = new DOMXPath( $dom );
	$headings = $xpath->query( '//h1 | //h2 | //h3 | //h4 | //h5 | //h6' );
	if ( ! $headings ) {
		return;
	}
	$last_level = 0;
	foreach ( $headings as $h ) {
		if ( ! $h instanceof DOMElement ) {
			continue;
		}
		$level = (int) substr( $h->tagName, 1 );
		if ( 0 === $last_level ) {
			$last_level = $level;
			continue;
		}
		if ( $level > $last_level + 1 ) {
			$errors[] = sprintf(
				'[%s] Heading hierarchy skip: <h%d> follows <h%d> (skipped <h%d>). Use sequential levels so screen-reader navigation works.',
				$file,
				$level,
				$last_level,
				$last_level + 1
			);
		}
		$last_level = $level;
	}
};

$summary = [
	'files_scanned'         => 0,
	'form_fields_observed'  => 0,
	'buttons_observed'      => 0,
	'svgs_observed'         => 0,
	'aria_controls_observed'=> 0,
	'headings_observed'     => 0,
	'errors'                => 0,
];

$iter = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $partials_dir, RecursiveDirectoryIterator::SKIP_DOTS )
);
foreach ( $iter as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}
	$abs     = (string) $file->getRealPath();
	$rel     = ltrim( substr( $abs, strlen( $partials_dir ) + 1 ), './' );
	$rel     = str_replace( '\\', '/', $rel );
	$dom     = $load_html( $abs );
	if ( ! $dom ) {
		continue;
	}
	++$summary['files_scanned'];

	$check_form_fields( $rel, $dom );
	$check_buttons( $rel, $dom );
	$check_svgs( $rel, $dom );
	$check_aria_controls( $rel, $dom );
	$check_no_positive_tabindex( $rel, $dom );
	$check_no_inline_handlers( $rel, $dom );
	$check_heading_hierarchy( $rel, $dom );
}

// -- CSS focus-style audit ----------------------------------------------------

// The shipped CSS must contain visible :focus or :focus-visible
// rules so keyboard focus is never invisible. We scan the CSS
// directory and look for any rule that touches an outline, a
// box-shadow, or a border on a focus pseudo-class.
$focus_selectors_seen = 0;
$focus_visible_styles = 0;
foreach ( (array) glob( $css_dir . '/*.css' ) as $css_file ) {
	$css = (string) file_get_contents( $css_file );
	// Strip block comments to avoid docblock false positives.
	$css = (string) preg_replace( '#/\*[\s\S]*?\*/#', '', $css );
	if ( preg_match_all( '/:focus(-visible|-within)?\b[^{{]*\{([^}]+)\}/', $css, $matches ) ) {
		foreach ( $matches[0] as $idx => $rule ) {
			++$focus_selectors_seen;
			$body = $matches[2][ $idx ];
			if ( preg_match( '/\b(?:outline|box-shadow|border(?:-color|-top|-right|-bottom|-left)?)\b/i', $body ) ) {
				++$focus_visible_styles;
			}
		}
	}
}
if ( $focus_selectors_seen === 0 ) {
	$errors[] = 'admin/css/*.css contains no :focus or :focus-visible rule. Keyboard focus must be visible.';
} elseif ( $focus_visible_styles === 0 ) {
	$errors[] = 'admin/css/*.css contains :focus rules but none of them set outline / box-shadow / border. Keyboard focus is currently invisible.';
}

// -- e2e axe specs must exist ---------------------------------------------

$e2e_a11y_dir = $root_dir . '/tests-e2e/a11y';
if ( ! is_dir( $e2e_a11y_dir ) ) {
	$errors[] = 'tests-e2e/a11y/ is missing. The full @axe-core/playwright matrix lives there.';
} else {
	$has_axe_spec = false;
	foreach ( (array) glob( $e2e_a11y_dir . '/*.spec.ts' ) as $spec ) {
		if ( is_string( $spec ) && false !== strpos( (string) file_get_contents( $spec ), '@axe-core' ) ) {
			$has_axe_spec = true;
			break;
		}
	}
	if ( ! $has_axe_spec ) {
		$errors[] = 'tests-e2e/a11y/ exists but contains no @axe-core/playwright spec. The runtime WCAG 2.2 AA matrix must be enforced.';
	}
}

// -- Report ----------------------------------------------------------------

echo "=== SScribe Accessibility Static Audit ===\n\n";
echo "Files scanned:                {$summary['files_scanned']}\n";
echo ":focus / :focus-visible rules:  {$focus_selectors_seen}\n";
echo "  with visible style:           {$focus_visible_styles}\n\n";
echo 'Errors: ' . count( $errors ) . "\n\n";

if ( ! empty( $errors ) ) {
	foreach ( $errors as $error ) {
		echo "  ✗ {$error}\n";
	}
}

$summary['focus_selectors_seen']    = $focus_selectors_seen;
$summary['focus_visible_styles']    = $focus_visible_styles;
$summary['errors_count']            = count( $errors );
$summary['passes']                  = 0 === count( $errors );
$manifest = [
	'generated_at'             => gmdate( 'c' ),
	'files_scanned'            => $summary['files_scanned'],
	'focus_selectors_seen'     => $focus_selectors_seen,
	'focus_visible_styles'     => $focus_visible_styles,
	'errors_count'             => count( $errors ),
	'passes'                   => $summary['passes'],
	'errors'                   => $errors,
];
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);
echo "Manifest persisted to: {$manifest_path}\n\n";

if ( ! empty( $errors ) ) {
	echo "✗ Accessibility static audit failed.\n";
	exit( 1 );
}
echo "✓ Accessibility static audit passed. No accessible-name gaps, no positive tabindex, no inline handlers, no skipped headings, focus styles are visible, and the e2e axe matrix is in place.\n";
exit( 0 );