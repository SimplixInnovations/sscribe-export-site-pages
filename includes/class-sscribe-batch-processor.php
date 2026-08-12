<?php
/**
 * SScribe Batch Processor
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);


// silenced at the point of use with a `phpcs:ignore` comment : see below.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-sscribe-result.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-batch-step-handler.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-export-finalizer.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-cancel-handler.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-session-status.php';

use SScribe_Result as SScribe_Export_Result;

/**
 * Handles batch export processing with rate limiting and resource monitoring.
 *
 * Marked `final` to prevent extension : the dependency surface is large
 * (12+ collaborators) and extension by third parties would be a support
 * liability. For custom batch behavior, register additional WP hooks
 * (e.g. {@see sscribe_after_export_page}) instead of subclassing.
 *
 * The actual methods are spread across four traits so each file stays
 * scannable : the four traits group behaviour by responsibility:
 *
 *  - SScribe_Batch_Step_Handler  : ajax_process_batch + the two
 *                                  helpers it uses exclusively
 *                                  (build_batch_response, restore_ob_level)
 *  - SScribe_Export_Finalizer    : ajax_finalize_export + finalize_export
 *  - SScribe_Cancel_Handler      : ajax_cancel_export,
 *                                  cleanup_cancelled_export,
 *                                  ajax_clear_session
 *  - SScribe_Session_Status      : ajax_check_active_session
 */
final class SScribe_Batch_Processor {

	use SScribe_Batch_Step_Handler;
	use SScribe_Export_Finalizer;
	use SScribe_Cancel_Handler;
	use SScribe_Session_Status;

	private const MAX_STORED_ERRORS          = 50;
	private const DEFAULT_FORMATS            = array( 'docx' );
	private const MAX_RETRIES                = 3;
	private const RETRY_TRANSIENT_CATEGORIES = array( 'network', 'timeout', 'rate_limit', 'temporary' );

	/**
	 * Number of pages to process in each batch.
	 *
	 * @var int
	 */
	private int $batch_size = 5;

	/**
	 * Diagnostics service.
	 *
	 * @var SScribe_Diagnostics|null
	 */
	private ?SScribe_Diagnostics $diagnostics = null;

	/**
	 * Page collector service.
	 *
	 * @var \SScribe_Page_Collector
	 */
	private readonly \SScribe_Page_Collector $collector;

	/**
	 * Zip handler service.
	 *
	 * @var \SScribe_Zip_Handler
	 */
	private readonly \SScribe_Zip_Handler $zip_handler;

	/**
	 * Session manager.
	 *
	 * @var \SScribe_Session
	 */
	private readonly \SScribe_Session $session;

	/**
	 * Logger interface.
	 *
	 * @var \SScribe_Logger_Interface
	 */
	private readonly \SScribe_Logger_Interface $logger;

	/**
	 * Batch file handler for download/delete/nonce operations.
	 *
	 * @var SScribe_Batch_File_Handler
	 */
	private readonly SScribe_Batch_File_Handler $file_handler;

	/**
	 * Export log instance.
	 *
	 * @var \SScribe_Export_Log|null
	 */
	private ?\SScribe_Export_Log $export_log = null;

	/**
	 * Current lock token for export operations.
	 *
	 * @var string|null
	 */
	private ?string $current_lock_token = null;

	/**
	 * Static flag to ensure shutdown cleanup is registered only once.
	 *
	 * @var bool
	 */
	private static bool $shutdown_registered = false;

	/**
	 * Active temp directory for shutdown cleanup (static to survive object destruction).
	 *
	 * Only set while ownership of the directory is still inside this PHP
	 * request. Cleared the moment the session has been persisted and the
	 * workspace has been handed off to the next batch step; if PHP dies
	 * before that point the value survives into shutdown_cleanup() and the
	 * workspace is then correctly classified as an orphan.
	 *
	 * @var string|null
	 */
	private static ?string $cleanup_temp_dir = null;

	/**
	 * Zip handler for shutdown cleanup.
	 *
	 * @var SScribe_Zip_Handler|null
	 */
	private static ?SScribe_Zip_Handler $cleanup_zip_handler = null;

	/**
	 * Logger for shutdown cleanup.
	 *
	 * @var SScribe_Logger_Interface|null
	 */
	private static ?SScribe_Logger_Interface $cleanup_logger = null;

	/**
	 * Adaptive metrics collector.
	 *
	 * @var \SScribe_Adaptive_Metrics|null
	 */
	private ?\SScribe_Adaptive_Metrics $adaptive_metrics = null;

	/**
	 * Rate limiter for export operations.
	 *
	 * @var SScribe_Export_Rate_Limiter|null
	 */
	private ?SScribe_Export_Rate_Limiter $rate_limiter = null;

	/**
	 * Cached capability to avoid repeated filter/validation calls per request.
	 *
	 * @var string|null
	 */
	private ?string $cached_required_capability = null;

	/**
	 * Export auditor.
	 *
	 * @var SScribe_Export_Auditor|null
	 */
	private ?SScribe_Export_Auditor $auditor = null;

	/**
	 * Resource monitor.
	 *
	 * @var SScribe_Export_Resource_Monitor|null
	 */
	private ?SScribe_Export_Resource_Monitor $resource_monitor = null;

	/**
	 * Lock manager for concurrent operations.
	 *
	 * @var SScribe_Export_Lock_Manager|null
	 */
	private ?SScribe_Export_Lock_Manager $lock_manager = null;

	/**
	 * Error handler for export failures.
	 *
	 * @var SScribe_Export_Error_Handler|null
	 */
	private ?SScribe_Export_Error_Handler $error_handler = null;

	/**
	 * Query controller for database operations.
	 *
	 * @var SScribe_Export_Query_Controller|null
	 */
	private ?SScribe_Export_Query_Controller $query_controller = null;

