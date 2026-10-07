<?php
/**
 * SScribe Destination Registry
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
 * Knows which export destinations exist.
 *
 * Built-in destinations are listed by class name; other plugins add theirs
 * through the sscribe_destinations filter. A class that does not implement
 * SScribe_Destination_Interface, has a malformed id, or repeats an id that
 * is already taken is skipped and logged.
 */
final class SScribe_Destination_Registry {

	public const BUILT_INS = array(
		SScribe_Destination_Directory::class,
		SScribe_Destination_S3::class,
	);

	public const FIELD_TYPES = array( 'text', 'password', 'checkbox' );

	private const ID_PATTERN = '/^[a-z0-9_-]{2,32}$/D';

	/**
	 * Every usable destination class, keyed by id.
	 *
	 * @return array<string, class-string<SScribe_Destination_Interface>>
	 */
	public static function all(): array {
		$classes = apply_filters( 'sscribe_destinations', self::BUILT_INS );
		if ( ! is_array( $classes ) ) {
			self::logger()->warning( 'The sscribe_destinations filter did not return a list; using the built-in destinations' );
			$classes = self::BUILT_INS;
		}

		$registered = array();
		foreach ( $classes as $class_name ) {
			$id = self::usable_id( $class_name );
			if ( '' === $id ) {
				continue;
			}
			if ( isset( $registered[ $id ] ) ) {
				self::skip( $class_name, 'duplicate_id' );
				continue;
			}
			$registered[ $id ] = $class_name;
		}

		return $registered;
	}

	/**
	 * A destination by id.
	 *
	 * @param string $id Destination id.
	 * @return SScribe_Destination_Interface|null Null when no destination has that id.
	 */
	public static function get( string $id ): ?SScribe_Destination_Interface {
		$class_name = self::all()[ $id ] ?? null;
		if ( null === $class_name ) {
			return null;
		}

		try {
			return new $class_name();
		} catch ( \Throwable $e ) {
			self::logger()->warning(
				'Export destination could not be created',
				array(
					'destination' => $id,
					'exception'   => get_class( $e ),
				)
			);
			return null;
		}
	}

	/**
	 * The settings fields of a destination, with malformed fields left out.
	 *
	 * @param string $id Destination id.
	 * @return array<string, array{type: string, label: string, required: bool}>
	 */
	public static function schema( string $id ): array {
		$class_name = self::all()[ $id ] ?? null;
		if ( null === $class_name ) {
			return array();
		}

		$fields = array();
		foreach ( $class_name::settings_schema() as $name => $field ) {
			$type  = $field['type'] ?? '';
			$label = $field['label'] ?? '';
			if ( sanitize_key( $name ) !== $name || ! is_string( $type ) || ! in_array( $type, self::FIELD_TYPES, true ) ) {
				continue;
			}
			$fields[ $name ] = array(
				'type'     => $type,
				'label'    => is_string( $label ) && '' !== $label ? $label : $name,
				'required' => ! empty( $field['required'] ),
			);
		}

		return $fields;
	}

	/**
	 * The id of a class when it can be registered, or an empty string.
	 *
	 * @param mixed $class_name Entry from the filter.
	 * @return string
	 */
	private static function usable_id( mixed $class_name ): string {
		if ( ! is_string( $class_name ) || ! class_exists( $class_name ) ) {
			self::skip( $class_name, 'missing_class' );
			return '';
		}
		if ( ! is_subclass_of( $class_name, SScribe_Destination_Interface::class ) ) {
			self::skip( $class_name, 'missing_interface' );
			return '';
		}

		$id = $class_name::id();
		if ( 1 !== preg_match( self::ID_PATTERN, $id ) || sanitize_key( $id ) !== $id ) {
			self::skip( $class_name, 'invalid_id' );
			return '';
		}

		return $id;
	}

	/**
	 * Log a skipped registration.
	 *
	 * @param mixed  $class_name Entry from the filter.
	 * @param string $reason     Why it was skipped.
	 * @return void
	 */
	private static function skip( mixed $class_name, string $reason ): void {
		self::logger()->warning(
			'Export destination skipped',
			array(
				'class'  => is_string( $class_name ) ? substr( $class_name, 0, 100 ) : gettype( $class_name ),
				'reason' => $reason,
			)
		);
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
