<?php
/**
 * SScribe batch loop-exit filter unit test
 *
 * Drives the DECISION side of the per-page batch loop in
 * SScribe_Batch_Step_Handler::ajax_process_batch() (the sibling
 * SScribe_Batch_Step_Handler_Trait_Test covers only the paused RESPONSE
 * shape of build_batch_response()). Pins the three loop-exit filters and
 * their branch semantics in includes/traits/trait-sscribe-batch-step-handler.php:
 *
 *   - :353 sscribe_timeout_buffer_seconds -> is_time_available() deadline
 *     check; when it fails the loop must break early, set paused_reason
 *     'timeout', and preserve the processed count.
 *   - :368 sscribe_soft_deadline_ratio -> warn-only soft deadline: it must
 *     stamp `_soft_deadline_warned` on the session and NOT stop the loop.
 *   - :385 sscribe_memory_threshold_mb -> is_memory_available() gate; when
 *     it fails the loop must break early with paused_reason 'memory'.
 *   - :474 sscribe_min_memory_per_page_mb pre-export gate deliberately uses
 *     continue (skip the page), NOT break — the loop keeps walking the batch.
 *     This test pins that distinction so the two memory gates cannot be
 *     confused for each other.
 *
 * The entrypoint is driven through a stub host class (the established
 * pattern for trait unit tests in this suite) that supplies controllable
 * collaborators while the trait's real loop, filter reads, pause flags, and
 * session bookkeeping run unchanged. is_time_available() /
 * is_memory_available() keep the real SScribe_Export_Resource_Monitor
 * comparison formulas against controlled budgets, so a mutation that stops
 * wiring the filter values into those checks fails on the outcome.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

if ( ! trait_exists( '\\SScribe_Batch_Step_Handler', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-batch-step-handler.php';
}

require_once __DIR__ . '/wp-suspend-cache-invalidation-stub.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class SScribe_Batch_Loop_Exit_Filters_Test extends TestCase {

	private Batch_Loop_Host $host;
	private string $session_id = 'a1b2c3d4e5f60718';
	private string $temp_dir  = '';

	/** @var array<int, callable> */
	private array $registered_filters = array();

	private int $original_max_execution_time = 0;

	protected function setUp(): void {
		parent::setUp();
		// ajax_process_batch() calls set_time_limit() and would otherwise
		// leave a 150s process-wide execution limit behind for every later
		// test in this PHPUnit process.
		$this->original_max_execution_time = (int) ini_get( 'max_execution_time' );

		$this->host = new Batch_Loop_Host();

		$zip        = new \SScribe_Zip_Handler();
		$this->temp_dir = $zip->create_temp_dir();

		$this->host->session->store = array(
			'processed'   => 0,
			'total'       => 3,
			'temp_dir'    => $this->temp_dir,
			'errors'      => array(),
			'start_time'  => microtime( true ),
			'formats'     => array( 'docx' ),
			'post_type'   => 'page',
			'language'    => 'EN',
			'user_id'     => 1,
		);
		$this->host->session->page_ids = array( 101, 102, 103 );

		$_POST['session_id'] = $this->session_id;
	}

	protected function tearDown(): void {
		foreach ( $this->registered_filters as $filter ) {
			remove_filter( 'sscribe_timeout_buffer_seconds', $filter );
			remove_filter( 'sscribe_memory_threshold_mb', $filter );
			remove_filter( 'sscribe_min_memory_per_page_mb', $filter );
			remove_filter( 'sscribe_soft_deadline_ratio', $filter );
		}
		$this->registered_filters = array();

		set_time_limit( $this->original_max_execution_time );

		$_POST = array();
		if ( '' !== $this->temp_dir && is_dir( $this->temp_dir ) ) {
			$zip = new \SScribe_Zip_Handler();
			$zip->delete_directory( $this->temp_dir );
		}
		$this->temp_dir = '';
		parent::tearDown();
	}

	private function inject_filter( string $hook, int|float $value ): void {
		$callback = static fn( $current ) => $value;
		add_filter( $hook, $callback );
		$this->registered_filters[] = $callback;
	}

	/**
	 * Run the entrypoint and decode the JSON the wp_send_json_* test stubs
	 * echo before throwing their sentinel RuntimeException.
	 */
	private function run_process_batch(): array {
		ob_start();
		try {
			$this->host->ajax_process_batch();
			$this->fail( 'Expected the AJAX success sentinel from wp_send_json_success()' );
		} catch ( \RuntimeException $e ) {
			$output = ob_get_clean();
		}

		$payload = json_decode( (string) $output, true );
		$this->assertIsArray( $payload, 'ajax_process_batch() must emit a JSON payload: ' . (string) $output );
		$this->assertTrue( $payload['success'] ?? false );
		$this->assertIsArray( $payload['data'] ?? null );

		return $payload['data'];
	}

	// ==================================================================
	// sscribe_timeout_buffer_seconds — deadline break.
	// ==================================================================

	public function test_timeout_buffer_filter_stops_batch_early_with_timeout_pause_and_preserved_count(): void {
		// The injected buffer equals the tiny simulated time budget, so the
		// safety margin is exhausted immediately: after page 101 the
		// deadline check must fail and the loop must break.
		$this->host->time_budget = 30.0;
		$this->inject_filter( 'sscribe_timeout_buffer_seconds', 30 );

		$data = $this->run_process_batch();

		$this->assertSame( 'timeout', $data['paused_reason'] );
		$this->assertTrue( $data['timeout_paused'] );
		$this->assertFalse( $data['memory_paused'] );
		$this->assertSame( 1, $data['processed'], 'The processed count must survive the early stop (one page completed).' );
		$this->assertSame( 3, $data['total'] );

		$this->assertSame(
			array( 30 ),
			$this->host->captured_time_buffers,
			'The loop must feed sscribe_timeout_buffer_seconds into the is_time_available() decision.'
		);
		$this->assertSame(
			array( 101 ),
			$this->host->collector->page_data_calls,
			'The loop must stop early: pages 102/103 must never be collected.'
		);
		$this->assertSame( array( 101 ), $this->host->dispatched_pages );

		$pause_updates = array_values(
			array_filter(
				$this->host->session->updates,
				static fn( array $update ): bool => array_key_exists( 'last_pause_reason', $update )
			)
		);
		$this->assertNotEmpty( $pause_updates );
		$this->assertSame( 'timeout', $pause_updates[ count( $pause_updates ) - 1 ]['last_pause_reason'] );
		$this->assertSame( 1, $pause_updates[ count( $pause_updates ) - 1 ]['processed'] );
	}

	// ==================================================================
	// sscribe_memory_threshold_mb — memory break.
	// ==================================================================

	public function test_memory_threshold_filter_pauses_batch_with_memory_reason(): void {
		// 1 TiB threshold cannot be satisfied: the gate must fail after the
		// first page and pause with reason 'memory'.
		$this->host->memory_limit_bytes = 268435456;
		$this->inject_filter( 'sscribe_memory_threshold_mb', 1048576 );

		$data = $this->run_process_batch();

		$this->assertSame( 'memory', $data['paused_reason'] );
		$this->assertTrue( $data['memory_paused'] );
		$this->assertFalse( $data['timeout_paused'] );
		$this->assertSame( 1, $data['processed'], 'The processed count must survive the memory pause.' );
		$this->assertSame( 3, $data['total'] );

		$this->assertSame(
			array( 64, 1048576 ),
			$this->host->captured_memory_buffers,
			'Page 1 passes the 64MB pre-export gate; the second iteration must evaluate the filtered 1048576MB loop threshold.'
		);
		$this->assertSame( array( 101 ), $this->host->collector->page_data_calls );
		$this->assertSame( array( 101 ), $this->host->dispatched_pages );
	}

	// ==================================================================
	// sscribe_soft_deadline_ratio — warn-only, must not stop the loop.
	// ==================================================================

	public function test_soft_deadline_ratio_filter_warns_without_stopping_the_loop(): void {
		// Forced remaining time is 80s against a 150s max_execution_time
		// (set via set_time_limit() by the entrypoint). The injected ratio
		// 0.8 makes 80 < 120 trip, while the default 0.5 (80 < 75) would
		// not — so the outcome pins the filter consultation.
		$this->host->forced_remaining_time = 80.0;
		$this->inject_filter( 'sscribe_soft_deadline_ratio', 0.8 );

		$data = $this->run_process_batch();

		$this->assertSame(
			'finalizing',
			$data['status'],
			'The soft deadline must warn without pausing: the whole batch runs to completion.'
		);
		$this->assertSame( 3, $data['processed'], 'The soft deadline must not stop the batch loop.' );

		$pause_updates = array_values(
			array_filter(
				$this->host->session->updates,
				static fn( array $update ): bool => array_key_exists( 'last_pause_reason', $update )
			)
		);
		$this->assertNotEmpty( $pause_updates );
		$this->assertSame(
			'',
			$pause_updates[ count( $pause_updates ) - 1 ]['last_pause_reason'],
			'A crossed soft deadline must not set a pause reason.'
		);

		$warned = array_filter(
			$this->host->session->updates,
			static fn( array $update ): bool => array_key_exists( '_soft_deadline_warned', $update )
		);
		$this->assertNotEmpty(
			$warned,
			'Crossing the soft deadline must stamp _soft_deadline_warned on the export session.'
		);
		$this->assertSame( array( 101, 102, 103 ), $this->host->dispatched_pages );
	}

	// ==================================================================
	// sscribe_min_memory_per_page_mb — pre-export gate uses continue.
	// ==================================================================

	public function test_pre_export_memory_gate_skips_pages_without_stopping_the_loop(): void {
		// The pre-export gate is a per-page skip (continue), never a break:
		// with an impossible 1048576MB requirement every page is skipped,
		// yet the loop must walk the whole batch and reach finalization.
		$this->host->memory_limit_bytes = 268435456;
		$this->inject_filter( 'sscribe_min_memory_per_page_mb', 1048576 );

		$data = $this->run_process_batch();

		$this->assertSame(
			array( 101, 102, 103 ),
			$this->host->collector->page_data_calls,
			'Every page must be visited: the pre-export memory gate skips (continue), it must not break the loop.'
		);
		$this->assertSame( array(), $this->host->dispatched_pages );
		$this->assertSame( 3, $data['processed'], 'Skipped pages still advance the processed count.' );
		$this->assertSame( 'finalizing', $data['status'], 'A fully-skipped batch must run to completion, not pause.' );

		foreach ( $this->host->session->updates as $update ) {
			if ( array_key_exists( 'last_pause_reason', $update ) ) {
				$this->assertSame(
					'',
					$update['last_pause_reason'],
					'The continue-style pre-export gate must never set a memory/timeout pause reason.'
				);
			}
		}
	}
}

