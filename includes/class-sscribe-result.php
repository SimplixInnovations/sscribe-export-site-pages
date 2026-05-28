<?php
/**
 * SScribe Result
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
 * Immutable result object for operation outcomes.
 */
class SScribe_Result {

	/**
	 * Whether the operation succeeded.
	 *
	 * @var bool
	 */
	private readonly bool $success;

	/**
	 * Operation payload data.
	 *
	 * @var mixed
	 */
	private readonly mixed $data;

	/**
	 * Error message on failure.
	 *
	 * @var string|null
	 */
	private readonly ?string $error;

	/**
	 * Additional context metadata.
	 *
	 * @var array
	 */
	private readonly array $context;

	/**
	 * Warnings collected during the operation.
	 *
	 * @var array
	 */
	private readonly array $warnings;

	/**
	 * Create a new result instance.
	 *
	 * @param bool   $success  Whether operation succeeded.
	 * @param mixed  $data     Operation payload.
	 * @param string $error    Error message on failure.
	 * @param array  $context  Additional context.
	 * @param array  $warnings Warnings collected during execution.
	 */
	private function __construct( bool $success, mixed $data = null, ?string $error = null, array $context = array(), array $warnings = array() ) {
		$this->success  = $success;
		$this->data     = $data;
		$this->error    = $error;
		$this->context  = $context;
		$this->warnings = $warnings;
	}

	/**
	 * Create a successful result.
	 *
	 * @param mixed $data     Optional payload.
	 * @param array $warnings Optional warnings collected during execution.
	 * @return self
	 */
	public static function success( mixed $data = null, array $warnings = array() ): self {
		return new self( true, $data, null, array(), $warnings );
	}

	/**
	 * Create a failure result.
	 *
	 * @param string $error   Error message.
	 * @param array  $context Additional context.
	 * @return self
	 */
	public static function failure( string $error, array $context = array() ): self {
		return new self( false, null, $error, $context );
	}

	/**
	 * Check if operation succeeded.
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return $this->success;
	}

	/**
	 * Check if operation failed.
	 *
	 * @return bool
	 */
	public function is_failure(): bool {
		return ! $this->success;
	}

	/**
	 * Get the result data.
	 *
	 * @return mixed
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
	 * Get the context metadata.
	 *
	 * @return array
	 */
	public function get_context(): array {
		return $this->context;
	}

	/**
	 * Get warnings collected during the operation.
	 *
	 * @return array
	 */
	public function get_warnings(): array {
		return $this->warnings;
	}

	/**
	 * Check if there are any warnings.
	 *
	 * @return bool
	 */
	public function has_warnings(): bool {
		return ! empty( $this->warnings );
	}
}
