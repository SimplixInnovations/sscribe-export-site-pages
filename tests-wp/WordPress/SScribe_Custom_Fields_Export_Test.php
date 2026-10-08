<?php
/**
 * Custom fields reach every written format.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

require_once __DIR__ . '/SScribe_WP_TestCase.php';

final class SScribe_Custom_Fields_Export_Test extends SScribe_WP_TestCase {

	private string $output_dir = '';

	public function set_up(): void {
		parent::set_up();
		SScribe_Activator::activate( false );
		$prefixed_autoload = SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/autoload.php';
		if ( file_exists( $prefixed_autoload ) && ! class_exists( '\\SScribeVendor_TCPDF', false ) ) {
			require_once $prefixed_autoload;
		}
		$this->output_dir = \SScribe_Private_Storage::get_subdirectory( 'fields-' . bin2hex( random_bytes( 4 ) ) );
		$this::assertNotSame( '', $this->output_dir );
	}

	public function tear_down(): void {
		if ( '' !== $this->output_dir && is_dir( $this->output_dir ) ) {
			foreach ( glob( $this->output_dir . '/*' ) ?: array() as $leftover ) {
				@unlink( $leftover ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			@rmdir( $this->output_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		parent::tear_down();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function page_data(): array {
		return array(
			'id'             => 42,
			'title'          => 'Fields page',
			'content'        => '<p>Body text</p>',
			'permalink'      => 'https://example.org/fields-page/',
			'language'       => 'en',
			'author'         => 'Pat',
			'date_published' => 'October 9, 2026',
			'date_modified'  => 'October 9, 2026',
			'word_count'     => 2,
			'reading_time'   => 1,
			'fields'         => array(
				array( 'key' => 'event_date', 'label' => 'Event date', 'type' => 'meta', 'value' => '2026-10-09', 'source' => 'meta' ),
				array( 'key' => 'speakers', 'label' => 'Speakers | Hosts', 'type' => 'checkbox', 'value' => array( 'Ann', 'Bob <b>Smith</b>' ), 'source' => 'acf' ),
			),
		);
	}

	public function test_markdown_export_writes_a_fields_table_before_the_body(): void {
		$exporter = new SScribe_Markdown_Exporter();
		$exporter->apply_format_options( array( 'sscribe_md_include_frontmatter' => '0' ) );

		$result = $exporter->export( $this->page_data(), $this->output_dir, 1, 1 );
		$this::assertTrue( $result->is_success(), 'Markdown export must succeed: ' . (string) $result->get_error() );

		$md = (string) file_get_contents( (string) $result->get_data()['path'] );
		$this::assertStringContainsString( "## Fields\n\n| Field | Value |\n| --- | --- |\n", $md );
		$this::assertStringContainsString( '| Event date | 2026-10-09 |', $md );
		$this::assertStringContainsString( '| Speakers \| Hosts | Ann<br>Bob Smith |', $md );
		$this::assertLessThan( (int) strpos( $md, 'Body text' ), (int) strpos( $md, '## Fields' ) );
	}

	public function test_docx_export_includes_the_field_labels_and_values(): void {
		$result = ( new SScribe_DOCX_Exporter() )->export( $this->page_data(), $this->output_dir, 1, 1 );
		$this::assertTrue( $result->is_success(), 'DOCX export must succeed: ' . (string) $result->get_error() );

		$zip = new ZipArchive();
		$this::assertTrue( $zip->open( (string) $result->get_data()['path'] ) );
		$document = (string) $zip->getFromName( 'word/document.xml' );
		$zip->close();

		$this::assertStringContainsString( 'Event date', $document );
		$this::assertStringContainsString( '2026-10-09', $document );
		$this::assertStringContainsString( 'Bob Smith', $document );
		$this::assertStringNotContainsString( '<b>Smith', $document );
	}

	public function test_pdf_export_succeeds_with_fields_present(): void {
		$result = ( new SScribe_PDF_Exporter() )->export( $this->page_data(), $this->output_dir, 1, 1 );
		$this::assertTrue( $result->is_success(), 'PDF export must succeed: ' . (string) $result->get_error() );
		$this::assertStringStartsWith( '%PDF', (string) file_get_contents( (string) $result->get_data()['path'], false, null, 0, 4 ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function page_data_with_provenance(): array {
		$page_data               = $this->page_data();
		$page_data['provenance'] = array(
			'source_url'     => 'https://example.org/fields-page/',
			'post_id'        => 42,
			'modified_utc'   => '2026-10-09T10:00:00Z',
			'content_sha256' => str_repeat( 'b', 64 ),
			'exported_utc'   => '2026-10-09T11:00:00Z',
			'exported_by'    => 'auditor',
			'site_url'       => 'https://example.org/',
			'plugin_version' => '2.0.0',
		);
		return $page_data;
	}

	public function test_markdown_export_writes_provenance_after_the_body_and_in_front_matter(): void {
		$result = ( new SScribe_Markdown_Exporter() )->export( $this->page_data_with_provenance(), $this->output_dir, 1, 1 );
		$this::assertTrue( $result->is_success(), (string) $result->get_error() );

		$md = (string) file_get_contents( (string) $result->get_data()['path'] );
		$this::assertStringContainsString( 'exported_by: "auditor"', $md );
		$this::assertStringContainsString( 'content_sha256: "' . str_repeat( 'b', 64 ) . '"', $md );
		$this::assertStringContainsString( "## Provenance\n\n| | |\n| --- | --- |\n| Source URL | https://example.org/fields-page/ |", $md );
		$this::assertGreaterThan( (int) strpos( $md, 'Body text' ), (int) strpos( $md, '## Provenance' ) );
	}

	public function test_docx_export_includes_the_provenance_table(): void {
		$result = ( new SScribe_DOCX_Exporter() )->export( $this->page_data_with_provenance(), $this->output_dir, 1, 1 );
		$this::assertTrue( $result->is_success(), (string) $result->get_error() );

		$zip = new ZipArchive();
		$this::assertTrue( $zip->open( (string) $result->get_data()['path'] ) );
		$document = (string) $zip->getFromName( 'word/document.xml' );
		$zip->close();

		$this::assertStringContainsString( 'Provenance', $document );
		$this::assertStringContainsString( 'auditor', $document );
		$this::assertStringContainsString( str_repeat( 'b', 64 ), $document );
	}
}
