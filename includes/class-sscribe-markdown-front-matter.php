<?php
/**
 * Front matter presets for Markdown exports aimed at static site
 * generators and note tools.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SScribe_Markdown_Front_Matter {

	public const PRESET_SSCRIBE  = 'sscribe';
	public const PRESET_HUGO     = 'hugo';
	public const PRESET_JEKYLL   = 'jekyll';
	public const PRESET_ASTRO    = 'astro';
	public const PRESET_OBSIDIAN = 'obsidian';
	public const PRESET_NONE     = 'none';

	public const PRESETS = array(
		self::PRESET_SSCRIBE,
		self::PRESET_HUGO,
		self::PRESET_JEKYLL,
		self::PRESET_ASTRO,
		self::PRESET_OBSIDIAN,
		self::PRESET_NONE,
	);

	private const MAX_DESCRIPTION = 300;

	/**
	 * Normalize a preset name, falling back to the plugin's own layout.
	 *
	 * @param mixed $preset Raw option value.
	 * @return string One of the PRESET_* constants.
	 */
	public static function normalize_preset( mixed $preset ): string {
		$preset = is_scalar( $preset ) ? strtolower( trim( (string) $preset ) ) : '';
		return in_array( $preset, self::PRESETS, true ) ? $preset : self::PRESET_SSCRIBE;
	}

	/**
	 * Human labels for the admin select and the CLI help.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			self::PRESET_SSCRIBE  => __( 'SScribe (full metadata)', 'sscribe-export-site-pages' ),
			self::PRESET_HUGO     => __( 'Hugo', 'sscribe-export-site-pages' ),
			self::PRESET_JEKYLL   => __( 'Jekyll', 'sscribe-export-site-pages' ),
			self::PRESET_ASTRO    => __( 'Astro content collections', 'sscribe-export-site-pages' ),
			self::PRESET_OBSIDIAN => __( 'Obsidian', 'sscribe-export-site-pages' ),
			self::PRESET_NONE     => __( 'No front matter', 'sscribe-export-site-pages' ),
		);
	}

	/**
	 * Render the front matter block for a preset.
	 *
	 * @param string $preset One of the PRESET_* constants other than sscribe/none.
	 * @param array  $fields Normalized page fields: title, slug, url, author,
	 *                       published (ISO 8601 or ''), modified (ISO 8601 or ''),
	 *                       status, post_type, language, description,
	 *                       featured_image, canonical_url, post_id.
	 * @return string YAML block ending with a blank line, or '' for unknown presets.
	 */
	public static function render( string $preset, array $fields ): string {
		$fields = self::clean_fields( $fields );

		switch ( $preset ) {
			case self::PRESET_HUGO:
				$lines = self::hugo( $fields );
				break;
			case self::PRESET_JEKYLL:
				$lines = self::jekyll( $fields );
				break;
			case self::PRESET_ASTRO:
				$lines = self::astro( $fields );
				break;
			case self::PRESET_OBSIDIAN:
				$lines = self::obsidian( $fields );
				break;
			default:
				return '';
		}

		$lines[] = 'sscribe:';
		$lines[] = '  source_url: ' . self::yaml( $fields['url'] );
		$lines[] = '  post_id: ' . $fields['post_id'];
		$lines[] = '  post_type: ' . self::yaml( $fields['post_type'] );
		$lines[] = '  language: ' . self::yaml( $fields['language'] );

		return "---\n" . implode( "\n", $lines ) . "\n---\n\n";
	}

	/**
	 * Hugo keys: date, lastmod, draft, slug, description, images.
	 *
	 * @param array<string, mixed> $f Fields.
	 * @return list<string>
	 */
	private static function hugo( array $f ): array {
		$lines   = array();
		$lines[] = 'title: ' . self::yaml( $f['title'] );
		if ( '' !== $f['published'] ) {
			$lines[] = 'date: ' . $f['published'];
		}
		if ( '' !== $f['modified'] ) {
			$lines[] = 'lastmod: ' . $f['modified'];
		}
		$lines[] = 'draft: ' . ( 'publish' === $f['status'] ? 'false' : 'true' );
		if ( '' !== $f['slug'] ) {
			$lines[] = 'slug: ' . self::yaml( $f['slug'] );
		}
		if ( '' !== $f['description'] ) {
			$lines[] = 'description: ' . self::yaml( $f['description'] );
		}
		if ( '' !== $f['author'] ) {
			$lines[] = 'author: ' . self::yaml( $f['author'] );
		}
		if ( '' !== $f['featured_image'] ) {
			$lines[] = 'images:';
			$lines[] = '  - ' . self::yaml( $f['featured_image'] );
		}
		if ( '' !== $f['canonical_url'] ) {
			$lines[] = 'canonicalURL: ' . self::yaml( $f['canonical_url'] );
		}
		return $lines;
	}

	/**
	 * Jekyll keys: layout, permalink and its own date format.
	 *
	 * @param array<string, mixed> $f Fields.
	 * @return list<string>
	 */
	private static function jekyll( array $f ): array {
		$lines   = array();
		$lines[] = 'layout: ' . ( 'post' === $f['post_type'] ? 'post' : 'page' );
		$lines[] = 'title: ' . self::yaml( $f['title'] );
		if ( '' !== $f['published'] ) {
			$lines[] = 'date: ' . self::yaml( self::jekyll_date( $f['published'] ) );
		}
		if ( '' !== $f['modified'] ) {
			$lines[] = 'last_modified_at: ' . self::yaml( self::jekyll_date( $f['modified'] ) );
		}
		$path = self::url_path( $f['url'] );
		if ( '' !== $path ) {
			$lines[] = 'permalink: ' . self::yaml( $path );
		}
		if ( '' !== $f['author'] ) {
			$lines[] = 'author: ' . self::yaml( $f['author'] );
		}
		if ( '' !== $f['description'] ) {
			$lines[] = 'excerpt: ' . self::yaml( $f['description'] );
		}
		if ( '' !== $f['featured_image'] ) {
			$lines[] = 'image: ' . self::yaml( $f['featured_image'] );
		}
		if ( '' !== $f['language'] ) {
			$lines[] = 'lang: ' . self::yaml( $f['language'] );
		}
		$lines[] = 'published: ' . ( 'publish' === $f['status'] ? 'true' : 'false' );
		return $lines;
	}

	/**
	 * Astro content-collection keys: pubDate, updatedDate, heroImage.
	 *
	 * @param array<string, mixed> $f Fields.
	 * @return list<string>
	 */
	private static function astro( array $f ): array {
		$lines   = array();
		$lines[] = 'title: ' . self::yaml( $f['title'] );
		if ( '' !== $f['description'] ) {
			$lines[] = 'description: ' . self::yaml( $f['description'] );
		}
		if ( '' !== $f['published'] ) {
			$lines[] = 'pubDate: ' . self::yaml( $f['published'] );
		}
		if ( '' !== $f['modified'] ) {
			$lines[] = 'updatedDate: ' . self::yaml( $f['modified'] );
		}
		$lines[] = 'draft: ' . ( 'publish' === $f['status'] ? 'false' : 'true' );
		if ( '' !== $f['author'] ) {
			$lines[] = 'author: ' . self::yaml( $f['author'] );
		}
		if ( '' !== $f['featured_image'] ) {
			$lines[] = 'heroImage: ' . self::yaml( $f['featured_image'] );
		}
		if ( '' !== $f['language'] ) {
			$lines[] = 'lang: ' . self::yaml( $f['language'] );
		}
		return $lines;
	}

	/**
	 * Obsidian keys: aliases, tags, created, updated, source.
	 *
	 * @param array<string, mixed> $f Fields.
	 * @return list<string>
	 */
	private static function obsidian( array $f ): array {
		$lines   = array();
		$lines[] = 'title: ' . self::yaml( $f['title'] );
		if ( '' !== $f['slug'] ) {
			$lines[] = 'aliases:';
			$lines[] = '  - ' . self::yaml( $f['slug'] );
		}
		$lines[] = 'tags:';
		$lines[] = '  - ' . self::yaml( 'wordpress' );
		if ( '' !== $f['post_type'] ) {
			$lines[] = '  - ' . self::yaml( $f['post_type'] );
		}
		if ( '' !== $f['published'] ) {
			$lines[] = 'created: ' . self::yaml( $f['published'] );
		}
		if ( '' !== $f['modified'] ) {
			$lines[] = 'updated: ' . self::yaml( $f['modified'] );
		}
		if ( '' !== $f['url'] ) {
			$lines[] = 'source: ' . self::yaml( $f['url'] );
		}
		if ( '' !== $f['author'] ) {
			$lines[] = 'author: ' . self::yaml( $f['author'] );
		}
		return $lines;
	}

	/**
	 * Trim, type and bound every field so presets can rely on strings.
	 *
	 * @param array<string, mixed> $fields Raw fields.
	 * @return array<string, mixed>
	 */
	private static function clean_fields( array $fields ): array {
		$string = static function ( mixed $value ): string {
			return is_scalar( $value ) && ! is_bool( $value ) ? trim( (string) $value ) : '';
		};
		$url    = static function ( mixed $value ) use ( $string ): string {
			$value = $string( $value );
			return 1 === preg_match( '#^https?://#i', $value ) ? $value : '';
		};

		return array(
			'title'          => $string( $fields['title'] ?? '' ),
			'slug'           => $string( $fields['slug'] ?? '' ),
			'url'            => $url( $fields['url'] ?? '' ),
			'author'         => $string( $fields['author'] ?? '' ),
			'published'      => self::iso_date( $fields['published'] ?? '' ),
			'modified'       => self::iso_date( $fields['modified'] ?? '' ),
			'status'         => $string( $fields['status'] ?? 'publish' ),
			'post_type'      => $string( $fields['post_type'] ?? '' ),
			'language'       => $string( $fields['language'] ?? '' ),
			'description'    => mb_substr( preg_replace( '/\s+/u', ' ', $string( $fields['description'] ?? '' ) ) ?? '', 0, self::MAX_DESCRIPTION ),
			'featured_image' => $url( $fields['featured_image'] ?? '' ),
			'canonical_url'  => $url( $fields['canonical_url'] ?? '' ),
			'post_id'        => max( 0, (int) ( $fields['post_id'] ?? 0 ) ),
		);
	}

	/**
	 * Accept an ISO 8601 string or a Unix timestamp and emit ISO 8601 UTC.
	 *
	 * @param mixed $value Raw date.
	 * @return string ISO 8601 (Y-m-d\TH:i:s\Z) or ''.
	 */
	private static function iso_date( mixed $value ): string {
		if ( is_int( $value ) && $value > 0 ) {
			return gmdate( 'Y-m-d\TH:i:s\Z', $value );
		}
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}
		$timestamp = strtotime( trim( $value ) );
		return false === $timestamp ? '' : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	/**
	 * Jekyll expects "YYYY-MM-DD HH:MM:SS +0000".
	 *
	 * @param string $iso ISO 8601 UTC date.
	 * @return string
	 */
	private static function jekyll_date( string $iso ): string {
		$timestamp = strtotime( $iso );
		return false === $timestamp ? '' : gmdate( 'Y-m-d H:i:s', $timestamp ) . ' +0000';
	}

	/**
	 * Path component of a URL with a trailing slash, for Jekyll permalinks.
	 *
	 * @param string $url Absolute URL.
	 * @return string
	 */
	private static function url_path( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		$path = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?? '' );
		if ( '' === $path || '/' === $path ) {
			return '/';
		}
		return '/' . trim( $path, '/' ) . '/';
	}

	/**
	 * Quote a scalar for YAML, escaping what a double-quoted scalar needs.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function yaml( string $text ): string {
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text ) ?? '';
		$text = str_replace( array( '\\', '"', "\n", "\r", "\t" ), array( '\\\\', '\\"', '\\n', '\\r', '\\t' ), $text );
		return '"' . $text . '"';
	}
}