/**
 * Session collaborator stub: serves a fixture session and records every
 * update so tests can assert the pause bookkeeping written back by the
 * trait's finally block.
 */
class Batch_Loop_Session_Stub {

	/** @var array<string, mixed> */
	public array $store = array();

	/** @var array<int, int> */
	public array $page_ids = array();

	/** @var array<int, array<string, mixed>> */
	public array $updates = array();

	public function get( string $session_id ): ?array {
		unset( $session_id );
		return $this->store;
	}

	public function get_page_ids( string $session_id ): array {
		unset( $session_id );
		return $this->page_ids;
	}

	public function validate( string $session_id ): bool {
		unset( $session_id );
		return true;
	}

	public function update( string $session_id, array $data ): bool {
		unset( $session_id );
		$this->updates[] = $data;
		$this->store     = array_merge( $this->store, $data );
		return true;
	}

	public function delete( string $session_id ): bool {
		unset( $session_id );
		return true;
	}
}

/**
 * Logger collaborator stub.
 */
class Batch_Loop_Logger_Stub {

	/** @var array<int, array{level: string, message: string, context: array}> */
	public array $entries = array();

	public function set_session_id( string $session_id ): void {
		unset( $session_id );
	}

	public function debug( string $message, array $context = array() ): void {
		$this->entries[] = array( 'level' => 'debug', 'message' => $message, 'context' => $context );
	}

