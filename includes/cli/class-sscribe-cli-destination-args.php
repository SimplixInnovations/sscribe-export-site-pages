<?php
/**
 * SScribe WP-CLI Destination Arguments
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
 * Reads --destination and --destination-settings, and describes destinations.
 */
final class SScribe_CLI_Destination_Args {

	private const JSON_DEPTH = 4;

	/**
	 * The destination named on the command line, checked and normalized.
	 *
	 * Secrets come back in plain text; they are sealed when a schedule
	 * stores them and never stored for a one-off export.
	 *
	 * @param array<string, mixed> $assoc_args Options from the command line.
	 * @return array{id: string, settings: array<string, mixed>}|null Null when no destination was given.
	 * @throws SScribe_Validation_Exception When the id is unknown or the settings are not valid.
	 */
	public static function parse( array $assoc_args ): ?array {
		$id   = is_string( $assoc_args['destination'] ?? null ) ? strtolower( trim( $assoc_args['destination'] ) ) : '';
		$json = $assoc_args['destination-settings'] ?? null;

		if ( '' === $id ) {
			if ( null !== $json ) {
				throw new SScribe_Validation_Exception( esc_html__( '--destination-settings needs --destination=<id>.', 'sscribe-export-site-pages' ), 'destination', 'required' );
			}
			return null;
		}

		$destination = sanitize_key( $id ) === $id ? SScribe_Destination_Registry::get( $id ) : null;
		if ( null === $destination ) {
			throw new SScribe_Validation_Exception(
				esc_html(
					sprintf(
						/* translators: 1: Destination id, 2: Comma separated list of destination ids. */
						__( 'Unknown destination "%1$s". Use one of: %2$s.', 'sscribe-export-site-pages' ),
						sanitize_key( $id ),
						implode( ', ', array_keys( SScribe_Destination_Registry::all() ) )
					)
				),
				'destination',
				'registered'
			);
		}

		$checked = $destination->validate( self::decode_settings( $json ) );
		if ( $checked->is_failure() ) {
			throw new SScribe_Validation_Exception( esc_html( (string) $checked->get_error() ), 'destination-settings', 'invalid' );
		}

		return array(
			'id'       => $id,
			'settings' => (array) $checked->get_data(),
		);
	}

	/**
	 * One row per registered destination for `wp sscribe destinations`.
	 *
	 * @return list<array{id: string, label: string, settings: string}>
	 */
	public static function schema_rows(): array {
		$rows = array();
		foreach ( SScribe_Destination_Registry::all() as $id => $class_name ) {
			$fields = array();
			foreach ( SScribe_Destination_Registry::schema( $id ) as $name => $field ) {
				$fields[] = sprintf(
					'%1$s (%2$s%3$s)',
					$name,
					$field['type'],
					$field['required'] ? ', ' . __( 'required', 'sscribe-export-site-pages' ) : ''
				);
			}
			$rows[] = array(
				'id'       => $id,
				'label'    => $class_name::label(),
				'settings' => implode( '; ', $fields ),
			);
		}

		return $rows;
	}

	/**
	 * Decode --destination-settings.
	 *
	 * @param mixed $json Option value.
	 * @return array<string, mixed>
	 * @throws SScribe_Validation_Exception When the value is not a JSON object.
	 */
	private static function decode_settings( mixed $json ): array {
		if ( null === $json ) {
			return array();
		}

		$decoded = is_string( $json ) ? json_decode( $json, true, self::JSON_DEPTH ) : null;
		if ( ! is_array( $decoded ) || ( array() !== $decoded && array_is_list( $decoded ) ) ) {
			throw new SScribe_Validation_Exception(
				esc_html__( '--destination-settings must be a JSON object, for example {"path":"/srv/backups"}.', 'sscribe-export-site-pages' ),
				'destination-settings',
				'json'
			);
		}

		return $decoded;
	}
}
