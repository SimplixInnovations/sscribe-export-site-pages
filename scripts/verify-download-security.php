<?php
/**
 * Phase 48: Download security contract.
 *
 * The download endpoint is gated by a single-use per-row token in the
 * URL (`token=<32-hex>`). The token is generated when the export ZIP
 * is created, persisted on the export row, and atomically validated +
 * rotated when the customer redeems it. A regression in any of these
 * properties ships a release whose download link either:
 *
 *   - leaks across users (token not bound to row)
 *   - works forever (token not rotated on consume)
 *   - leaks timing information (non-constant-time comparison)
 *   - can be replayed (token not single-use)
 *
 * This verifier pins the source-level contract the batch-file-handler
 * and the zip-handler must satisfy. The integration test exercises
 * the live code path with planted fixtures.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir        = dirname( __DIR__ );
$zip_handler     = $root_dir . '/includes/class-sscribe-zip-handler.php';
$batch_handler   = $root_dir . '/includes/class-sscribe-batch-file-handler.php';
$lock_manager    = $root_dir . '/includes/class-sscribe-export-lock-manager.php';
$manifest_path   = $root_dir . '/dist/download-security-manifest.json';

$errors = array();

// Sentinel sentinels so the source files can be parsed by simple
// string matching outside WordPress. We don't require the classes;
// this is a structural audit, not a runtime smoke test.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'SSCRIBE_PLUGIN_DIR' ) ) {
	define( 'SSCRIBE_PLUGIN_DIR', $root_dir . '/' );
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}

foreach ( array( $zip_handler, $batch_handler, $lock_manager ) as $path ) {
	if ( ! is_file( $path ) ) {
		fwrite( STDERR, basename( $path ) . " not found.\n" );
		exit( 1 );
	}
}

$zip_source     = (string) file_get_contents( $zip_handler );
$batch_source   = (string) file_get_contents( $batch_handler );
$lock_source    = (string) file_get_contents( $lock_manager );

$matrix = array();

// 1. consume_dl_token uses hash_equals (constant-time).
$uses_hash_equals = (bool) preg_match(
	'~function\s+consume_dl_token\b[\s\S]*?hash_equals\s*\(\s*\$stored\s*,\s*\$presented\s*\)~',
	$zip_source
);
$matrix[] = array(
	'rule'    => 'consume_dl_token_uses_hash_equals',
	'passes'  => $uses_hash_equals,
	'detail'  => 'consume_dl_token must compare stored vs presented with hash_equals() to prevent timing attacks.',
);
if ( ! $uses_hash_equals ) {
	$errors[] = 'consume_dl_token does not use hash_equals for token comparison.';
}

// 2. consume_dl_token acquires a per-row lock before validate+rotate.
$uses_lock = (bool) preg_match(
	'~function\s+consume_dl_token\b[\s\S]*?acquire_lock\s*\(~',
	$zip_source
);
$matrix[] = array(
	'rule'    => 'consume_dl_token_acquires_lock',
	'passes'  => $uses_lock,
	'detail'  => 'consume_dl_token must serialize validate+rotate via the export lock manager to prevent replay.',
);
if ( ! $uses_lock ) {
	$errors[] = 'consume_dl_token does not acquire an export lock around validate+rotate.';
}

// 3. consume_dl_token rotates the stored token on successful redeem.
$rotates = (bool) preg_match(
	'~function\s+consume_dl_token\b[\s\S]*?\$row\[.dl_token.\]\s*=\s*\$this->generate_dl_token\s*\(\s*\)~',
	$zip_source
);
$matrix[] = array(
	'rule'    => 'consume_dl_token_rotates_on_success',
	'passes'  => $rotates,
	'detail'  => 'consume_dl_token must rotate the stored token after a successful compare so the URL is single-use.',
);
if ( ! $rotates ) {
	$errors[] = 'consume_dl_token does not rotate the stored token after a successful redeem.';
}

// 4. Token format is 32 hex chars (the validator regex).
$token_format = strpos( $zip_source, "'/^[a-f0-9]{32}\$/'" ) !== false;
$matrix[] = array(
	'rule'    => 'token_format_is_32_hex',
	'passes'  => $token_format,
	'detail'  => 'Token format must be /^[a-f0-9]{32}$/ to match bin2hex(random_bytes(16)).',
);
if ( ! $token_format ) {
	$errors[] = 'Token format regex /^[a-f0-9]{32}$/ is missing from the zip-handler source.';
}

// 5. generate_dl_token uses random_bytes(16).
$uses_random_bytes = (bool) preg_match(
	'~function\s+generate_dl_token\b[\s\S]*?random_bytes\s*\(\s*16\s*\)~',
	$zip_source
);
$matrix[] = array(
	'rule'    => 'generate_dl_token_uses_random_bytes',
	'passes'  => $uses_random_bytes,
	'detail'  => 'generate_dl_token must use random_bytes(16) for cryptographic randomness.',
);
if ( ! $uses_random_bytes ) {
	$errors[] = 'generate_dl_token does not use random_bytes(16).';
}

// 6. get_ajax_download_url includes nonce + token + file in URL.
$url_has_args =
	strpos( $zip_source, "'action' => 'sscribe_download'" ) !== false
	&& strpos( $zip_source, "'nonce'  => \$this->cached_nonce" ) !== false
	&& strpos( $zip_source, "'token'  => \$token" ) !== false;
$matrix[] = array(
	'rule'    => 'download_url_includes_nonce_and_token',
	'passes'  => $url_has_args,
	'detail'  => 'get_ajax_download_url must include action=sscribe_download, nonce, and token in the URL.',
);
if ( ! $url_has_args ) {
	$errors[] = 'get_ajax_download_url does not include the canonical action / nonce / token args.';
}

// 7. Batch file handler calls consume_dl_token with filename + raw_token.
$batch_validates = (bool) preg_match(
	'~consume_dl_token\s*\(\s*\$filename\s*,\s*\$raw_token\s*\)~',
	$batch_source
);
$matrix[] = array(
	'rule'    => 'batch_handler_validates_token',
	'passes'  => $batch_validates,
	'detail'  => 'The batch file handler must call consume_dl_token with filename + raw token from the request.',
);
if ( ! $batch_validates ) {
	$errors[] = 'Batch file handler does not call consume_dl_token(filename, raw_token).';
}

// 8. Batch handler logs token rejections (auditor).
$logs_rejection = (bool) preg_match(
	"~log\\s*\\(\\s*'download_token_rejected'~",
	$batch_source
);
$matrix[] = array(
	'rule'    => 'batch_handler_logs_token_rejection',
	'passes'  => $logs_rejection,
	'detail'  => 'A rejected token must be logged as download_token_rejected so the operational log surfaces brute-force attempts.',
);
if ( ! $logs_rejection ) {
	$errors[] = 'Batch file handler does not log download_token_rejected events.';
}

// 9. Lock manager exposes acquire_lock / release_lock.
$lock_has_acquire = (bool) preg_match( '~public\s+function\s+acquire_lock\s*\(~', $lock_source );
$lock_has_release = (bool) preg_match( '~public\s+function\s+release_lock\s*\(~', $lock_source );
$matrix[] = array(
	'rule'    => 'lock_manager_has_acquire_and_release',
	'passes'  => $lock_has_acquire && $lock_has_release,
	'detail'  => 'Lock manager must expose acquire_lock and release_lock for consume_dl_token to serialize.',
);
if ( ! ( $lock_has_acquire && $lock_has_release ) ) {
	$errors[] = 'Export lock manager is missing acquire_lock / release_lock.';
}

// 10. Fail-closed on empty filename.
$fails_closed_empty = (bool) preg_match(
	'~function\s+consume_dl_token\b[\s\S]{0,400}?\'\'\s*===\s*\$zip_filename\s*\|\|\s*\'\'\s*===\s*\$presented~',
	$zip_source
);
$matrix[] = array(
	'rule'    => 'consume_dl_token_fails_closed_on_empty_inputs',
	'passes'  => $fails_closed_empty,
	'detail'  => 'consume_dl_token must return false when either filename or presented token is empty.',
);
if ( ! $fails_closed_empty ) {
	$errors[] = 'consume_dl_token does not fail closed on empty filename or empty presented token.';
}

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at' => gmdate( 'c' ),
	'rule_count'   => count( $matrix ),
	'passed_count' => count( array_filter( $matrix, static fn( $r ) => $r['passes'] ) ),
	'matrix'       => $matrix,
	'errors_count' => count( $errors ),
	'passes'       => 0 === count( $errors ),
	'errors'       => $errors,
);
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

echo "=== SScribe Download Security ===\n\n";
foreach ( $matrix as $row ) {
	$status = $row['passes'] ? '✓' : '✗';
	echo sprintf( "  %s  %-44s %s\n", $status, $row['rule'], $row['detail'] );
}
echo "\nErrors: " . count( $errors ) . "\n";
foreach ( $errors as $error ) {
	echo "  ✗ {$error}\n";
}
echo "\nManifest persisted to: {$manifest_path}\n";

if ( ! empty( $errors ) ) {
	exit( 1 );
}
echo "✓ Download security contract valid.\n";
exit( 0 );