	public function error( string $message, array $context = array() ): void {
		$this->entries[] = array( 'level' => 'error', 'message' => $message, 'context' => $context );
	}

	public function warning( string $message, array $context = array() ): void {
		$this->entries[] = array( 'level' => 'warning', 'message' => $message, 'context' => $context );
	}
}

/**
 * Page collector collaborator stub. get_page_data() records every page the
 * loop visits, which is how the tests observe early stop vs continue.
 */
class Batch_Loop_Collector_Stub {

	/** @var array<int, int> */
	public array $page_data_calls = array();

	public function get_featured_images_batch( array $batch ): void {
		unset( $batch );
	}

	public function get_child_pages_batch( array $batch, string $post_type ): void {
		unset( $batch, $post_type );
	}

	public function prime_seo_meta_cache( array $batch ): void {
		unset( $batch );
	}

	public function get_page_data( int $page_id, string $language = '' ): array {
		unset( $language );
		$this->page_data_calls[] = $page_id;
		return array(
			'title'  => 'Page ' . $page_id,
			'slug'   => 'page-' . $page_id,
			'language' => 'EN',
		);
	}

	public function clear_page_caches(): void {
	}
}

/**
 * Export log collaborator stub.
 */
class Batch_Loop_Export_Log_Stub {

	public function get_log(): array {
		return array( 'pages' => array() );
	}

