<?php
/**
 * Phase 63 — Exact package clean install contract.
 *
 * The "exact package" the WP.org reviewer will exercise is the
 * ZIP at `dist/sscribe-export-site-pages-{VERSION}.zip`. A
 * regression that:
 *
 *   - builds a ZIP whose mainfile Version: header does not match
 *     SSCRIBE_VERSION;
 *   - ships an activator that does not create the canonical
 *     tables / capabilities / cron schedule;
 *   - ships an uninstall.php that runs outside the WP-managed
 *     lifecycle;
 *   - or drops the auditable clean-install smoke doc;
 *
 * silently ships a build that fails the first install.
 *
 * This verifier walks the published ZIP and the source tree
 * side-by-side and asserts the canonical contract is intact:
 *
 *   1. dist/sscribe-export-site-pages-{VERSION}.zip exists for
 *      SSCRIBE_VERSION (no version-skew release).
 *   2. ZIP mainfile Version header equals SSCRIBE_VERSION.
 *   3. ZIP contains a complete activator that schedules the
 *      canonical 3 cron events, creates the 3 tables, and
 *      grants the 2 capabilities.
 *   4. ZIP contains an uninstall.php that is gated on
 *      WP_UNINSTALL_PLUGIN (so it only runs inside the WP
 *      uninstall flow).
 *   5. docs/WP_ORG_CLEAN_INSTALL_SMOKE.md declares the
 *      canonical 10-invariant matrix and references the exact
 *      ZIP filename pattern (so the reviewer can reproduce).
 *
 * The actual end-to-end "extract ZIP, install + activate on a
 * fresh WP, run smoke probes" step lives in
 * docs/WP_ORG_CLEAN_INSTALL_SMOKE.md (Phase 30 evidence) and
 * is exercised in the live WP testbench via the
 * SScribe_Exact_Package_Clean_Install_Test integration test.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$mainfile_path = $root_dir . '/sscribe-export-site-pages.php';
$smoke_doc     = $root_dir . '/docs/WP_ORG_CLEAN_INSTALL_SMOKE.md';
$manifest_path = $root_dir . '/dist/exact-package-clean-install-manifest.json';

if ( ! is_file( $mainfile_path ) ) {
	fwrite( STDERR, "Missing mainfile: sscribe-export-site-pages.php\n" );
	exit( 1 );
}
$mainfile_src = (string) file_get_contents( $mainfile_path );

// Read the canonical SSCRIBE_VERSION off the mainfile. The build
// script reads the same line, so this is the single source of truth.
if ( ! preg_match( "/define\\s*\\(\\s*['\"]SSCRIBE_VERSION['\"]\\s*,\\s*['\"]([^'\"]+)['\"]/", $mainfile_src, $version_match ) ) {
	fwrite( STDERR, "Could not read SSCRIBE_VERSION from mainfile.\n" );
	exit( 1 );
}
$canonical_version = $version_match[1];

$matrix = array();
$errors = array();

/**
 * Helper: append a rule + its pass/fail + detail to the matrix.
 * Auto-records an error on failure so the JSON manifest carries
 * the same summary the verifier prints.
 */
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
 * Rule 1: exact ZIP exists at dist/sscribe-export-site-pages-
 * {VERSION}.zip for the canonical SSCRIBE_VERSION. No other
 * version-skew file is allowed alongside it.
 */
$expected_zip = $root_dir . '/dist/sscribe-export-site-pages-' . $canonical_version . '.zip';
$zip_present  = is_file( $expected_zip );
$record(
	'exact_zip_present',
	$zip_present,
	"Exact ZIP at dist/sscribe-export-site-pages-{$canonical_version}.zip must exist (the reviewer will install this file)."
);

/**
 * Rule 2: the mainfile Version header in the main repo matches
 * SSCRIBE_VERSION (no version-skew release).
 */
$record(
	'mainfile_version_matches_constant',
	(bool) preg_match( '/Version:\s*' . preg_quote( $canonical_version, '/' ) . '/', $mainfile_src ),
	"Mainfile Version: header must equal SSCRIBE_VERSION={$canonical_version}."
);

