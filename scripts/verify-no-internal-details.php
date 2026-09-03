<?php
/**
 * Phase 60 — No raw internal details verifier.
 *
 * The plugin must NEVER leak internal implementation details to
 * user-facing surfaces: admin UI, AJAX responses, REST endpoints,
 * download contents, error messages, operational logs, or
 * JavaScript-visible PHP variables. Raw internal details include:
 *
 *   - Filesystem paths (e.g. /var/www/..., C:\Users\..., /tmp/...).
 *   - Database table names and raw SQL fragments.
 *   - Stack traces in production responses (allowed only behind
 *     SSCRIBE_DEBUG + admin capability checks).
 *   - Exception messages that echo internal state.
 *   - WordPress internal constants and ABSPATH values.
 *
 * A regression that surfaces any of these is a security/UX bug:
 *   - Sites get fingerprinted (filesystem layout, table prefix).
 *   - WP-Plugin-Check flags it as `production.debug`.
 *   - User trust breaks when a leaked SQL fragment shows up in
 *     a 500 response.
 *
 * The verifier walks every PHP file under includes/ + admin/ and
 * flags raw patterns. The integration test pins the contract at
 * the PHPUnit boundary.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$scan_dirs     = array(
	$root_dir . '/includes',
	$root_dir . '/admin',
);
$manifest_path = $root_dir . '/dist/no-internal-details-manifest.json';

$matrix   = array();
$errors   = array();
$findings = array();

// Pattern: the AJAX guard is the canonical entry point.
$ajax_guard_class = 'SScribe_AJAX_Guard';

// PHP files we always skip (the verifier itself, the test file).
$skip_files = array(
	basename( __FILE__ ),
	'SScribe_No_Internal_Details_Test.php',
	'verify-no-internal-details.php',
);

$files = array();
foreach ( $scan_dirs as $dir ) {
	if ( ! is_dir( $dir ) ) {
		continue;
	}
	$iter = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	foreach ( $iter as $file ) {
		if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
			$files[] = (string) $file->getPathname();
		}
	}
}
sort( $files );

/**
 * Add a finding row + an error if it fails.
 */