	public function update_page_status( int $page_id, string $status ): void {
		unset( $page_id, $status );
	}

	public function log_page_start( int $page_id, string $title, string $slug = '' ): void {
		unset( $page_id, $title, $slug );
	}

	public function log_page_failure( int $page_id, string $error, array $formats = array() ): void {
		unset( $page_id, $error, $formats );
	}

	public function log_page_success( int $page_id, array $formats ): void {
		unset( $page_id, $formats );
	}

	public function log_page_partial( int $page_id, array $success_formats, array $format_errors, string $error ): void {
		unset( $page_id, $success_formats, $format_errors, $error );
	}

	public function flush(): void {
	}
}

/**
 * Diagnostics collaborator stub.
 */
class Batch_Loop_Diagnostics_Stub {

	public function self_heal(): void {
	}

	public function diagnose_page_error( int $page_id, string $format, string $error, array $context = array() ): array {
		unset( $page_id, $format, $error, $context );
		return array( 'category' => 'unknown' );
	}
}

/**
 * Lock manager collaborator stub.
 */
class Batch_Loop_Lock_Stub {

	public function acquire_lock( string $name, int $ttl = 0, int $stale = 0 ): ?string {
		unset( $name, $ttl, $stale );
		return 'loop-lock-token';
	}

	public function renew_lock( string $name, string $token, int $ttl = 0 ): bool {
		unset( $name, $token, $ttl );
		return true;
	}

	public function release_lock( string $name, ?string $token ): bool {
		unset( $name, $token );
		return true;
	}
}

/**
 * Host class for the SScribe_Batch_Step_Handler trait. Provides the
 * supporting private API the trait requires while keeping the loop-exit
 * resource decisions on the real comparison formulas with controllable
 * budgets.
 */
class Batch_Loop_Host {

	use \SScribe_Batch_Step_Handler;

	private const MAX_STORED_ERRORS = 50;
	private const DEFAULT_FORMATS   = array( 'docx' );

	public int $batch_size = 10;

	/** @var mixed */
	public $current_lock_token = null;

	public Batch_Loop_Session_Stub $session;
	public Batch_Loop_Logger_Stub $logger;
	public Batch_Loop_Collector_Stub $collector;
	public Batch_Loop_Export_Log_Stub $export_log;
	public Batch_Loop_Diagnostics_Stub $diagnostics;
	public Batch_Loop_Lock_Stub $lock_manager;