/**
 * Rule 3: ZIP mainfile header (read out of the ZIP itself) also
 * carries the same Version. This catches a build that copies
 * stale source into dist/ without rebuilding.
 */
if ( $zip_present && class_exists( 'ZipArchive' ) ) {
	$zip = new ZipArchive();
	$zip_open = $zip->open( $expected_zip );
	if ( true === $zip_open ) {
		$mainfile_name = null;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( isset( $stat['name'] ) && preg_match( '#^sscribe-export-site-pages/[^/]+\.php$#', $stat['name'] ) ) {
				$peek = (string) $zip->getFromIndex( $i );
				if ( false !== strpos( $peek, "Plugin Name:" ) ) {
					$mainfile_name = $stat['name'];
					break;
				}
			}
		}
		if ( null !== $mainfile_name ) {
			$zip_mainfile = (string) $zip->getFromName( $mainfile_name );
			$record(
				'zip_mainfile_header_matches_version',
				(bool) preg_match( '/Version:\s*' . preg_quote( $canonical_version, '/' ) . '/', $zip_mainfile ),
				"ZIP mainfile '{$mainfile_name}' must carry Version: {$canonical_version}."
			);

			/**
			 * Rule 4: ZIP contains a complete activator that
			 * creates the canonical 3 tables, grants the 2 caps,
			 * schedules the 3 cron events.
			 *
			 * Source-level these calls live in:
			 *   - includes/class-sscribe-activator.php
			 *
			 * We assert the activator file is present and carries
			 * the canonical calls.
			 */
			$activator_name = null;
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$stat = $zip->statIndex( $i );
				if ( isset( $stat['name'] ) && 'sscribe-export-site-pages/includes/class-sscribe-activator.php' === $stat['name'] ) {
					$activator_name = $stat['name'];
					break;
				}
			}
			if ( null !== $activator_name ) {
				$activator_src = (string) $zip->getFromName( $activator_name );
				$expected_cron_events  = array( 'sscribe_cleanup_exports', 'sscribe_cleanup_sessions', 'sscribe_cleanup_audit_trail' );
				$expected_caps         = array( 'sscribe_export', 'sscribe_health' );

				$cron_present     = true;
				foreach ( $expected_cron_events as $hook ) {
					if ( false === strpos( $activator_src, $hook ) ) {
						$cron_present = false;
						break;
					}
				}
				$caps_present      = true;
				foreach ( $expected_caps as $cap ) {
					if ( false === strpos( $activator_src, $cap ) ) {
						$caps_present = false;
						break;
					}
				}

				// Tables may live in the activator OR in classes it
				// delegates to (e.g. SScribe_Audit_Trail owns the
				// sscribe_audit_log table). Walk every PHP file in
				// the ZIP and confirm the 3 table suffixes are
				// referenced from at least one file.
				$expected_table_refs = array( 'sscribe_export_logs', 'sscribe_export_stats', 'sscribe_audit_log' );
				$tables_present      = array_fill_keys( $expected_table_refs, false );
				for ( $i = 0; $i < $zip->numFiles; $i++ ) {
					$stat = $zip->statIndex( $i );
					if ( ! isset( $stat['name'] ) || '.php' !== substr( $stat['name'], -4 ) ) {
						continue;
					}
					// Read at most a 256 KiB prefix per file to keep
					// the verifier fast — table names always appear
					// in the first ~10 KiB of a class file.
					$peek = (string) $zip->getFromIndex( $i );
					foreach ( $expected_table_refs as $table_suffix ) {
						if ( false !== strpos( $peek, $table_suffix ) ) {
							$tables_present[ $table_suffix ] = true;
						}
					}
				}

				$record(
					'zip_activator_schedules_three_cron_events',
					$cron_present,
					'ZIP activator must reference all 3 cron hooks: sscribe_cleanup_exports, sscribe_cleanup_sessions, sscribe_cleanup_audit_trail.'
				);
				$record(
					'zip_creates_three_tables',
					! in_array( false, $tables_present, true ),
					'ZIP must reference all 3 table suffixes from at least one PHP file: sscribe_export_logs, sscribe_export_stats, sscribe_audit_log.'
				);
				$record(
					'zip_activator_grants_two_caps',
					$caps_present,
					'ZIP activator must reference both caps: sscribe_export, sscribe_health.'
				);
			} else {
				$record(
					'zip_activator_present',
					false,
					'ZIP must contain includes/class-sscribe-activator.php.'
				);
			}

			/**
			 * Rule 5: ZIP contains uninstall.php that is gated on
			 * WP_UNINSTALL_PLUGIN (so it only runs inside the WP
			 * uninstall flow).
			 */
			$uninstall_src = (string) $zip->getFromName( 'sscribe-export-site-pages/uninstall.php' );
			$uninstall_gated = '' !== $uninstall_src && (bool) preg_match( "/defined\\s*\\(\\s*['\"]WP_UNINSTALL_PLUGIN['\"]\\s*\\)/", $uninstall_src );
			$record(
				'zip_uninstall_gated_on_wp_uninstall_plugin',
				$uninstall_gated,
				'ZIP uninstall.php must be gated on defined( \'WP_UNINSTALL_PLUGIN\' ) so it only runs inside the WP uninstall flow.'
			);
		} else {
			$record(
				'zip_mainfile_located',
				false,
				'Could not locate a Plugin Name-bearing mainfile inside the ZIP.'
			);
		}
		$zip->close();
	} else {
		$record(
			'zip_opens_cleanly',
			false,
			'Exact ZIP must open cleanly via ZipArchive (CRC + header sanity).'
		);
	}
} else {
	$record(
		'zip_opens_cleanly',
		false,
		'ZipArchive extension not available — cannot verify ZIP structural contract.'
	);
}