	/**
	 * Generate non-cryptographic random bytes for identifiers and suffixes.
	 *
	 * Used only for ZIP filename suffixes and similar non-security
	 * identifiers. For session tokens, signing keys, or any other
	 * security-sensitive use, use {@see random_bytes()} (which throws
	 * on entropy failure) or {@see sodium_crypto_secretbox_keygen()}.
	 *
	 * The fallback chain is intentional: this function must never throw
	 * because a non-crypto suffix is not worth a broken export. The
	 * first preference is the system CSPRNG ({@see random_bytes()});
	 * the second preference is the OpenSSL CSPRNG (kept for diagnostics
	 * in the rare environment where /dev/urandom is missing); the last
	 * resort is {@see wp_generate_password()} which is NOT a CSPRNG
	 * but is acceptable for filename uniqueness on the same host.
	 *
	 * @param int $length Number of bytes.
	 * @return string Raw binary bytes.
	 */
	private static function random_suffix_bytes( int $length ): string {
		try {
			return random_bytes( $length );
		} catch ( \Throwable $e ) {

			$strong = false;
			$bytes  = openssl_random_pseudo_bytes( $length, $strong );
			if ( $bytes && $strong ) {
				return $bytes;
			}

			return (string) wp_generate_password( $length, false );
		}
	}

	/**
	 * Get the adaptive metrics instance (lazy-loaded).
	 *
	 * @return \SScribe_Adaptive_Metrics
	 */
	private function get_adaptive_metrics(): \SScribe_Adaptive_Metrics {
		return $this->adaptive_metrics ??= new \SScribe_Adaptive_Metrics();
	}

	/**
	 * Get minimum file size thresholds for export validation.
	 *
	 * Filterable via 'sscribe_min_export_file_sizes' to allow host-specific adjustment.
	 *
	 * @return array<string, int> Format => minimum size in bytes.
	 */
	private function get_min_file_sizes(): array {
		return apply_filters(
			'sscribe_min_export_file_sizes',
			array(
				'docx'     => 8192,
				'pdf'      => 8192,
				'html'     => 512,
				'markdown' => 50,
			)
		);
	}

	/**
	 * Validate export result and check file integrity.
	 *
	 * @param object $result   Export result object.
	 * @param string $format   Export format.
	 * @param int    $page_id  Page ID.
	 * @return array Result with keys: is_valid, error, category, context.
	 */
	private function validate_export_result( object $result, string $format, int $page_id ): array {
		$min_sizes = $this->get_min_file_sizes();
		$min_size  = $min_sizes[ $format ] ?? 100;

		if ( ! $result->is_success() ) {
			$result_data = $result->get_data();
			return array(
				'is_valid' => false,
				'error'    => $result->get_error(),
				'category' => $result_data['error_category'] ?? 'unknown',
				'context'  => is_array( $result_data ) ? $result_data : array(),
			);
		}

		$file_path = $result->get_data()['path'] ?? '';
		$file_size = $result->get_data()['size'] ?? 0;
		clearstatcache( true, $file_path );
		$actual_size = ( ! empty( $file_path ) && file_exists( $file_path ) ) ? (int) filesize( $file_path ) : 0;

		if ( $actual_size < $min_size ) {
			$size_error = sprintf(
				/* translators: 1: Format, 2: Actual size, 3: Minimum size. */
				__( '%1$s file appears empty or corrupted (size: %2$d bytes, minimum: %3$d bytes).', 'sscribe-export-site-pages' ),
				strtoupper( $format ),
				$actual_size,
				$min_size
			);
			return array(
				'is_valid' => false,
				'error'    => $size_error,
				'category' => 'empty_file',
				'context'  => array(
					'file_path'     => $file_path,
					'actual_size'   => $actual_size,
					'reported_size' => $file_size,
				),
			);
		}

		if ( $file_size > 0 ) {
			$size_diff_ratio = abs( $actual_size - $file_size ) / $file_size;
			if ( $size_diff_ratio > 0.10 ) {
				$this->logger->warning(
					'Export file size mismatch',
					array(
						'format'        => $format,
						'page_id'       => $page_id,
						'reported_size' => $file_size,
						'actual_size'   => $actual_size,
						'diff_ratio'    => round( $size_diff_ratio * 100, 1 ) . '%',
					)
				);
			}
		}

		return array(
			'is_valid' => true,
			'error'    => null,
			'category' => 'success',
			'context'  => array(
				'file_path'   => $file_path,
				'actual_size' => $actual_size,
			),
		);
	}

