<?php
declare( strict_types=1 );
namespace SScribe\Tests\Unit;
use PHPUnit\Framework\TestCase;
require_once dirname( __DIR__, 2 ) . '/scripts/lib/plugin-check-report.php';
final class SScribe_Plugin_Check_Report_Fixtures_Test extends TestCase {
	public function test_unsupported_or_incomplete_reports_fail_closed(): void {
		foreach ( array( '{}', '{"success":false}', '[{"type":"ERROR","code":"bad"}]', 'Plugin Check', 'FILE: includes/example.php', 'line column type code', "Success: Checks complete. No errors found.\nWARNING ignored", '' ) as $raw ) {
			$file = tempnam( sys_get_temp_dir(), 'pcp' );
			file_put_contents( $file, $raw );
			$parsed = sscribe_parse_plugin_check_report( $file );
			unlink( $file );
			self::assertFalse( $parsed['parseable'], $raw );
		}
	}
	public function test_warning_requires_exact_file_severity_and_acknowledged_status(): void {
		$file = tempnam( sys_get_temp_dir(), 'pcp' );
		file_put_contents( $file, 'FILE: includes/example.php' . "\n" . '[{"line":1,"column":0,"type":"WARNING","code":"custom_code","message":"Example","docs":""}]' );
		$parsed = sscribe_parse_plugin_check_report( $file );
		unlink( $file );
		$identity = array( 'source_sha' => str_repeat( 'a', 40 ), 'zip_sha256' => str_repeat( 'b', 64 ), 'report_sha256' => $parsed['sha256'], 'exit_code' => 0 );
		$expected = $identity;
		$expected['evidence'] = $identity;
		$row = array( 'code' => 'custom_code', 'source' => 'includes/example.php', 'severity' => 'warning', 'status' => 'acknowledged' );
		self::assertTrue( sscribe_validate_plugin_check_report( $parsed, array( $row ), $expected )['ok'] );
		foreach ( array( 'source' => 'includes/other.php', 'severity' => 'error', 'status' => 'deferred' ) as $key => $value ) {
			$wrong = $row;
			$wrong[ $key ] = $value;
			self::assertFalse( sscribe_validate_plugin_check_report( $parsed, array( $wrong ), $expected )['ok'] );
		}
		self::assertFalse( sscribe_validate_plugin_check_report( $parsed, array( $row ) )['ok'] );
	}
}
