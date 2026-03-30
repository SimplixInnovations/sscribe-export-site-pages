<?php
/**
 * Exporter interface for SScribe.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface SScribe_Exporter_Interface
 */
interface SScribe_Exporter_Interface {

	/**
	 * Export a single page.
	 *
	 * @param array  $page_data  Page data from collector.
	 * @param string $output_dir Output directory.
	 * @param int    $index      Page index (for filename).
	 * @param int    $total      Total pages (for filename).
	 * @return SScribe_Result Result with file path or error.
	 */
	public function export( array $page_data, string $output_dir, int $index = 0, int $total = 0 ): SScribe_Result;

	/**
	 * Get the file extension for this exporter.
	 *
	 * @return string File extension without dot (e.g., 'pdf', 'docx').
	 */
	public function get_extension(): string;

	/**
	 * Get the mime type for this exporter.
	 *
	 * @return string Mime type.
	 */
	public function get_mime_type(): string;
}