	/**
	 * Dispatch export to all formats for a single page.
	 *
	 * @param array  $page_data   Page data from collector.
	 * @param string $temp_dir    Temporary directory path.
	 * @param int    $page_index  Page index in export order.
	 * @param int    $total       Total pages.
	 * @param array  $formats     Formats to export.
	 * @param string $session_id  Session ID.
	 * @param array  &$session    Session array (passed by reference for tracking).
	 * @param int    $page_id     Page ID.
	 * @return array Results with keys: export_success, successful_formats, export_errors.
	 */
	private function dispatch_formats( array $page_data, string $temp_dir, int $page_index, int $total, array $formats, string $session_id, array &$session, int $page_id ): array {
		$export_success     = false;
		$successful_formats = array();
		$export_errors      = array();

		foreach ( $formats as $format ) {
			$format_start = microtime( true );

			$format_options = array();
			if ( ! empty( $session['format_options'] ) ) {
				$format_options = (array) $session['format_options'];
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Per-format dynamic hook, third-party integrations may register listeners.
				$format_options = apply_filters( "sscribe_export_options_{$format}", $format_options, $page_id, $session_id );
			}

			try {
				$exporter = \SScribe_Exporter_Factory::create( $format );
			} catch ( SScribe_Validation_Exception $e ) {
				$this->logger->error( 'Invalid export format requested', array( 'format' => $format ) );
				$export_errors[] = array(
					'page_id'   => $page_id,
					'format'    => $format,
					'message'   => 'Invalid format: ' . $format,
					'category'  => 'configuration',
					'retryable' => false,
				);
				continue;
			}

			if ( method_exists( $exporter, 'apply_format_options' ) ) {
				$exporter->apply_format_options( $format_options );
			}

			$attempt = 0;
			$result  = null;

			$page_lang_raw = isset( $page_data['language'] ) ? (string) $page_data['language'] : '';
			$page_lang_key = '' !== $page_lang_raw ? sanitize_key( substr( $page_lang_raw, 0, 2 ) ) : '';
			$page_lang     = '' !== $page_lang_key ? strtoupper( $page_lang_key ) : 'ALL';
			$page_dir      = trailingslashit( $temp_dir ) . $page_lang;
			if ( ! is_dir( $page_dir ) ) {
				wp_mkdir_p( $page_dir );
			}

			$total_sleep_ms = 0;
			while ( $attempt < self::MAX_RETRIES ) {
				try {
					$result = $exporter->export( $page_data, $page_dir, $page_index, $total );
				} catch ( \Throwable $e ) {
					$result_data    = array(
						'error_category' => 'transient',
						'error_message'  => $e->getMessage(),
					);
					$result = new SScribe_Export_Result( false, $result_data );
				}

				$is_transient = false;
				if ( ! $result->is_success() ) {
					$result_data    = $result->get_data();
					$error_category = $result_data['error_category'] ?? 'unknown';
					$is_transient   = in_array( $error_category, self::RETRY_TRANSIENT_CATEGORIES, true );
				}

				if ( $result->is_success() || ! $is_transient ) {
					break;
				}

				if ( $attempt >= self::MAX_RETRIES - 1 ) {
					break;
				}

				if ( $this->export_log ) {
					$this->export_log->log_page_retry( $page_id, $format, $attempt + 1, $error_category );
				}

				$delay_ms = 100;
				if ( $total_sleep_ms + $delay_ms > 500 ) {
					break;
				}
				$total_sleep_ms += $delay_ms;
				usleep( $delay_ms * 1000 );
				++$attempt;

				try {
					$exporter = \SScribe_Exporter_Factory::create( $format );
				} catch ( SScribe_Validation_Exception $e ) {
					$this->logger->error( 'Invalid export format on retry', array( 'format' => $format ) );
					break;
				}
			}

			$exporter = null;

			$format_elapsed         = microtime( true ) - $format_start;
			$format_key             = 'format_time_' . $format;
			$session[ $format_key ] = min( 3600, ( $session[ $format_key ] ?? 0 ) + $format_elapsed );

			$file_path = $result->get_data()['path'] ?? '';
			$file_size = $result->get_data()['size'] ?? 0;

			$validation = $this->validate_export_result( $result, $format, $page_id );

			if ( ! $validation['is_valid'] ) {
				$export_errors[] = array(
					'format'   => strtoupper( $format ),
					'message'  => $validation['error'],
					'category' => $validation['category'],
					'context'  => $validation['context'],
				);

				if ( $this->export_log ) {
					$this->export_log->log_format_result( $page_id, $format, false, '', $validation['error'] );
				}

				if ( 'empty_file' === $validation['category'] ) {
					$this->logger->error(
						ucfirst( $format ) . ' export produced empty/corrupted file',
						array(
							'page_id'       => $page_id,
							'file_path'     => $file_path,
							'actual_size'   => $validation['context']['actual_size'] ?? 0,
							'reported_size' => $file_size,
							'min_size'      => 100,
						)
					);
				}
			} else {
				$export_success       = true;
				$successful_formats[] = $format;

				$format_size_key             = 'format_size_' . $format;
				$session[ $format_size_key ] = min( 1073741824, ( $session[ $format_size_key ] ?? 0 ) + ( $validation['context']['actual_size'] ?? 0 ) );

				$format_pages_key             = 'format_pages_' . $format;
				$session[ $format_pages_key ] = min( 10000, ( $session[ $format_pages_key ] ?? 0 ) + 1 );

				if ( $this->export_log ) {
					$this->export_log->log_format_result( $page_id, $format, true, $file_path );
				}

				$this->logger->debug(
					ucfirst( $format ) . ' generated successfully',
					array(
						'file'        => basename( $file_path ),
						'page_id'     => $page_id,
						'format'      => $format,
						'actual_size' => $validation['context']['actual_size'] ?? 0,
						'duration'    => round( $format_elapsed, 3 ),
					)
				);
			}
		}

		return array(
			'export_success'     => $export_success,
			'successful_formats' => $successful_formats,
			'export_errors'      => $export_errors,
		);
	}

	/**
	 * Get the rate limiter instance (lazy-loaded).
	 *
	 * @return SScribe_Export_Rate_Limiter
	 */
	private function get_rate_limiter(): SScribe_Export_Rate_Limiter {
		return $this->rate_limiter ??= new SScribe_Export_Rate_Limiter();
	}

	/**
	 * Parse and sanitize the per-format options payload from the request.
	 *
	 * Accepts any keys (future formats can add their own) but enforces
	 * scalar / scalar-array values to prevent object/array injection into
	 * the session store. Keys are passed through `sanitize_key()` so they
	 * are always safe to use as session keys.
	 *
	 * Extracted from `ajax_start_export()` so the parsing rules can be
	 * unit-tested in isolation without bringing up the full AJAX stack.
	 *
	 * @param mixed $raw Raw $_POST['format_options'] value.
	 * @return array Sanitized options (always an array, possibly empty).
	 */
	/**
	 * Allowlist of format_options keys accepted from the AJAX endpoint.
	 *
	 * Every key listed here is a known per-format option consumed by
	 * one of the SScribe exporters. Keys outside this list are
	 * silently dropped from incoming requests so an attacker cannot
	 * smuggle arbitrary data into the export pipeline, where it
	 * would later reach filters, renderers, or filesystem paths.
	 *
	 * Third-party exporters can extend this list via the
	 * `sscribe_format_option_keys` filter; new keys added there MUST
	 * also be `sanitize_key()`-compatible (lowercase ASCII, digits,
	 * underscore, hyphen) and MUST be validated against an allowlist
	 * inside the consumer exporter.
	 *
	 * @var array<int, string>
	 */
	public const FORMAT_OPTION_KEYS = array(
		'sscribe_docx_include_images',
		'sscribe_docx_include_toc',
		'sscribe_docx_template',
		'sscribe_html_include_css',
		'sscribe_html_responsive_images',
		'sscribe_md_absolute_urls',
		'sscribe_md_include_featured_image',
		'sscribe_md_include_frontmatter',
		'sscribe_pdf_include_images',
		'sscribe_pdf_include_page_numbers',
		'sscribe_pdf_page_size',
	);

