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
	 * Logger instance.
	 *
	 * @var SScribe_Logger
	 */
	private SScribe_Logger $logger;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->html_exporter = new SScribe_HTML_Exporter();
		$this->logger        = new SScribe_Logger( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
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
			$options->set( 'isRemoteEnabled', false );
			$options->set( 'isHtml5ParserEnabled', true );
			$options->set( 'defaultFont', 'Arial' );
			$options->set( 'chroot', WP_CONTENT_DIR );

			$dompdf = new \Dompdf\Dompdf( $options );
			$dompdf->loadHtml( $html_content );
			$dompdf->setPaper( 'A4', 'portrait' );
			$dompdf->render();

			$output = $dompdf->output();

			$filename    = \SScribe_Exporter_Factory::build_filename( $page_data, $index, $total, 'pdf' );
			$output_path = trailingslashit( $output_dir ) . $filename;

			file_put_contents( $output_path, $output );

			return SScribe_Result::success( array(
				'path' => $output_path,
				'size' => strlen( $output ),
			) );

		} catch ( \Throwable $e ) {
			$this->logger->error( 'PDF generation failed', array(
				'error'   => $e->getMessage(),
				'page_id' => $page_data['id'] ?? 0,
				'file'    => $e->getFile(),
				'line'    => $e->getLine(),
			) );

			return SScribe_Result::failure(
				__( 'Unable to generate PDF for this page.', 'sscribe-export-site-pages' ),
				array( 'page_id' => $page_data['id'] ?? 0 )
			);
		}
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
