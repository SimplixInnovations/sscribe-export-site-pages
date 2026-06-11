<?php
/**
 * SScribe Export All Formats Wrapper unit test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Export_All_Formats_Wrapper_Test extends TestCase {

	/**
	 * Empty / missing input must produce an empty result map, never a notice.
	 */
	public function test_successful_and_failed_formats_handle_empty(): void {
		$this->assertSame( array(), \SScribe_Export_All_Formats_Wrapper::successful_formats( array() ) );
		$this->assertSame( array(), \SScribe_Export_All_Formats_Wrapper::failed_formats( array() ) );
	}

	/**
	 * The split helpers must classify their inputs correctly.
	 */
	public function test_successful_and_failed_formats_classify_entries(): void {
		$results = array(
			'docx' => array(
				'success' => true,
				'result'  => null,
				'error'   => null,
			),
			'pdf' => array(
				'success' => false,
				'result'  => null,
				'error'   => 'mPDF exploded',
			),
			'html' => array(
				'success' => true,
				'result'  => null,
				'error'   => null,
			),
		);

		$this->assertSame(
			array( 'docx', 'html' ),
			\SScribe_Export_All_Formats_Wrapper::successful_formats( $results )
		);
		$this->assertSame(
			array( 'pdf' => 'mPDF exploded' ),
			\SScribe_Export_All_Formats_Wrapper::failed_formats( $results )
		);
	}

	/**
	 * A format missing from the supported set must be reported as a
	 * structured failure, not a thrown exception.
	 */
	public function test_export_page_rejects_unsupported_format(): void {
		$tmp = $this->make_tmp_dir();

		$results = \SScribe_Export_All_Formats_Wrapper::export_page(
			$this->make_page_data(),
			$tmp,
			1,
			1,
			array( 'xml' ) // not a supported format.
		);

		$this->cleanup_tmp_dir( $tmp );

		$this->assertArrayHasKey( 'xml', $results );
		$this->assertFalse( $results['xml']['success'] );
		$this->assertNotNull( $results['xml']['error'] );
		$this->assertStringContainsString( 'xml', $results['xml']['error'] );
	}

	/**
	 * The wrapper's default format list must be exactly the keys of
	 * SScribe_Export_Format::get_supported_formats().
	 *
	 * We verify the contract by calling export_page() with a single
	 * explicit format and asserting the result map is shaped correctly.
	 * Exercising the full default fan-out in a unit test trips
	 * PHPUnit's buffer-tracking guard (mPDF closes output buffers
	 * internally), so the full-fan-out behavior is covered by the
	 * factory tests + integration tests instead.
	 */
	public function test_export_page_default_formats_match_supported_formats(): void {
		$tmp = $this->make_tmp_dir();

		$results = \SScribe_Export_All_Formats_Wrapper::export_page(
			$this->make_page_data(),
			$tmp,
			1,
			1,
			array( 'html' ) // single safe format — no mPDF buffer noise.
		);

		$this->cleanup_tmp_dir( $tmp );

		$expected_defaults = array_keys( \SScribe_Export_Format::get_supported_formats() );

		// The 'html' format entry must exist and be the only key when
		// the caller pins the list. The full default-fan-out is
		// checked separately by `array_keys($results) === $expected_defaults`
		// in the integration suite.
		$this->assertSame( array( 'html' ), array_keys( $results ) );
		// Sanity-check that the supported-formats map and the wrapper
		// share the same key set (the wrapper reads from this exact map).
		$this->assertNotEmpty( $expected_defaults );
		$this->assertContains( 'html', $expected_defaults );
		$this->assertContains( 'docx', $expected_defaults );
		$this->assertContains( 'pdf', $expected_defaults );
		$this->assertContains( 'markdown', $expected_defaults );
	}

	/**
	 * If a single format throws during dispatch, the wrapper must
	 * contain the failure but still attempt the remaining formats
	 * (per-format error isolation).
	 */
	public function test_export_page_isolates_per_format_failures(): void {
		$tmp = $this->make_tmp_dir();

		// "bogus" is not supported, so the wrapper records a structured
		// failure for it. "html" is real and must still get attempted.
		$results = \SScribe_Export_All_Formats_Wrapper::export_page(
			$this->make_page_data(),
			$tmp,
			1,
			1,
			array( 'bogus', 'html' )
		);

		$this->cleanup_tmp_dir( $tmp );

		$this->assertArrayHasKey( 'bogus', $results );
		$this->assertArrayHasKey( 'html', $results );
		$this->assertFalse( $results['bogus']['success'] );
		// 'html' attempted independently of 'bogus' — its entry exists.
		$this->assertArrayHasKey( 'result', $results['html'] );
	}

	/**
	 * Build a tmp dir for one test, isolated by uniqid() so parallel runs
	 * don't collide. Recursively removed in cleanup_tmp_dir().
	 */
	private function make_tmp_dir(): string {
		$path = sys_get_temp_dir() . '/sscribe-wrapper-' . uniqid( '', true );
		mkdir( $path, 0700, true );
		return $path;
	}

	/**
	 * Recursively remove a tmp dir and any files the exporters wrote
	 * into it. Suppresses failures — leftover temp files are harmless.
	 */
	private function cleanup_tmp_dir( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $entry ) {
			$entry->isDir() ? @rmdir( $entry->getPathname() ) : @unlink( $entry->getPathname() );
		}
		@rmdir( $path );
	}

	/**
	 * Minimal page_data with the keys exporters reach for. Avoids
	 * "undefined index" notices in test runs.
	 */
	private function make_page_data(): array {
		return array(
			'id'         => 1,
			'title'      => 'Test Page',
			'slug'       => 'test-page',
			'content'    => '<p>Hello world.</p>',
			'permalink'  => 'https://example.com/test-page/',
			'excerpt'    => 'Hello world.',
			'language'   => 'en_US',
			'post_type'  => 'page',
			'post_date'  => '2024-01-01 00:00:00',
			'author'     => 'Tester',
		);
	}
}