	/**
	 * Parse and sanitize a format_options map from untrusted input.
	 *
	 * Each key is normalized via sanitize_key(). Each key MUST be on
	 * the {@see self::FORMAT_OPTION_KEYS} allowlist (extended via the
	 * `sscribe_format_option_keys` filter); keys outside the allowlist
	 * are dropped to prevent injection into exporter pipelines.
	 *
	 * Each scalar value is sanitized via sanitize_text_field(); array
	 * values have every element sanitized recursively. Object and
	 * other non-scalar inputs collapse to empty strings so the
	 * storage shape stays predictable.
	 *
	 * @param mixed $raw Untrusted input from $_POST['format_options'].
	 * @return array<string, string|array<int, string>> Sanitized map.
	 */
	public static function parse_format_options( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		/**
		 * Filter the allowlist of format_options keys accepted from
		 * the AJAX endpoint.
		 *
		 * @param array<int, string> $allowed_keys Default allowlist.
		 */
		$allowed_keys = apply_filters( 'sscribe_format_option_keys', self::FORMAT_OPTION_KEYS );
		if ( ! is_array( $allowed_keys ) ) {
			$allowed_keys = self::FORMAT_OPTION_KEYS;
		}
		$allowed_keys = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $key ): string => is_scalar( $key ) ? sanitize_key( (string) $key ) : '',
						array_slice( $allowed_keys, 0, 100 )
					)
				)
			)
		);

		$cleaned = array();
		foreach ( array_slice( $raw, 0, 100, true ) as $sscribe_opt_name => $sscribe_opt_value ) {
			$sscribe_opt_name = sanitize_key( (string) $sscribe_opt_name );
			if ( '' === $sscribe_opt_name ) {
				continue;
			}
			if ( ! in_array( $sscribe_opt_name, $allowed_keys, true ) ) {
				continue;
			}
			if ( is_array( $sscribe_opt_value ) ) {
				$sscribe_opt_value = array_map(
					static function ( $sscribe_v ): string {
						if ( ! is_scalar( $sscribe_v ) ) {
							return '';
						}
						return mb_substr( sanitize_text_field( (string) $sscribe_v ), 0, 500 );
					},
					array_slice( $sscribe_opt_value, 0, 20 )
				);
			} elseif ( ! is_scalar( $sscribe_opt_value ) ) {
				$sscribe_opt_value = '';
			} else {
				$sscribe_opt_value = mb_substr( sanitize_text_field( (string) $sscribe_opt_value ), 0, 500 );
			}
			$cleaned[ $sscribe_opt_name ] = $sscribe_opt_value;
		}

		return $cleaned;
	}

	/**
	 * Get the auditor instance (lazy-loaded).
	 *
	 * @return SScribe_Export_Auditor
	 */
	private function get_auditor(): SScribe_Export_Auditor {
		return $this->auditor ??= new SScribe_Export_Auditor();
	}

	/**
	 * Get the resource monitor instance (lazy-loaded).
	 *
	 * @return SScribe_Export_Resource_Monitor
	 */
	private function get_resource_monitor(): SScribe_Export_Resource_Monitor {
		return $this->resource_monitor ??= new SScribe_Export_Resource_Monitor();
	}

	/**
	 * Get the lock manager instance (lazy-loaded).
	 *
	 * @return SScribe_Export_Lock_Manager
	 */
	private function get_lock_manager(): SScribe_Export_Lock_Manager {
		return $this->lock_manager ??= new SScribe_Export_Lock_Manager( $this->logger );
	}

	/**
	 * Get the error handler instance (lazy-loaded).
	 *
	 * @return SScribe_Export_Error_Handler
	 */
	private function get_error_handler(): SScribe_Export_Error_Handler {
		return $this->error_handler ??= new SScribe_Export_Error_Handler();
	}

	/**
	 * Get the query controller instance (lazy-loaded).
	 *
	 * @return SScribe_Export_Query_Controller
	 */
	private function get_query_controller(): SScribe_Export_Query_Controller {
		if ( null === $this->query_controller ) {
			$this->query_controller = new SScribe_Export_Query_Controller(
				$this->get_rate_limiter(),
				$this->get_diagnostics(),
				$this->collector,
				$this->logger,
				$this->zip_handler,
				$this->get_adaptive_metrics(),
				$this->get_error_handler()
			);
		}
		return $this->query_controller;
	}

	/**
	 * Get the diagnostics instance (lazy-loaded).
	 *
	 * @return SScribe_Diagnostics
	 */
	private function get_diagnostics(): SScribe_Diagnostics {
		return $this->diagnostics ??= new SScribe_Diagnostics();
	}

	/**
	 * Initialize the batch processor.
	 *
	 * @param SScribe_Page_Collector|null     $collector    Page collector.
	 * @param SScribe_Zip_Handler|null        $zip_handler  Zip handler.
	 * @param SScribe_Session|null            $session      Session.
	 * @param SScribe_Logger_Interface|null   $logger       Logger.
	 * @param SScribe_Batch_File_Handler|null $file_handler File handler.
	 */
	public function __construct(
		?SScribe_Page_Collector $collector = null,
		?SScribe_Zip_Handler $zip_handler = null,
		?SScribe_Session $session = null,
		?SScribe_Logger_Interface $logger = null,
		?SScribe_Batch_File_Handler $file_handler = null
	) {
		$this->batch_size = (int) apply_filters( 'sscribe_batch_size', 5 );
		$this->batch_size = max( 1, min( 20, $this->batch_size ) );

		$this->collector    = $collector ?? new SScribe_Page_Collector();
		$this->zip_handler  = $zip_handler ?? new SScribe_Zip_Handler();
		$this->session      = $session ?? new SScribe_Session();
		$this->logger       = $logger ?? SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() );
		$this->file_handler = $file_handler ?? new SScribe_Batch_File_Handler(
			$this->get_rate_limiter(),
			$this->zip_handler,
			$this->logger,
			$this->get_auditor()
		);

		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			register_shutdown_function(
				array( self::class, 'shutdown_cleanup' )
			);
		}
	}

	/**
	 * Verify rate limit hasn't been exceeded.
	 *
	 * @param string $bucket Rate-limit bucket name. Use 'export' for starting
	 *                      a new export and 'export_batch' for batch
	 *                      continuation / finalize steps. The two-bucket split
	 *                      prevents a single long export from rate-limiting its
	 *                      own continuation steps: a 1000-page export that
	 *                      issues 200 batch calls should not also count
	 *                      against the 200/min "start a new export" budget.
	 * @return bool True when allowed; false when limited or unavailable.
	 */
	private function check_rate_limit( string $bucket = 'export' ): bool {
		return $this->get_rate_limiter()->check_rate_limit( $this->get_required_capability(), $bucket );
	}

	/**
	 * Log an audit event.
	 *
	 * @param string $action  Action name.
	 * @param array  $context Additional context.
	 */
	private function audit_log( string $action, array $context = array() ): void {
		$this->get_auditor()->log( $action, $context );
	}

	/**
	 * Check if there's enough memory for processing.
	 *
	 * @param int $buffer_mb Additional buffer in MB.
	 * @return bool True if memory is available.
	 */
	private function is_memory_available( int $buffer_mb = 10 ): bool {
		return $this->get_resource_monitor()->is_memory_available( $buffer_mb );
	}

	/**
	 * Check if there's enough time remaining for batch processing.
	 *
	 * @param float $batch_start_time Start time of batch.
	 * @param int   $buffer_seconds   Safety buffer in seconds.
	 * @return bool True if time is available.
	 */
	private function is_time_available( float $batch_start_time, int $buffer_seconds = 10 ): bool {
		return $this->get_resource_monitor()->is_time_available( $batch_start_time, $buffer_seconds );
	}

	/**
	 * Calculate remaining processing time.
	 *
	 * @param float $batch_start_time Start time of batch.
	 * @return float Remaining time in seconds.
	 */
	private function get_remaining_time( float $batch_start_time ): float {
		return $this->get_resource_monitor()->get_remaining_time( $batch_start_time );
	}

	/**
	 * Get current memory usage as percentage.
	 *
	 * @return float Memory usage percentage.
	 */
	private function get_memory_usage_percent(): float {
		return $this->get_resource_monitor()->get_memory_usage_percent();
	}

	/**
	 * Adjust batch size based on available resources.
	 *
	 * @param array  $formats Export formats being processed.
	 * @param string $hint    Optional hint from previous batch ('memory', 'timeout').
	 */
	private function optimize_batch_size( array $formats = array(), string $hint = '' ): void {
		$this->batch_size = $this->get_resource_monitor()->get_optimal_batch_size( $formats, $hint );

		$this->logger->debug(
			'Batch size optimized',
			array(
				'formats'        => $formats,
				'optimized_size' => $this->batch_size,
				'pause_hint'     => $hint,
			)
		);
	}

	/**
	 * Get warning message if memory might be insufficient.
	 *
	 * @param int   $page_count Number of pages.
	 * @param array $formats    Export formats.
	 * @return array|null Warning array or null if okay.
	 */
	private function get_memory_warning( int $page_count, array $formats ): ?array {
		return $this->get_resource_monitor()->get_memory_warning( $page_count, $formats );
	}

	/**
	 * Get the capability required for export operations.
	 *
	 * Cached on first call to avoid repeated `get_required()` lookups
	 * across the 200+ AJAX calls a single batch export makes.
	 *
	 * @return string Capability name.
	 */
	private function get_required_capability(): string {
		if ( null !== $this->cached_required_capability ) {
			return $this->cached_required_capability;
		}

		$this->cached_required_capability = SScribe_Capabilities::get_required();
		return $this->cached_required_capability;
	}

	/**
	 * Recursively count the export output files in a temp dir.
	 *
	 * Files are written one level deeper under the per-language layout
	 * (e.g. `<temp>/AR/P001-foo.docx`), so a flat `glob()` would only
	 * see the language subdirs and drastically under-count (or zero out)
	 * the file total. The recursive walk matches the layout that
	 * `SScribe_Zip_Handler::create_zip()` consumes.
	 *
	 * @param string $temp_dir Absolute path to the export's temp dir.
	 * @return int Number of regular files inside (any depth).
	 */
	private function count_temp_dir_files( string $temp_dir ): int {
		if ( '' === $temp_dir || ! is_dir( $temp_dir ) ) {
			return 0;
		}

		$count    = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $temp_dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $iterator as $entry ) {
			if ( $entry->isFile() ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Verify the session belongs to the current user.
	 *
	 * @param array  $session   Session data.
	 * @param string $session_id Session identifier.
	 * @return bool True if user owns the session.
	 */
	private function validate_session_ownership( array $session, string $session_id ): bool {
		$current_user_id = get_current_user_id();

		if ( ! isset( $session['user_id'] ) ) {
			$this->audit_log(
				'session_missing_user_id',
				array(
					'session_id'      => $session_id,
					'reason'          => 'missing_user_id',
					'attempting_user' => $current_user_id,
				)
			);
			return false;
		}

		if ( (int) $session['user_id'] !== $current_user_id ) {
			$this->audit_log(
				'session_access_denied',
				array(
					'session_id'      => $session_id,
					'session_user'    => $session['user_id'] ?? 'unknown',
					'attempting_user' => $current_user_id,
				)
			);
			return false;
		}

		$this->audit_log(
			'session_access_ok',
			array(
				'session_id'      => $session_id,
				'session_user'    => $session['user_id'],
				'attempting_user' => $current_user_id,
			)
		);

		return true;
	}

	/**
	 * Start a new export session via AJAX.
	 */
	public function ajax_start_export(): void {
		if ( ! check_ajax_referer( 'sscribe_export_nonce', 'nonce', false ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'invalid_nonce',
					'message' => __( 'Security check failed.', 'sscribe-export-site-pages' ),
				),
				403
			);
		}

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'permission_denied',
					'message' => __( 'You do not have permission to export pages.', 'sscribe-export-site-pages' ),
				),
				403
			);
		}

		$rate_check = $this->check_rate_limit();
		if ( false === $rate_check ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'     => 'rate_limited',
					'message'  => __( 'Too many requests. Please wait a moment and try again.', 'sscribe-export-site-pages' ),
					'retry'    => true,
					'retry_in' => 60000,
				),
				429
			);
		}

		$this->get_diagnostics()->self_heal();

		$memory_raised = wp_raise_memory_limit( 'admin' );
		$this->logger->debug( 'Memory limit raised', array( 'result' => $memory_raised ) );

		$this->audit_log( 'export_started' );
		$this->logger->debug( '=== START EXPORT ===' );

		$requested_language = SScribe_AJAX_Guard::post_text( 'language', '', 100 );
		$language           = $this->collector->normalize_language_code( $requested_language );
		if ( '' !== $requested_language && '' === $language ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'invalid_language',
					'message' => __( 'Invalid or inactive language.', 'sscribe-export-site-pages' ),
				),
				400
			);
		}
		$post_status = SScribe_AJAX_Guard::post_text( 'post_status', 'publish', 30 );

		$allowed_statuses = array( 'publish', 'private', 'draft', 'pending', 'future' );
		if ( ! in_array( $post_status, $allowed_statuses, true ) ) {
			$post_status = 'publish';
		}
		$formats_raw   = SScribe_AJAX_Guard::post_array( 'formats', 10 );
		$formats_input = array();
		foreach ( $formats_raw as $format_input ) {
			if ( is_scalar( $format_input ) && ! is_bool( $format_input ) ) {
				$formats_input[] = sanitize_key( (string) $format_input );
			}
		}
		$formats       = ! empty( $formats_input ) ? $formats_input : self::DEFAULT_FORMATS;
		if ( empty( $formats_input ) ) {
			$this->logger->debug(
				'No formats supplied in AJAX request : falling back to DEFAULT_FORMATS',
				array( 'default_formats' => self::DEFAULT_FORMATS )
			);
		}

		$formats = array_values(
			array_filter(
				$formats,
				function ( $format ) {
					return \SScribe_Exporter_Factory::is_supported( $format );
				}
			)
		);

		if ( empty( $formats ) ) {
			$this->logger->debug(
				'All requested formats failed is_supported() check : falling back to DEFAULT_FORMATS',
				array(
					'rejected_input' => $formats_input,
					'default_formats' => self::DEFAULT_FORMATS,
				)
			);
			$formats = self::DEFAULT_FORMATS;
		}

		$post_type = SScribe_AJAX_Guard::post_text( 'post_type', 'page', 30 );

		$format_options = self::parse_format_options( SScribe_AJAX_Guard::post_array( 'format_options', 100 ) );
		$valid_post_types = array( 'page', 'post', 'any' );
		if ( ! in_array( $post_type, $valid_post_types, true ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'invalid_post_type',
					'message' => sprintf(
						/* translators: %s: Submitted post type. */
						__( 'Invalid post type "%s".', 'sscribe-export-site-pages' ),
						$post_type
					),
				),
				400
			);
		}

		$this->logger->debug(
			'Export params',
			array(
				'language'    => $language,
				'post_status' => $post_status,
				'post_type'   => $post_type,
				'formats'     => $formats,
			)
		);

		$user_id = get_current_user_id();

		if ( null !== $this->session->get_active_session_data( $user_id ) ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'concurrent_export',
					'message' => __( 'You already have an export in progress. Please wait for it to complete or refresh the page.', 'sscribe-export-site-pages' ),
				),
				409
			);
		}

		if ( ! empty( $language ) && $this->collector->is_wpml_active() ) {
			$valid_languages = wp_list_pluck( $this->collector->get_wpml_languages(), 'code' );
			if ( ! in_array( $language, $valid_languages, true ) ) {
				SScribe_AJAX_Guard::error(
					array(
						'code'    => 'invalid_language',
						'message' => __( 'Invalid language code specified.', 'sscribe-export-site-pages' ),
					),
					400
				);
			}
		}

		$page_ids      = $this->collector->get_page_ids( $language, $post_status, $post_type );
		$total         = count( $page_ids );
		$available_total = $this->collector->get_page_count_only( $language, $post_status, $post_type );
		$partial_export = $available_total > $total;
		$page_id_cap    = 10000;

		$current_lang = 'default';
		if ( $this->collector->is_wpml_active() ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML hook, not our filter.
			$current_lang = apply_filters( 'wpml_current_language', null );
		}

		$this->logger->debug(
			'Page IDs retrieved',
			array(
				'total'              => $total,
				'language_requested' => $language,
				'post_status'        => $post_status,
				'current_wpml_lang'  => $current_lang ?? 'n/a',
				'ids_sample'         => array_slice( $page_ids, 0, 10 ),
				'memory_usage'       => size_format( memory_get_usage( true ) ),
				'memory_peak'        => size_format( memory_get_peak_usage( true ) ),
			)
		);

		if ( 0 === $total ) {
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'no_pages_selected',
					'message' => __( 'No pages found matching the selected criteria.', 'sscribe-export-site-pages' ),
				),
				400
			);
		}

		try {
			$temp_dir = $this->zip_handler->create_temp_dir();
			self::$cleanup_temp_dir    = $temp_dir;
			self::$cleanup_zip_handler = $this->zip_handler;
			self::$cleanup_logger      = $this->logger;
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Failed to create temp directory',
				array(
					'exception' => $e->getMessage(),
				)
			);
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'workspace_init_failed',
					'message' => __( 'Failed to initialize export directory. Please try again.', 'sscribe-export-site-pages' ),
				),
				500
			);
		}

		$session_id = $this->session->create(
			array(
				'temp_dir'          => $temp_dir,
				'total'             => $total,
				'processed'         => 0,
				'status'            => 'processing',
				'language'          => $language,
				'post_type'         => $post_type,
				'post_status'       => $post_status,
				'formats'           => $formats,
				'errors'            => array(),
				'structured_errors' => array(),
				'start_time'        => microtime( true ),
				'cancelled'         => false,
				'user_id'           => $user_id,
				'format_options'    => $format_options,
			)
		);

		$this->logger->debug(
			'Session created',
			array(
				'session_id' => $session_id,
				'temp_dir'   => $temp_dir,
			)
		);

		if ( empty( $session_id ) ) {

			if ( ! empty( $temp_dir ) && is_dir( $temp_dir ) ) {
				$this->zip_handler->delete_directory( $temp_dir );

				self::$cleanup_temp_dir = null;
			}
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'session_create_failed',
					'message' => __( 'Failed to create export session. Please try again.', 'sscribe-export-site-pages' ),
				),
				500
			);
		}

		if ( ! $this->session->set_page_ids( $session_id, $page_ids ) ) {
			$this->session->delete( $session_id );
			if ( ! empty( $temp_dir ) && is_dir( $temp_dir ) ) {
				$this->zip_handler->delete_directory( $temp_dir );
				self::$cleanup_temp_dir = null;
			}
			SScribe_AJAX_Guard::error(
				array(
					'code'    => 'page_list_failed',
					'message' => __( 'Failed to store the export page list. Please try again.', 'sscribe-export-site-pages' ),
				),
				500
			);
		}

		if ( $user_id ) {
			$active_sid = get_transient( 'sscribe_active_sid_' . $user_id );
			if ( $active_sid !== $session_id && is_string( $active_sid ) && '0' !== $active_sid ) {
				$this->logger->warning(
					'Concurrent session creation detected : cleaning up duplicate',
					array(
						'user_id'         => $user_id,
						'our_session'     => $session_id,
						'winning_session' => $active_sid,
					)
				);
				$this->session->delete( $session_id );
				if ( ! empty( $temp_dir ) && is_dir( $temp_dir ) ) {
					$this->zip_handler->delete_directory( $temp_dir );

					self::$cleanup_temp_dir = null;
				}
				SScribe_AJAX_Guard::error(
					array(
						'code'    => 'concurrent_export',
						'message' => __( 'Another export was started. Please try again.', 'sscribe-export-site-pages' ),
					),
					409
				);
			}
		}

		$this->export_log = new SScribe_Export_Log( $session_id );
		$this->export_log->set_total_pages( $total );

		$this->logger->set_session_id( $session_id );

		$export_stats = new SScribe_Export_Stats();
		$export_stats->start_export(
			$session_id,
			$user_id,
			array(
				'total_pages' => $total,
				'formats'     => $formats,
			)
		);

		$memory_warning = $this->get_memory_warning( $total, $formats );

		$response = array(
			'session_id'     => $session_id,
			'total'          => $total,
			'batch_size'     => $this->batch_size,
			'message'        => sprintf(
				/* translators: %d: Number of pages found. */
				__( 'Found %d pages. Starting export...', 'sscribe-export-site-pages' ),
				$total
			),
		);
		if ( $partial_export ) {
			$response['partial_export']      = true;
			$response['available_total']     = $available_total;
			$response['page_id_cap']         = $page_id_cap;
			$response['partial_export_message'] = sprintf(
				/* translators: 1: Number of pages exported, 2: Total available pages, 3: Cap limit. */
				__( 'Export capped at %1$d pages. %2$d pages were available. Run additional exports in batches of %3$d to cover the rest.', 'sscribe-export-site-pages' ),
				$total,
				$available_total,
				$page_id_cap
			);
		}
		if ( null !== $memory_warning ) {
			$response['memory_warning'] = $memory_warning;
		}

		$sscribe_is_debug = SSCRIBE_DEBUG;
		if ( $sscribe_is_debug ) {
			$response['debug_info'] = array(
				'page_ids_count'    => $total,
				'language'          => $language,
				'post_status'       => $post_status,
				'post_type'         => $post_type,
				'current_wpml_lang' => $current_lang ?? 'n/a',
				'temp_dir'          => basename( $temp_dir ),
				'session_type'      => $this->session->get_storage_type(),
				'memory_usage'      => size_format( memory_get_usage( true ) ),
				'php_version'       => PHP_VERSION,
			);
		}

		self::$cleanup_temp_dir    = null;
		self::$cleanup_zip_handler = null;
		self::$cleanup_logger      = null;

		SScribe_AJAX_Guard::success( $response );
	}

	/**
	 * Run health check diagnostics via AJAX.
	 */
	public function ajax_health_check(): void {
		$this->get_query_controller()->ajax_health_check();
	}

	/**
	 * Handle file download via AJAX.
	 */
	public function ajax_download(): void {
		$this->file_handler->ajax_download();
	}

	/**
	 * Get export status counts via AJAX.
	 */
	public function ajax_get_status_counts(): void {
		$this->get_query_controller()->ajax_get_status_counts( $this->get_required_capability() );
	}

	/**
	 * Batch status counts for many languages via AJAX.
	 */
	public function ajax_get_all_status_counts(): void {
		$this->get_query_controller()->ajax_get_all_status_counts( $this->get_required_capability() );
	}

	/**
	 * Delete an export via AJAX.
	 */
	public function ajax_delete_export(): void {
		$this->file_handler->ajax_delete_export();
	}

	/**
	 * Get export log entries via AJAX.
	 */
	public function ajax_get_export_log(): void {
		$this->get_query_controller()->ajax_get_export_log( $this->get_required_capability() );
	}

	/**
	 * Assemble diagnostics data for error responses.
	 *
	 * @param array $structured_errors Structured error data.
	 * @param array $string_errors     String error data.
	 * @return array Diagnostics payload.
	 */
	private function build_error_diagnostics_payload( array $structured_errors, array $string_errors = array() ): array {
		return $this->get_error_handler()->build_diagnostics_payload( $structured_errors, $string_errors );
	}

	/**
	 * Release export lock for a session.
	 *
	 * @param string      $session_id Session ID.
	 * @param string|null $lock_token Lock token to release.
	 * @return bool True if lock was released.
	 */
	private function release_lock( string $session_id, ?string $lock_token = null ): bool {
		return $this->get_lock_manager()->release_lock( $session_id, $lock_token ?? $this->current_lock_token );
	}

	/**
	 * Run preflight checks before export via AJAX.
	 */
	public function ajax_preflight_check(): void {
		$this->get_query_controller()->ajax_preflight_check( $this->get_required_capability() );
	}

	/**
	 * Get export preview via AJAX.
	 */
	public function ajax_get_export_preview(): void {
		$this->get_query_controller()->ajax_get_export_preview( $this->get_required_capability() );
	}

	/**
	 * Get recent exports list via AJAX.
	 */
	public function ajax_get_recent_exports(): void {
		$this->get_query_controller()->ajax_get_recent_exports( $this->get_required_capability() );
	}

	/**
	 * Get support information via AJAX.
	 */
	public function ajax_get_support_info(): void {
		$this->get_query_controller()->ajax_get_support_info( SScribe_Capabilities::get_health_required() );
	}

	/**
	 * Refresh download nonce via AJAX.
	 */
	public function ajax_refresh_download_nonce(): void {
		$this->file_handler->ajax_refresh_download_nonce();
	}

	/**
	 * Refresh the main export nonce via AJAX.
	 *
	 * Long-running batch exports can outlive the WP nonce lifetime (default
	 * 12h, default 24h on some sites). Without a refresh hook the front-end
	 * JS would 403 on every subsequent batch step. The JS calls this on a
	 * 403 response, then retries the original request.
	 */
	public function ajax_refresh_nonce(): void {
		// WP convention: verify nonce BEFORE capability to avoid leaking which
		// unprivileged visitors get a permission error vs an invalid-nonce error.
		$nonce_ok = check_ajax_referer( 'sscribe_export_nonce', 'nonce', false );
		if ( ! $nonce_ok ) {
			wp_send_json_error(
				array(
					'code'    => 'invalid_nonce',
					'message' => __( 'Invalid nonce.', 'sscribe-export-site-pages' ),
				),
				403
			);
			return;
		}

		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_send_json_error(
				array(
					'code'    => 'permission_denied',
					'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ),
				),
				403
			);
			return;
		}

		wp_send_json_success(
			array(
				'nonce' => wp_create_nonce( 'sscribe_export_nonce' ),
			)
		);
	}

	/**
	 * Static shutdown handler : cleans up any orphaned temp directory if the
	 * export was interrupted before finalize_export() could run.
	 *
	 * Registered once per process via register_shutdown_function() in __construct.
	 *
	 * Lifecycle invariant:
	 *   - $cleanup_temp_dir is set the moment create_temp_dir() succeeds
	 *     inside start_export().
	 *   - $cleanup_temp_dir is cleared on every error path inside
	 *     start_export() (workspace_init_failed / session_create_failed /
	 *     page_list_failed / concurrent_export).
	 *   - $cleanup_temp_dir is cleared on the SUCCESS path of start_export()
	 *     immediately before wp_send_json_success(). PHP-FPM worker shutdown
	 *     then runs this handler; because the static was nulled, no deletion
	 *     happens and the workspace survives into the next batch step.
	 *
	 * If PHP dies (fatal, OOM, max_execution_time) between setting the state
	 * and clearing it on success, this handler still finds the workspace on
	 * disk and reclaims it. The race we previously saw — start_export
	 * returning a successful response, then shutdown wiping the workspace
	 * the JS client was about to use for step 2 — is impossible now because
	 * the static is nulled in the same function that sends the response.
	 */
	public static function shutdown_cleanup(): void {
		$temp_dir = self::$cleanup_temp_dir;
		if ( null === $temp_dir || ! is_dir( $temp_dir ) ) {
			self::$cleanup_temp_dir    = null;
			self::$cleanup_zip_handler = null;
			self::$cleanup_logger      = null;
			return;
		}

		$zip_handler = self::$cleanup_zip_handler;
		$logger      = self::$cleanup_logger;

		self::$cleanup_temp_dir    = null;
		self::$cleanup_zip_handler = null;
		self::$cleanup_logger      = null;

		if ( ! $zip_handler || ! $logger ) {
			return;
		}

		$zip_handler->delete_directory( $temp_dir );
		$logger->debug(
			'Shutdown cleanup removed orphaned temp directory',
			array( 'temp_dir' => $temp_dir )
		);
	}
}
