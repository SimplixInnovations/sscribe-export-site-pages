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

	/**
	 * Build a clean, human-readable filename with transliteration for non-ASCII titles.
	 *
	 * @param array  $page_data Page data array.
	 * @param int    $index     Sequential position (1-based).
	 * @param int    $total     Total pages (for zero-padding).
	 * @param string $extension File extension without dot.
	 * @return string Filename with extension.
	 */
	public static function build_filename( array $page_data, int $index = 0, int $total = 0, string $extension = 'docx' ): string {
		if ( $index > 0 && $total > 0 ) {
			$pad_length = strlen( (string) $total );
			$seq_prefix = str_pad( (string) $index, $pad_length, '0', STR_PAD_LEFT );
		} else {
			$seq_prefix = (string) ( $page_data['id'] ?? 0 );
		}

		$title = (string) ( $page_data['title'] ?? '' );
		$ascii_title = '';

		if ( function_exists( 'iconv' ) ) {
			$transliterated = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $title );
			if ( $transliterated ) {
				$ascii_title = $transliterated;
			}
		}

		if ( empty( $ascii_title ) || ! preg_match( '/[a-zA-Z0-9]/', $ascii_title ) ) {
			$ascii_title = preg_replace( '/[^\x20-\x7E]/', '', $title );
		}

		if ( empty( trim( $ascii_title ) ) || ! preg_match( '/[a-zA-Z0-9]/', $ascii_title ) ) {
			$ascii_title = 'page-' . ( $page_data['id'] ?? 0 );
		}

		$safe_label = sanitize_file_name( $ascii_title );
		$safe_label = substr( $safe_label, 0, 60 );
		$safe_label = trim( $safe_label, '-' );

		if ( empty( $safe_label ) ) {
			$safe_label = 'page-' . ( $page_data['id'] ?? 0 );
		}

		return $seq_prefix . '-' . $safe_label . '.' . $extension;
	}
}
