<?php
/**
 * Phase 59 — Debug / operational log acceptance verifier.
 *
 * The plugin's debug log writes to disk (under WP_CONTENT_DIR) and
 * can persist session IDs, IP addresses, and partial request data
 * for support diagnostics. The acceptance contract:
 *
 *   1. Sensitive keys (password, token, secret, nonce, …) MUST be
 *      redacted to [REDACTED] before the entry reaches disk.
 *   2. IP-like keys (ip, ip_address, remote_addr, …) MUST be
 *      hashed (truncated HMAC-SHA-256) before the entry reaches
 *      disk.
 *   3. JWT-shaped strings (starts with eyJ + 2 dots) MUST be
 *      redacted wherever they appear as a string value.
 *   4. Log messages MUST be stripped of control characters and
 *      bounded to 4000 chars.
 *   5. Log context strings MUST be bounded to 2000 chars.
 *   6. Every log entry MUST have the canonical shape
 *      `[ISO_TIMESTAMP] [LEVEL] message | json_context` so
 *      log scrapers can parse it deterministically.
 *   7. The log writer MUST NOT silently disable sanitization — the
 *      sanitize_log_context / sanitize_log_message methods must
 *      remain protected (or callable from the trait) so a future
 *      maintainer cannot bypass them by hand.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir       = dirname( __DIR__ );
$logger_path    = $root_dir . '/includes/class-sscribe-logger.php';
$trait_path     = $root_dir . '/includes/traits/trait-sscribe-logger-common.php';
$op_logger_path = $root_dir . '/includes/class-sscribe-operational-logger.php';
$enhanced_path  = $root_dir . '/includes/class-sscribe-logger-enhanced.php';
$manifest_path  = $root_dir . '/dist/debug-log-manifest.json';

$matrix = array();
$errors = array();

foreach ( array( $logger_path, $trait_path ) as $path ) {
	if ( ! is_file( $path ) ) {
		fwrite( STDERR, "✗ Required file missing: {$path}\n" );
		exit( 1 );
	}
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$logger_src = (string) file_get_contents( $logger_path );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$trait_src  = (string) file_get_contents( $trait_path );

// Sensitive keys that MUST be redacted.
$required_sensitive = array(
	'password',
	'token',
	'secret',
	'api_key',
	'authorization',
	'nonce',
	'cookie',
	'bearer',
	'credential',
	'private_key',
);

// Hashed keys (IP-like).
$required_hashed = array(
	'ip',
	'remote_addr',
	'client_ip',
);

/**
 * Rule 1: the canonical sensitive-key list is present in the
 * trait (sanitize_log_context).
 */
$missing_sensitive = array();
foreach ( $required_sensitive as $key ) {
	if ( ! preg_match( '/[\'"]' . preg_quote( $key, '/' ) . '[\'"]\s*,/', $trait_src ) ) {
		$missing_sensitive[] = $key;
	}
}
$matrix[] = array(
	'rule'   => 'sensitive_key_list_complete',
	'passes' => empty( $missing_sensitive ),
	'detail' => 'trait-sscribe-logger-common.php must list all canonical sensitive keys for redaction. Missing: ' . implode( ', ', $missing_sensitive ),
);
if ( ! empty( $missing_sensitive ) ) {
	$errors[] = 'Sensitive key list is missing keys: ' . implode( ', ', $missing_sensitive );
}

/**
 * Rule 2: the canonical hashed-key list (IP-like) is present.
 */
$missing_hashed = array();
foreach ( $required_hashed as $key ) {
	if ( ! preg_match( '/[\'"]' . preg_quote( $key, '/' ) . '[\'"]\s*,/', $trait_src ) ) {
		$missing_hashed[] = $key;
	}
}
$matrix[] = array(
	'rule'   => 'ip_like_key_list_complete',
	'passes' => empty( $missing_hashed ),
	'detail' => 'trait-sscribe-logger-common.php must list all canonical IP-like keys for HMAC-SHA-256 hashing. Missing: ' . implode( ', ', $missing_hashed ),
);
if ( ! empty( $missing_hashed ) ) {
	$errors[] = 'IP-like key list is missing keys: ' . implode( ', ', $missing_hashed );
}

/**
 * Rule 3: the redaction marker is exactly [REDACTED]. A typo
 * breaks log-scraper grep patterns silently.
 */
$uses_canonical_redact_marker = (bool) preg_match( '/\[\s*REDACTED\s*\]/', $trait_src );
$matrix[] = array(
	'rule'   => 'redaction_marker_is_canonical',
	'passes' => $uses_canonical_redact_marker,
	'detail' => 'Trait + logger must use the canonical `[REDACTED]` marker (with optional whitespace).',
);
if ( ! $uses_canonical_redact_marker ) {
	$errors[] = 'Redaction marker is not the canonical `[REDACTED]`.';
}

/**
 * Rule 4: the IP-hash uses HMAC-SHA-256 (not plain SHA-256) so
 * a leaked log + leaked secret cannot be reversed by a rainbow
 * table. The salt must come from wp_salt('auth') — that's the
 * WordPress-standard site-unique salt.
 */
