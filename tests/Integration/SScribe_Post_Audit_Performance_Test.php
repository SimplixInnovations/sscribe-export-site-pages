<?php
/**
 * Final first-party audit performance/compliance regressions.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Post_Audit_Performance_Test extends TestCase {

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	public function test_single_parent_children_reuse_bounded_batch_query(): void {
		$src = (string) file_get_contents( self::root() . '/includes/class-sscribe-page-collector.php' );
		$start = strpos( $src, 'private function get_child_pages(' );
		$end   = strpos( $src, 'private function filter_readable_child_rows(', $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$method = substr( $src, $start, $end - $start );
		$this->assertStringContainsString( 'get_child_pages_batch', $method );
		$this->assertStringNotContainsString( 'get_children(', $method );
	}

	public function test_breadcrumb_query_disables_count_and_unused_cache_priming(): void {
		$src = (string) file_get_contents( self::root() . '/includes/class-sscribe-page-collector.php' );
		$start = strpos( $src, 'private function get_breadcrumbs(' );
		$end   = strpos( $src, 'public function is_wpml_active(', $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$method = substr( $src, $start, $end - $start );
		$this->assertStringContainsString( "'no_found_rows'", $method );
		$this->assertStringContainsString( "'update_post_meta_cache'", $method );
		$this->assertStringContainsString( "'update_post_term_cache'", $method );
	}

	public function test_rate_limiter_contention_budget_is_short_and_bounded(): void {
		$src = (string) file_get_contents( self::root() . '/includes/class-sscribe-export-rate-limiter.php' );
		$this->assertStringContainsString( 'LOCK_MAX_ATTEMPTS', $src );
		$this->assertStringContainsString( 'LOCK_RETRY_MICROSECONDS', $src );
		$this->assertDoesNotMatchRegularExpression( '/\$attempts\s*<\s*50/', $src );
		$this->assertStringNotContainsString( 'usleep( 100000 )', $src );
	}

	public function test_zip_index_cache_never_outlives_export_log_retention(): void {
		$src = (string) file_get_contents( self::root() . '/includes/class-sscribe-export-log.php' );
		$this->assertStringContainsString( 'ZIP_INDEX_TTL', $src );
		$this->assertStringNotContainsString( '30 * DAY_IN_SECONDS', $src );
	}

	public function test_table_exists_like_patterns_escape_literal_underscores(): void {
		foreach ( array(
			'includes/class-sscribe-audit-trail.php',
			'includes/class-sscribe-logger-enhanced.php',
		) as $file ) {
			$src = (string) file_get_contents( self::root() . '/' . $file );
			$this->assertStringContainsString( 'esc_like', $src, $file . ' must escape table names passed through SQL LIKE.' );
		}
	}

	public function test_content_cache_generation_does_not_create_new_transient_keys(): void {
		$src = (string) file_get_contents( self::root() . '/includes/class-sscribe-page-collector.php' );
		$this->assertStringContainsString( "'sscribe_page_ids_v3_'", $src );
		$this->assertStringContainsString( "'generation' => $generation", $src );
		$this->assertStringContainsString( "'sscribe_estimate_count_v2_'", $src );
		$this->assertStringNotContainsString( '"sscribe_page_ids_v2_{$post_status}_{$generation}_"', $src );
		$this->assertStringNotContainsString( "'sscribe_estimate_count_' . $generation", $src );
	}

	public function test_child_queries_paginate_instead_of_silently_truncating_large_hierarchies(): void {
		$src = (string) file_get_contents( self::root() . '/includes/class-sscribe-page-collector.php' );
		$start = strpos( $src, 'public function get_child_pages_batch(' );
		$end = strpos( $src, 'private function get_child_pages(', $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$method = substr( $src, $start, $end - $start );
		$this->assertStringContainsString( "'paged'", $method );
		$this->assertStringContainsString( '$loaded === $batch_size', $method );
		$this->assertStringContainsString( "'ID'", $method );
	}

	public function test_export_index_rows_are_batch_loaded_instead_of_one_option_query_per_row(): void {
		$src = (string) file_get_contents( self::root() . '/includes/class-sscribe-zip-handler.php' );
		$start = strpos( $src, 'public function list_export_entries(): array' );
		$end = strpos( $src, 'public function get_ajax_download_url', $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$method = substr( $src, $start, $end - $start );
		$this->assertStringContainsString( 'option_name IN', $method );
		$this->assertStringContainsString( '$wpdb->prepare', $method );
		$this->assertStringNotContainsString( '$this->get_export_entry( $basename )', $method );
	}

	public function test_uninstall_clears_upgrade_retry_backoff_state(): void {
		$src = (string) file_get_contents( self::root() . '/uninstall.php' );
		$this->assertStringContainsString( "delete_option( 'sscribe_upgrade_failures' )", $src );
		$this->assertStringContainsString( "delete_option( 'sscribe_upgrade_next_attempt' )", $src );
	}

	public function test_vendor_dependency_notice_is_confined_to_relevant_admin_screens(): void {
		$src = (string) file_get_contents( self::root() . '/includes/class-sscribe.php' );
		$start = strpos( $src, 'public function render_vendor_dependency_notice' );
		$end   = strpos( $src, 'public function invalidate_admin_page_cache', $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$method = substr( $src, $start, $end - $start );
		$this->assertStringContainsString( 'get_current_screen', $method );
		$this->assertStringContainsString( 'toplevel_page_sscribe-export', $method );
		$this->assertStringContainsString( "'plugins'", $method );
	}
}
