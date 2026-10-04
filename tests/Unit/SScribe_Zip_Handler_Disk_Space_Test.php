<?php
/**
 * SScribe ZIP Handler disk-full abort contract test
 *
 * Pins the @disk_free_space() pre-check inside SScribe_Zip_Handler::create_zip():
 *
 *   - includes/class-sscribe-zip-handler.php:192  ($total_source_size aggregation)
 *   - includes/class-sscribe-zip-handler.php:194-200 (disk_free_space() probe)
 *   - includes/class-sscribe-zip-handler.php:201-210 (abort branch: log
 *     'Insufficient disk space to create ZIP archive', delete the source
 *     directory, return false)
 *   - includes/class-sscribe-zip-handler.php:213-219 (the export-index lock
 *     must only be attempted AFTER the disk pre-check)
 *   - includes/class-sscribe-zip-handler.php:468-481 (export-index row /
 *     index publication must never happen for an aborted archive)
 *
 * The branch compares total source bytes against 95% of the free space of
 * the export directory. disk_free_space() is a global PHP function called
 * unqualified from the global namespace, so it cannot be intercepted by a
 * namespaced test double or by Reflection; instead the fixture drives the
 * real comparison with an NTFS sparse file whose logical size dwarfs the
 * volume's free space while consuming almost no disk. If the sparse-file
 * seam is unavailable on this platform, the test is skipped rather than
 * silently passing.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Zip_Handler;

final class SScribe_Zip_Handler_Disk_Space_Test extends TestCase {

	/**
	 * NTFS sparse files can carry logical sizes up to 8 TiB on this
	 * platform's volumes; anything larger is rejected by SetEndOfFile.
	 */
	private const SPARSE_CEILING = 8796093022208; // 8 TiB in bytes.

	private ?string $source_dir = null;

	protected function tearDown(): void {
		if ( null !== $this->source_dir && is_dir( $this->source_dir ) ) {
			$this->remove_tree( $this->source_dir );
		}
		$this->source_dir = null;
		parent::tearDown();
	}

	private function remove_tree( string $dir ): void {
		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->remove_tree( $path );
				continue;
			}
			@unlink( $path );
		}
		@rmdir( $dir );
	}

	/**
	 * Create a sparse file with a huge logical size and a tiny disk cost.
	 *
	 * @param string $path    File to create.
	 * @param int    $logical Logical size in bytes.
	 * @return bool True when the sparse fixture was created and verified.
	 */
	private function make_sparse_file( string $path, int $logical ): bool {
		if ( false === @file_put_contents( $path, 'x' ) ) {
			return false;
		}

		// Make the file sparse first, then extend its logical EOF. On a
		// sparse-flagged NTFS file the extension stays unallocated, so
		// filesize() reports $logical while the disk cost stays ~zero.
		exec( 'fsutil sparse setflag ' . escapeshellarg( $path ) . ' 2>&1', $output, $rc );
		if ( 0 !== $rc ) {
			@unlink( $path );
			return false;
		}

		$handle = @fopen( $path, 'r+b' );
		if ( false === $handle ) {
			@unlink( $path );
			return false;
		}
		$extended = @ftruncate( $handle, $logical );
		fclose( $handle );
		if ( ! $extended ) {
			@unlink( $path );
			return false;
		}

		clearstatcache( true, $path );
		return filesize( $path ) === $logical;
	}

	/**
	 * Deny read-data on the fixture so a mutated implementation that skips
	 * the disk pre-check fails fast at archive assembly (the file cannot be
	 * opened for compression) instead of streaming tens of gigabytes.
	 * Stat and unlink keep working, so the legitimate abort path is
	 * unaffected. Best effort only.
	 *
	 * @param string $path File to lock down.
	 */
	private function deny_read_data( string $path ): void {
		exec( 'icacls ' . escapeshellarg( $path ) . ' /deny *S-1-1-0:(RD) 2>&1', $output, $rc );
		unset( $output, $rc );
	}

	/**
	 * Build a SScribe_Zip_Handler whose logger is an in-memory spy, so the
	 * exact abort message can be asserted without reading log files. The
	 * readonly logger property is uninitialized after
	 * newInstanceWithoutConstructor(), which Reflection may initialize.
	 *
	 * @return array{0: SScribe_Zip_Handler, 1: Disk_Space_Spy_Logger}
	 */
	private function make_handler_with_spy(): array {
		$export_dir = ( new SScribe_Zip_Handler() )->get_export_dir();

		$reflection = new \ReflectionClass( SScribe_Zip_Handler::class );
		$handler    = $reflection->newInstanceWithoutConstructor();
		$logger     = new Disk_Space_Spy_Logger();

		$logger_property = $reflection->getProperty( 'logger' );
		$logger_property->setValue( $handler, $logger );

		$dir_property = $reflection->getProperty( 'export_dir' );
		$dir_property->setValue( $handler, $export_dir );

		return array( $handler, $logger );
	}

	public function test_create_zip_aborts_on_insufficient_disk_space_without_publishing_export_index_row(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}

		list( $handler, $logger ) = $this->make_handler_with_spy();
		$export_dir = $handler->get_export_dir();

		$free = @disk_free_space( $export_dir );
		if ( false === $free || $free <= 0 ) {
			$this->markTestSkipped( 'disk_free_space() unavailable for the export directory' );
		}

		// The abort triggers at total_source_size > free * 0.95. Size the
		// sparse fixture at ~1.1x free so the real comparison trips while the
		// file stays well under the platform's sparse-size ceiling.
		$logical = (int) ( $free * 1.1 ) + 2147483648;
		$logical = min( $logical, self::SPARSE_CEILING );
		if ( (float) $logical <= $free * 0.95 ) {
			$this->markTestSkipped( 'volume free space exceeds the sparse-file ceiling; disk-full branch not reachable here' );
		}

		$this->source_dir = $handler->create_temp_dir();
		wp_mkdir_p( $this->source_dir . '/EN' );
		$fixture = $this->source_dir . '/EN/P001-Test.docx';
		if ( ! $this->make_sparse_file( $fixture, $logical ) ) {
			$this->markTestSkipped( 'needs NTFS sparse-file support (fsutil sparse setflag + ftruncate)' );
		}
		$this->deny_read_data( $fixture );

		$index_before = get_option( 'sscribe_export_index', array() );
		$options      = isset( $GLOBALS['sscribe_test_options'] ) && is_array( $GLOBALS['sscribe_test_options'] )
			? $GLOBALS['sscribe_test_options']
			: array();
		$rows_before  = array_keys(
			array_filter(
				$options,
				static fn( $key ): bool => is_string( $key ) && str_starts_with( $key, 'sscribe_export_row_' ),
				ARRAY_FILTER_USE_KEY
			)
		);

		$result = $handler->create_zip( $this->source_dir, 'disk-full-' . bin2hex( random_bytes( 3 ) ), array( 'docx' ) );

		$this->assertFalse( $result, 'ZIP assembly must abort when the source exceeds 95% of the free disk space.' );

		$messages = $logger->messages_at_level( 'error' );
		$found    = null;
		foreach ( $messages as $entry ) {
			if ( 'Insufficient disk space to create ZIP archive' === $entry['message'] ) {
				$found = $entry;
				break;
			}
		}
		$this->assertNotNull(
			$found,
			'The disk-full abort must log the exact "Insufficient disk space to create ZIP archive" message.'
		);
		$this->assertSame( $logical, (int) ( $found['context']['required_bytes'] ?? 0 ), 'required_bytes must be the aggregated source size.' );
		$this->assertGreaterThan( 0, (int) ( $found['context']['available_bytes'] ?? 0 ), 'available_bytes must report the probed free space.' );

		$this->assertSame(
			$index_before,
			get_option( 'sscribe_export_index', array() ),
			'A disk-full abort must NOT publish an export-index entry.'
		);

		$options     = isset( $GLOBALS['sscribe_test_options'] ) && is_array( $GLOBALS['sscribe_test_options'] )
			? $GLOBALS['sscribe_test_options']
			: array();
		$rows_after = array_keys(
			array_filter(
				$options,
				static fn( $key ): bool => is_string( $key ) && str_starts_with( $key, 'sscribe_export_row_' ),
				ARRAY_FILTER_USE_KEY
			)
		);
		$this->assertSame( $rows_before, $rows_after, 'A disk-full abort must NOT publish an export metadata row.' );

		$this->assertDirectoryDoesNotExist( $this->source_dir, 'The abort branch must consume the source directory.' );
		$this->source_dir = null;

		$staging = glob( $export_dir . '/.tmp-sscribe-*.zip' ) ?: array();
		$this->assertSame( array(), $staging, 'The abort must happen before any staging archive is created.' );
	}

	public function test_disk_space_precheck_precedes_export_index_lock_and_publication(): void {
		$source = (string) file_get_contents( SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-zip-handler.php' );

		$message = strpos( $source, 'Insufficient disk space to create ZIP archive' );
		$this->assertNotFalse( $message );

		$probe = strpos( $source, '@disk_free_space( $this->export_dir )' );
		$this->assertNotFalse( $probe );
		$this->assertLessThan( $message, $probe, 'The abort message must sit in the disk_free_space() decision branch.' );

		$lock = strpos( $source, "\$lock_token   = \$lock_manager->acquire_lock( \$lock_name, 120, 115 );" );
		$this->assertNotFalse( $lock );
		$this->assertLessThan(
			$lock,
			$message,
			'The disk-space pre-check must run before the export-index lock is acquired so a disk-full abort can never queue or publish an export row.'
		);

		$publish = strpos( $source, "\$index[] = \$basename;" );
		$this->assertNotFalse( $publish );
		$this->assertLessThan(
			$publish,
			$message,
			'Export-index publication must be unreachable when the disk-full abort fires.'
		);
	}
}

