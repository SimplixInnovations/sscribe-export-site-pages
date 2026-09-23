<?php
/**
 * Regression contracts for the final WordPress.org runtime hardening audit.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Final_Runtime_Hardening_Test extends TestCase {
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	private static function source( string $path ): string {
		$full = self::root() . '/' . $path;
		self::assertFileExists( $full );
		return (string) file_get_contents( $full );
	}

	public function test_toasts_use_a_real_keyboard_accessible_dismiss_button(): void {
		$src = self::source( 'admin/js/sscribe-admin.js' );
		self::assertStringContainsString( "addClass('sscribe-toast-dismiss')", $src );
		self::assertStringContainsString( "attr('aria-label'", $src );
		self::assertStringContainsString( "\$toast.append(\$icon).append(\$body).append(\$dismissButton)", $src );
	}

	public function test_preflight_banner_restores_focus_after_every_exit(): void {
		$src = self::source( 'admin/js/sscribe-admin.js' );
		self::assertStringContainsString( 'const previousFocus = document.activeElement;', $src );
		self::assertStringContainsString( 'restorePreflightFocus', $src );
		self::assertGreaterThanOrEqual( 3, substr_count( $src, 'restorePreflightFocus();' ) );
	}

	public function test_inactive_tabpanels_are_hidden_in_initial_markup(): void {
		$src = self::source( 'admin/partials/sscribe-admin-display.php' );
		foreach ( array( 'history', 'support', 'debug' ) as $tab ) {
			self::assertMatchesRegularExpression(
				'/id="sscribe-tab-' . $tab . '"[^>]*aria-hidden="true"[^>]*hidden/',
				$src,
				"Inactive {$tab} panel must be removed from the initial tab order."
			);
		}
	}

	public function test_debug_refresh_feedback_and_errors_are_announced_without_role_log_replay(): void {
		$tab = self::source( 'admin/partials/sscribe-admin-debug-tab.php' );
		$js  = self::source( 'admin/js/sscribe-debug-console.js' );
		self::assertStringNotContainsString( 'class="sscribe-debug-console-card" role="log"', $tab );
		self::assertMatchesRegularExpression( '/id="sscribe-debug-refresh-paused"[^>]*role="status"[^>]*aria-live="polite"/', $tab );
		self::assertStringContainsString( 'sscribe-feedback-success" role="status"', $js );
		self::assertStringContainsString( 'sscribe-feedback-error" role="alert"', $js );
		self::assertStringContainsString( 'sscribe-rotated-error" role="alert"', $js );
	}

	public function test_history_delete_confirmation_has_a_real_anchor(): void {
		$markup = self::source( 'admin/partials/sscribe-admin-display.php' );
		self::assertStringContainsString( 'class="sscribe-history-filename"', $markup );
	}

	public function test_export_phase_stepper_exposes_non_colour_state(): void {
		$src = self::source( 'admin/js/sscribe-admin.js' );
		self::assertStringContainsString( "attr('aria-current', 'step')", $src );
		self::assertStringContainsString( "removeAttr('aria-current')", $src );
		self::assertStringContainsString( 'sscribe-phase-state-text', $src );
	}

	public function test_dismissed_onboarding_and_preflight_advisories_are_restored_from_storage(): void {
		$src = self::source( 'admin/js/sscribe-admin.js' );
		self::assertStringContainsString( 'restorePersistedDismissals', $src );
		self::assertStringContainsString( "localStorage.getItem('sscribe_onboarding_dismissed')", $src );
		self::assertStringContainsString( "sessionStorage.getItem('sscribe_preflight_dismissed')", $src );
	}

	public function test_single_page_child_query_is_bounded_and_skips_unused_cache_priming(): void {
		$src = self::source( 'includes/class-sscribe-page-collector.php' );
		$start = strpos( $src, 'private function get_child_pages(' );
		self::assertNotFalse( $start );
		$section = substr( $src, (int) $start, 4500 );
		self::assertStringContainsString( "'numberposts'             => 200", $section );
		self::assertStringContainsString( "'update_post_meta_cache' => false", $section );
		self::assertStringContainsString( "'update_post_term_cache' => false", $section );
	}

	public function test_readability_filter_primes_posts_in_bounded_batches(): void {
		$src = self::source( 'includes/class-sscribe-page-collector.php' );
		self::assertStringContainsString( 'prime_readability_post_cache', $src );
		$start = strpos( $src, 'private function filter_readable_page_ids(' );
		self::assertNotFalse( $start );
		$section = substr( $src, (int) $start, 1200 );
		self::assertStringContainsString( '$this->prime_readability_post_cache( $page_ids );', $section );
	}

	public function test_rate_limiter_never_spins_for_seconds_under_contention(): void {
		$src = self::source( 'includes/class-sscribe-export-rate-limiter.php' );
		self::assertStringContainsString( 'private const LOCK_MAX_ATTEMPTS = 5;', $src );
		self::assertStringContainsString( 'private const LOCK_RETRY_DELAY_US = 20000;', $src );
		self::assertStringNotContainsString( '$attempts < 50', $src );
		self::assertStringNotContainsString( 'usleep( 100000 )', $src );
	}

	public function test_upgrader_is_fail_closed_portable_and_backed_off_after_failure(): void {
		$src = self::source( 'includes/class-sscribe-upgrader.php' );
		self::assertStringContainsString( "if ( ! function_exists( 'dbDelta' ) )", $src );
		self::assertStringContainsString( 'sscribe_upgrade_next_attempt', $src );
		self::assertStringContainsString( 'sscribe_upgrade_failures', $src );
		self::assertStringContainsString( 'is_sqlite_database', $src );
		self::assertStringContainsString( 'run_sqlite_schema_convergence', $src );
		self::assertStringContainsString( 'dbDelta( $sql_logs )', $src );
		self::assertStringContainsString( 'session_id VARCHAR(60)', $src );
		self::assertStringContainsString( 'KEY idx_session_id (session_id)', $src );
	}

	public function test_table_like_introspection_escapes_literal_table_names(): void {
		foreach ( array( 'includes/class-sscribe-audit-trail.php', 'includes/class-sscribe-logger-enhanced.php' ) as $path ) {
			$src = self::source( $path );
			self::assertStringContainsString( "\$wpdb->esc_like( \$this->table_name )", $src, $path );
		}
	}

	public function test_private_storage_protection_does_not_rewrite_identical_htaccess(): void {
		$src = self::source( 'includes/class-sscribe-security.php' );
		self::assertStringContainsString( 'hash_equals( $content, (string) file_get_contents( $htaccess_path ) )', $src );
	}

	public function test_zip_log_index_transient_ttl_matches_72_hour_retention(): void {
		$src = self::source( 'includes/class-sscribe-export-log.php' );
		self::assertStringNotContainsString( '30 * DAY_IN_SECONDS', $src );
		self::assertStringContainsString( '72 * HOUR_IN_SECONDS', $src );
	}

	public function test_boot_notices_use_modern_wordpress_notice_classes(): void {
		$src = self::source( 'sscribe-export-site-pages.php' );
		self::assertStringNotContainsString( '<div class="error">', $src );
		self::assertGreaterThanOrEqual( 3, substr_count( $src, 'notice notice-error' ) );
	}

	public function test_release_docs_and_e2e_pin_current_maintained_wordpress_release(): void {
		$checklist = self::source( 'docs/LOCAL_RELEASE_CHECKLIST_v2.0.0.md' );
		$runtime   = self::source( 'tests-e2e/runtime/native-wordpress.ts' );
		self::assertStringContainsString( 'currently 7.1.2', $checklist );
		self::assertStringContainsString( "WORDPRESS_VERSION = '7.1.2'", $runtime );
	}
}
