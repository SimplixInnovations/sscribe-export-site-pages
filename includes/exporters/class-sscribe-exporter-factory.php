<?php
/**
 * SScribe Exporter Factory
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SScribe_Exporter_Factory {

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

	public static function get_supported_formats(): array {
		return SScribe_Export_Format::get_supported_formats();
	}

	public static function is_supported( string $format ): bool {
		return null !== SScribe_Export_Format::tryFrom( $format );
	}

	public static function build_filename( array $page_data, int $index = 0, int $total = 0, string $extension = 'docx', bool $include_lang = true ): string {
		$page_id = (int) ( $page_data['id'] ?? 0 );

		$raw_title  = isset( $page_data['title'] ) && '' !== $page_data['title']
			? sanitize_file_name( trim( $page_data['title'] ) )
			: '';
		$raw_slug   = isset( $page_data['slug'] ) && '' !== $page_data['slug']
			? sanitize_file_name( trim( $page_data['slug'] ) )
			: '';
		$page_title = '' !== $raw_title ? $raw_title : ( '' !== $raw_slug ? $raw_slug : 'page' );

		if ( '' === $raw_title && isset( $page_data['title'] ) && '' !== $page_data['title'] && function_exists( 'do_action' ) ) {
			do_action(
				'sscribe_debug_log',
				'build_filename: title stripped to empty by sanitize_file_name — falling back to slug',
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
		}

		$lang_code = '';
		if ( $include_lang && ! empty( $page_data['language'] ) ) {
			$lang      = substr( $page_data['language'], 0, 2 );
			$lang_code = '-' . strtoupper( sanitize_key( $lang ) );
		}

		$pad_length = $total > 0 ? strlen( (string) $total ) : 3;
		$pad_length = max( 3, $pad_length );

		return sprintf(
			'P%0' . $pad_length . 'd-%s%s.%s',
			$index > 0 ? $index : $page_id,
			$page_title,
			$lang_code,
			strtolower( $extension )
		);
	}
}
