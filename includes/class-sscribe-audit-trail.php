<?php
/**
 * SScribe Audit Trail.
 *
 * @package SScribe_Export_Site_Pages
 * @license GPL v2 or later
 * @link    https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audit trail event logger.
 *
 * @package SScribe_Export_Site_Pages
 * @subpackage Audit
 */
class SScribe_Audit_Trail {

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
	 * Audit log table name.
	 *
	 * @var string
	 */
	private readonly string $table_name;

	/**
	 * Whether audit logging is enabled.
	 *
	 * @var bool
	 */
	private readonly bool $enabled;

	/**
	 * Initialize the audit trail.
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'sscribe_audit_log';
		$this->enabled    = $this->table_exists();
	}

	/**
	 * Cached table existence result.
	 *
	 * @var bool|null
	 */
	private ?bool $table_exists_cache = null;

	/**
	 * Check if the audit table exists.
	 *
	 * @return bool
	 */
	private function table_exists(): bool {
		global $wpdb;

		if ( null === $this->table_exists_cache ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection, cached via instance property
			$table                    = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $this->table_name ) )
			);
			$this->table_exists_cache = ( $table === $this->table_name );
		}

		return $this->table_exists_cache;
	}

	/**
	 * Log an audit event.
	 *
	 * @param string $event   Event identifier.
	 * @param array  $context Event context data.
	 * @return bool
	 */
	public function log( string $event, array $context = array() ): bool {
		if ( ! $this->enabled ) {
			return false;
		}

		global $wpdb;

		$user_id     = get_current_user_id();
		$ip          = $this->hash_client_ip();
		$user_agent  = $this->get_user_agent();
		$request_uri = $this->get_request_uri();

		$event             = substr( sanitize_key( $event ), 0, 50 );
		$event             = '' !== $event ? $event : 'unknown';
		$sanitized_context = $this->sanitize_context( $context );
		$session_id        = isset( $sanitized_context['session_id'] ) && is_scalar( $sanitized_context['session_id'] )
			? sanitize_key( (string) $sanitized_context['session_id'] )
			: '';

		$encoded_context = wp_json_encode( $sanitized_context );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write, no caching for audit integrity
		$result          = $wpdb->insert(
			$this->table_name,
			array(
				'timestamp'   => current_time( 'mysql', true ),
				'event'       => $event,
				'user_id'     => $user_id,
				'ip_address'  => $ip,
				'user_agent'  => $user_agent,
				'request_uri' => $request_uri,
				'context'     => false !== $encoded_context ? $encoded_context : '{}',
				'session_id'  => $session_id,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Sanitize context data by redacting sensitive keys.
	 *
	 * @param array $context Context data.
	 * @param int   $depth   Current recursion depth.
	 * @return array Sanitized context.
	 */
	private function sanitize_context( array $context, int $depth = 0 ): array {
		if ( $depth >= 5 ) {
			return array( '_truncated' => true );
		}

		$forbidden_keys = array(
			'password',
			'token',
			'secret',
			'api_key',
			'apikey',
			'auth',
			'credential',
			'private_key',
			'nonce',
			'session_key',
			'bearer',
			'access_key',
			'client_secret',
			'cookie',
			'set_cookie',
			'wordpress_logged',
			'wordpress_sec',
			'php_session',
			'phpsessid',
		);

		foreach ( $context as $key => $value ) {

			$is_sensitive = false;
			foreach ( $forbidden_keys as $forbidden ) {
				if ( stripos( (string) $key, $forbidden ) !== false ) {
					$is_sensitive = true;
					break;
				}
			}

			if ( ! $is_sensitive && is_string( $value ) && $this->looks_like_jwt( $value ) ) {
				$is_sensitive = true;
			}
			if ( $is_sensitive ) {
				$context[ $key ] = '[REDACTED]';
			} elseif ( is_array( $value ) ) {
				$context[ $key ] = $this->sanitize_context( $value, $depth + 1 );
			} elseif ( is_object( $value ) ) {
				$context[ $key ] = $this->sanitize_context( get_object_vars( $value ), $depth + 1 );
			} elseif ( is_string( $value ) ) {
				$context[ $key ] = SScribe_Helpers::mb_substr( $value, 0, 2000 );
			} elseif ( ! is_scalar( $value ) && null !== $value ) {
				$context[ $key ] = '[UNSUPPORTED]';
			}
		}

		return $context;
	}

	/**
	 * Heuristic check for a JSON Web Token string.
	 *
	 * A JWT has the form `header.payload.signature` where each segment
	 * is base64url-encoded. The header always starts with `eyJ` (the
	 * base64url of `{"`). We accept any string that has at least two
	 * dots and starts with `eyJ`.
	 *
	 * False positives are not a security problem : the value is redacted
	 * either way. False negatives (e.g. a JWT without the canonical
	 * header) are also not a problem, because the bearer/secret/...
	 * substring check above will catch most of them.
	 *
	 * @param string $value String to inspect.
	 * @return bool True if the value looks like a JWT.
	 */
	private function looks_like_jwt( string $value ): bool {
		if ( strlen( $value ) < 8 || strpos( $value, 'eyJ' ) !== 0 ) {
			return false;
		}

		return substr_count( $value, '.' ) >= 2;
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		return SScribe_Helpers::get_client_ip();
	}

	/**
	 * Hash the client IP for privacy.
	 *
	 * @return string
	 */
	private function hash_client_ip(): string {
		$raw_ip = $this->get_client_ip();
		$salt   = defined( 'AUTH_SALT' ) && '' !== AUTH_SALT ? AUTH_SALT : ( function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'sscribe-audit-fallback' );
		return substr( hash_hmac( 'sha256', $raw_ip, $salt ), 0, 16 );
	}

	/**
	 * Get the current user agent.
	 *
	 * @return string
	 */
	private function get_user_agent(): string {
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) && '' !== $_SERVER['HTTP_USER_AGENT'] ) {
			return SScribe_Helpers::mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 );
		}
		return 'Unknown';
	}

	/**
	 * Get the current request URI.
	 *
	 * @return string
	 */
	private function get_request_uri(): string {
		if ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) && '' !== $_SERVER['REQUEST_URI'] ) {
			$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			$path        = wp_parse_url( $request_uri, PHP_URL_PATH );
			return is_string( $path ) ? SScribe_Helpers::mb_substr( $path, 0, 2083 ) : '';
		}
		return '';
	}

	/**
	 * Get audit logs with optional filters.
	 *
	 * @param array $filters Filter criteria.
	 * @param int   $limit   Max records to return.
	 * @param int   $offset  Record offset.
	 * @return array
	 */
	public function get_logs( array $filters = array(), int $limit = 100, int $offset = 0 ): array {
		if ( ! $this->enabled ) {
			return array();
		}

		if ( ! current_user_can( SScribe_Capabilities::get_health_required() ) ) {
			return array();
		}

		return $this->query_logs( $filters, $limit, $offset );
	}

	/**
	 * Get audit rows for WordPress's authorized personal-data exporter.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Maximum rows.
	 * @param int $offset  Row offset.
	 * @return array
	 */
	public function get_logs_for_user( int $user_id, int $limit = 100, int $offset = 0 ): array {
		if ( ! $this->enabled || $user_id <= 0 ) {
			return array();
		}

		return $this->query_logs( array( 'user_id' => $user_id ), $limit, $offset );
	}

	/**
	 * Run a bounded audit-log query.
	 *
	 * @param array $filters Filter criteria.
	 * @param int   $limit   Maximum rows.
	 * @param int   $offset  Row offset.
	 * @return array
	 */
	private function query_logs( array $filters, int $limit, int $offset ): array {
		global $wpdb;

		$limit  = max( 1, min( 500, $limit ) );
		$offset = max( 0, $offset );

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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic WHERE clause placeholders counted at runtime
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE {$where_clause} ORDER BY timestamp DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name from $wpdb->prefix (trusted), WHERE clause built from controlled filter keys with placeholders
				...$args
			)
		);
	}

	/**
	 * Get event counts grouped by event type.
	 *
	 * @param array $filters Filter criteria.
	 * @return array
	 */
	public function get_event_counts( array $filters = array() ): array {
		if ( ! $this->enabled ) {
			return array();
		}

		$filters_json = wp_json_encode( $filters );
		$cache_key    = 'sscribe_audit_counts_' . md5( false !== $filters_json ? $filters_json : '' );
		$cached       = wp_cache_get( $cache_key, 'sscribe_audit_counts' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$result = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event, COUNT(*) as count FROM {$this->table_name} WHERE {$where_clause} GROUP BY event ORDER BY count DESC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name from $wpdb->prefix (trusted), WHERE clause built from controlled filter keys with placeholders
				...$args
			)
		);

		wp_cache_set( $cache_key, $result, 'sscribe_audit_counts', 30 );
		return $result;
	}

	/**
	 * Clean up old audit log entries.
	 *
	 * @param int $days Age threshold in days.
	 * @return int Number of deleted rows.
	 */
	public function cleanup( int $days = 90 ): int {
		if ( ! $this->enabled ) {
			return 0;
		}

		global $wpdb;

		$days    = max( 1, $days );
		$cutoff  = strtotime( "-{$days} days" );
		$cutoff  = gmdate( 'Y-m-d H:i:s', false !== $cutoff ? $cutoff : time() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'DELETE FROM ' . $this->table_name . ' WHERE timestamp < %s',
				$cutoff
			)
		);

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Erase user-specific audit data for GDPR compliance.
	 *
	 * @param int $user_id User ID.
	 * @return int Number of affected rows.
	 */
	public function erase_user_data( int $user_id ): int {
		if ( ! $this->enabled || $user_id <= 0 ) {
			return 0;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- GDPR erase, custom table write
		$result = $wpdb->update(
			$this->table_name,
			array(
				'user_id'     => 0,
				'ip_address'  => '',
				'user_agent'  => '',
				'request_uri' => '',
				'context'     => '{}',
			),
			array( 'user_id' => $user_id ),
			array( '%d', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Create the audit log database table.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'sscribe_audit_log';

		$sql = "CREATE TABLE $table_name (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			event VARCHAR(50) NOT NULL,
			user_id BIGINT UNSIGNED,
			ip_address VARCHAR(45),
			user_agent VARCHAR(255),
			request_uri VARCHAR(2083),
			context LONGTEXT,
			session_id VARCHAR(50),
			PRIMARY KEY  (id),
			KEY idx_timestamp (timestamp),
			KEY idx_event (event),
			KEY idx_user_id (user_id),
			KEY idx_ip_address (ip_address),
			KEY idx_session_id (session_id)
		) $charset_collate;";

		$upgrade_functions = ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( file_exists( $upgrade_functions ) ) {
			require_once $upgrade_functions;
		}
		dbDelta( $sql );
	}
}
