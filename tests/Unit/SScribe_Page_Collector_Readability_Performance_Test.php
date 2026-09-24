<?php
/**
 * Readability filtering performance regression.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SScribe_Page_Collector;

final class SScribe_Page_Collector_Readability_Performance_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_get_post_calls']   = 0;
		$GLOBALS['sscribe_test_get_posts_calls']  = 0;
		$GLOBALS['sscribe_test_current_user_can'] = true;
		$GLOBALS['sscribe_test_wp_query_calls']   = 0;
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['sscribe_test_get_post_calls'],
			$GLOBALS['sscribe_test_get_posts_calls'],
			$GLOBALS['sscribe_test_current_user_can'],
			$GLOBALS['sscribe_test_current_user_can_callback'],
			$GLOBALS['sscribe_test_get_posts_post_status'],
			$GLOBALS['sscribe_test_get_posts_post_type'],
			$GLOBALS['sscribe_test_wp_query_chunks'],
			$GLOBALS['sscribe_test_wp_query_calls']
		);
		parent::tearDown();
	}


	public function test_all_status_normalization_remains_idempotent_and_uses_any_internally(): void {
		$collector = new SScribe_Page_Collector();
		$this->assertSame( 'any', $collector->validate_post_status( 'all' ) );
		$this->assertSame( 'any', $collector->validate_post_status( 'any' ) );

		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-sscribe-page-collector.php' );
		$this->assertStringContainsString( "if ( 'any' === \$post_status )", $source );
		$this->assertStringContainsString( "if ( 'any' !== \$post_status )", $source );
	}



	public function test_all_status_count_uses_fast_publish_plus_bounded_nonpublic_scan(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-sscribe-page-collector.php' );
		$start  = strpos( $source, 'private function count_readable_posts_across_statuses(' );
		$end    = strpos( $source, 'public function get_page_count_only(', $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$method = substr( $source, $start, $end - $start );

		$this->assertStringContainsString(
			'$this->get_page_count_only( $language, \'publish\', $post_type )',
			$method,
			'Published content must use the constant-time found_posts path instead of readability pagination.'
		);
		$this->assertStringContainsString(
			'$this->count_readable_nonpublic_posts( $language, $post_type )',
			$method,
			'Permission-sensitive statuses must be handled by one bounded non-public scan.'
		);
		$this->assertStringNotContainsString(
			'$this->get_page_ids_chunked( $language, \'any\', $post_type',
			$method,
			'All-status totals must never paginate the full published inventory.'
		);
	}

	public function test_sparse_permissions_do_not_underreport_when_candidate_scan_saturates(): void {
		$chunks  = array();
		$next_id = 1;
		for ( $chunk_index = 0; $chunk_index < 21; ++$chunk_index ) {
			$chunk = array();
			for ( $offset = 0; $offset < 500; ++$offset ) {
				$chunk[] = $next_id++;
			}
			$chunks[] = $chunk;
		}

		$GLOBALS['sscribe_test_wp_query_chunks'] = $chunks;
		$GLOBALS['sscribe_test_get_posts_post_status'] = 'draft';
		$GLOBALS['sscribe_test_current_user_can_callback'] = static function ( string $capability, int $post_id = 0 ): bool {
			return 'read_post' === $capability && $post_id > 10001;
		};

		$collector = new SScribe_Page_Collector();
		$method    = new ReflectionMethod( $collector, 'count_readable_nonpublic_posts' );
		$method->setAccessible( true );

		$this->assertSame(
			10001,
			$method->invoke( $collector, '', 'page' ),
			'Candidate-scan saturation must return the conservative 10,001 capped/unknown sentinel instead of underreporting later readable posts.'
		);
	}

	public function test_filter_readable_page_ids_bulk_hydrates_instead_of_get_post_per_id(): void {
		$collector = new SScribe_Page_Collector();
		$method = new ReflectionMethod( $collector, 'filter_readable_page_ids' );
		$method->setAccessible( true );

		$ids = range( 1, 500 );
		$result = $method->invoke( $collector, $ids );

		$this->assertSame( $ids, $result );
		$this->assertSame(
			0,
			(int) $GLOBALS['sscribe_test_get_post_calls'],
			'Filtering 500 cached/query IDs must not call get_post() once per ID.'
		);
		$this->assertLessThanOrEqual(
			2,
			(int) $GLOBALS['sscribe_test_get_posts_calls'],
			'Readable-post hydration should be bounded to one or a few bulk queries, not N queries.'
		);
	}
}
