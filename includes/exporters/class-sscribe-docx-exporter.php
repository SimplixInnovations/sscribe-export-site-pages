<?php
/**
 * DOCX exporter for SScribe.
 *
 * @package SScribe
 */

declare(strict_types=1);

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
	 * Logger instance.
	 *
	 * @var SScribe_Logger_Interface
	 */
	private SScribe_Logger_Interface $logger;

	/**
	 * Constructor.
	 *
	 * @param SScribe_Exporter|null         $exporter DOCX exporter instance.
	 * @param SScribe_Logger_Interface|null $logger   Logger instance.
	 */
	public function __construct(
		?SScribe_Exporter $exporter = null,
		?SScribe_Logger_Interface $logger = null
	) {
		$this->exporter = $exporter ?? new SScribe_Exporter();
		$this->logger   = $logger ?? SScribe_Logger::instance( defined( 'SSCRIBE_DEBUG' ) && SSCRIBE_DEBUG );
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
		$page_id = $page_data['id'] ?? 0;

		try {
			$result = $this->exporter->generate_docx( $page_data, $output_dir, $index, $total );

			if ( $result ) {
				return SScribe_Result::success( array( 'path' => $result ) );
			}

			// Provide detailed error context for debugging - memory exhaustion is the most common failure.
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
			// Include exception class name for diagnostics — many PHP/DOM errors have empty messages.
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
