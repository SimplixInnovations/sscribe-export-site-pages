<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SScribe_Security {

	public static function protect_directory( string $dir ): void {
		self::validate_path_scope( $dir );

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$htaccess_path = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess_path ) ) {
			$content  = "Options -Indexes\n";
			$content .= "<Files \"*\">\n";
			$content .= "  <IfModule mod_authz_core.c>\n";
			$content .= "    Require all denied\n";
			$content .= "  </IfModule>\n";
			$content .= "  <IfModule !mod_authz_core.c>\n";
			$content .= "    Order Allow,Deny\n";
			$content .= "    Deny from all\n";
			$content .= "  </IfModule>\n";
			$content .= "</Files>\n";

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for directory security; path validated above.

			file_put_contents( $htaccess_path, $content );
		}

		$index_path = $dir . '/index.php';
		if ( ! file_exists( $index_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for directory security; path validated above.

			file_put_contents( $index_path, "<?php\n// Silence is golden.\n" );
		}
	}

	public static function delete_directory( string $dir, int $max_depth = 20, int $depth = 0 ): bool {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		if ( $depth >= $max_depth ) {
			return false;
		}

		if ( ! self::is_path_in_scope( $dir ) ) {
			return false;
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;

			if ( is_link( $path ) ) {
				wp_delete_file( $path );
			} elseif ( is_dir( $path ) ) {
				self::delete_directory( $path, $max_depth, $depth + 1 );
			} else {
				wp_delete_file( $path );
			}
		}

		return rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Required for recursive directory deletion; path validated above.
	}

	private static function validate_path_scope( string $path ): void {
		if ( ! self::is_path_in_scope( $path ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					'Directory "%s" is outside the allowed uploads scope.',
					esc_html( basename( $path ) )
				)
			);
		}
	}

	private static function is_path_in_scope( string $path ): bool {

		if ( str_contains( $path, '..' ) ) {
			return false;
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] );

		return str_starts_with( str_replace( '\\', '/', $path ), str_replace( '\\', '/', $base_dir ) );
	}
}
