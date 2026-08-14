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
	 * Per-export format options set by the batch processor.
	 *
	 * Keys are format-prefixed option names (e.g. `sscribe_docx_template`).
	 * Populated via apply_format_options() before export() is called.
	 *
	 * @var array<string, mixed>
	 */
	private array $format_options = array();

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
	 * Apply per-format options to this exporter instance.
	 *
	 * The batch processor calls this after running the
	 * `sscribe_export_options_docx` filter and before export(). The
	 * resolved options are also passed through to the underlying
	 * SScribe_Exporter via set_format_options() so the cover-page,
	 * TOC, and image code paths can read them.
	 *
	 * @param array<string, mixed> $options Sanitized options map.
	 * @return void
	 */
	public function apply_format_options( array $options ): void {
		$this->format_options = $options;

		$this->exporter->set_format_options( $options );
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
		$page_id = isset( $page_data['id'] ) && is_numeric( $page_data['id'] ) ? absint( $page_data['id'] ) : 0;

		if ( ! class_exists( '\SScribeVendor\PhpOffice\PhpWord\PhpWord' ) ) {
			$this->logger->error(
				'PhpWord library not available : vendor/ directory missing or autoloader not loaded',
				array( 'page_id' => $page_id )
			);
			return SScribe_Result::failure(
				__( 'DOCX export is unavailable because a required library is missing. Please reinstall the plugin.', 'sscribe-export-site-pages' ),
				array( 'page_id' => $page_id )
			);
		}

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
			$this->logger->error(
				'DOCX export failed',
				array(
					'page_id'      => $page_id,
					'error'        => $last_error,
					'memory_usage' => size_format( memory_get_usage( true ) ),
					'memory_peak'  => size_format( memory_get_peak_usage( true ) ),
					'memory_limit' => ini_get( 'memory_limit' ),
				)
			);

			return SScribe_Result::failure(
				__( 'Unable to generate the DOCX file. Please try again or use another export format.', 'sscribe-export-site-pages' ),
				array( 'page_id' => $page_id )
			);

		} catch ( \Throwable $e ) {
			$this->logger->error(
				'DOCX export crashed',
				array(
					'page_id' => $page_id,
					'error'   => $e->getMessage(),
					'class'   => get_class( $e ),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				)
			);

			return SScribe_Result::failure(
				__( 'Unable to generate the DOCX file. Please try again or use another export format.', 'sscribe-export-site-pages' ),
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
