<?php
/**
 * SScribe DOCX Content Renderer helper coverage test
 *
 * Targets the pure private helpers of SScribe_DOCX_Content_Renderer:
 *
 *   - default_colors()               : returns 8 keys
 *   - sanitize_colors()              : merges valid 6-hex colors
 *   - safe_preg_replace()            : happy path, null result, invalid pattern
 *   - safe_text()                    : passes through, munges control chars
 *   - validate_url()                 : http/https/javascript:/data:/empty
 *   - is_url_host_allowed_for_docx() : rejects private, allows public
 *   - with_complex_script()          : RTL adds complexScript + rtl
 *   - get_para_style()               : default + RTL bidi
 *   - sync_config()                  : sets is_rtl + color + font
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

if ( ! class_exists( '\\SScribe_DOCX_Content_Renderer', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-docx-content-renderer.php';
}

final class SScribe_DOCX_Content_Renderer_Helper_Coverage_Test extends TestCase {

	private \SScribe_DOCX_Content_Renderer $rnd;
	private ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();
		$this->rnd = new \SScribe_DOCX_Content_Renderer();
		$this->ref = new ReflectionClass( $this->rnd );
	}

	private function call( string $name, array $args = array() ): mixed {
		$m = $this->ref->getMethod( $name );
		return $m->invokeArgs( $this->rnd, $args );
	}

	public function test_default_colors_returns_eight_keys(): void {
		$result = $this->call( 'default_colors' );
		$this::assertIsArray( $result );
		$this::assertCount( 8, $result );
		$this::assertArrayHasKey( 'primary', $result );
		$this::assertArrayHasKey( 'heading', $result );
		$this::assertArrayHasKey( 'body', $result );
		$this::assertArrayHasKey( 'light_bg', $result );
		$this::assertArrayHasKey( 'link', $result );
		$this::assertArrayHasKey( 'code_bg', $result );
		$this::assertArrayHasKey( 'white', $result );
		$this::assertArrayHasKey( 'border', $result );
	}

	public function test_sanitize_colors_merges_valid_hex(): void {
		$result = $this->call( 'sanitize_colors', array( array( 'primary' => 'aabbcc', 'heading' => '112233' ) ) );
		$this::assertSame( 'AABBCC', $result['primary'] );
		$this::assertSame( '112233', $result['heading'] );
	}

	public function test_sanitize_colors_falls_back_for_invalid(): void {
		$result = $this->call( 'sanitize_colors', array( array( 'primary' => 'not-a-color', 'heading' => '#FF0000' ) ) );
		$this::assertSame( '4A8263', $result['primary'] ); // default
		$this::assertSame( '122119', $result['heading'] ); // default
	}

	public function test_sanitize_colors_falls_back_for_non_string(): void {
		$result = $this->call( 'sanitize_colors', array( array( 'primary' => 12345 ) ) );
		$this::assertSame( '4A8263', $result['primary'] ); // default
	}

	public function test_safe_preg_replace_happy_path(): void {
		$result = $this->call( 'safe_preg_replace', array( '/foo/', 'bar', 'foo here' ) );
		$this::assertSame( 'bar here', $result );
	}

	public function test_safe_preg_replace_returns_subject_for_invalid_pattern(): void {
		set_error_handler( static function (): bool { return true; } );
		try {
			$result = $this->call( 'safe_preg_replace', array( '/[invalid/', 'bar', 'foo here' ) );
		} finally {
			restore_error_handler();
		}
		$this::assertSame( 'foo here', $result );
	}

	public function test_safe_preg_replace_handles_array_replacement(): void {
		// preg_replace with array replacement requires array pattern.
		$result = $this->call( 'safe_preg_replace', array( array( '/one/', '/two/' ), array( 'X', 'Y' ), 'one two three' ) );
		$this::assertStringContainsString( 'X', $result );
		$this::assertStringContainsString( 'Y', $result );
	}

	public function test_safe_text_removes_control_chars(): void {
		$result = $this->call( 'safe_text', array( "hello\x00\x07world" ) );
		$this::assertStringNotContainsString( "\x00", $result );
		$this::assertStringNotContainsString( "\x07", $result );
		$this::assertStringContainsString( 'hello', $result );
	}

	public function test_validate_url_returns_safe_for_http(): void {
		$result = $this->call( 'validate_url', array( 'https://example.com/x' ) );
		$this::assertNotSame( '', $result );
	}

	public function test_validate_url_blocks_javascript(): void {
		$this::assertSame( '', $this->call( 'validate_url', array( 'javascript:alert(1)' ) ) );
	}

	public function test_validate_url_blocks_data(): void {
		$this::assertSame( '', $this->call( 'validate_url', array( 'data:text/html,x' ) ) );
	}

	public function test_validate_url_returns_empty_for_invalid(): void {
		$this::assertSame( '', $this->call( 'validate_url', array( 'not a url' ) ) );
		$this::assertSame( '', $this->call( 'validate_url', array( '' ) ) );
	}

	public function test_is_url_host_allowed_for_docx_blocks_private(): void {
		// Private/local URLs are blocked.
		$this::assertFalse( $this->call( 'is_url_host_allowed_for_docx', array( 'http://localhost/x' ) ) );
		$this::assertFalse( $this->call( 'is_url_host_allowed_for_docx', array( 'http://127.0.0.1/x' ) ) );
	}

	public function test_is_url_host_allowed_for_docx_allows_self_host(): void {
		// home_url() host is in the default allowlist (home/site/uploads).
		$self = home_url( '/wp-content/uploads/test.png' );
		$this::assertTrue( $this->call( 'is_url_host_allowed_for_docx', array( $self ) ) );
	}

	public function test_with_complex_script_passthrough_when_ltr(): void {
		$result = $this->call( 'with_complex_script', array( array( 'name' => 'Arial' ) ) );
		$this::assertSame( array( 'name' => 'Arial' ), $result );
	}

	public function test_with_complex_script_adds_complex_script_when_rtl(): void {
		// Set is_rtl via reflection (private property).
		$p = $this->ref->getProperty( 'is_rtl' );
		$p->setValue( $this->rnd, true );

		$result = $this->call( 'with_complex_script', array( array( 'name' => 'Amiri' ) ) );
		$this::assertTrue( $result['complexScript'] );
		$this::assertTrue( $result['rtl'] );

		$p->setValue( $this->rnd, false );
	}

	public function test_get_para_style_returns_default(): void {
		$result = $this->call( 'get_para_style' );
		$this::assertIsArray( $result );
	}

	public function test_get_para_style_adds_bidi_when_rtl(): void {
		$p = $this->ref->getProperty( 'is_rtl' );
		$p->setValue( $this->rnd, true );

		$result = $this->call( 'get_para_style' );
		$this::assertTrue( $result['bidi'] );

		$p->setValue( $this->rnd, false );
	}

	public function test_sync_config_sets_internal_state(): void {
		$this->rnd->sync_config(
			array( 'primary' => 'AABBCC' ),
			true,
			'Amiri',
			12
		);

		// After sync, internal state should be set.
		$colors = $this->ref->getProperty( 'colors' );
		$is_rtl = $this->ref->getProperty( 'is_rtl' );
		$font   = $this->ref->getProperty( 'font_name' );
		$size   = $this->ref->getProperty( 'font_size' );

		$this::assertSame( 'AABBCC', $colors->getValue( $this->rnd )['primary'] );
		$this::assertTrue( $is_rtl->getValue( $this->rnd ) );
		$this::assertSame( 'Amiri', $font->getValue( $this->rnd ) );
		$this::assertSame( 12, $size->getValue( $this->rnd ) );
	}

	public function test_class_has_expected_public_methods(): void {
		$this::assertTrue( method_exists( \SScribe_DOCX_Content_Renderer::class, 'sync_config' ) );
		$this::assertTrue( method_exists( \SScribe_DOCX_Content_Renderer::class, 'add_main_content' ) );
	}
}
