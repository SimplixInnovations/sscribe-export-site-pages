<?php
/**
 * SScribe Exporter Interface
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for export format implementations.
 */
interface SScribe_Exporter_Interface {

	/**
	 * Export page data to the target format.
	 *
	 * @param array  $page_data Page content and metadata.
	 * @param string $output_dir Output directory path.
	 * @param int    $index     Current page index.
	 * @param int    $total     Total page count.
	 * @return SScribe_Result
	 */
	public function export( array $page_data, string $output_dir, int $index = 0, int $total = 0 ): SScribe_Result;

	/**
	 * Get the file extension for this format.
	 *
	 * @return string
	 */
	public function get_extension(): string;

	/**
	 * Get the MIME type for this format.
	 *
	 * @return string
	 */
	public function get_mime_type(): string;
}
