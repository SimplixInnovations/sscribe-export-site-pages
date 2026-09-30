<?php
/**
 * PDF exporter regressions for CSS lengths the PDF engine cannot parse.
 *
 * The bundled engine throws "Invalid value: auto" for width/height values
 * with no number in them. SScribe's own stylesheet used img { height: auto },
 * so every page with an image lost its PDF.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SScribe_PDF_Exporter_Non_Numeric_Length_Test extends TestCase {

	private \SScribe_PDF_Exporter $exporter;
	private ReflectionClass $reflection;

	/** @var string[] */
	private array $cleanup = array();

	protected function setUp(): void {
		parent::setUp();
		$this->exporter   = new \SScribe_PDF_Exporter();
		$this->reflection = new ReflectionClass( $this->exporter );
	}

	protected function tearDown(): void {
		foreach ( $this->cleanup as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
		parent::tearDown();
	}

	private function call_private( string $method, array $args = array() ): mixed {
		return $this->reflection->getMethod( $method )->invokeArgs( $this->exporter, $args );
	}

	private function make_png( int $width, int $height ): string {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this::markTestSkipped( 'GD is required to create a test image.' );
		}
		$path  = tempnam( sys_get_temp_dir(), 'sscribe-img-' ) . '.png';
		$image = imagecreatetruecolor( $width, $height );
		imagepng( $image, $path );
		$this->cleanup[] = $path;
		return $path;
	}

	public function test_style_block_drops_lengths_without_a_number(): void {
		$html = '<style>img{max-width:100%;height:auto;} .a{width: fit-content !important; color:red} .b{border-left:1px solid #000;margin:0 auto;height:20px}</style><p>x</p>';

		$result = str_replace( ' ', '', (string) $this->call_private( 'prepare_html_for_pdf_engine', array( $html, false ) ) );

		$this::assertStringNotContainsString( 'height:auto', $result );
		$this::assertStringNotContainsString( 'fit-content', $result );
		$this::assertStringContainsString( 'max-width:100%', $result );
		$this::assertStringContainsString( 'color:red', $result );
		$this::assertStringContainsString( 'border-left:1pxsolid#000', $result );
		$this::assertStringContainsString( 'margin:0auto', $result );
		$this::assertStringContainsString( 'height:20px', $result );
	}

	public function test_inline_style_drops_lengths_without_a_number(): void {
		$result = (string) $this->call_private( 'filter_style_attribute', array( 'width:auto;height:inherit;max-width:100%;min-height:12px;color:blue', false ) );

		$this::assertSame( 'max-width:100%;min-height:12px;color:blue;', $result );
	}

	public function test_width_and_height_attributes_without_a_number_are_removed(): void {
		$html = '<img src="a.png" width="auto" height=\'auto\'><td width="300">x</td>';

		$result = (string) $this->call_private( 'prepare_html_for_pdf_engine', array( $html, false ) );

		$this::assertStringNotContainsString( 'auto', $result );
		$this::assertStringContainsString( 'width="300"', $result );
	}

	public function test_wide_image_is_fitted_to_the_text_column(): void {
		$method = $this->reflection->getMethod( 'fit_image_tag_to_page' );

		$wide = $method->invoke( null, '<img src="x" width="1200" height="600" alt="a">', $this->make_png( 1200, 600 ) );
		$this::assertSame( '<img width="100%" src="x" alt="a">', $wide );

		$small = '<img src="y" width="200" alt="b">';
		$this::assertSame( $small, $method->invoke( null, $small, $this->make_png( 200, 100 ) ) );
	}

	public function test_pdf_base_stylesheet_never_uses_height_auto(): void {
		$source = (string) file_get_contents( SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-pdf-exporter.php' );

		$this::assertDoesNotMatchRegularExpression( '/\$base_css\s*\.?=\s*\'[^\']*height:\s*auto/i', $source );
	}

	public function test_strip_author_styles_removes_every_style_and_size(): void {
		$html = '<style>p{height:auto}</style><p style="height:auto" width="auto">Text</p>';

		$result = (string) $this->call_private( 'strip_author_styles', array( $html ) );

		$this::assertSame( '<p>Text</p>', $result );
	}

	public function test_page_with_auto_lengths_renders_a_pdf(): void {
		if ( ! class_exists( '\\SScribeVendor_TCPDF' ) ) {
			$this::markTestSkipped( 'The PDF engine is not installed in this environment.' );
		}
		$dir = \SScribe_Private_Storage::get_subdirectory( 'sscribe-pdf-auto-' . uniqid() );

		$result = $this->exporter->export(
			array(
				'id'      => 5,
				'title'   => 'Auto lengths',
				'content' => '<style>.box{height:auto;width:auto}</style><div class="box" style="height:auto;width:fit-content">Hello</div><table width="auto"><tr><td style="width:auto">Cell</td></tr></table>',
				'language' => 'en',
			),
			$dir,
			1,
			1
		);

		$this::assertTrue( $result->is_success(), (string) $result->get_error() );
		$data = $result->get_data();
		$this->cleanup[] = (string) $data['path'];
		$this::assertGreaterThan( 0, (int) $data['size'] );
	}

	/**
	 * The engine only read local files from the temp dir, its own folder and
	 * the running script's folder. On a web request that is wp-admin/, so
	 * every image in uploads was silently left out of browser-made PDFs.
	 */
	public function test_engine_may_read_images_from_uploads_and_image_staging(): void {
		$uploads = (string) realpath( (string) wp_upload_dir()['basedir'] );
		$staging = (string) realpath( \SScribe_Private_Storage::get_subdirectory( 'image-staging' ) );

		$paths = \SScribe_PDF_Exporter::add_image_read_paths( array( '/already/allowed' ) );

		$this::assertContains( '/already/allowed', $paths );
		$this::assertContains( $uploads, $paths );
		$this::assertContains( $staging, $paths );
	}

	public function test_pdf_document_allowlist_includes_uploads(): void {
		if ( ! class_exists( '\\SScribeVendor_TCPDF' ) ) {
			$this::markTestSkipped( 'The PDF engine is not installed in this environment.' );
		}
		$document = $this->call_private( 'create_tcpdf_document', array( false ) );
		$allowed  = ( new \ReflectionMethod( $document, 'fileAllowedPaths' ) )->invoke( $document );

		$this::assertContains( (string) realpath( (string) wp_upload_dir()['basedir'] ), $allowed );
	}

	public function test_rtl_links_become_styled_text_and_details_become_paragraphs(): void {
		$html = '<dl class="meta"><dt>Author</dt><dd>admin</dd></dl><p>زوروا <a href="https://example.com">موقعنا</a> اليوم.</p>';

		$rtl = (string) $this->call_private( 'prepare_html_for_pdf_engine', array( $html, true ) );

		$this::assertStringNotContainsString( '<a ', $rtl );
		$this::assertStringContainsString( '<span style="color:#2C6E8A;text-decoration:underline">موقعنا</span>', $rtl );
		$this::assertStringContainsString( '<p><strong>Author</strong></p><p>admin</p>', $rtl );

		$ltr = (string) $this->call_private( 'prepare_html_for_pdf_engine', array( $html, false ) );
		$this::assertStringContainsString( '<a href="https://example.com">', $ltr );
		$this::assertStringContainsString( '<dd>admin</dd>', $ltr );
	}
}
