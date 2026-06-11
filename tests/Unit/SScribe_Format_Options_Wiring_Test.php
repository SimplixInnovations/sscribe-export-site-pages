<?php
/**
 * SScribe Format Options Wiring Unit Test
 *
 * Locks in the contract between the batch processor's
 * `sscribe_export_options_{$format}` filter, the new
 * `apply_format_options()` method on every built-in exporter, and
 * the per-format code paths that read the resolved options. A
 * regression here means the UI's per-format toggles silently have
 * no effect.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SScribe_Format_Options_Wiring_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sscribe_test_filters'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_filters'] = array();
		parent::tearDown();
	}

	public function test_html_exporter_omits_style_block_when_include_css_disabled(): void {
		$exporter = new \SScribe_HTML_Exporter();
		$exporter->apply_format_options(
			array( 'sscribe_html_include_css' => '' )
		);

		$ref    = new \ReflectionMethod( $exporter, 'generate_html' );
		$method = \Closure::bind(
			function ( $exporter, $page_data ) {
				return $exporter->generate_html( $page_data );
			},
			null,
			\SScribe_HTML_Exporter::class
		);
		$html   = $method( $exporter, $this->sample_page_data() );

		$this->assertStringNotContainsString( '<style>', $html );
		$this->assertStringContainsString( '<title>', $html );
	}

	public function test_html_exporter_includes_style_block_by_default(): void {
		$exporter = new \SScribe_HTML_Exporter();
		// No apply_format_options() call — defaults must include the style block.
		$ref    = new \ReflectionMethod( $exporter, 'generate_html' );
		$method = \Closure::bind(
			function ( $exporter, $page_data ) {
				return $exporter->generate_html( $page_data );
			},
			null,
			\SScribe_HTML_Exporter::class
		);
		$html   = $method( $exporter, $this->sample_page_data() );

		$this->assertStringContainsString( '<style>', $html );
	}

	public function test_html_exporter_uses_lazy_loading_when_responsive_enabled(): void {
		$exporter = new \SScribe_HTML_Exporter();
		$exporter->apply_format_options(
			array( 'sscribe_html_responsive_images' => '1' )
		);

		$ref    = new \ReflectionMethod( $exporter, 'get_featured_image_html' );
		$method = \Closure::bind(
			function ( $exporter, $page_data ) {
				return $exporter->get_featured_image_html( $page_data );
			},
			null,
			\SScribe_HTML_Exporter::class
		);
		$html   = $method(
			$exporter,
			$this->sample_page_data( array( 'featured_image_url' => 'https://example.com/img.png' ) )
		);

		$this->assertStringContainsString( 'loading="lazy"', $html );
		$this->assertStringNotContainsString( 'width="', $html );
	}

	public function test_html_exporter_uses_eager_loading_when_responsive_disabled(): void {
		$exporter = new \SScribe_HTML_Exporter();
		$exporter->apply_format_options(
			array( 'sscribe_html_responsive_images' => '' )
		);

		$ref    = new \ReflectionMethod( $exporter, 'get_featured_image_html' );
		$method = \Closure::bind(
			function ( $exporter, $page_data ) {
				return $exporter->get_featured_image_html( $page_data );
			},
			null,
			\SScribe_HTML_Exporter::class
		);
		$html   = $method(
			$exporter,
			$this->sample_page_data(
				array(
					'featured_image_url'    => 'https://example.com/img.png',
					'featured_image_width'  => 1200,
					'featured_image_height' => 800,
				)
			)
		);

		$this->assertStringContainsString( 'loading="eager"', $html );
		$this->assertStringContainsString( 'width="1200" height="800"', $html );
	}

	public function test_markdown_exporter_omits_frontmatter_when_disabled(): void {
		$exporter = new \SScribe_Markdown_Exporter();
		$exporter->apply_format_options(
			array( 'sscribe_md_include_frontmatter' => '' )
		);

		$ref    = new \ReflectionMethod( $exporter, 'generate_markdown' );
		$method = \Closure::bind(
			function ( $exporter, $page_data ) {
				return $exporter->generate_markdown( $page_data );
			},
			null,
			\SScribe_Markdown_Exporter::class
		);
		$md     = $method( $exporter, $this->sample_page_data() );

		$this->assertStringStartsNotWith( '---', $md );
		$this->assertStringNotContainsString( 'title:', explode( "\n\n", $md, 2 )[0] );
	}

	public function test_markdown_exporter_keeps_relative_urls_when_absolute_disabled(): void {
		$exporter = new \SScribe_Markdown_Exporter();
		$exporter->apply_format_options(
			array( 'sscribe_md_absolute_urls' => '' )
		);

		$ref    = new \ReflectionMethod( $exporter, 'sanitize_url' );
		$method = \Closure::bind(
			function ( $exporter, $url ) {
				return $exporter->sanitize_url( $url );
			},
			null,
			\SScribe_Markdown_Exporter::class
		);
		$url    = $method( $exporter, '/wp-content/uploads/img.png' );

		$this->assertSame( '/wp-content/uploads/img.png', $url );
	}

	public function test_markdown_exporter_promotes_relative_urls_to_absolute_by_default(): void {
		$exporter = new \SScribe_Markdown_Exporter();
		// Defaults to absolute; no apply_format_options() call.
		$ref    = new \ReflectionMethod( $exporter, 'sanitize_url' );
		$method = \Closure::bind(
			function ( $exporter, $url ) {
				return $exporter->sanitize_url( $url );
			},
			null,
			\SScribe_Markdown_Exporter::class
		);
		$url    = $method( $exporter, '/wp-content/uploads/img.png' );

		$this->assertStringStartsWith( home_url(), $url );
		$this->assertStringContainsString( '/wp-content/uploads/img.png', $url );
	}

	public function test_pdf_exporter_resolves_known_page_sizes(): void {
		$exporter = new \SScribe_PDF_Exporter();

		foreach ( array( 'A4', 'A3', 'Letter', 'Legal' ) as $size ) {
			$exporter->apply_format_options(
				array( 'sscribe_pdf_page_size' => $size )
			);
			$this->assertSame(
				$size,
				$this->call_resolve_pdf_page_size( $exporter ),
				"Page size '{$size}' must be honored verbatim"
			);
		}
	}

	public function test_pdf_exporter_falls_back_to_a4_for_unknown_page_size(): void {
		$exporter = new \SScribe_PDF_Exporter();
		$exporter->apply_format_options(
			array( 'sscribe_pdf_page_size' => 'Watermelon' )
		);

		$this->assertSame( 'A4', $this->call_resolve_pdf_page_size( $exporter ) );
	}

	public function test_pdf_exporter_defaults_to_a4_when_option_missing(): void {
		$exporter = new \SScribe_PDF_Exporter();

		$this->assertSame( 'A4', $this->call_resolve_pdf_page_size( $exporter ) );
	}

	public function test_docx_exporter_forwards_options_to_core_exporter(): void {
		$core    = new \SScribe_Exporter();
		$exporter = new \SScribe_DOCX_Exporter( $core );

		$exporter->apply_format_options(
			array(
				'sscribe_docx_template'        => 'minimal',
				'sscribe_docx_include_images'  => '',
				'sscribe_docx_include_toc'     => '1',
			)
		);

		$ref    = new \ReflectionProperty( $core, 'format_options' );
		$stored = $ref->getValue( $core );

		$this->assertSame( 'minimal', $stored['sscribe_docx_template'] );
		$this->assertSame( '', $stored['sscribe_docx_include_images'] );
		$this->assertSame( '1', $stored['sscribe_docx_include_toc'] );
	}

	public function test_docx_exporter_stores_options_internally(): void {
		$exporter = new \SScribe_DOCX_Exporter();
		$exporter->apply_format_options(
			array( 'sscribe_docx_template' => 'minimal' )
		);

		$ref    = new \ReflectionProperty( $exporter, 'format_options' );
		$stored = $ref->getValue( $exporter );

		$this->assertSame( 'minimal', $stored['sscribe_docx_template'] );
	}

	public function test_all_four_exporters_implement_apply_format_options(): void {
		$exporters = array(
			new \SScribe_HTML_Exporter(),
			new \SScribe_Markdown_Exporter(),
			new \SScribe_PDF_Exporter(),
			new \SScribe_DOCX_Exporter(),
		);

		foreach ( $exporters as $exporter ) {
			$this->assertTrue(
				method_exists( $exporter, 'apply_format_options' ),
				get_class( $exporter ) . ' must implement apply_format_options()'
			);
		}
	}

	/**
	 * Helper: invoke the private resolve_pdf_page_size() helper via a
	 * Closure::bind, avoiding the setAccessible() deprecation in PHP 8.1+.
	 */
	private function call_resolve_pdf_page_size( \SScribe_PDF_Exporter $exporter ): string {
		$ref    = new \ReflectionMethod( $exporter, 'resolve_pdf_page_size' );
		$method = \Closure::bind(
			function ( $exporter ) {
				return $exporter->resolve_pdf_page_size();
			},
			null,
			\SScribe_PDF_Exporter::class
		);
		return (string) $method( $exporter );
	}

	/**
	 * Build a minimal page-data array suitable for all exporter tests.
	 */
	private function sample_page_data( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'        => 42,
				'title'     => 'Test Page',
				'language'  => 'en',
				'permalink' => 'https://example.com/test',
				'content'   => '<p>Hello world</p>',
			),
			$overrides
		);
	}
}
