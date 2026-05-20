<?php
/**
 * SScribe Permission Exception
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exceptions/class-sscribe-exception.php';

/**
 * Exception for file permission errors.
 */
class SScribe_Permission_Exception extends SScribe_Exception {

	/**
	 * File or directory path.
	 *
	 * @var string
	 */
	protected string $path;

	/**
	 * Permission type (read, write, execute).
	 *
	 * @var string
	 */
	protected string $permission_type;

	/**
	 * Create a new permission exception.
	 *
	 * @param string|null    $path            File path.
	 * @param string|null    $message         Error message.
	 * @param string         $permission_type Permission type.
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
			'' !== $this->path ? $this->path : 'unknown location'
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
	 * Get the file path.
	 *
	 * @return string
	 */
	public function get_path(): string {
		return $this->path;
	}

	/**
	 * Get the permission type.
	 *
	 * @return string
	 */
	public function get_permission_type(): string {
		return $this->permission_type;
	}
}
