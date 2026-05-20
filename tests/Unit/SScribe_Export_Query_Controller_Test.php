<?php
/**
 * SScribe Export Query Controller Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Export_Query_Controller_Test extends TestCase {

	public function test_can_instantiate_with_no_args(): void {
		$controller = new \SScribe_Export_Query_Controller();
		$this->assertInstanceOf( \SScribe_Export_Query_Controller::class, $controller );
	}

	public function test_can_instantiate_with_mock_deps(): void {
		$controller = new \SScribe_Export_Query_Controller(
			$this->createMock( \SScribe_Export_Rate_Limiter::class ),
			$this->createMock( \SScribe_Diagnostics::class ),
			$this->createMock( \SScribe_Page_Collector::class ),
			$this->createMock( \SScribe_Logger_Interface::class ),
			$this->createMock( \SScribe_Zip_Handler::class ),
			$this->createMock( \SScribe_Adaptive_Metrics::class ),
			$this->createMock( \SScribe_Export_Error_Handler::class )
		);
		$this->assertInstanceOf( \SScribe_Export_Query_Controller::class, $controller );
	}

	public function test_can_instantiate_with_partial_deps(): void {
		$controller = new \SScribe_Export_Query_Controller(
			$this->createMock( \SScribe_Export_Rate_Limiter::class ),
			null,
			$this->createMock( \SScribe_Page_Collector::class )
		);
		$this->assertInstanceOf( \SScribe_Export_Query_Controller::class, $controller );
	}
}
