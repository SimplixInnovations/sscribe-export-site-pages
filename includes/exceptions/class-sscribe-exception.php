<?php
/**
 * Base exception class for SScribe.
 *
 * Provides structured error handling with error codes, HTTP status,
 * and integration with WordPress WP_Error and SScribe_Error.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Exception
 *
 * Base exception for all SScribe-specific errors.
 * Follows WooCommerce's WC_Data_Exception pattern for enterprise-grade error handling.
 */
class SScribe_Exception extends Exception {

	/**
	 * Error code constants (machine-readable).
	 */
	public const CODE_MEMORY_EXHAUSTED       = 'E_EXPORT_001';
	public const CODE_PERMISSION_DENIED      = 'E_EXPORT_002';
	public const CODE_INVALID_DATA           = 'E_EXPORT_003';
	public const CODE_DEPENDENCY_MISSING     = 'E_EXPORT_004';
	public const CODE_ZIP_FAILED             = 'E_EXPORT_005';
	public const CODE_TIMEOUT                = 'E_EXPORT_006';
	public const CODE_DISK_SPACE             = 'E_EXPORT_007';
	public const CODE_SESSION_EXPIRED        = 'E_EXPORT_008';
	public const CODE_SESSION_CORRUPTED      = 'E_EXPORT_009';
	public const CODE_RATE_LIMITED           = 'E_EXPORT_010';
	public const CODE_VALIDATION_FAILED      = 'E_EXPORT_011';
	public const CODE_EXPORT_FAILED          = 'E_EXPORT_012';
	public const CODE_PDF_GENERATION_FAILED  = 'E_EXPORT_013';
	public const CODE_DOCX_GENERATION_FAILED = 'E_EXPORT_014';
	public const CODE_UNEXPECTED_ERROR       = 'E_EXPORT_999';

	/**
	 * Machine-readable error code.
	 *
	 * @var string
	 */
	protected string $error_code;

	/**
	 * Additional error data/context.
	 *
	 * @var array
	 */
	protected array $error_data;

	/**
	 * HTTP status code for the error.
	 *
	 * @var int
	 */
	protected int $http_status_code;

	/**
	 * Whether this error should be logged.
	 *
	 * @var bool
	 */
	protected bool $should_log = true;

	/**
	 * Whether this error is recoverable.
	 *
	 * @var bool
	 */
	protected bool $recoverable = false;

	/**
	 * Constructor.
	 *
	 * @param string         $error_code       Machine-readable error code (e.g., 'E_EXPORT_001').
	 * @param string         $message          Human-readable error message.
	 * @param int            $http_status_code HTTP status code (default 500).
	 * @param array          $error_data       Additional error context.
	 * @param Throwable|null $previous     Previous exception for chaining.
	 */
	public function __construct(
		string $error_code,
		string $message,
		int $http_status_code = 500,
		array $error_data = array(),
		?Throwable $previous = null
	) {
		$this->error_code       = $error_code;
		$this->error_data       = $error_data;
		$this->http_status_code = $http_status_code;

		parent::__construct( $message, $http_status_code, $previous );
	}

	/**
	 * Get the machine-readable error code.
	 *
	 * @return string
	 */
	public function get_error_code(): string {
		return $this->error_code;
	}

	/**
	 * Get the error data/context.
	 *
	 * @return array
	 */
	public function get_error_data(): array {
		return array_merge(
			$this->error_data,
			array( 'status' => $this->http_status_code )
		);
	}

	/**
	 * Get the HTTP status code.
	 *
	 * @return int
	 */
	public function get_http_status_code(): int {
		return $this->http_status_code;
	}

	/**
	 * Check if this error should be logged.
	 *
	 * @return bool
	 */
	public function should_log(): bool {
		return $this->should_log;
	}

	/**
	 * Check if this error is recoverable.
	 *
	 * @return bool
	 */
	public function is_recoverable(): bool {
		return $this->recoverable;
	}

	/**
	 * Convert to WordPress WP_Error object.
	 *
	 * @return WP_Error
	 */
	public function to_wp_error(): \WP_Error {
		return new \WP_Error(
			$this->error_code,
			$this->getMessage(),
			$this->get_error_data()
		);
	}

	/**
	 * Convert to SScribe_Error object.
	 *
	 * @return SScribe_Error
	 */
	public function to_scribe_error(): \SScribe_Error {
		return \SScribe_Error::from_template(
			$this->error_code,
			$this->error_data
		);
	}

	/**
	 * Convert to array for JSON response.
	 *
	 * @param bool $include_details Include detailed info (debug mode).
	 * @return array
	 */
	public function to_array( bool $include_details = false ): array {
		$data = array(
			'code'       => $this->error_code,
			'message'    => $this->getMessage(),
			'httpStatus' => $this->http_status_code,
		);

		if ( $include_details ) {
			$data['context']     = $this->error_data;
			$data['file']        = $this->getFile();
			$data['line']        = $this->getLine();
			$data['trace']       = $this->getTraceAsString();
			$data['recoverable'] = $this->recoverable;
		}

		return $data;
	}

	/**
	 * Create from an SScribe_Error template.
	 *
	 * @param string $error_code Error code.
	 * @param array  $context    Context data for interpolation.
	 * @return static
	 */
	public static function from_template( string $error_code, array $context = array() ): self {
		$templates = \SScribe_Error::get_templates();

		if ( ! isset( $templates[ $error_code ] ) ) {
			return new static(
				self::CODE_UNEXPECTED_ERROR,
				'An unexpected error occurred.',
				500,
				$context
			);
		}

		$template = $templates[ $error_code ];
		$message  = \SScribe_Error::interpolate( $template['message'], $context );

		$http_status = match ( $template['severity'] ) {
			\SScribe_Error::SEVERITY_CRITICAL => 500,
			\SScribe_Error::SEVERITY_ERROR    => 400,
			\SScribe_Error::SEVERITY_WARNING  => 200,
			default                           => 500,
		};

		return new static(
			$error_code,
			$message,
			$http_status,
			$context
		);
	}
}
