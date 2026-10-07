<?php
/**
 * Real-WordPress test for scheduled exports.
 *
 * Runs a schedule end to end against the real export pipeline, then runs
 * it again incrementally and checks that only the edited page is exported.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

require_once __DIR__ . '/SScribe_WP_TestCase.php';

final class SScribe_Scheduler_WP_Test extends SScribe_WP_TestCase {

	private int $admin_user_id = 0;

	/** @var list<string> */
	private array $zip_filenames = array();

	public function set_up(): void {
		parent::set_up();
		SScribe_Session::enable_test_mode();
		SScribe_Session::test_reset();

		if ( defined( 'SSCRIBE_PRIVATE_STORAGE_DIR' ) ) {
			wp_mkdir_p( (string) constant( 'SSCRIBE_PRIVATE_STORAGE_DIR' ) );
		}

		SScribe_Activator::activate( false );
		delete_option( SScribe_Schedule_Store::OPTION );

		$this->admin_user_id = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$zip = new SScribe_Zip_Handler();
		foreach ( $this->zip_filenames as $filename ) {
			$zip->delete_export( $filename, $this->admin_user_id );
		}
		delete_option( SScribe_Schedule_Store::OPTION );
		delete_transient( 'sscribe_active_sid_' . $this->admin_user_id );
		SScribe_Scheduler::unschedule_all();
		SScribe_Session::test_reset();
		SScribe_Session::disable_test_mode();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_schedule_run_exports_tags_the_archive_and_then_exports_only_changed_pages(): void {
		$kept_id   = $this->page( 'Scheduled page one', '<p>First page body.</p>' );
		$edited_id = $this->page( 'Scheduled page two', '<p>Second page body.</p>' );

		$store    = new SScribe_Schedule_Store();
		$schedule = SScribe_Schedule::from_array(
			array(
				'label'         => 'Nightly docs',
				'frequency'     => 'daily',
				'hour'          => 2,
				'formats'       => 'docx,markdown',
				'owner_user_id' => $this->admin_user_id,
				'incremental'   => true,
				'next_run_at'   => time() + DAY_IN_SECONDS,
			)
		);
		$this::assertTrue( $store->save( $schedule ) );

		$first   = $this->scheduler( $store )->run( $schedule->id, true );
		$payload = $first->payload();

		$this::assertTrue( $first->is_success(), 'The scheduled run must finish: ' . wp_json_encode( $payload ) );
		$this::assertSame( 2, (int) ( $payload['pages'] ?? 0 ) );

		$first_zip             = (string) ( $payload['filename'] ?? '' );
		$this->zip_filenames[] = $first_zip;
		$this::assertFileExists( ( new SScribe_Zip_Handler() )->get_export_dir() . '/' . $first_zip );

		$row = ( new SScribe_Zip_Handler() )->get_export_entry( $first_zip );
		$this::assertIsArray( $row );
		$this::assertSame( $schedule->id, $row['schedule_id'] ?? '' );
		$this::assertSame( 'Nightly docs', $row['schedule_label'] ?? '' );
		$this::assertGreaterThan( time(), (int) ( $row['retain_until'] ?? 0 ) );
		$this::assertSame( $this->admin_user_id, (int) ( $row['user_id'] ?? 0 ) );

		$stored = $store->get( $schedule->id );
		$this::assertNotNull( $stored );
		$this::assertSame( 'success', $stored->last_run_status );
		$this::assertSame( $first_zip, $stored->last_run_file );
		$this::assertSame( '', $stored->running_session );
		$this::assertGreaterThan( time(), $stored->next_run_at );
		$this::assertGreaterThan( 0, $stored->last_run_at );

		$watermark = time() - 100;
		$this::assertTrue( $store->save( $stored->with( array( 'last_run_at' => $watermark ) ) ) );
		$this->set_modified( $kept_id, $watermark - 100 );
		wp_update_post(
			array(
				'ID'           => $edited_id,
				'post_content' => '<p>Second page body, edited.</p>',
			)
		);
		delete_transient( 'sscribe_active_sid_' . $this->admin_user_id );
		wp_set_current_user( 0 );

		$second  = $this->scheduler( $store )->run( $schedule->id, true );
		$payload = $second->payload();

		$this::assertTrue( $second->is_success(), 'The incremental run must finish: ' . wp_json_encode( $payload ) );
		$second_zip            = (string) ( $payload['filename'] ?? '' );
		$this->zip_filenames[] = $second_zip;
		$this::assertNotSame( $first_zip, $second_zip );
		$this::assertSame( array( $edited_id ), $this->manifest_post_ids( $second_zip ) );
		$this::assertSame( $second_zip, $store->get( $schedule->id )?->last_run_file );
	}

	private function scheduler( SScribe_Schedule_Store $store ): SScribe_Scheduler {
		return new SScribe_Scheduler( $store, new SScribe_Batch_Processor(), null, null, 0.0, 0 );
	}

	private function page( string $title, string $content ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			)
		);
	}

	private function set_modified( int $post_id, int $timestamp ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) ),
				'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', $timestamp ),
			),
			array( 'ID' => $post_id )
		);
		clean_post_cache( $post_id );
	}

	/**
	 * @return list<int>
	 */
	private function manifest_post_ids( string $zip_filename ): array {
		$zip = new ZipArchive();
		$this::assertTrue( true === $zip->open( ( new SScribe_Zip_Handler() )->get_export_dir() . '/' . $zip_filename ) );
		$json = $zip->getFromName( SScribe_Export_Manifest::JSON_ENTRY );
		$zip->close();
		$this::assertIsString( $json );

		$manifest = json_decode( $json, true );
		$this::assertIsArray( $manifest );

		$ids = array();
		foreach ( (array) ( $manifest['files'] ?? array() ) as $file ) {
			$id = (int) ( $file['post']['id'] ?? 0 );
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}
		sort( $ids );

		return array_values( $ids );
	}
}
