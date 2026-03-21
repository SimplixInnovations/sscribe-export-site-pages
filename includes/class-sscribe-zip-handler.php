<?php
/**
 * Handles ZIP packaging and file cleanup.
 *
 * @package SScribe
 */

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class SScribe_Zip_Handler
 *
 * Bundles DOCX files into ZIP packages and manages automatic cleanup.
 */
class SScribe_Zip_Handler
{

    /**
     * Export directory path.
     *
     * @var string
     */
    private $export_dir;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $upload_dir = wp_upload_dir();
        $this->export_dir = $upload_dir['basedir'] . '/sscribe-exports';
    }

    /**
     * Get the export directory path.
     *
     * @return string
     */
    public function get_export_dir()
    {
        if (!file_exists($this->export_dir)) {
            wp_mkdir_p($this->export_dir);
        }
        return $this->export_dir;
    }

    /**
     * Create a temporary directory for DOCX files.
     *
     * @return string Path to temporary directory.
     */
    public function create_temp_dir()
    {
        $temp_dir = $this->export_dir . '/temp-' . wp_generate_password(12, false);
        wp_mkdir_p($temp_dir);
        return $temp_dir;
    }

    /**
     * Bundle DOCX files from a directory into a ZIP.
     *
     * @param string $source_dir Directory containing DOCX files.
     * @param string $zip_name   Desired ZIP filename (without extension).
     * @return string|false Path to ZIP file or false on failure.
     */
    public function create_zip($source_dir, $zip_name = '')
    {
        if (!class_exists('ZipArchive')) {
            return false;
        }

        if (empty($zip_name)) {
            $zip_name = 'sscribe-export-' . gmdate('Y-m-d-His') . '-' . wp_generate_password(6, false);
        }

        $zip_path = $this->export_dir . '/' . sanitize_file_name($zip_name) . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $files = glob($source_dir . '/*.docx');
        if (empty($files)) {
            $zip->close();
            return false;
        }

        foreach ($files as $file) {
            $zip->addFile($file, basename($file));
        }

        $zip->close();

        // Clean up temporary directory.
        $this->delete_directory($source_dir);

        // Store creation time for cleanup.
        update_option('sscribe_last_export_time_' . md5($zip_path), time());

        return file_exists($zip_path) ? $zip_path : false;
    }

    /**
     * Get the download URL for a ZIP file.
     *
     * @param string $zip_path Full path to ZIP file.
     * @return string Download URL.
     */
    public function get_download_url($zip_path)
    {
        $upload_dir = wp_upload_dir();
        $relative = str_replace($upload_dir['basedir'], '', $zip_path);
        return $upload_dir['baseurl'] . $relative;
    }

    /**
     * Get the admin-ajax download URL.
     *
     * @param string $zip_filename The ZIP filename.
     * @return string AJAX download URL.
     */
    public function get_ajax_download_url($zip_filename)
    {
        return add_query_arg(
            array(
                'action' => 'sscribe_download',
                'file' => sanitize_file_name($zip_filename),
                'nonce' => wp_create_nonce('sscribe_download'),
            ),
            admin_url('admin-ajax.php')
        );
    }

    /**
     * Cleanup expired export files (older than 1 hour).
     *
     * @return int Number of files cleaned up.
     */
    public function cleanup_expired()
    {
        $cleaned = 0;
        $files = glob($this->export_dir . '/*.zip');

        if (empty($files)) {
            return $cleaned;
        }

        $max_age = HOUR_IN_SECONDS;

        foreach ($files as $file) {
            $file_time = filemtime($file);
            if ($file_time && (time() - $file_time) > $max_age) {
                wp_delete_file($file);
                // Remove associated option.
                delete_option('sscribe_last_export_time_' . md5($file));
                $cleaned++;
            }
        }

        // Also clean up any stale temp directories.
        $temp_dirs = glob($this->export_dir . '/temp-*', GLOB_ONLYDIR);
        if ($temp_dirs) {
            foreach ($temp_dirs as $temp_dir) {
                $dir_time = filemtime($temp_dir);
                if ($dir_time && (time() - $dir_time) > $max_age) {
                    $this->delete_directory($temp_dir);
                    $cleaned++;
                }
            }
        }

        return $cleaned;
    }

    /**
     * Recursively delete a directory and its contents.
     *
     * @param string $dir Directory path.
     * @return bool
     */
    public function delete_directory($dir)
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->delete_directory($path);
            } else {
                wp_delete_file($path);
            }
        }

        return rmdir($dir);
    }
}
