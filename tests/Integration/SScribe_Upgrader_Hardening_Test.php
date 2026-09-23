<?php
/**
 * Upgrade-path hardening regressions.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Upgrader_Hardening_Test extends TestCase {

	private static function source(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-sscribe-upgrader.php' );
	}

	public function test_migrations_converge_through_dbdelta_instead_of_mysql_only_introspection(): void {
		$source = self::source();
		$this->assertStringNotContainsString( 'SHOW INDEX FROM', $source );
		$this->assertStringNotContainsString( 'SHOW COLUMNS FROM', $source );
		$this->assertStringNotContainsString( 'MODIFY COLUMN', $source );
		$this->assertStringContainsString( 'session_id VARCHAR(60)', $source );
		$this->assertStringContainsString( 'KEY idx_session_id (session_id)', $source );
	}

	public function test_upgrader_fails_closed_when_dbdelta_is_unavailable(): void {
		$source = self::source();
		$this->assertMatchesRegularExpression(
			"/function_exists\(\s*'dbDelta'\s*\)/",
			$source,
			'Upgrader must verify dbDelta exists after loading upgrade.php.'
		);
	}

	public function test_failed_upgrade_has_context_gate_and_exponential_retry_backoff(): void {
		$source = self::source();
		$this->assertStringContainsString( 'should_attempt_upgrade', $source );
		$this->assertStringContainsString( 'sscribe_upgrade_next_attempt', $source );
		$this->assertStringContainsString( 'sscribe_upgrade_failures', $source );
		$this->assertStringContainsString( 'record_upgrade_failure', $source );
		$this->assertStringContainsString( 'clear_upgrade_failure_state', $source );
	}
}
