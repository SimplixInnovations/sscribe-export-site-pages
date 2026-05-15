<?php
/**
 * Enhanced logging service for SScribe with database and Query Monitor support.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/interfaces/interface-sscribe-logger.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-logger-common.php';

/**
 * Class SScribe_Logger_Enhanced
 *
 * Extended logger with database storage and Query Monitor integration.
 */
class SScribe_Logger_Enhanced implements SScribe_Logger_Interface {

	use SScribe_Logger_Common;

	/**
	 * Minimum log level to record.
	 *
	 * @var string
	 */
	private string $min_level;

	/**
	 * Enable database logging.
	 *
	 * @var bool
	 */
	private bool $enable_db;

	/**
	 * Enable Query Monitor integration.
	 *
	 * @var bool
	 */
	private bool $enable_qm;

	/**
	 * Enable file logging.
	 *
	 * @var bool
	 */
	private bool $enable_file;

	/**
	 * Log entries buffer for batch writes.
	 *
	 * @var array
	 */
	private array $buffer = array();

	/**
	 * Log directory path.
	 *
	 * @var string
	 */
	private readonly string $log_dir;

	/**
	 * Table name for database logs.
	 *
	 * @var string
	 */
	private readonly string $table_name;

	/**
	 * Request ID for correlation.
	 *
	 * @var string
	 */
	private readonly string $request_id;

	/**
	 * Export session ID for correlation in log entries.
	 *
	 * @var string|null
	 */
	private ?string $session_id = null;

	/**
	 * Log level priority mapping.
	 */
	private const LEVEL_PRIORITY = array(
		self::LEVEL_DEBUG     => 0,
		self::LEVEL_INFO      => 1,
		self::LEVEL_NOTICE    => 2,
		self::LEVEL_WARNING   => 3,
		self::LEVEL_ERROR     => 4,
		self::LEVEL_CRITICAL  => 5,
		self::LEVEL_ALERT     => 6,
		self::LEVEL_EMERGENCY => 7,
	);

	/**
	 * Constructor.
	 *
	 * @param array $options Configuration options.
	 */
	public function __construct( array $options = array() ) {
		$this->min_level   = $options['min_level'] ?? self::LEVEL_INFO;
		$this->enable_db   = $options['enable_db'] ?? false;
		$this->enable_qm   = $options['enable_qm'] ?? true;
		$this->enable_file = $options['enable_file'] ?? true;

		$upload_dir       = wp_upload_dir();
		$this->log_dir    = $upload_dir['basedir'] . '/sscribe-logs';
		$this->table_name = $GLOBALS['wpdb']->prefix . 'sscribe_export_logs';
		$this->request_id = substr( md5( microtime( true ) . (string) random_int( 0, PHP_INT_MAX ) ), 0, 12 );

		if ( $this->enable_file || $this->enable_db ) {
			add_action( 'shutdown', array( $this, 'flush' ) );
		}
	}

	/**
	 * Override trait method to return the cached instance request ID.
	 *
	 * @return string
	 */
	protected function get_request_id(): string {
		return $this->request_id;
	}

	/**
	 * Set the session ID for correlation in log entries.
	 *
	 * When set, all subsequent log entries will include this session_id
	 * in their context data, enabling correlation across export operations.
	 *
	 * @param string $session_id The export session identifier.
	 */
	public function set_session_id( string $session_id ): void {
		$this->session_id = $session_id;
	}

	/**
	 * Get standard context enrichment for log entries.
	 *
	 * Extends trait method to include session_id for export correlation.
	 *
	 * @return array<string, string>
	 */
	protected function get_context_enrichment(): array {
		$context = array(
			'plugin_version' => defined( 'SSCRIBE_VERSION' ) ? (string) SSCRIBE_VERSION : 'unknown',
			'php_version'    => PHP_VERSION,
			'memory_usage'   => size_format( memory_get_usage( true ) ),
			'request_id'     => $this->get_request_id(),
		);

		// Include session_id for export operation correlation.
		if ( null !== $this->session_id ) {
			$context['session_id'] = $this->session_id;
		}

		return $context;
	}

