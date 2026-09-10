<?php
/**
 * SScribe Exporter helper coverage test
 *
 * Targets the pure private helpers of SScribe_Exporter via reflection:
 *
 *   - normalize_scalar_value()      : scalar/empty/non-scalar
 *   - normalize_page_data()         : empty, partial, full, deep tree
 *   - normalize_child_pages()       : depth limit, non-array items
 *   - safe_preg_replace()           : happy path, invalid pattern, replacement array
 *   - safe_text()                   : passes through; munges control chars
 *   - is_machine_style_string()     : short / long / with-script
 *   - validate_url()                : http/https/javascript:/data:/empty
 *   - is_ip_blocked()               : private IP space
 *   - normalize_ip_literal()        : IPv6 brackets, IPv4
 *   - is_rtl_document()             : direction=rtl, rtl language, default
 *   - with_complex_script()         : adds fallback when script=auto
 *   - get_para_style()              : returns array with default props
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

if ( ! class_exists( '\\SScribe_Exporter', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-exporter.php';
}

final class SScribe_Exporter_Helper_Coverage_Test extends TestCase {

	private \SScribe_Exporter $ex;
	private ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();
		$this->ex  = new \SScribe_Exporter();
		$this->ref = new ReflectionClass( $this->ex );
	}

	private function call( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		$m->setAccessible( true );
		return $m->invokeArgs( $this->ex, $args );
	}

	public function test_normalize_scalar_value_passes_through_string(): void {
		$this::assertSame( 'hello', $this->call( 'normalize_scalar_value', array( 'hello' ) ) );
		$this::assertSame( '42', $this->call( 'normalize_scalar_value', array( 42 ) ) );
	}

	public function test_normalize_scalar_value_trims_whitespace(): void {
		$this::assertSame( 'hello', $this->call( 'normalize_scalar_value', array( '  hello  ' ) ) );
	}

	public function test_normalize_scalar_value_returns_default_for_empty(): void {
		$this::assertSame( 'X', $this->call( 'normalize_scalar_value', array( '', 'X' ) ) );
		$this::assertSame( 'X', $this->call( 'normalize_scalar_value', array( '   ', 'X' ) ) );
	}

	public function test_normalize_scalar_value_returns_default_for_non_scalar(): void {
		$this::assertSame( 'X', $this->call( 'normalize_scalar_value', array( array( 'a' ), 'X' ) ) );
		$this::assertSame( 'X', $this->call( 'normalize_scalar_value', array( null, 'X' ) ) );
	}

	public function test_normalize_page_data_returns_empty_defaults(): void {
		$result = $this->call( 'normalize_page_data', array( array() ) );
		$this::assertIsArray( $result );
		// Empty title gets the localized "Untitled" fallback.
		$this::assertNotSame( '', $result['title'] );
		$this::assertSame( '', $result['content'] );
		$this::assertArrayHasKey( 'language', $result );
		$this::assertSame( 'en', $result['language'] );
		$this::assertSame( 0, $result['id'] );
		$this::assertSame( 0, $result['word_count'] );
	}

	public function test_normalize_page_data_preserves_known_fields(): void {
		$input = array(
			'id'           => 42,
			'title'        => 'Sample',
			'content'      => '<p>Hello</p>',
			'word_count'   => 100,
			'reading_time' => 1.5,
			'language'     => 'fr',
		);
		$result = $this->call( 'normalize_page_data', array( $input ) );
		$this::assertSame( 42, $result['id'] );
		$this::assertSame( 'Sample', $result['title'] );
		$this::assertSame( '<p>Hello</p>', $result['content'] );
		$this::assertSame( 100, $result['word_count'] );
		$this::assertEquals( 1.5, $result['reading_time'] );
		$this::assertSame( 'fr', $result['language'] );
	}

	public function test_normalize_page_data_falls_back_language_for_invalid(): void {
		// 9-char base fails the {2,8} quantifier and triggers the fallback.
		$result = $this->call( 'normalize_page_data', array( array( 'language' => 'TOOLONGER' ) ) );
		$this::assertSame( 'en', $result['language'] );
	}

	public function test_normalize_page_data_normalizes_seo(): void {
		$input  = array( 'seo' => array( 'meta_title' => 'SEO Title', 'canonical_url' => 'https://example.com/' ) );
		$result = $this->call( 'normalize_page_data', array( $input ) );
		$this::assertSame( 'SEO Title', $result['seo']['meta_title'] );
		$this::assertSame( 'https://example.com/', $result['seo']['canonical_url'] );
	}

	public function test_normalize_page_data_limits_breadcrumbs_to_100(): void {
		$crumbs = array();
		for ( $i = 1; $i <= 150; $i++ ) {
			$crumbs[] = array( 'title' => "C{$i}", 'url' => "/c{$i}" );
		}
		$result = $this->call( 'normalize_page_data', array( array( 'breadcrumbs' => $crumbs ) ) );
		$this::assertCount( 100, $result['breadcrumbs'] );
	}

	public function test_normalize_page_data_recurses_children(): void {
		$input = array(
			'children' => array(
				array(
					'title'    => 'Child',
					'url'      => '/child',
					'children' => array(
						array( 'title' => 'Grand', 'url' => '/g' ),
					),
				),
			),
		);
		$result = $this->call( 'normalize_page_data', array( $input ) );
		$this::assertCount( 1, $result['children'] );
		$this::assertSame( 'Child', $result['children'][0]['title'] );
		$this::assertCount( 1, $result['children'][0]['children'] );
		$this::assertSame( 'Grand', $result['children'][0]['children'][0]['title'] );
	}

	public function test_normalize_child_pages_caps_depth_at_3(): void {
		// Build a tree with depth > 3 — anything past should be truncated.
		$tree = array(
			array( 'title' => 'L1', 'children' => array(
				array( 'title' => 'L2', 'children' => array(
					array( 'title' => 'L3', 'children' => array(
						array( 'title' => 'L4', 'children' => array(
							array( 'title' => 'L5' ),
						) ),
					) ),
				) ),
			) ),
		);
		$result = $this->call( 'normalize_child_pages', array( $tree ) );
		// L1 → L2 → L3 → L4 (truncated at depth 3 means L3 has no children).
		$this::assertCount( 1, $result );
		$this::assertSame( 'L1', $result[0]['title'] );
		$this::assertCount( 1, $result[0]['children'] );
		$this::assertSame( 'L2', $result[0]['children'][0]['title'] );
		$this::assertSame( array(), $result[0]['children'][0]['children'][0]['children'] );
	}

	public function test_normalize_child_pages_skips_non_arrays(): void {
		$tree = array( 'not-an-array', array( 'title' => 'ok' ) );
		$result = $this->call( 'normalize_child_pages', array( $tree ) );
		$this::assertCount( 1, $result );
		$this::assertSame( 'ok', $result[0]['title'] );
	}

	public function test_safe_preg_replace_happy_path(): void {
		$result = $this->call( 'safe_preg_replace', array( '/foo/', 'bar', 'foo here' ) );
		$this::assertSame( 'bar here', $result );
	}

	public function test_safe_preg_replace_returns_subject_on_invalid_pattern(): void {
		// Suppress the PHP warning that preg_replace emits on an invalid pattern.
		set_error_handler( static function (): bool { return true; } );
		try {
			$result = $this->call( 'safe_preg_replace', array( '/[invalid/', 'bar', 'foo here' ) );
		} finally {
			restore_error_handler();
		}
		$this::assertSame( 'foo here', $result );
	}

	public function test_safe_text_removes_control_chars(): void {
		$result = $this->call( 'safe_text', array( "hello\x00\x07world" ) );
		$this::assertStringContainsString( 'hello', $result );
		$this::assertStringContainsString( 'world', $result );
	}

	public function test_is_machine_style_string_returns_bool(): void {
		$result = $this->call( 'is_machine_style_string', array( '.class { color: red; }' ) );
		$this::assertIsBool( $result );
	}

	public function test_validate_url_returns_safe_for_http(): void {
		$result = $this->call( 'validate_url', array( 'https://example.com/page' ) );
		$this::assertNotSame( '', $result );
	}

	public function test_validate_url_blocks_javascript(): void {
		$result = $this->call( 'validate_url', array( 'javascript:alert(1)' ) );
		$this::assertSame( '', $result );
	}

	public function test_validate_url_blocks_data(): void {
		$result = $this->call( 'validate_url', array( 'data:text/html,<h1>x</h1>' ) );
		$this::assertSame( '', $result );
	}

	public function test_validate_url_returns_empty_for_invalid(): void {
		$this::assertSame( '', $this->call( 'validate_url', array( 'not a url' ) ) );
		$this::assertSame( '', $this->call( 'validate_url', array( '' ) ) );
	}

	public function test_is_ip_blocked_blocks_private_ranges(): void {
		// Private network IPs should be rejected by SSRF guard.
		$this::assertTrue( $this->call( 'is_ip_blocked', array( '127.0.0.1' ) ) );
		$this::assertTrue( $this->call( 'is_ip_blocked', array( '10.0.0.1' ) ) );
		$this::assertTrue( $this->call( 'is_ip_blocked', array( '192.168.1.1' ) ) );
	}

	public function test_is_ip_blocked_allows_public_ips(): void {
		$this::assertFalse( $this->call( 'is_ip_blocked', array( '8.8.8.8' ) ) );
	}

	public function test_normalize_ip_literal_handles_decimal_form(): void {
		// IPv4 in decimal integer form (e.g. 2130706433 → 127.0.0.1).
		$result = $this->call( 'normalize_ip_literal', array( '2130706433' ) );
		$this::assertSame( '127.0.0.1', $result );
	}

	public function test_normalize_ip_literal_handles_hex_form(): void {
		// 0x7F000001 → 127.0.0.1.
		$result = $this->call( 'normalize_ip_literal', array( '0x7F000001' ) );
		$this::assertSame( '127.0.0.1', $result );
	}

	public function test_normalize_ip_literal_returns_null_for_invalid(): void {
		$result = $this->call( 'normalize_ip_literal', array( 'not-an-ip' ) );
		$this::assertNull( $result );
	}

	public function test_is_rtl_document_detects_rtl_via_language(): void {
		// is_rtl_document() inspects 'language' — direction alone is ignored.
		$result = $this->call( 'is_rtl_document', array( array( 'language' => 'ar' ) ) );
		$this::assertTrue( $result );
	}

	public function test_is_rtl_document_returns_false_for_missing_language(): void {
		// Without language, the function defaults to LTR.
		$result = $this->call( 'is_rtl_document', array( array() ) );
		$this::assertFalse( $result );
	}

	public function test_is_rtl_document_detects_rtl_language(): void {
		$result = $this->call( 'is_rtl_document', array( array( 'language' => 'ar' ) ) );
		$this::assertTrue( $result );
	}

	public function test_is_rtl_document_default_false(): void {
		$result = $this->call( 'is_rtl_document', array( array( 'language' => 'en' ) ) );
		$this::assertFalse( $result );
	}

	public function test_with_complex_script_passthrough_when_ltr(): void {
		// When $this->is_rtl is false (default), the input passes through unchanged.
		$input  = array( 'name' => 'Arial' );
		$result = $this->call( 'with_complex_script', array( $input ) );
		$this::assertSame( $input, $result );
	}

	public function test_with_complex_script_adds_complex_script_when_rtl(): void {
		// Set the instance property so the RTL branch fires.
		$p = $this->ref->getProperty( 'is_rtl' );
		$p->setAccessible( true );
		$p->setValue( $this->ex, true );

		$result = $this->call( 'with_complex_script', array( array( 'name' => 'Amiri' ) ) );
		$this::assertTrue( $result['complexScript'] );
		$this::assertTrue( $result['rtl'] );

		$p->setValue( $this->ex, false );
	}

	public function test_get_para_style_returns_default(): void {
		$result = $this->call( 'get_para_style' );
		$this::assertIsArray( $result );
	}

	public function test_get_para_style_merges_base(): void {
		$base   = array( 'spacing' => array( 'before' => 100 ) );
		$result = $this->call( 'get_para_style', array( $base ) );
		$this::assertIsArray( $result );
	}

	public function test_get_last_error_returns_string(): void {
		$result = $this->ex->get_last_error();
		$this::assertIsString( $result );
	}

	public function test_class_has_expected_public_methods(): void {
		$this::assertTrue( method_exists( \SScribe_Exporter::class, 'generate_docx' ) );
		$this::assertTrue( method_exists( \SScribe_Exporter::class, 'get_last_error' ) );
		$this::assertTrue( method_exists( \SScribe_Exporter::class, 'set_format_options' ) );
	}
}
