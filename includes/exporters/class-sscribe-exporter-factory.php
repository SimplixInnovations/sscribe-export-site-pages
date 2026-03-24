<?php
/**
 * Exporter factory for SScribe.
 *
 * @package SScribe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exporters/interface-sscribe-exporter.php';

/**
 * Class SScribe_Exporter_Factory
 */
class SScribe_Exporter_Factory {

	public const FORMAT_DOCX     = 'docx';
	public const FORMAT_PDF      = 'pdf';
	public const FORMAT_HTML     = 'html';
	public const FORMAT_MARKDOWN = 'markdown';

	/**
	 * Create an exporter for the given format.
	 *
	 * @param string $format Export format.
	 * @return SScribe_Exporter_Interface|null
	 */
	public static function create( string $format ): ?SScribe_Exporter_Interface {
		switch ( $format ) {
			case self::FORMAT_DOCX:
				return new SScribe_DOCX_Exporter();
			case self::FORMAT_PDF:
				return new SScribe_PDF_Exporter();
			case self::FORMAT_HTML:
				return new SScribe_HTML_Exporter();
			case self::FORMAT_MARKDOWN:
				return new SScribe_Markdown_Exporter();
			default:
				return null;
		}
	}

	/**
	 * Get all supported formats.
	 *
	 * @return array Associative array: format => label.
	 */
	public static function get_supported_formats(): array {
		return array(
			self::FORMAT_DOCX     => __( 'Word Document (DOCX)', 'sscribe-export-site-pages' ),
			self::FORMAT_PDF      => __( 'PDF Document', 'sscribe-export-site-pages' ),
			self::FORMAT_HTML     => __( 'HTML Page', 'sscribe-export-site-pages' ),
			self::FORMAT_MARKDOWN => __( 'Markdown', 'sscribe-export-site-pages' ),
		);
	}

	/**
	 * Check if a format is supported.
	 *
	 * @param string $format Format to check.
	 * @return bool
	 */
	public static function is_supported( string $format ): bool {
		return in_array( $format, array_keys( self::get_supported_formats() ), true );
	}
}
