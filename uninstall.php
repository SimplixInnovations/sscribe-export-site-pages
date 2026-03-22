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

// Remove plugin options.
delete_option( 'sscribe_version' );
delete_option( 'sscribe_export_index' );

// Clear scheduled cron events.
$sscribe_timestamp = wp_next_scheduled( 'sscribe_cleanup_exports' );
if ( $sscribe_timestamp ) {
	wp_unschedule_event( $sscribe_timestamp, 'sscribe_cleanup_exports' );
}