	/**
	 * Check if a level should be logged.
	 *
	 * @param string $level Log level to check.
	 * @return bool True if level meets minimum threshold.
	 */
	private function should_log( string $level ): bool {
		$current = self::LEVEL_PRIORITY[ $this->min_level ] ?? 1;
		$check   = self::LEVEL_PRIORITY[ $level ] ?? 1;
		return $check >= $current;
	}

	/**
	 * Log a message.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		if ( ! $this->should_log( $level ) ) {
			return;
		}

		$entry = $this->format_entry( $level, $message, $context );

		if ( $this->enable_file ) {
			$this->buffer[] = $entry['file'];
		}

		if ( $this->enable_db ) {
			$this->write_to_database( $entry['db'] );
		}

		if ( $this->enable_qm ) {
			$this->write_to_query_monitor( $level, $message, $context );
		}
	}

	/**
	 * Format a log entry.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 * @return array Formatted file and db entries.
	 */
	private function format_entry( string $level, string $message, array $context ): array {
		$timestamp = current_time( 'mysql', true );
		$user_id   = get_current_user_id();

		$sanitized_context = $this->sanitize_context( array_merge( $this->get_context_enrichment(), $context ) );

		$file_entry = sprintf(
			'[%s] [%s] %s | %s',
			$timestamp,
			strtoupper( $level ),
			$message,
			wp_json_encode( $sanitized_context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);

		$db_entry = array(
			'timestamp'    => $timestamp,
			'level'        => $level,
			'message'      => $message,
			'context'      => wp_json_encode( $sanitized_context ),
			'user_id'      => $user_id,
			'request_id'   => $this->request_id,
			'memory_usage' => size_format( memory_get_usage( true ) ),
		);

		return array(
			'file' => $file_entry,
			'db'   => $db_entry,
		);
	}

	/**
	 * Sanitize context by removing sensitive data.
	 *
	 * @param array $context Raw context data.
	 * @return array Sanitized context with sensitive values redacted.
	 */
	private function sanitize_context( array $context ): array {
		$forbidden_keys = array( 'password', 'token', 'secret', 'api_key', 'auth', 'credential', 'private_key' );

		foreach ( $forbidden_keys as $key ) {
			if ( isset( $context[ $key ] ) ) {
				$context[ $key ] = '[REDACTED]';
			}
		}

		return $context;
	}

	/**
	 * Write to database.
	 *
	 * @param array $entry Log entry data.
	 */
	private function write_to_database( array $entry ): void {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$this->table_name,
			$entry,
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Check if the log table exists.
	 *
	 * @return bool True if table exists.
	 */
	private function table_exists(): bool {
		global $wpdb;
		static $exists = null;

		if ( null === $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table  = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table_name )
			);
			$exists = ( $table === $this->table_name );
		}

		return $exists;
	}

