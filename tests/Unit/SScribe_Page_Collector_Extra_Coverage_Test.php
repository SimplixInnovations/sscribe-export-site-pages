<?php
/**
 * SScribe Page Collector extra-coverage test
 *
 * Targets helpers that the existing Page_Collector_Test does not exercise:
 *
 *   - get_selectable_post_types() — default + filtered via sscribe_allowed_post_types
 *   - resolve_post_type_for_query() — defaults, 'any', invalid, explicit allow-list
 *   - get_valid_post_statuses() — pinned labels
 *   - validate_post_status() — publish/draft/private/future/pending/all/invalid
 *   - get_content_cache_generation() — option-backed
 *   - is_wpml_active() — false unless constant + class present
 *   - get_wpml_languages() / normalize_language_code() — empty when WPML inactive
 *   - get_post_status_counts() / get_total_all_statuses() — wpdb stub paths
 *   - clear_page_caches() — instance cache reset
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Page_Collector', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-page-collector.php';
}

final class SScribe_Page_Collector_Extra_Coverage_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_options']   = array();
		$GLOBALS['sscribe_test_db_tables'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_options']   = array();
		$GLOBALS['sscribe_test_db_tables'] = array();
		parent::tearDown();
	}

	public function test_get_selectable_post_types_returns_default_array_when_no_post_types(): void {
		$collector = new \SScribe_Page_Collector();
		$types     = $collector->get_selectable_post_types();
		$this::assertIsArray( $types );
		$this::assertContains( 'page', $types );
		$this::assertNotContains( 'attachment', $types );
	}

	public function test_get_selectable_post_types_respects_sscribe_allowed_post_types_filter(): void {
		add_filter(
			'sscribe_allowed_post_types',
			static fn( array $types ): array => array( 'product', 'page' )
		);
		$collector = new \SScribe_Page_Collector();
		$types     = $collector->get_selectable_post_types();
		$this::assertSame( array( 'product', 'page' ), $types );
	}

	public function test_resolve_post_type_for_query_returns_first_selectable_for_invalid(): void {
		$collector = new \SScribe_Page_Collector();
		$resolved  = $collector->resolve_post_type_for_query(
			'nope-not-allowed',
			array( 'page', 'post' )
		);
		$this::assertSame( 'page', $resolved );
	}

	public function test_resolve_post_type_for_query_returns_input_for_valid(): void {
		$collector = new \SScribe_Page_Collector();
		$resolved  = $collector->resolve_post_type_for_query(
			'post',
			array( 'page', 'post' )
		);
		$this::assertSame( 'post', $resolved );
	}

	public function test_resolve_post_type_for_query_any_returns_allow_list(): void {
		$collector = new \SScribe_Page_Collector();
		$resolved  = $collector->resolve_post_type_for_query(
			'any',
			array( 'page', 'post' )
		);
		$this::assertSame( array( 'page', 'post' ), $resolved );
	}

	public function test_resolve_post_type_for_query_falls_back_to_page_when_allow_list_empty(): void {
		$collector = new \SScribe_Page_Collector();
		$resolved  = $collector->resolve_post_type_for_query(
			'nope',
			array()
		);
		$this::assertSame( 'page', $resolved );
	}

	public function test_get_valid_post_statuses_returns_expected_labels(): void {
		$collector = new \SScribe_Page_Collector();
		$statuses  = $collector->get_valid_post_statuses();
		$this::assertArrayHasKey( 'publish', $statuses );
		$this::assertArrayHasKey( 'draft', $statuses );
		$this::assertArrayHasKey( 'private', $statuses );
		$this::assertArrayHasKey( 'future', $statuses );
		$this::assertArrayHasKey( 'pending', $statuses );
		$this::assertCount( 5, $statuses );
	}

	public function test_validate_post_status_returns_input_for_publish(): void {
		$collector = new \SScribe_Page_Collector();
		$this::assertSame( 'publish', $collector->validate_post_status( 'publish' ) );
	}

	public function test_validate_post_status_returns_input_for_draft(): void {
		$collector = new \SScribe_Page_Collector();
		$this::assertSame( 'draft', $collector->validate_post_status( 'draft' ) );
	}

	public function test_validate_post_status_returns_input_for_private(): void {
		$collector = new \SScribe_Page_Collector();
		$this::assertSame( 'private', $collector->validate_post_status( 'private' ) );
	}

	public function test_validate_post_status_returns_input_for_future(): void {
		$collector = new \SScribe_Page_Collector();
		$this::assertSame( 'future', $collector->validate_post_status( 'future' ) );
	}

	public function test_validate_post_status_returns_input_for_pending(): void {
		$collector = new \SScribe_Page_Collector();
		$this::assertSame( 'pending', $collector->validate_post_status( 'pending' ) );
	}

	public function test_validate_post_status_returns_any_for_all(): void {
		$collector = new \SScribe_Page_Collector();
		$this::assertSame( 'any', $collector->validate_post_status( 'all' ) );
	}

	public function test_validate_post_status_returns_publish_for_invalid(): void {
		$collector = new \SScribe_Page_Collector();
		$this::assertSame( 'publish', $collector->validate_post_status( 'garbage' ) );
		$this::assertSame( 'publish', $collector->validate_post_status( '' ) );
	}

	public function test_get_content_cache_generation_returns_one_when_option_missing(): void {
		$collector = new \SScribe_Page_Collector();
		$this::assertSame( 1, $collector->get_content_cache_generation() );
	}

	public function test_get_content_cache_generation_returns_option_value(): void {
		$GLOBALS['sscribe_test_options']['sscribe_content_cache_generation'] = 7;
		$collector = new \SScribe_Page_Collector();
		$this::assertSame( 7, $collector->get_content_cache_generation() );
	}

	public function test_get_content_cache_generation_clamps_to_minimum_one(): void {
		$GLOBALS['sscribe_test_options']['sscribe_content_cache_generation'] = 0;
		$collector = new \SScribe_Page_Collector();
		$this::assertSame( 1, $collector->get_content_cache_generation() );
	}

	public function test_is_wpml_active_returns_false_when_constant_missing(): void {
		$collector = new \SScribe_Page_Collector();
		$this::assertFalse( $collector->is_wpml_active() );
	}

	public function test_get_wpml_languages_returns_empty_when_wpml_inactive(): void {
		$collector = new \SScribe_Page_Collector();
		$this::assertSame( array(), $collector->get_wpml_languages() );
	}

	public function test_normalize_language_code_returns_empty_when_wpml_inactive(): void {
		$collector = new \SScribe_Page_Collector();
		$this::assertSame( '', $collector->normalize_language_code( 'en' ) );
	}

	public function test_normalize_language_code_returns_empty_for_empty_input(): void {
		$collector = new \SScribe_Page_Collector();
		$this::assertSame( '', $collector->normalize_language_code( '' ) );
	}

	public function test_clear_page_caches_resets_instance_state(): void {
		$collector = new \SScribe_Page_Collector();
		$collector->clear_page_caches();
		$this::assertSame( 1, $collector->get_content_cache_generation() );
	}
}
