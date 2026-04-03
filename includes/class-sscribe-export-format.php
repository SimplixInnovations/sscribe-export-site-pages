<?php
/**
 * Enumeration of supported export formats.
 *
 * @package SScribe
 */

declare(strict_types=1);

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enum SScribe_Export_Format
 */
enum SScribe_Export_Format: string {

	case DOCX     = 'docx';
	case PDF      = 'pdf';
	case HTML     = 'html';
	case MARKDOWN = 'markdown';

	/**
	 * Get all supported formats.
	 *
	 * @return array Associative array of format values to labels.
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
