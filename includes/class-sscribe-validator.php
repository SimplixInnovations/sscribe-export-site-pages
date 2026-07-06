<?php
/**
 * SScribe Validator
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
 * Validation utilities for export configuration and input sanitization.
 */
class SScribe_Validator {

	public const ALLOWED_FORMATS = array( 'docx', 'pdf', 'html', 'markdown' );

	public const MAX_PAGES = 5000;

	public const MEMORY_PER_PAGE_MB = 5;

	public const MIN_DISK_SPACE_MB = 100;


	public const BASELINE_MB_PER_PAGE = array(
		'docx'     => 1.5,
		'pdf'      => 5.0,
		'html'     => 0.8,
		'markdown' => 0.5,
	);

	/**
	 * Validate complete export configuration.
	 *
	 * @param array $config Export configuration.
	 * @return array
	 */
	public static function validate_export_config( array $config ): array {
		$errors = array();

		$errors = array_merge( $errors, self::validate_formats( $config['formats'] ?? array() ) );
		$errors = array_merge( $errors, self::validate_page_selection( $config ) );
		$errors = array_merge( $errors, self::validate_language( $config['language'] ?? '' ) );
		$errors = array_merge( $errors, self::validate_post_status( $config['post_status'] ?? 'publish' ) );
		$errors = array_merge( $errors, self::validate_resource_availability( $config ) );

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * Validate selected export formats.
	 *
	 * @param array $formats Format strings.
	 * @return array
	 */
	public static function validate_formats( array $formats ): array {
		$errors = array();

		if ( empty( $formats ) ) {
			$errors[] = __( 'No export formats selected. Please select at least one format.', 'sscribe-export-site-pages' );
			return $errors;
		}

		$invalid = array_diff( $formats, self::ALLOWED_FORMATS );
		if ( ! empty( $invalid ) ) {
			$errors[] = sprintf(
				/* translators: %s: Invalid formats list. */
				__( 'Invalid export format(s): %s. Allowed: docx, pdf, html, markdown.', 'sscribe-export-site-pages' ),
				implode( ', ', $invalid )
			);
		}

		if ( in_array( 'pdf', $formats, true ) && ! class_exists( '\\SScribeVendor\\Mpdf\\Mpdf' ) ) {
			$errors[] = __( 'PDF export is not available on this server. Please contact your hosting provider or site administrator.', 'sscribe-export-site-pages' );
		}

		return $errors;
	}

	/**
	 * Validate page selection parameters.
	 *
	 * @param array $config Export configuration.
	 * @return array
	 */
	public static function validate_page_selection( array $config ): array {
		$errors = array();

		if ( isset( $config['page_ids'] ) ) {
			$page_ids = $config['page_ids'];

			if ( ! is_array( $page_ids ) ) {
				$errors[] = __( 'Page IDs must be an array.', 'sscribe-export-site-pages' );
				return $errors;
			}

			$page_ids = array_filter( array_map( 'absint', $page_ids ) );

			if ( empty( $page_ids ) ) {
				$errors[] = __( 'No valid page IDs provided.', 'sscribe-export-site-pages' );
			}

			if ( count( $page_ids ) > self::MAX_PAGES ) {
				$errors[] = sprintf(
					/* translators: 1: Number of pages, 2: Maximum pages. */
					_n(
						'%1$d page selected. Maximum allowed is %2$d.',
						'%1$d pages selected. Maximum allowed is %2$d.',
						count( $page_ids ),
						'sscribe-export-site-pages'
					),
					count( $page_ids ),
					self::MAX_PAGES
				);
			}
		}

		return $errors;
	}

	/**
	 * Validate language code.
	 *
	 * @param string $language Language code.
	 * @return array
	 */
	public static function validate_language( string $language ): array {
		$errors = array();

		if ( empty( $language ) || 'all' === $language ) {
			return $errors;
		}

		$collector = new SScribe_Page_Collector();
		if ( $collector->is_wpml_active() ) {
			$valid_languages = wp_list_pluck( $collector->get_wpml_languages(), 'code' );
			if ( ! in_array( $language, $valid_languages, true ) ) {
				$errors[] = sprintf(
					/* translators: %s: Language code. */
					__( 'Invalid language code: %s. Select a valid language from the dropdown.', 'sscribe-export-site-pages' ),
					esc_html( $language )
				);
			}
		}

		return $errors;
	}

	/**
	 * Validate post status.
	 *
	 * @param string $status Post status.
	 * @return array
	 */
	public static function validate_post_status( string $status ): array {
		$errors = array();

		$allowed_statuses = array( 'publish', 'draft', 'pending', 'private', 'future', 'all' );

		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$errors[] = sprintf(
				/* translators: %s: Post status. */
				__( 'Invalid post status: %s. Allowed: publish, draft, pending, private, future, all.', 'sscribe-export-site-pages' ),
				esc_html( $status )
			);
		}

		return $errors;
	}

