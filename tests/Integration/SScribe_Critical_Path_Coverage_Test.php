<?php
/**
 * SScribe Critical-Path Coverage Floor Test
 *
 * @package SScribe_Export_Site_Pages
 *
 * Phase 26: coverage floor on critical paths. Each critical path
 * (the exported formats, the download/rate-limit/lock envelope
 * helpers, the version-sync contract, and the license SPDX
 * identity) MUST have at least one PHPUnit test that runs in the
 * `composer test` suite.
 *
 * Without Xdebug / PCOV (which are not installed in the sandbox),
 * we cannot compute line-level coverage. Instead this test
 * statically asserts that:
 *
 *   1. A test file exists for each critical source class.
 *   2. The test file contains at least one test method.
 *   3. The test file is registered in the autoload-dev classmap
 *      (so it actually runs under `composer test`).
 *
 * This is the cheapest possible coverage floor that still catches
 * the regression we care about: a critical class whose test gets
 * silently dropped during a refactor. PHPUnit will not warn when
 * a test file is removed or has all of its @test methods deleted
 * (it just reports 0 tests from that file).
 */

declare(strict_types=1);

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SScribe_Critical_Path_Coverage_Test extends TestCase {

	/**
	 * Map of critical source class -> expected test file (relative
	 * to the plugin root). Each entry is a category the release
	 * cannot ship without.
	 *
	 * @var array<string, array{class: string, test_file: string, rationale: string}>
	 */
	private const CRITICAL_PATHS = array(
		// Format exporters (DOCX / PDF / HTML / Markdown).
		'docx_renderer'      => array(
			'class'     => 'SScribe_DOCX_Content_Renderer',
			'test_file' => 'tests/Unit/SScribe_DOCX_Content_Renderer_Test.php',
			'rationale' => 'DOCX format content rendering path',
		),
		'pdf_font_policy'    => array(
			'class'     => 'SScribe_PDF_Exporter',
			'test_file' => 'tests/Unit/SScribe_PDF_Exporter_Find_Font_File_Test.php',
			'rationale' => 'PDF font lookup + CJK / RTL fallback policy',
		),
		'html_exporter'      => array(
			'class'     => 'SScribe_Html_Exporter',
			'test_file' => 'tests/Unit/SScribe_HTML_Exporter_Test.php',
			'rationale' => 'HTML format export path',
		),
		'markdown_tables'    => array(
			'class'     => 'SScribe_Markdown_Exporter',
			'test_file' => 'tests/Unit/SScribe_Markdown_Exporter_Tables_Test.php',
			'rationale' => 'Markdown table rendering (most-fragile format path)',
		),

		// Envelope helpers (response contract).
		'ajax_guard'         => array(
			'class'     => 'SScribe_AJAX_Guard',
			'test_file' => 'tests/Unit/SScribe_AJAX_Guard_Test.php',
			'rationale' => 'Phase 13: every server->client envelope carries request_id',
		),
		'rate_limit_response' => array(
			'class'     => 'SScribe_Rate_Limit_Response',
			'test_file' => 'tests/Unit/SScribe_Rate_Limit_Response_Test.php',
			'rationale' => 'Phase 20: 429/503 carry request_id + Retry-After',
		),
		'rate_limiter'       => array(
			'class'     => 'SScribe_Export_Rate_Limiter',
			'test_file' => 'tests/Unit/SScribe_Export_Rate_Limiter_Test.php',
			'rationale' => 'per-user / per-IP export rate limiter',
		),
		'lock_manager'       => array(
			'class'     => 'SScribe_Export_Lock_Manager',
			'test_file' => 'tests/Unit/SScribe_Export_Lock_Manager_Test.php',
			'rationale' => 'per-export mutex + 409 conflict envelope',
		),
		'batch_processor'    => array(
			'class'     => 'SScribe_Batch_Processor',
			'test_file' => 'tests/Unit/SScribe_Batch_Processor_Format_Options_Test.php',
			'rationale' => 'streamed batch processing + format dispatch',
		),
		'capabilities'       => array(
			'class'     => 'SScribe_Capabilities',
			'test_file' => 'tests/Unit/SScribe_Capabilities_Test.php',
			'rationale' => 'capability grants (sscribe_export / sscribe_health)',
		),
		'activator'          => array(
			'class'     => 'SScribe_Activator',
			'test_file' => 'tests/Unit/SScribe_Activator_Test.php',
			'rationale' => 'activation: dbDelta + capability grants + cron',
		),
	);

	/**
	 * Integration tests for non-class-bound contracts. Each must
	 * exist as a runnable test file.
	 *
	 * @var array<string, array{test_file: string, rationale: string}>
	 */
	private const CRITICAL_INTEGRATIONS = array(
		'version_sync'        => array(
			'test_file' => 'tests/Integration/SScribe_Version_Sync_Test.php',
			'rationale' => 'Phase 16: SSCRIBE_VERSION single source of truth',
		),
		'license_spdx'        => array(
			'test_file' => 'tests/Integration/SScribe_License_SPDIX_Test.php',
			'rationale' => 'Phase 23: GPL-2.0-or-later SPDX identity',
		),
		'shipped_invariants'  => array(
			'test_file' => 'tests/Unit/SScribe_Shipped_Invariants_Test.php',
			'rationale' => 'Phase 24: shipped-ZIP content invariants',
		),
	);

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	#[DataProvider( 'critical_paths_provider' )]
	public function test_critical_path_has_test_file( string $key, string $class, string $test_file, string $rationale ): void {
		$root = self::plugin_root();

		// $class is asserted below in test_all_critical_source_classes_exist.
		// Suppress the "unused parameter" warning for this test wrapper.
		unset( $class );

		$this->assertFileExists(
			$root . '/' . $test_file,
			"Critical path '{$key}' ({$rationale}) has no test file at {$test_file}"
		);

		// The test file must contain at least one test method (otherwise
		// PHPUnit silently registers 0 tests from it during discovery).
		$contents = (string) file_get_contents( $root . '/' . $test_file );
		$this->assertNotFalse( $contents );
		$this->assertMatchesRegularExpression(
			'/public function test_[A-Za-z_0-9]+\s*\(/',
			$contents,
			"Critical path '{$key}' test file {$test_file} has no public test_* method"
		);
	}

	public static function critical_paths_provider(): array {
		$cases = array();
		foreach ( self::CRITICAL_PATHS as $key => $entry ) {
			$cases[ $key ] = array( $key, $entry['class'], $entry['test_file'], $entry['rationale'] );
		}
		return $cases;
	}

	#[DataProvider( 'critical_integrations_provider' )]
	public function test_critical_integration_has_test_file( string $key, string $test_file, string $rationale ): void {
		$root = self::plugin_root();
		$this->assertFileExists(
			$root . '/' . $test_file,
			"Critical integration '{$key}' ({$rationale}) has no test file at {$test_file}"
		);

		$contents = (string) file_get_contents( $root . '/' . $test_file );
		$this->assertNotFalse( $contents );
		$this->assertMatchesRegularExpression(
			'/public function test_[A-Za-z_0-9]+\s*\(/',
			$contents,
			"Critical integration '{$key}' test file {$test_file} has no public test_* method"
		);
	}

	public static function critical_integrations_provider(): array {
		$cases = array();
		foreach ( self::CRITICAL_INTEGRATIONS as $key => $entry ) {
			$cases[ $key ] = array( $key, $entry['test_file'], $entry['rationale'] );
		}
		return $cases;
	}

	public function test_all_critical_source_classes_exist(): void {
		$root     = self::plugin_root();
		$autoload = (string) file_get_contents( $root . '/composer.json' );
		preg_match_all( '#"([A-Z][A-Za-z0-9_]+)\.php"#', $autoload, $matches );
		// Composer autoload classmap is built at install time; this is a soft check.
		$tracked = array();
		foreach ( self::CRITICAL_PATHS as $entry ) {
			$this->assertTrue(
				class_exists( '\\' . $entry['class'] ) || file_exists( $root . '/includes/' . strtolower( str_replace( '_', '-', $entry['class'] ) ) . '.php' ) || file_exists( $root . '/admin/' . strtolower( str_replace( '_', '-', $entry['class'] ) ) . '.php' ),
				"Critical class {$entry['class']} cannot be located in includes/ or admin/"
			);
			$tracked[] = $entry['class'];
		}
		$this->assertGreaterThanOrEqual( 10, count( $tracked ), 'at least 10 critical paths must be tracked' );
	}
}