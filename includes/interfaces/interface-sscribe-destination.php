<?php
/**
 * SScribe Destination Interface
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
 * A place a finished export archive can be sent to.
 *
 * Implementations need a constructor without required arguments and are
 * registered by class name through the sscribe_destinations filter.
 */
interface SScribe_Destination_Interface {

	/**
	 * Stable id, 2 to 32 lower-case letters, digits, dashes or underscores.
	 *
	 * @return string
	 */
	public static function id(): string;

	/**
	 * Name shown to people.
	 *
	 * @return string
	 */
	public static function label(): string;

	/**
	 * Settings the destination takes, keyed by field name.
	 *
	 * Each field is an array with "type" (text, password or checkbox),
	 * "label" and "required". Password fields are encrypted at rest.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings_schema(): array;

	/**
	 * Check settings and return them normalized.
	 *
	 * @param array<string, mixed> $settings Settings with secrets in plain text.
	 * @return SScribe_Result Normalized settings as data, or a translated error.
	 */
	public function validate( array $settings ): SScribe_Result;

	/**
	 * Send an archive.
	 *
	 * @param string               $zip_path Absolute path of the archive.
	 * @param array<string, mixed> $context  filename, size, pages, errors, session_id, schedule_id and manifest.
	 * @param array<string, mixed> $settings Settings with secrets in plain text.
	 * @return SScribe_Result Where the archive went as data, or a translated error.
	 */
	public function deliver( string $zip_path, array $context, array $settings ): SScribe_Result;
}
