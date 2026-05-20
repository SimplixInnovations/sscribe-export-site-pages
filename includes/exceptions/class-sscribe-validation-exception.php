<?php
/**
 * SScribe Validation Exception
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exceptions/class-sscribe-exception.php';

class SScribe_Validation_Exception extends SScribe_Exception {

	protected string $field;

	protected string $rule;

	public function __construct(
		?string $message = null,
		string $field = '',
		string $rule = '',
		array $context = array(),
		?Throwable $previous = null
	) {
		$this->field = $field;
		$this->rule  = $rule;

		$this->recoverable = true;

		$context = array_merge(
			$context,
			array(
				'field' => $field,
				'rule'  => $rule,
			)
		);

		$message = $message ?? sprintf(
			'Validation failed for field "%s": %s',
			'' !== $field ? $field : 'unknown',
			'' !== $rule ? $rule : 'unknown rule'
		);

		parent::__construct(
			self::CODE_VALIDATION_FAILED,
			$message,
			400,
			$context,
			$previous
		);
	}

	public function get_field(): string {
		return $this->field;
	}

	public function get_rule(): string {
		return $this->rule;
	}
}
