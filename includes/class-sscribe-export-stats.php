<?php
/**
 * Export statistics tracking for SScribe.
 *
 * @package SScribe
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SScribe_Export_Stats
 *
 * Tracks and retrieves export statistics for monitoring dashboard.
 */
class SScribe_Export_Stats {

	/**
	 * Table name for statistics.
	 *
	 * @var string
	 */
	private readonly string $table_name;

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'sscribe_export_stats';
	}

	/**
	 * Record a new export session.
	 *
	 * @param string $session_id Session ID.
	 * @param int    $user_id    User ID.
	 * @param array  $config     Export configuration.
	 * @return bool True if insert succeeded.
	 */
	public function start_export( string $session_id, int $user_id, array $config ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write, no caching for stats integrity
		return false !== $wpdb->insert(
			$this->table_name,
			array(
				'export_session_id' => $session_id,
				'user_id'           => $user_id,
				'export_date'       => current_time( 'mysql', true ),
				'total_pages'       => $config['total_pages'] ?? 0,
				'formats'           => wp_json_encode( $config['formats'] ?? array() ),
				'status'            => 'processing',
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Update export progress.
	 *
	 * @param string $session_id      Session ID.
	 * @param int    $successful_pages Successful page count.
	 * @param int    $failed_pages    Failed page count.
	 * @return bool Success.
	 */
	public function update_progress( string $session_id, int $successful_pages, int $failed_pages = 0 ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write
		return false !== $wpdb->update(
			$this->table_name,
			array(
				'successful_pages' => $successful_pages,
				'failed_pages'     => $failed_pages,
			),
			array( 'export_session_id' => $session_id ),
			array( '%d', '%d' ),
			array( '%s' )
		);
	}

	/**
	 * Complete an export session.
	 *
	 * @param string $session_id Session ID.
	 * @param array  $results    Export results.
	 * @return bool Success.
	 */
	public function complete_export( string $session_id, array $results = array() ): bool {
		global $wpdb;

		$data = array(
			'status'           => 'completed',
			'successful_pages' => $results['successful_pages'] ?? 0,
			'failed_pages'     => $results['failed_pages'] ?? 0,
			'memory_peak'      => $results['memory_peak'] ?? '',
			'duration_seconds' => $results['duration'] ?? 0,
			'file_size_mb'     => $results['file_size_mb'] ?? 0,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write
		return false !== $wpdb->update(
			$this->table_name,
			$data,
			array( 'export_session_id' => $session_id ),
			array( '%s', '%d', '%d', '%s', '%f', '%f' ),
			array( '%s' )
		);
	}

	/**
	 * Mark export as failed.
	 *
	 * @param string $session_id   Session ID.
	 * @param string $error_message Error message.
	 * @return bool Success.
	 */
	public function fail_export( string $session_id, string $error_message ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write
		return false !== $wpdb->update(
			$this->table_name,
			array(
				'status'        => 'failed',
				'error_message' => $error_message,
			),
			array( 'export_session_id' => $session_id ),
			array( '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Get export statistics for a period.
	 *
	 * @param string $period Period: 'today', 'week', 'month', 'year'.
	 * @return array Statistics.
	 */
	public function get_stats( string $period = 'month' ): array {
		$date_from = $this->get_period_start( $period );

		return array(
			'total_exports'      => $this->get_total_exports( $date_from ),
			'successful_exports' => $this->get_successful_exports( $date_from ),
			'failed_exports'     => $this->get_failed_exports( $date_from ),
			'total_pages'        => $this->get_total_pages( $date_from ),
			'avg_duration'       => $this->get_avg_duration( $date_from ),
			'total_size_mb'      => $this->get_total_size( $date_from ),
			'format_breakdown'   => $this->get_format_breakdown( $date_from ),
			'daily_exports'      => $this->get_daily_exports( $date_from ),
		);
	}

	/**
	 * Get period start date.
	 *
	 * @param string $period Period name.
	 * @return string MySQL date string.
	 */
	private function get_period_start( string $period ): string {
		$intervals = array(
			'today' => '-1 day',
			'week'  => '-1 week',
			'month' => '-1 month',
			'year'  => '-1 year',
		);

		$interval = $intervals[ $period ] ?? '-1 month';

		return gmdate( 'Y-m-d H:i:s', strtotime( $interval ) );
	}

	/**
	 * Get total exports count.
	 *
	 * @param string $date_from Start date.
	 * @return int Count.
	 */
	private function get_total_exports( string $date_from ): int {
		global $wpdb;

		// Table name from $wpdb->prefix is trusted (not user-controlled) — safe to interpolate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'SELECT COUNT(*) FROM ' . $this->table_name . ' WHERE export_date >= %s',
				$date_from
			)
		);
	}

	/**
	 * Get successful exports count.
	 *
	 * @param string $date_from Start date.
	 * @return int Count.
	 */
	private function get_successful_exports( string $date_from ): int {
		global $wpdb;

		// Table name from $wpdb->prefix is trusted (not user-controlled) — safe to interpolate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'SELECT COUNT(*) FROM ' . $this->table_name . " WHERE export_date >= %s AND status = 'completed'",
				$date_from
			)
		);
	}

	/**
	 * Get failed exports count.
	 *
	 * @param string $date_from Start date.
	 * @return int Count.
	 */
	private function get_failed_exports( string $date_from ): int {
		global $wpdb;

		// Table name from $wpdb->prefix is trusted (not user-controlled) — safe to interpolate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'SELECT COUNT(*) FROM ' . $this->table_name . " WHERE export_date >= %s AND status = 'failed'",
				$date_from
			)
		);
	}

	/**
	 * Get total pages exported.
	 *
	 * @param string $date_from Start date.
	 * @return int Count.
	 */
	private function get_total_pages( string $date_from ): int {
		global $wpdb;

		// Table name from $wpdb->prefix is trusted (not user-controlled) — safe to interpolate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'SELECT SUM(total_pages) FROM ' . $this->table_name . ' WHERE export_date >= %s AND status = \'completed\'',
				$date_from
			)
		);
	}

	/**
	 * Get average export duration.
	 *
	 * @param string $date_from Start date.
	 * @return float Average seconds.
	 */
	private function get_avg_duration( string $date_from ): float {
		global $wpdb;

		// Table name from $wpdb->prefix is trusted (not user-controlled) — safe to interpolate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (float) $wpdb->get_var(
			$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'SELECT AVG(duration_seconds) FROM ' . $this->table_name . ' WHERE export_date >= %s AND status = \'completed\'',
				$date_from
			)
		);
	}

	/**
	 * Get total export size in MB.
	 *
	 * @param string $date_from Start date.
	 * @return float Total size in MB.
	 */
	private function get_total_size( string $date_from ): float {
		global $wpdb;

		// Table name from $wpdb->prefix is trusted (not user-controlled) — safe to interpolate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (float) $wpdb->get_var(
			$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'SELECT SUM(file_size_mb) FROM ' . $this->table_name . ' WHERE export_date >= %s AND status = \'completed\'',
				$date_from
			)
		);
	}

	/**
	 * Get format breakdown.
	 *
	 * @param string $date_from Start date.
	 * @return array Format counts.
	 */
	private function get_format_breakdown( string $date_from ): array {
		global $wpdb;

		// Table name from $wpdb->prefix is trusted (not user-controlled) — safe to interpolate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'SELECT formats FROM ' . $this->table_name . ' WHERE export_date >= %s AND status = \'completed\'',
				$date_from
			)
		);

		$breakdown = array(
			'docx'     => 0,
			'pdf'      => 0,
			'html'     => 0,
			'markdown' => 0,
		);

		foreach ( $results as $row ) {
			$formats = json_decode( $row->formats, true );
			if ( is_array( $formats ) ) {
				foreach ( $formats as $format ) {
					if ( isset( $breakdown[ $format ] ) ) {
						++$breakdown[ $format ];
					}
				}
			}
		}

		return $breakdown;
	}

	/**
	 * Get daily export counts.
	 *
	 * @param string $date_from Start date.
	 * @return array Daily counts.
	 */
	private function get_daily_exports( string $date_from ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Table name from $wpdb->prefix is trusted (not user-controlled) — safe to interpolate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DATE(export_date) as date, COUNT(*) as count, SUM(total_pages) as pages
				FROM '

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				. $this->table_name . '
				WHERE export_date >= %s
				GROUP BY DATE(export_date)
				ORDER BY date DESC
				LIMIT 30',
				$date_from
			)
		);

		$daily = array();
		foreach ( $results as $row ) {
			$daily[ $row->date ] = array(
				'exports' => (int) $row->count,
				'pages'   => (int) $row->pages,
			);
		}

		return $daily;
	}

	/**
	 * Get recent exports.
	 *
	 * @param int $limit Maximum results.
	 * @return array Recent exports.
	 */
	public function get_recent_exports( int $limit = 10 ): array {
		global $wpdb;

		// Table name from $wpdb->prefix is trusted (not user-controlled) — safe to interpolate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'SELECT * FROM ' . $this->table_name . ' ORDER BY export_date DESC LIMIT %d',
				$limit
			)
		);
	}

	/**
	 * Get exports associated with a specific user.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Maximum results.
	 * @return array
	 */
	public function get_exports_by_user( int $user_id, int $limit = 100 ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		global $wpdb;

		// Table name from $wpdb->prefix is trusted (not user-controlled) — safe to interpolate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'SELECT * FROM ' . $this->table_name . ' WHERE user_id = %d ORDER BY export_date DESC LIMIT %d',
				$user_id,
				$limit
			)
		);
	}

	/**
	 * Erase personal data associated with a specific user while retaining aggregate export history.
	 *
	 * @param int $user_id User ID.
	 * @return int Number of updated rows.
	 */
	public function erase_user_data( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- GDPR erase, custom table write
		$result = $wpdb->update(
			$this->table_name,
			array(
				'user_id'       => 0,
				'error_message' => '',
			),
			array( 'user_id' => $user_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Clean up old statistics.
	 *
	 * @param int $days Maximum age in days.
	 * @return int Deleted rows.
	 */
	public function cleanup( int $days = 365 ): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// Table name from $wpdb->prefix is trusted (not user-controlled) — safe to interpolate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->query(
			$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'DELETE FROM ' . $this->table_name . ' WHERE export_date < %s',
				$cutoff
			)
		);
	}
}
