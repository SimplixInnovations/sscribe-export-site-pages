<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package SScribe
 */

// If uninstall not called from WordPress, abort.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Recursively delete export directory and all files/folders inside.
$sscribe_upload_dir  = wp_upload_dir();
$sscribe_export_path = $sscribe_upload_dir['basedir'] . '/sscribe-exports';

if ( is_dir( $sscribe_export_path ) ) {
	$sscribe_objects = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $sscribe_export_path, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $sscribe_objects as $sscribe_fileinfo ) {
		if ( $sscribe_fileinfo->isDir() ) {
			if ( is_dir( $sscribe_fileinfo->getRealPath() ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				rmdir( $sscribe_fileinfo->getRealPath() );
			}
		} else {
			wp_delete_file( $sscribe_fileinfo->getRealPath() );
		}
	}
	if ( is_dir( $sscribe_export_path ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $sscribe_export_path );
	}
}

// Recursively delete session directory and all files/folders inside.
$sscribe_session_path = $sscribe_upload_dir['basedir'] . '/sscribe-sessions';

if ( is_dir( $sscribe_session_path ) ) {
	$sscribe_session_objects = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $sscribe_session_path, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $sscribe_session_objects as $sscribe_session_fileinfo ) {
		if ( $sscribe_session_fileinfo->isDir() ) {
			if ( is_dir( $sscribe_session_fileinfo->getRealPath() ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				rmdir( $sscribe_session_fileinfo->getRealPath() );
			}
		} else {
			wp_delete_file( $sscribe_session_fileinfo->getRealPath() );
		}
	}
	if ( is_dir( $sscribe_session_path ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $sscribe_session_path );
	}
}

// Recursively delete logs directory.
$sscribe_logs_path = $sscribe_upload_dir['basedir'] . '/sscribe-logs';

if ( is_dir( $sscribe_logs_path ) ) {
	$sscribe_logs_objects = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $sscribe_logs_path, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $sscribe_logs_objects as $sscribe_logs_fileinfo ) {
		if ( $sscribe_logs_fileinfo->isDir() ) {
			if ( is_dir( $sscribe_logs_fileinfo->getRealPath() ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				rmdir( $sscribe_logs_fileinfo->getRealPath() );
			}
		} else {
			wp_delete_file( $sscribe_logs_fileinfo->getRealPath() );
		}
	}
	if ( is_dir( $sscribe_logs_path ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $sscribe_logs_path );
	}
}

// Remove plugin options.
delete_option( 'sscribe_version' );
delete_option( 'sscribe_export_index' );

// Clear scheduled cron events.
$sscribe_timestamp = wp_next_scheduled( 'sscribe_cleanup_exports' );
if ( $sscribe_timestamp ) {
	wp_unschedule_event( $sscribe_timestamp, 'sscribe_cleanup_exports' );
}

$sscribe_session_timestamp = wp_next_scheduled( 'sscribe_cleanup_sessions' );
if ( $sscribe_session_timestamp ) {
	wp_unschedule_event( $sscribe_session_timestamp, 'sscribe_cleanup_sessions' );
}
