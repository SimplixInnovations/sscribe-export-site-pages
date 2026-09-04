<?php
/**
 * Language request boundary regressions.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Language_Request_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$_POST    = array();
		$_REQUEST = array();
	}

	protected function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
		parent::tearDown();
	}

	private function assert_normalizer_available(): void {
		$this->assertTrue(
			class_exists( '\\SScribe_Language_Request' ),
			'SScribe_Language_Request must provide the single request-boundary contract for the __all__ sentinel.'
		);
	}

	public function test_preview_all_languages_is_translated_to_internal_empty_language(): void {
		$this->assert_normalizer_available();
		$_POST = array(
			'action'   => 'sscribe_get_export_preview',
			'language' => '__all__',
		);
		$_REQUEST = $_POST;

		\SScribe_Language_Request::normalize_current_request();

		$this->assertSame( '', $_POST['language'] );
		$this->assertSame( '', $_REQUEST['language'] );
	}

	public function test_start_export_all_languages_is_translated_to_internal_empty_language(): void {
		$this->assert_normalizer_available();
		$_POST = array(
			'action'   => 'sscribe_start_export',
			'language' => '__all__',
		);
		$_REQUEST = $_POST;

		\SScribe_Language_Request::normalize_current_request();

		$this->assertSame( '', $_POST['language'] );
		$this->assertSame( '', $_REQUEST['language'] );
	}

	public function test_count_endpoint_preserves_transport_sentinel(): void {
		$this->assert_normalizer_available();
		$_POST = array(
			'action'   => 'sscribe_get_status_counts',
			'language' => '__all__',
		);
		$_REQUEST = $_POST;

		\SScribe_Language_Request::normalize_current_request();

		$this->assertSame( '__all__', $_POST['language'] );
		$this->assertSame( '__all__', $_REQUEST['language'] );
	}

	public function test_real_language_code_is_never_rewritten(): void {
		$this->assert_normalizer_available();
		$_POST = array(
			'action'   => 'sscribe_start_export',
			'language' => 'en',
		);
		$_REQUEST = $_POST;

		\SScribe_Language_Request::normalize_current_request();

		$this->assertSame( 'en', $_POST['language'] );
		$this->assertSame( 'en', $_REQUEST['language'] );
	}

	public function test_non_scalar_language_is_not_coerced_by_boundary_normalizer(): void {
		$this->assert_normalizer_available();
		$_POST = array(
			'action'   => 'sscribe_get_export_preview',
			'language' => array( '__all__' ),
		);
		$_REQUEST = $_POST;

		\SScribe_Language_Request::normalize_current_request();

		$this->assertSame( array( '__all__' ), $_POST['language'] );
	}
}
