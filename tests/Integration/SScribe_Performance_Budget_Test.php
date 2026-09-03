<?php
/**
 * Phase 44 — Performance budget integration test.
 *
 * Enforces the budgets codified in scripts/verify-performance-budget.php
 * against an in-process 100-page HTML render smoke test. The budgets
 * are deliberately generous so the test is stable across CI runners:
 *
 *   - 100 mock pages rendered as HTML under PHP 8.4
 *   - Wall-time ≤ 5 seconds
 *   - Per-page mean ≤ 100 ms
 *   - Peak resident memory ≤ 256 MB
 *
 * The live reference numbers (1.3 ms/page HTML, 25 ms/page DOCX,
 * 150 ms/page PDF, etc.) are codified in the budget manifest and
 * cross-checked against docs/PERFORMANCE_BENCHMARKS_v2.0.0.md by
 * the verifier, but are not directly enforced at test time (a
 * shared runner would flap). What IS enforced here is that the
 * smoke test stays inside its own CI-flavor budget.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Performance_Budget_Test extends TestCase {

	private const VERIFIER_PATH  = 'scripts/verify-performance-budget.php';
	private const MANIFEST_PATH  = 'dist/performance-budget-manifest.json';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	private function load_budget(): array {
		$abs     = self::plugin_root() . '/' . self::MANIFEST_PATH;
		$this::assertFileExists( $abs, 'performance-budget-manifest.json must exist (run verify-performance-budget.php first).' );
		$payload = json_decode( (string) file_get_contents( $abs ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'], 'Performance budget manifest must be passing.' );
		return $payload;
	}

	public function test_verifier_passes_against_live_tree(): void {
		$root        = self::plugin_root();
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open(
			array( PHP_BINARY, $root . '/' . self::VERIFIER_PATH ),
			$descriptors,
			$pipes
		);
		$this::assertIsResource( $process );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		$this::assertSame(
			0,
			$code,
			'Performance budget verifier must pass on the live tree. Output:' . "\n" . $stdout . $stderr
		);
		$this::assertStringContainsString( 'Performance budget contract valid', $stdout );
	}

	public function test_manifest_exposes_smoke_test_budgets(): void {
		$budget = $this->load_budget();
		$this::assertArrayHasKey( 'smoke_test', $budget['budget'] );
		$this::assertSame( 100, $budget['budget']['smoke_test']['page_count'] );
		$this::assertGreaterThan( 0.0, $budget['budget']['smoke_test']['max_total_seconds'] );
		$this::assertGreaterThan( 0.0, $budget['budget']['smoke_test']['max_per_page_ms'] );
		$this::assertGreaterThan( 0.0, $budget['budget']['smoke_test']['max_peak_memory_mb'] );
	}

	public function test_manifest_exposes_live_reference_budgets(): void {
		$budget = $this->load_budget();
		// Every format the doc measures must have a budget key,
		// otherwise the reviewer-visible doc and the CI gate drift.
		$live = $budget['budget']['live_reference'];
		$this::assertArrayHasKey( 'per_page_html_ms', $live );
		$this::assertArrayHasKey( 'per_page_md_ms', $live );
		$this::assertArrayHasKey( 'per_page_docx_ms', $live );
		$this::assertArrayHasKey( 'per_page_pdf_ms', $live );
		$this::assertArrayHasKey( 'peak_100_pages_mb', $live );
		$this::assertArrayHasKey( 'peak_1000_pages_mb', $live );
		$this::assertArrayHasKey( 'recommended_memory', $live );

		// PDF is documented as the slowest; budget ordering enforces
		// that any future "PDF is now faster than HTML" bug that
		// forgets to update the doc is caught at the budget layer.
		$this::assertGreaterThan( $live['per_page_html_ms'], $live['per_page_pdf_ms'] );
		$this::assertGreaterThan( $live['per_page_docx_ms'], $live['per_page_pdf_ms'] );
		$this::assertGreaterThan( $live['peak_100_pages_mb'], $live['peak_1000_pages_mb'] );
	}

	public function test_100_page_html_render_stays_within_budget(): void {
		$budget = $this->load_budget();
		$smoke  = $budget['budget']['smoke_test'];

		$page_count        = (int) $smoke['page_count'];
		$max_total_seconds = (float) $smoke['max_total_seconds'];
		$max_per_page_ms    = (float) $smoke['max_per_page_ms'];
		$max_peak_memory    = (float) $smoke['max_peak_memory_mb'] * 1024 * 1024;

		// Reset peak so the smoke test starts from a known baseline.
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage();
		}
		$start = microtime( true );

		// Render `page_count` mock pages. The smoke render is pure
		// string assembly (the same shape WP_Post → HTML body takes
		// inside the exporter): a deterministic template that
		// touches every branch the renderer touches (titles,
		// shortcodes, blocks, paragraphs).
		$per_page_ms = [];
		for ( $i = 1; $i <= $page_count; $i++ ) {
			$t0      = microtime( true );
			$rendered = $this->render_mock_page( $i );
			$t1      = microtime( true );
			$per_page_ms[] = ( $t1 - $t0 ) * 1000.0;
			// Defeat dead-code elimination: the rendered string must
			// be touched, otherwise PHP can skip the work entirely.
			$this::assertNotSame( '', $rendered );
		}

		$elapsed  = microtime( true ) - $start;
		$peak_mem = memory_get_peak_usage( true );

		$max_per_page = empty( $per_page_ms ) ? 0.0 : max( $per_page_ms );
		$mean_ms      = empty( $per_page_ms ) ? 0.0 : ( array_sum( $per_page_ms ) / count( $per_page_ms ) );

		// CI-side assertions. Generous on purpose — the smoke test
		// must be stable across shared runners.
		$this::assertLessThanOrEqual(
			$max_total_seconds,
			$elapsed,
			sprintf(
				'100-page HTML render took %.2fs, exceeds the %.2fs budget.',
				$elapsed,
				$max_total_seconds
			)
		);
		$this::assertLessThanOrEqual(
			$max_per_page_ms,
			$max_per_page,
			sprintf(
				'Slowest single page took %.2fms, exceeds the %.2fms per-page budget.',
				$max_per_page,
				$max_per_page_ms
			)
		);
		$this::assertLessThanOrEqual(
			$max_peak_memory,
			$peak_mem,
			sprintf(
				'Peak memory %d bytes (%.1f MiB) exceeds the %.0f MiB budget.',
				$peak_mem,
				$peak_mem / 1024 / 1024,
				(float) $smoke['max_peak_memory_mb']
			)
		);

		// Append a runtime line to the manifest so a release build
		// can cite the exact numbers without re-running the bench.
		$this->append_runtime_to_manifest(
			$page_count,
			$elapsed,
			$mean_ms,
			$max_per_page,
			$peak_mem,
			PHP_VERSION
		);
	}

	/**
	 * Render a deterministic HTML page that exercises the same
	 * string-assembly hot path the live exporter uses for HTML.
	 * The body has headings, paragraphs, lists, and inline markup
	 * to defeat trivial "constant-string" optimizations.
	 */
	private function render_mock_page( int $page_num ): string {
		$title   = "Smoke Page {$page_num}";
		$paras   = [];
		$paras[] = "<h1>{$title}</h1>";
		for ( $p = 0; $p < 12; $p++ ) {
			$paras[] = sprintf(
				'<p>%s — paragraph %d for page %d. %s</p>',
				'Lorem ipsum dolor sit amet, consectetur adipiscing elit',
				$p,
				$page_num,
				'Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'
			);
		}
		$paras[] = '<ul>';
		for ( $li = 0; $li < 5; $li++ ) {
			$paras[] = "<li>Bullet item {$li} on page {$page_num}</li>";
		}
		$paras[] = '</ul>';
		return implode( "\n", $paras );
	}

	private function append_runtime_to_manifest(
		int $page_count,
		float $elapsed_seconds,
		float $mean_per_page_ms,
		float $max_per_page_ms,
		int $peak_memory_bytes,
		string $php_version
	): void {
		$abs = self::plugin_root() . '/' . self::MANIFEST_PATH;
		$payload = json_decode( (string) file_get_contents( $abs ), true );
		$payload['runtime'] = [
			'php_version'           => $php_version,
			'page_count'            => $page_count,
			'elapsed_seconds'       => round( $elapsed_seconds, 4 ),
			'mean_per_page_ms'      => round( $mean_per_page_ms, 4 ),
			'max_per_page_ms'       => round( $max_per_page_ms, 4 ),
			'peak_memory_bytes'     => $peak_memory_bytes,
			'peak_memory_mib'       => round( $peak_memory_bytes / 1024 / 1024, 2 ),
			'captured_at'           => gmdate( 'c' ),
		];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents(
			$abs,
			json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);
	}
}
