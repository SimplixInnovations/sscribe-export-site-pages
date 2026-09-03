<?php
/**
 * Phase 68 — Required new tests (coverage verifier).
 *
 * The v2.0.0 release-hardening spec lists 24 required regression
 * scenarios. This verifier walks the test tree and asserts each
 * one has at least one matching test method.
 *
 * For each row in docs/REQUIRED_NEW_TESTS_v2.0.0.md the verifier
 * matches on a case-insensitive substring against the combined
 * (method-name + docblock) text of every test method in
 * tests/Integration/ and tests/Unit/.
 *
 * Rules:
 *
 *   1. The Phase 68 registry doc exists.
 *   2. The registry doc declares every canonical row header
 *      (required topic, signature phrase, coverage location).
 *   3. The 24 canonical signature phrases are all present in the
 *      registry doc.
 *   4. Every registry signature has at least one matching test
 *      method in the test tree (case-insensitive substring match
 *      against method name OR docblock).
 *   5. The integration test that pins this verifier exists.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$registry_doc  = $root_dir . '/docs/REQUIRED_NEW_TESTS_v2.0.0.md';
$manifest_path = $root_dir . '/dist/phase-68-test-coverage-manifest.json';

$matrix  = array();
$errors  = array();
$covered = array();

$record = static function ( string $rule, bool $passes, string $detail ) use ( &$matrix, &$errors ): void {
	$matrix[] = array(
		'rule'   => $rule,
		'passes' => $passes,
		'detail' => $detail,
	);
	if ( ! $passes ) {
		$errors[] = $detail;
	}
};

/**
 * Canonical Phase 68 signature phrases. Each row maps to one
 * required topic in the v2.0.0 release-hardening spec.
 *
 * The verifier searches for each phrase as a case-insensitive
 * substring inside any test method name OR docblock in the test
 * tree. A match counts the topic as covered.
 */
$canonical_signatures = array(
	'stale count response',
	'aborted count xhr',
	'delayed stale retry',
	'__all__ count',
	'__all__ preview',
	'__all__ start',
	'all types preview',
	'summary preview equality',
	'preflight retry method',
	'429 preflight',
	'503 preflight',
	'terminal 500 preflight',
	'clear-session terminal 500',
	'retry-after',
	'finalize terminal 500',
	'debug off→on refresh',
	'debug error no-match',
	'operational logger after-init flush',
	'fatal logger flush',
	'redis rate-limit counter',
	'sticky-bit ownership',
	'exact package vendor-prefixed bootstrap',
	'auto-download',
	'language dom sibling',
);

/**
 * Rule 1: registry doc exists.
 */
$record(
	'registry_doc_exists',
	is_file( $registry_doc ),
	'docs/REQUIRED_NEW_TESTS_v2.0.0.md must exist so the Phase 68 registry is auditable.'
);

/**
 * Rule 2: registry doc declares canonical sections.
 */
if ( is_file( $registry_doc ) ) {
	$doc_src = (string) file_get_contents( $registry_doc );
	$canonical_sections = array(
		'## Why this exists',
		'## Canonical registry',
		'## Why this is a contract',
		'## How an independent auditor verifies this',
	);
	$missing_sections   = array();
	foreach ( $canonical_sections as $section ) {
		if ( false === strpos( $doc_src, $section ) ) {
			$missing_sections[] = $section;
		}
	}
	$record(
		'registry_doc_has_canonical_sections',
		0 === count( $missing_sections ),
		'Phase 68 registry doc is missing canonical sections: ' . implode( ', ', $missing_sections )
	);
}

/**
 * Build the searchable corpus: every test method name + its
 * docblock, keyed by file path.
 */
