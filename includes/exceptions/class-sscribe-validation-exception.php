<?php
/**
 * Validation-related exception for SScribe.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exceptions/class-sscribe-exception.php';

/**
 * Class SScribe_Validation_Exception
 *
 * Thrown when input validation fails.
 */
class SScribe_Validation_Exception extends SScribe_Exception {

	/**
	 * Field that failed validation.
	 *
	 * @var string
	 */
	protected string $field;

	/**
	 * Validation rule that failed.
	 *
	 * @var string
	 */
	protected string $rule;

	/**
	 * Constructor.
	 *
	 * @param string|null    $message    Custom message.
	 * @param string         $field      Field that failed validation.
	 * @param string         $rule       Validation rule that failed.
	 * @param array          $context    Additional context.
	 * @param Throwable|null $previous   Previous exception.
	 */
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

	/**
	 * Get the field that failed validation.
	 *
	 * @return string
	 */
	public function get_field(): string {
		return $this->field;
	}

	/**
	 * Get the validation rule that failed.
	 *
	 * @return string
	 */
	public function get_rule(): string {
		return $this->rule;
	}
}
