<?php
/**
 * Session-related exception for SScribe.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exceptions/class-sscribe-exception.php';

/**
 * Class SScribe_Session_Exception
 *
 * Thrown when a session operation fails.
 */
class SScribe_Session_Exception extends SScribe_Exception {

	/**
	 * Session ID that caused the error.
	 */
	protected string $session_id;

	/**
	 * Constructor.
	 *
	 * @param string|null    $message     Custom message.
	 * @param string         $session_id  Session ID.
	 * @param bool           $expired     Whether session is expired.
	 * @param array          $context     Additional context.
	 * @param Throwable|null $previous    Previous exception.
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
			$session_id ?: 'unknown'
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
	 */
	public function get_session_id(): string {
		return $this->session_id;
	}
}
