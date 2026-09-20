<?php
/**
 * Release audit: single-pass gate running every CI check in release order.
 *
 * Canonical cross-platform implementation. bin/release-audit.sh is a thin
 * wrapper around this file; both entry points share behavior.
 *
 * Each gate is intentionally independent — a failure in one does NOT
 * short-circuit the run, so the operator sees all problems at once.
 * Exits 0 only if all gates pass. Prints a summary table on success.
 *
 * Usage:
 *   php scripts/release-audit.php
 *   SSCRIBE_RELEASE_CERTIFICATION=1 php scripts/release-audit.php
 *
 * Normal mode validates the source/artifact contracts without requiring the
 * release-only ignored Phase 70/71/72 evidence bundle. Strict certification
 * is enabled only when SSCRIBE_RELEASE_CERTIFICATION=1 is explicitly set.
 *
 * Per-gate logs are written to the system temp directory as
 * release-audit-<slug>.log (the same basenames the shell implementation
 * used under /tmp).
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

require_once __DIR__ . '/lib/cross-platform.php';

/**
 * Run one gate, record PASS/FAIL with elapsed milliseconds.
 *
 * @param string $name    Gate display name.
 * @param array  $argv    Argument vector.
 * @param array  $extra   Extra environment variables.
 * @param array  $summary Accumulator for the summary table (by reference).
 * @return bool True on PASS.
 */
function sscribe_audit_gate( string $name, array $argv, array $extra, array &$summary ): bool {
	$log = rtrim( sys_get_temp_dir(), '/\\' ) . '/release-audit-' . strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $name ) ) . '.log';
	@unlink( $log );
	$res    = sscribe_run_argv( $argv, sscribe_repo_root(), $extra, $log );
	$status = 0 === $res['code'] ? 'PASS' : 'FAIL';
	$summary[] = array(
		'status' => $status,
		'ms'     => $res['ms'],
		'name'   => $name,
	);
	printf( "%s %-32s %6sms\n", $status, $name, number_format( $res['ms'] ) );
	if ( 0 !== $res['code'] && is_file( $log ) ) {
		$failure_output = trim( (string) file_get_contents( $log ) );
		if ( '' !== $failure_output ) {
			fwrite( STDERR, "\n--- {$name} failure output ---\n{$failure_output}\n--- end {$name} failure output ---\n\n" );
		}
	}
	return 0 === $res['code'];
}

