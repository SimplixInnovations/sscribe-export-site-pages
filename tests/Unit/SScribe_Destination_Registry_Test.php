<?php
/**
 * Unit tests for SScribe_Destination_Registry and SScribe_Destination_Settings.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Destination_Registry_Test extends TestCase {

	/** @var array<int, mixed> */
	private array $saved_filters = array();

	protected function setUp(): void {
		parent::setUp();
		$this->saved_filters = (array) ( $GLOBALS['sscribe_test_filters'] ?? array() );
		unset( $GLOBALS['sscribe_test_options'][ \SScribe_Secret_Store::KEY_OPTION ] );
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_filters'] = $this->saved_filters;
		unset( $GLOBALS['sscribe_test_options'][ \SScribe_Secret_Store::KEY_OPTION ] );
		parent::tearDown();
	}

	public function test_built_in_destinations_are_registered(): void {
		$all = \SScribe_Destination_Registry::all();

		$this::assertSame( \SScribe_Destination_Directory::class, $all['directory'] );
		$this::assertSame( \SScribe_Destination_S3::class, $all['s3'] );
		$this::assertInstanceOf( \SScribe_Destination_S3::class, \SScribe_Destination_Registry::get( 's3' ) );
		$this::assertNull( \SScribe_Destination_Registry::get( 'ftp' ) );
	}

	public function test_filter_adds_a_valid_destination(): void {
		add_filter( 'sscribe_destinations', static fn( array $classes ): array => array_merge( $classes, array( Registry_Fake_Destination::class ) ) );

		$this::assertSame( Registry_Fake_Destination::class, \SScribe_Destination_Registry::all()['fake-dest'] );
		$this::assertInstanceOf( Registry_Fake_Destination::class, \SScribe_Destination_Registry::get( 'fake-dest' ) );
	}

	public function test_filter_entries_that_are_not_destinations_are_skipped(): void {
		add_filter(
			'sscribe_destinations',
			static fn( array $classes ): array => array_merge(
				$classes,
				array( 'No_Such_Class', \stdClass::class, 42, Registry_Bad_Id_Destination::class, Registry_Duplicate_Destination::class )
			)
		);

		$all = \SScribe_Destination_Registry::all();

		$this::assertSame( array( 'directory', 's3' ), array_keys( $all ) );
		$this::assertSame( \SScribe_Destination_S3::class, $all['s3'] );
	}

	public function test_filter_returning_garbage_keeps_the_built_ins(): void {
		add_filter( 'sscribe_destinations', static fn(): string => 'nope' );

		$this::assertSame( array( 'directory', 's3' ), array_keys( \SScribe_Destination_Registry::all() ) );
	}

	public function test_schema_lists_typed_fields(): void {
		$schema = \SScribe_Destination_Registry::schema( 's3' );

		$this::assertSame( 'password', $schema['secret_key']['type'] );
		$this::assertSame( 'checkbox', $schema['path_style']['type'] );
		$this::assertTrue( $schema['bucket']['required'] );
		$this::assertSame( array(), \SScribe_Destination_Registry::schema( 'unknown' ) );
	}

	public function test_settings_for_storage_seal_passwords_and_drop_unknown_keys(): void {
		$stored = \SScribe_Destination_Settings::for_storage(
			array(
				array(
					'id'       => 's3',
					'settings' => array(
						'bucket'     => 'my-bucket',
						'secret_key' => 'top-secret-key',
						'path_style' => 'yes',
						'evil'       => 'dropped',
					),
				),
			)
		);

		$settings = $stored[0]['settings'];
		$this::assertArrayNotHasKey( 'evil', $settings );
		$this::assertTrue( $settings['path_style'] );
		$this::assertTrue( \SScribe_Secret_Store::is_sealed( (string) $settings['secret_key'] ) );
		$this::assertStringNotContainsString( 'top-secret-key', (string) wp_json_encode( $stored ) );
		$this::assertSame( $stored, \SScribe_Destination_Settings::for_storage( $stored ) );
		$this::assertSame( 'top-secret-key', \SScribe_Destination_Settings::open_secrets( 's3', $settings )['secret_key'] );
		$this::assertSame( \SScribe_Destination_Settings::MASK, \SScribe_Destination_Settings::redact( $stored )[0]['settings']['secret_key'] );
	}

	public function test_settings_for_storage_reject_unknown_ids_and_bad_shapes(): void {
		foreach ( array(
			array( array( 'id' => 'ftp' ) ),
			array( array( 'settings' => array() ) ),
			array( 'directory' => array( 'id' => 'directory' ) ),
			array( array( 'id' => 'directory', 'settings' => 'x' ) ),
			array( array( 'id' => 'directory', 'settings' => array( 'path' => array( 'nested' ) ) ) ),
			array( array( 'id' => 'directory', 'settings' => array( 'path' => "line\nbreak" ) ) ),
			array_fill( 0, \SScribe_Destination_Settings::MAX_DESTINATIONS + 1, array( 'id' => 'directory' ) ),
		) as $case ) {
			try {
				\SScribe_Destination_Settings::for_storage( $case );
				$this::fail( 'Expected a validation error for ' . wp_json_encode( $case ) );
			} catch ( \SScribe_Validation_Exception $e ) {
				$this::assertSame( 'destinations', $e->get_field() );
			}
		}
	}
}

final class Registry_Fake_Destination implements \SScribe_Destination_Interface {

	public static function id(): string {
		return 'fake-dest';
	}

	public static function label(): string {
		return 'Fake';
	}

	public static function settings_schema(): array {
		return array();
	}

	public function validate( array $settings ): \SScribe_Result {
		return \SScribe_Result::success( $settings );
	}

	public function deliver( string $zip_path, array $context, array $settings ): \SScribe_Result {
		return \SScribe_Result::success( 'fake://' . basename( $zip_path ) );
	}
}

final class Registry_Bad_Id_Destination implements \SScribe_Destination_Interface {

	public static function id(): string {
		return 'Bad Id!';
	}

	public static function label(): string {
		return 'Bad';
	}

	public static function settings_schema(): array {
		return array();
	}

	public function validate( array $settings ): \SScribe_Result {
		return \SScribe_Result::success( $settings );
	}

	public function deliver( string $zip_path, array $context, array $settings ): \SScribe_Result {
		return \SScribe_Result::failure( 'never' );
	}
}

final class Registry_Duplicate_Destination implements \SScribe_Destination_Interface {

	public static function id(): string {
		return 's3';
	}

	public static function label(): string {
		return 'Impostor';
	}

	public static function settings_schema(): array {
		return array();
	}

	public function validate( array $settings ): \SScribe_Result {
		return \SScribe_Result::success( $settings );
	}

	public function deliver( string $zip_path, array $context, array $settings ): \SScribe_Result {
		return \SScribe_Result::failure( 'never' );
	}
}