	/**
	 * Validate resource availability for export.
	 *
	 * @param array $config Export configuration.
	 * @return array
	 */
	public static function validate_resource_availability( array $config ): array {
		$errors = array();

		$page_count = isset( $config['page_ids'] ) ? count( $config['page_ids'] ) : 0;
		$formats    = $config['formats'] ?? array( 'docx' );

		$memory_errors = self::validate_memory_availability( $page_count, $formats );
		$errors        = array_merge( $errors, $memory_errors );

		$disk_errors = self::validate_disk_space();
		$errors      = array_merge( $errors, $disk_errors );

		return $errors;
	}

	/**
	 * Validate sufficient memory is available.
	 *
	 * @param int   $page_count Number of pages.
	 * @param array $formats    Export formats.
	 * @return array
	 */
	public static function validate_memory_availability( int $page_count, array $formats ): array {
		$errors = array();

		$memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		if ( $memory_limit <= 0 ) {
			return $errors;
		}

		$memory_used      = memory_get_usage( true );
		$memory_available = $memory_limit - $memory_used;

		$metrics           = class_exists( 'SScribe_Adaptive_Metrics' ) ? new SScribe_Adaptive_Metrics() : null;
		$total_mb_per_page = 0.0;
		foreach ( $formats as $format ) {
			$mb                 = $metrics
				? $metrics->get_mb_per_page( $format )
				: ( self::BASELINE_MB_PER_PAGE[ $format ] ?? self::MEMORY_PER_PAGE_MB );
			$total_mb_per_page += $mb;
		}

		$memory_per_page = $total_mb_per_page * 1024 * 1024;
		$estimated_need  = ( $page_count * $memory_per_page ) + ( 50 * 1024 * 1024 );
		$safe_available  = $memory_available * 0.8;

		if ( $estimated_need > $memory_available ) {
			$estimated_mb   = round( $estimated_need / 1024 / 1024 );
			$available_mb   = round( $memory_available / 1024 / 1024 );
			$recommended_mb = ceil( $estimated_mb / 128 ) * 128;

			$errors[] = sprintf(
				/* translators: 1: Estimated memory, 2: Available memory, 3: Recommended memory. */
				__( 'Insufficient memory: Export requires ~%1$dMB but only %2$dMB available. Increase memory to %3$dMB+ or reduce export size.', 'sscribe-export-site-pages' ),
				$estimated_mb,
				$available_mb,
				$recommended_mb
			);
		}

		return $errors;
	}

	/**
	 * Validate sufficient disk space is available.
	 *
	 * @return array
	 */
	public static function validate_disk_space(): array {
		$errors = array();

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			$errors[] = __( 'Upload directory is not accessible.', 'sscribe-export-site-pages' );
			return $errors;
		}

		$export_dir = $upload_dir['basedir'] . '/sscribe-exports';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disk_free_space returns false on failure, properly guarded.

		$free_space = function_exists( 'disk_free_space' ) ? @disk_free_space( $export_dir ) : false;

		if ( false !== $free_space ) {
			$free_mb = round( $free_space / 1024 / 1024 );
			if ( $free_mb < self::MIN_DISK_SPACE_MB ) {
				$errors[] = sprintf(
					/* translators: %d: Free disk space in MB. */
					__( 'Low disk space: Only %dMB free. Free up disk space before exporting.', 'sscribe-export-site-pages' ),
					$free_mb
				);
			}
		}