function sscribe_release_audit(): void {
	$root     = sscribe_repo_root();
	$composer = sscribe_composer_cmd();
	$npm      = sscribe_npm_cmd();
	$php      = PHP_BINARY;
	$summary  = array();
	$pass     = 0;
	$fail     = 0;

	$gate = function ( string $name, array $argv, array $extra = array() ) use ( &$summary, &$pass, &$fail ): void {
		if ( sscribe_audit_gate( $name, $argv, $extra, $summary ) ) {
			$pass++;
		} else {
			$fail++;
		}
	};

	$strict_certification = '1' === (string) getenv( 'SSCRIBE_RELEASE_CERTIFICATION' );
	$cert = $strict_certification ? array( 'SSCRIBE_RELEASE_CERTIFICATION' => '1' ) : array();
	fwrite(
		STDOUT,
		'Release certification mode: ' . ( $strict_certification ? 'STRICT' : 'NORMAL' ) . "\n"
	);

	fwrite( STDOUT, "== PHPUnit (full suite) ==\n" );
	$gate( 'PHPUnit', array( $composer, 'test' ) );

	$composer_gates = array(
		'Acceptance-Matrix'            => 'test:acceptance-matrix',
		'Debug-Log'                    => 'test:debug-log',
		'No-Internal-Details'          => 'test:no-internal-details',
		'CI-Docs'                      => 'test:ci-docs',
		'Build-Order'                  => 'test:build-order',
		'Exact-Package-Clean-Install'  => 'test:exact-package-clean-install',
		'Plugin-Check-Triage'          => 'test:plugin-check-triage',
		'JS-Error-Free'                => 'test:js-error-free',
		'Ajax-Network-Trace'           => 'test:ajax-network-trace',
		'UI-Refactor-Discipline'       => 'test:ui-refactor-discipline',
		'Phase-68-Test-Coverage'       => 'test:phase-68-test-coverage',
		'Manual-Runtime-Tests'         => 'test:manual-runtime-tests',
	);
	foreach ( $composer_gates as $name => $script ) {
		fwrite( STDOUT, "== {$name} ==\n" );
		$gate( $name, array( $composer, $script ) );
	}

	$cert_gates = array(
		'Release-Blockers'       => 'test:release-blockers',
		'Final-CI-State'         => 'test:final-ci-state',
		'Exact-Artifact-Evidence' => 'test:exact-artifact-evidence',
	);
	foreach ( $cert_gates as $name => $script ) {
		fwrite( STDOUT, "== {$name} ==\n" );
		$gate( $name, array( $composer, $script ), $cert );
	}

	$tail_gates = array(
		'Agent-Final-Report' => 'test:agent-final-report',
		'Branch-Policy'      => 'test:branch-policy',
		'Auditor-Handoff'    => 'test:auditor-handoff',
		'Release-Invariants' => 'test:release-invariants',
		'Definition-Of-Done' => 'test:definition-of-done',
	);
	foreach ( $tail_gates as $name => $script ) {
		fwrite( STDOUT, "== {$name} ==\n" );
		$tail_env = in_array( $name, array( 'Agent-Final-Report', 'Auditor-Handoff' ), true ) ? $cert : array();
		$gate( $name, array( $composer, $script ), $tail_env );
	}

	fwrite( STDOUT, "== PHPStan ==\n" );
	$gate( 'PHPStan-level-7', array( $php, 'vendor/bin/phpstan', 'analyse', '--memory-limit=1G', '--no-progress' ) );

	fwrite( STDOUT, "== PHPCS ==\n" );
	$gate( 'PHPCS', array( $php, 'vendor/bin/phpcs', '--standard=phpcs.xml' ) );

	fwrite( STDOUT, "== ESLint + Stylelint ==\n" );
	$gate( 'ESLint+Stylelint', array( $npm, 'run', 'lint' ) );

	fwrite( STDOUT, "== ZIP artifact certification ==\n" );
	$gate( 'Artifact-Cert', array( $php, 'vendor/bin/phpunit', '--filter=SScribe_Artifact_Certification_Test', '--testsuite=Unit' ) );

	fwrite( STDOUT, "== Plugin-Check (full WP instance) ==\n" );
	$wp_root = getenv( 'SSCRIBE_WP_ROOT' );
	$wp_bin  = getenv( 'SSCRIBE_WP_BIN' );
	$wp_found = true;
	if ( ! is_string( $wp_bin ) || '' === $wp_bin ) {
		$wp_bin   = sscribe_which( 'wp' );
		$wp_found = 'wp' !== $wp_bin;
	}
	if ( is_string( $wp_root ) && '' !== $wp_root && is_dir( $wp_root . '/wp-content/plugins/plugin-check' ) && $wp_found && '' !== $wp_bin ) {
		$plugin_dir       = $wp_root . '/wp-content/plugins/sscribe-export-site-pages';
		$plugin_check_cli       = $wp_root . '/wp-content/plugins/plugin-check/cli.php';
		$plugin_check_bootstrap = __DIR__ . '/plugin-check-cli-bootstrap.php';
		if ( ! is_dir( $plugin_dir ) || ! is_file( $plugin_check_cli ) || ! is_file( $plugin_check_bootstrap ) ) {
			fwrite( STDERR, "Plugin Check runtime prerequisites are incomplete: exact plugin directory or runtime bootstrap is missing.\n" );
			$summary[] = array( 'status' => 'FAIL', 'ms' => 0, 'name' => 'Plugin-Check' );
			$fail++;
			printf( "%s %-32s %6sms\n", 'FAIL', 'Plugin-Check', '0' );
		} else {
			$gate(
				'Plugin-Check',
				array(
					$wp_bin,
					'--path=' . $wp_root,
					'plugin',
					'check',
					$plugin_dir,
					'--format=json',
					'--require=' . $plugin_check_bootstrap,
					'--allow-root',
				)
			);
		}
	} else {
		fwrite( STDERR, "Plugin Check testbench unavailable. Set SSCRIBE_WP_ROOT to a WordPress install containing the official Plugin Check plugin and optionally SSCRIBE_WP_BIN to the wp-cli executable. release audit is fail-closed.\n" );
		$summary[] = array(
			'status' => 'FAIL',
			'ms'     => 0,
			'name'   => 'Plugin-Check',
		);
		$fail++;
		printf( "%s %-32s %6sms\n", 'FAIL', 'Plugin-Check', '0' );
	}

	fwrite( STDOUT, "\n== Release-Audit summary ==\n" );
	$tmp = rtrim( sys_get_temp_dir(), '/\\' );
	foreach ( $summary as $row ) {
		printf( "  %-10s %sms %s\n", $row['status'], number_format( $row['ms'] ), $row['name'] );
	}
	fwrite( STDOUT, "\nPass: {$pass}\nFail: {$fail}\n\n" );

	if ( 0 !== $fail ) {
		fwrite( STDOUT, "Latest logs:\n" );
		foreach ( $summary as $row ) {
			$slug = strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $row['name'] ) );
			fwrite( STDOUT, "  {$tmp}/release-audit-{$slug}.log\n" );
		}
		exit( 1 );
	}

	fwrite( STDOUT, "All gates green.\n" );
}

sscribe_release_audit();
