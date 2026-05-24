<?php
/**
 * SScribe Export Exception
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exceptions/class-sscribe-exception.php';

/**
 * Exception for export operation failures.
 */
class SScribe_Export_Exception extends SScribe_Exception {

	/**
	 * Page ID being exported.
	 *
	 * @var int
	 */
	protected int $page_id;

	/**
	 * Export format (pdf, docx, html, markdown).
	 *
	 * @var string
	 */
	protected string $format;

	/**
	 * Whether the export can be retried.
	 *
	 * @var bool
	 */
	protected bool $retryable = false;

	/**
	 * Create a new export exception.
	 *
	 * @param string|null    $message  Error message.
	 * @param int            $page_id  Page ID being exported.
	 * @param string         $format   Export format.
	 * @param array          $context  Additional context.
	 * @param Throwable|null $previous Previous exception.
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

	/**
	 * Get the page ID.
	 *
	 * @return int
	 */
	public function get_page_id(): int {
		return $this->page_id;
	}

	/**
	 * Get the export format.
	 *
	 * @return string
	 */
	public function get_format(): string {
		return $this->format;
	}

	/**
	 * Check if the export can be retried.
	 *
	 * @return bool
	 */
	public function is_retryable(): bool {
		return $this->retryable;
	}

	/**
	 * Set whether the export is retryable.
	 *
	 * @param bool $retryable Retryable flag.
	 * @return self
	 */
	public function set_retryable( bool $retryable ): self {
		$this->retryable = $retryable;
		return $this;
	}
}
