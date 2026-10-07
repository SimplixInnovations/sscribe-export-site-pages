<?php
/**
 * SScribe Destination Dispatcher
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
 * Sends one finished archive to every configured destination.
 *
 * Each delivery stands alone: a failing or crashing destination is
 * recorded and logged, and the next one still runs.
 */
final class SScribe_Destination_Dispatcher {

	private const MAX_MANIFEST_BYTES = 8388608;
	private const ZIP_NAME           = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}\.zip$/D';

	/**
	 * Finds a destination by id.
	 *
	 * @var callable(string): ?SScribe_Destination_Interface
	 */
	private $resolver;

	/**
	 * Set up the dispatcher.
	 *
	 * @param callable|null $resolver Called with an id; the registry is asked when null.
	 */
	public function __construct( ?callable $resolver = null ) {
		$this->resolver = $resolver ?? array( SScribe_Destination_Registry::class, 'get' );
	}

	/**
	 * Deliver an archive everywhere it should go.
	 *
	 * @param string               $zip_path     Absolute path of the archive.
	 * @param array<string, mixed> $context      filename, size, pages, errors, session_id, schedule_id, manifest.
	 * @param array<int, mixed>    $destinations List of array( 'id' => ..., 'settings' => ... ); sealed secrets are opened here.
	 * @return list<array{id: string, ok: bool, message: string}>
	 */
	public function deliver_all( string $zip_path, array $context, array $destinations ): array {
		$results = array();
		foreach ( $destinations as $entry ) {
			$results[] = $this->deliver_one( $zip_path, $context, is_array( $entry ) ? $entry : array() );
		}

		do_action( 'sscribe_export_delivered', $zip_path, $context, $results );

		return $results;
	}

	/**
	 * Context for destinations from a finished export payload.
	 *
	 * @param string                   $zip_path    Absolute path of the archive.
	 * @param array<string|int, mixed> $payload     Finished export payload.
	 * @param string                   $schedule_id Schedule that ran, empty for manual exports.
	 * @return array<string, mixed>
	 */
	public static function context_from_payload( string $zip_path, array $payload, string $schedule_id = '' ): array {
		$errors = $payload['errors'] ?? array();
		$size   = is_file( $zip_path ) ? filesize( $zip_path ) : false;

		return array(
			'filename'    => basename( $zip_path ),
			'size'        => false === $size ? (int) ( $payload['file_size'] ?? 0 ) : $size,
			'pages'       => (int) ( $payload['pages'] ?? 0 ),
			'errors'      => is_array( $errors ) ? count( $errors ) : (int) $errors,
			'session_id'  => sanitize_key( (string) ( $payload['session_id'] ?? '' ) ),
			'schedule_id' => sanitize_key( $schedule_id ),
			'manifest'    => self::read_manifest( $zip_path ),
		);
	}

	/**
	 * Absolute path of a finished archive in a directory.
	 *
	 * @param string $directory Export directory.
	 * @param string $filename  ZIP filename.
	 * @return string Empty when the name is unsafe or the file is not inside the directory.
	 */
	public static function archive_path( string $directory, string $filename ): string {
		if ( '' === $directory || 1 !== preg_match( self::ZIP_NAME, $filename ) ) {
			return '';
		}
		$real_dir  = realpath( $directory );
		$real_path = realpath( rtrim( $directory, '/\\' ) . DIRECTORY_SEPARATOR . $filename );
		if ( false === $real_dir || false === $real_path || ! is_file( $real_path ) ) {
			return '';
		}

		return str_starts_with( $real_path, rtrim( $real_dir, '/\\' ) . DIRECTORY_SEPARATOR ) ? $real_path : '';
	}

	/**
	 * One-line summary of delivery results.
	 *
	 * @param array<int, array{id: string, ok: bool, message: string}> $results Delivery results.
	 * @return string
	 */
	public static function summarize( array $results ): string {
		$parts = array();
		foreach ( $results as $result ) {
			$parts[] = $result['ok']
				/* translators: %s: Destination id. */
				? sprintf( __( '%s: delivered', 'sscribe-export-site-pages' ), $result['id'] )
				/* translators: 1: Destination id, 2: Error message. */
				: sprintf( __( '%1$s: failed (%2$s)', 'sscribe-export-site-pages' ), $result['id'], $result['message'] );
		}

		return implode( '; ', $parts );
	}

	/**
	 * Deliver to one destination, containing every failure.
	 *
	 * @param string               $zip_path Absolute path of the archive.
	 * @param array<string, mixed> $context  Export context.
	 * @param array<string, mixed> $entry    Destination entry.
	 * @return array{id: string, ok: bool, message: string}
	 */
	private function deliver_one( string $zip_path, array $context, array $entry ): array {
		$id = sanitize_key( is_string( $entry['id'] ?? null ) ? $entry['id'] : '' );

		try {
			$destination = ( $this->resolver )( $id );
			if ( ! $destination instanceof SScribe_Destination_Interface ) {
				return $this->record( $id, false, __( 'No export destination has that id.', 'sscribe-export-site-pages' ) );
			}

			$settings = SScribe_Destination_Settings::open_secrets( $id, is_array( $entry['settings'] ?? null ) ? $entry['settings'] : array() );
			$result   = $destination->deliver( $zip_path, $context, $settings );
		} catch ( \Throwable $e ) {
			self::logger()->error(
				'Export destination crashed',
				array(
					'destination' => $id,
					'exception'   => get_class( $e ),
				)
			);
			return $this->record( $id, false, __( 'The destination stopped with an unexpected error.', 'sscribe-export-site-pages' ) );
		}

		if ( $result->is_success() ) {
			$data = $result->get_data();
			return $this->record( $id, true, is_string( $data ) ? $data : '' );
		}

		return $this->record( $id, false, (string) ( $result->get_error() ?? __( 'The delivery failed.', 'sscribe-export-site-pages' ) ) );
	}

	/**
	 * Log a delivery result and return it.
	 *
	 * @param string $id      Destination id.
	 * @param bool   $ok      Whether the delivery worked.
	 * @param string $message Location on success, translated error on failure.
	 * @return array{id: string, ok: bool, message: string}
	 */
	private function record( string $id, bool $ok, string $message ): array {
		if ( $ok ) {
			self::logger()->info( 'Export delivered', array( 'destination' => $id ) );
		} else {
			self::logger()->warning(
				'Export delivery failed',
				array(
					'destination' => $id,
					'message'     => $message,
				)
			);
		}

		return array(
			'id'      => $id,
			'ok'      => $ok,
			'message' => $message,
		);
	}

	/**
	 * The decoded manifest.json of an archive, or an empty array.
	 *
	 * @param string $zip_path Archive path.
	 * @return array<string, mixed>
	 */
	private static function read_manifest( string $zip_path ): array {
		if ( ! class_exists( 'ZipArchive' ) || ! is_file( $zip_path ) ) {
			return array();
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::RDONLY ) ) {
			return array();
		}

		try {
			$stat = $zip->statName( SScribe_Export_Manifest::JSON_ENTRY );
			if ( false === $stat || (int) $stat['size'] > self::MAX_MANIFEST_BYTES ) {
				return array();
			}
			$json = $zip->getFromName( SScribe_Export_Manifest::JSON_ENTRY );
		} finally {
			$zip->close();
		}

		$decoded = is_string( $json ) ? json_decode( $json, true ) : null;

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Plugin logger.
	 *
	 * @return SScribe_Logger_Interface
	 */
	private static function logger(): SScribe_Logger_Interface {
		return SScribe_Logger::instance( SScribe_Logger::is_logging_enabled() );
	}
}
