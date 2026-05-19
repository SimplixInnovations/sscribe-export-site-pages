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
	 * Get recent log entries from database.
	 *
	 * @param int $limit Maximum number of entries to return.
	 * @return array Log entries.
	 */
	public function get_logs( int $limit = 100 ): array {
		return $this->get_db_logs( array(), $limit );
	}

	/**
	 * Get log entries from database with optional filters.
	 *
	 * @param array $filters Filter criteria (level, user_id, date_from, date_to).
	 * @param int   $limit   Maximum number of entries.
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
