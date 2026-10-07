<?php
/**
 * SScribe Directory Destination
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
 * Copies finished archives into a directory on the server.
 *
 * The directory must already exist, be writable, and lie outside the
 * plugin and outside its private storage. The copy is written under a
 * temporary name and renamed into place, so nothing ever sees half a file.
 */
final class SScribe_Destination_Directory implements SScribe_Destination_Interface {

	private const ZIP_NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}\.zip$/D';

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'directory';
	}

	/**
	 * Name shown to people.
	 *
	 * @return string
	 */
	public static function label(): string {
		return __( 'Server directory', 'sscribe-export-site-pages' );
	}

	/**
	 * Settings the destination takes.
	 *
	 * @return array<string, array{type: string, label: string, required: bool}>
	 */
	public static function settings_schema(): array {
		return array(
			'path'      => array(
				'type'     => 'text',
				'label'    => __( 'Absolute directory path', 'sscribe-export-site-pages' ),
				'required' => true,
			),
			'overwrite' => array(
				'type'     => 'checkbox',
				'label'    => __( 'Replace a file with the same name', 'sscribe-export-site-pages' ),
				'required' => false,
			),
		);
	}

	/**
	 * Check the directory.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return SScribe_Result Settings with the resolved directory, or why it cannot be used.
	 */
	public function validate( array $settings ): SScribe_Result {
		$path = is_string( $settings['path'] ?? null ) ? trim( $settings['path'] ) : '';
		if ( '' === $path ) {
			return SScribe_Result::failure( __( 'Give the directory path.', 'sscribe-export-site-pages' ) );
		}
		if ( str_contains( $path, "\0" ) || ! self::is_absolute( $path ) ) {
			return SScribe_Result::failure( __( 'The directory path must be absolute.', 'sscribe-export-site-pages' ) );
		}

		$trimmed = '' !== rtrim( $path, '/\\' ) ? rtrim( $path, '/\\' ) : $path;
		if ( is_link( $trimmed ) ) {
			return SScribe_Result::failure( __( 'The directory must not be a symbolic link.', 'sscribe-export-site-pages' ) );
		}

		$real = realpath( $trimmed );
		if ( false === $real || ! is_dir( $real ) ) {
			return SScribe_Result::failure( __( 'The directory does not exist.', 'sscribe-export-site-pages' ) );
		}
		if ( ! wp_is_writable( $real ) ) {
			return SScribe_Result::failure( __( 'The directory is not writable.', 'sscribe-export-site-pages' ) );
		}
		if ( self::is_within( $real, SSCRIBE_PLUGIN_DIR ) ) {
			return SScribe_Result::failure( __( 'The directory must be outside the plugin folder.', 'sscribe-export-site-pages' ) );
		}
		if ( self::is_within( $real, self::private_root() ) ) {
			return SScribe_Result::failure( __( 'The directory must be outside the plugin\'s private storage.', 'sscribe-export-site-pages' ) );
		}

		$overwrite = $settings['overwrite'] ?? false;

		return SScribe_Result::success(
			array(
				'path'      => $real,
				'overwrite' => is_bool( $overwrite ) ? $overwrite : true === filter_var( $overwrite, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ),
			)
		);
	}

	/**
	 * Copy the archive into the directory.
	 *
	 * @param string               $zip_path Absolute path of the archive.
	 * @param array<string, mixed> $context  Export context.
	 * @param array<string, mixed> $settings Settings.
	 * @return SScribe_Result Final path of the copy, or why it failed.
	 */
	public function deliver( string $zip_path, array $context, array $settings ): SScribe_Result {
		$checked = $this->validate( $settings );
		if ( $checked->is_failure() ) {
			return $checked;
		}
		if ( is_link( $zip_path ) || ! is_file( $zip_path ) || ! is_readable( $zip_path ) ) {
			return SScribe_Result::failure( __( 'The archive to deliver is missing.', 'sscribe-export-site-pages' ) );
		}

		$valid     = (array) $checked->get_data();
		$filename  = self::filename( $zip_path, $context );
		$target    = rtrim( (string) $valid['path'], '/\\' ) . DIRECTORY_SEPARATOR . $filename;
		$overwrite = true === $valid['overwrite'];

		if ( is_link( $target ) || ( file_exists( $target ) && ( ! $overwrite || ! is_file( $target ) ) ) ) {
			return SScribe_Result::failure(
				sprintf(
					/* translators: %s: ZIP filename. */
					__( 'A file named %s already exists in the directory.', 'sscribe-export-site-pages' ),
					$filename
				)
			);
		}

		return self::copy_atomically( $zip_path, $target );
	}

	/**
	 * Copy under a temporary name, then rename into place.
	 *
	 * @param string $source Archive path.
	 * @param string $target Final path.
	 * @return SScribe_Result
	 */
	private static function copy_atomically( string $source, string $target ): SScribe_Result {
		$temporary = dirname( $target ) . DIRECTORY_SEPARATOR . '.sscribe-' . bin2hex( random_bytes( 8 ) ) . '.part';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- The operator chose this directory; it lies outside the plugin's managed storage.
		if ( ! copy( $source, $temporary ) || filesize( $temporary ) !== filesize( $source ) ) {
			wp_delete_file( $temporary );
			return SScribe_Result::failure( __( 'The archive could not be copied into the directory.', 'sscribe-export-site-pages' ) );
		}

		if ( ! rename( $temporary, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic publish of the finished copy.
			wp_delete_file( $temporary );
			return SScribe_Result::failure( __( 'The archive could not be moved into place.', 'sscribe-export-site-pages' ) );
		}

		return SScribe_Result::success( $target );
	}

	/**
	 * Name of the copy: the export filename, or the archive's own name.
	 *
	 * @param string               $zip_path Archive path.
	 * @param array<string, mixed> $context  Export context.
	 * @return string
	 */
	private static function filename( string $zip_path, array $context ): string {
		$name = is_string( $context['filename'] ?? null ) ? $context['filename'] : '';

		return 1 === preg_match( self::ZIP_NAME, $name ) ? $name : 'sscribe-export-' . gmdate( 'Ymd-His' ) . '.zip';
	}

	/**
	 * Whether a path is absolute on Unix or Windows.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function is_absolute( string $path ): bool {
		return str_starts_with( $path, '/' )
			|| str_starts_with( $path, '\\\\' )
			|| 1 === preg_match( '#^[A-Za-z]:[\\\\/]#', $path );
	}

	/**
	 * The folder holding every directory the plugin manages privately.
	 *
	 * @return string Empty when private storage is unavailable.
	 */
	private static function private_root(): string {
		$export_dir = SScribe_Private_Storage::get_export_dir( false );

		return '' === $export_dir ? '' : dirname( $export_dir, 2 );
	}

	/**
	 * Whether a resolved path equals or lies under a root.
	 *
	 * @param string $real Resolved path.
	 * @param string $root Root path.
	 * @return bool
	 */
	private static function is_within( string $real, string $root ): bool {
		if ( '' === $root ) {
			return false;
		}
		$real_root = realpath( $root );
		if ( false === $real_root ) {
			return false;
		}

		$path = self::comparable( $real );
		$base = self::comparable( $real_root );

		return $path === $base || str_starts_with( $path, $base . '/' );
	}

	/**
	 * A path with forward slashes, without a trailing slash, case-folded on Windows.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function comparable( string $path ): string {
		$path = rtrim( str_replace( '\\', '/', $path ), '/' );

		return '\\' === DIRECTORY_SEPARATOR ? strtolower( $path ) : $path;
	}
}
