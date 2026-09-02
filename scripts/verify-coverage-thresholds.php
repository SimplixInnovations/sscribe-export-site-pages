<?php
/**
 * Phase 41: Coverage-threshold gate.
 *
 * The plugin ships with a coverage contract:
 *
 *   (a) Project-wide line coverage must be at or above 70%.
 *   (b) Every file in the security-critical module list must be at
 *       or above 90%. Those four files are the private-storage
 *       boundary (class-sscribe-private-storage.php), the
 *       filesystem containment surface
 *       (class-sscribe-filesystem.php), the path-scope security
 *       validator (class-sscribe-security.php), and the audit-trail
 *       writer (class-sscribe-audit-trail.php). These four carry the
 *       invariants the spec explicitly enumerates:
 *
 *         - private storage rejects unowned / non-sticky bases;
 *         - filesystem refuses uncontained mkdir() payloads;
 *         - security validates path scope before side effects;
 *         - audit trail records every export operation.
 *
 *       A regression that drops coverage on any of these files is a
 *       release blocker because the contracts they implement are
 *       exactly the ones the rest of the spec hardens.
 *
 * The verifier:
 *
 *   1. Loads `clover.xml` (written by PHPUnit when a coverage
 *      driver is active) and walks every `<file>` element.
 *   2. Computes a project-wide statements-covered ratio.
 *   3. Compares each critical file against its 90% threshold.
 *   4. Emits a human-readable report and persists a machine-readable
 *      manifest under `dist/coverage-threshold-manifest.json` so the
 *      release auditor can re-verify the same gate independently.
 *
 * Exit codes:
 *
 *   0 — every threshold satisfied, manifest written.
 *   1 — one or more thresholds violated, error report printed.
 *   2 — clover.xml missing (CI ran without a coverage driver); the
 *       spec considers that a release blocker because the gate
 *       could not be enforced.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir = dirname( __DIR__ );

// Default to the repo-root clover.xml; allow overriding for
// integration tests that exercise the verifier against fixtures
// without touching the live report.
$clover_path = isset( $argv[1] ) && is_string( $argv[1] ) && '' !== $argv[1]
	? $argv[1]
	: $root_dir . '/clover.xml';
if ( ! is_file( $clover_path ) ) {
	fwrite(
		STDERR,
		"Coverage gate: clover.xml missing from {$clover_path}. The test runner "
		. "must execute with a coverage driver (Xdebug or PCOV) so the gate can "
		. "enforce the project-wide and critical-module thresholds.\n"
	);
	exit( 2 );
}

$clover_xml = (string) file_get_contents( $clover_path );
if ( '' === $clover_xml ) {
	fwrite( STDERR, "Coverage gate: clover.xml is empty.\n" );
	exit( 2 );
}

libxml_use_internal_errors( true );
$document = simplexml_load_string( $clover_xml );
if ( false === $document ) {
	fwrite( STDERR, "Coverage gate: clover.xml is not valid XML.\n" );
	foreach ( libxml_get_errors() as $error ) {
		fwrite( STDERR, '  ' . trim( (string) $error->message ) . "\n" );
	}
	exit( 2 );
}
libxml_clear_errors();

/**
 * Coverage threshold contract.
 *
 * PROJECT_THRESHOLD — every statement across the audited source tree
 * must be at least this fraction covered.
 *
 * CRITICAL_FILES — per-file 90% threshold for the security-critical
 * modules. Files whose names appear here must hit the critical
 * threshold; everything else only contributes to the project-wide
 * ratio.
 */
$project_threshold = 70.0;

$critical_files = array(
	'includes/class-sscribe-private-storage.php' => 90.0,
	'includes/class-sscribe-filesystem.php'      => 90.0,
	'includes/class-sscribe-security.php'        => 90.0,
	'includes/class-sscribe-audit-trail.php'     => 90.0,
);

/**
 * Walk the Clover document and compute per-file + project-wide
 * statement coverage. Returns a tuple of [per_file, totals].
 *
 * @return array{per_file: array<string,float>, totals: array{statements:int,covered:int}}
 */
