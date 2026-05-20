<?php
/**
 * SScribe Export Exception
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exceptions/class-sscribe-exception.php';

class SScribe_Export_Exception extends SScribe_Exception {

	protected int $page_id;

	protected string $format;

	protected bool $retryable = false;

	public function __construct(
		?string $message = null,
		int $page_id = 0,
		string $format = '',
		array $context = array(),
		?Throwable $previous = null
	) {
		$this->page_id = $page_id;
		$this->format  = $format;

		$context = array_merge(
			$context,
			array(
				'page_id' => $page_id,
				'format'  => $format,
			)
		);

		$message = $message ?? sprintf(
			'Export failed for page %d in %s format',
			$page_id,
			'' !== $format ? $format : 'unknown'
		);

		$error_code = match ( strtolower( $format ) ) {
			'pdf'   => self::CODE_PDF_GENERATION_FAILED,
			'docx'  => self::CODE_DOCX_GENERATION_FAILED,
			default => self::CODE_EXPORT_FAILED,
		};

		parent::__construct(
			$error_code,
			$message,
			500,
			$context,
			$previous
		);
	}

	public function get_page_id(): int {
		return $this->page_id;
	}

	public function get_format(): string {
		return $this->format;
	}

	public function is_retryable(): bool {
		return $this->retryable;
	}

	public function set_retryable( bool $retryable ): self {
		$this->retryable = $retryable;
		return $this;
	}
}
