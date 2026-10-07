<?php
/**
 * SScribe Schedule Notifier
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
 * Emails a schedule owner about a finished or failed run.
 */
final class SScribe_Schedule_Notifier {

	/**
	 * Send the notification when the schedule asks for one.
	 *
	 * @param SScribe_Schedule         $schedule Schedule after the run.
	 * @param array<string|int, mixed> $payload  Export payload with optional "delivery" results, or code and message for a failure.
	 * @return void
	 */
	public static function notify( SScribe_Schedule $schedule, array $payload ): void {
		if ( ! $schedule->notify ) {
			return;
		}

		$user = get_userdata( $schedule->owner_user_id );
		$mail = apply_filters(
			'sscribe_schedule_notification',
			array(
				'to'      => $user instanceof WP_User ? (string) $user->user_email : '',
				'subject' => self::subject( $schedule ),
				'message' => self::body( $schedule, $payload ),
			),
			$schedule,
			$payload
		);

		if ( ! is_array( $mail ) || empty( $mail['to'] ) || ! is_string( $mail['to'] ) ) {
			return;
		}

		if ( ! wp_mail( $mail['to'], (string) ( $mail['subject'] ?? '' ), (string) ( $mail['message'] ?? '' ) ) ) {
			SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() )->warning(
				'Schedule notification email was not sent',
				array( 'schedule_id' => $schedule->id )
			);
		}
	}

	/**
	 * Subject line of the notification email.
	 *
	 * @param SScribe_Schedule $schedule Schedule after the run.
	 * @return string
	 */
	private static function subject( SScribe_Schedule $schedule ): string {
		$site = html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' );

		if ( 'success' === $schedule->last_run_status ) {
			/* translators: 1: Site name, 2: Schedule label. */
			$format = __( '[%1$s] Scheduled export "%2$s" finished', 'sscribe-export-site-pages' );
		} else {
			/* translators: 1: Site name, 2: Schedule label. */
			$format = __( '[%1$s] Scheduled export "%2$s" failed', 'sscribe-export-site-pages' );
		}

		return sprintf( $format, $site, $schedule->label );
	}

	/**
	 * Body of the notification email.
	 *
	 * @param SScribe_Schedule         $schedule Schedule after the run.
	 * @param array<string|int, mixed> $payload  Export payload, or code and message for a failure.
	 * @return string
	 */
	private static function body( SScribe_Schedule $schedule, array $payload ): string {
		$errors = $payload['errors'] ?? 0;
		$lines  = array(
			/* translators: %s: Schedule label. */
			sprintf( __( 'Schedule: %s', 'sscribe-export-site-pages' ), $schedule->label ),
			/* translators: %s: Run status, success or failed. */
			sprintf( __( 'Status: %s', 'sscribe-export-site-pages' ), 'success' === $schedule->last_run_status ? __( 'success', 'sscribe-export-site-pages' ) : __( 'failed', 'sscribe-export-site-pages' ) ),
		);

		if ( 'success' === $schedule->last_run_status ) {
			/* translators: %d: Number of pages exported. */
			$lines[] = sprintf( __( 'Pages: %d', 'sscribe-export-site-pages' ), (int) ( $payload['pages'] ?? 0 ) );
			/* translators: %d: Number of pages that failed to export. */
			$lines[] = sprintf( __( 'Errors: %d', 'sscribe-export-site-pages' ), is_array( $errors ) ? count( $errors ) : (int) $errors );
			$lines[] = '' !== $schedule->last_run_file
				/* translators: %s: ZIP filename. */
				? sprintf( __( 'File: %s', 'sscribe-export-site-pages' ), $schedule->last_run_file )
				: __( 'No archive was made because nothing changed since the last run.', 'sscribe-export-site-pages' );
			$lines   = array_merge( $lines, self::delivery_lines( $payload['delivery'] ?? array() ) );
		} else {
			/* translators: %s: Error message. */
			$lines[] = sprintf( __( 'Error: %s', 'sscribe-export-site-pages' ), $schedule->last_error );
		}

		/* translators: %s: URL of the export history page. */
		$lines[] = sprintf( __( 'Export history: %s', 'sscribe-export-site-pages' ), admin_url( 'admin.php?page=sscribe-export&tab=history' ) );

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * One line per destination the archive was sent to.
	 *
	 * @param mixed $results Delivery results.
	 * @return list<string>
	 */
	private static function delivery_lines( mixed $results ): array {
		if ( ! is_array( $results ) ) {
			return array();
		}

		$lines = array();
		foreach ( $results as $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}
			$id      = sanitize_key( (string) ( $result['id'] ?? '' ) );
			$message = (string) ( $result['message'] ?? '' );
			$lines[] = ! empty( $result['ok'] )
				/* translators: %s: Destination id. */
				? sprintf( __( 'Delivered to %s', 'sscribe-export-site-pages' ), $id )
				/* translators: 1: Destination id, 2: Error message. */
				: sprintf( __( 'Delivery to %1$s failed: %2$s', 'sscribe-export-site-pages' ), $id, $message );
		}

		return $lines;
	}
}
