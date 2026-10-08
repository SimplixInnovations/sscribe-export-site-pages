<?php
declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Custom_Fields_Rendering_Test extends TestCase {

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

	public function test_html_export_renders_an_escaped_fields_table(): void {
		$html = ( new \SScribe_HTML_Exporter() )->generate_html_string( $this->page_data() );

		$this::assertStringContainsString( '<section class="fields">', $html );
		$this::assertStringContainsString( '<th scope="row">Event date</th><td>2026-10-09</td>', $html );
		$this::assertStringContainsString( 'Ann<br />', $html );
		$this::assertStringContainsString( 'Bob Smith', $html );
		$this::assertStringNotContainsString( '<b>Smith', $html, 'Field values are plain text, never injected as markup.' );
	}

	public function test_html_export_omits_the_section_without_fields(): void {
		$page_data = $this->page_data();
		unset( $page_data['fields'] );

		$this::assertStringNotContainsString( 'class="fields"', ( new \SScribe_HTML_Exporter() )->generate_html_string( $page_data ) );
	}

	public function test_html_export_renders_provenance_in_the_footer(): void {
		$page_data               = $this->page_data();
		$page_data['provenance'] = array(
			'source_url'     => 'https://example.org/fields-page/',
			'post_id'        => 42,
			'modified_utc'   => '2026-10-09T10:00:00Z',
			'content_sha256' => str_repeat( 'a', 64 ),
			'exported_utc'   => '2026-10-09T11:00:00Z',
			'exported_by'    => 'auditor',
			'site_url'       => 'https://example.org/',
			'plugin_version' => '2.0.0',
		);

		$html = ( new \SScribe_HTML_Exporter() )->generate_html_string( $page_data );

		$this::assertStringContainsString( '<section class="provenance">', $html );
		$this::assertStringContainsString( '<dt>Exported by</dt><dd>auditor</dd>', $html );
		$this::assertStringContainsString( str_repeat( 'a', 64 ), $html );
		$this::assertLessThan( (int) strpos( $html, 'class="provenance"' ), (int) strpos( $html, 'Body text' ), 'Provenance sits in the footer, after the content.' );
	}
}