	/** @var array<int, int> */
	public array $captured_time_buffers = array();

	/** @var array<int, int> */
	public array $captured_memory_buffers = array();

	/** @var array<int, int> */
	public array $dispatched_pages = array();

	/** @var array<int, string> */
	public array $released_tokens = array();

	public float $time_budget           = 1000.0;
	public float $forced_remaining_time = 1000.0;
	public ?int  $memory_limit_bytes    = null;

	public function __construct() {
		$this->session     = new Batch_Loop_Session_Stub();
		$this->logger      = new Batch_Loop_Logger_Stub();
		$this->collector   = new Batch_Loop_Collector_Stub();
		$this->export_log  = new Batch_Loop_Export_Log_Stub();
		$this->diagnostics = new Batch_Loop_Diagnostics_Stub();
		$this->lock_manager = new Batch_Loop_Lock_Stub();
	}

	private function check_rate_limit_decision( string $bucket = 'export' ): \SScribe_Rate_Limit_Decision {
		return \SScribe_Rate_Limit_Decision::allowed( $bucket, 100, 99, time() + 60 );
	}

	private function get_diagnostics(): Batch_Loop_Diagnostics_Stub {
		return $this->diagnostics;
	}

	private function get_lock_manager(): Batch_Loop_Lock_Stub {
		return $this->lock_manager;
	}

	private function validate_session_ownership( array $session, string $session_id ): bool {
		unset( $session, $session_id );
		return true;
	}

	private function release_lock( string $session_id, ?string $lock_token = null ): bool {
		unset( $session_id );
		$this->released_tokens[] = (string) $lock_token;
		return true;
	}

	private function cleanup_cancelled_export( array $session ): void {
		unset( $session );
	}

	private function optimize_batch_size( array $formats = array(), string $hint = '' ): void {
		unset( $formats, $hint );
		$this->batch_size = 10;
	}

	private function dispatch_formats( array $page_data, string $temp_dir, int $page_index, int $total, array $formats, string $session_id, array &$session, int $page_id ): array {
		unset( $page_data, $temp_dir, $page_index, $total, $formats, $session_id, $session );
		$this->dispatched_pages[] = $page_id;
		return array(
			'export_success'     => true,
			'successful_formats' => array( 'docx' ),
			'export_errors'      => array(),
		);
	}

	private function finalize_export( string $session_id, array $session, $lock_token, \SScribe_Export_Context_Interface $context ): \SScribe_Export_Outcome {
		unset( $session_id, $session, $lock_token, $context );
		return \SScribe_Export_Outcome::ok();
	}

	private function build_error_diagnostics_payload( array $structured_errors, array $string_errors = array() ): array {
		return array(
			'count'         => count( $structured_errors ),
			'string_count'  => count( $string_errors ),
		);
	}

	/**
	 * Real SScribe_Export_Resource_Monitor formula against the controllable
	 * time budget, so the injected sscribe_timeout_buffer_seconds value is
	 * compared exactly like in production.
	 */
	private function is_time_available( float $batch_start_time, int $buffer_seconds = 10 ): bool {
		$this->captured_time_buffers[] = $buffer_seconds;
		$remaining                     = $this->time_budget - ( microtime( true ) - $batch_start_time );
		return $remaining > $buffer_seconds;
	}

	/**
	 * Real SScribe_Export_Resource_Monitor formula against a controllable
	 * memory ceiling (defaults to the process memory_limit).
	 */
	private function is_memory_available( int $buffer_mb = 10 ): bool {
		$this->captured_memory_buffers[] = $buffer_mb;

		$limit = null !== $this->memory_limit_bytes
			? $this->memory_limit_bytes
			: wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return true;
		}
		$available = $limit - memory_get_usage( true );
		return $available > ( max( 0, $buffer_mb ) * 1024 * 1024 );
	}

	private function get_remaining_time( float $batch_start_time ): float {
		unset( $batch_start_time );
		return $this->forced_remaining_time;
	}

	private function get_memory_usage_percent(): float {
		return 0.0;
	}
}
