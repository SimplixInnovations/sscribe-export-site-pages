<?php
/**
 * SScribe DOCX Exporter
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/interface-sscribe-exporter.php';

/**
 * Exports pages as Word (DOCX) documents.
 */
class SScribe_DOCX_Exporter implements SScribe_Exporter_Interface {

	/**
	 * Core exporter instance.
	 *
	 * @var SScribe_Exporter
	 */
	private SScribe_Exporter $exporter;

	/**
	 * Logger instance.
	 *
	 * @var SScribe_Logger_Interface
	 */
	private SScribe_Logger_Interface $logger;

	/**
	 * Initialize the DOCX exporter.
	 *
	 * @param SScribe_Exporter|null         $exporter Core exporter.
	 * @param SScribe_Logger_Interface|null $logger   Logger.
	 */
	public function __construct(
		?SScribe_Exporter $exporter = null,
		?SScribe_Logger_Interface $logger = null
	) {
		$this->exporter = $exporter ?? new SScribe_Exporter();
		$this->logger   = $logger ?? SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() );
	}

	/**
	 * Export a page as a DOCX file.
	 *
	 * @param array  $page_data Page data to export.
	 * @param string $output_dir Output directory path.
	 * @param int    $index     Current page index.
	 * @param int    $total     Total number of pages.
	 * @return SScribe_Result Result of the export operation.
	 */
	public function export( array $page_data, string $output_dir, int $index = 0, int $total = 0 ): SScribe_Result {
		$page_id = $page_data['id'] ?? 0;

		try {
			$result = $this->exporter->generate_docx( $page_data, $output_dir, $index, $total );

			if ( $result ) {
				return SScribe_Result::success(
					array(
						'path'         => $result,
						'page_id'      => $page_id,
						'memory_after' => size_format( memory_get_usage( true ) ),
					)
				);
			}

			$last_error     = $this->exporter->get_last_error();
			$memory_context = sprintf(
				'Memory: %s / %s',
				size_format( memory_get_usage( true ) ),
				ini_get( 'memory_limit' )
			);

			$error_message = $last_error
				? $last_error
				: sprintf( 'Unknown export error. %s', $memory_context );

			$this->logger->error(
				'DOCX export failed',
				array(
					'page_id'      => $page_id,
					'error'        => $error_message,
					'memory_usage' => size_format( memory_get_usage( true ) ),
					'memory_peak'  => size_format( memory_get_peak_usage( true ) ),
					'memory_limit' => ini_get( 'memory_limit' ),
				)
			);

			return SScribe_Result::failure(
				$error_message,
				array(
					'page_id'      => $page_id,
					'memory_usage' => size_format( memory_get_usage( true ) ),
				)
			);

		} catch ( \Throwable $e ) {
			$exception_class = (string) get_class( $e );
			$raw_message     = $e->getMessage();

			$display_message = ! empty( $raw_message )
				? sprintf( '%s: %s', $exception_class, $raw_message )
				: sprintf( '%s (no message)', $exception_class );

			$this->logger->error(
				'DOCX export crashed',
				array(
					'page_id' => $page_id,
					'error'   => $display_message,
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				)
			);

			return SScribe_Result::failure(
				sprintf(
					/* translators: 1: Page ID, 2: Error message. */

					__( 'DOCX export failed for page %1$d: %2$s', 'sscribe-export-site-pages' ),
					$page_id,
					$display_message
				),
				array( 'page_id' => $page_id )
			);
		}
	}

	/**
	 * Get the file extension for DOCX files.
	 *
	 * @return string
	 */
	public function get_extension(): string {
		return 'docx';
	}

	/**
	 * Get the MIME type for DOCX files.
	 *
	 * @return string
	 */
	public function get_mime_type(): string {
		return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
	}
}
