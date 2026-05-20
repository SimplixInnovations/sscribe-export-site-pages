<?php
/**
 * SScribe Exception
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SScribe_Exception extends Exception {

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

	protected string $error_code;

	protected array $error_data;

	protected int $http_status_code;

	protected bool $should_log = true;

	protected bool $recoverable = false;

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

	public function get_error_code(): string {
		return $this->error_code;
	}

	public function get_error_data(): array {
		return array_merge(
			$this->error_data,
			array( 'status' => $this->http_status_code )
		);
	}

	public function get_http_status_code(): int {
		return $this->http_status_code;
	}

	public function should_log(): bool {
		return $this->should_log;
	}

	public function is_recoverable(): bool {
		return $this->recoverable;
	}

	public function to_wp_error(): \WP_Error {
		return new \WP_Error(
			$this->error_code,
			$this->getMessage(),
			$this->get_error_data()
		);
	}

	public function to_scribe_error(): \SScribe_Error {
		return \SScribe_Error::from_template(
			$this->error_code,
			$this->error_data
		);
	}

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
