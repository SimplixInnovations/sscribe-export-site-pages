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
