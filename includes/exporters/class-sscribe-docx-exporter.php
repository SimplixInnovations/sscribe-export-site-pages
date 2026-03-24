<?php
/**
 * DOCX exporter for SScribe.
 *
 * @package SScribe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/interface-sscribe-exporter.php';

/**
 * Class SScribe_DOCX_Exporter
 *
 * Exports pages to Microsoft Word DOCX format.
 */
class SScribe_DOCX_Exporter implements SScribe_Exporter_Interface {

	/**
	 * Original exporter instance (for backward compatibility).
	 *
	 * @var SScribe_Exporter
	 */
	private SScribe_Exporter $exporter;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->exporter = new SScribe_Exporter();
	}

	/**
	 * Export a single page to DOCX.
	 *
	 * @param array  $page_data  Page data from collector.
	 * @param string $output_dir Output directory.
	 * @param int    $index      Page index.
	 * @param int    $total      Total pages.
	 * @return SScribe_Result
	 */
	public function export( array $page_data, string $output_dir, int $index = 0, int $total = 0 ): SScribe_Result {
		$result = $this->exporter->generate_docx( $page_data, $output_dir, $index, $total );

		if ( $result ) {
			return SScribe_Result::success( array( 'path' => $result ) );
		}

		return SScribe_Result::failure(
			$this->exporter->last_error ?: 'Unknown export error',
			array( 'page_id' => $page_data['id'] ?? 0 )
		);
	}

	/**
	 * Get the file extension.
	 *
	 * @return string
	 */
	public function get_extension(): string {
		return 'docx';
	}

	/**
	 * Get the mime type.
	 *
	 * @return string
	 */
	public function get_mime_type(): string {
		return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
	}
}
