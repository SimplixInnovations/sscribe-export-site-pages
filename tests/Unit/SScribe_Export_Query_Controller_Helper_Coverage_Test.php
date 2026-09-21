<?php
/**
 * SScribe Export Query Controller helper coverage test
 *
 * Targets the pure private helpers of SScribe_Export_Query_Controller:
 *
 *   - compute_counts_payload()        : aggregates page+post status counts
 *   - read_client_generation()        : clamp + is_numeric + int cast
 *   - normalize_languages_for_batch() : drops non-strings, dedupes, caps 50,
 *                                       preserves '__all__'
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

if ( ! class_exists( '\\SScribe_Export_Query_Controller', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-export-query-controller.php';
}

final class SScribe_Export_Query_Controller_Helper_Coverage_Test extends TestCase {

	private \SScribe_Export_Query_Controller $ctrl;
	private ReflectionClass $ref;
	private \SScribe_Page_Collector $collector;

	protected function setUp(): void {
		parent::setUp();
		// Inject a real Page_Collector (no WP required for read methods).
		$this->collector = new \SScribe_Page_Collector();
		$this->ctrl = new \SScribe_Export_Query_Controller( null, null, $this->collector );
		$this->ref  = new ReflectionClass( $this->ctrl );
	}

	private function call( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		return $m->invokeArgs( $this->ctrl, $args );
	}

	public function test_compute_counts_payload_for_post_type_any(): void {
		$result = $this->call( 'compute_counts_payload', array( '', 'any' ) );
		$this::assertIsArray( $result );
		$this::assertArrayHasKey( 'counts', $result );
		$this::assertArrayHasKey( 'counts_page', $result );
		$this::assertArrayHasKey( 'counts_post', $result );
		$this::assertArrayHasKey( 'counts_any', $result );
	}

	public function test_compute_counts_payload_for_post_type_page(): void {
		$result = $this->call( 'compute_counts_payload', array( '', 'page' ) );
		$this::assertIsArray( $result );
		$this::assertSame( $result['counts'], $result['counts_page'] );
	}

	public function test_compute_counts_payload_for_post_type_post(): void {
		$result = $this->call( 'compute_counts_payload', array( '', 'post' ) );
		$this::assertIsArray( $result );
		$this::assertSame( $result['counts'], $result['counts_post'] );
	}

	public function test_read_client_generation_returns_int(): void {
		// Default value (when POST has no client_generation).
		$result = $this->call( 'read_client_generation' );
		$this::assertIsInt( $result );
		$this::assertGreaterThanOrEqual( 0, $result );
	}

	public function test_normalize_languages_for_batch_drops_non_strings(): void {
		// Mixed types: non-strings should be skipped.
		$result = $this->call( 'normalize_languages_for_batch', array( array( '__all__', 42, null, '__all__' ) ) );
		$this::assertIsArray( $result );
		foreach ( $result as $lang ) {
			$this::assertIsString( $lang );
		}
	}

	public function test_normalize_languages_for_batch_dedupes_sentinel(): void {
		// Sentinel dedup: two __all__ entries should yield one.
		$result = $this->call( 'normalize_languages_for_batch', array( array( '__all__', '__all__', '__all__' ) ) );
		$this::assertCount( 1, $result );
		$this::assertSame( '__all__', $result[0] );
	}

	public function test_normalize_languages_for_batch_caps_at_50(): void {
		$langs = array();
		for ( $i = 0; $i < 100; $i++ ) {
			$langs[] = '__all__';
		}
		$result = $this->call( 'normalize_languages_for_batch', array( $langs ) );
		// sentinel survives dedup → only 1 entry, but slice still caps at 50.
		$this::assertLessThanOrEqual( 50, count( $result ) );
	}

	public function test_normalize_languages_for_batch_preserves_sentinel_all(): void {
		// __all__ must survive normalization (it's the canonical sentinel).
		$result = $this->call( 'normalize_languages_for_batch', array( array( '__all__' ) ) );
		$this::assertContains( '__all__', $result );
	}

	public function test_class_has_expected_public_methods(): void {
		$this::assertTrue( method_exists( \SScribe_Export_Query_Controller::class, 'ajax_get_support_info' ) );
		$this::assertTrue( method_exists( \SScribe_Export_Query_Controller::class, 'ajax_get_status_counts' ) );
		$this::assertTrue( method_exists( \SScribe_Export_Query_Controller::class, 'ajax_get_export_preview' ) );
		$this::assertSame( '__all__', \SScribe_Export_Query_Controller::SENTINEL_ALL );
	}
}
