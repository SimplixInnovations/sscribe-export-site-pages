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

	private static function activator_source(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-sscribe-activator.php' );
	}

	public function test_migrations_reuse_the_canonical_activation_schema(): void {
		$upgrader  = self::source();
		$activator = self::activator_source();

		$this->assertStringNotContainsString( 'SHOW INDEX FROM', $upgrader );
		$this->assertStringNotContainsString( 'SHOW COLUMNS FROM', $upgrader );
		$this->assertStringNotContainsString( 'MODIFY COLUMN', $upgrader );
		$this->assertStringContainsString( 'SScribe_Activator::create_database_tables( false )', $upgrader );
		$this->assertDoesNotMatchRegularExpression(
			'/["\']CREATE\\s+TABLE\\s+/i',
			$upgrader,
			'The upgrader must not own a second executable schema declaration.'
		);

		$this->assertStringContainsString( 'session_id VARCHAR(60)', $activator );
		$this->assertStringContainsString( 'KEY idx_session_id (session_id)', $activator );
		$this->assertStringContainsString( 'failed_pages INT UNSIGNED', $activator );
	}

	public function test_canonical_schema_fails_closed_when_dbdelta_is_unavailable(): void {
		$source = self::activator_source();
		$this->assertMatchesRegularExpression(
			"/function_exists\(\s*'dbDelta'\s*\)/",
			$source,
			'Canonical schema reconciliation must verify dbDelta exists after loading upgrade.php.'
		);
	}


	public function test_dbdelta_errors_are_checked_before_schema_version_can_advance(): void {
		$activator = self::activator_source();
		$upgrader  = self::source();

		$this->assertStringContainsString(
			'run_dbdelta_or_throw',
			$activator,
			'Canonical schema reconciliation must pass through one fail-closed dbDelta wrapper.'
		);
		$this->assertStringContainsString(
			'$wpdb->last_error',
			$activator,
			'dbDelta failures must be inspected explicitly because dbDelta can report SQL errors without throwing.'
		);
		$this->assertStringContainsString(
			'assert_required_schema',
			$activator,
			'Canonical reconciliation must verify required columns before recording the schema version.'
		);
		$this->assertStringContainsString(
			'SScribe_Activator::create_database_tables( false )',
			$upgrader,
			'Runtime upgrades must reconcile without advancing schema_version prematurely.'
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
