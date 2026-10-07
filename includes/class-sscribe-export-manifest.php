<?php
/**
 * Export manifest: a machine-readable index of every document in an archive.
 *
 * During an export each generated file is appended as one JSON line to a
 * dotfile sidecar inside the session temp directory. The sidecar survives
 * request boundaries, retries and resumes (later lines win), and is never
 * matched by the per-format globs that collect documents for the archive.
 * When the ZIP is assembled the sidecar is joined with the archive entries,
 * every document is hashed, and `manifest.json` plus a human-readable
 * `INDEX.md` are written into the archive root.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SScribe_Export_Manifest {

	public const SCHEMA       = 'sscribe-export-manifest/1';
	public const SIDECAR_NAME = '.sscribe-manifest.jsonl';
	public const JSON_ENTRY   = 'manifest.json';
	public const INDEX_ENTRY  = 'INDEX.md';

	private const MAX_SIDECAR_BYTES = 16777216;
	private const MAX_TITLE_LENGTH  = 500;
	private const MAX_URL_LENGTH    = 2048;

	private const EXTENSION_FORMATS = array(
		'docx' => 'docx',
		'pdf'  => 'pdf',
		'html' => 'html',
		'md'   => 'markdown',
	);

	/**
	 * Append one generated-file record to the session sidecar.
	 *
	 * @param string $temp_dir Session temp directory (absolute).
	 * @param array  $record   Keys: file (relative to temp dir, e.g. "EN/P001-x.docx"),
	 *                         format, lang, post_id, post_type, title, url, modified.
	 * @return bool True when the line was written.
	 */
	public static function record( string $temp_dir, array $record ): bool {
		$file   = self::sanitize_relative_path( (string) ( $record['file'] ?? '' ) );
		$format = sanitize_key( (string) ( $record['format'] ?? '' ) );
		if ( '' === $file || '' === $format || ! is_dir( $temp_dir ) ) {
			return false;
		}

		$line = wp_json_encode( self::normalize_record( $record, $file, $format ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $line ) ) {
			return false;
		}

		$sidecar = rtrim( $temp_dir, '/\\' ) . '/' . self::SIDECAR_NAME;
		$written = file_put_contents( $sidecar, $line . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Append-only sidecar inside the plugin's private export workspace.

		return false !== $written;
	}

	/**
	 * Load sidecar records keyed by relative file path. Later lines replace
	 * earlier ones so a retried page keeps a single record.
	 *
	 * @param string $temp_dir Session temp directory.
	 * @return array<string,array<string,mixed>>
	 */
	public static function load( string $temp_dir ): array {
		$sidecar = rtrim( $temp_dir, '/\\' ) . '/' . self::SIDECAR_NAME;
		if ( ! is_file( $sidecar ) || is_link( $sidecar ) ) {
			return array();
		}
		$size = filesize( $sidecar );
		if ( false === $size || $size > self::MAX_SIDECAR_BYTES ) {
			return array();
		}

		$raw = file_get_contents( $sidecar ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Plugin-owned sidecar in private storage.
		if ( false === $raw ) {
			return array();
		}

		$records = array();
		foreach ( explode( "\n", $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$decoded = json_decode( $line, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			$file   = self::sanitize_relative_path( (string) ( $decoded['file'] ?? '' ) );
			$format = sanitize_key( (string) ( $decoded['format'] ?? '' ) );
			if ( '' === $file || '' === $format ) {
				continue;
			}
			$records[ $file ] = self::normalize_record( $decoded, $file, $format );
		}

		return $records;
	}

	/**
	 * Build the manifest document from the archive entries and the sidecar.
	 *
	 * @param string $temp_dir    Session temp directory.
	 * @param array  $zip_entries Entries as assembled by the ZIP handler. Each has
	 *                            source_path, zip_path, lang_code and size.
	 * @param array  $meta        session_id, formats, languages.
	 * @return array<string,mixed>
	 */
	public static function build( string $temp_dir, array $zip_entries, array $meta = array() ): array {
		$records   = self::load( $temp_dir );
		$temp_root = rtrim( str_replace( '\\', '/', $temp_dir ), '/' );

		$files      = array();
		$posts      = array();
		$bytes      = 0;
		$by_format  = array();
		$languages  = array();

		foreach ( $zip_entries as $entry ) {
			$source   = str_replace( '\\', '/', (string) ( $entry['source_path'] ?? '' ) );
			$relative = '' !== $source && str_starts_with( $source, $temp_root . '/' )
				? substr( $source, strlen( $temp_root ) + 1 )
				: '';
			$record   = '' !== $relative && isset( $records[ $relative ] ) ? $records[ $relative ] : null;

			$zip_path  = (string) ( $entry['zip_path'] ?? '' );
			$extension = strtolower( (string) pathinfo( $zip_path, PATHINFO_EXTENSION ) );
			$format    = null !== $record ? $record['format'] : ( self::EXTENSION_FORMATS[ $extension ] ?? $extension );
			$lang      = null !== $record && '' !== $record['lang']
				? $record['lang']
				: strtolower( (string) ( $entry['lang_code'] ?? '' ) );

			$sha256 = '' !== $source && is_file( $source ) && ! is_link( $source ) ? hash_file( 'sha256', $source ) : false;
			$size   = (int) ( $entry['size'] ?? 0 );

			$post = null;
			if ( null !== $record && $record['post_id'] > 0 ) {
				$post = array(
					'id'           => $record['post_id'],
					'type'         => $record['post_type'],
					'title'        => $record['title'],
					'url'          => $record['url'],
					'modified_utc' => $record['modified'],
				);
				$posts[ $record['post_id'] ] = true;
			}

			$files[] = array(
				'path'   => $zip_path,
				'format' => $format,
				'lang'   => $lang,
				'bytes'  => $size,
				'sha256' => false === $sha256 ? null : $sha256,
				'post'   => $post,
			);

			$bytes                += $size;
			$by_format[ $format ]  = ( $by_format[ $format ] ?? 0 ) + 1;
			if ( '' !== $lang ) {
				$languages[ $lang ] = true;
			}
		}

		ksort( $by_format );

		$declared_languages = array_values( array_filter( array_map( 'strval', (array) ( $meta['languages'] ?? array() ) ) ) );
		if ( array() === $declared_languages ) {
			$declared_languages = array_keys( $languages );
			sort( $declared_languages );
		}

		return array(
			'schema'    => self::SCHEMA,
			'generator' => array(
				'name'    => 'SScribe Export Site Pages',
				'version' => defined( 'SSCRIBE_VERSION' ) ? (string) SSCRIBE_VERSION : '',
			),
			'site'      => array(
				'name' => (string) get_bloginfo( 'name' ),
				'url'  => (string) home_url( '/' ),
			),
			'export'    => array(
				'created_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'session_id'  => (string) ( $meta['session_id'] ?? '' ),
				'formats'     => array_values( array_map( 'strval', (array) ( $meta['formats'] ?? array_keys( $by_format ) ) ) ),
				'languages'   => $declared_languages,
			),
			'files'     => $files,
			'totals'    => array(
				'files'     => count( $files ),
				'posts'     => count( $posts ),
				'bytes'     => $bytes,
				'by_format' => $by_format,
			),
		);
	}

	/**
	 * Pretty, unescaped JSON suitable for humans and tooling alike.
	 *
	 * @param array $manifest Manifest document.
	 * @return string
	 */
	public static function to_json( array $manifest ): string {
		$json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? $json . "\n" : "{}\n";
	}

	/**
	 * Render INDEX.md: one row per source post with a link per format, then
	 * any files that have no source record.
	 *
	 * @param array $manifest Manifest document.
	 * @return string
	 */
	public static function render_index_markdown( array $manifest ): string {
		$site    = (array) ( $manifest['site'] ?? array() );
		$export  = (array) ( $manifest['export'] ?? array() );
		$totals  = (array) ( $manifest['totals'] ?? array() );
		$files   = (array) ( $manifest['files'] ?? array() );
		$version = (string) ( $manifest['generator']['version'] ?? '' );

		$rows     = array();
		$orphans  = array();
		foreach ( $files as $file ) {
			$post = $file['post'] ?? null;
			if ( ! is_array( $post ) ) {
				$orphans[] = $file;
				continue;
			}
			$key = (string) $post['id'] . '|' . (string) ( $file['lang'] ?? '' );
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array(
					'post'  => $post,
					'lang'  => (string) ( $file['lang'] ?? '' ),
					'links' => array(),
				);
			}
			$rows[ $key ]['links'][ (string) $file['format'] ] = (string) $file['path'];
		}

		$lines   = array();
		$lines[] = '# ' . self::md_escape( (string) ( $site['name'] ?? '' ) ) . ' export';
		$lines[] = '';
		$lines[] = 'Generated ' . self::md_escape( (string) ( $export['created_utc'] ?? '' ) ) . ' by SScribe Export Site Pages ' . self::md_escape( $version ) . '.';
		$lines[] = 'Site: ' . self::md_escape( (string) ( $site['url'] ?? '' ) );
		$lines[] = '';
		$lines[] = sprintf(
			'%d documents for %d posts across %d formats (%s). Checksums and metadata are in `%s`.',
			(int) ( $totals['files'] ?? 0 ),
			(int) ( $totals['posts'] ?? 0 ),
			count( (array) ( $totals['by_format'] ?? array() ) ),
			self::md_escape( implode( ', ', array_map( 'strval', (array) ( $export['formats'] ?? array() ) ) ) ),
			self::JSON_ENTRY
		);
		$lines[] = '';
		$lines[] = '| ID | Type | Language | Title | Source | Modified (UTC) | Documents |';
		$lines[] = '|---:|------|----------|-------|--------|----------------|-----------|';

		foreach ( $rows as $row ) {
			$post  = $row['post'];
			$links = array();
			ksort( $row['links'] );
			foreach ( $row['links'] as $format => $path ) {
				$links[] = '[' . strtoupper( self::md_escape( $format ) ) . '](' . self::md_link_target( $path ) . ')';
			}
			$lines[] = sprintf(
				'| %d | %s | %s | %s | %s | %s | %s |',
				(int) $post['id'],
				self::md_escape( (string) ( $post['type'] ?? '' ) ),
				self::md_escape( $row['lang'] ),
				self::md_escape( (string) ( $post['title'] ?? '' ) ),
				self::md_escape( (string) ( $post['url'] ?? '' ) ),
				self::md_escape( (string) ( $post['modified_utc'] ?? '' ) ),
				implode( ' ', $links )
			);
		}

		if ( array() !== $orphans ) {
			$lines[] = '';
			$lines[] = '## Other files';
			$lines[] = '';
			foreach ( $orphans as $file ) {
				$lines[] = '- `' . self::md_escape( (string) ( $file['path'] ?? '' ) ) . '`';
			}
		}

		$lines[] = '';
		return implode( "\n", $lines );
	}

	/**
	 * @param array  $record Raw record.
	 * @param string $file   Sanitized relative path.
	 * @param string $format Sanitized format key.
	 * @return array<string,mixed>
	 */
	private static function normalize_record( array $record, string $file, string $format ): array {
		$url = (string) ( $record['url'] ?? '' );
		return array(
			'file'      => $file,
			'format'    => $format,
			'lang'      => sanitize_key( (string) ( $record['lang'] ?? '' ) ),
			'post_id'   => max( 0, (int) ( $record['post_id'] ?? 0 ) ),
			'post_type' => sanitize_key( (string) ( $record['post_type'] ?? '' ) ),
			'title'     => mb_substr( wp_strip_all_tags( (string) ( $record['title'] ?? '' ) ), 0, self::MAX_TITLE_LENGTH ),
			'url'       => mb_substr( $url, 0, self::MAX_URL_LENGTH ),
			'modified'  => (string) ( $record['modified'] ?? '' ),
		);
	}

	/**
	 * Accept only a forward-slash relative path with no traversal or absolute
	 * components.
	 *
	 * @param string $path Candidate path.
	 * @return string Sanitized path or empty string.
	 */
	private static function sanitize_relative_path( string $path ): string {
		$path = str_replace( '\\', '/', trim( $path ) );
		if ( '' === $path || str_starts_with( $path, '/' ) || str_contains( $path, "\0" ) || preg_match( '/^[A-Za-z]:/', $path ) ) {
			return '';
		}
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return '';
			}
		}
		return $path;
	}

	/**
	 * Escape text for a Markdown table cell.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function md_escape( string $text ): string {
		$text = str_replace( array( "\r", "\n" ), ' ', $text );
		$text = str_replace( array( '\\', '|', '<', '>', '`', '*', '_', '[', ']' ), array( '\\\\', '\\|', '&lt;', '&gt;', '\\`', '\\*', '\\_', '\\[', '\\]' ), $text );
		return $text;
	}

	/**
	 * Encode characters that would break a Markdown link target.
	 *
	 * @param string $path Archive-relative path.
	 * @return string
	 */
	private static function md_link_target( string $path ): string {
		return str_replace( array( ' ', '(', ')' ), array( '%20', '%28', '%29' ), $path );
	}
}
