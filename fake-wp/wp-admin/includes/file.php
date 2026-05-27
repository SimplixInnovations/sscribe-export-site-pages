<?php
/**
 * Minimal WordPress filesystem stub for testing.
 *
 * @package SScribe_Export_Site_Pages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'FS_CHMOD_FILE' ) ) {
	define( 'FS_CHMOD_FILE', 0644 );
}
if ( ! defined( 'FS_CHMOD_DIR' ) ) {
	define( 'FS_CHMOD_DIR', 0755 );
}

/**
 * Initialize the WordPress filesystem.
 *
 * @param mixed $args Optional arguments.
 * @return bool True on success.
 */
function WP_Filesystem( $args = false ) {
	global $wp_filesystem;

	if ( ! empty( $wp_filesystem ) ) {
		return true;
	}

	// Try to connect directly.
	$wp_filesystem = new WP_Filesystem_Base();
	return true;
}

/**
 * Request filesystem credentials.
 *
 * @param string $form_url  The URL to redirect to.
 * @param string $type      Type of filesystem.
 * @param bool   $error     Whether to show error.
 * @param bool   $context   Context path.
 * @param array  $fields    Required fields.
 * @return bool True on success.
 */
function request_filesystem_credentials( $form_url = '', $type = '', $error = false, $context = false, $fields = null ) {
	return false;
}

/**
 * Base filesystem class.
 */
class WP_Filesystem_Base {
	/**
	 * Write file contents.
	 *
	 * @param string $file    File path.
	 * @param string $content Content to write.
	 * @param int    $mode    File mode.
	 * @return bool True on success.
	 */
	public function put_contents( $file, $content, $mode = false ) {
		return false !== file_put_contents( $file, $content, LOCK_EX );
	}

	/**
	 * Remove directory.
	 *
	 * @param string $dir  Directory path.
	 * @param bool   $recursive Whether to recurse.
	 * @return bool True on success.
	 */
	public function rmdir( $dir, $recursive = false ) {
		if ( $recursive && is_dir( $dir ) ) {
			$files = array_diff( scandir( $dir ), array( '.', '..' ) );
			foreach ( $files as $file ) {
				$path = $dir . '/' . $file;
				is_dir( $path ) ? $this->rmdir( $path, true ) : unlink( $path );
			}
		}
		return @rmdir( $dir );
	}

	/**
	 * Create directory.
	 *
	 * @param string $dir  Directory path.
	 * @param int    $mode File mode.
	 * @param bool   $recursive Whether to recurse.
	 * @return bool True on success.
	 */
	public function mkdir( $dir, $mode = false, $recursive = false ) {
		if ( $recursive ) {
			return wp_mkdir_p( $dir );
		}
		return @mkdir( $dir, $mode );
	}

	/**
	 * Check if file exists.
	 *
	 * @param string $file File path.
	 * @return bool True if exists.
	 */
	public function exists( $file ) {
		return file_exists( $file );
	}

	/**
	 * Get file contents.
	 *
	 * @param string $file File path.
	 * @return string|false Contents or false on failure.
	 */
	public function get_contents( $file ) {
		return file_get_contents( $file );
	}

	/**
	 * Get file contents as array.
	 *
	 * @param string $file File path.
	 * @return array|false Lines or false on failure.
	 */
	public function get_contents_array( $file ) {
		return file( $file );
	}
}
