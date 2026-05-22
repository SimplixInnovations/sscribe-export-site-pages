<?php
/**
 * SScribe Enhanced Logger
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/interfaces/interface-sscribe-logger.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-logger-common.php';

/**
 * Enhanced logger with multiple output destinations.
 */
class SScribe_Logger_Enhanced implements SScribe_Logger_Interface {

	use SScribe_Logger_Common;

	/**
	 * Minimum log level threshold.
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
	 * Enable Query Monitor logging.
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
	 * Log message buffer.
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
	 * Database table name for logs.
	 *
	 * @var string
	 */
	private readonly string $table_name;

	/**
	 * Current request identifier.
	 *
	 * @var string
	 */
	private readonly string $request_id;

	/**
	 * Current session identifier.
	 *
	 * @var string|null
	 */
	private ?string $session_id = null;

	/**
	 * Log level priority mapping.
	 *
	 * @var array<string, int>
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
	 * @param array $options Logger configuration options.
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
	 * Get request identifier.
	 *
	 * @return string Request ID.
	 */
	protected function get_request_id(): string {
		return $this->request_id;
	}

	/**
	 * Set session identifier for log context.
	 *
	 * @param string $session_id Session identifier.
	 */
	/**
	 * Set session identifier for log context.
	 *
	 * @param string $session_id Session identifier.
	 */
	public function set_session_id( string $session_id ): void {
		$this->session_id = $session_id;
	}

	/**
	 * Get context enrichment data for log entries.
	 *
	 * @return array Context data.
	 */
	protected function get_context_enrichment(): array {
		$context = array(
			'plugin_version' => defined( 'SSCRIBE_VERSION' ) ? (string) SSCRIBE_VERSION : 'unknown',
			'php_version'    => PHP_VERSION,
			'memory_usage'   => size_format( memory_get_usage( true ) ),
			'request_id'     => $this->get_request_id(),
		);

		if ( null !== $this->session_id ) {
			$context['session_id'] = $this->session_id;
		}

		return $context;
	}

	/**
	 * Determine if a log level should be processed.
	 *
	 * @param string $level Log level to check.
	 * @return bool True if level meets threshold.
	 */
	private function should_log( string $level ): bool {
		$current = self::LEVEL_PRIORITY[ $this->min_level ] ?? 1;
		$check   = self::LEVEL_PRIORITY[ $level ] ?? 1;
		return $check >= $current;
	}

	/**
	 * Log a message at specified level.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	/**
	 * Write a log entry to the log destinations.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
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
	 * Check if logger has any active output.
	 *
	 * @return bool True if file or database logging is enabled.
	 */
	public function is_enabled(): bool {
		return $this->enable_file || $this->enable_db;
	}

