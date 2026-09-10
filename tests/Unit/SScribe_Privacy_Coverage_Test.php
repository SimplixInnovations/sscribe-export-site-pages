<?php
/**
 * SScribe Privacy coverage test.
 *
 * Targets the WordPress privacy-tools integration class. The class
 * itself is small but exercises collaborators (audit_trail, session,
 * export_stats, zip_handler). This test drives the public surface:
 *
 *   - register_privacy_policy()
 *   - register_exporter()
 *   - register_eraser()
 *   - export_personal_data() — unknown user returns done=true
 *   - erase_personal_data()  — unknown user returns done=true
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Privacy', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-privacy.php';
}

final class SScribe_Privacy_Coverage_Test extends TestCase {

	public function test_register_privacy_policy_returns_without_wp_function(): void {
		// The unit bootstrap does not define register_privacy_policy_content,
		// so the method must short-circuit and return without crashing.
		$privacy = new \SScribe_Privacy();
		$privacy->register_privacy_policy();
		$this::assertTrue( true ); // no exception = pass
	}

	public function test_register_exporter_adds_sscribe_entry(): void {
		$privacy  = new \SScribe_Privacy();
		$result   = $privacy->register_exporter( array() );
		$this::assertArrayHasKey( 'sscribe-export-site-pages', $result );
		$this::assertArrayHasKey( 'exporter_friendly_name', $result['sscribe-export-site-pages'] );
		$this::assertArrayHasKey( 'callback', $result['sscribe-export-site-pages'] );
	}

	public function test_register_eraser_adds_sscribe_entry(): void {
		$privacy  = new \SScribe_Privacy();
		$result   = $privacy->register_eraser( array() );
		$this::assertArrayHasKey( 'sscribe-export-site-pages', $result );
		$this::assertArrayHasKey( 'eraser_friendly_name', $result['sscribe-export-site-pages'] );
		$this::assertArrayHasKey( 'callback', $result['sscribe-export-site-pages'] );
	}

	public function test_export_personal_data_returns_done_for_unknown_email(): void {
		$privacy = new \SScribe_Privacy();
		$result  = $privacy->export_personal_data( 'no-such-user@example.invalid' );
		$this::assertIsArray( $result );
		$this::assertArrayHasKey( 'data', $result );
		$this::assertArrayHasKey( 'done', $result );
		$this::assertTrue( $result['done'] );
		$this::assertSame( array(), $result['data'] );
	}

	public function test_erase_personal_data_returns_done_for_unknown_email(): void {
		$privacy = new \SScribe_Privacy();
		$result  = $privacy->erase_personal_data( 'no-such-user@example.invalid' );
		$this::assertIsArray( $result );
		$this::assertArrayHasKey( 'items_removed', $result );
		$this::assertArrayHasKey( 'items_retained', $result );
		$this::assertArrayHasKey( 'messages', $result );
		$this::assertArrayHasKey( 'done', $result );
		$this::assertTrue( $result['done'] );
	}

	public function test_class_has_expected_methods(): void {
		$this::assertTrue( method_exists( \SScribe_Privacy::class, 'register_privacy_policy' ) );
		$this::assertTrue( method_exists( \SScribe_Privacy::class, 'register_exporter' ) );
		$this::assertTrue( method_exists( \SScribe_Privacy::class, 'register_eraser' ) );
		$this::assertTrue( method_exists( \SScribe_Privacy::class, 'export_personal_data' ) );
		$this::assertTrue( method_exists( \SScribe_Privacy::class, 'erase_personal_data' ) );
	}
}
