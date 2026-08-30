<?php
/**
 * SScribe Batch File Handler Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Batch_File_Handler_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_transients']      = array();
		$GLOBALS['sscribe_test_current_user_can'] = true;
		$GLOBALS['sscribe_test_current_user']     = new \WP_User();
		$GLOBALS['sscribe_test_current_user']->ID = 1;
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_transients']      = array();
		$GLOBALS['sscribe_test_current_user_can'] = null;
		$GLOBALS['sscribe_test_current_user']     = null;
		parent::tearDown();
	}

	public function test_can_instantiate_with_no_args(): void {
		$handler = new \SScribe_Batch_File_Handler();
		$this->assertInstanceOf( \SScribe_Batch_File_Handler::class, $handler );
	}

	public function test_can_instantiate_with_dependencies(): void {
		$handler = new \SScribe_Batch_File_Handler(
			new \SScribe_Export_Rate_Limiter(),
			new \SScribe_Zip_Handler(),
			\SScribe_Logger::instance(),
			new \SScribe_Export_Auditor()
		);
		$this->assertInstanceOf( \SScribe_Batch_File_Handler::class, $handler );
	}
}
