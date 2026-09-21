<?php
/**
 * SScribe PDF exporter CSS/font isolation regression tests.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SScribe_PDF_Exporter_CSS_Keyword_Font_Policy_Test extends TestCase {

	private \SScribe_PDF_Exporter $exporter;
	private ReflectionClass $reflection;

	protected function setUp(): void {
		parent::setUp();
		$this->exporter   = new \SScribe_PDF_Exporter();
		$this->reflection = new ReflectionClass( $this->exporter );
	}

	private function call_private( string $method, array $args = array() ): mixed {
		return $this->reflection->getMethod( $method )->invokeArgs( $this->exporter, $args );
	}

	public function test_page_css_cannot_override_pinned_tcpdf_font_family(): void {
		$html = '<style>'
			. '.a{font-family: Georgia, serif;color:red;}'
			. '.b{font: italic 700 16px/1.4 "Remote Font", serif;background:#fff;}'
			. '</style><p>Body</p>';

		$result = $this->call_private( 'prepare_html_for_pdf_engine', array( $html, false ) );

		$this::assertStringNotContainsString( 'font-family', strtolower( $result ) );
		$this::assertDoesNotMatchRegularExpression( '/(?<![-a-z])font\s*:/i', $result );
		$this::assertStringContainsString( 'color:red', str_replace( ' ', '', $result ) );
		$this::assertStringContainsString( 'background:#fff', str_replace( ' ', '', $result ) );
	}

	public function test_pdf_html_removes_external_resource_css_tokens(): void {
		$html = '<style>'
			. '@import url("https://evil.example/x.css");'
			. '@font-face{font-family:x;src:url(https://evil.example/font.woff2);}'
			. '.a{background-image:url(https://evil.example/pixel.png);color:blue;}'
			. '</style><p>Body</p>';

		$result = $this->call_private( 'prepare_html_for_pdf_engine', array( $html, false ) );

		$this::assertStringNotContainsString( '@import', strtolower( $result ) );
		$this::assertStringNotContainsString( '@font-face', strtolower( $result ) );
		$this::assertStringNotContainsString( 'evil.example', strtolower( $result ) );
		$this::assertStringNotContainsString( 'url(', strtolower( $result ) );
		$this::assertStringContainsString( 'color:blue', str_replace( ' ', '', $result ) );
	}

	public function test_inline_style_filter_rejects_font_and_active_resource_values(): void {
		$input = 'font-family: Georgia; font: 12px serif; color: red; '
			. 'background: url(https://evil.example/x.png); '
			. 'border: expression(alert(1)); width: 100%;';

		$result = $this->call_private( 'filter_style_attribute', array( $input, false ) );

		$this::assertStringNotContainsString( 'font-family', $result );
		$this::assertDoesNotMatchRegularExpression( '/(?<![-a-z])font:/i', $result );
		$this::assertStringNotContainsString( 'url(', $result );
		$this::assertStringNotContainsString( 'expression(', $result );
		$this::assertStringContainsString( 'color:red;', $result );
		$this::assertStringContainsString( 'width:100%;', $result );
	}

	public function test_inline_style_filter_rejects_javascript_and_data_schemes(): void {
		$result = $this->call_private(
			'filter_style_attribute',
			array(
				'background: javascript:alert(1); border-color: data:text/plain,abc; color: #123456;',
				false,
			)
		);

		$this::assertStringNotContainsString( 'javascript:', strtolower( $result ) );
		$this::assertStringNotContainsString( 'data:', strtolower( $result ) );
		$this::assertSame( 'color:#123456;', $result );
	}

	public function test_safe_document_layout_styles_survive_filter(): void {
		$result = $this->call_private(
			'filter_style_attribute',
			array(
				'direction: rtl; text-align: right; page-break-after: always; '
				. 'border: 1px solid #000; width: 80%; position: fixed;',
				true,
			)
		);

		$this::assertStringContainsString( 'direction:rtl;', $result );
		$this::assertStringContainsString( 'text-align:right;', $result );
		$this::assertStringContainsString( 'page-break-after:always;', $result );
		$this::assertStringContainsString( 'border:1px solid #000;', $result );
		$this::assertStringContainsString( 'width:80%;', $result );
		$this::assertStringNotContainsString( 'position:', $result );
	}

	public function test_visible_content_that_looks_like_css_is_not_rewritten(): void {
		$html = '<p>Literal url(https://example.test/image.png)</p>'
			. '<code>font: 16px serif; font-family: Georgia;</code>'
			. '<style>.safe{font:16px serif;background-image:url(https://example.test/bg.png);color:red;}</style>';

		$result = $this->call_private( 'prepare_html_for_pdf_engine', array( $html, false ) );

		$this::assertStringContainsString( '<p>Literal url(https://example.test/image.png)</p>', $result );
		$this::assertStringContainsString( '<code>font: 16px serif; font-family: Georgia;</code>', $result );

		$matched = preg_match( '/<style\\b[^>]*>(.*?)<\\/style>/is', $result, $style_match );
		$this::assertSame( 1, $matched );
		$style = str_replace( ' ', '', strtolower( (string) $style_match[1] ) );
		$this::assertStringNotContainsString( 'background-image:url(', $style );
		$this::assertStringNotContainsString( 'font:16pxserif', $style );
		$this::assertStringNotContainsString( 'font-family:', $style );
		$this::assertStringContainsString( 'color:red', $style );
	}
}