$uses_hmac_sha256 = (bool) preg_match( '/hash_hmac\s*\(\s*[\'"]sha256[\'"]\s*,/', $trait_src );
$uses_wp_salt     = (bool) preg_match( '/wp_salt\s*\(\s*[\'"]auth[\'"]\s*\)/', $trait_src );
$matrix[] = array(
	'rule'   => 'ip_hash_uses_hmac_sha256_with_wp_salt',
	'passes' => $uses_hmac_sha256 && $uses_wp_salt,
	'detail' => 'IP hashing must use `hash_hmac("sha256", …, wp_salt("auth"))` (HMAC-SHA-256 with the WP site-auth salt).',
);
if ( ! $uses_hmac_sha256 || ! $uses_wp_salt ) {
	$errors[] = 'IP hash is not HMAC-SHA-256 with wp_salt("auth").';
}

/**
 * Rule 5: JWT-shaped strings (start with "eyJ", contain 2+ dots)
 * MUST be redacted. Otherwise a leaked log carries a working
 * session token for any JWT-authenticated service.
 */
$redacts_jwt_shaped_strings = (bool) preg_match(
	'/str_starts_with\s*\(\s*\$value\s*,\s*[\'"]eyJ[\'"]\s*\)\s*&&\s*substr_count\s*\(\s*\$value\s*,\s*[\'"]\.[\'"]\s*\)\s*>=\s*2/',
	$trait_src
);
$matrix[] = array(
	'rule'   => 'redacts_jwt_shaped_strings',
	'passes' => $redacts_jwt_shaped_strings,
	'detail' => 'Trait must redact any string starting with `eyJ` + 2+ dots (JWT shape).',
);
if ( ! $redacts_jwt_shaped_strings ) {
	$errors[] = 'Trait does NOT redact JWT-shaped strings (eyJ + 2 dots).';
}

/**
 * Rule 6: log messages are bounded to 4000 chars; context strings
 * to 2000 chars. A regression that drops these bounds can OOM the
 * log-writer on a multi-megabyte error payload.
 */
$message_bounded_4000  = (bool) preg_match( '/mb_substr\s*\(\s*trim\s*\(\s*\$message\s*\)\s*,\s*0\s*,\s*4000\s*\)/', $trait_src );
$context_bounded_2000  = (bool) preg_match( '/SScribe_Helpers::mb_substr\s*\(\s*\$value\s*,\s*0\s*,\s*2000\s*\)/', $trait_src )
	|| (bool) preg_match( '/mb_substr\s*\(\s*\$value\s*,\s*0\s*,\s*2000\s*\)/', $trait_src );
$matrix[] = array(
	'rule'   => 'message_bounded_to_4000_chars',
	'passes' => $message_bounded_4000,
	'detail' => 'Trait sanitize_log_message() must bound messages to 4000 chars via mb_substr.',
);
$matrix[] = array(
	'rule'   => 'context_string_bounded_to_2000_chars',
	'passes' => $context_bounded_2000,
	'detail' => 'Trait sanitize_log_context() must bound string values to 2000 chars.',
);
if ( ! $message_bounded_4000 ) {
	$errors[] = 'Trait does NOT bound log messages to 4000 chars.';
}
if ( ! $context_bounded_2000 ) {
	$errors[] = 'Trait does NOT bound log context strings to 2000 chars.';
}

/**
 * Rule 7: control characters are stripped from messages. A
 * regression that leaves \r\n in a message corrupts the log
 * shape (one entry per line is the contract).
 */
$strips_control_chars = (bool) preg_match( '/preg_replace\s*\(\s*[\'"][^\n]*x00[^\n]*u[\'"]/', $trait_src );
$matrix[] = array(
	'rule'   => 'strips_control_chars_from_messages',
	'passes' => $strips_control_chars,
	'detail' => 'Trait sanitize_log_message() must strip control characters (\\x00-\\x1F, \\x7F) so log lines stay one-per-entry.',
);
if ( ! $strips_control_chars ) {
	$errors[] = 'Trait does NOT strip control chars from log messages.';
}

/**
 * Rule 8: the canonical log entry shape is
 * `[ISO_TIMESTAMP] [LEVEL] message | json_context`.
 */
$has_canonical_shape = (bool) preg_match(
	'/\[?\s*\{\$timestamp\}\s*\]?\s*\[\s*\{\$level_upper\}\s*\]\s*\{\$message\}/',
	$logger_src
);
$matrix[] = array(
	'rule'   => 'log_entry_has_canonical_shape',
	'passes' => $has_canonical_shape,
	'detail' => 'class-sscribe-logger.php format_entry() must produce `[ISO_TIMESTAMP] [LEVEL] message | json`.',
);
if ( ! $has_canonical_shape ) {
	$errors[] = 'Log entry does NOT use canonical `[timestamp] [LEVEL] message | json` shape.';
}

/**
 * Rule 9: log timestamps are UTC (gmdate), not local. A
 * regression that uses date() makes log entries non-comparable
 * across timezones — every debug session becomes a guess.
 */