	/**
	 * Log debug message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_DEBUG, $message, $context );
	}

	/**
	 * Log info message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_INFO, $message, $context );
	}

	/**
	 * Log notice message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 */
	public function notice( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_NOTICE, $message, $context );
	}

	/**
	 * Log warning message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_WARNING, $message, $context );
	}

	/**
	 * Log error message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_ERROR, $message, $context );
	}

	/**
	 * Log critical message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 */
	public function critical( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_CRITICAL, $message, $context );
	}

	/**
	 * Log alert message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 */
	public function alert( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_ALERT, $message, $context );
	}

	/**
	 * Log emergency message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 */
	public function emergency( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_EMERGENCY, $message, $context );
	}

	/**
	 * Flush log buffer to file.
	 */
	public function flush(): void {
		if ( empty( $this->buffer ) ) {
			return;
		}

		if ( ! is_dir( $this->log_dir ) ) {
			wp_mkdir_p( $this->log_dir );
		}

		$log_file = $this->get_log_file();
		file_put_contents( $log_file, implode( PHP_EOL, $this->buffer ) . PHP_EOL, FILE_APPEND | LOCK_EX );
		$this->buffer = array();
	}

	/**
	 * Format log entry for different destinations.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @return array Formatted entries.
	 */
	private function format_entry( string $level, string $message, array $context ): array {
		$timestamp = gmdate( 'Y-m-d H:i:s' );
		$context   = $this->sanitize_context(
			array_merge( $this->get_context_enrichment(), $context )
		);

		return array(
			'file' => sprintf(
				'[%s] %s: %s %s',
				$timestamp,
				strtoupper( $level ),
				$message,
				$context ? wp_json_encode( $context ) : ''
			),
			'db'   => array(
				'timestamp'  => $timestamp,
				'level'      => $level,
				'message'    => $message,
				'context'    => wp_json_encode( $context ),
				'session_id' => $this->session_id,
				'request_id' => $this->request_id,
			),
		);
	}

	/**
	 * Sanitize context array by removing forbidden keys.
	 *
	 * @param array $context Context array to sanitize.
	 * @return array Sanitized context.
	 */
	private function sanitize_context( array $context ): array {
		$forbidden = array( 'password', 'token', 'secret', 'auth', 'credential', 'private_key' );

		foreach ( $forbidden as $key ) {
			if ( isset( $context[ $key ] ) ) {
				$context[ $key ] = '[REDACTED]';
			}
		}

		return $context;
	}

	/**
	 * Write log entry to database.
	 *
	 * @param array $entry Log entry data.
	 */
	private function write_to_database( array $entry ): void {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			$this->create_log_table();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$this->table_name,
			array(
				'timestamp'  => $entry['timestamp'],
				'level'      => $entry['level'],
				'message'    => $entry['message'],
				'context'    => $entry['context'],
				'session_id' => $entry['session_id'],
				'request_id' => $entry['request_id'],
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Write log entry to Query Monitor.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 */
	private function write_to_query_monitor( string $level, string $message, array $context ): void {
		if ( ! class_exists( 'QM_Collector' ) || ! class_exists( 'QM_Collectors' ) ) {
			return;
		}

		$collector = QM_Collectors::get( 'sscribe' );
		if ( null === $collector ) {
			return;
		}

		$collector->log( $level, $message, $context );
	}

	/**
	 * Check if log table exists.
	 *
	 * @return bool True if table exists.
	 */
	private function table_exists(): bool {
		global $wpdb;
		static $exists = null;

		if ( null === $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection, cached via static variable
			$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table_name ) );
			$exists = ( $result === $this->table_name );
		}

		return $exists;
	}

	/**
	 * Create log table if not exists.
	 */
	private function create_log_table(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			timestamp datetime NOT NULL,
			level varchar(20) NOT NULL,
			message text NOT NULL,
			context longtext,
			session_id varchar(60) DEFAULT NULL,
			request_id varchar(12) DEFAULT NULL,
			user_id bigint(20) DEFAULT NULL,
			PRIMARY KEY id (id),
			KEY timestamp (timestamp),
			KEY level (level)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Get log file path.
	 *
	 * @return string Log file path.
	 */
	private function get_log_file(): string {
		$date = gmdate( 'Y-m-d' );
		return trailingslashit( $this->log_dir ) . "sscribe_debug_{$date}.log";
	}

	/**
	 * Get recent log entries from database.
	 *
	 * @param int $limit Maximum number of entries to return.
	 * @return array Log entries.
	 */
	public function get_logs( int $limit = 100 ): array {
		$entries = $this->get_db_logs( array(), $limit );

		return array_map(
			function ( object $row ): string {
				$context = json_decode( $row->context, true ) ?: array();
				$context_str = $context ? ' | ' . wp_json_encode( $context ) : '';
				return sprintf(
					'[%s] [%s] %s%s',
					$row->timestamp,
					strtoupper( $row->level ),
					$row->message,
					$context_str
				);
			},
			$entries
		);
	}

	/**
	 * Clear all log entries from database.
	 */
	public function clear_logs(): void {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is already escaped via esc_sql(); DELETE FROM does not support placeholders for table names.
		$wpdb->query( 'DELETE FROM ' . esc_sql( $this->table_name ) );
	}

	/**
	 * Get log entries from database with optional filters.
	 *
	 * Note: This method returns raw database row objects, not formatted strings.
	 * For formatted string output, use get_logs() which calls this method internally.
	 *
	 * @param array $filters Filter criteria (level, user_id, date_from, date_to).
	 * @param int   $limit   Maximum number of entries.
	 * @return object[] Array of raw database row objects with timestamp, level, message, context, session_id, request_id properties.
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE {$where_clause} ORDER BY timestamp DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix (trusted), WHERE clause built from controlled filter keys with placeholders
				...$args
			)
		);
	}

		/**
		 * Delete old log entries from the database.
		 *
		 * @param int $days Number of days to retain.
		 * @return int Number of rows deleted.
		 */
	public function cleanup_db_logs( int $days = 30 ): int {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

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
