<?php
/**
 * SScribe Export Format Enum
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

enum SScribe_Export_Format: string {

	case DOCX     = 'docx';
	case PDF      = 'pdf';
	case HTML     = 'html';
	case MARKDOWN = 'markdown';

	public static function get_supported_formats(): array {
		return array(
			self::DOCX->value     => __( 'Word Document (DOCX)', 'sscribe-export-site-pages' ),
			self::PDF->value      => __( 'PDF Document', 'sscribe-export-site-pages' ),
			self::HTML->value     => __( 'HTML Page', 'sscribe-export-site-pages' ),
			self::MARKDOWN->value => __( 'Markdown', 'sscribe-export-site-pages' ),
		);
	}
}
