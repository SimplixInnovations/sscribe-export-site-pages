<?php
/**
 * Error diagnostics and guidance for SScribe export operations.
 *
 * Aggregates structured error data from batch export processing into
 * a user-facing diagnostics payload with categorised guidance, fix steps,
 * and technical context for troubleshooting.
 *
 * @package       SScribe
 * @since         1.1.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Error handler and diagnostics builder for export operations.
 *
 * Transforms raw structured errors (collected during batch processing)
 * into a concise diagnostics payload for the admin UI. Includes
 * category-level guidance, deduplicated fix steps, and aggregated
 * technical metadata (memory peaks, HTML sizes, exception types).
 *
 * @since 1.1.0
 */
class SScribe_Export_Error_Handler {

	/**
	 * Maximum number of errors to retain in memory per export session.
	 *
	 * Beyond this limit, errors are still logged to disk but not stored
	 * in the session array to prevent OOM on large exports (500+ pages).
	 *
	 * @var int
	 * @since 1.1.0
	 */
	public const MAX_STORED_ERRORS = 50;

	/**
	 * Diagnostics instance for per-page error diagnosis.
	 *
	 * @var SScribe_Diagnostics
	 * @since 1.1.0
	 */
	private readonly SScribe_Diagnostics $diagnostics;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param SScribe_Diagnostics|null $diagnostics Diagnostics instance.
	 *                                              Falls back to a new
	 *                                              instance if omitted.
	 */
	public function __construct( ?SScribe_Diagnostics $diagnostics = null ) {
		$this->diagnostics = $diagnostics ?? new SScribe_Diagnostics();
	}

