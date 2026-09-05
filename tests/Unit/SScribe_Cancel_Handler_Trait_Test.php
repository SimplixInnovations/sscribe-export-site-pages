<?php
/**
 * SScribe Cancel Handler Trait unit test
 *
 * Covers the three public/private methods on the cancel-handler trait:
 *   - ajax_cancel_export() — delegates to $this->session->ajax_cancel_export()
 *   - cleanup_cancelled_export() — deletes temp_dir + export_log when set
 *   - ajax_clear_session() — delegates to $this->session->ajax_clear_session()
 *
 * The trait is exercised via a stub class (matching the project pattern for
 * testing private helpers) that provides $session, $zip_handler, $logger,
 * and $export_log collaborators as PHP 8 readonly properties. PHPUnit 11
 * silently inlines the readonly promotion, so a plain class without readonly
 * keeps the stub simple.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! trait_exists( '\\SScribe_Cancel_Handler', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-cancel-handler.php';
}

final class SScribe_Cancel_Handler_Trait_Test extends TestCase {

	private \ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();
		$this->ref = new \ReflectionClass( SScribe_Cancel_Handler_Stub::class );
	}

	private function call_private( object $stub, string $method, ...$args ) {
		$m = $this->ref->getMethod( $method );
		return $m->invoke( $stub, ...$args );
	}

	private function new_stub(): SScribe_Cancel_Handler_Stub {
		return new SScribe_Cancel_Handler_Stub();
	}

	// ==================================================================
	// ajax_cancel_export() — pure delegation
	// ==================================================================

	public function test_ajax_cancel_export_delegates_to_session(): void {
		$stub = $this->new_stub();
		$stub->cancel_export_called = false;
		$stub->ajax_cancel_export();
		$this::assertTrue( $stub->cancel_export_called );
	}

	// ==================================================================
	// cleanup_cancelled_export() — temp_dir + export_log teardown
	// ==================================================================

	public function test_cleanup_cancelled_export_deletes_temp_dir_when_set(): void {
		$stub = $this->new_stub();

		// Point zip_handler at a real temp dir we can create + clean up.
		$tmp_dir = sys_get_temp_dir() . '/sscribe-cancel-test-' . uniqid();
		mkdir( $tmp_dir );
		file_put_contents( $tmp_dir . '/junk.bin', 'x' );

		$this->call_private(
			$stub,
			'cleanup_cancelled_export',
			array( 'temp_dir' => $tmp_dir )
		);

		$this::assertFalse( is_dir( $tmp_dir ) );
		$this::assertTrue( $stub->zip_handler->deleted );
		$this::assertTrue( $stub->logger->debug_called );
	}

	public function test_cleanup_cancelled_export_skips_temp_dir_when_empty(): void {
		$stub = $this->new_stub();
		$this->call_private(
			$stub,
			'cleanup_cancelled_export',
			array( 'temp_dir' => '' )
		);
		$this::assertFalse( $stub->zip_handler->deleted );
	}

	public function test_cleanup_cancelled_export_skips_temp_dir_when_not_a_directory(): void {
		$stub = $this->new_stub();
		$this->call_private(
			$stub,
			'cleanup_cancelled_export',
			array( 'temp_dir' => '/some/regular/file/that/is/not/a/dir' )
		);
		$this::assertFalse( $stub->zip_handler->deleted );
	}

	public function test_cleanup_cancelled_export_deletes_export_log_when_set(): void {
		$stub = $this->new_stub();
		$stub->export_log->delete_called = false;

		$this->call_private(
			$stub,
			'cleanup_cancelled_export',
			array( 'temp_dir' => '' )
		);

		$this::assertTrue( $stub->export_log->delete_called );
	}

	public function test_cleanup_cancelled_export_skips_export_log_when_null(): void {
		$stub = $this->new_stub();
		$stub->export_log = null;

		// No exception should be thrown when export_log is null.
		$this->call_private(
			$stub,
			'cleanup_cancelled_export',
			array( 'temp_dir' => '' )
		);

		$this::assertNull( $stub->export_log );
	}

	// ==================================================================
	// ajax_clear_session() — pure delegation
	// ==================================================================

	public function test_ajax_clear_session_delegates_to_session(): void {
		$stub = $this->new_stub();
		$stub->clear_session_called = false;
		$stub->ajax_clear_session();
		$this::assertTrue( $stub->clear_session_called );
	}
}

/**
 * Minimal stub that lets the trait's collaborators behave deterministically.
 * Tracks each delegation as a boolean so tests can assert without inspecting
 * the inner session mock.
 */
class SScribe_Cancel_Handler_Stub {

	use \SScribe_Cancel_Handler;

	public bool $cancel_export_called = false;
	public bool $clear_session_called = false;

	public Cancel_Handler_Session_Stub $session;
	public Cancel_Handler_Zip_Stub $zip_handler;
	public Cancel_Handler_Logger_Stub $logger;
	public ?Cancel_Handler_Export_Log_Stub $export_log;

	public function __construct() {
		$this->session     = new Cancel_Handler_Session_Stub( $this );
		$this->zip_handler = new Cancel_Handler_Zip_Stub();
		$this->logger      = new Cancel_Handler_Logger_Stub();
		$this->export_log  = new Cancel_Handler_Export_Log_Stub();
	}
}

class Cancel_Handler_Session_Stub {

	private SScribe_Cancel_Handler_Stub $owner;

	public function __construct( SScribe_Cancel_Handler_Stub $owner ) {
		$this->owner = $owner;
	}

	public function ajax_cancel_export(): void {
		$this->owner->cancel_export_called = true;
	}

	public function ajax_clear_session(): void {
		$this->owner->clear_session_called = true;
	}
}

class Cancel_Handler_Zip_Stub {

	public bool $deleted = false;

	public function delete_directory( string $dir ): bool {
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		foreach ( glob( $dir . '/*' ) as $f ) {
			if ( is_file( $f ) ) {
				@unlink( $f );
			} elseif ( is_dir( $f ) ) {
				$this->delete_directory( $f );
			}
		}
		@rmdir( $dir );
		$this->deleted = is_dir( $dir ) === false;
		return $this->deleted;
	}
}

class Cancel_Handler_Logger_Stub {

	public bool $debug_called = false;

	public function debug( string $msg, array $ctx = array() ): void {
		$this->debug_called = true;
	}
}

class Cancel_Handler_Export_Log_Stub {

	public bool $delete_called = false;

	public function delete(): bool {
		$this->delete_called = true;
		return true;
	}
}