/**
 * In-memory SScribe_Logger_Interface spy. Records every entry so the test
 * can assert the exact abort message and its context payload.
 */
class Disk_Space_Spy_Logger implements \SScribe_Logger_Interface {

	/** @var array<int, array{level: string, message: string, context: array}> */
	public array $entries = array();

	public function set_session_id( string $session_id ): void {
		unset( $session_id );
	}

	private function record( string $level, string $message, array $context ): void {
		$this->entries[] = array(
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		);
	}

	public function emergency( string $message, array $context = array() ): void {
		$this->record( 'emergency', $message, $context );
	}

	public function alert( string $message, array $context = array() ): void {
		$this->record( 'alert', $message, $context );
	}

	public function critical( string $message, array $context = array() ): void {
		$this->record( 'critical', $message, $context );
	}

	public function error( string $message, array $context = array() ): void {
		$this->record( 'error', $message, $context );
	}

	public function warning( string $message, array $context = array() ): void {
		$this->record( 'warning', $message, $context );
	}

	public function notice( string $message, array $context = array() ): void {
		$this->record( 'notice', $message, $context );
	}

	public function info( string $message, array $context = array() ): void {
		$this->record( 'info', $message, $context );
	}

	public function debug( string $message, array $context = array() ): void {
		$this->record( 'debug', $message, $context );
	}

	public function log( string $level, string $message, array $context = array() ): void {
		$this->record( $level, $message, $context );
	}

	public function is_enabled(): bool {
		return true;
	}

	public function get_logs( int $limit = -1 ): array {
		$lines = array_map(
			static fn( array $entry ): string => sprintf( '[%s] %s', $entry['level'], $entry['message'] ),
			$this->entries
		);
		return $limit < 0 ? $lines : array_slice( $lines, -$limit );
	}

	public function clear_logs(): void {
		$this->entries = array();
	}

	public function get_log_file(): string {
		return '';
	}

	/**
	 * @return array<int, array{level: string, message: string, context: array}>
	 */
	public function messages_at_level( string $level ): array {
		return array_values(
			array_filter(
				$this->entries,
				static fn( array $entry ): bool => $entry['level'] === $level
			)
		);
	}
}
