<?php
/**
 * SScribe storage migration from earlier private-storage locations.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moves archives and logs written by 2.0.0 into the current storage folder.
 *
 * Release 2.0.0 kept files in a per-site `site-{blog}-{hash}` folder below a
 * temporary or account directory, or below uploads on restricted hosts. This
 * class finds those folders once, moves their contents into the current
 * storage folder, removes the old tree, and records completion so the work
 * never repeats.
 */
final class SScribe_Storage_Migration {

	public const COMPLETED_OPTION = 'sscribe_storage_migrated_v2';

	/**
	 * Whether the one-time move has already been recorded.
	 *
	 * @return bool True when the migration finished on an earlier request.
	 */
	public static function is_complete(): bool {
		return (bool) get_option( self::COMPLETED_OPTION, false );
	}

	/**
	 * Move previous storage into the current storage folder once.
	 *
	 * Unreadable or vanished locations are logged and skipped. Completion is
	 * recorded as soon as the current folder exists, so a location that can
	 * never be read does not cause the scan to repeat on every request.
	 *
	 * @return bool True when every discovered location moved cleanly.
	 */
	public static function run(): bool {
		if ( self::is_complete() ) {
			return true;
		}

		$target = SScribe_Private_Storage::get_export_dir();
		if ( '' === $target ) {
			return false;
		}

		$moved_all = true;
		foreach ( self::find_previous_site_dirs( $target ) as $site_dir ) {
			$moved_all = self::migrate_site_dir( $site_dir, $target ) && $moved_all;
		}

		update_option( self::COMPLETED_OPTION, gmdate( 'Y-m-d H:i:s' ), false );

		return $moved_all;
	}

	/**
	 * Move one previous site folder and remove it when the move succeeded.
	 *
	 * @param string $site_dir Previous `site-*` folder.
	 * @param string $target   Current storage folder.
	 * @return bool True when the folder was moved and removed.
	 */
	private static function migrate_site_dir( string $site_dir, string $target ): bool {
		$leaf = $site_dir . DIRECTORY_SEPARATOR . SScribe_Private_Storage::get_directory_name();
		if ( is_dir( $leaf ) && ! is_link( $leaf ) && ! SScribe_Private_Storage::move_into_storage( $leaf, $target ) ) {
			self::log( 'Previous private storage could not be moved completely; it was left in place', $site_dir );
			return false;
		}

		$container = dirname( $site_dir );
		if ( ! SScribe_Private_Storage::remove_previous_tree( $site_dir ) ) {
			self::log( 'Previous private storage was moved but could not be removed', $site_dir );
			return false;
		}
		if ( ! self::is_current_container( $container, $target ) ) {
			SScribe_Private_Storage::remove_guarded_directory( $container );
		}

		return true;
	}

