<?php
/**
 * SScribe Continue-On-Error Integration Test
 *
 * @package SScribe_Export_Site_Pages
 *
 * Phase 31: locks the continue-on-error contract.
 *
 * A real export run rarely fails every page — it usually fails one
 * badly-malformed post or hits a single memory spike. The batch loop
 * MUST therefore treat per-page failures as data points, not as a
 * signal to abort the whole run. Without this guarantee, one bad page
 * wastes every page already exported and every page queued after it.
 *
 * This integration test runs scripts/verify-continue-on-error.php
 * against the live repo and against a series of synthetic mutations:
 * drop the per-page try/catch, swap `continue` for `break` on the
 * early-exit paths, drop the do_action seam, drop the schema column,
 * drop the failed_pages persistence, drop the per-method param, drop
 * the finalizer tally. A regression that:
 *
 *   - silently accepts a try/catch stripped from dispatch_formats,
 *   - silently accepts `break` instead of `continue`,
 *   - silently accepts a dropped sscribe_after_export_page action,
 *   - silently accepts a dropped failed_pages column in the schema,
 *   - silently accepts a dropped failed_pages persistence in stats,
 *   - silently accepts a dropped failed_pages tally in the finalizer,
 *
 * ...fails the suite immediately.
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Continue_On_Error_Test extends TestCase {

	private const SCRIPT_PATH = 'scripts/verify-continue-on-error.php';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Run the verifier against a series of mutated production files.
	 *
	 * @param array<string,string|null> $mutations path => new_contents (null = leave unchanged).
	 * @return array{0:int,1:string}
	 */
	private function run_with_mutations( array $mutations ): array {
		$backups = array();
		try {
			foreach ( $mutations as $path => $new_contents ) {
				if ( null === $new_contents ) {
					continue;
				}
				$abs        = self::plugin_root() . '/' . $path;
				$backups[ $path ] = file_get_contents( $abs );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $abs, $new_contents );
			}

			$descriptors = array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			);
			$process = proc_open(
				array( PHP_BINARY, self::plugin_root() . '/' . self::SCRIPT_PATH ),
				$descriptors,
				$pipes
			);
			$this::assertIsResource( $process );
			$stdout = (string) stream_get_contents( $pipes[1] );
			$stderr = (string) stream_get_contents( $pipes[2] );
			$code   = proc_close( $process );

			return array( (int) $code, $stdout . $stderr );
		} finally {
			foreach ( $backups as $path => $contents ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( self::plugin_root() . '/' . $path, $contents );
			}
		}
	}

	private function live_batch_trait(): string {
		return (string) file_get_contents( self::plugin_root() . '/includes/traits/trait-sscribe-batch-step-handler.php' );
	}

	private function live_finalizer(): string {
		return (string) file_get_contents( self::plugin_root() . '/includes/traits/trait-sscribe-export-finalizer.php' );
	}

	private function live_activator(): string {
		return (string) file_get_contents( self::plugin_root() . '/includes/class-sscribe-activator.php' );
	}

	private function live_export_stats(): string {
		return (string) file_get_contents( self::plugin_root() . '/includes/class-sscribe-export-stats.php' );
	}

	private function live_upgrader(): string {
		return (string) file_get_contents( self::plugin_root() . '/includes/class-sscribe-upgrader.php' );
	}

	public function test_live_repo_passes(): void {
		list( $code, $output ) = $this->run_with_mutations( array() );
		$this::assertSame(
			0,
			$code,
			'Live repo must satisfy the continue-on-error contract. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'Continue-on-error contract holds', $output );
	}

	public function test_dropped_try_catch_fails(): void {
		$src = $this->live_batch_trait();
		// Replace the `try {` keyword (only the inner one around dispatch_formats)
		// with `if (true) {` so the dispatch call no longer has try/catch protection.
		// The verifier reads source as text, so a syntax error here doesn't matter —
		// we are simulating "the contract regressed" rather than producing a
		// functional file.
		$mutated = preg_replace(
			'/(\$pre_export_memory_mb\s*=\s*\(int\)\s*apply_filters\(\s*.sscribe_min_memory_per_page_mb.[^;]+;\s*\n\t+)\}\s*elseif\s*\(\s*\\\$this->is_memory_available\(\s*\\\$pre_export_memory_mb\s*\)\s*\)\s*\{\s*\}\s*try\s*\{/m',
			'$1} elseif ( ! $this->is_memory_available( $pre_export_memory_mb ) ) { } if (true) {',
			$src,
			1,
			$count
		);
		// Simpler approach: directly replace `try {` followed by $page_data['_batch_start_time']
		if ( 0 === $count ) {
			$mutated = preg_replace(
				'/try\s*\{\s*\\$page_data\[.\\_batch_start_time.\]/',
				'if ( true ) { $page_data[\'_batch_start_time\']',
				$src,
				1,
				$count
			);
		}
		$this::assertSame( 1, $count, 'preg_replace should have removed the try block; check pattern.' );
		list( $code, $output ) = $this->run_with_mutations( array(
			'includes/traits/trait-sscribe-batch-step-handler.php' => $mutated,
		) );
		$this::assertSame( 1, $code, 'dropped try/catch must fail the verifier' );
		$this::assertStringContainsString( 'try/catch', $output );
	}

	public function test_break_replaces_continue_on_no_page_data(): void {
		$src = $this->live_batch_trait();
		$mutated = preg_replace(
			'/(Failed to collect data for page ID[\s\S]{0,2000}?)continue(\s*;)/',
			'$1break$2',
			$src,
			1,
			$count
		);
		$this::assertSame( 1, $count );
		list( $code, $output ) = $this->run_with_mutations( array(
			'includes/traits/trait-sscribe-batch-step-handler.php' => $mutated,
		) );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'no_page_data', $output );
	}

	public function test_break_replaces_continue_on_insufficient_memory(): void {
		$src = $this->live_batch_trait();
		$mutated = preg_replace(
			'/(Skipped page %d - insufficient memory[\s\S]{0,2000}?)continue(\s*;)/',
			'$1break$2',
			$src,
			1,
			$count
		);
		$this::assertSame( 1, $count );
		list( $code, $output ) = $this->run_with_mutations( array(
			'includes/traits/trait-sscribe-batch-step-handler.php' => $mutated,
		) );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'insufficient_memory', $output );
	}

	public function test_dropped_after_export_page_action_fails(): void {
		$src = $this->live_batch_trait();
		$mutated = preg_replace(
			"/do_action\(\s*'sscribe_after_export_page'[^)]*\)\s*;/",
			'// removed',
			$src,
			1,
			$count
		);
		$this::assertSame( 1, $count );
		list( $code, $output ) = $this->run_with_mutations( array(
			'includes/traits/trait-sscribe-batch-step-handler.php' => $mutated,
		) );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'sscribe_after_export_page', $output );
	}

	public function test_dropped_failed_pages_column_in_activator_fails(): void {
		$src = $this->live_activator();
		$mutated = preg_replace(
			'/\s*failed_pages\s+INT\s+UNSIGNED,/i',
			"\n\t\t\t\t// failed_pages column dropped",
			$src,
			1,
			$count
		);
		$this::assertSame( 1, $count );
		list( $code, $output ) = $this->run_with_mutations( array(
			'includes/class-sscribe-activator.php' => $mutated,
		) );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'failed_pages', $output );
	}

	public function test_dropped_canonical_schema_delegation_in_upgrader_fails(): void {
		$src = $this->live_upgrader();
		$mutated = preg_replace(
			'/SScribe_Activator::ensure_database_schema\s*\(\s*false\s*\)\s*;/',
			'// canonical schema delegation dropped',
			$src,
			1,
			$count
		);
		$this::assertSame( 1, $count );
		list( $code, $output ) = $this->run_with_mutations(
			array(
				'includes/class-sscribe-upgrader.php' => $mutated,
			)
		);
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'canonical', strtolower( $output ) );
	}

	public function test_dropped_failed_pages_in_complete_export_fails(): void {
		$src = $this->live_export_stats();
		$mutated = preg_replace(
			"/'failed_pages'\s*=>\s*max\s*\(\s*0\s*,\s*\(int\)\s*\(\s*\\\$results\[.failed_pages.\][^,]*,/s",
			"// failed_pages persistence dropped,",
			$src,
			1,
			$count
		);
		$this::assertSame( 1, $count );
		list( $code, $output ) = $this->run_with_mutations( array(
			'includes/class-sscribe-export-stats.php' => $mutated,
		) );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'failed_pages', $output );
	}

	public function test_dropped_failed_pages_tally_in_finalizer_fails(): void {
		$src = $this->live_finalizer();
		// Drop the `'failed_pages' => $error_count` line in complete_export call.
		$mutated = preg_replace(
			"/'failed_pages'\s*=>\s*\\\$error_count\s*,/",
			"// failed_pages tally dropped,",
			$src,
			1,
			$count
		);
		$this::assertSame( 1, $count );
		list( $code, $output ) = $this->run_with_mutations( array(
			'includes/traits/trait-sscribe-export-finalizer.php' => $mutated,
		) );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'failed_pages', $output );
	}
}
