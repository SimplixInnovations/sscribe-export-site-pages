<?php
/**
 * PDF exporter for SScribe.
 *
 * @package SScribe
 */

declare(strict_types=1);

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
	 * @var SScribe_Logger_Interface
	 */
	private SScribe_Logger_Interface $logger;

	/**
	 * Filesystem instance.
	 *
	 * @var SScribe_Filesystem
	 */
	private SScribe_Filesystem $filesystem;

	/**
	 * Constructor.
	 *
	 * @param SScribe_HTML_Exporter|null    $html_exporter HTML exporter instance.
	 * @param SScribe_Logger_Interface|null $logger        Logger instance.
	 * @param SScribe_Filesystem|null       $filesystem    Filesystem instance.
	 */
	public function __construct(
		?SScribe_HTML_Exporter $html_exporter = null,
		?SScribe_Logger_Interface $logger = null,
		?SScribe_Filesystem $filesystem = null
	) {
		$this->html_exporter = $html_exporter ?? new SScribe_HTML_Exporter();
		$this->logger        = $logger ?? SScribe_Logger::instance( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
		$this->filesystem    = $filesystem ?? new SScribe_Filesystem();
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
		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-rtl-helper.php';
		require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-image-processor.php';

		$page_id  = $page_data['id'] ?? 0;
		$title    = $page_data['title'] ?? 'Untitled';
		$language = $page_data['language'] ?? 'en';
		$is_rtl   = SScribe_RTL_Helper::is_rtl( $language );

		$processed_page_data = $this->process_images_in_page_data( $page_data );

		$html_result = $this->html_exporter->export( $processed_page_data, $output_dir, $index, $total );

		if ( $html_result->is_failure() ) {
			return $html_result;
		}

		$html_content = $html_result->get_data()['html'] ?? '';

		$dompdf      = null;
		$prev_errors = libxml_use_internal_errors( true );

		try {
			$options = new \SScribeVendor\Dompdf\Options();
			$options->set( 'isRemoteEnabled', true );
			$options->set( 'isHtml5ParserEnabled', true );
			$options->set( 'isFontSubsettingEnabled', true );
			$options->set( 'defaultFont', $is_rtl ? 'Noto Sans Arabic' : 'DejaVu Sans' );
			$options->set( 'chroot', ABSPATH );

			$dompdf = new \SScribeVendor\Dompdf\Dompdf( $options );
			$dompdf->loadHtml( $html_content );
			$dompdf->setPaper( 'A4', 'portrait' );
			$dompdf->render();

			$output = $dompdf->output();

			$filename    = \SScribe_Exporter_Factory::build_filename( $page_data, $index, $total, 'pdf' );
			$output_path = trailingslashit( $output_dir ) . $filename;

			$result = $this->filesystem->put_contents( $output_path, $output );

			if ( ! $result ) {
				$this->logger->error(
					'PDF export failed: filesystem write error',
					array(
						'page_id'   => $page_id,
						'path'      => $output_path,
						'fs_error'  => $this->filesystem->get_last_error(),
						'fs_method' => $this->filesystem->get_method(),
					)
				);

				return SScribe_Result::failure(
					__( 'Failed to write PDF file.', 'sscribe-export-site-pages' ),
					array( 'page_id' => $page_id )
				);
			}

			return SScribe_Result::success(
				array(
					'path' => $output_path,
					'size' => strlen( $output ),
				)
			);

		} catch ( \Throwable $e ) {
			$this->logger->error(
				'PDF generation failed',
				array(
					'error'   => $e->getMessage(),
					'page_id' => $page_id,
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				)
			);

			return SScribe_Result::failure(
				__( 'Unable to generate PDF for this page.', 'sscribe-export-site-pages' ),
				array( 'page_id' => $page_id )
			);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $prev_errors );
			$dompdf = null;
			unset( $dompdf );
		}
	}

	/**
	 * Process images in page data for PDF embedding.
	 *
	 * @param array $page_data The page data array.
	 * @return array Modified page data with local image paths.
	 */
	private function process_images_in_page_data( array $page_data ): array {
		if ( ! empty( $page_data['featured_image_url'] ) ) {
			$local_path = SScribe_Image_Processor::download_and_optimize( $page_data['featured_image_url'] );
			if ( $local_path && file_exists( $local_path ) ) {
				$page_data['featured_image_url'] = $local_path;
			}
		}

		return $page_data;
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
