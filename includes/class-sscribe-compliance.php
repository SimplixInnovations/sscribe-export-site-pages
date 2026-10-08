<?php
/**
 * Compliance mode: provenance, signed manifests and retention.
 *
 * @package SScribe_Export_Site_Pages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the evidence a records archive needs and verifies it later.
 */
final class SScribe_Compliance {

	public const OPTION_KEY      = 'sscribe_compliance_mode';
	public const KEY_OPTION      = 'sscribe_manifest_signing_key';
	public const SIGNATURE_ENTRY = 'manifest.sig';
	public const SIGNATURE_FORMAT = 'sscribe-manifest-signature/1';

	private const KEY_BYTES              = 32;
	private const DEFAULT_RETENTION_DAYS = 365;
	private const MAX_RETENTION_DAYS     = 3650;

	/**
	 * Whether the export's format options turn compliance mode on.
	 *
	 * @param array $format_options Session format options.
	 * @return bool
	 */
	public static function is_enabled( array $format_options ): bool {
		$raw = $format_options[ self::OPTION_KEY ] ?? '0';
		return is_scalar( $raw ) && in_array( strtolower( trim( (string) $raw ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Context the ZIP handler needs, derived from a session.
	 *
	 * @param array $session Export session.
	 * @return array{enabled: bool, user_id: int}
	 */
	public static function context_from_session( array $session ): array {
		$options = isset( $session['format_options'] ) && is_array( $session['format_options'] ) ? $session['format_options'] : array();
		return array(
			'enabled' => self::is_enabled( $options ),
			'user_id' => isset( $session['user_id'] ) && is_numeric( $session['user_id'] ) ? (int) $session['user_id'] : get_current_user_id(),
		);
	}

	/**
	 * Retention applied to compliance archives, in days.
	 *
	 * @return int
	 */
	public static function retention_days(): int {
		/**
		 * Filter how long a compliance archive is kept before cleanup.
		 *
		 * @param int $days Days, default 365.
		 */
		$days = apply_filters( 'sscribe_compliance_retention_days', self::DEFAULT_RETENTION_DAYS );
		return max( 1, min( self::MAX_RETENTION_DAYS, is_numeric( $days ) ? (int) $days : self::DEFAULT_RETENTION_DAYS ) );
	}

	/**
	 * Who exported, when, from where: attached to every document.
	 *
	 * @param int $post_id Post id.
	 * @param int $user_id User the export runs for.
	 * @return array<string, string|int>
	 */
	public static function provenance( int $post_id, int $user_id ): array {
		$post     = get_post( $post_id );
		$modified = $post instanceof WP_Post ? (string) $post->post_modified_gmt : '';
		$content  = $post instanceof WP_Post ? (string) $post->post_content : '';
		$exporter = self::user_login( $user_id );

		return array(
			'site_name'      => (string) get_bloginfo( 'name' ),
			'site_url'       => (string) home_url( '/' ),
			'source_url'     => (string) get_permalink( $post_id ),
			'post_id'        => $post_id,
			'modified_utc'   => '' !== $modified ? str_replace( ' ', 'T', $modified ) . 'Z' : '',
			'content_sha256' => hash( 'sha256', $content ),
			'exported_utc'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'exported_by'    => $exporter,
			'plugin_version' => defined( 'SSCRIBE_VERSION' ) ? (string) SSCRIBE_VERSION : '',
		);
	}

	/**
	 * Label/value rows for the provenance block, in display order.
	 *
	 * @param array $provenance Output of provenance().
	 * @return list<array{label: string, value: string}>
	 */
	public static function provenance_rows( array $provenance ): array {
		$labels = array(
			'source_url'     => __( 'Source URL', 'sscribe-export-site-pages' ),
			'post_id'        => __( 'Post ID', 'sscribe-export-site-pages' ),
			'modified_utc'   => __( 'Content last modified (UTC)', 'sscribe-export-site-pages' ),
			'content_sha256' => __( 'Content SHA-256', 'sscribe-export-site-pages' ),
			'exported_utc'   => __( 'Exported (UTC)', 'sscribe-export-site-pages' ),
			'exported_by'    => __( 'Exported by', 'sscribe-export-site-pages' ),
			'site_url'       => __( 'Site', 'sscribe-export-site-pages' ),
			'plugin_version' => __( 'Exporter version', 'sscribe-export-site-pages' ),
		);
		$rows = array();
		foreach ( $labels as $key => $label ) {
			$value = isset( $provenance[ $key ] ) && is_scalar( $provenance[ $key ] ) ? trim( (string) $provenance[ $key ] ) : '';
			if ( '' === $value ) {
				continue;
			}
			$rows[] = array(
				'label' => $label,
				'value' => $value,
			);
		}
		return $rows;
	}

	/**
	 * Block recorded in manifest.json when compliance mode is on.
	 *
	 * @param int $user_id User the export runs for.
	 * @return array<string, mixed>
	 */
	public static function manifest_block( int $user_id ): array {
		global $wp_version;

		return array(
			'enabled'        => true,
			'exported_by'    => array(
				'id'    => $user_id,
				'login' => self::user_login( $user_id ),
			),
			'retention_days' => self::retention_days(),
			'environment'    => array(
				'wordpress' => isset( $wp_version ) && is_scalar( $wp_version ) ? (string) $wp_version : '',
				'php'       => PHP_VERSION,
				'plugin'    => defined( 'SSCRIBE_VERSION' ) ? (string) SSCRIBE_VERSION : '',
			),
			'signature'      => array(
				'entry'     => self::SIGNATURE_ENTRY,
				'algorithm' => 'HMAC-SHA256',
				'key_id'    => self::key_id( self::signing_key() ),
			),
		);
	}

	/**
	 * Detached signature text for a manifest.json body.
	 *
	 * @param string $manifest_json Exact bytes written to the archive.
	 * @return string
	 */
	public static function sign( string $manifest_json ): string {
		$key = self::signing_key();
		return implode(
			"\n",
			array(
				self::SIGNATURE_FORMAT,
				'algorithm: HMAC-SHA256',
				'key_id: ' . self::key_id( $key ),
				'manifest_sha256: ' . hash( 'sha256', $manifest_json ),
				'signature: ' . hash_hmac( 'sha256', $manifest_json, $key ),
			)
		) . "\n";
	}

	/**
	 * Check a detached signature against a manifest.json body.
	 *
	 * @param string $manifest_json  Manifest bytes.
	 * @param string $signature_text manifest.sig contents.
	 * @return array{valid: bool, reason: string}
	 */
	public static function verify_signature( string $manifest_json, string $signature_text ): array {
		$parsed = self::parse_signature( $signature_text );
		if ( array() === $parsed ) {
			return array(
				'valid'  => false,
				'reason' => 'malformed',
			);
		}
		$key = self::signing_key( false );
		if ( '' === $key ) {
			return array(
				'valid'  => false,
				'reason' => 'no_key',
			);
		}
		if ( ! hash_equals( self::key_id( $key ), $parsed['key_id'] ) ) {
			return array(
				'valid'  => false,
				'reason' => 'key_mismatch',
			);
		}
		if ( ! hash_equals( hash_hmac( 'sha256', $manifest_json, $key ), $parsed['signature'] ) ) {
			return array(
				'valid'  => false,
				'reason' => 'signature_mismatch',
			);
		}
		return array(
			'valid'  => true,
			'reason' => '',
		);
	}

	/**
	 * Verify an archive: every manifest checksum and, when present, the signature.
	 *
	 * @param string $zip_path Absolute ZIP path.
	 * @return array{ok: bool, files: int, mismatched: list<string>, missing: list<string>, signature: string, error: string}
	 */
	public static function verify_archive( string $zip_path ): array {
		$report = array(
			'ok'         => false,
			'files'      => 0,
			'mismatched' => array(),
			'missing'    => array(),
			'signature'  => 'absent',
			'error'      => '',
		);
		if ( ! is_file( $zip_path ) || is_link( $zip_path ) ) {
			$report['error'] = 'not_found';
			return $report;
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::RDONLY ) ) {
			$report['error'] = 'unreadable';
			return $report;
		}
		try {
			$manifest_json = $zip->getFromName( SScribe_Export_Manifest::JSON_ENTRY );
			if ( ! is_string( $manifest_json ) ) {
				$report['error'] = 'no_manifest';
				return $report;
			}
			$manifest = json_decode( $manifest_json, true );
			if ( ! is_array( $manifest ) || ! isset( $manifest['files'] ) || ! is_array( $manifest['files'] ) ) {
				$report['error'] = 'bad_manifest';
				return $report;
			}
			foreach ( $manifest['files'] as $file ) {
				if ( ! is_array( $file ) || ! isset( $file['path'] ) || ! is_string( $file['path'] ) ) {
					continue;
				}
				++$report['files'];
				$expected = isset( $file['sha256'] ) && is_string( $file['sha256'] ) ? strtolower( $file['sha256'] ) : '';
				$body     = $zip->getFromName( $file['path'] );
				if ( ! is_string( $body ) ) {
					$report['missing'][] = $file['path'];
					continue;
				}
				if ( '' !== $expected && ! hash_equals( $expected, hash( 'sha256', $body ) ) ) {
					$report['mismatched'][] = $file['path'];
				}
			}
			$signature_text = $zip->getFromName( self::SIGNATURE_ENTRY );
			if ( is_string( $signature_text ) ) {
				$verdict             = self::verify_signature( $manifest_json, $signature_text );
				$report['signature'] = $verdict['valid'] ? 'valid' : 'invalid:' . $verdict['reason'];
			}
		} finally {
			$zip->close();
		}
		$report['ok'] = array() === $report['mismatched'] && array() === $report['missing'] && ! str_starts_with( $report['signature'], 'invalid' );
		return $report;
	}

	/**
	 * Persistent signing key, created on first use.
	 *
	 * @param bool $create Create the key when none is stored.
	 * @return string Raw key bytes, empty when absent and not created.
	 */
	public static function signing_key( bool $create = true ): string {
		$stored = get_option( self::KEY_OPTION, '' );
		$key    = is_string( $stored ) && '' !== $stored ? base64_decode( $stored, true ) : false;
		if ( is_string( $key ) && strlen( $key ) === self::KEY_BYTES ) {
			return $key;
		}
		if ( ! $create ) {
			return '';
		}
		$key = random_bytes( self::KEY_BYTES );
		add_option( self::KEY_OPTION, base64_encode( $key ), '', 'no' );
		$stored = get_option( self::KEY_OPTION, '' );
		$again  = is_string( $stored ) ? base64_decode( $stored, true ) : false;
		return is_string( $again ) && strlen( $again ) === self::KEY_BYTES ? $again : $key;
	}

	/**
	 * Public identifier of a key, safe to publish in the manifest.
	 *
	 * @param string $key Raw key bytes.
	 * @return string
	 */
	public static function key_id( string $key ): string {
		return substr( hash( 'sha256', 'sscribe-manifest-key:' . $key ), 0, 16 );
	}

	/**
	 * Parse the detached signature text.
	 *
	 * @param string $text manifest.sig contents.
	 * @return array{key_id: string, signature: string}|array{}
	 */
	private static function parse_signature( string $text ): array {
		$lines = preg_split( '/\r?\n/', trim( $text ) );
		if ( ! is_array( $lines ) || self::SIGNATURE_FORMAT !== ( $lines[0] ?? '' ) ) {
			return array();
		}
		$values = array();
		foreach ( array_slice( $lines, 1 ) as $line ) {
			if ( 1 === preg_match( '/^([a-z_]+):\s*([A-Za-z0-9\-]+)$/', $line, $m ) ) {
				$values[ $m[1] ] = $m[2];
			}
		}
		if ( ! isset( $values['key_id'], $values['signature'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $values['signature'] ) ) {
			return array();
		}
		return array(
			'key_id'    => $values['key_id'],
			'signature' => $values['signature'],
		);
	}

	/**
	 * Login name of a user, or a neutral label for system runs.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private static function user_login( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( is_object( $user ) && isset( $user->user_login ) && is_string( $user->user_login ) && '' !== $user->user_login ) {
			return $user->user_login;
		}
		return __( 'system', 'sscribe-export-site-pages' );
	}
}
