<?php
/**
 * SScribe Capabilities Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Capabilities_Test extends TestCase {

	/**
	 * All allowed capabilities.
	 *
	 * @var array
	 */
	private const ALLOWED = array(
		'sscribe_export',
		'manage_options',
		'edit_pages',
		'edit_posts',
		'publish_pages',
		'publish_posts',
		'delete_pages',
		'export',
		'export_posts',
	);

	public function test_is_allowed_returns_true_for_valid(): void {
		foreach ( self::ALLOWED as $cap ) {
			$this->assertTrue( \SScribe_Capabilities::is_allowed( $cap ) );
		}
	}

	public function test_is_allowed_returns_false_for_invalid(): void {
		$this->assertFalse( \SScribe_Capabilities::is_allowed( 'manage_network' ) );
		$this->assertFalse( \SScribe_Capabilities::is_allowed( 'administrator' ) );
		$this->assertFalse( \SScribe_Capabilities::is_allowed( '' ) );
		$this->assertFalse( \SScribe_Capabilities::is_allowed( 'delete_users' ) );
	}

	public function test_get_allowed_list_returns_all(): void {
		$list = \SScribe_Capabilities::get_allowed_list();
		$this->assertIsArray( $list );
		$this->assertCount( 9, $list );
		foreach ( self::ALLOWED as $cap ) {
			$this->assertContains( $cap, $list );
		}
	}

	public function test_get_required_returns_default(): void {
		$cap = \SScribe_Capabilities::get_required();
		$this->assertEquals( 'sscribe_export', $cap );
	}

	public function test_get_required_respects_filter(): void {
		$GLOBALS['sscribe_test_filters'][] = array(
			'hook'     => 'sscribe_export_capability',
			'callback' => function () {
				return 'edit_pages';
			},
			'priority' => 10,
			'accepted_args' => 1,
		);

		$cap = \SScribe_Capabilities::get_required();
		$this->assertEquals( 'edit_pages', $cap );

		array_pop( $GLOBALS['sscribe_test_filters'] );
	}

	public function test_get_required_falls_back_for_invalid_filter(): void {
		$GLOBALS['sscribe_test_filters'][] = array(
			'hook'     => 'sscribe_export_capability',
			'callback' => function () {
				return 'delete_users';
			},
			'priority' => 10,
			'accepted_args' => 1,
		);

		$cap = \SScribe_Capabilities::get_required();
		$this->assertEquals( 'sscribe_export', $cap );

		array_pop( $GLOBALS['sscribe_test_filters'] );
	}
}
