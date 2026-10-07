<?php
/**
 * SScribe Export Job
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
 * What to export: language, status, post type, formats and their options.
 *
 * Only the shape is checked here. Whether a language, post type or format
 * is actually usable is decided by the export core, so every caller gets
 * the same answers and error messages.
 */
final class SScribe_Export_Job {

	private const MAX_FORMATS         = 10;
	private const MAX_LANGUAGE_LENGTH = 100;

	/**
	 * Requested language code, or an empty string for every language.
	 *
	 * @var string
	 */
	public readonly string $language;

	/**
	 * Requested post status.
	 *
	 * @var string
	 */
	public readonly string $post_status;

	/**
	 * Requested post type.
	 *
	 * @var string
	 */
	public readonly string $post_type;

	/**
	 * Requested formats, in order and without duplicates.
	 *
	 * @var list<string>
	 */
	public readonly array $formats;

	/**
	 * Per-format options keyed by option name.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $format_options;

	/**
	 * Build a job.
	 *
	 * @param string               $language       Language code, empty for all languages.
	 * @param string               $post_status    Post status.
	 * @param string               $post_type      Post type.
	 * @param array<mixed>         $formats        Format names.
	 * @param array<string, mixed> $format_options Per-format options.
	 */
	public function __construct(
		string $language = '',
		string $post_status = 'publish',
		string $post_type = 'page',
		array $formats = array(),
		array $format_options = array()
	) {
		$this->language       = self::clean_language( $language );
		$this->post_status    = sanitize_key( $post_status );
		$this->post_type      = sanitize_key( $post_type );
		$this->formats        = self::clean_formats( $formats );
		$this->format_options = self::clean_format_options( $format_options );
	}

	/**
	 * Build a job from loosely typed input such as CLI arguments.
	 *
	 * Formats may be a list or a comma separated string.
	 *
	 * @param array<string, mixed> $input Keys: language, post_status, post_type, formats, format_options.
	 * @return self
	 */
	public static function from_array( array $input ): self {
		$formats = $input['formats'] ?? array();
		if ( is_string( $formats ) ) {
			$formats = explode( ',', $formats );
		}

		$format_options = $input['format_options'] ?? array();

		return new self(
			self::scalar_string( $input['language'] ?? '', '' ),
			self::scalar_string( $input['post_status'] ?? null, 'publish' ),
			self::scalar_string( $input['post_type'] ?? null, 'page' ),
			is_array( $formats ) ? $formats : array(),
			is_array( $format_options ) ? $format_options : array()
		);
	}

	/**
	 * Turn a scalar into a string, falling back when it is missing.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Value used for null, arrays, objects and booleans.
	 * @return string
	 */
	private static function scalar_string( mixed $value, string $fallback ): string {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return $fallback;
		}

		return trim( (string) $value );
	}

	/**
	 * Lowercase and trim the language without dropping characters.
	 *
	 * Unusable codes stay non-empty so the core can still reject them as
	 * invalid instead of quietly exporting every language.
	 *
	 * @param string $language Raw language code.
	 * @return string
	 */
	private static function clean_language( string $language ): string {
		$language = strtolower( trim( sanitize_text_field( $language ) ) );

		return substr( $language, 0, self::MAX_LANGUAGE_LENGTH );
	}

	/**
	 * Sanitize format names, drop blanks and duplicates.
	 *
	 * @param array<mixed> $formats Raw format names.
	 * @return list<string>
	 */
	private static function clean_formats( array $formats ): array {
		$clean = array();
		foreach ( array_slice( $formats, 0, self::MAX_FORMATS ) as $format ) {
			if ( ! is_scalar( $format ) || is_bool( $format ) ) {
				continue;
			}
			$format = sanitize_key( trim( (string) $format ) );
			if ( '' === $format || in_array( $format, $clean, true ) ) {
				continue;
			}
			$clean[] = $format;
		}

		return $clean;
	}

	/**
	 * Keep only string-keyed options.
	 *
	 * @param array<mixed> $options Raw options.
	 * @return array<string, mixed>
	 */
	private static function clean_format_options( array $options ): array {
		$clean = array();
		foreach ( $options as $name => $value ) {
			if ( is_string( $name ) && '' !== $name ) {
				$clean[ $name ] = $value;
			}
		}

		return $clean;
	}
}