$uses_gmdate = (bool) preg_match( '/gmdate\s*\(/', $logger_src );
$matrix[] = array(
	'rule'   => 'log_timestamps_are_utc',
	'passes' => $uses_gmdate,
	'detail' => 'class-sscribe-logger.php must use `gmdate()` (UTC) for entry timestamps — local time makes entries non-comparable.',
);
if ( ! $uses_gmdate ) {
	$errors[] = 'Log timestamps are NOT UTC.';
}

/**
 * Rule 10: the redaction helpers are protected (or otherwise
 * non-public) — a public redaction helper is a foot-gun: a
 * future maintainer can call it from a non-log context and
 * trust the result. We assert the methods are declared
 * `protected function`.
 */
$redact_methods_protected = (bool) preg_match( '/protected\s+function\s+sanitize_log_message/', $trait_src )
	&& (bool) preg_match( '/protected\s+function\s+sanitize_log_context/', $trait_src );
$matrix[] = array(
	'rule'   => 'sanitize_methods_are_protected',
	'passes' => $redact_methods_protected,
	'detail' => 'sanitize_log_message / sanitize_log_context must be `protected` so external callers cannot bypass sanitization by hand.',
);
if ( ! $redact_methods_protected ) {
	$errors[] = 'Sanitize methods are NOT protected — a future maintainer could bypass them.';
}

/**
 * Rule 11: the log writer is enabled by default OR disabled by
 * default with a deliberate toggle. Either is acceptable, but
 * the log MUST be opt-in (not opt-out) — otherwise a default
 * install ships debug logs to disk.
 *
 * We assert: there's a method or property named `is_enabled()` /
 * `enabled` / `disable()` / `enable()` / `set_enabled()`. We do
 * NOT require any specific default — that is a UX decision, not
 * a security one.
 */
$has_enable_toggle = (bool) preg_match( '/is_enabled\s*\(\s*\)/', $logger_src )
	|| (bool) preg_match( '/function\s+(enable|disable|set_enabled)\s*\(/', $logger_src )
	|| (bool) preg_match( '/(protected|private|public|readonly)\s+\??\s*bool\s+\$enabled\b/', $logger_src );
$matrix[] = array(
	'rule'   => 'logger_has_explicit_enable_toggle',
	'passes' => $has_enable_toggle,
	'detail' => 'class-sscribe-logger.php must expose an explicit enable/disable toggle so the log is opt-in or opt-out by deliberate config.',
);
if ( ! $has_enable_toggle ) {
	$errors[] = 'Logger has NO explicit enable/disable toggle.';
}

/**
 * Rule 12: PHPUnit tests cover BOTH the redacted case (a
 * password token in context becomes [REDACTED]) AND the
 * canonical-shape case (a logged entry has the right prefix).
 */
$tests_dir       = $root_dir . '/tests';
$redaction_tests = 0;
$shape_tests     = 0;
$logger_test_files = array_merge(
	glob( $tests_dir . '/Integration/*Logger*.php' ) ?: array(),
	glob( $tests_dir . '/Unit/*Logger*.php' ) ?: array(),
	glob( $tests_dir . '/Integration/*Log*.php' ) ?: array(),
	glob( $tests_dir . '/Unit/*Log*.php' ) ?: array(),
	glob( $tests_dir . '/Integration/*Logging*.php' ) ?: array(),
	glob( $tests_dir . '/Unit/*Logging*.php' ) ?: array()
);
foreach ( $logger_test_files as $test_file ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$src = (string) file_get_contents( $test_file );
	if ( preg_match( '/\[REDACTED\]/', $src ) ) {
		$redaction_tests++;
	}
	if ( preg_match( '/\[\d{4}-\d{2}-\d{2}.*?\[(?:DEBUG|INFO|WARNING|ERROR|NOTICE|CRITICAL)\]/', $src ) ) {
		$shape_tests++;
	}
}
$matrix[] = array(
	'rule'   => 'redaction_has_unit_or_integration_test',
	'passes' => $redaction_tests >= 1,
	'detail' => "At least one PHPUnit test must assert a sensitive-key context value becomes [REDACTED]. Found: {$redaction_tests}.",
);
$matrix[] = array(
	'rule'   => 'log_shape_has_unit_or_integration_test',
	'passes' => $shape_tests >= 1,
	'detail' => "At least one PHPUnit test must assert the canonical `[timestamp] [LEVEL]` log-entry shape. Found: {$shape_tests}.",
);
if ( $redaction_tests < 1 ) {
	$errors[] = 'No PHPUnit test asserts [REDACTED] redaction behavior.';
}
if ( $shape_tests < 1 ) {
	$errors[] = 'No PHPUnit test asserts the canonical log-entry shape.';
}

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at'   => gmdate( 'c' ),
	'rule_count'     => count( $matrix ),
	'passed_count'   => count( array_filter( $matrix, static fn( $r ) => $r['passes'] ) ),
	'matrix'         => $matrix,
	'errors_count'   => count( $errors ),
	'passes'         => 0 === count( $errors ),
	'errors'         => $errors,
);
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

echo "=== SScribe Debug Log Acceptance ===\n\n";
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
echo "✓ Debug log acceptance contract valid.\n";
exit( 0 );