	/**
	 * Find previous per-site folders that belong to this site.
	 *
	 * Folders below the uploads directory are always this installation's.
	 * Folders below shared temporary or account directories are only taken
	 * when they hold an archive listed in this site's export index, so files
	 * of another installation on the same server are never touched.
	 *
	 * @param string $target Current storage folder.
	 * @return string[] Previous site folders.
	 */
	private static function find_previous_site_dirs( string $target ): array {
		$pattern  = '/^site-' . get_current_blog_id() . '-[0-9a-f]{12}$/D';
		$uploads  = SScribe_Private_Storage::get_uploads_basedir();
		$archives = self::get_indexed_archives();
		$found    = array();

		foreach ( self::get_previous_bases() as $base ) {
			$container = $base . DIRECTORY_SEPARATOR . SScribe_Private_Storage::DIRECTORY_NAME;
			if ( ! is_dir( $container ) || is_link( $container ) ) {
				continue;
			}
			$entries = @scandir( $container ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A previous location may have become unreadable; that is logged below.
			if ( false === $entries ) {
				self::log( 'Previous private storage location could not be read', $container );
				continue;
			}
			$trusted = '' !== $uploads && self::same_path( $base, $uploads );
			foreach ( $entries as $entry ) {
				$site_dir = $container . DIRECTORY_SEPARATOR . $entry;
				if (
					1 !== preg_match( $pattern, $entry )
					|| ! is_dir( $site_dir )
					|| is_link( $site_dir )
					|| self::same_path( $site_dir, dirname( $target ) )
				) {
					continue;
				}
				if ( ! $trusted && ! self::holds_indexed_archive( $site_dir, $archives ) ) {
					continue;
				}
				$real = realpath( $site_dir );
				if ( false !== $real ) {
					$found[ self::normalize( $real ) ] = $site_dir;
				}
			}
		}

		return array_values( $found );
	}

	/**
	 * Return the base directories an earlier install may have used: the
	 * operator-configured overrides and the uploads directory.
	 *
	 * @return string[]
	 */
	private static function get_previous_bases(): array {
		$candidates   = SScribe_Private_Storage::get_override_bases();
		$candidates[] = SScribe_Private_Storage::get_uploads_basedir();

		$bases = array();
		foreach ( $candidates as $candidate ) {
			$candidate = rtrim( trim( $candidate ), '/\\' );
			if ( '' === $candidate || str_contains( $candidate, "\0" ) || ! is_dir( $candidate ) ) {
				continue;
			}
			$real = realpath( $candidate );
			if ( false !== $real ) {
				$bases[ self::normalize( $real ) ] = rtrim( $real, '/\\' );
			}
		}

		return array_values( $bases );
	}

	/**
	 * Return archive names listed in this site's export index.
	 *
	 * @return string[]
	 */
	private static function get_indexed_archives(): array {
		$names = array();
		foreach ( (array) get_option( 'sscribe_export_index', array() ) as $name ) {
			if ( is_string( $name ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}\.zip$/D', $name ) ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * Whether a previous site folder holds one of this site's archives.
	 *
	 * @param string   $site_dir Previous site folder.
	 * @param string[] $archives Archive names from the export index.
	 * @return bool True when at least one archive is present.
	 */
	private static function holds_indexed_archive( string $site_dir, array $archives ): bool {
		$leaf = $site_dir . DIRECTORY_SEPARATOR . SScribe_Private_Storage::get_directory_name();
		foreach ( $archives as $archive ) {
			$path = $leaf . DIRECTORY_SEPARATOR . $archive;
			if ( is_file( $path ) && ! is_link( $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a container also holds the current storage folder.
	 *
	 * @param string $container Previous container folder.
	 * @param string $target    Current storage folder.
	 * @return bool True when the container is shared with current storage.
	 */
	private static function is_current_container( string $container, string $target ): bool {
		return self::same_path( $container, dirname( dirname( $target ) ) );
	}

	/**
	 * Compare two existing paths after resolving links.
	 *
	 * @param string $first  First path.
	 * @param string $second Second path.
	 * @return bool True when both resolve to the same location.
	 */
	private static function same_path( string $first, string $second ): bool {
		$first_real  = realpath( $first );
		$second_real = realpath( $second );

		return false !== $first_real && false !== $second_real && self::normalize( $first_real ) === self::normalize( $second_real );
	}

	/**
	 * Normalize a path for comparison.
	 *
	 * @param string $path Path.
	 * @return string Normalized path.
	 */
	private static function normalize( string $path ): string {
		$path = rtrim( str_replace( '\\', '/', $path ), '/' );

		return 'Windows' === PHP_OS_FAMILY ? strtolower( $path ) : $path;
	}

	/**
	 * Record a migration problem without exposing the full path.
	 *
	 * @param string $message Log message.
	 * @param string $path    Location involved.
	 */
	private static function log( string $message, string $path ): void {
		SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() )->warning(
			$message,
			array( 'location' => basename( $path ) )
		);
	}
}