	/**
	 * Build a user-facing diagnostics payload from structured errors.
	 *
	 * Aggregates per-page error entries into a flattened payload with:
	 * - Categorised error guidance
	 * - Deduplicated fix steps
	 * - Aggregated technical metadata (HTML sizes, memory peaks)
	 * - Per-entry diagnostics
	 *
	 * @since 1.1.0
	 *
	 * @param array $structured_errors Array of structured error entries,
	 *                                 each containing 'page_id', 'errors',
	 *                                 'diagnostics', and optional context.
	 * @param array $string_errors     Optional. Flat string error messages
	 *                                 for fallback when diagnostics are empty.
	 *
	 * @return array{
	 *     total_errors: int,
	 *     categories: string[],
	 *     guidance: string,
	 *     fix_steps: string[],
	 *     technical: array,
	 *     entries: array
	 * } Diagnostics payload.
	 */
	public function build_diagnostics_payload( array $structured_errors, array $string_errors = array() ): array {
		$categories      = array();
		$guidance_map    = array();
		$fix_steps_map   = array();
		$technical_items = array();
		$diagnostics     = array();
		$html_sizes      = array();
		$memory_peaks    = array();
		$memory_limits   = array();
		$exception_types = array();
		$formats         = array();
		$page_ids        = array();
		$libxml_count    = 0;

		foreach ( $structured_errors as $entry ) {
			$page_id       = (int) ( $entry['page_id'] ?? 0 );
			$page_ids[]    = $page_id;
			$format_errors = isset( $entry['errors'] ) && is_array( $entry['errors'] ) ? $entry['errors'] : array();

			if ( ! empty( $entry['diagnostics'] ) && is_array( $entry['diagnostics'] ) ) {
				foreach ( $entry['diagnostics'] as $diagnosis ) {
					$diagnostics[] = $diagnosis;
				}
			} else {
				foreach ( $format_errors as $format_error ) {
					$diagnostics[] = $this->diagnostics->diagnose_page_error(
						$page_id,
						strtolower( $format_error['format'] ?? 'unknown' ),
						$format_error['message'] ?? '',
						is_array( $format_error['context'] ?? null ) ? $format_error['context'] : array()
					);
				}
			}

			foreach ( $format_errors as $format_error ) {
				$context   = is_array( $format_error['context'] ?? null ) ? $format_error['context'] : array();
				$formats[] = $format_error['format'] ?? 'UNKNOWN';

				if ( isset( $context['html_size'] ) ) {
					$html_sizes[] = (int) $context['html_size'];
				}

				if ( isset( $context['memory_peak'] ) ) {
					$memory_peaks[] = (int) $context['memory_peak'];
				}

				if ( ! empty( $context['memory_limit'] ) ) {
					$memory_limits[] = (string) $context['memory_limit'];
				}

				if ( ! empty( $context['exception_class'] ) ) {
					$exception_types[] = (string) $context['exception_class'];
				}

				if ( ! empty( $context['libxml_errors'] ) && is_array( $context['libxml_errors'] ) ) {
					$libxml_count += count( $context['libxml_errors'] );
				}

				if ( ! empty( $context ) ) {
					$technical_items[] = array(
						'page_id'    => $page_id,
						'page_title' => $entry['page_title'] ?? '',
						'format'     => $format_error['format'] ?? 'UNKNOWN',
						'category'   => $format_error['category'] ?? 'unknown',
						'context'    => $context,
					);
				}
			}
		}

		foreach ( $diagnostics as $diagnosis ) {
			$category                = $diagnosis['category'] ?? 'unknown';
			$categories[ $category ] = true;

			$guidance = $this->get_guidance_for_category( $category );
			if ( '' !== $guidance ) {
				$guidance_map[ $guidance ] = true;
			}

			$fixes = $diagnosis['fix'] ?? array();
			if ( is_array( $fixes ) ) {
				foreach ( $fixes as $fix_step ) {
					$fix_steps_map[ $fix_step ] = true;
				}
			}

			$technical = $diagnosis['technical'] ?? array();
			if ( ! empty( $technical ) ) {
				$technical_items[] = array(
					'page_id'  => $diagnosis['page_id'] ?? 0,
					'format'   => strtoupper( $diagnosis['format'] ?? 'unknown' ),
					'category' => $category,
					'context'  => $technical,
				);
			}
		}

		if ( empty( $diagnostics ) && ! empty( $string_errors ) ) {
			foreach ( $string_errors as $error_message ) {
				$diagnosis     = $this->diagnostics->diagnose_page_error( 0, 'system', (string) $error_message );
				$diagnostics[] = $diagnosis;

				$categories[ $diagnosis['category'] ?? 'unknown' ] = true;

				$guidance = $this->get_guidance_for_category( $diagnosis['category'] ?? 'unknown' );
				if ( '' !== $guidance ) {
					$guidance_map[ $guidance ] = true;
				}

				foreach ( $diagnosis['fix'] ?? array() as $fix_step ) {
					$fix_steps_map[ $fix_step ] = true;
				}
			}
		}

		return array(
			'total_errors' => count( $string_errors ),
			'categories'   => array_keys( $categories ),
			'guidance'     => implode( "\n\n", array_keys( $guidance_map ) ),
			'fix_steps'    => array_keys( $fix_steps_map ),
			'technical'    => array(
				'formats'            => array_unique( $formats ),
				'page_ids_count'     => count( array_unique( $page_ids ) ),
				'max_html_size'      => empty( $html_sizes ) ? 0 : max( $html_sizes ),
				'max_memory_peak'    => empty( $memory_peaks ) ? 0 : max( $memory_peaks ),
				'memory_limits'      => array_unique( $memory_limits ),
				'exceptions'         => array_unique( $exception_types ),
				'libxml_error_count' => $libxml_count,
				'entries'            => $technical_items,
			),
			'entries'      => $diagnostics,
		);
	}

