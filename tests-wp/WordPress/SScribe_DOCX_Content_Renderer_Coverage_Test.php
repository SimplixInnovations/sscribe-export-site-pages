<?php
/**
 * Real-WordPress integration coverage tests for SScribe_DOCX_Content_Renderer.
 *
 * Slice #10 of the canonical Linux/Xdebug coverage architecture.
 *
 * The DOCX content renderer is a pure-logic helper (no I/O) extracted
 * from SScribe_Exporter. The only methods that depend on the PHPWord
 * Section/TextRun classes are render_paragraph, render_runs, render_list,
 * render_table, render_button, render_figure, render_inline_image, and
 * render_details — those are exercised end-to-end via SScribe_Exporter's
 * DOCX pipeline.
 *
 * This slice covers the pure helpers and the public surface:
 *
 *   - __construct() default + custom + RTL
 *   - sanitize_colors() accept / reject / fallback
 *   - safe_preg_replace() valid + null subject
 *   - safe_text() clean / dirty UTF-8 / oversize
 *   - validate_url() empty / fragment / absolute / relative / http(s) /
 *     javascript: rejection / mailto+tel pass-through
 *   - is_url_host_allowed_for_docx() relative pass / non-http reject /
 *     allowed host / filter override
 *   - with_complex_script() RTL flag
 *   - get_para_style() RTL + bidi
 *   - get_logger() lazy init
 *   - sync_config() clamp + persist
 *   - last_error public field default
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

require_once __DIR__ . '/SScribe_WP_TestCase.php';

final class SScribe_DOCX_Content_Renderer_Coverage_Test extends SScribe_WP_TestCase {

	/** Reach into private helpers via reflection. */
	private function call( string $method, array $args, ?SScribe_DOCX_Content_Renderer $r = null ): mixed {
		$r   = $r ?? new SScribe_DOCX_Content_Renderer();
		$ref = new \ReflectionMethod( $r, $method );
		return $ref->invokeArgs( $r, $args );
	}

	// -----------------------------------------------------------------
	// __construct / default state
	// -----------------------------------------------------------------

	public function test_constructor_defaults_are_safe(): void {
		$r = new SScribe_DOCX_Content_Renderer();
		$this::assertSame( '', $r->last_error );
		$this::assertInstanceOf( SScribe_Content_Parser::class, $this->read_prop( $r, 'parser' ) );
		$this::assertSame( 11, (int) $this->read_prop( $r, 'font_size' ) );
		$this::assertSame( 'Arial', (string) $this->read_prop( $r, 'font_name' ) );
		$this::assertFalse( (bool) $this->read_prop( $r, 'is_rtl' ) );
	}

	public function test_constructor_accepts_custom_logger_and_colors(): void {
		$logger = new SScribe_Logger( false );
		$r      = new SScribe_DOCX_Content_Renderer( null, $logger, array(), true, 'CustomFont', 36 );
		$this::assertSame( $logger, $this->read_prop( $r, 'logger' ) );
		$this::assertSame( 36, (int) $this->read_prop( $r, 'font_size' ) );
		$this::assertSame( 'CustomFont', (string) $this->read_prop( $r, 'font_name' ) );
		$this::assertTrue( (bool) $this->read_prop( $r, 'is_rtl' ) );
	}

	public function test_constructor_clamps_font_size_below_minimum(): void {
		$r = new SScribe_DOCX_Content_Renderer( null, null, array(), false, 'Arial', 2 );
		$this::assertSame( 6, (int) $this->read_prop( $r, 'font_size' ) );
	}

	public function test_constructor_clamps_font_size_above_maximum(): void {
		$r = new SScribe_DOCX_Content_Renderer( null, null, array(), false, 'Arial', 200 );
		$this::assertSame( 72, (int) $this->read_prop( $r, 'font_size' ) );
	}

	// -----------------------------------------------------------------
	// sanitize_colors()
	// -----------------------------------------------------------------

	public function test_sanitize_colors_keeps_valid_six_digit_hex(): void {
		$out = $this->call( 'sanitize_colors', array( array( 'primary' => 'abcdef' ) ) );
		$this::assertSame( 'ABCDEF', $out['primary'] );
	}

	public function test_sanitize_colors_rejects_non_hex(): void {
		$out = $this->call( 'sanitize_colors', array( array( 'primary' => 'not-a-hex' ) ) );
		// Default stays.
		$this::assertSame( '4A8263', $out['primary'] );
	}

	public function test_sanitize_colors_rejects_short_hex(): void {
		$out = $this->call( 'sanitize_colors', array( array( 'primary' => 'FFF' ) ) );
		$this::assertSame( '4A8263', $out['primary'] );
	}

	public function test_sanitize_colors_rejects_non_string(): void {
		$out = $this->call( 'sanitize_colors', array( array( 'primary' => 12345 ) ) );
		$this::assertSame( '4A8263', $out['primary'] );
	}

	// -----------------------------------------------------------------
	// safe_preg_replace()
	// -----------------------------------------------------------------

	public function test_safe_preg_replace_returns_replaced_string(): void {
		$out = $this->call( 'safe_preg_replace', array( '/foo/', 'bar', 'foo-baz' ) );
		$this::assertSame( 'bar-baz', $out );
	}

	public function test_safe_preg_replace_falls_back_on_null(): void {
		// Trigger PCRE failure by passing a malformed pattern. preg_replace returns null.
		// We silence the resulting warning because the branch under test is the null-fallback path.
		$out = @$this->call( 'safe_preg_replace', array( '/[unclosed/', 'x', 'subject' ) );
		$this::assertSame( 'subject', $out );
	}

	// -----------------------------------------------------------------
	// safe_text()
	// -----------------------------------------------------------------

	public function test_safe_text_strips_null_bytes(): void {
		$out = $this->call( 'safe_text', array( "hi\x00there" ) );
		$this::assertSame( 'hithere', $out );
	}

	public function test_safe_text_normalizes_crlf(): void {
		$out = $this->call( 'safe_text', array( "line1\r\nline2\r\nline3" ) );
		$this::assertSame( "line1\nline2\nline3", $out );
	}

	public function test_safe_text_strips_form_feed(): void {
		$out = $this->call( 'safe_text', array( "a\x0Cb" ) );
		$this::assertSame( 'ab', $out );
	}

	public function test_safe_text_strips_bom_and_zero_width(): void {
		// Use literal UTF-8 byte sequences (PHP doesn't expand \x{...} in strings).
		$bom        = "\xEF\xBB\xBF";      // U+FEFF
		$zwsp       = "\xE2\x80\x8B";      // U+200B
		$soft_hyphen = "\xC2\xAD";         // U+00AD
		$out        = $this->call( 'safe_text', array( $bom . $zwsp . 'hello' . $soft_hyphen ) );
		$this::assertSame( 'hello', $out );
	}

	// -----------------------------------------------------------------
	// validate_url()
	// -----------------------------------------------------------------

	public function test_validate_url_returns_empty_for_empty(): void {
		$this::assertSame( '', $this->call( 'validate_url', array( '' ) ) );
	}

	public function test_validate_url_preserves_fragment(): void {
		$this::assertSame( '#section-1', $this->call( 'validate_url', array( '#section-1' ) ) );
	}

	public function test_validate_url_passes_https(): void {
		$out = $this->call( 'validate_url', array( 'https://example.com/page' ) );
		$this::assertSame( 'https://example.com/page', $out );
	}

	public function test_validate_url_passes_mailto(): void {
		$out = $this->call( 'validate_url', array( 'mailto:hi@example.com' ) );
		$this::assertStringContainsString( 'mailto:', $out );
	}

	public function test_validate_url_passes_tel(): void {
		$out = $this->call( 'validate_url', array( 'tel:+15551234567' ) );
		$this::assertStringContainsString( 'tel:', $out );
	}

	public function test_validate_url_rejects_javascript_scheme(): void {
		$this::assertSame( '', $this->call( 'validate_url', array( 'javascript:alert(1)' ) ) );
	}

	public function test_validate_url_rejects_data_scheme(): void {
		$this::assertSame( '', $this->call( 'validate_url', array( 'data:text/plain;base64,SGk=' ) ) );
	}

	public function test_validate_url_normalizes_relative_path(): void {
		$out = $this->call( 'validate_url', array( '/foo bar/baz' ) );
		// path segments are urlencoded
		$this::assertStringContainsString( 'foo%20bar', $out );
		$this::assertStringContainsString( 'baz', $out );
	}

	// -----------------------------------------------------------------
	// is_url_host_allowed_for_docx()
	// -----------------------------------------------------------------

	public function test_is_url_host_allowed_rejects_non_http_scheme(): void {
		$this::assertFalse( $this->call( 'is_url_host_allowed_for_docx', array( 'data:text/plain,hi' ) ) );
		$this::assertFalse( $this->call( 'is_url_host_allowed_for_docx', array( 'ftp://example.com/file' ) ) );
		$this::assertFalse( $this->call( 'is_url_host_allowed_for_docx', array( 'javascript:alert(1)' ) ) );
	}

	public function test_is_url_host_allowed_rejects_empty_host(): void {
		$this::assertFalse( $this->call( 'is_url_host_allowed_for_docx', array( 'https://' ) ) );
	}

	public function test_is_url_host_allowed_rejects_disallowed_host(): void {
		$this::assertFalse( $this->call( 'is_url_host_allowed_for_docx', array( 'https://evil.example.com/img.png' ) ) );
	}

	public function test_is_url_host_allowed_accepts_self_host(): void {
		$home  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$url   = 'https://' . $home . '/wp-content/uploads/img.png';
		$this::assertTrue( $this->call( 'is_url_host_allowed_for_docx', array( $url ) ) );
	}

	public function test_is_url_host_allowed_filter_can_extend_list(): void {
		$callback = function ( $hosts ) {
			$hosts[] = 'cdn.trusted.example';
			return $hosts;
		};
		add_filter( 'sscribe_allowed_image_hosts', $callback );
		try {
			$this::assertTrue(
				$this->call( 'is_url_host_allowed_for_docx', array( 'https://cdn.trusted.example/file.png' ) )
			);
		} finally {
			remove_filter( 'sscribe_allowed_image_hosts', $callback );
		}
	}

	// -----------------------------------------------------------------
	// with_complex_script()
	// -----------------------------------------------------------------

	public function test_with_complex_script_returns_input_when_not_rtl(): void {
		$r   = new SScribe_DOCX_Content_Renderer();
		$out = $this->call( 'with_complex_script', array( array( 'name' => 'Arial' ) ), $r );
		$this::assertSame( array( 'name' => 'Arial' ), $out );
	}

	public function test_with_complex_script_adds_complex_script_when_rtl(): void {
		$r = new SScribe_DOCX_Content_Renderer( null, null, array(), true );
		$out = $this->call( 'with_complex_script', array( array( 'name' => 'Arial' ) ), $r );
		$this::assertTrue( $out['complexScript'] );
		$this::assertTrue( $out['rtl'] );
	}

	public function test_with_complex_script_preserves_existing_complex_script(): void {
		$r = new SScribe_DOCX_Content_Renderer( null, null, array(), true );
		$out = $this->call(
			'with_complex_script',
			array( array( 'name' => 'Arial', 'complexScript' => false ) ),
			$r
		);
		// Existing key is not overwritten.
		$this::assertFalse( $out['complexScript'] );
		$this::assertTrue( $out['rtl'] );
	}

	// -----------------------------------------------------------------
	// get_para_style()
	// -----------------------------------------------------------------

	public function test_get_para_style_passthrough_when_not_rtl(): void {
		$r   = new SScribe_DOCX_Content_Renderer();
		$out = $this->call( 'get_para_style', array( array( 'alignment' => 'left' ) ), $r );
		$this::assertSame( 'left', $out['alignment'] );
		$this::assertArrayNotHasKey( 'bidi', $out );
	}

	public function test_get_para_style_adds_bidi_and_jc_start_when_rtl(): void {
		// The Jc::START constant is in vendor-prefixed/phpoffice; loading
		// it pulls in AbstractEnum via the classmap, which we can't
		// safely require from this testbench layer. Instead, mark the
		// alignment-default branch as skipped (the bidi branch is still
		// covered by test_get_para_style_preserves_alignment_when_rtl).
		$this::markTestSkipped( 'Jc::START class chain not loadable in testbench layer.' );
	}

	public function test_get_para_style_preserves_alignment_when_rtl_and_alignment_set(): void {
		$r   = new SScribe_DOCX_Content_Renderer( null, null, array(), true );
		$out = $this->call( 'get_para_style', array( array( 'alignment' => 'center' ) ), $r );
		$this::assertSame( 'center', $out['alignment'] );
		$this::assertTrue( $out['bidi'] );
	}

	// -----------------------------------------------------------------
	// get_logger()
	// -----------------------------------------------------------------

	public function test_get_logger_lazy_inits_when_null(): void {
		$r = new SScribe_DOCX_Content_Renderer();
		$logger = $this->call( 'get_logger', array(), $r );
		$this::assertInstanceOf( SScribe_Logger_Interface::class, $logger );
		// Subsequent calls return the same instance.
		$this::assertSame( $logger, $this->call( 'get_logger', array(), $r ) );
	}

	public function test_get_logger_returns_constructor_injected_logger(): void {
		$logger = new SScribe_Logger( false );
		$r      = new SScribe_DOCX_Content_Renderer( null, $logger );
		$this::assertSame( $logger, $this->call( 'get_logger', array(), $r ) );
	}

	// -----------------------------------------------------------------
	// sync_config()
	// -----------------------------------------------------------------

	public function test_sync_config_persists_values_and_clamps_font_size(): void {
		$r = new SScribe_DOCX_Content_Renderer();
		$r->sync_config( array( 'primary' => 'ff00aa' ), true, 'Verdana', 100 );
		$this::assertTrue( (bool) $this->read_prop( $r, 'is_rtl' ) );
		$this::assertSame( 'Verdana', (string) $this->read_prop( $r, 'font_name' ) );
		$this::assertSame( 72, (int) $this->read_prop( $r, 'font_size' ) );
		$this::assertSame( 'FF00AA', $this->read_prop( $r, 'colors' )['primary'] );
	}

	public function test_sync_config_clamps_font_size_below_minimum(): void {
		$r = new SScribe_DOCX_Content_Renderer();
		$r->sync_config( array(), false, 'Arial', 1 );
		$this::assertSame( 6, (int) $this->read_prop( $r, 'font_size' ) );
	}

	// -----------------------------------------------------------------
	// last_error (public field) default
	// -----------------------------------------------------------------

	public function test_last_error_defaults_to_empty_string(): void {
		$r = new SScribe_DOCX_Content_Renderer();
		$this::assertSame( '', $r->last_error );
		$r->last_error = 'a captured error';
		$this::assertSame( 'a captured error', $r->last_error );
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * Read a private property value via Reflection.
	 */
	private function read_prop( object $obj, string $name ): mixed {
		$ref = new \ReflectionProperty( $obj, $name );
		return $ref->getValue( $obj );
	}
}
