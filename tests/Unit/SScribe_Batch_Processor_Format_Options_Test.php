<?php
/**
 * SScribe Batch Processor format_options parsing test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Batch_Processor_Format_Options_Test extends TestCase {

	/**
	 * Non-array input (null, string, scalar) must return an empty array
	 * rather than erroring or leaking through unfiltered.
	 */
	public function test_parse_format_options_rejects_non_array_input(): void {
		$this->assertSame( array(), \SScribe_Batch_Processor::parse_format_options( null ) );
		$this->assertSame( array(), \SScribe_Batch_Processor::parse_format_options( 'not an array' ) );
		$this->assertSame( array(), \SScribe_Batch_Processor::parse_format_options( 42 ) );
		$this->assertSame( array(), \SScribe_Batch_Processor::parse_format_options( new \stdClass() ) );
	}

	/**
	 * Plain scalar values must be sanitized via sanitize_text_field().
	 * Numeric inputs must coerce to their string form without erroring.
	 */
	public function test_parse_format_options_sanitizes_scalar_values(): void {
		$result = \SScribe_Batch_Processor::parse_format_options(
			array(
				'sscribe_pdf_page_size'        => 'A4',
				'sscribe_pdf_include_images'   => '1',
				'sscribe_pdf_include_page_numbers' => 1,
			)
		);

		$this->assertSame(
			array(
				'sscribe_pdf_page_size'        => 'A4',
				'sscribe_pdf_include_images'   => '1',
				'sscribe_pdf_include_page_numbers' => '1',
			),
			$result
		);
	}

	/**
	 * Keys must be sanitized via sanitize_key() — uppercase, spaces, and
	 * unsafe characters must be normalized; empty keys must be dropped.
	 * Keys not on the FORMAT_OPTION_KEYS allowlist are also dropped.
	 */
	public function test_parse_format_options_sanitizes_and_allowlists_keys(): void {
		$result = \SScribe_Batch_Processor::parse_format_options(
			array(
				'SSCRIBE_PDF_PAGE_SIZE' => 'A4',
				'evil/key;'             => 'x',
				''                      => 'dropped',
				'  spaced key'         => 'y',
				'unknown_random_key'    => 'dropped-by-allowlist',
			)
		);

		// sanitize_key() lowercases + strips anything not [a-z0-9_-].
		// 'SSCRIBE_PDF_PAGE_SIZE' normalizes to 'sscribe_pdf_page_size' (on allowlist).
		$this->assertArrayHasKey( 'sscribe_pdf_page_size', $result );
		// 'evil/key;' normalizes to 'evilkey' (NOT on allowlist, dropped).
		$this->assertArrayNotHasKey( 'evilkey', $result );
		// Empty key dropped.
		$this->assertArrayNotHasKey( '', $result );
		// '  spaced key' normalizes to 'spacedkey' (NOT on allowlist, dropped).
		$this->assertArrayNotHasKey( 'spacedkey', $result );
		// 'unknown_random_key' is on no allowlist, dropped.
		$this->assertArrayNotHasKey( 'unknown_random_key', $result );
	}

	/**
	 * Array values must have every element sanitized via
	 * sanitize_text_field(); nested arrays must flatten to string('')
	 * for non-scalar children to keep the storage shape predictable.
	 * Keys must be on the FORMAT_OPTION_KEYS allowlist.
	 */
	public function test_parse_format_options_handles_array_values(): void {
		$result = \SScribe_Batch_Processor::parse_format_options(
			array(
				// sscribe_docx_colors is a comma-separated colour list,
				// passed as an array from the admin UI.
				'sscribe_docx_colors'     => array( 'red', 'blue', '#FF0000' ),
				// sscribe_docx_section_settings is a nested-array option
				// where each leaf should be sanitized or collapsed.
				'sscribe_docx_section_settings' => array(
					array( 'left' => '100pt' ),
					'normal',
				),
			)
		);

		$this->assertSame(
			array( 'red', 'blue', '#FF0000' ),
			$result['sscribe_docx_colors']
		);
		// Non-scalar nested → string('') so the structure stays flat.
		$this->assertSame(
			array( '', 'normal' ),
			$result['sscribe_docx_section_settings']
		);
	}

	/**
	 * Top-level object values must be coerced to string('') — never
	 * serialized or stored as-is. Keys must be on the allowlist.
	 */
	public function test_parse_format_options_rejects_object_values(): void {
		$result = \SScribe_Batch_Processor::parse_format_options(
			array( 'sscribe_pdf_page_size' => new \stdClass() )
		);

		$this->assertSame( '', $result['sscribe_pdf_page_size'] );
	}

	/**
	 * Keys outside the FORMAT_OPTION_KEYS allowlist must be silently
	 * dropped, even if they sanitize_key-normalize to a valid shape.
	 * This blocks attacker-controlled keys from reaching filters or
	 * exporters that validate against an unrelated allowlist.
	 */
	public function test_parse_format_options_drops_unknown_keys(): void {
		$result = \SScribe_Batch_Processor::parse_format_options(
			array(
				'sscribe_pdf_page_size' => 'A4',
				'unknown_xyz'           => 'dropped',
				'inject_<script>'        => 'dropped',
			)
		);

		$this->assertArrayHasKey( 'sscribe_pdf_page_size', $result );
		$this->assertArrayNotHasKey( 'unknown_xyz', $result );
		$this->assertArrayNotHasKey( 'inject_script', $result );
	}

	/**
	 * Empty input must produce an empty result (no notices, no warnings).
	 */
	public function test_parse_format_options_handles_empty_array(): void {
		$this->assertSame( array(), \SScribe_Batch_Processor::parse_format_options( array() ) );
	}

	/**
	 * Regression: the Markdown exporter's three admin checkboxes
	 * (absolute_urls, include_featured_image, include_frontmatter) were
	 * silently dropped by the FORMAT_OPTION_KEYS allowlist in an earlier
	 * WP.org review pass. The admin UI sent the keys, the allowlist
	 * dropped them, and the exporter fell back to defaults — so the
	 * "Include frontmatter" / "Include featured image" / "Use absolute
	 * URLs" toggles had no effect on the generated .md output. This test
	 * pins all three keys on the allowlist so a future refactor that
	 * drops them will fail CI.
	 */
	public function test_parse_format_options_preserves_markdown_keys(): void {
		$result = \SScribe_Batch_Processor::parse_format_options(
			array(
				'sscribe_md_absolute_urls'         => '1',
				'sscribe_md_include_featured_image' => '1',
				'sscribe_md_include_frontmatter'   => '0',
			)
		);

		$this->assertArrayHasKey( 'sscribe_md_absolute_urls', $result );
		$this->assertArrayHasKey( 'sscribe_md_include_featured_image', $result );
		$this->assertArrayHasKey( 'sscribe_md_include_frontmatter', $result );
		$this->assertSame( '1', $result['sscribe_md_absolute_urls'] );
		$this->assertSame( '1', $result['sscribe_md_include_featured_image'] );
		$this->assertSame( '0', $result['sscribe_md_include_frontmatter'] );
	}
}
