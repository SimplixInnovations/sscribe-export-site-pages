<?php
/**
 * SScribe Logger Enhanced
 *
 * Multi-destination logger that extends the base SScribe_Logger to
 * add optional database and Query Monitor output alongside the
 * inherited file output. Subclasses add destinations on top of the
 * file pipeline rather than duplicating it.
 *
 * Inherits from SScribe_Logger:
 *   - the buffered write pipeline ($buffer, $log_dir, $prefix)
 *   - the level dispatch (debug/info/...) via SScribe_Logger_Common trait
 *   - the singleton factory (instance())
 *
 * Enhanced adds:
 *   - level threshold filtering (min_level from constructor options)
 *   - optional database writes (enable_db, table_name, table_exists)
 *   - optional Query Monitor output (enable_qm)
 *   - a different file-rotation strategy (rotate when existing file
 *     already exceeds the limit, not when the incoming content would)
 *   - DB-side helpers (get_db_logs, cleanup_db_logs)
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SSCRIBE_PLUGIN_DIR . 'includes/interfaces/interface-sscribe-logger.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/traits/trait-sscribe-logger-common.php';
require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-logger.php';

/**
 * Enhanced logger with multiple output destinations.
 */
class SScribe_Logger_Enhanced extends SScribe_Logger {

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
	 * Database table name for logs.
	 *
	 * @var string
	 */
	private readonly string $table_name;

	/**
	 * Cached table existence result.
	 *
	 * @var bool|null
	 */
	private ?bool $table_exists_cache = null;

