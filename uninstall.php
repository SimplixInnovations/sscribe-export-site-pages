<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package SScribe
 */

// If uninstall not called from WordPress, abort.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Recursively delete export directory and all files/folders inside.
$upload_dir = wp_upload_dir();
$export_path = $upload_dir['basedir'] . '/sscribe-exports';

if (is_dir($export_path)) {
    $objects = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($export_path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($objects as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        @$todo($fileinfo->getRealPath());
    }
    @rmdir($export_path);
}

// Remove plugin options.
delete_option('sscribe_version');

// Clear scheduled cron events.
$timestamp = wp_next_scheduled('sscribe_cleanup_exports');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'sscribe_cleanup_exports');
}
