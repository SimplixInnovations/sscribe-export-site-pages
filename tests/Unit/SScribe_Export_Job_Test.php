<?php
/**
 * Unit tests for SScribe_Export_Job.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Export_Job_Test extends TestCase {

	public function test_constructor_keeps_clean_values(): void {
		$job = new \SScribe_Export_Job( 'en', 'publish', 'page', array( 'docx', 'pdf' ), array( 'sscribe_docx_include_toc' => '1' ) );

		$this::assertSame( 'en', $job->language );
		$this::assertSame( 'publish', $job->post_status );
		$this::assertSame( 'page', $job->post_type );
		$this::assertSame( array( 'docx', 'pdf' ), $job->formats );
		$this::assertSame( array( 'sscribe_docx_include_toc' => '1' ), $job->format_options );
	}

	public function test_defaults_match_the_admin_screen(): void {
		$job = new \SScribe_Export_Job();

		$this::assertSame( '', $job->language );
		$this::assertSame( 'publish', $job->post_status );
		$this::assertSame( 'page', $job->post_type );
		$this::assertSame( array(), $job->formats );
		$this::assertSame( array(), $job->format_options );
	}

	public function test_scalars_are_lowercased_and_sanitized(): void {
		$job = new \SScribe_Export_Job( ' FR ', 'Draft', 'My Type!', array( 'DOCX', ' Markdown ' ) );

		$this::assertSame( 'fr', $job->language );
		$this::assertSame( 'draft', $job->post_status );
		$this::assertSame( 'mytype', $job->post_type );
		$this::assertSame( array( 'docx', 'markdown' ), $job->formats );
	}

	public function test_language_with_unusable_characters_stays_non_empty(): void {
		$job = new \SScribe_Export_Job( '%%%' );

		$this::assertSame( '%%%', $job->language );
	}

	public function test_formats_are_deduplicated_and_unknown_ones_kept(): void {
		$job = new \SScribe_Export_Job( '', 'publish', 'page', array( 'docx', 'DOCX', 'epub', '', true, array( 'pdf' ), 'epub' ) );

		$this::assertSame( array( 'docx', 'epub' ), $job->formats );
	}

	public function test_format_options_drop_numeric_keys(): void {
		$job = new \SScribe_Export_Job( '', 'publish', 'page', array(), array( 'a' => '1', 0 => 'x' ) );

		$this::assertSame( array( 'a' => '1' ), $job->format_options );
	}

	public function test_from_array_splits_comma_separated_formats(): void {
		$job = \SScribe_Export_Job::from_array(
			array(
				'language'    => 'de',
				'post_status' => 'all',
				'post_type'   => 'post',
				'formats'     => 'docx, markdown,,html',
			)
		);

		$this::assertSame( 'de', $job->language );
		$this::assertSame( 'all', $job->post_status );
		$this::assertSame( 'post', $job->post_type );
		$this::assertSame( array( 'docx', 'markdown', 'html' ), $job->formats );
	}

	public function test_from_array_accepts_format_list_and_applies_defaults(): void {
		$job = \SScribe_Export_Job::from_array( array( 'formats' => array( 'pdf' ) ) );

		$this::assertSame( '', $job->language );
		$this::assertSame( 'publish', $job->post_status );
		$this::assertSame( 'page', $job->post_type );
		$this::assertSame( array( 'pdf' ), $job->formats );
	}

	public function test_from_array_ignores_wrongly_typed_values(): void {
		$job = \SScribe_Export_Job::from_array(
			array(
				'language'       => array( 'en' ),
				'post_status'    => false,
				'post_type'      => null,
				'formats'        => 42,
				'format_options' => 'nope',
			)
		);

		$this::assertSame( '', $job->language );
		$this::assertSame( 'publish', $job->post_status );
		$this::assertSame( 'page', $job->post_type );
		$this::assertSame( array(), $job->formats );
		$this::assertSame( array(), $job->format_options );
	}

	public function test_properties_cannot_be_changed(): void {
		$job = new \SScribe_Export_Job( 'en' );

		$this->expectException( \Error::class );

		$job->language = 'fr'; // @phpstan-ignore-line
	}
}
