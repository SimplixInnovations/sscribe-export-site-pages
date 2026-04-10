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
				sprintf( 'Invalid export format: %s', $format ),
				'format',
				'enum',
				array( 'format' => $format )
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
		$enum_format = self::validate_format( $format );

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
	 * Build a clean filename with format: WebsiteName-Date-Time-Lang-Type
	 *
	 * Example: mywebsite-2026-04-02-192555-AR-DOCX
	 *
	 * @param array  $page_data Page data array.
	 * @param int    $index     Sequential position (1-based).
	 * @param int    $total     Total pages (unused, kept for API compatibility).
	 * @param string $extension File extension without dot.
	 * @return string Filename with extension.
	 */
	public static function build_filename( array $page_data, int $index = 0, int $total = 0, string $extension = 'docx' ): string {
		$site_name = sanitize_file_name( get_bloginfo( 'name' ) );
		$site_name = strtolower( substr( $site_name, 0, 20 ) );

		if ( empty( $site_name ) ) {
			$site_name = 'export';
		}

		$timestamp = gmdate( 'Y-m-d-His' );

		$lang_code = 'EN';
		if ( ! empty( $page_data['language'] ) ) {
			$lang      = substr( $page_data['language'], 0, 2 );
			$lang_code = strtoupper( sanitize_key( $lang ) );
		}

		$page_id = (int) ( $page_data['id'] ?? 0 );

		return sprintf(
			'%s-%s-%s-P%d.%s',
			$site_name,
			$timestamp,
			$lang_code,
			$page_id,
			strtolower( $extension )
		);
	}
}
