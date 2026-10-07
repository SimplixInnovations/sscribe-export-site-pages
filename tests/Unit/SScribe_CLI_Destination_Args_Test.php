<?php
/**
 * Unit tests for the --destination options of the WP-CLI commands.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_CLI_Destination_Args_Test extends TestCase {

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sscribe-cli-dest-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir, 0700, true );
		unset( $GLOBALS['sscribe_test_options'][ \SScribe_Secret_Store::KEY_OPTION ] );
	}

	protected function tearDown(): void {
		@rmdir( $this->dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		unset( $GLOBALS['sscribe_test_options'][ \SScribe_Secret_Store::KEY_OPTION ] );
		parent::tearDown();
	}

	public function test_no_destination_option_means_no_delivery(): void {
		$this::assertNull( \SScribe_CLI_Destination_Args::parse( array() ) );
	}

	public function test_directory_destination_is_parsed_and_validated(): void {
		$parsed = \SScribe_CLI_Destination_Args::parse(
			array(
				'destination'          => 'Directory',
				'destination-settings' => (string) wp_json_encode(
					array(
						'path'      => $this->dir,
						'overwrite' => true,
					)
				),
			)
		);

		$this::assertSame( 'directory', $parsed['id'] ?? '' );
		$this::assertSame( realpath( $this->dir ), $parsed['settings']['path'] );
		$this::assertTrue( $parsed['settings']['overwrite'] );
	}

	public function test_bad_input_is_rejected_with_a_validation_error(): void {
		$cases = array(
			array( 'destination-settings' => '{}' ),
			array( 'destination' => 'ftp' ),
			array(
				'destination'          => 'directory',
				'destination-settings' => 'not json',
			),
			array(
				'destination'          => 'directory',
				'destination-settings' => '["a"]',
			),
			array(
				'destination'          => 'directory',
				'destination-settings' => '{"path":"relative"}',
			),
			array(
				'destination'          => 's3',
				'destination-settings' => '{"bucket":"b","region":"us-east-1","access_key":"AKIA1234","secret_key":"x","endpoint":"http://insecure.example"}',
			),
		);

		foreach ( $cases as $args ) {
			try {
				\SScribe_CLI_Destination_Args::parse( $args );
				$this::fail( 'Expected a validation error for ' . wp_json_encode( $args ) );
			} catch ( \SScribe_Validation_Exception $e ) {
				$this::assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_schema_rows_describe_fields_without_values(): void {
		$rows = \SScribe_CLI_Destination_Args::schema_rows();
		$ids  = array_column( $rows, 'id' );

		$this::assertSame( array( 'directory', 's3' ), $ids );
		$this::assertSame( array( 'id', 'label', 'settings' ), array_keys( $rows[1] ) );
		$this::assertStringContainsString( 'secret_key (password, required)', $rows[1]['settings'] );
		$this::assertStringContainsString( 'path_style (checkbox)', $rows[1]['settings'] );
	}

	public function test_schedule_add_stores_the_destination_with_the_secret_sealed(): void {
		$schedule = \SScribe_CLI_Schedule_Command::schedule_from_args(
			array(
				'label'                => 'Offsite',
				'frequency'            => 'daily',
				'destination'          => 's3',
				'destination-settings' => '{"bucket":"backups","region":"eu-west-1","access_key":"AKIA12345678","secret_key":"very-secret","path_style":true}',
			),
			7,
			time()
		);

		$this::assertCount( 1, $schedule->destinations );
		$this::assertSame( 's3', $schedule->destinations[0]['id'] );
		$this::assertTrue( $schedule->destinations[0]['settings']['path_style'] );
		$this::assertStringNotContainsString( 'very-secret', (string) wp_json_encode( $schedule->to_array() ) );
		$this::assertSame( 'very-secret', \SScribe_Destination_Settings::open_secrets( 's3', $schedule->destinations[0]['settings'] )['secret_key'] );
	}

	public function test_schedule_add_without_destination_keeps_an_empty_list(): void {
		$schedule = \SScribe_CLI_Schedule_Command::schedule_from_args(
			array(
				'label'     => 'Local',
				'frequency' => 'daily',
			),
			7,
			time()
		);

		$this::assertSame( array(), $schedule->destinations );
	}
}
