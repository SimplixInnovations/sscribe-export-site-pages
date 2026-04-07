<?php
/**
 * Streaming DOCX generator for SScribe.
 *
 * Memory-efficient DOCX generation for large exports.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/interface-sscribe-exporter.php';

/**
 * Class SScribe_Streaming_DOCX_Generator
 *
 * Generates DOCX files with streaming approach to reduce memory footprint.
 */
class SScribe_Streaming_DOCX_Generator {

	/**
	 * Temporary directory for document parts.
	 *
	 * @var string
	 */
	private string $temp_dir;

	/**
	 * Document content XML buffer.
	 *
	 * @var string
	 */
	private string $document_xml;

	/**
	 * Number of sections added.
	 *
	 * @var int
	 */
	private int $section_count = 0;

	/**
	 * Memory threshold for flush (bytes).
	 *
	 * @var int
	 */
	private int $memory_threshold;

	/**
	 * Logger instance.
	 *
	 * @var SScribe_Logger_Interface
	 */
	private SScribe_Logger_Interface $logger;

	/**
	 * Constructor.
	 *
	 * @param string|null                   $temp_dir        Temporary directory.
	 * @param int                           $memory_threshold Memory threshold in MB before flush.
	 * @param SScribe_Logger_Interface|null $logger         Logger instance.
	 */
	public function __construct(
		?string $temp_dir = null,
		int $memory_threshold = 50,
		?SScribe_Logger_Interface $logger = null
	) {
		$this->temp_dir         = $temp_dir ?? sys_get_temp_dir() . '/sscribe_stream_' . uniqid();
		$this->memory_threshold = $memory_threshold * 1024 * 1024;
		$this->logger           = $logger ?? SScribe_Logger::instance( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
		$this->document_xml     = '';

		$this->initialize();
	}

	/**
	 * Initialize the streaming document.
	 */
	private function initialize(): void {
		if ( ! is_dir( $this->temp_dir ) ) {
			wp_mkdir_p( $this->temp_dir );
		}

		$this->document_xml = $this->get_document_header();
	}

	/**
	 * Get document.xml header.
	 */
	private function get_document_header(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
            xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
            xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
            xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
            xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
<w:body>';
	}

	/**
	 * Get document.xml footer.
	 */
	private function get_document_footer(): string {
		return '<w:sectPr>
<w:pgSz w:w="12240" w:h="15840"/>
<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/>
</w:sectPr>
</w:body>
</w:document>';
	}

	/**
	 * Add a page as a section.
	 *
	 * @param array $page_data Page data from collector.
	 * @return bool Success.
	 */
	public function add_page( array $page_data ): bool {
		$title   = $page_data['title'] ?? 'Untitled';
		$content = $page_data['content'] ?? '';
		$url     = $page_data['url'] ?? '';

		$section_xml         = $this->build_page_section( $title, $content, $url );
		$this->document_xml .= $section_xml;
		++$this->section_count;

		$this->check_memory_and_flush();

		return true;
	}

	/**
	 * Build XML for a page section.
	 *
	 * @param string $title   Page title.
	 * @param string $content Page content.
	 * @param string $url     Page URL.
	 * @return string XML content.
	 */
	private function build_page_section( string $title, string $content, string $url ): string {
		$xml = '';

		if ( $this->section_count > 0 ) {
			$xml .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
		}

		$xml .= $this->build_heading( $title, 1 );

		if ( ! empty( $url ) ) {
			$xml .= $this->build_paragraph( $url, true );
		}

		$xml .= $this->convert_html_to_docx( $content );

		return $xml;
	}

	/**
	 * Build a heading element.
	 *
	 * @param string $text Heading text.
	 * @param int    $level Heading level (1-6).
	 * @return string XML.
	 */
	private function build_heading( string $text, int $level = 1 ): string {
		$style = 'Heading' . min( max( $level, 1 ), 6 );

		return sprintf(
			'<w:p><w:pPr><w:pStyle w:val="%s"/></w:pPr><w:r><w:t>%s</w:t></w:r></w:p>',
			$style,
			$this->escape_xml( $text )
		);
	}

	/**
	 * Build a paragraph element.
	 *
	 * @param string $text     Paragraph text.
	 * @param bool   $is_url   Whether text is a URL.
	 * @return string XML.
	 */
	private function build_paragraph( string $text, bool $is_url = false ): string {
		if ( $is_url ) {
			return sprintf(
				'<w:p><w:r><w:rPr><w:color w:val="0000FF"/><w:u w:val="single"/></w:rPr><w:t>%s</w:t></w:r></w:p>',
				$this->escape_xml( $text )
			);
		}

		return sprintf(
			'<w:p><w:r><w:t>%s</w:t></w:r></w:p>',
			$this->escape_xml( $text )
		);
	}

	/**
	 * Convert HTML content to DOCX XML.
	 *
	 * @param string $html HTML content.
	 * @return string DOCX XML.
	 */
	private function convert_html_to_docx( string $html ): string {
		$html = wp_strip_all_tags( $html, false );

		$paragraphs = preg_split( '/\n\s*\n/', $html );
		$xml        = '';

		foreach ( $paragraphs as $paragraph ) {
			$paragraph = trim( $paragraph );
			if ( ! empty( $paragraph ) ) {
				$xml .= $this->build_paragraph( $paragraph );
			}
		}

		return $xml;
	}

	/**
	 * Escape XML special characters.
	 *
	 * @param string $text Text to escape.
	 * @return string Escaped text.
	 */
	private function escape_xml( string $text ): string {
		return htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Check memory usage and flush to disk if needed.
	 */
	private function check_memory_and_flush(): void {
		$memory_usage = memory_get_usage( true );

		if ( $memory_usage > $this->memory_threshold ) {
			$this->flush_to_disk();
		}
	}

	/**
	 * Flush current document XML to disk.
	 */
	private function flush_to_disk(): void {
		$chunk_file = $this->temp_dir . '/chunk_' . $this->section_count . '.xml';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $chunk_file, $this->document_xml );
		$this->document_xml = '';
		$this->logger->debug(
			'Flushed DOCX chunk to disk',
			array(
				'chunk_file'    => $chunk_file,
				'memory_usage'  => size_format( memory_get_usage( true ) ),
				'section_count' => $this->section_count,
			)
		);
	}

	/**
	 * Force flush current buffer to disk (for AJAX batch persistence).
	 *
	 * @return void
	 */
	public function flush(): void {
		if ( ! empty( $this->document_xml ) ) {
			$this->flush_to_disk();
		}
	}

	/**
	 * Get the temporary directory path (for session persistence).
	 *
	 * @return string
	 */
	public function get_temp_dir(): string {
		return $this->temp_dir;
	}

	/**
	 * Get current section count (for session persistence).
	 *
	 * @return int
	 */
	public function get_section_count(): int {
		return $this->section_count;
	}

	/**
	 * Set section count (for resuming from session).
	 *
	 * @param int $count Section count to restore.
	 * @return void
	 */
	public function set_section_count( int $count ): void {
		$this->section_count = $count;
	}

	/**
	 * Finalize and save the DOCX file.
	 *
	 * @param string $output_path Output file path.
	 * @return bool Success.
	 */
	public function save( string $output_path ): bool {
		$this->document_xml .= $this->get_document_footer();

		$final_xml = $this->reassemble_chunks();

		$document_dir = $this->temp_dir . '/word';
		wp_mkdir_p( $document_dir );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $document_dir . '/document.xml', $final_xml );

		$this->create_content_types();
		$this->create_rels();

		$result = $this->create_zip( $output_path );

		$this->cleanup();

		return $result;
	}

