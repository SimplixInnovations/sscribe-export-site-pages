<?php
/**
 * Real-WordPress test for the headless export runner.
 *
 * Runs a whole export in-process, the way `wp sscribe export` does, and
 * checks the archive on disk and the export row it leaves behind.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

require_once __DIR__ . '/SScribe_WP_TestCase.php';

final class SScribe_Export_Runner_WP_Test extends SScribe_WP_TestCase {

	private int $admin_user_id = 0;

	private string $zip_filename = '';

	public function set_up(): void {
		parent::set_up();
		SScribe_Session::enable_test_mode();
		SScribe_Session::test_reset();

		SScribe_Activator::activate( false );

		$this->admin_user_id = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );
	}

	public function tear_down(): void {
		if ( '' !== $this->zip_filename ) {
			( new SScribe_Zip_Handler() )->delete_export( $this->zip_filename, $this->admin_user_id );
		}
		delete_transient( 'sscribe_active_sid_' . $this->admin_user_id );
		SScribe_Session::test_reset();
		SScribe_Session::disable_test_mode();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_runner_exports_two_pages_to_a_zip_owned_by_the_current_user(): void {
		self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Runner page one',
				'post_content' => '<p>First page body.</p>',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Runner page two',
				'post_content' => '<p>Second page body.</p>',
			)
		);

		$reports = array();
		$context = new SScribe_Headless_Export_Context(
			$this->admin_user_id,
			static function ( array $payload ) use ( &$reports ): void {
				$reports[] = $payload;
			}
		);
		$runner  = new SScribe_Export_Runner( new SScribe_Batch_Processor(), 1000, 0 );
		$job     = SScribe_Export_Job::from_array( array( 'formats' => 'docx,markdown' ) );

		$outcome = $runner->run( $job, $context );
		$payload = $outcome->payload();

		$this::assertTrue( $outcome->is_success(), 'Runner must finish successfully: ' . wp_json_encode( $payload ) );
		$this::assertSame( 'complete', $payload['status'] ?? '' );
		$this::assertSame( 2, (int) ( $payload['pages'] ?? 0 ) );
		$this::assertNotSame( '', $runner->last_session_id() );
		$this::assertNotEmpty( $reports, 'The context must receive progress payloads.' );

		$this->zip_filename = (string) ( $payload['filename'] ?? '' );
		$this::assertMatchesRegularExpression( '/\.zip$/', $this->zip_filename );

		$zip_path = ( new SScribe_Zip_Handler() )->get_export_dir() . '/' . $this->zip_filename;
		$this::assertFileExists( $zip_path );

		$zip = new ZipArchive();
		$this::assertTrue( true === $zip->open( $zip_path ), 'The archive must open.' );
		$this::assertNotFalse( $zip->locateName( SScribe_Export_Manifest::JSON_ENTRY ), 'The archive must contain manifest.json.' );
		$zip->close();

		$row = ( new SScribe_Zip_Handler() )->get_export_entry( $this->zip_filename );
		$this::assertIsArray( $row );
		$this::assertSame( $this->admin_user_id, (int) ( $row['user_id'] ?? 0 ) );
	}

	public function test_runner_reports_no_pages_without_creating_a_session(): void {
		$runner = new SScribe_Export_Runner( new SScribe_Batch_Processor(), 1000, 0 );
		$job    = SScribe_Export_Job::from_array(
			array(
				'formats'   => 'markdown',
				'post_type' => 'page',
			)
		);

		$outcome = $runner->run( $job, new SScribe_Headless_Export_Context( $this->admin_user_id ) );

		$this::assertFalse( $outcome->is_success() );
		$this::assertSame( 'no_pages_selected', $outcome->code() );
		$this::assertSame( '', $runner->last_session_id() );
	}
}
