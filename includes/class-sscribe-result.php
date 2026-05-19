<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SScribe_Result {

	private readonly bool $success;

	private readonly mixed $data;

	private readonly ?string $error;

	private readonly array $context;

	private function __construct( bool $success, $data = null, ?string $error = null, array $context = array() ) {
		$this->success = $success;
		$this->data    = $data;
		$this->error   = $error;
		$this->context = $context;
	}

	public static function success( $data = null ): self {
		return new self( true, $data );
	}

	public static function failure( string $error, array $context = array() ): self {
		return new self( false, null, $error, $context );
	}

	public function is_success(): bool {
		return $this->success;
	}

	public function is_failure(): bool {
		return ! $this->success;
	}

	public function get_data(): mixed {
		return $this->data;
	}

	public function get_error(): ?string {
		return $this->error;
	}

	public function get_context(): array {
		return $this->context;
	}
}
