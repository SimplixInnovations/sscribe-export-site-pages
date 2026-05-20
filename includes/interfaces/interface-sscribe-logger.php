<?php
/**
 * SScribe Logger Interface
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface SScribe_Logger_Interface {

	public const LEVEL_DEBUG     = 'debug';
	public const LEVEL_INFO      = 'info';
	public const LEVEL_NOTICE    = 'notice';
	public const LEVEL_WARNING   = 'warning';
	public const LEVEL_ERROR     = 'error';
	public const LEVEL_CRITICAL  = 'critical';
	public const LEVEL_ALERT     = 'alert';
	public const LEVEL_EMERGENCY = 'emergency';

	public const MAX_LOG_FILE_SIZE = 10485760;

	public function set_session_id( string $session_id ): void;

	public function debug( string $message, array $context = array() ): void;

	public function info( string $message, array $context = array() ): void;

	public function notice( string $message, array $context = array() ): void;

	public function warning( string $message, array $context = array() ): void;

	public function error( string $message, array $context = array() ): void;

	public function critical( string $message, array $context = array() ): void;

	public function alert( string $message, array $context = array() ): void;

	public function emergency( string $message, array $context = array() ): void;

	public function log( string $level, string $message, array $context = array() ): void;

	public function is_enabled(): bool;
}
