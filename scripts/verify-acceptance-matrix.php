<?php
/**
 * Phase 58 — Count acceptance matrix verifier.
 *
 * The acceptance matrix is the auditable list of "every numeric
 * check must hold" for the release. Each cell in the matrix pins:
 *
 *   - a numeric floor / ceiling / equality,
 *   - the CI command that produces the value,
 *   - the ci.yml step that runs it.
 *
 * A regression that breaks the matrix (e.g., drops a check from
 * composer ci, removes a ci.yml step, or quietly lowers a
 * threshold) fails this verifier BEFORE it reaches the release
 * gate.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir       = dirname( __DIR__ );
$matrix_path    = $root_dir . '/docs/ACCEPTANCE_MATRIX_v2.0.0.json';
$composer_path  = $root_dir . '/composer.json';
$ci_path        = $root_dir . '/.github/workflows/ci.yml';
$audit_script   = $root_dir . '/scripts/release-audit.php';
$manifest_path  = $root_dir . '/dist/acceptance-matrix-manifest.json';

$matrix = array();
$errors = array();

foreach ( array( $matrix_path, $composer_path, $ci_path, $audit_script ) as $path ) {
	if ( ! is_file( $path ) ) {
		fwrite( STDERR, "✗ Required file missing: {$path}\n" );
		exit( 1 );
	}
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$matrix_data = json_decode( (string) file_get_contents( $matrix_path ), true );
if ( ! is_array( $matrix_data ) || ! isset( $matrix_data['matrix'] ) || ! is_array( $matrix_data['matrix'] ) ) {
	fwrite( STDERR, "✗ docs/ACCEPTANCE_MATRIX_v2.0.0.json is not valid JSON or is missing the `matrix` key.\n" );
	exit( 1 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$composer_src = (string) file_get_contents( $composer_path );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$ci_src       = (string) file_get_contents( $ci_path );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$audit_src    = (string) file_get_contents( $audit_script );

/**
 * Rule 1: every matrix cell has the canonical shape
 * (ci_command + ci_step + operator + either floor/ceiling/expected).
 */
$matrix_cell_count = 0;
foreach ( $matrix_data['matrix'] as $name => $cell ) {
	$matrix_cell_count++;
	$has_command = isset( $cell['ci_command'] ) && is_string( $cell['ci_command'] ) && '' !== $cell['ci_command'];
	$has_step    = isset( $cell['ci_step'] ) && is_string( $cell['ci_step'] ) && '' !== $cell['ci_step'];
	$has_op      = isset( $cell['operator'] ) && in_array( $cell['operator'], array( '>=', '<=', '==' ), true );
	$has_value   = isset( $cell['floor'] ) || isset( $cell['ceiling'] ) || ( isset( $cell['expected'] ) && is_bool( $cell['expected'] ) );

	$passes = $has_command && $has_step && $has_op && $has_value;
	$matrix[] = array(
		'rule'   => "matrix_cell_{$name}_is_well_formed",
		'passes' => $passes,
		'detail' => $passes
			? "Cell `{$name}` has ci_command + ci_step + operator + value."
			: "Cell `{$name}` is missing one of: ci_command, ci_step, operator, floor/ceiling/expected.",
	);
	if ( ! $passes ) {
		$errors[] = "Acceptance-matrix cell `{$name}` is malformed.";
	}
}
$matrix[] = array(
	'rule'   => 'acceptance_matrix_has_at_least_twenty_cells',
	'passes' => $matrix_cell_count >= 20,
	'detail' => "Acceptance matrix must pin at least 20 checks. Discovered: {$matrix_cell_count}.",
);
if ( $matrix_cell_count < 20 ) {
	$errors[] = "Acceptance matrix only has {$matrix_cell_count} cells; need at least 20.";
}

/**
 * Rule 2: every matrix cell's ci_command string appears in
 * composer.json scripts. A regression that drops the script
 * silently leaves a cell with no executable command.
 */
foreach ( $matrix_data['matrix'] as $name => $cell ) {
	if ( ! isset( $cell['ci_command'] ) ) {
		continue;
	}
	$cmd = $cell['ci_command'];
	// The first token (e.g. "composer test:a11y") must appear in composer.json.
	$first_token = trim( explode( ' ', $cmd )[0] );
	$matches_composer = (bool) strpos( $composer_src, $first_token );
	$matrix[] = array(
		'rule'   => "matrix_cell_{$name}_command_in_composer",
		'passes' => $matches_composer,
		'detail' => "Cell `{$name}` command `{$cmd}` (token: `{$first_token}`) must appear in composer.json scripts.",
	);
	if ( ! $matches_composer ) {
		$errors[] = "Acceptance-matrix cell `{$name}` command `{$first_token}` is not declared in composer.json.";
	}
}