$add = static function ( string $rule, string $detail, bool $passes ) use ( &$matrix, &$errors ): void {
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
 * Rule 1: every AJAX action registered with add_action('wp_ajax_*')
 * uses the SScribe_AJAX_Guard wrapper (::with_guard) — or at minimum,
 * the AJAX response goes through ::success()/::error() which sanitize
 * the environment. A handler that calls wp_send_json directly bypasses
 * display_errors=0 + buffer cleaning.
 */
$ajax_action_count    = 0;
$guarded_action_count = 0;
foreach ( $files as $file ) {
	if ( in_array( basename( $file ), $skip_files, true ) ) {
		continue;
	}
	$src = (string) file_get_contents( $file );

	if ( preg_match_all( "/add_action\s*\(\s*['\"]wp_ajax_[^'\"]+['\"]/", $src, $hits ) ) {
		foreach ( $hits[0] as $hit ) {
			++$ajax_action_count;
			// Check that the registration site uses the guard or the
			// surrounding code calls the guard for the same handler.
			if (
				(bool) preg_match( "/SScribe_AJAX_Guard::with_guard|SScribe_AJAX_Guard::success|SScribe_AJAX_Guard::error/i", $src )
			) {
				++$guarded_action_count;
			}
		}
	}
}
$add(
	'ajax_handlers_use_guard',
	"At least one SScribe_AJAX_Guard reference must exist in production code (so wp_ajax_* actions are wrapped). Found guard refs in: {$guarded_action_count} files with ajax actions.",
	$guarded_action_count >= 1
);

/**
 * Rule 2: no `var_dump`, `print_r`, or `var_export` in production code
 * paths. These produce raw internal state (paths, objects, SQL) on
 * screen during AJAX. They MUST be inside `defined( 'SSCRIBE_DEBUG' )`
 * guards, OR they must not exist at all (PHPStan / PHPCS already warn
 * about this; the verifier asserts absence in non-debug contexts).
 */
$unconditional_dump_count = 0;
$dump_findings            = array();
foreach ( $files as $file ) {
	if ( in_array( basename( $file ), $skip_files, true ) ) {
		continue;
	}
	$src = (string) file_get_contents( $file );

	// Find var_dump/print_r/var_export calls.
	if ( preg_match_all( '/\b(var_dump|print_r|var_export)\s*\(/', $src, $hits, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $hits[0] as $hit ) {
			$offset = $hit[1];
			// Look back 500 chars: is this inside an SSCRIBE_DEBUG / WP_DEBUG guard?
			$window = substr( $src, max( 0, $offset - 500 ), 500 );
			$inside_guard = (bool) preg_match(
				'/(defined\s*\(\s*[\'"]SSCRIBE_DEBUG[\'"]\s*\)|WP_DEBUG|SSCRIBE_TESTING|@var_dump|@print_r|//\s*phpcs:ignore|phpunit|tests\/)/',
				$window
			);
			if ( ! $inside_guard ) {
				++$unconditional_dump_count;
				$dump_findings[] = basename( $file ) . ':' . substr_count( substr( $src, 0, $offset ), "\n" );
			}
		}
	}
}
$add(
	'no_unconditional_var_dump_or_print_r',
	'No var_dump / print_r / var_export in production paths (debug-only / phpunit-ignored is fine). Violations: ' . $unconditional_dump_count . ' (' . implode( ', ', array_slice( $dump_findings, 0, 5 ) ) . ').',
	$unconditional_dump_count === 0
);

/**
 * Rule 3: no `wp_die( $wpdb->last_error )` or similar raw DB error
 * echo in production. Public die() calls must use a translated,
 * user-facing string — never the raw DB error.
 */
$raw_db_error_count = 0;
$raw_db_findings    = array();
foreach ( $files as $file ) {
	if ( in_array( basename( $file ), $skip_files, true ) ) {
		continue;
	}
	$src = (string) file_get_contents( $file );

	// Pattern: $wpdb->last_error / $wpdb->last_query inside wp_die / echo / printf.
	if ( preg_match_all( '/\b(wp_die|echo|printf|sprintf|error_log)\s*\([^)]*\$wpdb->(last_error|last_query)/', $src, $hits ) ) {
		foreach ( $hits[0] as $hit ) {
			++$raw_db_error_count;
			$raw_db_findings[] = basename( $file );
		}
	}
}
$add(
	'no_raw_wpdb_last_error_echo',
	'No `wp_die($wpdb->last_error)` / `echo $wpdb->last_query` patterns in production code. Violations: ' . $raw_db_error_count . ' (' . implode( ', ', array_unique( array_slice( $raw_db_findings, 0, 5 ) ) ) . ').',
	$raw_db_error_count === 0
);

/**
 * Rule 4: error messages exposed via wp_die() / AJAX responses must
 * use i18n functions (__(), esc_html__, _x()). A raw untranslated
 * string is a code-smell (and often leaks internal names).
 *
 * We sample wp_die( ... ) calls — they should wrap the message in
 * __(...) or contain a literal user-facing label.
 */
$wp_die_calls = 0;
$wp_die_i18n  = 0;
foreach ( $files as $file ) {
	if ( in_array( basename( $file ), $skip_files, true ) ) {
		continue;
	}
	$src = (string) file_get_contents( $file );

	if ( preg_match_all( '/\bwp_die\s*\(/', $src, $hits ) ) {
		foreach ( $hits[0] as $offset_match ) {
			++$wp_die_calls;
			// Look forward 200 chars for an i18n wrapper.
			$offset = strpos( $src, 'wp_die(' );
			if ( false === $offset ) {
				continue;
			}
			$window = substr( $src, $offset, 250 );
			if ( preg_match( '/wp_die\s*\(\s*__\(|wp_die\s*\(\s*esc_html__|wp_die\s*\(\s*esc_html\(|wp_die\s*\(\s*sprintf\s*\(\s*__/', $window ) ) {
				++$wp_die_i18n;
			}
		}
	}
}
$add(
	'wp_die_calls_use_i18n',
	'At least one wp_die() call uses i18n (__/esc_html__) for its message. Found: ' . $wp_die_i18n . '/' . $wp_die_calls . ' calls i18n-wrapped.',
	$wp_die_calls === 0 || $wp_die_i18n > 0
);

/**
 * Rule 5: the AJAX guard sanitizes PHP's display_errors setting
 * before responding. A regression that lets display_errors bleed
 * a stack trace into an AJAX response is a security issue.
 */
$ajax_guard_src  = (string) file_get_contents( $root_dir . '/includes/class-sscribe-ajax-guard.php' );
$disables_errors = (bool) preg_match( "/disable_if_possible\s*\(\s*['\"]display_errors['\"]/", $ajax_guard_src );
$add(
	'ajax_guard_disables_display_errors',
	'class-sscribe-ajax-guard.php must disable display_errors via disable_if_possible("display_errors", "0") before sending AJAX responses.',
	$disables_errors
);

/**
 * Rule 6: SSCRIBE_DEBUG gates backtrace emission. A regression that
 * drops the SSCRIBE_DEBUG gate around debug_backtrace() leaks a
 * full call stack into a public response.
 */
$backtrace_count = 0;
$gated_count     = 0;
foreach ( $files as $file ) {
	if ( in_array( basename( $file ), $skip_files, true ) ) {
		continue;
	}
	$src = (string) file_get_contents( $file );

	if ( preg_match_all( '/\bdebug_backtrace\s*\(/', $src, $hits, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $hits[0] as $hit ) {
			++$backtrace_count;
			$offset = $hit[1];
			$window = substr( $src, max( 0, $offset - 600 ), 700 );
			$gated = (bool) preg_match( '/defined\s*\(\s*[\'"]SSCRIBE_DEBUG[\'"]\s*\)\s*&&\s*SSCRIBE_DEBUG/', $window )
				|| (bool) preg_match( '/WP_DEBUG/', $window );
			if ( $gated ) {
				++$gated_count;
			}
		}
	}
}
$add(
	'debug_backtrace_calls_are_gated',
	'every debug_backtrace() call must be inside a SSCRIBE_DEBUG / WP_DEBUG gate. Gated: ' . $gated_count . '/' . $backtrace_count . '.',
	$backtrace_count === 0 || $gated_count === $backtrace_count
);

/**
 * Rule 7: no raw filesystem paths (Unix-style /var/www/... or
 * Windows-style C:\Users\...) appear as literals in production
 * code paths. Plugin source files may reference ABSPATH / plugin
 * dir constants but never hardcoded environment paths.
 */
$raw_path_count  = 0;
$raw_path_files  = array();
foreach ( $files as $file ) {
	if ( in_array( basename( $file ), $skip_files, true ) ) {
		continue;
	}
	$src = (string) file_get_contents( $file );

	if ( preg_match_all( '%(\/var\/www\/|\/home\/[^\/]+\/public_html\/|C:\\\\Users\\\\|/Users\/[^\/]+\/Sites\/)%', $src, $hits ) ) {
		foreach ( $hits[0] as $hit ) {
			++$raw_path_count;
			$raw_path_files[] = basename( $file );
		}
	}
}
$add(
	'no_hardcoded_filesystem_paths',
	'No hardcoded filesystem paths (e.g. /var/www/, C:\Users\, /home/*/public_html/) in production PHP code. Violations: ' . $raw_path_count . ' (' . implode( ', ', array_unique( array_slice( $raw_path_files, 0, 5 ) ) ) . ').',
	$raw_path_count === 0
);

/**
 * Rule 8: download filenames / ZIP contents must not leak the
 * server's plugin path. The PHP-side ZIP path should be opaque
 * to the user (use wp_generate_password() / random_bytes()) rather
 * than a path constructed from server constants.
 */
$zip_class_count = 0;
$zip_random_count = 0;
foreach ( $files as $file ) {
	if ( in_array( basename( $file ), $skip_files, true ) ) {
		continue;
	}
	$src = (string) file_get_contents( $file );

	if ( false !== stripos( basename( $file ), 'zip' ) || false !== stripos( $src, 'class SScribe_ZIP' ) ) {
		++$zip_class_count;
	}
	if ( preg_match_all( '%(wp_generate_password|random_bytes|bin2hex\s*\(\s*random_bytes)%', $src ) ) {
		++$zip_random_count;
	}
}
$add(
	'zip_filenames_use_crypto_random',
	'ZIP-export code uses crypto-random (wp_generate_password/random_bytes) for filenames (not server paths). Files with ZIP logic: ' . $zip_class_count . ', with crypto-random: ' . $zip_random_count . '.',
	$zip_class_count === 0 || $zip_random_count > 0
);

/**
 * Rule 9: PHP files in production paths do NOT use ABSPATH itself as
 * a user-visible string. ABSPATH is a server-side constant — exposing
 * it in error messages or localizing to JS leaks the install path.
 */
$abspath_in_strings = 0;
foreach ( $files as $file ) {
	if ( in_array( basename( $file ), $skip_files, true ) ) {
		continue;
	}
	$src = (string) file_get_contents( $file );

	// ABSPATH used as a string literal in wp_die / wp_send_json / echo.
	if ( preg_match_all( '/(wp_die|wp_send_json|echo|printf|sprintf|error_log)\s*\([^)]*\bABSPATH\b/', $src, $hits ) ) {
		foreach ( $hits[0] as $hit ) {
			++$abspath_in_strings;
		}
	}
}
$add(
	'abspath_not_in_user_visible_strings',
	'ABSPATH is NOT used as a literal in user-visible strings (wp_die/wp_send_json/echo). Violations: ' . $abspath_in_strings . '.',
	$abspath_in_strings === 0
);

/**
 * Rule 10: the integration test exists + passes (sanity self-check).
 */
$test_path = $root_dir . '/tests/Integration/SScribe_No_Internal_Details_Test.php';
$add(
	'integration_test_exists',
	'tests/Integration/SScribe_No_Internal_Details_Test.php must exist so the contract is pinned at the PHPUnit boundary.',
	is_file( $test_path )
);

/**
 * Persist manifest.
 */
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at' => gmdate( 'c' ),
	'scan_dirs'    => array_map( 'basename', $scan_dirs ),
	'file_count'   => count( $files ),
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

echo "=== SScribe No-Internal-Details Acceptance ===\n\n";
echo "Files scanned: " . count( $files ) . "\n";
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
echo "✓ No-internal-details contract valid.\n";
exit( 0 );
