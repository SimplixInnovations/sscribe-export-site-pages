<?php
/**
 * SScribe Exporter Factory
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
 * Factory for creating export format instances.
 */
class SScribe_Exporter_Factory {

	/**
	 * Validate and resolve export format enum.
	 *
	 * @param string $format Format string.
	 * @return SScribe_Export_Format
	 * @throws SScribe_Validation_Exception If format is invalid.
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
	 * Create an exporter instance for the given format.
	 *
	 * @param string $format Export format string.
	 * @return SScribe_Exporter_Interface
	 * @throws SScribe_Validation_Exception If format is invalid.
	 */
	public static function create( string $format ): SScribe_Exporter_Interface {
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
	 * Get all supported export formats.
	 *
	 * @return array
	 */
	public static function get_supported_formats(): array {
		return SScribe_Export_Format::get_supported_formats();
	}

	/**
	 * Check if a format is supported.
	 *
	 * @param string $format Format string.
	 * @return bool
	 */
	public static function is_supported( string $format ): bool {
		return null !== SScribe_Export_Format::tryFrom( $format );
	}

	/**
	 * Build a filename for the exported page.
	 *
	 * Language is no longer encoded in the filename. The batch processor
	 * now writes each page into a per-language output subdirectory
	 * (e.g. `temp-xxx/AR/P001-foo.pdf`), and the ZIP handler derives the
	 * language from the parent directory name. The `$include_lang`
	 * parameter is preserved for backward compatibility with any
	 * third-party callers but is now a no-op.
	 *
	 * @param array  $page_data     Page metadata.
	 * @param int    $index         Current page index.
	 * @param int    $total         Total page count.
	 * @param string $extension     File extension.
	 * @param bool   $include_lang  Deprecated — language is communicated
	 *                              via the output directory, not the filename.
	 * @return string Generated filename.
	 */
	public static function build_filename( array $page_data, int $index = 0, int $total = 0, string $extension = 'docx', bool $include_lang = true ): string {
		$page_id = $page_data['id'] ?? 0;

		$raw_title  = $page_data['title'] ?? '';
		if ( '' !== $raw_title ) {
			$raw_title = self::sanitize_filename_preserve_unicode( trim( $raw_title ) );
		}

		$raw_slug   = $page_data['slug'] ?? '';
		if ( '' !== $raw_slug ) {
			$raw_slug = self::sanitize_filename_preserve_unicode( trim( $raw_slug ) );
		}

		$page_title = '' !== $raw_title ? $raw_title : ( '' !== $raw_slug ? $raw_slug : (string) $page_id );

		if ( mb_strlen( $page_title ) > 60 ) {
			$page_title = mb_substr( $page_title, 0, 60 );

			$page_title = rtrim( $page_title, '- _' );

			// Re-sanitize after truncation to ensure no problematic characters remain.
			$page_title = self::sanitize_filename_preserve_unicode( $page_title );
		}

		// $include_lang is intentionally ignored — see the docblock.
		unset( $include_lang );

		$pad_length = $total > 0 ? strlen( (string) $total ) : 3;
		$pad_length = max( 3, $pad_length );

		// Include page_id in filename to prevent collisions when multiple pages
		// share the same title but have different IDs and/or indices.
		$id_suffix = $page_id > 0 ? '-' . $page_id : '';

		// Default $index of 0 maps to 1 (first page). Guard against accidental
		// misuse when $total > 1 by logging a debug message.
		$page_index = $index > 0 ? $index : 1;
		if ( 0 === $index && $total > 1 ) {
			// This is a calling-code bug — log it for debugging.
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				sprintf(
					'SScribe: build_filename called with index=0 for a multi-page export (%d pages). Filename will use P001.',
					$total
				)
			);
		}

		return sprintf(
			'P%0' . $pad_length . 'd-%s%s.%s',
			$page_index,
			$page_title,
			$id_suffix,
			strtolower( $extension )
		);
	}

	/**
	 * Sanitize a filename while preserving Unicode characters (non-Latin scripts).
	 *
	 * Unlike WordPress's sanitize_file_name(), this does NOT call remove_accents()
	 * which destroys CJK, Arabic, Hebrew, Thai, Cyrillic, etc. characters.
	 * Only strips characters that are genuinely invalid in filenames across
	 * modern OS filesystems (null bytes, path separators, and a short list
	 * of filesystemreserved names).
	 *
	 * @param string $filename Raw filename.
	 * @return string Sanitized filename safe for filesystem use.
	 */
	private static function sanitize_filename_preserve_unicode( string $filename ): string {
		// Replace directory separators with hyphens so the filename stays as one component.
		$filename = str_replace( array( '/', '\\' ), '-', $filename );

		// Remove null bytes (always invalid in filenames).
		$filename = str_replace( "\0", '', $filename );

		// Strip characters that are illegal on Windows (and generally problematic).
		// These are: < > : " | ? and non-ASCII control characters.
		$filename = preg_replace( '/[\x00-\x1f\x7f<>:\"\/\\\\|?]/', '', $filename );

		// Windows reserved names (device names): CON, PRN, AUX, NUL, COM1-9, LPT1-9.
		// Handle edge cases like "COM1" or "NUL" (case-insensitive on Windows).
		if ( preg_match( '/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\\.|$)/i', $filename ) ) {
			$filename = '_' . $filename;
		}

		// Collapse multiple hyphens/stubs to a single hyphen.
		$filename = preg_replace( '/-+/', '-', $filename );

		// Remove leading/trailing hyphens and dots (hidden files on Windows).
		$filename = trim( $filename, '.-' );

		// Enforce max length (255 bytes is the filesystem limit for most OS).
		if ( strlen( $filename ) > 200 ) {
			$filename = substr( $filename, 0, 200 );
		}

		return '' !== $filename ? $filename : '_';
	}
}
