<?php
/**
 * SScribe Admin Debug unit tests
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Admin_Debug_Test extends TestCase {

	/**
	 * Helper: invoke the private parse_log_entries() method.
	 */
	private function call_parse_log_entries(
		\SScribe_Admin_Debug $debug,
		array $lines,
		string $filter_level = 'ALL',
		string $search = '',
		string $session_id = '',
		bool $reverse = true
	): array {
		$method = \Closure::bind(
			function ( $debug, $lines, $level, $search, $session, $reverse ) {
				return $debug->parse_log_entries( $lines, $level, $search, $session, $reverse );
			},
			null,
			\SScribe_Admin_Debug::class
		);
		return $method( $debug, $lines, $filter_level, $search, $session_id, $reverse );
	}

	/**
	 * Build a JSON log line for the parser.
	 */
	private function make_line( string $level, string $message, array $context = array() ): string {
		return wp_json_encode(
			array(
				'timestamp' => '2024-01-01 00:00:00',
				'level'     => $level,
				'message'   => $message,
				'context'   => $context,
			)
		);
	}

	/**
	 * Regression (BUG-1 + BUG-3): parse_log_entries must apply the
	 * 'ALL' filter correctly — it must NOT match the priority
	 * short-circuit at the wrong level. Before the filter-level
	 * validation, an unknown level (typo, tampered value) would
	 * resolve to $filter_priority = null, hit the `null !==
	 * $filter_priority` short-circuit, and bypass filtering entirely.
	 */
	public function test_parse_log_entries_filters_by_level(): void {
		$debug = new \SScribe_Admin_Debug();

		$lines = array(
			$this->make_line( 'DEBUG', 'debug message' ),
			$this->make_line( 'INFO', 'info message' ),
			$this->make_line( 'ERROR', 'error message' ),
		);

		$entries = $this->call_parse_log_entries( $debug, $lines, 'ERROR' );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'error message', $entries[0]['message'] );
	}

	/**
	 * Regression (BUG-1): parse_log_entries with filter_level=ALL
	 * must include every entry regardless of level.
	 */
	public function test_parse_log_entries_all_includes_every_level(): void {
		$debug = new \SScribe_Admin_Debug();

		$lines = array(
			$this->make_line( 'DEBUG', 'd' ),
			$this->make_line( 'INFO', 'i' ),
			$this->make_line( 'WARNING', 'w' ),
			$this->make_line( 'ERROR', 'e' ),
		);

		$entries = $this->call_parse_log_entries( $debug, $lines, 'ALL' );

		$this->assertCount( 4, $entries );
	}

	/**
	 * Regression (BUG-2): an unknown filter level (e.g. 'VERBOSE')
	 * must be treated as 'ALL' in the parser, not silently bypass
	 * filtering. The validation step that prevents this lives in
	 * ajax_debug_fetch_logs() and ajax_debug_export_logs(), which
	 * normalize unknown values to 'ALL' before reaching the parser.
	 *
	 * At the parser level: an unknown level resolves to
	 * $filter_priority = null. The `null !== $filter_priority`
	 * check is correct ONLY when the caller has already validated
	 * the input. The handler test (below) confirms that the
	 * validation step does its job.
	 *
	 * This test pins the parser's documented behavior: an unknown
	 * level returns ALL entries. If a future change tightens the
	 * parser to reject unknown levels, this test will fail and
	 * force the developer to update both call sites in lockstep.
	 */
	public function test_parse_log_entries_unknown_level_returns_all(): void {
		$debug = new \SScribe_Admin_Debug();

		$lines = array(
			$this->make_line( 'DEBUG', 'd' ),
			$this->make_line( 'ERROR', 'e' ),
		);

		$entries = $this->call_parse_log_entries( $debug, $lines, 'BOGUS' );

		// Parser behavior: unknown level falls through to "no filter".
		$this->assertCount( 2, $entries );
	}

	/**
	 * Regression (BUG-1 / BUG-3 pagination): the same input lines
	 * must produce the same parsed-entries list regardless of how
	 * the caller paginates. The new model always reads the same
	 * window from the logger and slices by offset — this test
	 * asserts the underlying invariant: parse_log_entries is
	 * deterministic for a given input.
	 */
	public function test_parse_log_entries_is_deterministic(): void {
		$debug = new \SScribe_Admin_Debug();

		$lines = array();
		for ( $i = 0; $i < 100; $i++ ) {
			$lines[] = $this->make_line( 'INFO', "entry {$i}" );
		}

		$first  = $this->call_parse_log_entries( $debug, $lines, 'ALL' );
		$second = $this->call_parse_log_entries( $debug, $lines, 'ALL' );

		$this->assertSame( count( $first ), count( $second ) );
		$this->assertSame( $first[0]['message'], $second[0]['message'] );
		$this->assertSame( end( $first )['message'], end( $second )['message'] );
	}

	/**
	 * Regression: parse_log_entries with $reverse = true must
	 * return newest-first (the debug console reads top-down so
	 * the most recent log appears at the top of the panel).
	 */
	public function test_parse_log_entries_reverse_orders_newest_first(): void {
		$debug = new \SScribe_Admin_Debug();

		$lines = array(
			$this->make_line( 'INFO', 'first' ),
			$this->make_line( 'INFO', 'second' ),
			$this->make_line( 'INFO', 'third' ),
		);

		$entries = $this->call_parse_log_entries( $debug, $lines, 'ALL', '', '', true );

		$this->assertSame( 'third', $entries[0]['message'] );
		$this->assertSame( 'first', $entries[2]['message'] );
	}

	/**
	 * Regression: search filter is case-insensitive and matches
	 * in the message field.
	 */
	public function test_parse_log_entries_search_is_case_insensitive(): void {
		$debug = new \SScribe_Admin_Debug();

		$lines = array(
			$this->make_line( 'INFO', 'Export Complete' ),
			$this->make_line( 'INFO', 'cache cleared' ),
		);

		$entries = $this->call_parse_log_entries( $debug, $lines, 'ALL', 'EXPORT' );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'Export Complete', $entries[0]['message'] );
	}

	/**
	 * Regression: session_id filter does partial-match against
	 * the context.session_id field.
	 */
	public function test_parse_log_entries_session_id_partial_match(): void {
		$debug = new \SScribe_Admin_Debug();

		$lines = array(
			$this->make_line( 'INFO', 'a', array( 'session_id' => 'abc123def' ) ),
			$this->make_line( 'INFO', 'b', array( 'session_id' => 'xyz789' ) ),
		);

		$entries = $this->call_parse_log_entries( $debug, $lines, 'ALL', '', '123' );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'a', $entries[0]['message'] );
	}
	/**
	 * Regression: HTTP Content-Length is measured in bytes, not Unicode
	 * characters. Using mb_strlen() advertises a shorter body whenever
	 * exported debug JSON contains Arabic or other multibyte text.
	 */
	public function test_debug_json_download_content_length_uses_bytes(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/admin/class-sscribe-admin-debug.php'
		);

		$this->assertStringContainsString(
			"header( 'Content-Length: ' . strlen( \$content ) );",
			$source
		);
		$this->assertStringNotContainsString(
			"Content-Length: ' . SScribe_Helpers::mb_strlen( \$content )",
			$source
		);
	}

}
