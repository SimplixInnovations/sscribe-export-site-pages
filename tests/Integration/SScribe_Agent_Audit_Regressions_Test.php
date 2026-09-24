<?php
/**
 * Regression contracts for the final WordPress.org audit findings.
 *
 * These assertions intentionally pin the structural fixes that existing
 * functional tests did not cover. Runtime/E2E tests provide the behavioral
 * half of the contract.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Agent_Audit_Regressions_Test extends TestCase {

	private static function root(): string {
		return dirname(__DIR__, 2);
	}

	private static function source(string $path): string {
		$full = self::root() . '/' . $path;
		self::assertFileExists($full);
		return (string) file_get_contents($full);
	}

	public function test_page_id_permission_filter_bulk_primes_post_objects(): void {
		$src = self::source('includes/class-sscribe-page-collector.php');
		self::assertStringContainsString('prime_readability_post_cache', $src);
		self::assertStringContainsString('filter_readable_page_ids( array $page_ids, string $post_status, string $post_type )', $src);
		self::assertStringContainsString('no_found_rows', $src);
		self::assertStringContainsString('update_post_meta_cache', $src);
		self::assertStringContainsString('update_post_term_cache', $src);
	}

	public function test_content_cache_generation_does_not_create_unbounded_transient_names(): void {
		$src = self::source('includes/class-sscribe-page-collector.php');
		self::assertStringNotContainsString('"sscribe_page_ids_v2_{$post_status}_{$generation}_"', $src);
		self::assertStringNotContainsString("'sscribe_estimate_count_' . \$generation . '_'", $src);
		self::assertStringContainsString('cache_generation', $src);
	}

	public function test_upgrader_is_cross_database_and_backed_off(): void {
		$src = self::source('includes/class-sscribe-upgrader.php');
		self::assertStringContainsString("function_exists( 'dbDelta' )", $src);
		self::assertStringContainsString('sscribe_upgrade_next_attempt', $src);
		self::assertStringContainsString('sscribe_upgrade_failures', $src);
		self::assertStringContainsString('wp_doing_ajax()', $src);
		self::assertStringContainsString('wp_doing_cron()', $src);
		self::assertStringNotContainsString('SHOW INDEX', $src);
		self::assertStringNotContainsString('SHOW COLUMNS', $src);
		self::assertStringNotContainsString('ALTER TABLE', $src);
		self::assertStringContainsString('session_id VARCHAR(60) DEFAULT NULL', $src);
		self::assertStringContainsString('KEY idx_session_id (session_id)', $src);
	}

	public function test_rate_limiter_contention_budget_is_bounded(): void {
		$src = self::source('includes/class-sscribe-export-rate-limiter.php');
		self::assertMatchesRegularExpression('/private const LOCK_MAX_ATTEMPTS\s*=\s*[1-5]\s*;/', $src);
		self::assertMatchesRegularExpression('/private const LOCK_RETRY_MICROSECONDS\s*=\s*(?:[1-9]\d{0,3}|1\d{4}|20000)\s*;/', $src);
		self::assertStringContainsString('limiter_contention', $src);
	}

	public function test_admin_accessibility_regressions_are_structurally_closed(): void {
		$js = self::source('admin/js/sscribe-admin.js');
		$display = self::source('admin/partials/sscribe-admin-display.php');
		$debug = self::source('admin/partials/sscribe-admin-debug-tab.php');
		$debug_js = self::source('admin/js/sscribe-debug-console.js');

		self::assertStringContainsString('sscribe-toast-dismiss', $js);
		self::assertStringContainsString('restorePreflightFocus', $js);
		self::assertStringContainsString("aria-current", $js);
		self::assertStringContainsString('restorePersistedDismissals', $js);
		self::assertStringContainsString('sscribe-history-filename', $display);
		self::assertMatchesRegularExpression('/id="sscribe-tab-history"[^>]*\shidden(?:\s|>)/', $display);
		self::assertMatchesRegularExpression('/id="sscribe-tab-support"[^>]*\shidden(?:\s|>)/', $display);
		self::assertStringContainsString('role="status"', $debug);
		self::assertStringContainsString("role: 'status'", $debug_js);
	}

	public function test_boot_notices_use_current_wordpress_notice_classes(): void {
		$src = self::source('sscribe-export-site-pages.php');
		self::assertStringNotContainsString('<div class="error">', $src);
		self::assertStringContainsString('notice notice-error is-dismissible', $src);
	}
}
