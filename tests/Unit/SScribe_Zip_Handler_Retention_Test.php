<?php
/**
 * Unit tests for schedule tagging and per-row retention in SScribe_Zip_Handler.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Zip_Handler_Retention_Test extends TestCase {

	private \SScribe_Zip_Handler $handler;

	private string $export_dir = '';

	private mixed $saved_index = null;

	/** @var list<string> */
	private array $files = array();

	protected function setUp(): void {
		parent::setUp();
		$this->handler     = new \SScribe_Zip_Handler();
		$this->export_dir  = $this->handler->get_export_dir();
		$this->saved_index = $GLOBALS['sscribe_test_options']['sscribe_export_index'] ?? null;
		$GLOBALS['sscribe_test_options']['sscribe_export_index'] = array();
	}

	protected function tearDown(): void {
		foreach ( $this->files as $basename ) {
			$path = $this->export_dir . '/' . $basename;
			if ( is_file( $path ) ) {
				unlink( $path );
			}
			unset( $GLOBALS['sscribe_test_options'][ 'sscribe_export_row_' . md5( $basename ) ] );
		}
		if ( null === $this->saved_index ) {
			unset( $GLOBALS['sscribe_test_options']['sscribe_export_index'] );
		} else {
			$GLOBALS['sscribe_test_options']['sscribe_export_index'] = $this->saved_index;
		}
		unset( $GLOBALS['sscribe_test_update_option_failure'] );
		parent::tearDown();
	}

	public function test_tag_export_row_merges_only_schedule_fields(): void {
		$basename = $this->archive( 'tag', time() );

		$tagged = $this->handler->tag_export_row(
			$basename,
			array(
				'schedule_id'    => 'sch_0123456789ab',
				'schedule_label' => '<b>Nightly</b>',
				'retain_until'   => '1900000000',
				'user_id'        => 999,
				'dl_token'       => 'stolen',
			)
		);
		$row    = $this->handler->get_export_entry( $basename );

		$this::assertTrue( $tagged );
		$this::assertSame( 'sch_0123456789ab', $row['schedule_id'] ?? null );
		$this::assertSame( 'Nightly', $row['schedule_label'] ?? null );
		$this::assertSame( 1900000000, $row['retain_until'] ?? null );
		$this::assertSame( 7, $row['user_id'] ?? null );
		$this::assertSame( str_repeat( 'a', 32 ), $row['dl_token'] ?? null );
	}

	public function test_tag_export_row_refuses_missing_rows_and_unsafe_names(): void {
		$this::assertFalse( $this->handler->tag_export_row( 'missing-archive.zip', array( 'schedule_id' => 'sch_0123456789ab' ) ) );
		$this::assertFalse( $this->handler->tag_export_row( '../escape.zip', array( 'schedule_id' => 'sch_0123456789ab' ) ) );
	}

	public function test_tag_export_row_reports_a_failed_write(): void {
		$basename = $this->archive( 'write-fail', time() );
		$GLOBALS['sscribe_test_update_option_failure'] = 'sscribe_export_row_' . md5( $basename );

		$this::assertFalse( $this->handler->tag_export_row( $basename, array( 'schedule_id' => 'sch_0123456789ab' ) ) );
	}

	public function test_exports_for_schedule_lists_only_that_schedule_newest_first(): void {
		$older = $this->archive( 'older', time() - 200, array( 'schedule_id' => 'sch_0123456789ab' ) );
		$newer = $this->archive( 'newer', time() - 100, array( 'schedule_id' => 'sch_0123456789ab' ) );
		$this->archive( 'other', time() - 50, array( 'schedule_id' => 'sch_ffffffffffff' ) );
		$this->archive( 'manual', time() - 10 );

		$rows = $this->handler->exports_for_schedule( 'sch_0123456789ab' );

		$this::assertSame( array( $newer, $older ), array_column( $rows, 'basename' ) );
		$this::assertSame( array(), $this->handler->exports_for_schedule( '' ) );
	}

	public function test_cleanup_honours_retain_until_instead_of_the_fixed_age(): void {
		$old_but_retained = $this->archive( 'retained', time() - 10 * DAY_IN_SECONDS, array( 'retain_until' => time() + DAY_IN_SECONDS ) );
		$new_but_expired  = $this->archive( 'expired', time(), array( 'retain_until' => time() - 60 ) );
		$old_untagged     = $this->archive( 'untagged-old', time() - 4 * DAY_IN_SECONDS );
		$new_untagged     = $this->archive( 'untagged-new', time() - DAY_IN_SECONDS );

		$this->handler->cleanup_expired();

		$this::assertFileExists( $this->export_dir . '/' . $old_but_retained );
		$this::assertFileExists( $this->export_dir . '/' . $new_untagged );
		$this::assertFileDoesNotExist( $this->export_dir . '/' . $new_but_expired );
		$this::assertFileDoesNotExist( $this->export_dir . '/' . $old_untagged );
		$this::assertNull( $this->handler->get_export_entry( $new_but_expired ) );
		$this::assertSame(
			array( $old_but_retained, $new_untagged ),
			array_values( (array) get_option( 'sscribe_export_index', array() ) )
		);
	}

	/**
	 * @param array<string, mixed> $extra Extra row fields.
	 */
	private function archive( string $name, int $time, array $extra = array() ): string {
		$basename      = 'retention-' . $name . '-' . bin2hex( random_bytes( 4 ) ) . '.zip';
		$this->files[] = $basename;
		$path          = $this->export_dir . '/' . $basename;
		file_put_contents( $path, 'PK' );
		touch( $path, $time );

		$GLOBALS['sscribe_test_options'][ 'sscribe_export_row_' . md5( $basename ) ] = array_merge(
			array(
				'created_at' => $time,
				'user_id'    => 7,
				'session_id' => '',
				'dl_token'   => str_repeat( 'a', 32 ),
			),
			$extra
		);
		$GLOBALS['sscribe_test_options']['sscribe_export_index'][] = $basename;

		return $basename;
	}
}
