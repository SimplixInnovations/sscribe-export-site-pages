<?php
/**
 * Text-only DOCX template tests.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Content_Parser;
use SScribe_Exporter;

final class SScribe_DOCX_Translation_Template_Test extends TestCase {
	private string $temp_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = \SScribe_Private_Storage::get_subdirectory( 'test-translation-' . uniqid() );
		if ( ! is_dir( $this->temp_dir ) ) {
			mkdir( $this->temp_dir, 0700, true );
		}
	}

	protected function tearDown(): void {
		foreach ( glob( $this->temp_dir . '/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		@rmdir( $this->temp_dir );
		parent::tearDown();
	}

	private function page(): array {
		return array(
			'id'                 => 31,
			'title'              => 'Translate me',
			'slug'               => 'translate-me',
			'permalink'          => 'https://example.test/translate-me/',
			'content'            => '<h2>Section</h2><p>Body <strong>text</strong> for translators.</p>'
				. '<img src="https://example.test/wp-content/uploads/photo.jpg" alt="Photo alt">'
				. '<figure><img src="https://example.test/wp-content/uploads/fig.jpg" alt="Figure"><figcaption>A caption to translate</figcaption></figure>'
				. '<a class="wp-block-button__link" href="https://example.test/apply/">Apply now</a>'
				. '<table><tr><td>Cell one</td><td>Cell two</td></tr></table>',
			'author'             => 'Jane',
			'date_published'     => '2026-10-01',
			'date_modified'      => '2026-10-07',
			'word_count'         => 12,
			'reading_time'       => 1,
			'language'           => 'en',
			'featured_image_url' => 'https://example.test/wp-content/uploads/hero.jpg',
			'breadcrumbs'        => array( array( 'title' => 'Home', 'url' => 'https://example.test/' ), array( 'title' => 'Translate me', 'url' => '' ) ),
			'seo'                => array( 'meta_description' => 'SEO description' ),
		);
	}

	private function document_xml( string $path ): string {
		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $path ) );
		$xml = (string) $zip->getFromName( 'word/document.xml' );
		$zip->close();
		return $xml;
	}

	public function test_translation_template_keeps_text_and_drops_images_layout_and_metadata(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}
		$exporter = new SScribe_Exporter( new SScribe_Content_Parser() );
		$exporter->set_format_options( array( 'sscribe_docx_template' => SScribe_Exporter::TEMPLATE_TRANSLATION ) );

		$path = $exporter->generate_docx( $this->page(), $this->temp_dir, 1, 1 );
		$this->assertIsString( $path );
		$xml = $this->document_xml( $path );

		$this->assertStringContainsString( 'Translate me', $xml );
		$this->assertStringContainsString( 'https://example.test/translate-me/', $xml );
		$this->assertStringContainsString( 'Section', $xml );
		$this->assertStringContainsString( 'for translators.', $xml );
		$this->assertStringContainsString( 'A caption to translate', $xml, 'Figure captions are text and stay.' );
		$this->assertStringContainsString( 'Apply now (https://example.test/apply/)', $xml, 'Buttons become plain text.' );
		$this->assertStringContainsString( 'Cell one', $xml, 'Tables carry text and stay.' );

		$this->assertStringNotContainsString( '<w:drawing', $xml, 'No images are embedded.' );
		$this->assertStringNotContainsString( 'MISSING IMAGE', $xml );
		$this->assertStringNotContainsString( 'ACTION BUTTON', $xml );
		$this->assertStringNotContainsString( 'Page Information', $xml );
		$this->assertStringNotContainsString( 'SEO Information', $xml );
		$this->assertStringNotContainsString( 'SEO description', $xml );
		$this->assertStringNotContainsString( 'Reading time', $xml );
		$this->assertStringNotContainsString( 'TOC', $xml );
		$this->assertStringNotContainsString( 'Home', $xml, 'Breadcrumbs are navigation, not content.' );
	}

	public function test_default_template_still_renders_layout(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available' );
		}
		$exporter = new SScribe_Exporter( new SScribe_Content_Parser() );

		$path = $exporter->generate_docx( $this->page(), $this->temp_dir, 1, 1 );
		$this->assertIsString( $path );
		$xml = $this->document_xml( $path );

		$this->assertStringContainsString( 'Page Information', $xml );
		$this->assertStringContainsString( 'ACTION BUTTON', $xml );
	}
}
