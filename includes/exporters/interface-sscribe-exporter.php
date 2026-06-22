<?php
/**
 * SScribe Exporter Interface
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public contract for export format implementations.
 *
 * ## Stability
 *
 * @api This interface is part of the **stable public API** of SScribe Export
 * Site Pages. Methods documented here will not change signature across
 * minor versions. New optional capability methods may be added in minor
 * versions with a default implementation in {@see SScribe_Exporter_Base}
 * (not yet extracted — see Phase 2 audit finding 2.2).
 *
 * Third-party plugins are encouraged to implement this interface to add
 * custom export formats. The recommended registration path is:
 *
 *  1. Add a new case to {@see SScribe_Export_Format}.
 *  2. Add a new arm to {@see SScribe_Exporter_Factory::create()} mapping
 *     the new case to your implementation.
 *  3. Register the option in the admin UI's format picker.
 *
 * ## Error handling
 *
 * Implementations must return a {@see SScribe_Result} on every code path.
 * The batch processor and the {@see SScribe_Export_All_Formats_Wrapper}
 * both rely on a non-throwing contract — `export()` should catch
 * internal exceptions and surface them as
 * {@see SScribe_Result::failure()} instead.
 *
 * @package SScribe_Export_Site_Pages
 * @since   1.1.1
 * @api     stable
 */
interface SScribe_Exporter_Interface {

	/**
	 * Export page data to the target format.
	 *
	 * Must NEVER throw. Internal exceptions must be caught and returned
	 * as {@see SScribe_Result::failure()} so that the batch orchestrator
	 * and the fan-out wrapper can isolate per-page, per-format failures.
	 *
	 * @param array  $page_data  Page content and metadata (id, title,
	 *                           content, meta, language, slug, etc.).
	 * @param string $output_dir Absolute path to a writable directory.
	 *                           The implementation is responsible for
	 *                           ensuring the directory exists.
	 * @param int    $index      0-based page index within the batch.
	 * @param int    $total      Total page count in the batch (0 if
	 *                           single-page export).
	 * @return SScribe_Result Success with the output file path, or
	 *                        failure with a human-readable error.
	 */
	public function export( array $page_data, string $output_dir, int $index = 0, int $total = 0 ): SScribe_Result;

	/**
	 * File extension for outputs of this format (without the dot).
	 *
	 * Examples: `"docx"`, `"pdf"`, `"html"`, `"md"`.
	 *
	 * @return string
	 */
	public function get_extension(): string;

	/**
	 * Canonical MIME type for outputs of this format.
	 *
	 * Examples:
	 *  - DOCX: `"application/vnd.openxmlformats-officedocument.wordprocessingml.document"`
	 *  - PDF:  `"application/pdf"`
	 *  - HTML: `"text/html"`
	 *  - MD:   `"text/markdown"`
	 *
	 * @return string
	 */
	public function get_mime_type(): string;
}
