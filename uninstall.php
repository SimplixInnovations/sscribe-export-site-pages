<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package SScribe
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$sscribe_upload_dir = wp_upload_dir();

$directories_to_clean = array(
	$sscribe_upload_dir['basedir'] . '/sscribe-exports',
	$sscribe_upload_dir['basedir'] . '/sscribe-sessions',
	$sscribe_upload_dir['basedir'] . '/sscribe-logs',
);

foreach ( $directories_to_clean as $dir_path ) {
	if ( is_dir( $dir_path ) ) {
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir_path, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ( $iterator as $fileinfo ) {
				try {
					if ( $fileinfo->isDir() ) {
						$real_path = $fileinfo->getRealPath();
						if ( $real_path && is_dir( $real_path ) ) {
							rmdir( $real_path );
						}
					} else {
						$real_path = $fileinfo->getRealPath();
						if ( $real_path ) {
							wp_delete_file( $real_path );
						}
					}
				} catch ( \Throwable $e ) {
					continue;
				}
			}

			if ( is_dir( $dir_path ) ) {
				rmdir( $dir_path );
			}
		} catch ( \Throwable $e ) {
			continue;
		}
	}
}

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = 'no'",
		$wpdb->esc_like( 'sscribe_session_' ) . '%'
	)
);

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND autoload = 'no'",
		$wpdb->esc_like( 'sscribe_log_' ) . '%'
	)
);

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'sscribe_rate_' ) . '%'
	)
);

delete_option( 'sscribe_version' );
delete_option( 'sscribe_export_index' );

$sscribe_timestamp = wp_next_scheduled( 'sscribe_cleanup_exports' );
if ( $sscribe_timestamp ) {
	wp_unschedule_event( $sscribe_timestamp, 'sscribe_cleanup_exports' );
}

$sscribe_session_timestamp = wp_next_scheduled( 'sscribe_cleanup_sessions' );
if ( $sscribe_session_timestamp ) {
	wp_unschedule_event( $sscribe_session_timestamp, 'sscribe_cleanup_sessions' );
}