	/**
	 * Reassemble chunked content.
	 *
	 * @return string Complete document XML.
	 */
	private function reassemble_chunks(): string {
		$chunks = glob( $this->temp_dir . '/chunk_*.xml' );

		if ( empty( $chunks ) ) {
			return $this->document_xml;
		}

		$combined = '';
		natsort( $chunks );

		foreach ( $chunks as $chunk ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$combined .= file_get_contents( $chunk );
		}

		$combined .= $this->document_xml;

		return $combined;
	}

	/**
	 * Create [Content_Types].xml.
	 */
	private function create_content_types(): void {
		$content = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->temp_dir . '/[Content_Types].xml', $content );
	}

	/**
	 * Create _rels/.rels file.
	 */
	private function create_rels(): void {
		$rels_dir = $this->temp_dir . '/_rels';
		wp_mkdir_p( $rels_dir );

		$content = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $rels_dir . '/.rels', $content );
	}

	/**
	 * Create the final ZIP file.
	 *
	 * @param string $output_path Output path.
	 * @return bool Success.
	 */
	private function create_zip( string $output_path ): bool {
		$zip = new ZipArchive();

		if ( true !== $zip->open( $output_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			$this->logger->error(
				'Failed to create ZIP archive',
				array(
					'output_path' => $output_path,
				)
			);
			return false;
		}

		$temp_dir_normalized = rtrim( str_replace( '\\', '/', $this->temp_dir ), '/' );

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->temp_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isFile() ) {
				$file_path           = $item->getRealPath();
				$file_path_normalized= str_replace( '\\', '/', $file_path );
				$relative            = str_replace( $temp_dir_normalized . '/', '', $file_path_normalized );
				$zip->addFile( $file_path, $relative );
			}
		}

		$zip->close();
		$zip = null;
		unset( $zip );

		return file_exists( $output_path );
	}

	/**
	 * Clean up temporary files.
	 */
	private function cleanup(): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->temp_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				rmdir( $item->getRealPath() );
			} else {
				wp_delete_file( $item->getRealPath() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $this->temp_dir );
	}

	/**
	 * Get memory usage statistics.
	 *
	 * @return array Memory stats.
	 */
	public function get_memory_stats(): array {
		return array(
			'current'   => size_format( memory_get_usage( true ) ),
			'peak'      => size_format( memory_get_peak_usage( true ) ),
			'sections'  => $this->section_count,
			'threshold' => size_format( $this->memory_threshold ),
		);
	}
}