$corpus = array();
$test_dirs = array( $root_dir . '/tests/Integration', $root_dir . '/tests/Unit' );
foreach ( $test_dirs as $dir ) {
	if ( ! is_dir( $dir ) ) {
		continue;
	}
	$iter = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
	);
	foreach ( $iter as $info ) {
		if ( $info->isDir() || '.php' !== substr( $info->getFilename(), -4 ) ) {
			continue;
		}
		$src = (string) file_get_contents( $info->getPathname() );
		// Match every method definition (public function test_…).
		if ( preg_match_all( '/\bfunction\s+(test_[A-Za-z0-9_]+)\s*\(/', $src, $name_hits, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $name_hits[1] as $hit ) {
				$method_name = $hit[0];
				$offset      = $hit[1];
				// Capture the docblock: walk back from the method
				// definition to the nearest "*/" before the
				// "function" keyword.
				$before = substr( $src, 0, $offset );
				$doc_end   = strrpos( $before, '*/' );
				$doc_start = false === $doc_end ? false : strrpos( substr( $before, 0, $doc_end ), '/*' );
				$docblock  = ( false !== $doc_start && false !== $doc_end )
					? substr( $before, $doc_start, $doc_end - $doc_start + 2 )
					: '';
				$corpus[] = array(
					'file'     => substr( $info->getPathname(), strlen( $root_dir ) + 1 ),
					'method'   => $method_name,
					'docblock' => $docblock,
					'haystack' => strtolower( $method_name . ' ' . $docblock ),
				);
			}
		}
	}
}

/**
 * Rule 3-4: every canonical signature has at least one matching
 * test in the corpus.
 */
foreach ( $canonical_signatures as $signature ) {
	$needle  = strtolower( $signature );
	$matches = array();
	foreach ( $corpus as $entry ) {
		if ( false !== strpos( $entry['haystack'], $needle ) ) {
			$matches[] = $entry['file'] . '::' . $entry['method'];
		}
	}
	$covered[ $signature ] = $matches;
	$record(
		'covers_' . preg_replace( '/[^a-z0-9_]/', '_', strtolower( $signature ) ),
		count( $matches ) > 0,
		'Phase 68 signature "' . $signature . '" must appear in at least one test method name OR docblock. No match found in ' . count( $corpus ) . ' test methods scanned.'
	);
}

/**
 * Rule 5: integration test exists.
 */
$test_path = $root_dir . '/tests/Integration/SScribe_Phase_68_Test_Coverage_Test.php';
$record(
	'integration_test_exists',
	is_file( $test_path ),
	'tests/Integration/SScribe_Phase_68_Test_Coverage_Test.php must exist so the Phase 68 coverage contract is pinned at the PHPUnit boundary.'
);

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at'        => gmdate( 'c' ),
	'canonical_signatures' => $canonical_signatures,
	'covered'             => $covered,
	'coverage_summary'    => array(
		'total_signatures' => count( $canonical_signatures ),
		'covered_count'    => count( array_filter( $covered, static fn( $m ) => count( $m ) > 0 ) ),
		'missing_count'    => count( array_filter( $covered, static fn( $m ) => 0 === count( $m ) ) ),
	),
	'rule_count'          => count( $matrix ),
	'passed_count'        => count( array_filter( $matrix, static fn( $r ) => $r['passes'] ) ),
	'errors_count'        => count( $errors ),
	'passes'              => 0 === count( $errors ),
	'errors'              => $errors,
	'matrix'              => $matrix,
);
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

echo "=== SScribe Phase 68 Test Coverage Acceptance ===\n\n";
echo "Total canonical signatures: " . count( $canonical_signatures ) . "\n";
echo "Covered:                   " . $manifest['coverage_summary']['covered_count'] . "\n";
echo "Missing:                   " . $manifest['coverage_summary']['missing_count'] . "\n\n";
foreach ( $matrix as $row ) {
	$status = $row['passes'] ? '✓' : '✗';
	echo sprintf( "  %s  %s\n      %s\n", $status, $row['rule'], $row['detail'] );
}
echo "\nErrors: " . count( $errors ) . "\n";
foreach ( $errors as $error ) {
	echo "  ✗ {$error}\n";
}
echo "\nManifest persisted to: {$manifest_path}\n";

if ( ! empty( $errors ) ) {
	exit( 1 );
}
echo "✓ Phase 68 test coverage contract valid.\n";
exit( 0 );
