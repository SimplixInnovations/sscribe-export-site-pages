<?php
/**
 * SScribe Privacy
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
 * WordPress privacy tools integration for personal data export and erasure.
 */
class SScribe_Privacy {

	/**
	 * Audit trail instance.
	 *
	 * @var SScribe_Audit_Trail
	 */
	private SScribe_Audit_Trail $audit_trail;

	/**
	 * Session manager instance.
	 *
	 * @var SScribe_Session
	 */
	private SScribe_Session $session;

	/**
	 * Export statistics instance.
	 *
	 * @var SScribe_Export_Stats
	 */
	private SScribe_Export_Stats $export_stats;

	/**
	 * Export archive manager.
	 *
	 * @var SScribe_Zip_Handler
	 */
	private SScribe_Zip_Handler $zip_handler;

	/**
	 * Initialize privacy components.
	 */
	public function __construct() {
		$this->audit_trail  = new SScribe_Audit_Trail();
		$this->session      = new SScribe_Session();
		$this->export_stats = new SScribe_Export_Stats();
		$this->zip_handler  = new SScribe_Zip_Handler();
	}

	/**
	 * Register privacy policy content.
	 */
	public function register_privacy_policy(): void {
		if ( ! function_exists( 'register_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<p>' . esc_html__( 'SScribe stores export session records, export statistics, and security audit events to provide reliable document exports, enforce rate limiting, and help administrators troubleshoot failures.', 'sscribe-export-site-pages' ) . '</p>';
		$content .= '<p>' . esc_html__( 'These records may include your user ID, a shortened hash derived from your IP address, browser user agent, request path, export status metadata, and timestamps. Generated export packages and debug logs are stored temporarily in private server-side storage outside public WordPress directories and are automatically cleaned up after their retention window.', 'sscribe-export-site-pages' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Site owners can export or erase SScribe personal data through WordPress privacy tools.', 'sscribe-export-site-pages' ) . '</p>';

		register_privacy_policy_content(
			esc_html__( 'SScribe Export Site Pages', 'sscribe-export-site-pages' ),
			wp_kses_post( $content )
		);
	}

	/**
	 * Register personal data exporter.
	 *
	 * @param array $exporters Existing exporters.
	 * @return array
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['sscribe-export-site-pages'] = array(
			'exporter_friendly_name' => esc_html__( 'SScribe export data', 'sscribe-export-site-pages' ),
			'callback'               => array( $this, 'export_personal_data' ),
		);

		return $exporters;
	}

	/**
	 * Register personal data eraser.
	 *
	 * @param array $erasers Existing erasers.
	 * @return array
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['sscribe-export-site-pages'] = array(
			'eraser_friendly_name' => esc_html__( 'SScribe export data', 'sscribe-export-site-pages' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);

		return $erasers;
	}

	/**
	 * Export personal data for a user.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Pagination page.
	 * @return array
	 */
	public function export_personal_data( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', sanitize_email( $email_address ) );
		if ( ! $user || empty( $user->ID ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$user_id      = (int) $user->ID;
		$export_items = array();
		$items_limit  = 100;
		$page         = max( 1, $page );
		$offset       = ( $page - 1 ) * $items_limit;

		$audit_logs = $this->audit_trail->get_logs_for_user( $user_id, $items_limit, $offset );

		foreach ( $audit_logs as $log ) {
			$export_items[] = array(
				'group_id'    => 'sscribe-audit-events',
				'group_label' => esc_html__( 'SScribe audit trail', 'sscribe-export-site-pages' ),
				'item_id'     => 'sscribe-audit-' . absint( $log->id ),
				'data'        => array(
					array(
						'name'  => esc_html__( 'Event', 'sscribe-export-site-pages' ),
						'value' => $log->event,
					),
					array(
						'name'  => esc_html__( 'Timestamp', 'sscribe-export-site-pages' ),
						'value' => $log->timestamp,
					),
					array(
						'name'  => esc_html__( 'Hashed IP identifier', 'sscribe-export-site-pages' ),
						'value' => $log->ip_address,
					),
					array(
						'name'  => esc_html__( 'User agent', 'sscribe-export-site-pages' ),
						'value' => $log->user_agent,
					),
					array(
						'name'  => esc_html__( 'Request URI', 'sscribe-export-site-pages' ),
						'value' => $log->request_uri,
					),
					array(
						'name'  => esc_html__( 'Context', 'sscribe-export-site-pages' ),
						'value' => $log->context,
					),
				),
			);
		}

		$export_stats = $this->export_stats->get_exports_by_user( $user_id, $items_limit, $offset );
		foreach ( $export_stats as $stat ) {
			$export_items[] = array(
				'group_id'    => 'sscribe-export-stats',
				'group_label' => esc_html__( 'SScribe export statistics', 'sscribe-export-site-pages' ),
				'item_id'     => 'sscribe-export-stat-' . absint( $stat->id ),
				'data'        => array(
					array(
						'name'  => esc_html__( 'Export session ID', 'sscribe-export-site-pages' ),
						'value' => $stat->export_session_id,
					),
					array(
						'name'  => esc_html__( 'Export date', 'sscribe-export-site-pages' ),
						'value' => $stat->export_date,
					),
					array(
						'name'  => esc_html__( 'Status', 'sscribe-export-site-pages' ),
						'value' => $stat->status,
					),
					array(
						'name'  => esc_html__( 'Total pages', 'sscribe-export-site-pages' ),
						'value' => (string) $stat->total_pages,
					),
					array(
						'name'  => esc_html__( 'Successful pages', 'sscribe-export-site-pages' ),
						'value' => (string) $stat->successful_pages,
					),
					array(
						'name'  => esc_html__( 'Failed pages', 'sscribe-export-site-pages' ),
						'value' => (string) $stat->failed_pages,
					),
					array(
						'name'  => esc_html__( 'Formats', 'sscribe-export-site-pages' ),
						'value' => (string) $stat->formats,
					),
					array(
						'name'  => esc_html__( 'Error message', 'sscribe-export-site-pages' ),
						'value' => (string) $stat->error_message,
					),
				),
			);
		}

		$session_page = $this->session->get_sessions_for_user( $user_id, $items_limit + 1, $offset );
		$sessions     = array_slice( $session_page, 0, $items_limit );
		foreach ( $sessions as $session ) {
			$session_id = sanitize_key( (string) ( $session['session_id'] ?? '' ) );
			if ( '' === $session_id ) {
				continue;
			}
				$export_items[] = array(
					'group_id'    => 'sscribe-export-sessions',
					'group_label' => esc_html__( 'SScribe export sessions', 'sscribe-export-site-pages' ),
					'item_id'     => 'sscribe-session-' . $session_id,
					'data'        => array(
						array(
							'name'  => esc_html__( 'Session ID', 'sscribe-export-site-pages' ),
							'value' => (string) ( $session['session_id'] ?? '' ),
						),
						array(
							'name'  => esc_html__( 'Created at', 'sscribe-export-site-pages' ),
							'value' => isset( $session['created_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $session['created_at'] ) : '',
						),
						array(
							'name'  => esc_html__( 'Updated at', 'sscribe-export-site-pages' ),
							'value' => isset( $session['updated_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $session['updated_at'] ) : '',
						),
						array(
							'name'  => esc_html__( 'Status', 'sscribe-export-site-pages' ),
							'value' => (string) ( $session['status'] ?? '' ),
						),
					),
				);
		}

		if ( 1 === $page ) {
			foreach ( $this->zip_handler->list_export_entries() as $filename => $entry ) {
				if ( (int) ( $entry['user_id'] ?? 0 ) !== $user_id ) {
					continue;
				}
				$export_items[] = array(
					'group_id'    => 'sscribe-export-archives',
					'group_label' => esc_html__( 'SScribe export archives', 'sscribe-export-site-pages' ),
					'item_id'     => 'sscribe-archive-' . md5( (string) $filename ),
					'data'        => array(
						array(
							'name'  => esc_html__( 'Archive filename', 'sscribe-export-site-pages' ),
							'value' => (string) $filename,
						),
						array(
							'name'  => esc_html__( 'Created at', 'sscribe-export-site-pages' ),
							'value' => ! empty( $entry['created_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $entry['created_at'] ) : '',
						),
					),
				);
			}
		}

		$has_more = count( $audit_logs ) >= $items_limit
			|| count( $export_stats ) >= $items_limit
			|| count( $session_page ) > $items_limit;

		return array(
			'data' => $export_items,
			'done' => ! $has_more,
		);
	}

	/**
	 * Erase personal data for a user.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Pagination page.
	 * @return array
	 */
	public function erase_personal_data( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', sanitize_email( $email_address ) );
		if ( ! $user || empty( $user->ID ) ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$user_id          = (int) $user->ID;
		$items_limit      = 100;
		$session_page     = $this->session->get_sessions_for_user( $user_id, $items_limit + 1, 0 );
		$session_batch    = array_slice( $session_page, 0, $items_limit );
		$removed_items    = 0;
		$retained_items   = 0;
		$session_failures = 0;

		foreach ( $session_batch as $user_session ) {
			$session_id = sanitize_key( (string) ( $user_session['session_id'] ?? '' ) );
			if ( 1 !== preg_match( '/^[a-f0-9]{16}$/D', $session_id ) ) {
				continue;
			}

			if ( $this->session->delete_owned_if_unlocked( $session_id, $user_id ) ) {
				SScribe_Export_Log::delete_by_session( $session_id );
				++$removed_items;
			} else {
				++$session_failures;
			}
		}

		$has_more_sessions = count( $session_page ) > $items_limit;
		if ( $has_more_sessions || $session_failures > 0 ) {
			return array(
				'items_removed'  => $removed_items > 0,
				'items_retained' => $session_failures > 0,
				'messages'       => $session_failures > 0
					? array( esc_html__( 'Some export sessions could not be removed. Please run the eraser again.', 'sscribe-export-site-pages' ) )
					: array(),
				'done'           => false,
			);
		}

		$removed_items += $this->audit_trail->erase_user_data( $user_id );
		$removed_items += $this->export_stats->erase_user_data( $user_id );

		foreach ( $this->zip_handler->list_export_entries() as $filename => $entry ) {
			if ( (int) ( $entry['user_id'] ?? 0 ) !== $user_id ) {
				continue;
			}
			if ( $this->zip_handler->delete_export( (string) $filename, $user_id ) ) {
				++$removed_items;
			} else {
				++$retained_items;
			}
		}

		return array(
			'items_removed'  => $removed_items > 0,
			'items_retained' => $retained_items > 0,
			'messages'       => $retained_items > 0
				? array( esc_html__( 'Some export archives could not be removed because another export operation is using the archive index. Please run the eraser again.', 'sscribe-export-site-pages' ) )
				: array(),
			'done'           => 0 === $retained_items,
		);
	}
}
