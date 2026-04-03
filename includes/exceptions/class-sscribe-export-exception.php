<?php
/**
 * Export-related exception for SScribe.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exceptions/class-sscribe-exception.php';

/**
 * Class SScribe_Export_Exception
 *
 * Thrown when an export operation fails.
 */
class SScribe_Export_Exception extends SScribe_Exception {

	/**
	 * Page ID being exported when error occurred.
	 */
	protected int $page_id;

	/**
	 * Export format being processed.
	 */
	protected string $format;

	/**
	 * Whether the export can be retried.
	 */
	protected bool $retryable = false;

	/**
	 * Constructor.
	 *
	 * @param string|null    $message    Custom message.
	 * @param int            $page_id    Page ID being exported.
	 * @param string         $format     Export format (docx, pdf, html, markdown).
	 * @param array          $context    Additional context.
	 * @param Throwable|null $previous   Previous exception.
	 */
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
			$format ?: 'unknown'
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

	/**
	 * Get the page ID that failed.
	 */
	public function get_page_id(): int {
		return $this->page_id;
	}

	/**
	 * Get the export format.
	 */
	public function get_format(): string {
		return $this->format;
	}

	/**
	 * Check if the export can be retried.
	 */
	public function is_retryable(): bool {
		return $this->retryable;
	}

	/**
	 * Set whether the export can be retried.
	 */
	public function set_retryable( bool $retryable ): self {
		$this->retryable = $retryable;
		return $this;
	}
}
