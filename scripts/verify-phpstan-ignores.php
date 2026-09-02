<?php
/**
 * Phase 40: PHPStan ignore-rule audit contract.
 *
 * Every ignore rule in `phpstan.neon` must be classified as one of:
 *
 *   - `unavoidable_third_party` — WordPress / mPDF / DOM stubs
 *     missing, dynamic WordPress hooks, etc. These cannot be
 *     removed without losing analysis coverage.
 *   - `technical_debt` — known PHPStan false positives that we
 *     accept because the code is correct under runtime guards
 *     (SSCRIBE_DEBUG, instanceof null, etc.). Each entry is named
 *     in the manifest so the suppression is auditable.
 *
 * A rule that does not fall into either bucket is `stale` and
 * must be removed. A broad pattern (one that ignores an entire
 * directory or namespace) is a release blocker — the spec
 * explicitly forbids "ignore everything in admin/*" or equivalent
 * suppressions.
 *
 * This verifier enforces the contract by:
 *
 *   1. Loading phpstan.neon and extracting every ignoreErrors
 *      entry as a regex string.
 *   2. Classifying each pattern by signature (WP function
 *      stub-missing vs. control-flow predicate vs. known
 *      false-positive bucket).
 *   3. Refusing any pattern whose body looks like a directory
 *      glob (e.g. `^admin/`, `^vendor/`).
 *   4. Confirming PHPStan still passes at Level 7 with the
 *      current rule list (smoke gate).
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir = dirname( __DIR__ );

$config_path = $root_dir . '/phpstan.neon';
if ( ! is_file( $config_path ) ) {
	fwrite( STDERR, "phpstan.neon missing from repo root.\n" );
	exit( 1 );
}

$config = (string) file_get_contents( $config_path );

/**
 * Extract every `ignoreErrors:` entry as a regex string. The
 * entries are written as YAML list items, e.g.
 *
 *   ignoreErrors:
 *       - '#regex#'
 *
 * @return string[]
 */
$extract_rules = static function ( string $config ): array {
	$rules = array();
	if ( ! preg_match_all( '/^\s*-\s*([\'"])(#.+?#)\1\s*$/m', $config, $matches ) ) {
		return $rules;
	}
	foreach ( $matches[2] as $pattern ) {
		$rules[] = $pattern;
	}
	return $rules;
};

$rules = $extract_rules( $config );

echo "=== SScribe PHPStan Ignore-Rule Audit ===\n\n";
echo "Ignore rules declared: " . count( $rules ) . "\n\n";

/**
 * Classify a regex body by signature.
 *
 * @param string $rule_body  Stripped regex body (between `#` delimiters).
 * @return string One of unavoidable_third_party, technical_debt,
 *                unknown, broad_pattern.
 */
$classify = static function ( string $rule_body ): string {
	// Refuse broad directory globs explicitly.
	if ( preg_match( '#^admin/?\*?$#', $rule_body ) ) {
		return 'broad_pattern';
	}
	if ( preg_match( '#^vendor/?\*?$#', $rule_body ) ) {
		return 'broad_pattern';
	}
	if ( preg_match( '#^.+/?\*$#', $rule_body ) && ! str_contains( $rule_body, 'method' ) ) {
		return 'broad_pattern';
	}

	// Third-party / stub-missing signatures. Each entry matches the
	// regex body the way a human would read it — `addCell()` in the
	// body of the ignore rule (which is itself a regex) is matched
	// by the literal substring `addCell\(\)`.
	$third_party_signatures = array(
		'/Function .* not found/',
		'/Constant .* not found/',
		'/Class .* not found/',
		'/unknown class/',
		'/Instantiated class/',
		'/Access to .* property/',
		'/has unknown class/',
		'/on an unknown class/',
		'/no value type specified in iterable type/',
		'/invalid return type WP_Error/',
		'/addCell\(\) expects int/', // mPDF DOMCell.
		'/Call to an undefined method DOMNode/',
		// WordPress apply_filters/do_action accept variadic args;
		// PHPStan stubs don't reflect this.
		'/apply_filters\|do_action\) invoked with/',
		// WP_Filesystem / hook callbacks are declared variadic in
		// the stubs but PHPStan still warns about static-method
		// parameter counts.
		'/Static method .* invoked with \\\\d\+ parameters/',
	);
	foreach ( $third_party_signatures as $signature ) {
		if ( preg_match( $signature, $rule_body ) ) {
			return 'unavoidable_third_party';
		}
	}

	// Known technical-debt signatures. These are runtime guard
	// predicates or PHPStan false positives that the team has
	// explicitly accepted.
	$tech_debt_signatures = array(
		'/If condition is always/',
		'/Ternary operator condition is always/',
		'/Offset .* on .* on left side of \?\?/',
		'/Offset.* in isset\(\)/',
		'/Strict comparison using/',
		'/Property .* is never read/',
		'/Property .* does not accept null/',
		'/Unsafe usage of new static\(\)/',
		'/contains unresolvable type/',
		'/Right side of \\\\|\\\\| is always false/',
		'/Left side of \\\\|\\\\| is always false/',
		'/Negated boolean expression is always true/',
		'/Left side of && is always false/',
		'/Right side of && is always false/',
		'/Result of && is always false/',
		'/Instanceof between null/',
		'/Unreachable statement/',
		'/array_map expects/',
		'/Call to an undefined static method SScribe_/',
	);
	foreach ( $tech_debt_signatures as $signature ) {
		if ( preg_match( $signature, $rule_body ) ) {
			return 'technical_debt';
		}
	}

	return 'unknown';
};

