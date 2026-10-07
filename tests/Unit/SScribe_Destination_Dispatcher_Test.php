<?php
/**
 * Unit tests for SScribe_Destination_Dispatcher.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Destination_Dispatcher_Test extends TestCase {

	private string $zip = '';

	/** @var array<int, mixed> */
	private array $saved_actions = array();

	protected function setUp(): void {
		parent::setUp();
		$this->saved_actions = (array) ( $GLOBALS['sscribe_test_actions'] ?? array() );
		$this->zip           = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sscribe-dispatch-' . bin2hex( random_bytes( 4 ) ) . '.zip';
		$zip                 = new \ZipArchive();
		$zip->open( $this->zip, \ZipArchive::CREATE );
		$zip->addFromString( 'manifest.json', (string) wp_json_encode( array( 'site' => array( 'name' => 'Example' ) ) ) );
		$zip->addFromString( 'page.md', '# Page' );
		$zip->close();
	}

	protected function tearDown(): void {
		$GLOBALS['sscribe_test_actions'] = $this->saved_actions;
		@unlink( $this->zip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		parent::tearDown();
	}

	public function test_failures_and_crashes_do_not_stop_later_destinations(): void {
		$ok       = new Dispatcher_Fake_Destination( \SScribe_Result::success( 'fake://done' ) );
		$failing  = new Dispatcher_Fake_Destination( \SScribe_Result::failure( 'Disk full.' ) );
		$crashing = new Dispatcher_Fake_Destination( null );
		$by_id    = array(
			'fail'  => $failing,
			'crash' => $crashing,
			'ok'    => $ok,
		);

		$results = ( new \SScribe_Destination_Dispatcher( static fn( string $id ) => $by_id[ $id ] ?? null ) )->deliver_all(
			$this->zip,
			array( 'filename' => 'a.zip' ),
			array(
				array( 'id' => 'fail' ),
				array( 'id' => 'crash' ),
				array( 'id' => 'missing' ),
				'not-an-entry',
				array(
					'id'       => 'ok',
					'settings' => array( 'x' => '1' ),
				),
			)
		);

		$this::assertSame(
			array(
				array(
					'id'      => 'fail',
					'ok'      => false,
					'message' => 'Disk full.',
				),
				array(
					'id'      => 'crash',
					'ok'      => false,
					'message' => 'The destination stopped with an unexpected error.',
				),
				array(
					'id'      => 'missing',
					'ok'      => false,
					'message' => 'No export destination has that id.',
				),
				array(
					'id'      => '',
					'ok'      => false,
					'message' => 'No export destination has that id.',
				),
				array(
					'id'      => 'ok',
					'ok'      => true,
					'message' => 'fake://done',
				),
			),
			$results
		);
		$this::assertSame( array( 'x' => '1' ), $ok->received_settings );
		$this::assertSame( $this->zip, $ok->received_path );
	}

	public function test_delivered_action_fires_once_with_every_result(): void {
		$seen = array();
		add_action(
			'sscribe_export_delivered',
			static function ( string $zip_path, array $context, array $results ) use ( &$seen ): void {
				$seen[] = array( $zip_path, $context['filename'], count( $results ) );
			},
			10,
			3
		);
		$fake = new Dispatcher_Fake_Destination( \SScribe_Result::success( 'x' ) );

		( new \SScribe_Destination_Dispatcher( static fn() => $fake ) )->deliver_all( $this->zip, array( 'filename' => 'a.zip' ), array( array( 'id' => 'a' ), array( 'id' => 'b' ) ) );

		$this::assertSame( array( array( $this->zip, 'a.zip', 2 ) ), $seen );
	}

	public function test_sealed_secrets_are_opened_before_delivery(): void {
		$stored = \SScribe_Destination_Settings::for_storage(
			array(
				array(
					'id'       => 's3',
					'settings' => array(
						'bucket'     => 'my-bucket',
						'secret_key' => 'plain-secret',
					),
				),
			)
		);
		$fake   = new Dispatcher_Fake_Destination( \SScribe_Result::success( 'x' ) );

		( new \SScribe_Destination_Dispatcher( static fn() => $fake ) )->deliver_all( $this->zip, array(), $stored );

		$this::assertSame( 'plain-secret', $fake->received_settings['secret_key'] );
	}

	public function test_context_reads_the_manifest_from_the_archive(): void {
		$context = \SScribe_Destination_Dispatcher::context_from_payload(
			$this->zip,
			array(
				'pages'      => 4,
				'errors'     => array( 'one' ),
				'session_id' => 'ABC123',
			),
			'sch_0123456789ab'
		);

		$this::assertSame( basename( $this->zip ), $context['filename'] );
		$this::assertSame( filesize( $this->zip ), $context['size'] );
		$this::assertSame( 4, $context['pages'] );
		$this::assertSame( 1, $context['errors'] );
		$this::assertSame( 'abc123', $context['session_id'] );
		$this::assertSame( 'sch_0123456789ab', $context['schedule_id'] );
		$this::assertSame( 'Example', $context['manifest']['site']['name'] );
	}

	public function test_archive_path_only_resolves_files_inside_the_directory(): void {
		$dir = dirname( $this->zip );

		$this::assertSame( realpath( $this->zip ), \SScribe_Destination_Dispatcher::archive_path( $dir, basename( $this->zip ) ) );
		$this::assertSame( '', \SScribe_Destination_Dispatcher::archive_path( $dir, '../' . basename( $this->zip ) ) );
		$this::assertSame( '', \SScribe_Destination_Dispatcher::archive_path( $dir, 'missing.zip' ) );
		$this::assertSame( '', \SScribe_Destination_Dispatcher::archive_path( '', basename( $this->zip ) ) );
	}

	public function test_summary_lists_each_destination(): void {
		$summary = \SScribe_Destination_Dispatcher::summarize(
			array(
				array(
					'id'      => 'directory',
					'ok'      => true,
					'message' => '/srv/x.zip',
				),
				array(
					'id'      => 's3',
					'ok'      => false,
					'message' => 'S3 rejected the upload with HTTP 403.',
				),
			)
		);

		$this::assertSame( 'directory: delivered; s3: failed (S3 rejected the upload with HTTP 403.)', $summary );
	}
}

final class Dispatcher_Fake_Destination implements \SScribe_Destination_Interface {

	/** @var array<string, mixed> */
	public array $received_settings = array();

	public string $received_path = '';

	public function __construct( private readonly ?\SScribe_Result $result ) {
	}

	public static function id(): string {
		return 'dispatcher-fake';
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
		$this->received_path     = $zip_path;
		$this->received_settings = $settings;
		if ( null === $this->result ) {
			throw new \RuntimeException( 'secret-should-not-be-logged' );
		}
		return $this->result;
	}
}
