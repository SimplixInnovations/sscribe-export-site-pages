<?php
/**
 * Isolated fixtures for Plugin Check report parsing/validation.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/lib/plugin-check-report.php';

final class SScribe_Plugin_Check_Report_Fixtures_Test extends TestCase {

	private function fixture( string $name ): string {
		return dirname( __DIR__ ) . '/fixtures/plugin-check/' . $name;
	}

	private function triage_rows(): array {
		return array(
			array( 'code' => 'WordPress.DB.DirectDatabaseQuery.DirectQuery', 'status' => 'acknowledged' ),
			array( 'code' => 'WordPress.DB.DirectDatabaseQuery.NoCaching', 'status' => 'acknowledged' ),
			array( 'code' => 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound', 'status' => 'fixed' ),
		);
	}

	public function test_missing_report_is_blocked_not_pass(): void {
		$parsed = sscribe_parse_plugin_check_report( $this->fixture( 'does-not-exist.log' ) );
		$result = sscribe_validate_plugin_check_report( $parsed, $this->triage_rows() );
		$this::assertFalse( $result['ok'] );
		$this::assertTrue( $result['blocked'] );
		$this::assertContains( 'missing_report', $result['violations'] );
	}

	public function test_empty_report_is_blocked(): void {
		$parsed = sscribe_parse_plugin_check_report( $this->fixture( 'empty.log' ) );
		$result = sscribe_validate_plugin_check_report( $parsed, $this->triage_rows() );
		$this::assertFalse( $result['ok'] );
		$this::assertTrue( $result['blocked'] );
		$this::assertContains( 'empty_report', $result['violations'] );
	}

	public function test_stub_report_is_never_pass(): void {
		$parsed = sscribe_parse_plugin_check_report( $this->fixture( 'stub.log' ) );
		$this::assertTrue( $parsed['is_stub'] );
		$result = sscribe_validate_plugin_check_report( $parsed, $this->triage_rows() );
		$this::assertFalse( $result['ok'] );
		$this::assertContains( 'stub_report', $result['violations'] );
	}

	public function test_malformed_report_fails_parse(): void {
		$parsed = sscribe_parse_plugin_check_report( $this->fixture( 'malformed.log' ) );
		$result = sscribe_validate_plugin_check_report( $parsed, $this->triage_rows() );
		$this::assertFalse( $result['ok'] );
		$this::assertContains( 'malformed_report', $result['violations'] );
	}

	public function test_errors_in_report_fail_validation(): void {
		$parsed = sscribe_parse_plugin_check_report( $this->fixture( 'errors-present.log' ) );
		$result = sscribe_validate_plugin_check_report( $parsed, $this->triage_rows() );
		$this::assertFalse( $result['ok'] );
		$this::assertContains( 'report_contains_errors', $result['violations'] );
	}

	public function test_unknown_code_is_flagged(): void {
		$parsed = sscribe_parse_plugin_check_report( $this->fixture( 'unknown-code.log' ) );
		$result = sscribe_validate_plugin_check_report( $parsed, $this->triage_rows() );
		$this::assertFalse( $result['ok'] );
		$joined = implode( ' ', $result['violations'] );
		$this::assertStringContainsString( 'unknown_code:', $joined );
		$this::assertStringContainsString( 'PluginCheck.Security.Unknown.BrandNewSniff', $joined );
	}

	public function test_fixed_warning_recurrence_is_flagged(): void {
		$parsed = sscribe_parse_plugin_check_report( $this->fixture( 'fixed-recurrence.log' ) );
		$result = sscribe_validate_plugin_check_report( $parsed, $this->triage_rows() );
		$this::assertFalse( $result['ok'] );
		$joined = implode( ' ', $result['violations'] );
		$this::assertStringContainsString( 'fixed_warning_recurrence:', $joined );
		$this::assertStringContainsString( 'PrefixAllGlobals', $joined );
	}

	public function test_wrong_source_is_flagged(): void {
		$parsed = sscribe_parse_plugin_check_report( $this->fixture( 'wrong-source.json' ) );
		$result = sscribe_validate_plugin_check_report(
			$parsed,
			$this->triage_rows(),
			array( 'expected_source' => 'sscribe-export-site-pages' )
		);
		$this::assertFalse( $result['ok'] );
		$joined = implode( ' ', $result['violations'] );
		$this::assertStringContainsString( 'wrong_source:', $joined );
	}

	public function test_valid_exception_passes(): void {
		$parsed = sscribe_parse_plugin_check_report( $this->fixture( 'valid-exception.log' ) );
		$result = sscribe_validate_plugin_check_report( $parsed, $this->triage_rows() );
		$this::assertTrue( $result['ok'], 'Triaged warnings must pass. Violations: ' . implode( ',', $result['violations'] ) );
		$this::assertSame( array(), $result['violations'] );
	}

	public function test_clean_success_passes(): void {
		$parsed = sscribe_parse_plugin_check_report( $this->fixture( 'success-clean.log' ) );
		$result = sscribe_validate_plugin_check_report( $parsed, $this->triage_rows() );
		$this::assertTrue( $result['ok'], 'Violations: ' . implode( ',', $result['violations'] ) );
		$this::assertTrue( $parsed['success_marker'] );
	}

	public function test_stale_identity_is_flagged(): void {
		$parsed = sscribe_parse_plugin_check_report( $this->fixture( 'stale-identity.log' ) );
		$result = sscribe_validate_plugin_check_report(
			$parsed,
			$this->triage_rows(),
			array(
				'source_sha' => 'cccccccccccccccccccccccccccccccccccccccc',
				'zip_sha256' => 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
			)
		);
		$this::assertFalse( $result['ok'] );
		$joined = implode( ' ', $result['violations'] );
		$this::assertStringContainsString( 'stale_', $joined );
	}
}
