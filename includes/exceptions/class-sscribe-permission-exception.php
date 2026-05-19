<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/exceptions/class-sscribe-exception.php';

class SScribe_Permission_Exception extends SScribe_Exception {

	protected string $path;

	protected string $permission_type;

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

	public function get_path(): string {
		return $this->path;
	}

	public function get_permission_type(): string {
		return $this->permission_type;
	}
}
