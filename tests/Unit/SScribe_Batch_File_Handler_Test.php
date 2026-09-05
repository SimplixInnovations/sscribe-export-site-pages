<?php
/**
 * SScribe_Batch_File_Handler unit test
 *
 * Exercises the two private helpers exposed by the file handler:
 *   - get_required_capability() — delegates to SScribe_Capabilities
 *   - check_rate_limit_decision() — wraps the rate-limiter decision
 *
 * The public AJAX entrypoints (ajax_download, ajax_delete_export) require
 * a full WP HTTP request lifecycle and the real audit trail, so they are
 * covered by the Real WP testbench instead.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Batch_File_Handler' ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-batch-file-handler.php';
}

final class SScribe_Batch_File_Handler_Test extends TestCase {

	private \ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();
		// Default handler uses real collaborators; tests below swap by
		// constructing with custom stubs through the public constructor.
		$this->ref = new \ReflectionClass( \SScribe_Batch_File_Handler::class );
	}

	private function new_handler( Batch_File_Handler_Rate_Limiter_Stub $rate_limiter ): \SScribe_Batch_File_Handler {
		return new \SScribe_Batch_File_Handler(
			$rate_limiter,
			new Batch_File_Handler_Zip_Stub(),
			new Batch_File_Handler_Logger_Stub(),
			new Batch_File_Handler_Auditor_Stub()
		);
	}

	private function call_private( object $obj, string $method, ...$args ) {
		$m = $this->ref->getMethod( $method );
		return $m->invoke( $obj, ...$args );
	}

	// ==================================================================
	// get_required_capability() — pure delegation
	// ==================================================================

	public function test_get_required_capability_delegates_to_capabilities_class(): void {
		$expected = \SScribe_Capabilities::get_required();
		$this::assertNotEmpty( $expected );

		$stub   = new Batch_File_Handler_Rate_Limiter_Stub();
		$actual = $this->call_private( $this->new_handler( $stub ), 'get_required_capability' );
		$this::assertSame( $expected, $actual );
	}

	// ==================================================================
	// check_rate_limit_decision() — wrapper that returns the
	// rate-limiter's structured decision.
	// ==================================================================

	public function test_check_rate_limit_decision_returns_allowed_decision(): void {
		$stub = new Batch_File_Handler_Rate_Limiter_Stub();
		$stub->next_decision = \SScribe_Rate_Limit_Decision::allowed(
			'export_finalize',
			10,
			5,
			time() + 60
		);

		$decision = $this->call_private(
			$this->new_handler( $stub ),
			'check_rate_limit_decision'
		);

		$this::assertInstanceOf( \SScribe_Rate_Limit_Decision::class, $decision );
		$this::assertTrue( $decision->allowed );
		$this::assertSame( 5, $decision->remaining );
		$this::assertSame( 10, $decision->limit );
	}

	public function test_check_rate_limit_decision_returns_blocked_decision(): void {
		$stub = new Batch_File_Handler_Rate_Limiter_Stub();
		$stub->next_decision = \SScribe_Rate_Limit_Decision::quota_exceeded(
			'export_finalize',
			10,
			30000,
			time() + 30
		);

		$decision = $this->call_private(
			$this->new_handler( $stub ),
			'check_rate_limit_decision'
		);

		$this::assertFalse( $decision->allowed );
		$this::assertSame( 429, $decision->http_status() );
	}

	public function test_check_rate_limit_decision_uses_default_bucket_when_omitted(): void {
		$stub = new Batch_File_Handler_Rate_Limiter_Stub();
		$stub->captured_capability = null;
		$stub->captured_bucket     = null;

		$this->call_private(
			$this->new_handler( $stub ),
			'check_rate_limit_decision'
		);

		$this::assertNotNull( $stub->captured_bucket );
		$this::assertSame( 'export_finalize', $stub->captured_bucket );
	}

	// ==================================================================
	// Constructor with null collaborators → defaults constructed.
	// ==================================================================

	public function test_constructor_with_null_collaborators_uses_defaults(): void {
		// The default constructor instantiates SScribe_Export_Rate_Limiter,
		// SScribe_Zip_Handler, SScribe_Logger, and SScribe_Export_Auditor.
		// We verify it does not throw and the readonly properties are
		// populated by reading them via reflection.
		$handler = new \SScribe_Batch_File_Handler( null, null, null, null );

		$rl = $this->ref->getProperty( 'rate_limiter' )->getValue( $handler );
		$this::assertInstanceOf( \SScribe_Export_Rate_Limiter::class, $rl );

		$zh = $this->ref->getProperty( 'zip_handler' )->getValue( $handler );
		$this::assertInstanceOf( \SScribe_Zip_Handler::class, $zh );

		$lg = $this->ref->getProperty( 'logger' )->getValue( $handler );
		$this::assertInstanceOf( \SScribe_Logger_Interface::class, $lg );

		$au = $this->ref->getProperty( 'auditor' )->getValue( $handler );
		$this::assertInstanceOf( \SScribe_Export_Auditor::class, $au );
	}
}

/**
 * Stubs that satisfy the constructor's readonly type-hints without
 * pulling in the real collaborators' heavy WP dependencies.
 */
class Batch_File_Handler_Rate_Limiter_Stub extends \SScribe_Export_Rate_Limiter {

	public ?\SScribe_Rate_Limit_Decision $next_decision = null;
	public ?string $captured_capability = null;
	public ?string $captured_bucket     = null;

	public function __construct() {
		// Skip parent — we only use the decision-override surface.
		$this->next_decision = \SScribe_Rate_Limit_Decision::allowed(
			'export_finalize',
			10,
			5,
			time() + 60
		);
	}

	public function check_rate_limit_decision( string $export_capability = 'sscribe_export', string $bucket = 'export_finalize' ): \SScribe_Rate_Limit_Decision {
		$this->captured_capability = $export_capability;
		$this->captured_bucket     = $bucket;
		return $this->next_decision;
	}
}

class Batch_File_Handler_Zip_Stub extends \SScribe_Zip_Handler {
	public function __construct() {
		// Skip parent.
	}
}

class Batch_File_Handler_Logger_Stub implements \SScribe_Logger_Interface {
	public function set_session_id( string $session_id ): void {}
	public function emergency( string $msg, array $ctx = array() ): void {}
	public function alert( string $msg, array $ctx = array() ): void {}
	public function critical( string $msg, array $ctx = array() ): void {}
	public function error( string $msg, array $ctx = array() ): void {}
	public function warning( string $msg, array $ctx = array() ): void {}
	public function notice( string $msg, array $ctx = array() ): void {}
	public function info( string $msg, array $ctx = array() ): void {}
	public function debug( string $msg, array $ctx = array() ): void {}
	public function log( string $level, string $msg, array $ctx = array() ): void {}
	public function is_enabled(): bool { return true; }
	public function get_logs( int $limit = -1 ): array { return array(); }
	public function clear_logs(): void {}
	public function get_log_file(): string { return ''; }
}

class Batch_File_Handler_Auditor_Stub extends \SScribe_Export_Auditor {
	public function __construct() {
		// Skip parent.
	}
}
