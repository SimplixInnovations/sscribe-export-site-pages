<?php
/**
 * SScribe Privacy Unit Test
 *
 * Validates the WP privacy-tool registration shape (filter callbacks +
 * friendly names + keys) and the no-user fast-path. The exporter/eraser
 * data-payload paths are exercised through SScribe_Privacy_Storage_Test
 * (existing) which fakes the storage layer; this test pins the public
 * contract so a future refactor cannot silently break the Tools →
 * Export/erase Personal Data page.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @group privacy
 */
class SScribe_Privacy_Test extends TestCase {

	public function test_register_exporter_adds_sscribe_entry(): void {
		$privacy  = new \SScribe_Privacy();
		$filtered = $privacy->register_exporter( array() );

		$this->assertArrayHasKey( 'sscribe-export-site-pages', $filtered );
		$this->assertSame( 'SScribe export data', $filtered['sscribe-export-site-pages']['exporter_friendly_name'] );
		$this->assertIsCallable( $filtered['sscribe-export-site-pages']['callback'] );
		$this->assertSame(
			array( $privacy, 'export_personal_data' ),
			$filtered['sscribe-export-site-pages']['callback']
		);
	}

	public function test_register_eraser_adds_sscribe_entry(): void {
		$privacy  = new \SScribe_Privacy();
		$filtered = $privacy->register_eraser( array() );

		$this->assertArrayHasKey( 'sscribe-export-site-pages', $filtered );
		$this->assertSame( 'SScribe export data', $filtered['sscribe-export-site-pages']['eraser_friendly_name'] );
		$this->assertIsCallable( $filtered['sscribe-export-site-pages']['callback'] );
		$this->assertSame(
			array( $privacy, 'erase_personal_data' ),
			$filtered['sscribe-export-site-pages']['callback']
		);
	}

	public function test_register_exporter_preserves_other_entries(): void {
		$existing = array(
			'foo-plugin' => array( 'exporter_friendly_name' => 'Foo' ),
		);
		$privacy  = new \SScribe_Privacy();
		$filtered = $privacy->register_exporter( $existing );

		$this->assertArrayHasKey( 'foo-plugin', $filtered );
		$this->assertArrayHasKey( 'sscribe-export-site-pages', $filtered );
	}

	public function test_export_personal_data_returns_empty_for_unknown_email(): void {
		$privacy = new \SScribe_Privacy();
		$result  = $privacy->export_personal_data( 'no-such-user@example.invalid' );

		$this->assertSame( array(), $result['data'] );
		$this->assertTrue( $result['done'] );
	}
}