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

	public function test_table_exists_probes_avoid_dialect_sensitive_like_patterns(): void {
		foreach ( array(
			'includes/class-sscribe-audit-trail.php',
			'includes/class-sscribe-logger-enhanced.php',
		) as $file ) {
			$src = (string) file_get_contents( self::root() . '/' . $file );
			$this->assertStringNotContainsString( 'SHOW TABLES LIKE', $src, $file . ' must not depend on wildcard table-name semantics.' );
			$this->assertStringContainsString( 'WHERE 1 = 0', $src, $file . ' must use a zero-row structural probe.' );
			$this->assertStringContainsString( "preg_match( '/^[A-Za-z0-9_]+\\$/D'", $src, $file . ' must validate the internal table identifier before interpolation.' );
		}
	}


	public function test_content_cache_invalidation_is_not_limited_to_page_and_post(): void {
		$src = (string) file_get_contents( self::root() . '/includes/class-sscribe.php' );
		$start = strpos( $src, 'public function invalidate_admin_page_cache' );
		$end = strpos( $src, 'public function bump_content_cache_generation', $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$method = substr( $src, $start, $end - $start );
		$this->assertStringNotContainsString( "array( 'page', 'post' )", $method );
		$this->assertStringContainsString( 'get_post_type( $post_id )', $method );
		$this->assertStringContainsString( 'bump_content_cache_generation', $method );
	}


	public function test_content_cache_generation_does_not_create_new_transient_keys(): void {
		$src = (string) file_get_contents( self::root() . '/includes/class-sscribe-page-collector.php' );
		$this->assertStringContainsString( "'sscribe_page_ids_v3_'", $src );
		$this->assertStringContainsString( "'generation' => \$generation", $src );
		$this->assertStringContainsString( "'sscribe_estimate_count_v2_'", $src );
		$this->assertStringNotContainsString( '"sscribe_page_ids_v2_{$post_status}_{$generation}_"', $src );
		$this->assertStringNotContainsString( "'sscribe_estimate_count_' . \$generation", $src );
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

	public function test_readability_hydration_is_processed_in_bounded_working_sets(): void {
		$src = (string) file_get_contents( self::root() . '/includes/class-sscribe-page-collector.php' );
		$start = strpos( $src, 'private function filter_readable_page_ids(' );
		$end   = strpos( $src, 'private function prime_readability_posts(', $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$method = substr( $src, $start, $end - $start );
		$this->assertStringContainsString( 'array_chunk( $page_ids, self::CACHE_MAX_SIZE )', $method );
		$this->assertStringContainsString( 'unset( $this->readability_post_cache[ $page_id ] )', $method );
	}

	public function test_all_language_counts_use_one_aggregate_scan_per_language(): void {
		$src = (string) file_get_contents( self::root() . '/includes/class-sscribe-export-query-controller.php' );
		$start = strpos( $src, 'public function ajax_get_all_status_counts(' );
		$end   = strpos( $src, 'private function compute_counts_payload(', $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$method = substr( $src, $start, $end - $start );
		$this->assertStringContainsString( "get_page_count_only( \$query_language, 'all', \$post_type )", $method );
		$this->assertStringNotContainsString( 'compute_counts_payload( $query_language', $method );
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

		$admin = (string) file_get_contents( self::root() . '/admin/class-sscribe-admin.php' );
		$this->assertStringContainsString( 'get_ajax_download_url( $filename, $data )', $admin );

		$url_start = strpos( $src, 'public function get_ajax_download_url(' );
		$url_end   = strpos( $src, 'public function rotate_dl_token(', $url_start );
		$this->assertNotFalse( $url_start );
		$this->assertNotFalse( $url_end );
		$url_method = substr( $src, $url_start, $url_end - $url_start );
		$this->assertStringContainsString( '?array $row = null', $url_method );
		$this->assertStringContainsString( 'null === $row ? get_option(', $url_method );
	}

	public function test_frontend_bootstrap_keeps_heavy_request_specific_graph_lazy(): void {
		$src = (string) file_get_contents( self::root() . '/includes/class-sscribe.php' );
		$start = strpos( $src, 'public function run(): void' );
		$this->assertNotFalse( $start );
		$method = substr( $src, $start );
		$this->assertStringContainsString( '$is_admin_request', $method );
		$this->assertStringContainsString( '$is_ajax_request', $method );
		$this->assertStringContainsString( '$this->define_content_hooks();', $method );
		$this->assertStringContainsString( '$this->define_privacy_hooks();', $method );
		$this->assertStringContainsString( '$this->define_ajax_hooks();', $method );
		$this->assertStringContainsString( '$this->define_cron_hooks();', $method );
		$privacy_pos = strpos( $method, '$this->define_privacy_hooks();' );
		$ajax_pos = strpos( $method, '$this->define_ajax_hooks();' );
		$cron_pos = strpos( $method, '$this->define_cron_hooks();' );
		$admin_gate_pos = strpos( $method, 'if ( $is_admin_request && ! $is_ajax_request )' );
		$this->assertIsInt( $privacy_pos );
		$this->assertIsInt( $ajax_pos );
		$this->assertIsInt( $cron_pos );
		$this->assertIsInt( $admin_gate_pos );
		$this->assertLessThan( $admin_gate_pos, $privacy_pos, 'Privacy filters must register before the non-AJAX admin gate.' );
		$this->assertLessThan( $admin_gate_pos, $ajax_pos, 'AJAX hook names must remain globally dispatchable.' );
		$this->assertLessThan( $admin_gate_pos, $cron_pos, 'Cron hook names must remain globally dispatchable.' );

		$ajax_start = strpos( $src, 'private function define_ajax_hooks(): void' );
		$ajax_end = strpos( $src, 'private function define_cron_hooks(): void', $ajax_start );
		$this->assertNotFalse( $ajax_start );
		$this->assertNotFalse( $ajax_end );
		$ajax_method = substr( $src, $ajax_start, $ajax_end - $ajax_start );
		$this->assertStringContainsString( 'add_guarded_lazy_ajax_action', $ajax_method );
		$this->assertStringContainsString( '$batch_resolver = static function', $ajax_method );
		$this->assertStringNotContainsString( '$batch = $container->get( SScribe_Batch_Processor::class );', $ajax_method );

		$cron_start = strpos( $src, 'private function define_cron_hooks(): void' );
		$cron_end = strpos( $src, 'private function define_lifecycle_hooks(): void', $cron_start );
		$this->assertNotFalse( $cron_start );
		$this->assertNotFalse( $cron_end );
		$cron_method = substr( $src, $cron_start, $cron_end - $cron_start );
		$this->assertStringNotContainsString( '$container->get( SScribe_Zip_Handler::class )', $cron_method );
		$this->assertStringNotContainsString( '$container->get( SScribe_Session::class )', $cron_method );
	}


	public function test_audit_count_cache_ignores_filters_that_do_not_affect_sql(): void {
		$src = (string) file_get_contents( self::root() . '/includes/class-sscribe-audit-trail.php' );
		$start = strpos( $src, 'public function get_event_counts(' );
		$end   = strpos( $src, 'public function cleanup(', $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$method = substr( $src, $start, $end - $start );
		$this->assertStringContainsString( "'date_from' =>", $method );
		$this->assertStringContainsString( "'date_to'   =>", $method );
		$this->assertStringContainsString( 'wp_json_encode( $cache_filters )', $method );
		$this->assertStringNotContainsString( 'wp_json_encode( $filters )', $method );
		$this->assertStringContainsString( "wp_cache_get( \$cache_key, 'sscribe_audit' )", $method );
		$this->assertStringContainsString( "wp_cache_set( \$cache_key, \$result, 'sscribe_audit', 30 )", $method );
		$this->assertStringNotContainsString( 'set_transient( $cache_key', $method );
	}

	public function test_uninstall_clears_upgrade_retry_backoff_state(): void {
		$src = (string) file_get_contents( self::root() . '/uninstall.php' );
		$this->assertStringContainsString( "delete_option( 'sscribe_upgrade_failures' )", $src );
		$this->assertStringContainsString( "delete_option( 'sscribe_upgrade_next_attempt' )", $src );
	}

	public function test_private_storage_resolver_memoizes_only_revalidated_paths(): void {
		$src = (string) file_get_contents( self::root() . '/includes/class-sscribe-private-storage.php' );
		$start = strpos( $src, 'public static function get_export_dir(' );
		$end   = strpos( $src, 'private static function prepare_managed_path(', $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$method = substr( $src, $start, $end - $start );
		$this->assertStringContainsString( 'static $resolved_paths', $method );
		$this->assertStringContainsString( 'array_key_exists( $cache_key, $resolved_paths )', $method );
		$this->assertStringContainsString( 'clearstatcache( true, $cached_path )', $method );
		$this->assertStringContainsString( 'realpath( $cached_path )', $method );
		$this->assertStringContainsString( '$cached_base = self::validate_base_candidate(', $method );
		$this->assertStringContainsString( 'self::prepare_managed_path( $cached_path, $cached_base, false )', $method );
		$this->assertStringContainsString( '! is_link( $cached_path )', $method );
		$this->assertStringContainsString( 'self::is_outside_public_roots( $cached_real )', $method );
		$this->assertStringContainsString( 'unset( $resolved_paths[ $cache_key ] )', $method );
		$this->assertStringNotContainsString( '$resolved_paths[ $cache_key ] = \'\';', $method );
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