/**
 * Rule 3: every matrix cell's ci_step string appears in ci.yml
 * as a step name. A regression that drops the step leaves a cell
 * with no verification gate.
 */
foreach ( $matrix_data['matrix'] as $name => $cell ) {
	if ( ! isset( $cell['ci_step'] ) ) {
		continue;
	}
	$step = $cell['ci_step'];
	$matches_ci = (bool) strpos( $ci_src, $step );
	$matrix[] = array(
		'rule'   => "matrix_cell_{$name}_step_in_ci_yml",
		'passes' => $matches_ci,
		'detail' => "Cell `{$name}` step `{$step}` must appear in .github/workflows/ci.yml.",
	);
	if ( ! $matches_ci ) {
		$errors[] = "Acceptance-matrix cell `{$name}` step `{$step}` is missing from ci.yml.";
	}
}

/**
 * Rule 4: floors are non-negative numbers; ceilings are non-negative
 * numbers; expected is bool. Mis-typed cells silently never enforce.
 */
foreach ( $matrix_data['matrix'] as $name => $cell ) {
	$bad = false;
	if ( isset( $cell['floor'] ) && ! is_numeric( $cell['floor'] ) ) {
		$bad = true;
	}
	if ( isset( $cell['ceiling'] ) && ! is_numeric( $cell['ceiling'] ) ) {
		$bad = true;
	}
	if ( isset( $cell['expected'] ) && ! is_bool( $cell['expected'] ) ) {
		$bad = true;
	}
	$matrix[] = array(
		'rule'   => "matrix_cell_{$name}_value_type_valid",
		'passes' => ! $bad,
		'detail' => $bad
			? "Cell `{$name}` has wrong type for its floor/ceiling/expected value."
			: "Cell `{$name}` has correct value type.",
	);
	if ( $bad ) {
		$errors[] = "Acceptance-matrix cell `{$name}` has wrong value type.";
	}
}

/**
 * Rule 5: the matrix declares pass criteria + a list of
 * enforcement scripts. Without this, the matrix is just a
 * spreadsheet — not a contract.
 */
$has_pass_criteria = isset( $matrix_data['pass_criteria'] ) && is_string( $matrix_data['pass_criteria'] ) && '' !== $matrix_data['pass_criteria'];
$has_enforcement   = isset( $matrix_data['enforcement'] ) && is_array( $matrix_data['enforcement'] ) && count( $matrix_data['enforcement'] ) >= 2;
$matrix[] = array(
	'rule'   => 'acceptance_matrix_declares_pass_criteria',
	'passes' => $has_pass_criteria,
	'detail' => 'docs/ACCEPTANCE_MATRIX_v2.0.0.json must declare `pass_criteria` (what counts as "all green").',
);
$matrix[] = array(
	'rule'   => 'acceptance_matrix_declares_enforcement_chain',
	'passes' => $has_enforcement,
	'detail' => 'docs/ACCEPTANCE_MATRIX_v2.0.0.json must list >= 2 enforcement points (verifier + PHPUnit + audit + docs).',
);
if ( ! $has_pass_criteria ) {
	$errors[] = 'Acceptance matrix is missing pass_criteria.';
}
if ( ! $has_enforcement ) {
	$errors[] = 'Acceptance matrix is missing enforcement chain (need >= 2 entries).';
}

/**
 * Rule 6: the release-audit script actually invokes the matrix
 * checks. Without this, the pre-tag gate is silent.
 */
$audit_invokes_matrix = (bool) strpos( $audit_src, 'acceptance-matrix' )
	|| (bool) preg_match( '/acceptance\s*matrix/i', $audit_src );
$matrix[] = array(
	'rule'   => 'release_audit_invokes_acceptance_matrix',
	'passes' => $audit_invokes_matrix,
	'detail' => 'scripts/release-audit.php must invoke the acceptance matrix (via composer verify-acceptance-matrix or explicit reference).',
);
if ( ! $audit_invokes_matrix ) {
	$errors[] = 'scripts/release-audit.php does NOT invoke the acceptance matrix.';
}

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at'        => gmdate( 'c' ),
	'cell_count'          => $matrix_cell_count,
	'rule_count'          => count( $matrix ),
	'passed_count'        => count( array_filter( $matrix, static fn( $r ) => $r['passes'] ) ),
	'matrix'              => $matrix,
	'errors_count'        => count( $errors ),
	'passes'              => 0 === count( $errors ),
	'errors'              => $errors,
);
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

echo "=== SScribe Acceptance Matrix ===\n\n";
echo "Cells: {$matrix_cell_count}\n";
echo "Rules: " . count( $matrix ) . "\n\n";
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
echo "✓ Acceptance matrix contract valid.\n";
exit( 0 );
