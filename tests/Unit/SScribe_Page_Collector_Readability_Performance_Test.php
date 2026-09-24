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
		$GLOBALS['sscribe_test_get_post_calls']  = 0;
		$GLOBALS['sscribe_test_get_posts_calls'] = 0;
		$GLOBALS['sscribe_test_current_user_can'] = true;
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['sscribe_test_get_post_calls'],
			$GLOBALS['sscribe_test_get_posts_calls'],
			$GLOBALS['sscribe_test_current_user_can']
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



	public function test_all_status_count_aggregates_bounded_status_counts_instead_of_scanning_every_id(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-sscribe-page-collector.php' );
		$start  = strpos( $source, 'public function get_page_count_only(' );
		$end    = strpos( $source, 'public function get_post_status_counts(', $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$method = substr( $source, $start, $end - $start );

		$this->assertStringContainsString(
			'$this->get_post_status_counts( $language, $post_type )',
			$method,
			'All-status totals must sum the bounded status-count paths instead of enumerating every readable ID.'
		);
		$this->assertStringNotContainsString(
			"get_page_ids_chunked( $language, 'any'",
			$method,
			'All-status totals must not perform an unbounded full-inventory ID scan.'
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
