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
	 * @param array  $page_data     Page metadata.
	 * @param int    $index         Current page index.
	 * @param int    $total         Total page count.
	 * @param string $extension     File extension.
	 * @param bool   $include_lang  Whether to include language code.
	 * @return string Generated filename.
	 */
	public static function build_filename( array $page_data, int $index = 0, int $total = 0, string $extension = 'docx', bool $include_lang = true ): string {
		$page_id = (int) ( $page_data['id'] ?? 0 );

		$raw_title  = isset( $page_data['title'] ) && '' !== $page_data['title']
			? sanitize_file_name( function_exists( 'remove_accents' ) ? remove_accents( trim( $page_data['title'] ) ) : trim( $page_data['title'] ) )
			: '';
		$raw_slug   = isset( $page_data['slug'] ) && '' !== $page_data['slug']
			? sanitize_file_name( trim( $page_data['slug'] ) )
			: '';
		$page_title = '' !== $raw_title ? $raw_title : ( '' !== $raw_slug ? $raw_slug : (string) $page_id );

		if ( '' === $raw_title && isset( $page_data['title'] ) && '' !== $page_data['title'] && function_exists( 'do_action' ) ) {
			$fallback = '' !== $raw_slug ? $raw_slug : (string) $page_id;
			do_action(
				'sscribe_debug_log',
				'build_filename: title stripped to empty by sanitize_file_name — falling back to ' . $fallback,
				array(
					'page_id' => $page_data['id'] ?? 0,
					'title'   => $page_data['title'],
					'slug'    => $raw_slug,
				)
			);
		}

		if ( mb_strlen( $page_title ) > 60 ) {
			$page_title = mb_substr( $page_title, 0, 60 );

			$page_title = rtrim( $page_title, '- _' );

			// Re-sanitize after truncation to ensure no problematic characters remain.
			$page_title = sanitize_file_name( $page_title );
		}

		$lang_code = '';
		if ( $include_lang && ! empty( $page_data['language'] ) ) {
			$lang      = substr( $page_data['language'], 0, 2 );
			$sanitized = sanitize_key( $lang );
			if ( '' !== $lang && '' !== $sanitized ) {
				$lang_code = '-' . strtoupper( $sanitized );
			}
		}

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
			'P%0' . $pad_length . 'd-%s%s%s.%s',
			$page_index,
			$page_title,
			$lang_code,
			$id_suffix,
			strtolower( $extension )
		);
	}
}
