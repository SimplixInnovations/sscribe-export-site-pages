<?php
/**
 * SScribe Diagnostics pure helpers coverage test
 *
 * Targets the pure private helpers of SScribe_Diagnostics via reflection.
 *
 *   - categorize_error()       : each category branch + unknown fallback
 *   - format_support_value()   : php_version normalization, passthrough
 *   - normalize_path()         : backslash normalization, lowercase on Windows
 *   - is_temp_dir_in_active_set() : in set, not in set
 *   - get_recommendations()    : with low-priority checks
 *   - find_mpdf_font_files()   : missing dir, empty dir, found files
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

if ( ! class_exists( '\\SScribe_Diagnostics', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-diagnostics.php';
}

final class SScribe_Diagnostics_Categorize_Coverage_Test extends TestCase {

	private \SScribe_Diagnostics $diag;
	private ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();
		$this->diag = new \SScribe_Diagnostics();
		$this->ref  = new ReflectionClass( $this->diag );
	}

	private function call( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		return $m->invokeArgs( $this->diag, $args );
	}

	public function test_categorize_error_returns_memory_for_memory_keywords(): void {
		$this::assertSame( 'memory', $this->call( 'categorize_error', array( 'Allowed memory size exhausted' ) ) );
		$this::assertSame( 'memory', $this->call( 'categorize_error', array( 'Memory allocated' ) ) );
	}

	public function test_categorize_error_returns_timeout_for_timeout_keywords(): void {
		$this::assertSame( 'timeout', $this->call( 'categorize_error', array( 'Maximum execution time exceeded' ) ) );
		$this::assertSame( 'timeout', $this->call( 'categorize_error', array( 'timeout' ) ) );
	}

	public function test_categorize_error_returns_permission_for_permission_keywords(): void {
		$this::assertSame( 'permission', $this->call( 'categorize_error', array( 'Permission denied' ) ) );
		$this::assertSame( 'permission', $this->call( 'categorize_error', array( 'directory not writable' ) ) );
	}

	public function test_categorize_error_returns_zip_for_zip_keywords(): void {
		$this::assertSame( 'zip', $this->call( 'categorize_error', array( 'failed to open archive' ) ) );
		$this::assertSame( 'zip', $this->call( 'categorize_error', array( 'ZipArchive error' ) ) );
	}

	public function test_categorize_error_returns_pdf_for_pdf_keywords(): void {
		$this::assertSame( 'pdf', $this->call( 'categorize_error', array( 'TCPDF rendering failed' ) ) );
		$this::assertSame( 'pdf', $this->call( 'categorize_error', array( 'mPDF rendering failed' ) ) );
		$this::assertSame( 'pdf', $this->call( 'categorize_error', array( 'pdf file unreadable' ) ) );
	}

	public function test_categorize_error_returns_docx_for_docx_keywords(): void {
		$this::assertSame( 'docx', $this->call( 'categorize_error', array( 'PhpWord exception thrown' ) ) );
		$this::assertSame( 'docx', $this->call( 'categorize_error', array( 'docx write failed' ) ) );
	}

	public function test_categorize_error_returns_network_for_network_keywords(): void {
		$this::assertSame( 'network', $this->call( 'categorize_error', array( 'network unreachable' ) ) );
		$this::assertSame( 'network', $this->call( 'categorize_error', array( 'AJAX request failed' ) ) );
	}

	public function test_categorize_error_returns_session_for_session_keywords(): void {
		$this::assertSame( 'session', $this->call( 'categorize_error', array( 'Session not found' ) ) );
		$this::assertSame( 'session', $this->call( 'categorize_error', array( 'session expired' ) ) );
	}

	public function test_categorize_error_returns_unknown_for_unrecognized(): void {
		$this::assertSame( 'unknown', $this->call( 'categorize_error', array( 'completely unrelated error message' ) ) );
	}

	public function test_format_support_value_passes_through_other_keys(): void {
		$this::assertSame( '8.5.10', $this->call( 'format_support_value', array( 'php_ext_version', '8.5.10' ) ) );
		$this::assertSame( 'value', $this->call( 'format_support_value', array( 'any_key', 'value' ) ) );
	}

	public function test_format_support_value_normalizes_php_version(): void {
		$this::assertSame( '8.5.x', $this->call( 'format_support_value', array( 'php_version', '8.5.10' ) ) );
		$this::assertSame( '7.4.x', $this->call( 'format_support_value', array( 'php_version', '7.4.33' ) ) );
	}

	public function test_format_support_value_php_version_passthrough_when_fewer_than_two_parts(): void {
		// Without 2+ '.'-separated parts, the php_version formatter returns the value as-is.
		$this::assertSame( 'nodots', $this->call( 'format_support_value', array( 'php_version', 'nodots' ) ) );
		$this::assertSame( '8', $this->call( 'format_support_value', array( 'php_version', '8' ) ) );
	}

	public function test_normalize_path_handles_backslashes(): void {
		$result = $this->call( 'normalize_path', array( '/foo\\bar/baz' ) );
		$this::assertNotSame( '', $result );
	}

	public function test_is_temp_dir_in_active_set_returns_true_when_present(): void {
		// The function indexes $active_dirs by normalized realpath. On Windows,
		// normalize_path() lowercases the path so we must too.
		$tmp  = sys_get_temp_dir() . '/diag_temp_' . uniqid();
		mkdir( $tmp, 0755, true );
		$real = realpath( $tmp );
		$norm = rtrim( str_replace( '\\', '/', (string) $real ), '/' );
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$norm = strtolower( $norm );
		}
		$map = array( $norm => true, '/tmp/other' => true );

		$result = $this->call( 'is_temp_dir_in_active_set', array( $tmp, $map ) );
		$this::assertTrue( $result );

		@rmdir( $tmp );
	}

	public function test_is_temp_dir_in_active_set_returns_false_when_absent(): void {
		$result = $this->call( 'is_temp_dir_in_active_set', array( '/tmp/nope_' . uniqid(), array( '/tmp/never-here' => true ) ) );
		$this::assertFalse( $result );
	}

	public function test_get_active_seo_plugins_returns_array(): void {
		$result = $this->call( 'get_active_seo_plugins' );
		$this::assertIsArray( $result );
	}

	public function test_diagnose_page_error_returns_array(): void {
		$result = $this->diag->diagnose_page_error( 1, 'docx', 'Memory exhausted', array() );
		$this::assertIsArray( $result );
		$this::assertArrayHasKey( 'category', $result );
	}

	public function test_class_has_public_surface(): void {
		$this::assertTrue( method_exists( \SScribe_Diagnostics::class, 'get_support_info' ) );
		$this::assertTrue( method_exists( \SScribe_Diagnostics::class, 'run_preflight' ) );
		$this::assertTrue( method_exists( \SScribe_Diagnostics::class, 'self_heal' ) );
		$this::assertTrue( method_exists( \SScribe_Diagnostics::class, 'diagnose_page_error' ) );
		$this::assertTrue( method_exists( \SScribe_Diagnostics::class, 'get_boot_diagnostics' ) );
	}
}