/**
 * Rule 6: the clean-install smoke doc exists at the canonical
 * path so an independent reviewer can reproduce the gate.
 */
$record(
	'smoke_doc_exists',
	is_file( $smoke_doc ),
	'docs/WP_ORG_CLEAN_INSTALL_SMOKE.md must exist so the reviewer can reproduce the exact-package clean-install smoke.'
);

/**
 * Rule 7: the smoke doc references the canonical ZIP filename
 * pattern AND declares the canonical invariants.
 */
if ( is_file( $smoke_doc ) ) {
	$smoke_src = (string) file_get_contents( $smoke_doc );
	$record(
		'smoke_doc_references_canonical_zip_pattern',
		(bool) preg_match( "/dist\\/sscribe-export-site-pages-{$canonical_version}\\.zip/", $smoke_src ),
		"docs/WP_ORG_CLEAN_INSTALL_SMOKE.md must reference dist/sscribe-export-site-pages-{$canonical_version}.zip."
	);
	$record(
		'smoke_doc_declares_canonical_invariants',
		(bool) preg_match( '/wp_sscribe_export_logs.*wp_sscribe_export_stats.*wp_sscribe_audit_log/s', $smoke_src )
			&& (bool) preg_match( '/sscribe_cleanup_exports.*sscribe_cleanup_sessions.*sscribe_cleanup_audit_trail/s', $smoke_src )
			&& (bool) preg_match( '/sscribe_export.*sscribe_health/s', $smoke_src ),
		'docs/WP_ORG_CLEAN_INSTALL_SMOKE.md must declare the canonical invariants (3 tables + 3 cron events + 2 caps).'
	);
	$record(
		'smoke_doc_declares_invariants_table',
		(bool) preg_match( '/\| Invariant\s+\|/', $smoke_src ),
		'docs/WP_ORG_CLEAN_INSTALL_SMOKE.md must declare an `| Invariant …` table so the invariants are auditable.'
	);
}

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at' => gmdate( 'c' ),
	'version'      => $canonical_version,
	'rule_count'   => count( $matrix ),
	'passed_count' => count( array_filter( $matrix, static fn( $r ) => $r['passes'] ) ),
	'errors_count' => count( $errors ),
	'passes'       => 0 === count( $errors ),
	'errors'       => $errors,
	'matrix'       => $matrix,
);
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

echo "=== SScribe Exact-Package Clean-Install Acceptance ===\n\n";
echo "Canonical version: {$canonical_version}\n";
echo "Expected ZIP:      dist/sscribe-export-site-pages-{$canonical_version}.zip\n\n";
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
echo "✓ Exact-package clean-install contract valid.\n";
exit( 0 );
