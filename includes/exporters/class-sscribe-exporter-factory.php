<?php
/**
 * Exporter factory for SScribe.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Exporter_Factory
 */
class SScribe_Exporter_Factory {

	/**
	 * Validate and normalize an export format string.
	 *
	 * @param string $format Export format.
	 * @return SScribe_Export_Format
	 * @throws SScribe_Validation_Exception When format is invalid.
	 */
	private static function validate_format( string $format ): SScribe_Export_Format {
		$enum_format = SScribe_Export_Format::tryFrom( $format );

		if ( null === $enum_format ) {
			throw new SScribe_Validation_Exception(
				'Invalid export format provided',
				'format',
				'enum',
				array()
			);
		}

		return $enum_format;
	}

	/**
	 * Create an exporter for the given format.
	 *
	 * Uses the service container when available for proper dependency injection,
	 * falls back to direct instantiation for standalone usage.
	 *
	 * @param string $format Export format.
	 * @return SScribe_Exporter_Interface|null
	 */
	public static function create( string $format ): ?SScribe_Exporter_Interface {
		try {
			$enum_format = self::validate_format( $format );
		} catch ( SScribe_Validation_Exception $e ) {
			return null;
		}

		$container = SScribe_Container::instance();

		return match ( $enum_format ) {
			SScribe_Export_Format::DOCX     => $container->has( SScribe_DOCX_Exporter::class )
				? $container->get( SScribe_DOCX_Exporter::class )
				: new SScribe_DOCX_Exporter(),
			SScribe_Export_Format::PDF      => $container->has( SScribe_PDF_Exporter::class )
				? $container->get( SScribe_PDF_Exporter::class )
				: new SScribe_PDF_Exporter(),
			SScribe_Export_Format::HTML     => $container->has( SScribe_HTML_Exporter::class )
				? $container->get( SScribe_HTML_Exporter::class )
				: new SScribe_HTML_Exporter(),
			SScribe_Export_Format::MARKDOWN => $container->has( SScribe_Markdown_Exporter::class )
				? $container->get( SScribe_Markdown_Exporter::class )
				: new SScribe_Markdown_Exporter(),
		};
	}

	/**
	 * Get all supported formats.
	 *
	 * @return array Associative array: format => label.
	 */
	public static function get_supported_formats(): array {
		return SScribe_Export_Format::get_supported_formats();
	}

	/**
	 * Check if a format is supported.
	 *
	 * @param string $format Format to check.
	 * @return bool
	 */
	public static function is_supported( string $format ): bool {
		return null !== SScribe_Export_Format::tryFrom( $format );
	}

	/**
	 * Build a user-friendly filename including the page title.
	 *
	 * Format: P001-Page-Title-AR.docx
	 *
	 * The page title is included in its native language (Arabic, English, etc.)
	 * so users can identify files at a glance. The slug is NOT used because
	 * it is shared across all language variants of the same page.
	 *
	 * @param array  $page_data    Page data array (must contain 'title' and 'id').
	 * @param int    $index        Sequential position (1-based).
	 * @param int    $total        Total page count — used to calculate zero-padding width for proper sort order with 1000+ pages.
	 * @param string $extension    File extension without dot.
	 * @param bool   $include_lang Whether to include language code suffix (default true).
	 * @return string Filename with extension.
	 */
	public static function build_filename( array $page_data, int $index = 0, int $total = 0, string $extension = 'docx', bool $include_lang = true ): string {
		$page_id = (int) ( $page_data['id'] ?? 0 );

		// Include page title in native language for user-friendly identification.
		// sanitize_file_name handles Unicode (Arabic, CJK, etc.) and strips unsafe chars.
		// Attempt page title first, then slug, then 'page' as last resort.
		// sanitize_file_name() strips all non-ASCII (Arabic, CJK, etc.) returning empty.
		// Slug is URL-safe ASCII and uniquely identifies the page even for non-Latin scripts.
		$raw_title = isset( $page_data['title'] ) && '' !== $page_data['title']
			? sanitize_file_name( trim( $page_data['title'] ) )
			: '';
		$raw_slug = isset( $page_data['slug'] ) && '' !== $page_data['slug']
			? sanitize_file_name( trim( $page_data['slug'] ) )
			: '';
		$page_title = '' !== $raw_title ? $raw_title : ( '' !== $raw_slug ? $raw_slug : 'page' );

		// Truncate title to 60 chars to keep filenames reasonable.
		// mb_substr handles multibyte (Arabic, CJK) correctly.
		if ( mb_strlen( $page_title ) > 60 ) {
			$page_title = mb_substr( $page_title, 0, 60 );
			// Remove trailing partial char from multibyte truncation.
			$page_title = rtrim( $page_title, '- _' );
		}

		$lang_code = '';
		if ( $include_lang && ! empty( $page_data['language'] ) ) {
			$lang      = substr( $page_data['language'], 0, 2 );
			$lang_code = '-' . strtoupper( sanitize_key( $lang ) );
		}

		// Dynamic padding based on total count for proper sort order with 1000+ pages.
		$pad_length = $total > 0 ? strlen( (string) $total ) : 3;
		$pad_length = max( 3, $pad_length );

		// Format: P001-Page-Title-AR.docx (with lang) or P001-Page-Title.docx (without lang).
		return sprintf(
			'P%0' . $pad_length . 'd-%s%s.%s',
			$index > 0 ? $index : $page_id,
			$page_title,
			$lang_code,
			strtolower( $extension )
		);
	}
}