	/**
	 * Constructor.
	 *
	 * @param array $options Logger configuration options.
	 */
	public function __construct( array $options = array() ) {
		$this->min_level = $options['min_level'] ?? self::LEVEL_INFO;
		$this->enable_qm = $options['enable_qm'] ?? true;

		if ( isset( $options['enabled'] ) && false === $options['enabled'] ) {
			$this->enable_file = false;
			$this->enable_db   = false;
			$parent_enabled    = false;
		} else {
			$this->enable_db   = $options['enable_db'] ?? false;
			$this->enable_file = $options['enable_file'] ?? true;
			$parent_enabled    = $this->enable_file;
		}

		$this->table_name = $GLOBALS['wpdb']->prefix . 'sscribe_export_logs';

		parent::__construct( $parent_enabled, $options['prefix'] ?? 'sscribe' );
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
	 * Write a log entry to the log destinations.
	 *
	 * The base class's log() is bypassed: Enhanced uses a different
	 * level filter (min_level from constructor, not the Settings-
	 * based threshold the base uses) and a different entry format
	 * (file + DB shapes returned by format_entry()).
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		if ( ! $this->should_log( $level ) ) {
			return;
		}
		$context = $this->sanitize_log_context( $context );

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
	 * Flush log buffer to file.
	 *
	 * Different rotation strategy from the base: rotate when the
	 * existing file already exceeds the limit, not when the
	 * incoming content would push it over. This preserves the
	 * pre-refactor behavior.
	 */
	/**
	 * Determine if a log level should be processed.
	 *
	 * Delegates to the shared `SScribe_Logger_Common::level_meets_threshold()`
	 * helper so the priority comparison lives in one place.
	 *
	 * @param string $level Log level to check.
	 * @return bool True if level meets threshold.
	 */
	private function should_log( string $level ): bool {
		return self::level_meets_threshold( $level, $this->min_level );
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
		$message   = $this->sanitize_log_message( $message );
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
				'request_id' => $this->get_request_id(),
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
		return $this->sanitize_log_context( $context );
	}

	/**
	 * Write log entry to database.
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
			array(
				'timestamp'  => $entry['timestamp'],
				'level'      => $entry['level'],
				'message'    => $entry['message'],
				'context'    => $entry['context'],
				'session_id' => $entry['session_id'],
				'request_id' => $entry['request_id'],
				'user_id'    => get_current_user_id(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
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

		if ( null === $this->table_exists_cache ) {
			if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $this->table_name ) ) {
				$this->table_exists_cache = false;
				return false;
			}
			$wpdb->last_error = '';
			$sql = 'SELECT 1 FROM `' . $this->table_name . '` WHERE 1 = 0';
			$previous_suppression = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Internal table identifier is regex-validated; zero-row structural probe only.
			$result = $wpdb->query( $sql );
			$last_error = trim( (string) $wpdb->last_error );
			$wpdb->suppress_errors( (bool) $previous_suppression );
			$this->table_exists_cache = false !== $result && '' === $last_error;
		}

		return $this->table_exists_cache;
	}

	/**
	 * Get log file path.
	 *
	 * Returns the same path the base class computes; declared here
	 * only to preserve the historical public API on Enhanced.
	 *
	 * @return string Log file path.
	 */
	public function get_log_file(): string {
		return parent::get_log_file();
	}

	/**
	 * Get recent log entries.
	 *
	 * When DB logging is disabled, falls back to reading from the file log
	 * to ensure the debug console can display logs even when database
	 * logging is not active.
	 *
	 * @param int $limit Maximum number of entries to return.
	 * @return array Log entries.
	 */
	public function get_logs( int $limit = 100 ): array {

		if ( ! $this->enable_db ) {
			return $this->get_file_logs( $limit );
		}

		$entries = $this->get_db_logs( array(), $limit );

		return array_map(
			static function ( object $row ): string {
				$row_array        = (array) $row;
				$context_decoded  = json_decode( (string) ( $row_array['context'] ?? '' ), true );
				$context          = is_array( $context_decoded ) ? $context_decoded : array();
				$context_str      = $context ? ' | ' . wp_json_encode( $context ) : '';
				$timestamp_value  = isset( $row_array['timestamp'] ) ? (string) $row_array['timestamp'] : '';
				$level_value      = isset( $row_array['level'] ) ? (string) $row_array['level'] : '';
				$message_value    = isset( $row_array['message'] ) ? (string) $row_array['message'] : '';
				return sprintf(
					'[%s] [%s] %s%s',
					$timestamp_value,
					strtoupper( $level_value ),
					$message_value,
					$context_str
				);
			},
			$entries
		);
	}

	/**
	 * Get log entries from file (fallback when DB is disabled).
	 *
	 * @param int $limit Maximum number of entries to return.
	 * @return array Log entries.
	 */
	private function get_file_logs( int $limit = 100 ): array {
		return parent::get_logs( $limit );
	}

	/**
	 * Clear all log entries from database and file.
	 */
	public function clear_logs(): void {
		global $wpdb;

		if ( $this->table_exists() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is already escaped via esc_sql(); DELETE FROM does not support placeholders for table names.
			$wpdb->query( 'DELETE FROM ' . esc_sql( $this->table_name ) );
		}

		if ( $this->enable_file && $this->storage_available ) {
			parent::clear_logs();
		}
	}

	/**
	 * Get log entries from database with optional filters.
	 *
	 * Note: returns raw database row objects, not formatted strings.
	 * For formatted string output, use get_logs() which calls fetch_logs() internally.
	 *
	 * @param array $filters Filter criteria (level, user_id, date_from, date_to).
	 * @param int   $limit   Maximum number of entries.
	 * @return object[] Array of raw database row objects with timestamp, level, message, context, session_id, request_id properties.
	 */
	public function get_db_logs( array $filters = array(), int $limit = 100 ): array {
		global $wpdb;
		$limit = max( 1, min( 1000, $limit ) );

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
				"SELECT id, timestamp, level, message, context, session_id, request_id, user_id FROM {$this->table_name} WHERE {$where_clause} ORDER BY timestamp DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix (trusted), WHERE clause built from controlled filter keys with placeholders
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

		$cutoff = strtotime( '-' . max( 1, abs( (int) $days ) ) . ' days' );
		$cutoff = gmdate( 'Y-m-d H:i:s', false !== $cutoff ? $cutoff : time() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . esc_sql( $this->table_name ) . ' WHERE timestamp < %s',
				$cutoff
			)
		);
	}
}
