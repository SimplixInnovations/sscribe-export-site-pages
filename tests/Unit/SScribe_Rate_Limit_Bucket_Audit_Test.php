<?php
/**
 * Phase 15 audit: every rate-limit call site in production source must
 * pass a bucket argument that resolves to the canonical BUCKETS allow-list
 * (or, for new code, an explicit alias also in the allow-list).
 *
 * Background: SScribe_Export_Rate_Limiter::normalise_bucket() silently
 * falls back to BUCKET_DEFAULT when an unknown bucket name is supplied,
 * because silent fallback is what callers want for typo-safety. That
 * means a misnamed bucket does not crash — it just shares a counter with
 * whatever happens to live at the default key (currently 'export').
 *
 * Before this audit, the debug admin module used three bucket names
 * ('debug_settings', 'debug_read', 'debug_delete'). Only 'debug_read'
 * was canonical; the other two were silently aliased to the default
 * 'export' counter, so a user clearing the debug log shared their quota
 * with the actual export-start action. This test locks down the contract
 * by reading every production source file and asserting each call site's
 * bucket argument is canonical.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SScribe_Rate_Limit_Bucket_Audit_Test extends TestCase {

	/**
	 * Production source files that may call check_rate_limit*().
	 *
	 * The autoloader + bootstrap guarantee these are the only files in
	 * the plugin tree that touch the limiter; tests/, fake-wp/, and the
	 * vendor/ tree are deliberately excluded.
	 *
	 * @return array<string, string>  label => absolute path
	 */
	private static function production_sources(): array {
		$root = dirname( __DIR__, 2 );
		return array(
			'query-controller'        => $root . '/includes/class-sscribe-export-query-controller.php',
			'batch-processor'         => $root . '/includes/class-sscribe-batch-processor.php',
			'batch-file-handler'      => $root . '/includes/class-sscribe-batch-file-handler.php',
			'trait-session-ajax'      => $root . '/includes/traits/trait-sscribe-session-ajax.php',
			'trait-batch-step'        => $root . '/includes/traits/trait-sscribe-batch-step-handler.php',
			'trait-export-finalizer'  => $root . '/includes/traits/trait-sscribe-export-finalizer.php',
			'admin-debug'             => $root . '/admin/class-sscribe-admin-debug.php',
		);
	}

	/**
	 * Every bucket argument passed to check_rate_limit*() in production
	 * source must appear in {@see SScribe_Export_Rate_Limiter::BUCKETS}.
	 *
	 * Excludes comments and the limiter class itself (which defines
	 * BUCKETS) so the audit is not self-referential.
	 *
	 * @dataProvider provideProductionSource
	 */
	#[DataProvider( 'provideProductionSource' )]
	public function test_every_bucket_argument_is_canonical( string $label, string $path ): void {
		$source = file_get_contents( $path );
		$this->assertNotFalse( $source, "Could not read {$path}" );

		$canonical = \SScribe_Export_Rate_Limiter::BUCKETS;

		// Match the bucket argument in either form:
//   check_rate_limit_decision('export_batch')            -> bucket is 1st arg
//   check_rate_limit_decision( $cap, 'export_batch' )    -> bucket is 2nd arg
//   verify_request_authorization('debug_write', 'cap')   -> bucket is 1st arg
//
// Two distinct patterns so the optional-skip branch cannot gobble a
// second quoted arg. The "1st arg" branch requires a quoted string
// immediately after `(`. The "2nd arg" branch requires a non-quoted
// first arg (capability/identity variable) followed by a quoted string.
		$pattern = '/(?:check_rate_limit(?:_decision)?|verify_request_authorization)\s*\(\s*(?:[^\'"(\n]+,\s*)?[\'"]([a-z_]+)[\'"]/i';
		$found   = array();

		if ( preg_match_all( $pattern, $source, $matches ) ) {
			foreach ( $matches[1] as $bucket ) {
				$found[] = strtolower( $bucket );
			}
		}

		$this->assertNotEmpty(
			$found,
			"File {$label} was scanned but no check_rate_limit() calls were detected; either remove it from the audit list or restore the expected call sites."
		);

		$non_canonical = array_values( array_diff( array_unique( $found ), $canonical ) );
		$this->assertSame(
			array(),
			$non_canonical,
			"File {$label} ({$path}) calls check_rate_limit*() with non-canonical bucket(s): " . implode( ', ', $non_canonical ) . '. Add the bucket to SScribe_Export_Rate_Limiter::BUCKETS or fix the call site.'
		);
	}

	/**
	 * Document the canonical (AJAX handler / hook → bucket) inventory.
	 *
	 * Locks down the contract so a refactor that drops the bucket
	 * argument (or renames a handler without updating the bucket) fails
	 * the test instead of silently sharing a counter with the wrong
	 * operation.
	 *
	 * @dataProvider provideDocumentedBuckets
	 */
	#[DataProvider( 'provideDocumentedBuckets' )]
	public function test_documented_bucket_inventory_holds( string $file, string $handler_method, string $expected_bucket ): void {
		$root     = dirname( __DIR__, 2 );
		$path     = $root . $file;
		$source   = file_get_contents( $path );
		$this->assertNotFalse( $source );

		// Walk the file once and pair each handler declaration with the
		// first check_rate_limit*() or verify_request_authorization()
		// call that occurs *after* it but before the next handler
		// declaration. That call's bucket is the one under audit.
		$lines = preg_split( '/\r?\n/', $source );
		$found = null;
		foreach ( $lines as $line ) {
			if ( preg_match( '/function\s+' . preg_quote( $handler_method, '/' ) . '\s*\(/', $line ) ) {
				$found = false;
				continue;
			}
			if ( false === $found && preg_match( '/check_rate_limit(?:_decision)?\s*\(\s*(?:[^,]+,\s*)?[\'"]([a-z_]+)[\'"]/i', $line, $matches ) ) {
				$found = strtolower( $matches[1] );
				break;
			}
			if ( false === $found && preg_match( '/verify_request_authorization\s*\(\s*[\'"]([a-z_]+)[\'"]/i', $line, $matches ) ) {
				$found = strtolower( $matches[1] );
				break;
			}
		}

		$this->assertNotFalse(
			$found,
			"Could not locate a check_rate_limit() call inside {$handler_method}() in {$file}"
		);

		$this->assertSame(
			$expected_bucket,
			$found,
			"Bucket for {$handler_method}() in {$file} drifted to '{$found}'. Update this test to lock the new contract."
		);
	}

	public static function provideProductionSource(): array {
		$out = array();
		foreach ( self::production_sources() as $label => $path ) {
			$out[] = array( $label, $path );
		}
		return $out;
	}

	public static function provideDocumentedBuckets(): array {
		return array(
			// Export query controller: every handler explicitly named.
			array( '/includes/class-sscribe-export-query-controller.php', 'ajax_get_status_counts',         'export_read' ),
			array( '/includes/class-sscribe-export-query-controller.php', 'ajax_get_all_status_counts',     'export_read' ),
			array( '/includes/class-sscribe-export-query-controller.php', 'ajax_get_export_preview',       'export_read' ),
			array( '/includes/class-sscribe-export-query-controller.php', 'ajax_get_recent_exports',       'export_read' ),
			array( '/includes/class-sscribe-export-query-controller.php', 'ajax_preflight_check',          'health' ),

			// Trait-driven handlers (handler methods on the using class).
			array( '/includes/traits/trait-sscribe-batch-step-handler.php',    'ajax_process_batch',     'export_batch' ),
			array( '/includes/traits/trait-sscribe-export-finalizer.php',      'ajax_finalize_export',   'export_finalize' ),
			array( '/includes/traits/trait-sscribe-session-ajax.php',          'ajax_check_active_session','export_read' ),
			array( '/includes/traits/trait-sscribe-session-ajax.php',          'ajax_cancel_export',     'export_start' ),
			array( '/includes/traits/trait-sscribe-session-ajax.php',          'ajax_clear_session',     'export_start' ),

			// Debug admin: every handler explicitly named.
			array( '/admin/class-sscribe-admin-debug.php', 'ajax_debug_save_settings',            'debug_write' ),
			array( '/admin/class-sscribe-admin-debug.php', 'ajax_debug_clear_logs',              'debug_write' ),
			array( '/admin/class-sscribe-admin-debug.php', 'ajax_debug_delete_rotated',          'debug_write' ),
			array( '/admin/class-sscribe-admin-debug.php', 'ajax_debug_fetch_logs',              'debug_read' ),
			array( '/admin/class-sscribe-admin-debug.php', 'ajax_debug_fetch_rotated',           'debug_read' ),
			array( '/admin/class-sscribe-admin-debug.php', 'ajax_debug_get_rotated_log_files',   'debug_read' ),
			array( '/admin/class-sscribe-admin-debug.php', 'ajax_debug_refresh_nonce',           'debug_read' ),
		);
	}
}