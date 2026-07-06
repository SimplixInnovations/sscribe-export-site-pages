<?php
/**
 * SScribe Settings
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings management for SScribe plugin.
 */
class SScribe_Settings {

	/**
	 * Option keys.
	 */
	public const OPT_DEBUG_ENABLED      = 'sscribe_debug_enabled';
	public const OPT_DEBUG_LOG_LEVEL    = 'sscribe_debug_log_level';
	public const OPT_DEBUG_AUTO_REFRESH = 'sscribe_debug_auto_refresh';

	/**
	 * Log levels.
	 */
	public const LEVEL_DEBUG    = 'DEBUG';
	public const LEVEL_INFO     = 'INFO';
	public const LEVEL_NOTICE   = 'NOTICE';
	public const LEVEL_WARNING  = 'WARNING';
	public const LEVEL_ERROR    = 'ERROR';
	public const LEVEL_CRITICAL = 'CRITICAL';
	public const LEVEL_ALL      = 'ALL';

	/**
	 * Get debug enabled setting.
	 *
	 * @return bool
	 */
	public static function is_debug_enabled(): bool {
		return (bool) get_option( self::OPT_DEBUG_ENABLED, false );
	}

	/**
	 * Set debug enabled setting.
	 *
	 * @param bool $enabled Whether debug is enabled.
	 * @return bool
	 */
	public static function set_debug_enabled( bool $enabled ): bool {
		$current = get_option( self::OPT_DEBUG_ENABLED );
		if ( false !== $current && (bool) $current === $enabled ) {
			return true;
		}
		$result = update_option( self::OPT_DEBUG_ENABLED, $enabled, 'no' );
		if ( ! $result ) {
			
			
			return (bool) get_option( self::OPT_DEBUG_ENABLED ) === $enabled;
		}
		return true;
	}

	/**
	 * Get debug log level.
	 *
	 * @return string
	 */
	public static function get_debug_log_level(): string {
		return (string) get_option( self::OPT_DEBUG_LOG_LEVEL, self::LEVEL_DEBUG );
	}

	/**
	 * Set debug log level.
	 *
	 * @param string $level Log level.
	 * @return bool
	 */
	public static function set_debug_log_level( string $level ): bool {
		$allowed = array(
			self::LEVEL_DEBUG,
			self::LEVEL_INFO,
			self::LEVEL_NOTICE,
			self::LEVEL_WARNING,
			self::LEVEL_ERROR,
			self::LEVEL_CRITICAL,
			self::LEVEL_ALL,
		);

		if ( ! in_array( $level, $allowed, true ) ) {
			$level = self::LEVEL_DEBUG;
		}

		$current = get_option( self::OPT_DEBUG_LOG_LEVEL );
		if ( false !== $current && $current === $level ) {
			return true;
		}
		$result = update_option( self::OPT_DEBUG_LOG_LEVEL, $level, 'no' );
		if ( ! $result ) {
			
			
			return get_option( self::OPT_DEBUG_LOG_LEVEL ) === $level;
		}
		return true;
	}

	/**
	 * Get auto refresh setting.
	 *
	 * @return bool
	 */
	public static function is_auto_refresh(): bool {
		return (bool) get_option( self::OPT_DEBUG_AUTO_REFRESH, true );
	}

	/**
	 * Set auto refresh setting.
	 *
	 * @param bool $enabled Whether auto refresh is enabled.
	 * @return bool
	 */
	public static function set_auto_refresh( bool $enabled ): bool {
		$current = get_option( self::OPT_DEBUG_AUTO_REFRESH );
		if ( false !== $current && (bool) $current === $enabled ) {
			return true;
		}
		$result = update_option( self::OPT_DEBUG_AUTO_REFRESH, $enabled, 'no' );
		if ( ! $result ) {
			
			
			return (bool) get_option( self::OPT_DEBUG_AUTO_REFRESH ) === $enabled;
		}
		return true;
	}

	/**
	 * Get all debug settings.
	 *
	 * @return array{
	 *     debug_enabled: bool,
	 *     log_level: string,
	 *     auto_refresh: bool
	 * }
	 */
	public static function get_debug_settings(): array {
		return array(
			'debug_enabled' => self::is_debug_enabled(),
			'log_level'     => self::get_debug_log_level(),
			'auto_refresh'  => self::is_auto_refresh(),
		);
	}

	/**
	 * Save all debug settings at once.
	 *
	 * @param array $settings Settings array.
	 * @return bool
	 */
	public static function save_debug_settings( array $settings ): bool {
		$enabled = (bool) ( $settings['debug_enabled'] ?? false );
		$level   = (string) ( $settings['log_level'] ?? self::LEVEL_DEBUG );
		$refresh = (bool) ( $settings['auto_refresh'] ?? true );

		$level_saved   = self::set_debug_log_level( $level );
		$enabled_saved = self::set_debug_enabled( $enabled );
		$refresh_saved = self::set_auto_refresh( $refresh );

		
		if ( ! $level_saved || ! $enabled_saved || ! $refresh_saved ) {
			if ( class_exists( 'SScribe_Logger' ) ) {
				$logger = SScribe_Logger::instance( true );
				$logger->warning(
					'Debug settings save: partial failure',
					array(
						'level_saved'   => $level_saved,
						'enabled_saved' => $enabled_saved,
						'refresh_saved' => $refresh_saved,
					)
				);
			}
			return false;
		}

		
		if ( class_exists( 'SScribe_Logger' ) ) {
			SScribe_Logger::reset_instance();
		}

		return true;
	}
}