	/**
	 * Write to Query Monitor.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 */
	private function write_to_query_monitor( string $level, string $message, array $context ): void {
		// JUSTIFICATION: The hook name is dynamically constructed from 'sscribe_qm/' prefix concatenated with $level
		// (debug, info, warning, error). The 'sscribe_qm/' prefix satisfies the plugin prefix requirement. The dynamic
		// construction is necessary because the log level (debug/info/warning/error) determines which Query Monitor
		// hook to fire. This is a standard pattern for Query Monitor integration and the prefix ensures no conflicts.
		$action = 'sscribe_qm/' . $level; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name prefixed with sscribe_qm/

		if ( did_action( 'plugins_loaded' ) ) {
			do_action( $action, $message, $context ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name prefixed with sscribe_qm/
		}
	}

	/**
	 * Get log file path.
	 *
	 * @return string
	 */
	private function get_log_file(): string {
		if ( ! file_exists( $this->log_dir ) ) {
			SScribe_Security::protect_directory( $this->log_dir );
		}
		return $this->log_dir . '/sscribe_' . gmdate( 'Y-m-d' ) . '.log';
	}

	/**
	 * Flush buffered logs to file.
	 *
	 * @return void
	 */
	public function flush(): void {
		if ( empty( $this->buffer ) || ! $this->enable_file ) {
			return;
		}

		$log_file = $this->get_log_file();
		$content  = implode( PHP_EOL, $this->buffer ) . PHP_EOL;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$result = file_put_contents( $log_file, $content, FILE_APPEND | LOCK_EX );

		if ( false !== $result ) {
			$this->buffer = array();
		}
	}

	/**
	 * Log a debug message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_DEBUG, $message, $context );
	}

	/**
	 * Log an info message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_INFO, $message, $context );
	}

	/**
	 * Log a notice message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 */
	public function notice( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_NOTICE, $message, $context );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_WARNING, $message, $context );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_ERROR, $message, $context );
	}

	/**
	 * Log a critical message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 */
	public function critical( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_CRITICAL, 'CRITICAL: ' . $message, $context );
	}

	/**
	 * Log an alert message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 */
	public function alert( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_ALERT, $message, $context );
	}

	/**
	 * Log an emergency message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 */
	public function emergency( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_EMERGENCY, $message, $context );
	}

	/**
	 * Check if logging is enabled.
	 *
	 * @return bool True if any logging destination is active.
	 */
	public function is_enabled(): bool {
		return $this->enable_file || $this->enable_db;
	}

	/**
	 * Get recent log entries.
	 *
	 * @param int $limit Maximum entries to return.
	 * @return array Log entries.
	 */
	public function get_logs( int $limit = 100 ): array {
		return $this->get_db_logs( array(), $limit );
	}

	/**
	 * Get logs from database.
	 *
	 * @param array $filters Filters (level, user_id, date_from, date_to).
	 * @param int   $limit   Maximum results.
	 * @return array Log entries.
	 */
	public function get_db_logs( array $filters = array(), int $limit = 100 ): array {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return array();
		}

		$where = array( '1=1' );
		$args  = array();

		if ( ! empty( $filters['level'] ) ) {
			$where[] = 'level = %s';
			$args[]  = $filters['level'];
		}

		if ( ! empty( $filters['user_id'] ) ) {
			$where[] = 'user_id = %d';
			$args[]  = $filters['user_id'];
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$where[] = 'timestamp >= %s';
			$args[]  = $filters['date_from'];
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where[] = 'timestamp <= %s';
			$args[]  = $filters['date_to'];
		}

		$where_clause = implode( ' AND ', $where );
		$args[]       = $limit;

		// JUSTIFICATION: The table name {$this->table_name} is derived from $wpdb->prefix (a trusted WordPress core value)
		// and is NOT user-controlled input. The WHERE clause {$where_clause} is built from controlled filter keys
		// (level, user_id, date_from, date_to) using %s and %d placeholders in the $args array, which are properly
		// escaped by $wpdb->prepare(). The table name interpolation is necessary because $wpdb->prepare() does not
		// support table name placeholders — this is a documented WordPress limitation. All filter values pass through
		// the $args array with proper placeholder escaping.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE {$where_clause} ORDER BY timestamp DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix (trusted), WHERE clause built from controlled filter keys with placeholders
				...$args
			)
		);
	}

	/**
	 * Clean up old logs from database.
	 *
	 * @param int $days Maximum age in days.
	 * @return int Number of deleted rows.
	 */
	public function cleanup_db_logs( int $days = 30 ): int {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// Table name from $wpdb->prefix is trusted (not user-controlled) — safe to interpolate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->query(
			$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'DELETE FROM ' . $this->table_name . ' WHERE timestamp < %s',
				$cutoff
			)
		);
	}
}
