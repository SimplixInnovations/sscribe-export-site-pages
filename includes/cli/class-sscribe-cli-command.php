<?php
/**
 * SScribe WP-CLI Command
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
 * Export pages and list finished exports from the command line.
 */
final class SScribe_CLI_Command {

	private const DEFAULT_FORMATS = 'docx,pdf,html,markdown';
	private const LIST_FORMATS    = array( 'table', 'json', 'csv' );
	private const LIST_FIELDS     = array( 'filename', 'created', 'size', 'pages', 'errors', 'owner' );
	private const ZIP_NAME        = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}\.zip$/D';

	/**
	 * Export pages to a ZIP archive.
	 *
	 * ## OPTIONS
	 *
	 * [--formats=<list>]
	 * : Comma separated formats: docx, pdf, html, markdown.
	 * ---
	 * default: docx,pdf,html,markdown
	 * ---
	 *
	 * [--post-type=<type>]
	 * : Post type to export, or "any".
	 * ---
	 * default: page
	 * ---
	 *
	 * [--post-status=<status>]
	 * : One of publish, private, draft, pending, future or all.
	 * ---
	 * default: publish
	 * ---
	 *
	 * [--language=<code>]
	 * : Language code when a multilingual plugin is active. Leave out for every language.
	 *
	 * [--md-preset=<preset>]
	 * : Markdown front matter layout: sscribe, hugo, jekyll, astro, obsidian or none.
	 *
	 * [--modified-since=<date>]
	 * : Only export posts modified after this date or Unix timestamp, for example 2026-10-01 or "-7 days".
	 *
	 * [--output=<path>]
	 * : Copy the finished ZIP to this file, or into this directory.
	 *
	 * [--porcelain]
	 * : Print only the path of the finished ZIP.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sscribe export --user=admin
	 *     wp sscribe export --user=admin --formats=docx,markdown --post-status=all
	 *     wp sscribe export --user=admin --output=/tmp/ --porcelain
	 *
	 * @param array<int, string>    $args       Positional arguments, unused.
	 * @param array<string, string> $assoc_args Options.
	 * @return void
	 */
	public function export( array $args, array $assoc_args ): void {
		unset( $args );

		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 || ! current_user_can( SScribe_Capabilities::get_required() ) ) {
			self::fail( __( 'Run this command as a user who can export: wp sscribe export --user=<login>', 'sscribe-export-site-pages' ) );
		}

		$porcelain = ! empty( $assoc_args['porcelain'] );
		$job       = self::job_from_args( $assoc_args );

		$output_target = '';
		if ( isset( $assoc_args['output'] ) && '' !== trim( (string) $assoc_args['output'] ) ) {
			$output_target = trim( (string) $assoc_args['output'] );
			if ( ! self::output_is_writable( $output_target ) ) {
				self::fail( __( 'The --output location does not exist or is not writable.', 'sscribe-export-site-pages' ) );
			}
		}

		foreach ( $job->formats as $format ) {
			if ( ! SScribe_Exporter_Factory::is_supported( $format ) && ! $porcelain ) {
				self::warn(
					sprintf(
						/* translators: %s: Format name given on the command line. */
						__( 'Unknown format "%s" will be skipped.', 'sscribe-export-site-pages' ),
						$format
					)
				);
			}
		}

		$progress = $porcelain ? null : static function ( array $payload ): void {
			self::log_progress( $payload );
		};

		$processor = SScribe_Container::instance()->get( SScribe_Batch_Processor::class );
		if ( ! $processor instanceof SScribe_Export_Pipeline_Interface ) {
			self::fail( __( 'The export service is unavailable. (service_unavailable)', 'sscribe-export-site-pages' ) );
		}

		$runner  = new SScribe_Export_Runner( $processor );
		$outcome = $runner->run( $job, new SScribe_Headless_Export_Context( $user_id, $progress ) );

		if ( ! $outcome->is_success() ) {
			self::fail( self::describe_failure( $outcome ) );
		}

		$payload  = $outcome->payload();
		$zip_path = self::resolve_zip_path( (string) ( $payload['filename'] ?? '' ) );
		if ( '' === $zip_path ) {
			self::fail( __( 'The export finished but its ZIP could not be found. (zip_missing)', 'sscribe-export-site-pages' ) );
		}

		$final_path = $zip_path;
		if ( '' !== $output_target ) {
			$final_path = self::copy_to_output( $zip_path, $output_target );
			if ( '' === $final_path ) {
				self::fail( __( 'The ZIP could not be copied to the --output location. (output_copy_failed)', 'sscribe-export-site-pages' ) );
			}
		}

		if ( $porcelain ) {
			self::line( $final_path );
			return;
		}

		$errors = $payload['errors'] ?? array();
		self::line(
			sprintf(
				/* translators: %s: Absolute path of the ZIP archive. */
				__( 'ZIP: %s', 'sscribe-export-site-pages' ),
				$final_path
			)
		);
		self::line(
			sprintf(
				/* translators: %s: Human readable file size. */
				__( 'Size: %s', 'sscribe-export-site-pages' ),
				size_format( (int) ( $payload['file_size'] ?? 0 ) )
			)
		);
		self::line(
			sprintf(
				/* translators: %d: Number of pages exported. */
				__( 'Pages: %d', 'sscribe-export-site-pages' ),
				(int) ( $payload['pages'] ?? 0 )
			)
		);
		self::line(
			sprintf(
				/* translators: %d: Number of pages that failed to export. */
				__( 'Errors: %d', 'sscribe-export-site-pages' ),
				is_array( $errors ) ? count( $errors ) : 0
			)
		);
		self::succeed( __( 'Export complete.', 'sscribe-export-site-pages' ) );
	}

	/**
	 * List finished exports.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp sscribe list
	 *     wp sscribe list --format=json
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>    $args       Positional arguments, unused.
	 * @param array<string, string> $assoc_args Options.
	 * @return void
	 */
	public function list_exports( array $args, array $assoc_args ): void {
		unset( $args );

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		if ( ! in_array( $format, self::LIST_FORMATS, true ) ) {
			self::fail( __( 'Use --format=table, --format=json or --format=csv.', 'sscribe-export-site-pages' ) );
		}

		$zip_handler = SScribe_Container::instance()->get( SScribe_Zip_Handler::class );
		if ( ! $zip_handler instanceof SScribe_Zip_Handler ) {
			self::fail( __( 'The export service is unavailable. (service_unavailable)', 'sscribe-export-site-pages' ) );
		}

		$items = self::list_rows( $zip_handler->list_export_entries(), self::stats_by_session() );

		if ( class_exists( 'WP_CLI' ) && function_exists( 'WP_CLI\Utils\format_items' ) ) {
			\WP_CLI\Utils\format_items( $format, $items, self::LIST_FIELDS );
		}
	}

	/**
	 * Turn command line options into an export job.
	 *
	 * Formats are passed through as given, so unknown ones reach the export
	 * core and are handled there the same way as from the admin screen.
	 *
	 * @param array<string, mixed> $assoc_args Options from the command line.
	 * @return SScribe_Export_Job
	 */
	public static function job_from_args( array $assoc_args ): SScribe_Export_Job {
		$formats = $assoc_args['formats'] ?? self::DEFAULT_FORMATS;
		if ( ! is_string( $formats ) || '' === trim( $formats ) ) {
			$formats = self::DEFAULT_FORMATS;
		}

		return SScribe_Export_Job::from_array(
			array(
				'language'       => $assoc_args['language'] ?? '',
				'post_status'    => $assoc_args['post-status'] ?? 'publish',
				'post_type'      => $assoc_args['post-type'] ?? 'page',
				'formats'        => $formats,
				'modified_since' => $assoc_args['modified-since'] ?? 0,
				'format_options' => isset( $assoc_args['md-preset'] )
					? array( 'sscribe_md_frontmatter_preset' => SScribe_Markdown_Front_Matter::normalize_preset( $assoc_args['md-preset'] ) )
					: array(),
			)
		);
	}

	/**
	 * Build table rows for the list subcommand, newest first.
	 *
	 * @param array<string, array<string, mixed>> $entries Export rows keyed by ZIP filename.
	 * @param array<string, array<string, int>>   $stats   Page and error counts keyed by session id.
	 * @return list<array<string, string|int>>
	 */
	public static function list_rows( array $entries, array $stats ): array {
		$export_dir = SScribe_Private_Storage::get_export_dir( false );

		uasort(
			$entries,
			static fn( array $a, array $b ): int => (int) ( $b['created_at'] ?? 0 ) <=> (int) ( $a['created_at'] ?? 0 )
		);

		$rows = array();
		foreach ( $entries as $filename => $entry ) {
			$filename = (string) $filename;
			if ( 1 !== preg_match( self::ZIP_NAME, $filename ) ) {
				continue;
			}

			$session_id = sanitize_key( (string) ( $entry['session_id'] ?? '' ) );
			$counts     = $stats[ $session_id ] ?? array();
			$owner_id   = (int) ( $entry['user_id'] ?? 0 );
			$created    = (int) ( $entry['created_at'] ?? 0 );

			$rows[] = array(
				'filename' => $filename,
				'created'  => $created > 0 ? (string) wp_date( 'Y-m-d H:i:s', $created ) : '',
				'size'     => self::file_size_label( $export_dir, $filename ),
				'pages'    => (int) ( $counts['pages'] ?? 0 ),
				'errors'   => (int) ( $counts['errors'] ?? 0 ),
				'owner'    => self::owner_label( $owner_id ),
			);
		}

		return $rows;
	}

	/**
	 * Page and error counts from the export statistics, keyed by session id.
	 *
	 * @return array<string, array<string, int>>
	 */
	private static function stats_by_session(): array {
		$stats = array();
		foreach ( ( new SScribe_Export_Stats() )->get_recent_exports( 100 ) as $row ) {
			$row        = (array) $row;
			$session_id = sanitize_key( (string) ( $row['export_session_id'] ?? '' ) );
			if ( '' === $session_id ) {
				continue;
			}
			$stats[ $session_id ] = array(
				'pages'  => (int) ( $row['total_pages'] ?? 0 ),
				'errors' => (int) ( $row['failed_pages'] ?? 0 ),
			);
		}

		return $stats;
	}

	/**
	 * Human readable size of an export, or an empty string when the file is gone.
	 *
	 * @param string $export_dir Export directory.
	 * @param string $filename   ZIP filename.
	 * @return string
	 */
	private static function file_size_label( string $export_dir, string $filename ): string {
		if ( '' === $export_dir ) {
			return '';
		}

		$path = trailingslashit( $export_dir ) . $filename;
		if ( is_link( $path ) || ! is_file( $path ) ) {
			return '';
		}

		$size = filesize( $path );

		return false === $size ? '' : (string) size_format( $size );
	}

	/**
	 * Login of the user who owns an export.
	 *
	 * @param int $user_id Owner user id.
	 * @return string
	 */
	private static function owner_label( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$user = get_userdata( $user_id );

		return $user instanceof WP_User ? (string) $user->user_login : (string) $user_id;
	}

	/**
	 * Absolute path of a finished ZIP inside the export directory.
	 *
	 * Mirrors the checks the download handler makes before serving a file.
	 *
	 * @param string $filename ZIP filename from the finalize payload.
	 * @return string Absolute path, or an empty string when it is not a safe export file.
	 */
	private static function resolve_zip_path( string $filename ): string {
		if ( 1 !== preg_match( self::ZIP_NAME, $filename ) ) {
			return '';
		}

		try {
			$export_dir = ( new SScribe_Zip_Handler() )->get_export_dir();
		} catch ( \InvalidArgumentException $e ) {
			return '';
		}

		$real_dir  = realpath( $export_dir );
		$real_path = realpath( $export_dir . '/' . $filename );
		if ( false === $real_dir || false === $real_path || ! is_file( $real_path ) ) {
			return '';
		}

		$safe_dir = rtrim( $real_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;

		return str_starts_with( $real_path, $safe_dir ) ? $real_path : '';
	}

	/**
	 * Whether --output names a writable directory, or a file in one.
	 *
	 * @param string $target Path given on the command line.
	 * @return bool
	 */
	private static function output_is_writable( string $target ): bool {
		if ( is_dir( $target ) ) {
			return wp_is_writable( $target );
		}

		if ( file_exists( $target ) && ! wp_is_writable( $target ) ) {
			return false;
		}

		$parent = dirname( $target );

		return is_dir( $parent ) && wp_is_writable( $parent );
	}

	/**
	 * Copy the ZIP to the --output location.
	 *
	 * @param string $zip_path Absolute path of the finished ZIP.
	 * @param string $target   Directory or file path given on the command line.
	 * @return string Absolute path of the copy, or an empty string on failure.
	 */
	private static function copy_to_output( string $zip_path, string $target ): string {
		$destination = is_dir( $target ) ? trailingslashit( $target ) . basename( $zip_path ) : $target;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- The destination is chosen by the operator and lies outside the plugin's private storage.
		if ( ! copy( $zip_path, $destination ) ) {
			return '';
		}

		$real = realpath( $destination );

		return false === $real ? $destination : $real;
	}

	/**
	 * Explain a failed outcome, including its code.
	 *
	 * @param SScribe_Export_Outcome $outcome Failed outcome.
	 * @return string
	 */
	private static function describe_failure( SScribe_Export_Outcome $outcome ): string {
		$payload = $outcome->payload();
		$message = isset( $payload['message'] ) && is_string( $payload['message'] ) && '' !== $payload['message']
			? $payload['message']
			: __( 'The export failed.', 'sscribe-export-site-pages' );
		$code    = '' !== $outcome->code() ? $outcome->code() : $outcome->kind();

		return sprintf( '%1$s (%2$s)', $message, $code );
	}

	/**
	 * Print one progress line for a step payload.
	 *
	 * @param array<string|int, mixed> $payload Step payload.
	 * @return void
	 */
	private static function log_progress( array $payload ): void {
		if ( isset( $payload['processed'], $payload['total'] ) ) {
			self::line(
				sprintf(
					/* translators: 1: Pages processed so far, 2: Total pages, 3: Percentage complete. */
					__( 'Processed %1$d of %2$d pages (%3$d%%)', 'sscribe-export-site-pages' ),
					(int) $payload['processed'],
					(int) $payload['total'],
					(int) ( $payload['percentage'] ?? 0 )
				)
			);
			return;
		}

		if ( isset( $payload['message'] ) && is_string( $payload['message'] ) ) {
			self::line( $payload['message'] );
		}
	}

	/**
	 * Print a line.
	 *
	 * @param string $message Text to print.
	 * @return void
	 */
	private static function line( string $message ): void {
		if ( class_exists( 'WP_CLI' ) ) {
			\WP_CLI::log( $message );
		}
	}

	/**
	 * Print a warning.
	 *
	 * @param string $message Text to print.
	 * @return void
	 */
	private static function warn( string $message ): void {
		if ( class_exists( 'WP_CLI' ) ) {
			\WP_CLI::warning( $message );
		}
	}

	/**
	 * Print a success message.
	 *
	 * @param string $message Text to print.
	 * @return void
	 */
	private static function succeed( string $message ): void {
		if ( class_exists( 'WP_CLI' ) ) {
			\WP_CLI::success( $message );
		}
	}

	/**
	 * Print an error and stop with a non-zero exit code.
	 *
	 * @param string $message Text to print.
	 * @return never
	 * @throws RuntimeException When WP-CLI is not loaded.
	 */
	private static function fail( string $message ): never {
		if ( class_exists( 'WP_CLI' ) ) {
			\WP_CLI::error( $message );
		}

		throw new RuntimeException( esc_html( $message ) );
	}
}
