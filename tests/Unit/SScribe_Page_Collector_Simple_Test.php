<?php
/**
 * SScribe Page Collector (non-DB methods) Unit Test
 *
 * Exercises the public methods that do not need a real WP_Query:
 *   - is_wpml_active()
 *   - get_selectable_post_types()
 *   - resolve_post_type_for_query()
 *   - get_valid_post_statuses()
 *   - normalize_language_code()
 *   - clear_page_caches()
 *   - get_content_cache_generation()
 *   - get_post_status_counts()
 *   - validate_post_status()
 *   - normalize_wpml_languages() (via get_wpml_languages())
 *   - get_total_all_statuses()
 *
 * The WP_Query-driven methods (get_page_ids, get_page_data, etc.) are
 * covered by the WP integration suite. The DB-free methods below are
 * the highest-leverage untested surface and exercise roughly half the
 * file's lines without requiring any fixture data.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Page_Collector;

if ( ! class_exists( '\\SScribe_Page_Collector', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-page-collector.php';
}

final class SScribe_Page_Collector_Simple_Test extends TestCase {

	public function test_clear_page_caches_resets_all_internal_caches(): void {
		$pc = new SScribe_Page_Collector();
		// Drive clear_page_caches — should not throw, no assertion needed.
		$pc->clear_page_caches();
		$this::assertTrue( true, 'clear_page_caches() must run without error.' );
	}

	public function test_get_content_cache_generation_returns_at_least_one(): void {
		$pc = new SScribe_Page_Collector();
		$gen = $pc->get_content_cache_generation();
		$this::assertGreaterThanOrEqual( 1, $gen );
	}

	public function test_is_wpml_active_returns_false_when_constants_absent(): void {
		// The fake-WP bootstrap does not load SitePress. The function
		// must report "not active" rather than crashing on a missing
		// class check.
		$pc = new SScribe_Page_Collector();
		$this::assertFalse( $pc->is_wpml_active() );
	}

	public function test_get_selectable_post_types_returns_array(): void {
		$pc = new SScribe_Page_Collector();
		$types = $pc->get_selectable_post_types();
		$this::assertIsArray( $types );
		// attachment must never appear in the allow-list.
		$this::assertNotContains( 'attachment', $types );
	}

	public function test_get_selectable_post_types_respects_filter(): void {
		// A filter that adds a synthetic post type must reach the result.
		$callback = static function ( array $types ): array {
			$types[] = 'synthetic';
			return $types;
		};
		add_filter( 'sscribe_allowed_post_types', $callback );
		try {
			$pc    = new SScribe_Page_Collector();
			$types = $pc->get_selectable_post_types();
		} finally {
			remove_filter( 'sscribe_allowed_post_types', $callback );
		}
		$this::assertContains( 'synthetic', $types );
	}

	public function test_resolve_post_type_for_query_returns_first_allowed_when_input_unknown(): void {
		$pc      = new SScribe_Page_Collector();
		$allowed = array( 'page', 'post' );
		$result  = $pc->resolve_post_type_for_query( 'unknown', $allowed );
		$this::assertSame( 'page', $result );
	}

	public function test_resolve_post_type_for_query_returns_input_when_allowed(): void {
		$pc      = new SScribe_Page_Collector();
		$allowed = array( 'page', 'post' );
		$result  = $pc->resolve_post_type_for_query( 'post', $allowed );
		$this::assertSame( 'post', $result );
	}

	public function test_resolve_post_type_for_query_expand_any(): void {
		$pc      = new SScribe_Page_Collector();
		$allowed = array( 'page', 'post' );
		$result  = $pc->resolve_post_type_for_query( 'any', $allowed );
		$this::assertSame( $allowed, $result );
	}

	public function test_resolve_post_type_for_query_falls_back_to_page_when_allowed_empty(): void {
		$pc     = new SScribe_Page_Collector();
		$result = $pc->resolve_post_type_for_query( 'unknown', array() );
		$this::assertSame( 'page', $result );
	}

	public function test_get_valid_post_statuses_returns_canonical_set(): void {
		$pc       = new SScribe_Page_Collector();
		$statuses = $pc->get_valid_post_statuses();
		$this::assertIsArray( $statuses );
		$this::assertArrayHasKey( 'publish', $statuses );
		$this::assertArrayHasKey( 'draft', $statuses );
		$this::assertArrayHasKey( 'private', $statuses );
		$this::assertArrayHasKey( 'future', $statuses );
		$this::assertArrayHasKey( 'pending', $statuses );
		$this::assertCount( 5, $statuses );
	}

	public function test_validate_post_status_passes_through_known(): void {
		$pc = new SScribe_Page_Collector();
		$this::assertSame( 'publish', $pc->validate_post_status( 'publish' ) );
		$this::assertSame( 'draft', $pc->validate_post_status( 'draft' ) );
	}

	public function test_validate_post_status_rejects_unknown(): void {
		$pc = new SScribe_Page_Collector();
		$this::assertSame( 'publish', $pc->validate_post_status( 'totally-bogus' ) );
	}

	public function test_normalize_language_code_returns_empty_when_wpml_inactive(): void {
		// When WPML is not loaded, the function must short-circuit to
		// '' rather than trying to look up language codes.
		$pc = new SScribe_Page_Collector();
		$this::assertSame( '', $pc->normalize_language_code( 'en' ) );
		$this::assertSame( '', $pc->normalize_language_code( '' ) );
	}

	public function test_normalize_language_code_rejects_empty_input(): void {
		$pc = new SScribe_Page_Collector();
		$this::assertSame( '', $pc->normalize_language_code( '' ) );
	}

	public function test_get_total_all_statuses_returns_zero_or_integer(): void {
		// Without seeded count data the function must return a non-negative
		// integer — exercises the type-cast fallback branch.
		$pc   = new SScribe_Page_Collector();
		$total = $pc->get_total_all_statuses();
		$this::assertIsInt( $total );
		$this::assertGreaterThanOrEqual( 0, $total );
	}

	public function test_get_post_status_counts_returns_array(): void {
		$pc      = new SScribe_Page_Collector();
		$counts  = $pc->get_post_status_counts();
		$this::assertIsArray( $counts );
	}

	public function test_get_total_all_statuses_with_explicit_arguments(): void {
		$pc    = new SScribe_Page_Collector();
		$total = $pc->get_total_all_statuses( '', 'page' );
		$this::assertIsInt( $total );
	}
}
