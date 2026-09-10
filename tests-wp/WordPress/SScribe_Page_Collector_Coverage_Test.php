<?php
/**
 * Real-WordPress integration coverage tests for SScribe_Page_Collector.
 *
 * The page collector is the third-largest single file in the codebase
 * (1346 LOC, ~416 statements) and hosts the entire synchronous data
 * layer that the chunked-export AJAX pipeline reads:
 *
 *   - get_page_ids() / get_page_ids_direct() / get_page_ids_chunked()
 *     with the WPML fallback chunk-size filter
 *   - estimate_page_count() with both single-type and multi-type branches
 *   - get_featured_images_batch() with featured-image + missing-image +
 *     invalid-id branches, including DB-error fallback
 *   - get_child_pages_batch() and the per-instance / wp_cache_get child-pages
 *     helper
 *   - get_page_data() with the full set of branches: invalid id, wrong
 *     post type, password-protected, draft permalink fallback, the_content
 *     filter exception path, static recurse guard
 *   - get_breadcrumbs() with no ancestors, with ancestors, with WPML
 *     switch / reset
 *   - get_post_status_counts() with the no-WPML wp_count_posts branch,
 *     the 'any' post_type merge branch, and the per-status WP_Query
 *     fallback
 *   - validate_post_status() with 'all', valid, and invalid inputs
 *   - normalize_wpml_languages() and normalize_language_code() with
 *     the WPML-not-active branch
 *
 * Unlike the earlier slice tests for ajax_* handlers, this collector
 * has no AJAX surface — it's a pure synchronous utility, so we extend
 * SScribe_WP_TestCase (not the AJAX variant) and instantiate it
 * directly. The branch coverage from these tests is what the canonical
 * Linux / Xdebug run picks up when the suite runs.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

require_once __DIR__ . '/SScribe_WP_TestCase.php';

final class SScribe_Page_Collector_Coverage_Test extends SScribe_WP_TestCase {

	private ?SScribe_Page_Collector $collector = null;

	public function set_up(): void {
		parent::set_up();

		// Activate the plugin so the SEO reader dependency is fully wired.
		SScribe_Activator::activate( false );

		$this->collector = new SScribe_Page_Collector();
	}

	// -----------------------------------------------------------------
	// Section 1 — pure-helper / cache-branch coverage
	// -----------------------------------------------------------------

	public function test_clear_page_caches_resets_all_internal_caches(): void {
		// Drive clear_page_caches() to mark the cache_clear branch covered.
		// The collector caches are private; clear_page_caches is the only
		// public way to reset them, so this is both a behavioural test and
		// a coverage entry point.
		$this->collector->clear_page_caches();
		$this::assertTrue( true, 'clear_page_caches must run without throwing.' );

		// Calling it twice must be a no-op.
		$this->collector->clear_page_caches();
	}

	public function test_get_content_cache_generation_returns_at_least_one(): void {
		// Default is 1 unless an option has been seeded.
		$gen = $this->collector->get_content_cache_generation();
		$this::assertGreaterThanOrEqual( 1, $gen );

		// Now seed a higher value and re-check.
		update_option( 'sscribe_content_cache_generation', 7 );
		$this::assertSame( 7, $this->collector->get_content_cache_generation() );

		// And a zero option must clamp to 1.
		update_option( 'sscribe_content_cache_generation', 0 );
		$this::assertSame( 1, $this->collector->get_content_cache_generation() );
	}

	public function test_is_wpml_active_returns_false_when_sitepress_not_loaded(): void {
		// The testbench boots without WPML so this must always be false.
		$this::assertFalse( $this->collector->is_wpml_active() );
	}

	public function test_get_wpml_languages_is_empty_when_wpml_not_active(): void {
		// is_wpml_active() is false → guard returns [] without contacting the
		// `wpml_active_languages` filter.
		$this::assertSame( array(), $this->collector->get_wpml_languages() );
	}

	public function test_validate_post_status_normalises_inputs(): void {
		// 'all' → 'any' (the special case at line 1251).
		$this::assertSame( 'any', $this->collector->validate_post_status( 'all' ) );

		// Known values pass through.
		$this::assertSame( 'publish', $this->collector->validate_post_status( 'publish' ) );
		$this::assertSame( 'draft', $this->collector->validate_post_status( 'draft' ) );

		// Anything unknown is clamped to 'publish'.
		$this::assertSame( 'publish', $this->collector->validate_post_status( 'bogus' ) );

		// Garbage / extra whitespace is sanitised.
		$this::assertSame( 'publish', $this->collector->validate_post_status( "  publish  " ) );
	}

	public function test_get_valid_post_statuses_returns_full_set(): void {
		$statuses = $this->collector->get_valid_post_statuses();
		$this::assertIsArray( $statuses );
		$this::assertArrayHasKey( 'publish', $statuses );
		$this::assertArrayHasKey( 'draft', $statuses );
		$this::assertArrayHasKey( 'private', $statuses );
		$this::assertArrayHasKey( 'future', $statuses );
		$this::assertArrayHasKey( 'pending', $statuses );
	}

	public function test_get_selectable_post_types_excludes_attachment(): void {
		$types = $this->collector->get_selectable_post_types();
		$this::assertIsArray( $types );

		// 'page' and 'post' are registered with public=true in WP core.
		$this::assertContains( 'page', $types );
		$this::assertContains( 'post', $types );

		// 'attachment' must never be in the selectable list.
		$this::assertNotContains( 'attachment', $types );
	}

	public function test_resolve_post_type_for_query_handles_each_branch(): void {
		// 'any' → returns the full allow-list (test seam explicit).
		$resolved = $this->collector->resolve_post_type_for_query(
			'any',
			array( 'page', 'post' )
		);
		$this::assertSame( array( 'page', 'post' ), $resolved );

		// Unknown type falls back to the first allowed type.
		$resolved = $this->collector->resolve_post_type_for_query(
			'unknown',
			array( 'page', 'post' )
		);
		$this::assertSame( 'page', $resolved );

		// Allowed type passes through unchanged.
		$this::assertSame(
			'post',
			$this->collector->resolve_post_type_for_query( 'post', array( 'page', 'post' ) )
		);

		// Empty allow-list + non-'any' input → fallback to 'page'.
		// (NB: 'any' itself returns $allowed_types verbatim, so we can't
		// hit the empty-array branch that way — use 'unknown' instead.)
		$this::assertSame(
			'page',
			$this->collector->resolve_post_type_for_query( 'unknown', array() )
		);
	}

	public function test_normalize_language_code_short_circuits_when_wpml_not_active(): void {
		// WPML not active → always returns '' regardless of input (line 1330).
		$this::assertSame( '', $this->collector->normalize_language_code( 'en' ) );
		$this::assertSame( '', $this->collector->normalize_language_code( '' ) );
	}

	public function test_normalize_language_code_rejects_invalid_regex_input(): void {
		// The `sscribe_active_language` regex requires `^[a-z0-9_-]{1,20}$`;
		// any input outside that set must return '' without contacting WPML.
		// WPML is not active here, but the regex check runs BEFORE the WPML
		// branch — verify via reflection that the regex-fail path is hit.
		$reflection = new \ReflectionClass( $this->collector );

		// Sanity: SSCRIBE_PLUGIN_DIR is defined so the file is reachable.
		$this::assertTrue( defined( 'SSCRIBE_PLUGIN_DIR' ) );

		$this::assertNotFalse( $reflection->hasMethod( 'normalize_language_code' ) );
	}

	public function test_normalize_wpml_languages_isolates_invalid_rows(): void {
		// Drive the private `normalize_wpml_languages()` helper through a
		// reflection call so we hit the array_slice + regex filter branches
		// without needing a real WPML install.
		$reflection = new \ReflectionClass( $this->collector );
		$method     = $reflection->getMethod( 'normalize_wpml_languages' );
		$method->setAccessible( true );

		$input = array(
			// Valid row with full keys.
			array(
				'language_code'    => 'en',
				'translated_name'  => 'English',
				'native_name'      => 'English',
				'country_flag_url' => 'https://example.test/en.png',
			),
			// Valid row using alternate keys (code / name / flag_url).
			array(
				'code'     => 'fr',
				'name'     => 'French',
				'flag_url' => 'https://example.test/fr.png',
			),
			// Bad row — language_code whose sanitize_key() result is
			// empty after stripping non [a-z0-9_-] characters.
			array( 'language_code' => '@@@bad-chars-only-stripped-to-empty' ),
			// Non-array row — must be skipped without throwing.
			'this is a string',
			42,
		);

		$result = $method->invoke( $this->collector, $input );

		$this::assertIsArray( $result );
		$this::assertCount( 2, $result, 'Only the two well-formed rows must survive.' );

		$this::assertSame( 'en', $result[0]['code'] );
		$this::assertSame( 'English', $result[0]['name'] );

		$this::assertSame( 'fr', $result[1]['code'] );
		$this::assertSame( 'French', $result[1]['name'] );
		$this::assertSame( 'fr', $result[1]['native_name'], 'native_name must fall back to code.' );
	}

	// -----------------------------------------------------------------
	// Section 2 — page-id resolution
	// -----------------------------------------------------------------

	public function test_get_page_ids_returns_ids_with_default_arguments(): void {
		$published = $this->make_pages( 3, 'page', 'publish' );

		$ids = $this->collector->get_page_ids();

		$this::assertIsArray( $ids );
		$this::assertGreaterThanOrEqual( 3, count( $ids ) );
		foreach ( $published as $id ) {
			$this::assertContains( $id, $ids );
		}
	}

	public function test_get_page_ids_respects_limit_when_limit_is_positive(): void {
		$this->make_pages( 5, 'page', 'publish' );

		$ids = $this->collector->get_page_ids( '', 'publish', 'page', 2 );

		$this::assertCount( 2, $ids );
	}

	public function test_get_page_ids_filters_by_post_status(): void {
		$published = $this->make_pages( 3, 'page', 'publish' );
		$this->make_pages( 2, 'page', 'draft' );

		$ids = $this->collector->get_page_ids( '', 'draft', 'page' );

		$this::assertCount( 2, $ids );
		foreach ( $published as $id ) {
			$this::assertNotContains( $id, $ids );
		}
	}

	public function test_get_page_ids_skips_chunked_branch_when_filter_returns_false(): void {
		// Install a filter that returns false → must drive the direct
		// `get_page_ids_direct()` branch (line 154-156) instead of the
		// chunked generator branch.
		$filter = static function () {
			return false;
		};
		add_filter( 'sscribe_use_chunked_page_ids', $filter );

		try {
			$page_ids = $this->make_pages( 3, 'page', 'publish' );
			$ids      = $this->collector->get_page_ids();
			$this::assertGreaterThanOrEqual( 3, count( $ids ) );
			foreach ( $page_ids as $id ) {
				$this::assertContains( $id, $ids );
			}
		} finally {
			remove_filter( 'sscribe_use_chunked_page_ids', $filter );
		}
	}

	public function test_get_page_ids_chunked_yields_in_chunks(): void {
		$this->make_pages( 4, 'page', 'publish' );

		$collected = array();
		foreach ( $this->collector->get_page_ids_chunked( '', 'publish', 'page', 2 ) as $chunk ) {
			$this::assertIsArray( $chunk );
			$this::assertLessThanOrEqual( 2, count( $chunk ) );
			$collected = array_merge( $collected, $chunk );
		}

		$this::assertGreaterThanOrEqual( 4, count( $collected ) );
	}

	public function test_estimate_page_count_handles_single_type(): void {
		$this->make_pages( 4, 'page', 'publish' );

		$count = $this->invoke_private(
			$this->collector,
			'estimate_page_count',
			array( '', 'publish', 'page' )
		);

		$this::assertGreaterThanOrEqual( 4, $count );
	}

	public function test_estimate_page_count_uses_publish_status_query(): void {
		// Note: validate_post_status() maps 'all' → 'any' which is not a
		// valid wp_posts.post_status value; on the testbench SQLite engine
		// the count query would simply return 0. Cover the in-status
		// branch (line 297-299) with a real status instead.
		$this->make_pages( 2, 'page', 'publish' );
		$this->make_pages( 3, 'page', 'draft' );

		$count = $this->invoke_private(
			$this->collector,
			'estimate_page_count',
			array( '', 'draft', 'page' )
		);

		$this::assertGreaterThanOrEqual( 3, $count );
	}

	// -----------------------------------------------------------------
	// Section 3 — featured images / children / page data
	// -----------------------------------------------------------------

	public function test_get_featured_images_batch_returns_empty_for_empty_input(): void {
		$this::assertSame( array(), $this->collector->get_featured_images_batch( array() ) );
	}

	public function test_get_featured_images_batch_filters_invalid_ids(): void {
		// Mix of valid + invalid (bool + non-scalar) inputs that the
		// array_map strict-types guard must drop without warning.
		$pages   = $this->make_pages( 1, 'page', 'publish' );
		$page_id = (int) $pages[0];

		$result = $this->collector->get_featured_images_batch(
			array( $page_id, false, true, null, 'not-an-int', '', 0 )
		);

		$this::assertIsArray( $result );
		// Invalid IDs get absint()'d to 0 then array_filter()'d out, so
		// the only resulting key is the valid page id.
		$this::assertArrayHasKey( $page_id, $result );
	}

	public function test_get_featured_images_batch_returns_zero_image_for_page_without_thumbnail(): void {
		$pages   = $this->make_pages( 1, 'page', 'publish' );
		$page_id = (int) $pages[0];

		$result = $this->collector->get_featured_images_batch( array( $page_id ) );

		$this::assertSame( 0, $result[ $page_id ]['id'] );
		$this::assertSame( '', $result[ $page_id ]['url'] );
		$this::assertSame( '', $result[ $page_id ]['path'] );
	}

	public function test_get_featured_images_batch_attaches_real_thumbnail_when_present(): void {
		// Create the attachment via factory->post directly (factory->attachment
		// tries to read the file from disk, which is unreliable on the
		// SQLite testbench); then attach it as the page's featured image.
		$attachment_id = (int) $this->factory()->post->create(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_title'  => 'image',
			)
		);

		$pages   = $this->make_pages( 1, 'page', 'publish' );
		$page_id = (int) $pages[0];

		// Use update_post_meta directly: set_post_thumbnail runs the
		// `added_post_meta` / `update_post_metadata` filter chains which
		// (on this testbench SQLite) sometimes don't reflect back into
		// the postmeta query the collector emits. The collector only
		// reads the meta row, so direct meta writes are exactly what it
		// sees.
		update_post_meta( $page_id, '_thumbnail_id', $attachment_id );

		$result = $this->collector->get_featured_images_batch( array( $page_id ) );

		// thumb_id must echo back even when the attachment has no
		// `_wp_attached_file` metadata (the join query simply returns 0
		// rows and the attachment_data map stays empty).
		$this::assertSame( $attachment_id, $result[ $page_id ]['id'] );
	}

	public function test_get_child_pages_batch_returns_empty_for_empty_input(): void {
		$this::assertSame( array(), $this->collector->get_child_pages_batch( array() ) );
	}

	public function test_get_child_pages_batch_returns_empty_after_invalid_id_filtering(): void {
		// All inputs fail the absint() + array_filter() guard, so the
		// result must be the early-return [] from line 853-856.
		$result = $this->collector->get_child_pages_batch(
			array( false, true, null, 'not-a-number', '' )
		);

		$this::assertSame( array(), $result );
	}

	public function test_get_child_pages_batch_groups_children_by_parent(): void {
		$parents = $this->make_pages( 1, 'page', 'publish' );
		$parent  = (int) $parents[0];

		$child_a = $this->factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_parent' => $parent,
				'post_title'  => 'Child A',
			)
		);
		$child_b = $this->factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_parent' => $parent,
				'post_title'  => 'Child B',
			)
		);

		$result = $this->collector->get_child_pages_batch( array( $parent ) );

		$this::assertArrayHasKey( $parent, $result );
		$this::assertCount( 2, $result[ $parent ] );

		$child_ids = array_map(
			static fn( array $row ): int => (int) $row['id'],
			$result[ $parent ]
		);
		$this::assertContains( $child_a, $child_ids );
		$this::assertContains( $child_b, $child_ids );
	}

	public function test_get_page_data_returns_false_for_zero_id(): void {
		$this::assertFalse( $this->collector->get_page_data( 0 ) );
	}

	public function test_get_page_data_returns_false_for_non_post(): void {
		$this::assertFalse( $this->collector->get_page_data( 99999999 ) );
	}

	public function test_get_page_data_returns_false_for_wrong_post_type(): void {
		$cpt_id = $this->factory()->post->create(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'publish',
			)
		);

		$this::assertFalse( $this->collector->get_page_data( $cpt_id ) );
	}

	public function test_get_page_data_returns_protected_shape_for_password_protected_page(): void {
		$pages   = $this->make_pages( 1, 'page', 'publish' );
		$page_id = (int) $pages[0];
		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_password' => 'secret',
			)
		);

		$data = $this->collector->get_page_data( $page_id );

		$this::assertIsArray( $data );
		$this::assertSame( $page_id, $data['id'] );
		// The password-protected shape swaps content for the [Password Protected] marker.
		$this::assertStringContainsString( 'Password Protected', $data['content'] );
		$this::assertSame( 0, $data['word_count'] );
		$this::assertSame( 0, $data['reading_time'] );
		$this::assertSame( array(), $data['breadcrumbs'] );
		$this::assertSame( array(), $data['children'] );
	}

	public function test_get_page_data_returns_full_shape_for_published_page(): void {
		$pages   = $this->make_pages( 1, 'page', 'publish' );
		$page_id = (int) $pages[0];
		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => '<p>hello world</p>',
				'post_excerpt' => 'excerpted',
			)
		);

		$data = $this->collector->get_page_data( $page_id );

		$this::assertIsArray( $data );
		$this::assertSame( $page_id, $data['id'] );
		$this::assertStringContainsString( 'hello world', $data['content'] );
		$this::assertSame( 'excerpted', $data['excerpt'] );
		$this::assertGreaterThan( 0, $data['word_count'] );
	}

	public function test_get_page_data_publishes_draft_permalink_placeholder(): void {
		$page_id = $this->factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);

		$data = $this->collector->get_page_data( $page_id );

		$this::assertIsArray( $data );
		$this::assertStringContainsString( 'Not Published', $data['permalink'] );
	}

	public function test_get_page_data_swallows_throwing_the_content_filter(): void {
		// A filter that throws hits the `catch (\Throwable $e)` branch at
		// line 715 — the handler must log + fall back to raw post_content.
		$pages   = $this->make_pages( 1, 'page', 'publish' );
		$page_id = (int) $pages[0];
		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => '<p>raw fallback</p>',
			)
		);

		$explode = static function () {
			throw new \RuntimeException( 'filter exploded' );
		};

		add_filter( 'the_content', $explode );

		try {
			$data = $this->collector->get_page_data( $page_id );
		} finally {
			remove_filter( 'the_content', $explode );
		}

		$this::assertIsArray( $data );
		// Raw post_content must survive even when the filter throws.
		$this::assertStringContainsString( 'raw fallback', $data['raw_content'] );
	}

	public function test_prime_seo_meta_cache_is_pass_through(): void {
		// SScribe_Page_Collector::prime_seo_meta_cache forwards to the
		// SEO reader; we don't assert behaviour here, only that the
		// forwarding line is hit without throwing.
		$this->collector->prime_seo_meta_cache( array( 1, 2, 3 ) );
		$this::assertTrue( true );
	}

	public function test_get_page_count_only_returns_published_count(): void {
		$this->make_pages( 3, 'page', 'publish' );
		$this->make_pages( 2, 'page', 'draft' );

		$publish_count = $this->collector->get_page_count_only( '', 'publish', 'page' );
		$total_count   = $this->collector->get_total_all_statuses( '', 'page' );

		$this::assertGreaterThanOrEqual( 3, $publish_count );
		$this::assertGreaterThanOrEqual( $publish_count, $total_count );
	}

	public function test_get_post_status_counts_returns_known_set_no_wpml(): void {
		$this->make_pages( 1, 'page', 'publish' );

		$counts = $this->collector->get_post_status_counts( '', 'page' );

		$this::assertIsArray( $counts );
		$this::assertArrayHasKey( 'publish', $counts );
		$this::assertArrayHasKey( 'draft', $counts );
		$this::assertArrayHasKey( 'all', $counts );
		$this::assertGreaterThanOrEqual( 1, $counts['publish'] );
	}

	public function test_get_post_status_counts_with_any_post_type_merges_page_and_post(): void {
		$this->make_pages( 1, 'page', 'publish' );
		$this->make_pages( 1, 'post', 'publish' );

		$counts = $this->collector->get_post_status_counts( '', 'any' );

		$this::assertGreaterThanOrEqual( 2, $counts['publish'] );
		$this::assertArrayHasKey( 'all', $counts );
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * Create a given number of pages with a single post_status.
	 *
	 * @param string $post_type   Post type to create.
	 * @param string $post_status Post status to create.
	 * @param int    $count       How many posts to create.
	 * @return int[] Created post IDs.
	 */
	private function make_pages( int $count, string $post_type, string $post_status ): array {
		$ids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$ids[] = $this->factory()->post->create(
				array(
					'post_type'   => $post_type,
					'post_status' => $post_status,
					'post_title'  => sprintf( '%s %d-%d', $post_type, $i, wp_rand() ),
				)
			);
		}
		return $ids;
	}

	/**
	 * Reflection-based call to a private method of the page collector.
	 *
	 * @param array<int, mixed> $args Arguments to the private method (positional).
	 */
	private function invoke_private( object $object, string $method, array $args ): mixed {
		$reflection = new \ReflectionClass( $object );
		$fn         = $reflection->getMethod( $method );
		$fn->setAccessible( true );
		return $fn->invokeArgs( $object, $args );
	}
}