		return $errors;
	}

	/**
	 * Validate page data structure.
	 *
	 * @param array $page_data Page data array.
	 * @return array
	 */
	public static function validate_page_data( array $page_data ): array {
		$errors = array();

		$required_fields = array( 'id', 'title', 'content' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $page_data[ $field ] ) ) {
				$errors[] = sprintf(
					/* translators: %s: Field name. */
					__( 'Missing required field: %s', 'sscribe-export-site-pages' ),
					$field
				);
			}
		}

		if ( isset( $page_data['id'] ) && ! is_numeric( $page_data['id'] ) ) {
			$errors[] = __( 'Page ID must be numeric.', 'sscribe-export-site-pages' );
		}

		if ( isset( $page_data['id'] ) && intval( $page_data['id'] ) <= 0 ) {
			$errors[] = __( 'Page ID must be a positive integer.', 'sscribe-export-site-pages' );
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * Validate DOCX file integrity.
	 *
	 * @param string $file_path File path.
	 * @return bool
	 */
	public static function validate_docx_integrity( string $file_path ): bool {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		if ( 'zip' !== pathinfo( $file_path, PATHINFO_EXTENSION ) && 'docx' !== pathinfo( $file_path, PATHINFO_EXTENSION ) ) {
			return false;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file_path ) ) {
			return false;
		}

		$required = array(
			'word/document.xml',
			'[Content_Types].xml',
		);

		foreach ( $required as $file ) {
			if ( false === $zip->locateName( $file ) ) {
				$zip->close();
				return false;
			}
		}

		$zip->close();
		return true;
	}

	/**
	 * Sanitize AJAX input according to expected types.
	 *
	 * @param array $input    Raw input data.
	 * @param array $expected Expected field types.
	 * @return array
	 */
	public static function sanitize_ajax_input( array $input, array $expected ): array {
		$sanitized = array();

		foreach ( $expected as $field => $type ) {
			if ( ! isset( $input[ $field ] ) ) {
				continue;
			}

			$value = wp_unslash( $input[ $field ] );

			switch ( $type ) {
				case 'int':
					$sanitized[ $field ] = absint( $value );
					break;
				case 'string':
					$sanitized[ $field ] = sanitize_text_field( $value );
					break;
				case 'array_int':
					if ( ! is_array( $value ) ) {
						$sanitized[ $field ] = array();
						break;
					}
					$sanitized[ $field ] = array_filter( array_map( 'absint', $value ) );
					break;
				case 'array_string':
					if ( ! is_array( $value ) ) {
						$sanitized[ $field ] = array();
						break;
					}
					$sanitized[ $field ] = array_map( 'sanitize_text_field', $value );
					break;
				case 'key':
					$sanitized[ $field ] = sanitize_key( $value );
					break;
				case 'url':
					$sanitized_url       = esc_url_raw( $value );
					$sanitized[ $field ] = self::is_safe_url( $sanitized_url ) ? $sanitized_url : '';
					break;
				case 'filename':
					$sanitized[ $field ] = self::sanitize_filename( $value );
					break;
				default:
					$sanitized[ $field ] = sanitize_text_field( $value );
			}
		}

		return $sanitized;
	}

	/**
	 * Check if a URL is safe (no dangerous protocols).
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	public static function is_safe_url( string $url ): bool {
		$dangerous_protocols = array( 'javascript:', 'data:', 'vbscript:', 'file:' );
		$url_lower           = strtolower( $url );

		foreach ( $dangerous_protocols as $protocol ) {
			if ( str_contains( $url_lower, $protocol ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Sanitize filename removing dangerous patterns.
	 *
	 * @param string $filename Raw filename.
	 * @return string
	 */
	public static function sanitize_filename( string $filename ): string {
		$filename = sanitize_file_name( $filename );

		$dangerous_patterns = array( '../', '..\\', '/', '\\', "\x00" );
		foreach ( $dangerous_patterns as $pattern ) {
			$filename = str_replace( $pattern, '', $filename );
		}

		$filename = preg_replace( '/\.\.+/', '.', $filename ) ?? $filename;

		return $filename;
	}
}
