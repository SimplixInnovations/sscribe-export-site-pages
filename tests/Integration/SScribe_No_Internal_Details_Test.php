<?php
/**
 * Phase 60 — No raw internal details integration test.
 *
 * The plugin must NEVER leak internal implementation details to
 * user-facing surfaces. This test pins that contract at the
 * PHPUnit boundary:
 *
 *   - AJAX handlers use SScribe_AJAX_Guard (which sanitizes the
 *     environment before responding).
 *   - No raw var_dump / print_r in production code paths.
 *   - No raw $wpdb->last_error echoes in user-facing strings.
 *   - debug_backtrace() is SSCRIBE_DEBUG-gated.
 *   - No hardcoded filesystem paths in production PHP.
 *   - ABSPATH is not in any wp_die / wp_send_json / echo string.
 *
 * A regression that violates any of these fails this test BEFORE
 * it reaches a release tag.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_No_Internal_Details_Test extends TestCase {

	private const VERIFIER_PATH      = 'scripts/verify-no-internal-details.php';
	private const MANIFEST_PATH      = 'dist/no-internal-details-manifest.json';
	private const AJAX_GUARD_PATH    = 'includes/class-sscribe-ajax-guard.php';
	private const INCLUDES_DIR       = 'includes';
	private const ADMIN_DIR          = 'admin';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	private function run_verifier(): array {
		$root        = self::plugin_root();
		$descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
		$process     = proc_open( array( PHP_BINARY, $root . '/' . self::VERIFIER_PATH ), $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
			throw new \RuntimeException( 'Could not spawn verifier subprocess.' );
		}
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		return array( (int) $code, $stdout . $stderr );
	}

	private static function collect_php_files( string $relative_dir ): array {
		$root = self::plugin_root() . '/' . $relative_dir;
		if ( ! is_dir( $root ) ) {
			return array();
		}
		$iter = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);
		$out = array();
		foreach ( $iter as $file ) {
			if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
				$out[] = (string) $file->getPathname();
			}
		}
		return $out;
	}

	public function test_live_manifest_passes_verifier(): void {
		list( $code, $output ) = $this->run_verifier();
		$this::assertSame(
			0,
			$code,
			'Phase 60 no-internal-details verifier must return exit 0. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'No-internal-details contract valid', $output );
	}

	public function test_manifest_records_all_rules_passing(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'] );
		$this::assertGreaterThanOrEqual( 10, $payload['rule_count'] );
		$this::assertSame( $payload['rule_count'], $payload['passed_count'] );
		$this::assertSame( 0, $payload['errors_count'] );
	}

	public function test_ajax_guard_displays_errors_off(): void {
		$src = (string) file_get_contents( self::plugin_root() . '/' . self::AJAX_GUARD_PATH );
		$this::assertMatchesRegularExpression(
			'/disable_if_possible\s*\(\s*[\'"]display_errors[\'"]/',
			$src,
			'AJAX guard MUST disable display_errors before sending any response.'
		);
	}

	public function test_ajax_guard_cleans_output_buffers(): void {
		$src = (string) file_get_contents( self::plugin_root() . '/' . self::AJAX_GUARD_PATH );
		$this::assertStringContainsString( 'ob_get_contents', $src );
		$this::assertStringContainsString( 'ob_clean', $src );
		// The guard must clean extraneous output buffers before responding so
		// a stray PHP warning doesn't bleed into the JSON body.
		$this::assertMatchesRegularExpression( '/log_cleaned_buffers/', $src );
	}

	public function test_debug_backtrace_is_debug_gated(): void {
		$files = array_merge(
			self::collect_php_files( self::INCLUDES_DIR ),
			self::collect_php_files( self::ADMIN_DIR )
		);

		$violations = array();
		foreach ( $files as $file ) {
			$src = (string) file_get_contents( $file );
			if ( ! preg_match_all( '/\bdebug_backtrace\s*\(/', $src, $hits, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $hits[0] as $hit ) {
				$offset = $hit[1];
				$window = substr( $src, max( 0, $offset - 600 ), 700 );
				$gated = (bool) preg_match( '/defined\s*\(\s*[\'"]SSCRIBE_DEBUG[\'"]\s*\)\s*&&\s*SSCRIBE_DEBUG/', $window )
					|| (bool) preg_match( '/WP_DEBUG/', $window );
				if ( ! $gated ) {
					$violations[] = basename( $file );
				}
			}
		}
		$this::assertSame(
			array(),
			array_values( array_unique( $violations ) ),
			'Every debug_backtrace() call must be SSCRIBE_DEBUG / WP_DEBUG gated. Ungated files: ' . implode( ', ', array_unique( $violations ) )
		);
	}

	public function test_no_hardcoded_filesystem_paths_in_production_php(): void {
		$files = array_merge(
			self::collect_php_files( self::INCLUDES_DIR ),
			self::collect_php_files( self::ADMIN_DIR )
		);

		$violations = array();
		foreach ( $files as $file ) {
			$src = (string) file_get_contents( $file );
			if ( preg_match_all( '/(\/var\/www\/|\/home\/[^\/]+\/public_html\/|C:\\\\Users\\\\|\/Users\/[^\/]+\/Sites\/)/', $src, $hits ) ) {
				foreach ( $hits[0] as $hit ) {
					$violations[] = basename( $file ) . ' (' . $hit . ')';
				}
			}
		}
		$this::assertSame(
			array(),
			$violations,
			'No hardcoded filesystem paths in production PHP. Violations: ' . implode( ', ', $violations )
		);
	}

	public function test_no_raw_wpdb_error_in_user_facing_strings(): void {
		$files = array_merge(
			self::collect_php_files( self::INCLUDES_DIR ),
			self::collect_php_files( self::ADMIN_DIR )
		);

		$violations = array();
		foreach ( $files as $file ) {
			$src = (string) file_get_contents( $file );
			if ( preg_match_all( '/\b(wp_die|echo|printf|sprintf|error_log)\s*\([^)]*\$wpdb->(last_error|last_query)/', $src, $hits ) ) {
				foreach ( $hits[0] as $hit ) {
					$violations[] = basename( $file );
				}
			}
		}
		$this::assertSame(
			array(),
			array_values( array_unique( $violations ) ),
			'No raw $wpdb->last_error / last_query in user-facing strings. Violations: ' . implode( ', ', array_unique( $violations ) )
		);
	}

	public function test_abspath_not_in_user_visible_strings(): void {
		$files = array_merge(
			self::collect_php_files( self::INCLUDES_DIR ),
			self::collect_php_files( self::ADMIN_DIR )
		);

		$violations = array();
		foreach ( $files as $file ) {
			$src = (string) file_get_contents( $file );
			if ( preg_match_all( '/(wp_die|wp_send_json|echo|printf|sprintf|error_log)\s*\([^)]*\bABSPATH\b/', $src, $hits ) ) {
				foreach ( $hits[0] as $hit ) {
					$violations[] = basename( $file );
				}
			}
		}
		$this::assertSame(
			array(),
			array_values( array_unique( $violations ) ),
			'ABSPATH must not be used as a literal in user-visible strings. Violations: ' . implode( ', ', array_unique( $violations ) )
		);
	}

	public function test_no_unconditional_var_dump_in_production(): void {
		$files = array_merge(
			self::collect_php_files( self::INCLUDES_DIR ),
			self::collect_php_files( self::ADMIN_DIR )
		);

		$violations = array();
		foreach ( $files as $file ) {
			$src = (string) file_get_contents( $file );
			if ( ! preg_match_all( '/\b(var_dump|print_r|var_export)\s*\(/', $src, $hits, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $hits[0] as $hit ) {
				$offset = $hit[1];
				$window = substr( $src, max( 0, $offset - 500 ), 500 );
				$inside_guard = (bool) preg_match(
					'/(defined\s*\(\s*[\'"]SSCRIBE_DEBUG[\'"]\s*\)|WP_DEBUG|SSCRIBE_TESTING|@var_dump|@print_r|phpunit|tests\/)/',
					$window
				);
				if ( ! $inside_guard ) {
					$violations[] = basename( $file ) . ':' . substr_count( substr( $src, 0, $offset ), "\n" );
				}
			}
		}
		$this::assertSame(
			array(),
			$violations,
			'No unconditional var_dump/print_r/var_export in production code paths. Violations: ' . implode( ', ', $violations )
		);
	}

	public function test_at_least_one_ajax_action_uses_guard(): void {
		$files = array_merge(
			self::collect_php_files( self::INCLUDES_DIR ),
			self::collect_php_files( self::ADMIN_DIR )
		);

		$ajax_action_count = 0;
		$guarded_count     = 0;
		foreach ( $files as $file ) {
			$src = (string) file_get_contents( $file );
			if ( preg_match_all( "/add_action\s*\(\s*['\"]wp_ajax_[^'\"]+['\"]/", $src, $hits ) ) {
				$ajax_action_count += count( $hits[0] );
				if ( preg_match( '/SScribe_AJAX_Guard::/', $src ) ) {
					$guarded_count += count( $hits[0] );
				}
			}
		}

		$this::assertGreaterThanOrEqual(
			1,
			$ajax_action_count,
			'At least one wp_ajax_* action must be registered for the guard contract to apply.'
		);
		$this::assertGreaterThanOrEqual(
			1,
			$guarded_count,
			'At least one wp_ajax_* action must be wrapped in SScribe_AJAX_Guard (with_guard / success / error).'
		);
	}
}
