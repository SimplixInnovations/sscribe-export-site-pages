<?php
/**
 * Permission-related exception for SScribe.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exceptions/class-sscribe-exception.php';

/**
 * Class SScribe_Permission_Exception
 *
 * Thrown when file system or capability permissions prevent an operation.
 */
class SScribe_Permission_Exception extends SScribe_Exception {

	/**
	 * Path that caused the permission error.
	 */
	protected string $path;

	/**
	 * Type of permission required.
	 */
	protected string $permission_type;

	/**
	 * Constructor.
	 *
	 * @param string|null    $path            Path that caused the error.
	 * @param string|null    $message         Custom message.
	 * @param string         $permission_type Type: 'read', 'write', 'execute'.
	 * @param array          $context         Additional context.
	 * @param Throwable|null $previous        Previous exception.
	 */
	public function __construct(
		?string $path = null,
		?string $message = null,
		string $permission_type = 'write',
		array $context = array(),
		?Throwable $previous = null
	) {
		$this->path            = $path ?? '';
		$this->permission_type = $permission_type;

		$context = array_merge(
			$context,
			array(
				'path'            => $this->path,
				'permission_type' => $permission_type,
			)
		);

		$message = $message ?? sprintf(
			'Permission denied: Cannot %s to %s',
			$permission_type,
			$this->path ?: 'unknown location'
		);

		parent::__construct(
			self::CODE_PERMISSION_DENIED,
			$message,
			403,
			$context,
			$previous
		);
	}

	/**
	 * Get the path that caused the error.
	 */
	public function get_path(): string {
		return $this->path;
	}

	/**
	 * Get the permission type required.
	 */
	public function get_permission_type(): string {
		return $this->permission_type;
	}
}
