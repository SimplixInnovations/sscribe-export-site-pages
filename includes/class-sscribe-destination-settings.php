<?php
/**
 * SScribe Destination Settings
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
 * Shapes destination lists for storage, delivery and display.
 *
 * A destination list is a list of array( 'id' => ..., 'settings' => ... ).
 * Stored lists keep password fields sealed; they are opened only right
 * before delivery and masked anywhere they are shown.
 */
final class SScribe_Destination_Settings {

	public const MAX_DESTINATIONS = 5;
	public const MASK             = '********';

	private const MAX_VALUE_LENGTH = 2048;

	/**
	 * Check a destination list and seal its secrets.
	 *
	 * Only fields named in the destination's schema are kept. Values already
	 * sealed stay as they are, so a stored list can be checked again safely.
	 *
	 * @param mixed $destinations Raw list.
	 * @return list<array{id: string, settings: array<string, string|bool>}>
	 * @throws SScribe_Validation_Exception When the list or an entry is malformed or names an unknown destination.
	 */
	public static function for_storage( mixed $destinations ): array {
		if ( null === $destinations || '' === $destinations ) {
			return array();
		}
		if ( ! is_array( $destinations ) || ! array_is_list( $destinations ) ) {
			throw new SScribe_Validation_Exception( esc_html__( 'Destinations must be a list.', 'sscribe-export-site-pages' ), 'destinations', 'list' );
		}
		if ( count( $destinations ) > self::MAX_DESTINATIONS ) {
			throw new SScribe_Validation_Exception(
				esc_html(
					sprintf(
						/* translators: %d: Maximum number of destinations. */
						__( 'A schedule can have at most %d destinations.', 'sscribe-export-site-pages' ),
						self::MAX_DESTINATIONS
					)
				),
				'destinations',
				'max'
			);
		}

		$clean = array();
		foreach ( $destinations as $entry ) {
			$clean[] = self::clean_entry( $entry );
		}

		return $clean;
	}

	/**
	 * Settings with sealed password fields opened, ready for delivery.
	 *
	 * @param string               $id       Destination id.
	 * @param array<string, mixed> $settings Stored settings.
	 * @return array<string, mixed>
	 * @throws RuntimeException When a sealed secret cannot be opened.
	 */
	public static function open_secrets( string $id, array $settings ): array {
		foreach ( self::password_fields( $id ) as $name ) {
			$value = $settings[ $name ] ?? '';
			if ( is_string( $value ) && SScribe_Secret_Store::is_sealed( $value ) ) {
				$settings[ $name ] = SScribe_Secret_Store::open( $value );
			}
		}

		return $settings;
	}

	/**
	 * A destination list safe to show, with every password masked.
	 *
	 * @param array<int, mixed> $destinations Stored list.
	 * @return list<array{id: string, settings: array<string, mixed>}>
	 */
	public static function redact( array $destinations ): array {
		$redacted = array();
		foreach ( $destinations as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id       = sanitize_key( (string) ( $entry['id'] ?? '' ) );
			$settings = is_array( $entry['settings'] ?? null ) ? $entry['settings'] : array();
			foreach ( self::password_fields( $id ) as $name ) {
				if ( isset( $settings[ $name ] ) && '' !== $settings[ $name ] ) {
					$settings[ $name ] = self::MASK;
				}
			}
			$redacted[] = array(
				'id'       => $id,
				'settings' => $settings,
			);
		}

		return $redacted;
	}

	/**
	 * Check one entry of a destination list.
	 *
	 * @param mixed $entry Raw entry.
	 * @return array{id: string, settings: array<string, string|bool>}
	 * @throws SScribe_Validation_Exception When the entry is malformed.
	 */
	private static function clean_entry( mixed $entry ): array {
		if ( ! is_array( $entry ) || ! is_string( $entry['id'] ?? null ) ) {
			throw new SScribe_Validation_Exception( esc_html__( 'Each destination needs an id.', 'sscribe-export-site-pages' ), 'destinations', 'id' );
		}

		$id = $entry['id'];
		if ( sanitize_key( $id ) !== $id || ! array_key_exists( $id, SScribe_Destination_Registry::all() ) ) {
			throw new SScribe_Validation_Exception(
				esc_html(
					sprintf(
						/* translators: %s: Destination id. */
						__( 'Unknown export destination "%s". See wp sscribe destinations.', 'sscribe-export-site-pages' ),
						sanitize_key( $id )
					)
				),
				'destinations',
				'registered'
			);
		}

		$raw = $entry['settings'] ?? array();
		if ( ! is_array( $raw ) ) {
			throw new SScribe_Validation_Exception( esc_html__( 'Destination settings must be an object.', 'sscribe-export-site-pages' ), 'destinations', 'settings' );
		}

		$settings = array();
		foreach ( SScribe_Destination_Registry::schema( $id ) as $name => $field ) {
			$settings[ $name ] = self::clean_value( $name, $field['type'], $raw[ $name ] ?? null );
		}

		return array(
			'id'       => $id,
			'settings' => $settings,
		);
	}

	/**
	 * Check one setting value.
	 *
	 * @param string $name  Field name.
	 * @param string $type  Field type.
	 * @param mixed  $value Raw value.
	 * @return string|bool
	 * @throws SScribe_Validation_Exception When the value is not plain text.
	 */
	private static function clean_value( string $name, string $type, mixed $value ): string|bool {
		if ( 'checkbox' === $type ) {
			return is_bool( $value ) ? $value : true === filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		}
		if ( null === $value ) {
			return '';
		}
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			throw new SScribe_Validation_Exception( esc_html( self::bad_value_message( $name ) ), 'destinations', 'scalar' );
		}

		$text = trim( (string) $value );
		if ( strlen( $text ) > self::MAX_VALUE_LENGTH || 1 === preg_match( '/[\x00-\x1F\x7F]/', $text ) ) {
			throw new SScribe_Validation_Exception( esc_html( self::bad_value_message( $name ) ), 'destinations', 'text' );
		}
		if ( 'password' === $type && '' !== $text && ! SScribe_Secret_Store::is_sealed( $text ) ) {
			try {
				return SScribe_Secret_Store::seal( $text );
			} catch ( RuntimeException $e ) {
				throw new SScribe_Validation_Exception(
					esc_html__( 'The destination password could not be encrypted on this server.', 'sscribe-export-site-pages' ),
					'destinations',
					'seal',
					array(),
					$e // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception, never printed.
				);
			}
		}

		return $text;
	}

	/**
	 * Message for a setting that is not plain text.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	private static function bad_value_message( string $name ): string {
		return sprintf(
			/* translators: %s: Setting name. */
			__( 'The destination setting "%s" must be a single line of text.', 'sscribe-export-site-pages' ),
			$name
		);
	}

	/**
	 * Names of password fields of a destination.
	 *
	 * @param string $id Destination id.
	 * @return list<string>
	 */
	private static function password_fields( string $id ): array {
		$names = array();
		foreach ( SScribe_Destination_Registry::schema( $id ) as $name => $field ) {
			if ( 'password' === $field['type'] ) {
				$names[] = $name;
			}
		}

		return $names;
	}
}
