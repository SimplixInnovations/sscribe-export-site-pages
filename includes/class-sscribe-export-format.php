<?php
/**
 * SScribe Export Format
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
 * Supported export format enumeration.
 */
enum SScribe_Export_Format: string {

	case DOCX     = 'docx';
	case PDF      = 'pdf';
	case HTML     = 'html';
	case MARKDOWN = 'markdown';

	/**
	 * Get all supported formats with display labels.
	 *
	 * @return array
	 */
	public static function get_supported_formats(): array {
		return array(
			self::DOCX->value     => __( 'Word Document (DOCX)', 'sscribe-export-site-pages' ),
			self::PDF->value      => __( 'PDF Document', 'sscribe-export-site-pages' ),
			self::HTML->value     => __( 'HTML Page', 'sscribe-export-site-pages' ),
			self::MARKDOWN->value => __( 'Markdown', 'sscribe-export-site-pages' ),
		);
	}
}