	/**
	 * Build structured error entries from export log data.
	 *
	 * Transforms the flat log format into the structured error array
	 * expected by {@see build_diagnostics_payload()}.
	 *
	 * @since 1.1.0
	 *
	 * @param array $log_data Export log data containing a 'pages' key.
	 *
	 * @return array Array of structured error entries.
	 */
	public function build_structured_errors_from_log( array $log_data ): array {
		$structured_errors = array();
		$pages             = isset( $log_data['pages'] ) && is_array( $log_data['pages'] )
			? $log_data['pages']
			: array();

		foreach ( $pages as $page_id => $page ) {
			$page_errors = array();
			$formats_raw = isset( $page['formats'] ) && is_array( $page['formats'] )
				? $page['formats']
				: array();

			/*
			 * Normalise: handle both plain arrays ('docx', 'pdf') and
			 * associative arrays ('docx' => array('success' => true, ...)).
			 */
			$formats = array();
			foreach ( $formats_raw as $key => $value ) {
				if ( is_int( $key ) && is_string( $value ) ) {
					$formats[ $value ] = array(
						'success' => true,
						'file'    => '',
						'error'   => '',
					);
				} else {
					$formats[ $key ] = $value;
				}
			}

			foreach ( $formats as $format => $format_data ) {
				if ( ! is_array( $format_data ) ) {
					continue;
				}
				if ( ! empty( $format_data['success'] ) || empty( $format_data['error'] ) ) {
					continue;
				}

				$page_errors[] = array(
					'format'   => strtoupper( (string) $format ),
					'message'  => (string) $format_data['error'],
					'category' => 'unknown',
					'context'  => array(
						'page_id'    => (int) $page_id,
						'page_title' => $page['title'] ?? '',
						'memory'     => $page['memory'] ?? '',
					),
				);
			}

			if ( empty( $page_errors ) && ! empty( $page['error'] ) ) {
				$page_errors[] = array(
					'format'   => 'SYSTEM',
					'message'  => (string) $page['error'],
					'category' => 'unknown',
					'context'  => array(
						'page_id'    => (int) $page_id,
						'page_title' => $page['title'] ?? '',
						'memory'     => $page['memory'] ?? '',
					),
				);
			}

			if ( empty( $page_errors ) ) {
				continue;
			}

			$structured_errors[] = array(
				'page_id'    => (int) $page_id,
				'page_title' => $page['title'] ?? '',
				'message'    => $page['error'] ?? '',
				'errors'     => $page_errors,
				'time'       => $page['end_time'] ?? '',
			);
		}

		return $structured_errors;
	}

	/**
	 * Get user-facing guidance text for an error category.
	 *
	 * @since 1.1.0
	 *
	 * @param string $category Error category slug.
	 *
	 * @return string Guidance text, translated. Returns the 'unknown'
	 *                category guidance if the category is not recognised.
	 */
	public function get_guidance_for_category( string $category ): string {
		$guidance_map = array(
			'memory_exhausted'    => __( 'The server ran out of memory during export. Large PDF renders often need a higher PHP memory limit.', 'sscribe-export-site-pages' ),
			'timeout'             => __( 'The export is hitting a server time limit before rendering can finish. Reduce load or increase execution time.', 'sscribe-export-site-pages' ),
			'pdf_generation'      => __( 'mPDF could not render the page successfully. Review the technical details for HTML size, memory usage, and libxml parsing problems.', 'sscribe-export-site-pages' ),
			'pdf_missing_library' => __( 'The mPDF library is missing from the plugin install, so PDF export cannot start.', 'sscribe-export-site-pages' ),
			'pdf_filesystem'      => __( 'The PDF was generated but could not be written to disk. Review filesystem access and output path details.', 'sscribe-export-site-pages' ),
			'permissions'         => __( 'The server does not have permission to write required export files. Check upload directory access.', 'sscribe-export-site-pages' ),
			'zip_extension'       => __( 'ZIP creation failed because the server is missing ZIP support or the archive step could not complete.', 'sscribe-export-site-pages' ),
			'zip_creation'        => __( 'The export finished processing pages but failed while packaging the ZIP archive.', 'sscribe-export-site-pages' ),
			'docx_generation'     => __( 'DOCX generation failed for at least one page. Complex content or resource pressure may be involved.', 'sscribe-export-site-pages' ),
			'critical_error'      => __( 'A low-level PHP error interrupted the export. Review the technical context and server logs for the failing component.', 'sscribe-export-site-pages' ),
			'unknown'             => __( 'Review the diagnostics below and your server error log for the most specific failure details.', 'sscribe-export-site-pages' ),
		);

		return $guidance_map[ $category ] ?? $guidance_map['unknown'];
	}
}
