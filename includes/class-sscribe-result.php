<?php
declare(strict_types=1);

/**
 * Result pattern for consistent error handling.
 *
 * @package SScribe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Result
 *
 * Represents the result of an operation that can succeed or fail.
 */
class SScribe_Result {

	/**
	 * Whether the operation succeeded.
	 *
	 * @var bool
	 */
	private readonly bool $success;

	/**
	 * The result data (on success).
	 *
	 * @var mixed
	 */
	private readonly mixed $data;

	/**
	 * Error message (on failure).
	 *
	 * @var string|null
	 */
	private readonly ?string $error;

	/**
	 * Additional context (on failure).
	 *
	 * @var array
	 */
	private readonly array $context;

	/**
	 * Private constructor - use factory methods.
	 *
	 * @param bool        $success Whether successful.
	 * @param mixed       $data    Result data.
	 * @param string|null $error   Error message.
	 * @param array       $context Additional context.
	 */
	private function __construct( bool $success, $data = null, ?string $error = null, array $context = array() ) {
		$this->success = $success;
		$this->data    = $data;
		$this->error   = $error;
		$this->context = $context;
	}

	/**
	 * Create a successful result.
	 *
	 * @param mixed $data Result data.
	 * @return self
	 */
	public static function success( $data = null ): self {
		return new self( true, $data );
	}

	/**
	 * Create a failed result.
	 *
	 * @param string $error   Error message.
	 * @param array  $context Additional context.
	 * @return self
	 */
	public static function failure( string $error, array $context = array() ): self {
		return new self( false, null, $error, $context );
	}

	/**
	 * Check if the operation succeeded.
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return $this->success;
	}

	/**
	 * Check if the operation failed.
	 *
	 * @return bool
	 */
	public function is_failure(): bool {
		return ! $this->success;
	}

	/**
	 * Get the result data.
	 *
	 * @return mixed|null
	 */
	public function get_data(): mixed {
		return $this->data;
	}

	/**
	 * Get the error message.
	 *
	 * @return string|null
	 */
	public function get_error(): ?string {
		return $this->error;
	}

	/**
	 * Get the error context.
	 *
	 * @return array
	 */
	public function get_context(): array {
		return $this->context;
	}
}
