<?php
/**
 * Unit tests for the argument parsing in SScribe_CLI_Command.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_CLI_Command_Test extends TestCase {

	public function test_class_loads_without_wp_cli(): void {
		$this::assertTrue( class_exists( \SScribe_CLI_Command::class ) );
	}

	public function test_defaults_export_every_format_for_published_pages(): void {
		$job = \SScribe_CLI_Command::job_from_args( array() );

		$this::assertSame( array( 'docx', 'pdf', 'html', 'markdown' ), $job->formats );
		$this::assertSame( 'page', $job->post_type );
		$this::assertSame( 'publish', $job->post_status );
		$this::assertSame( '', $job->language );
		$this::assertSame( array(), $job->format_options );
	}

	public function test_fields_and_compliance_flags_become_format_options(): void {
		$job = \SScribe_CLI_Command::job_from_args( array( 'fields' => 'ALL', 'compliance' => true ) );

		$this::assertSame( 'all', $job->format_options['sscribe_include_fields'] );
		$this::assertSame( '1', $job->format_options['sscribe_compliance_mode'] );
	}

	public function test_comma_separated_formats_are_split(): void {
		$job = \SScribe_CLI_Command::job_from_args( array( 'formats' => 'docx,markdown' ) );

		$this::assertSame( array( 'docx', 'markdown' ), $job->formats );
	}

	public function test_blank_formats_fall_back_to_every_format(): void {
		$job = \SScribe_CLI_Command::job_from_args( array( 'formats' => '  ' ) );

		$this::assertSame( array( 'docx', 'pdf', 'html', 'markdown' ), $job->formats );
	}

	public function test_dashed_options_map_to_job_fields(): void {
		$job = \SScribe_CLI_Command::job_from_args(
			array(
				'post-type'   => 'post',
				'post-status' => 'all',
				'language'    => 'FR',
			)
		);

		$this::assertSame( 'post', $job->post_type );
		$this::assertSame( 'all', $job->post_status );
		$this::assertSame( 'fr', $job->language );
	}

	public function test_unknown_format_passes_through_for_the_core_to_reject(): void {
		$job = \SScribe_CLI_Command::job_from_args( array( 'formats' => 'docx,epub' ) );

		$this::assertSame( array( 'docx', 'epub' ), $job->formats );
	}

	public function test_list_rows_are_sorted_and_skip_unsafe_names(): void {
		$rows = \SScribe_CLI_Command::list_rows(
			array(
				'old.zip'     => array(
					'created_at' => 100,
					'user_id'    => 0,
					'session_id' => 'aaaa',
				),
				'../evil.zip' => array( 'created_at' => 300 ),
				'new.zip'     => array(
					'created_at' => 200,
					'user_id'    => 0,
					'session_id' => 'bbbb',
				),
			),
			array(
				'bbbb' => array(
					'pages'  => 7,
					'errors' => 2,
				),
			)
		);

		$this::assertSame( array( 'new.zip', 'old.zip' ), array_column( $rows, 'filename' ) );
		$this::assertSame( 7, $rows[0]['pages'] );
		$this::assertSame( 2, $rows[0]['errors'] );
		$this::assertSame( 0, $rows[1]['pages'] );
		$this::assertSame( array( 'filename', 'created', 'size', 'pages', 'errors', 'owner' ), array_keys( $rows[0] ) );
	}
}
