<?php
/**
 * SScribe Session Exception
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exceptions/class-sscribe-exception.php';

/**
 * Exception for session-related errors.
 */
class SScribe_Session_Exception extends SScribe_Exception {

	/**
	 * Affected session identifier.
	 *
	 * @var string
	 */
	protected string $session_id;

	/**
	 * Create a new session exception.
	 *
	 * @param string|null    $message    Error message.
	 * @param string         $session_id Session ID.
	 * @param bool           $expired    Whether session expired.
	 * @param array          $context    Additional context.
	 * @param Throwable|null $previous   Previous exception.
	 */
	public function __construct(
		?string $message = null,
		string $session_id = '',
		bool $expired = false,
		array $context = array(),
		?Throwable $previous = null
	) {
		$this->session_id = $session_id;

		$context = array_merge(
			$context,
			array(
				'session_id' => $session_id,
				'expired'    => $expired,
			)
		);

		$error_code = $expired ? self::CODE_SESSION_EXPIRED : self::CODE_SESSION_CORRUPTED;

		$message = $message ?? sprintf(
			'Session %s: %s',
			$expired ? 'expired' : 'corrupted',
			'' !== $session_id ? $session_id : 'unknown'
		);

		parent::__construct(
			$error_code,
			$message,
			410,
			$context,
			$previous
		);
	}

	/**
	 * Get the session ID.
	 *
	 * @return string
	 */
	public function get_session_id(): string {
		return $this->session_id;
	}
}
