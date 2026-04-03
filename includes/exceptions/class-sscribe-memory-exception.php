<?php
/**
 * Memory-related exception for SScribe.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exceptions/class-sscribe-exception.php';

/**
 * Class SScribe_Memory_Exception
 *
 * Thrown when memory limits are exceeded during export operations.
 */
class SScribe_Memory_Exception extends SScribe_Exception {

	/**
	 * Memory limit that was exceeded.
	 */
	protected int $memory_limit;

	/**
	 * Memory used at time of exception.
	 */
	protected int $memory_used;

	/**
	 * Constructor.
	 *
	 * @param string|null    $message       Custom message.
	 * @param int            $memory_limit  Memory limit in bytes.
	 * @param int            $memory_used   Memory used in bytes.
	 * @param array          $context       Additional context.
	 * @param Throwable|null $previous      Previous exception.
	 */
	public function __construct(
		?string $message = null,
		int $memory_limit = 0,
		int $memory_used = 0,
		array $context = array(),
		?Throwable $previous = null
	) {
		$this->memory_limit = $memory_limit;
		$this->memory_used  = $memory_used;

		$context = array_merge(
			$context,
			array(
				'memory_limit'     => size_format( $memory_limit ),
				'memory_used'      => size_format( $memory_used ),
				'memory_limit_raw' => $memory_limit,
				'memory_used_raw'  => $memory_used,
			)
		);

		$message = $message ?? sprintf(
			'Memory limit of %s exceeded. Used: %s',
			size_format( $memory_limit ),
			size_format( $memory_used )
		);

		parent::__construct(
			self::CODE_MEMORY_EXHAUSTED,
			$message,
			500,
			$context,
			$previous
		);
	}

	/**
	 * Get the memory limit that was exceeded.
	 */
	public function get_memory_limit(): int {
		return $this->memory_limit;
	}

	/**
	 * Get the memory used at time of exception.
	 */
	public function get_memory_used(): int {
		return $this->memory_used;
	}

	/**
	 * Get recommended memory limit based on current usage.
	 */
	public function get_recommended_memory_limit(): int {
		$current_limit = $this->memory_limit;
		$used          = $this->memory_used;

		$recommended = max( $used * 2, $current_limit * 2 );
		$recommended = min( $recommended, 1073741824 );

		$steps = array( 256, 512, 768, 1024 );
		$found = $current_limit / 1024 / 1024;
		foreach ( $steps as $step ) {
			if ( $step > $found ) {
				return $step * 1024 * 1024;
			}
		}

		return 1073741824;
	}
}
