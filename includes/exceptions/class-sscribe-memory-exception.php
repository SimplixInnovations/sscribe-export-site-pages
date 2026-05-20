<?php
/**
 * SScribe Memory Exception
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exceptions/class-sscribe-exception.php';

class SScribe_Memory_Exception extends SScribe_Exception {

	protected int $memory_limit;

	protected int $memory_used;

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

	public function get_memory_limit(): int {
		return $this->memory_limit;
	}

	public function get_memory_used(): int {
		return $this->memory_used;
	}

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
