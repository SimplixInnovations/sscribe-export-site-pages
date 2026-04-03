<?php
/**
 * Security audit trail for SScribe.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Audit_Trail
 *
 * Provides database-backed audit logging for security-sensitive operations.
 */
class SScribe_Audit_Trail {

	/**
	 * Audit event types.
	 */
	public const EVENT_EXPORT_STARTED         = 'export_started';
	public const EVENT_EXPORT_COMPLETED       = 'export_completed';
	public const EVENT_EXPORT_FAILED          = 'export_failed';
	public const EVENT_EXPORT_CANCELLED       = 'export_cancelled';
	public const EVENT_DOWNLOAD               = 'download';
	public const EVENT_DOWNLOAD_DENIED        = 'download_denied';
	public const EVENT_DELETE                 = 'delete_export';
	public const EVENT_SESSION_CLEARED        = 'session_cleared';
	public const EVENT_PREFLIGHT_CHECK        = 'preflight_check';
	public const EVENT_RATE_LIMITED           = 'rate_limited';
	public const EVENT_PERMISSION_DENIED      = 'permission_denied';
	public const EVENT_INVALID_NONCE          = 'invalid_nonce';
	public const EVENT_SESSION_HIJACK_ATTEMPT = 'session_hijack_attempt';

	/**
	 * Table name for audit logs.
	 *
	 * @var string
	 */
	private readonly string $table_name;

	/**
	 * Whether database logging is enabled.
	 *
	 * @var bool
	 */
	private readonly bool $enabled;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'sscribe_audit_log';
		$this->enabled    = $this->table_exists();
	}

	/**
	 * Check if the audit table exists.
	 *
	 * @return bool True if table exists.
	 */
	private function table_exists(): bool {
		global $wpdb;
		static $exists = null;

		if ( null === $exists ) {
			$table  = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table_name )
			);
			$exists = ( $table === $this->table_name );
		}

		return $exists;
	}

	/**
	 * Log an audit event.
	 *
	 * @param string $event   Event type (use constants).
	 * @param array  $context Event context data.
	 * @return bool True if logged successfully.
	 */
	public function log( string $event, array $context = array() ): bool {
		if ( ! $this->enabled ) {
			return false;
		}

		global $wpdb;

		$user_id     = get_current_user_id();
		$ip          = $this->get_client_ip();
		$user_agent  = $this->get_user_agent();
		$request_uri = $this->get_request_uri();

		$sanitized_context = $this->sanitize_context( $context );

		$result = $wpdb->insert(
			$this->table_name,
			array(
				'timestamp'   => current_time( 'mysql', true ),
				'event'       => $event,
				'user_id'     => $user_id,
				'ip_address'  => $ip,
				'user_agent'  => $user_agent,
				'request_uri' => $request_uri,
				'context'     => wp_json_encode( $sanitized_context ),
				'session_id'  => $context['session_id'] ?? '',
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Sanitize context by removing sensitive data.
	 *
	 * @param array $context Raw context data.
	 * @return array Sanitized context.
	 */
	private function sanitize_context( array $context ): array {
		$forbidden_keys = array(
			'password',
			'token',
			'secret',
			'api_key',
			'auth',
			'credential',
			'private_key',
			'nonce',
		);

		foreach ( $forbidden_keys as $key ) {
			if ( isset( $context[ $key ] ) ) {
				$context[ $key ] = '[REDACTED]';
			}
		}

		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$context[ $key ] = wp_json_encode( $value );
			}
		}

		return $context;
	}

	/**
	 * Get client IP address.
	 *
	 * Prioritizes REMOTE_ADDR to prevent IP spoofing via HTTP headers.
	 *
	 * @return string Client IP address.
	 */
	private function get_client_ip(): string {
		$ip = '';

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		if ( empty( $ip ) ) {
			$ip = '0.0.0.0';
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Get user agent string.
	 *
	 * @return string User agent or 'Unknown'.
	 */
	private function get_user_agent(): string {
		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}
		return 'Unknown';
	}

	/**
	 * Get request URI.
	 *
	 * @return string Request URI or empty string.
	 */
	private function get_request_uri(): string {
		if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		}
		return '';
	}

	/**
	 * Get audit logs with filters.
	 *
	 * @param array $filters Filters (event, user_id, date_from, date_to).
	 * @param int   $limit   Maximum results.
	 * @param int   $offset  Offset for pagination.
	 * @return array Audit log entries.
	 */
	public function get_logs( array $filters = array(), int $limit = 100, int $offset = 0 ): array {
		if ( ! $this->enabled ) {
			return array();
		}

		global $wpdb;

		$where = array( '1=1' );
		$args  = array();

		if ( ! empty( $filters['event'] ) ) {
			$where[] = 'event = %s';
			$args[]  = $filters['event'];
		}

		if ( ! empty( $filters['user_id'] ) ) {
			$where[] = 'user_id = %d';
			$args[]  = $filters['user_id'];
		}

		if ( ! empty( $filters['ip_address'] ) ) {
			$where[] = 'ip_address = %s';
			$args[]  = $filters['ip_address'];
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$where[] = 'timestamp >= %s';
			$args[]  = $filters['date_from'];
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where[] = 'timestamp <= %s';
			$args[]  = $filters['date_to'];
		}

		if ( ! empty( $filters['session_id'] ) ) {
			$where[] = 'session_id = %s';
			$args[]  = $filters['session_id'];
		}

		$where_clause = implode( ' AND ', $where );
		$args[]       = $limit;
		$args[]       = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE {$where_clause} ORDER BY timestamp DESC LIMIT %d OFFSET %d",
				...$args
			)
		);
	}

	/**
	 * Get audit log counts by event type.
	 *
	 * @param array $filters Filters.
	 * @return array Event counts.
	 */
	public function get_event_counts( array $filters = array() ): array {
		if ( ! $this->enabled ) {
			return array();
		}

		global $wpdb;

		$where = array( '1=1' );
		$args  = array();

		if ( ! empty( $filters['date_from'] ) ) {
			$where[] = 'timestamp >= %s';
			$args[]  = $filters['date_from'];
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where[] = 'timestamp <= %s';
			$args[]  = $filters['date_to'];
		}

		$where_clause = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event, COUNT(*) as count FROM {$this->table_name} WHERE {$where_clause} GROUP BY event ORDER BY count DESC",
				...$args
			)
		);
	}

	/**
	 * Clean up old audit logs.
	 *
	 * @param int $days Maximum age in days.
	 * @return int Number of deleted rows.
	 */
	public function cleanup( int $days = 90 ): int {
		if ( ! $this->enabled ) {
			return 0;
		}

		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table_name} WHERE timestamp < %s",
				$cutoff
			)
		);
	}

	/**
	 * Create the audit log table.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'sscribe_audit_log';

		$sql = "CREATE TABLE $table_name (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			event VARCHAR(50) NOT NULL,
			user_id BIGINT UNSIGNED,
			ip_address VARCHAR(45),
			user_agent VARCHAR(255),
			request_uri VARCHAR(2083),
			context LONGTEXT,
			session_id VARCHAR(50),
			INDEX idx_timestamp (timestamp),
			INDEX idx_event (event),
			INDEX idx_user_id (user_id),
			INDEX idx_ip_address (ip_address),
			INDEX idx_session_id (session_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