$compute_coverage = static function ( SimpleXMLElement $document ): array {
	$per_file  = array();
	$totals    = array(
		'statements' => 0,
		'covered'    => 0,
	);
	$project_metrics = $document->project->metrics ?? null;
	if ( null !== $project_metrics ) {
		$totals['statements'] = (int) $project_metrics['statements'];
		$totals['covered']    = (int) $project_metrics['coveredstatements'];
	}
	foreach ( $document->project->file as $file_node ) {
		$name = (string) $file_node['name'];
		if ( '' === $name ) {
			continue;
		}
		// Clover records paths relative to the working directory at
		// the moment phpunit ran. Normalise to forward slashes so
		// the critical-file map matches regardless of OS.
		$relative = str_replace( '\\', '/', $name );
		// Strip leading ./ artifacts.
		$relative = ltrim( $relative, './' );
		$file_metrics = $file_node->metrics ?? null;
		if ( null === $file_metrics ) {
			continue;
		}
		$statements = (int) $file_metrics['statements'];
		$covered    = (int) $file_metrics['coveredstatements'];
		if ( $statements <= 0 ) {
			continue;
		}
		$per_file[ $relative ] = ( 100.0 * $covered ) / $statements;
	}
	return array(
		'per_file' => $per_file,
		'totals'   => $totals,
	);
};

$coverage = $compute_coverage( $document );
$per_file = $coverage['per_file'];
$totals   = $coverage['totals'];

echo "=== SScribe Coverage Threshold Gate ===\n\n";

$project_ratio = $totals['statements'] > 0
	? ( 100.0 * $totals['covered'] ) / $totals['statements']
	: 0.0;

echo sprintf(
	"Project statements: %d / %d (%.2f%%, threshold %.0f%%)\n\n",
	$totals['covered'],
	$totals['statements'],
	$project_ratio,
	$project_threshold
);

$errors = array();

if ( $project_ratio + 1e-9 < $project_threshold ) {
	$errors[] = sprintf(
		'Project-wide coverage %.2f%% is below the required threshold of %.0f%%.',
		$project_ratio,
		$project_threshold
	);
}

echo "Critical-module thresholds:\n";
foreach ( $critical_files as $relative => $threshold ) {
	if ( ! isset( $per_file[ $relative ] ) ) {
		// The file might be missing from the coverage report if
		// every test was excluded from it (e.g. the file is
		// loaded conditionally). Treat that as a release blocker
		// because the contract guarantees the file is reachable
		// during the test suite.
		$errors[] = sprintf(
			'Critical file %s was not present in the Clover report. The gate cannot '
				. 'verify the %d%% threshold for that module.',
			$relative,
			(int) $threshold
		);
		echo sprintf( "  ✗ %s — missing from report (threshold %.0f%%)\n", $relative, $threshold );
		continue;
	}
	$actual = $per_file[ $relative ];
	if ( $actual + 1e-9 < $threshold ) {
		$errors[] = sprintf(
			'Critical file %s is at %.2f%% (required ≥ %.0f%%).',
			$relative,
			$actual,
			$threshold
		);
		echo sprintf( "  ✗ %s — %.2f%% (threshold %.0f%%)\n", $relative, $actual, $threshold );
		continue;
	}
	echo sprintf( "  ✓ %s — %.2f%% (threshold %.0f%%)\n", $relative, $actual, $threshold );
}

echo "\n";
echo "Errors: " . count( $errors ) . "\n\n";

$manifest = array(
	'generated_at'       => gmdate( 'c' ),
	'project_threshold'  => $project_threshold,
	'critical_threshold' => 90.0,
	'project'            => array(
		'statements'      => $totals['statements'],
		'covered'         => $totals['covered'],
		'coverage_percent' => $project_ratio,
		'passes'          => $project_ratio + 1e-9 >= $project_threshold,
	),
	'critical_files'     => array(),
	'passes'             => 0 === count( $errors ),
	'errors'             => $errors,
);
foreach ( $critical_files as $relative => $threshold ) {
	$actual = $per_file[ $relative ] ?? null;
	$manifest['critical_files'][ $relative ] = array(
		'threshold'        => $threshold,
		'coverage_percent' => $actual,
		'passes'           => null === $actual ? false : $actual + 1e-9 >= $threshold,
	);
}

$manifest_path = isset( $argv[2] ) && is_string( $argv[2] ) && '' !== $argv[2]
	? $argv[2]
	: $root_dir . '/dist/coverage-threshold-manifest.json';
$manifest_dir  = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

if ( ! empty( $errors ) ) {
	echo "Errors:\n";
	foreach ( $errors as $error ) {
		echo "  ✗ {$error}\n";
	}
	echo "\n";
	echo "Manifest persisted to: {$manifest_path}\n\n";
	echo "✗ Coverage threshold gate failed. The contract requires 70% project-wide and 90% for every critical module.\n";
	exit( 1 );
}

echo "Manifest persisted to: {$manifest_path}\n\n";
echo "✓ Coverage threshold gate passed. Project-wide ratio meets the 70% floor and every critical module meets the 90% floor.\n";
exit( 0 );
