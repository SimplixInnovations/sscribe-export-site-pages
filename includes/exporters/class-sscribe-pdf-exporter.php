<?php
/**
 * PDF exporter for SScribe.
 *
 * @package SScribe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/interface-sscribe-exporter.php';

/**
 * Class SScribe_PDF_Exporter
 *
 * Exports pages to PDF format using DomPDF.
 */
class SScribe_PDF_Exporter implements SScribe_Exporter_Interface {

	/**
	 * HTML exporter instance.
	 *
	 * @var SScribe_HTML_Exporter
	 */
	private SScribe_HTML_Exporter $html_exporter;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->html_exporter = new SScribe_HTML_Exporter();
	}

	/**
	 * Export a single page to PDF.
	 *
	 * @param array  $page_data  Page data from collector.
	 * @param string $output_dir Output directory.
	 * @param int    $index      Page index.
	 * @param int    $total      Total pages.
	 * @return SScribe_Result
	 */
	public function export( array $page_data, string $output_dir, int $index = 0, int $total = 0 ): SScribe_Result {
		$html_result = $this->html_exporter->export( $page_data, $output_dir, $index, $total );

		if ( $html_result->is_failure() ) {
			return $html_result;
		}

		$html_content = $html_result->get_data()['html'] ?? '';

		try {
			$options = new \Dompdf\Options();
			$options->set( 'isRemoteEnabled', true );
			$options->set( 'isHtml5ParserEnabled', true );
			$options->set( 'defaultFont', 'Arial' );

			$dompdf = new \Dompdf\Dompdf( $options );
			$dompdf->loadHtml( $html_content );
			$dompdf->setPaper( 'A4', 'portrait' );
			$dompdf->render();

			$output = $dompdf->output();

			$filename    = $this->build_filename( $page_data, $index, $total );
			$output_path = trailingslashit( $output_dir ) . $filename;

			file_put_contents( $output_path, $output );

			return SScribe_Result::success( array(
				'path' => $output_path,
				'size' => strlen( $output ),
			) );

		} catch ( \Throwable $e ) {
			return SScribe_Result::failure(
				'PDF generation failed: ' . $e->getMessage(),
				array( 'page_id' => $page_data['id'] ?? 0 )
			);
		}
	}

	/**
	 * Build filename for the PDF.
	 *
	 * @param array $page_data Page data.
	 * @param int   $index     Page index.
	 * @param int   $total     Total pages.
	 * @return string
	 */
	private function build_filename( array $page_data, int $index, int $total ): string {
		if ( $index > 0 && $total > 0 ) {
			$pad_length = strlen( (string) $total );
			$seq_prefix = str_pad( (string) $index, $pad_length, '0', STR_PAD_LEFT );
		} else {
			$seq_prefix = (string) $page_data['id'];
		}

		$title = sanitize_file_name( $page_data['title'] );
		$title = substr( $title, 0, 60 );
		$title = trim( $title, '-' );

		if ( empty( $title ) ) {
			$title = 'page-' . $page_data['id'];
		}

		return $seq_prefix . '-' . $title . '.pdf';
	}

	/**
	 * Get the file extension.
	 *
	 * @return string
	 */
	public function get_extension(): string {
		return 'pdf';
	}

	/**
	 * Get the mime type.
	 *
	 * @return string
	 */
	public function get_mime_type(): string {
		return 'application/pdf';
	}
}