$errors  = array();
$reports = array();

foreach ( $rules as $rule ) {
	$rule_body  = trim( $rule, '#' );
	$classification = $classify( $rule_body );

	if ( 'broad_pattern' === $classification ) {
		$errors[] = "Broad ignore pattern detected: {$rule}";
	}
	if ( 'unknown' === $classification ) {
		$errors[] = "Unknown / unclassified ignore pattern: {$rule}";
	}

	$reports[] = array(
		'pattern'        => $rule,
		'classification' => $classification,
	);
}

echo str_pad( 'PATTERN', 60 ) . str_pad( 'CLASSIFICATION', 28 ) . "\n";
echo str_repeat( '-', 110 ) . "\n";
foreach ( $reports as $r ) {
	echo str_pad( $r['pattern'], 60 )
		. str_pad( $r['classification'], 28 )
		. "\n";
}
echo "\n";

$counts = array(
	'unavoidable_third_party' => 0,
	'technical_debt'          => 0,
	'unknown'                 => 0,
	'broad_pattern'           => 0,
);
foreach ( $reports as $r ) {
	++$counts[ $r['classification'] ];
}

echo "Unavoidable third-party:    {$counts['unavoidable_third_party']}\n";
echo "Technical debt:             {$counts['technical_debt']}\n";
echo "Unknown (must classify):    {$counts['unknown']}\n";
echo "Broad pattern (release block): {$counts['broad_pattern']}\n";
echo "Errors:                     " . count( $errors ) . "\n\n";

if ( ! empty( $errors ) ) {
	echo "Errors:\n";
	foreach ( $errors as $error ) {
		echo "  ✗ {$error}\n";
	}
	echo "\n";
	exit( 1 );
}

// Smoke gate: PHPStan at the configured level must still pass.
echo "Running PHPStan smoke gate at Level 7...\n";
$descriptors = array(
	0 => array( 'pipe', 'r' ),
	1 => array( 'pipe', 'w' ),
	2 => array( 'pipe', 'w' ),
);
$process = proc_open(
	array( PHP_BINARY, $root_dir . '/vendor/bin/phpstan', 'analyse', '--memory-limit=512M', '--no-progress' ),
	$descriptors,
	$pipes
);
if ( ! is_resource( $process ) ) {
	fwrite( STDERR, "Failed to launch PHPStan smoke gate.\n" );
	exit( 1 );
}
$stdout = (string) stream_get_contents( $pipes[1] );
$stderr = (string) stream_get_contents( $pipes[2] );
$code   = proc_close( $process );
if ( 0 !== $code ) {
	fwrite( STDERR, "PHPStan smoke gate failed:\n{$stdout}\n{$stderr}\n" );
	exit( 1 );
}
echo "PHPStan: OK (Level 7, 0 errors)\n\n";

// Persist the audit manifest.
$manifest = array(
	'generated_at' => gmdate( 'c' ),
	'level'        => 7,
	'total_rules'  => count( $rules ),
	'counts'       => $counts,
	'rules'        => $reports,
);
$manifest_path = $root_dir . '/dist/phpstan-ignore-manifest.json';
$manifest_dir  = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);
echo "Manifest persisted to: {$manifest_path}\n\n";

echo "✓ PHPStan ignore-rule audit holds. Every suppression is either an unavoidable third-party signature or an accepted technical-debt pattern.\n";
exit( 0 );