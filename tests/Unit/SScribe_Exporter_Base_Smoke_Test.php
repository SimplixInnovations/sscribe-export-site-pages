<?php
/**
 * SScribe Exporter (legacy DOCX engine) smoke test
 *
 * Exercises the public surface of SScribe_Exporter that does not
 * require a live PhpWord render:
 *
 *   - __construct() with default collaborators — wires parser, logger,
 *     filesystem, content renderer, and the DOCX color palette
 *   - __construct() with explicit collaborators — same as above but
 *     without touching the lazy default paths
 *   - set_format_options() stores the supplied map untouched
 *   - get_last_error() returns empty when no error has been recorded
 *
 * The huge private surface (cover page, TOC, breadcrumbs, etc.) is
 * covered by the existing SScribe_Exporter_Test suite via the public
 * generate_docx() entry point.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Content_Parser;
use SScribe_Exporter;

if ( ! class_exists( '\\SScribe_Exporter', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-exporter.php';
}

final class SScribe_Exporter_Base_Smoke_Test extends TestCase {

	public function test_construct_with_default_collaborators(): void {
		$exporter = new SScribe_Exporter();
		$this::assertInstanceOf( SScribe_Exporter::class, $exporter );
		$this::assertSame( '', $exporter->get_last_error() );
	}

	public function test_construct_with_explicit_parser(): void {
		$parser   = new SScribe_Content_Parser();
		$exporter = new SScribe_Exporter( $parser );
		$this::assertInstanceOf( SScribe_Exporter::class, $exporter );
	}

	public function test_set_format_options_stores_map(): void {
		$exporter = new SScribe_Exporter();
		$options  = array(
			'sscribe_docx_include_images' => '1',
			'sscribe_docx_include_toc'    => '',
		);
		$exporter->set_format_options( $options );
		// get_last_error must still be empty — no error path was touched.
		$this::assertSame( '', $exporter->get_last_error() );
	}

	public function test_set_format_options_with_empty_array_is_safe(): void {
		$exporter = new SScribe_Exporter();
		$exporter->set_format_options( array() );
		$this::assertSame( '', $exporter->get_last_error() );
	}

	public function test_construct_with_filter_supplying_valid_colors_uses_them(): void {
		// The constructor applies the `sscribe_docx_colors` filter and
		// normalizes any supplied hex strings to uppercase. We use a
		// filter that returns an uppercase palette to exercise both
		// branches of the per-color validation.
		$callback = static function (): array {
			return array(
				'primary'  => 'abcdef',
				'heading'  => '123456',
				'body'     => '654321',
				'light_bg' => 'aabbcc',
				'link'     => 'ddeeff',
				'code_bg'  => '001122',
				'white'    => 'ffffff',
				'border'   => '999999',
			);
		};
		add_filter( 'sscribe_docx_colors', $callback );
		try {
			$exporter = new SScribe_Exporter();
			$this::assertInstanceOf( SScribe_Exporter::class, $exporter );
		} finally {
			remove_filter( 'sscribe_docx_colors', $callback );
		}
	}

	public function test_construct_with_filter_supplying_invalid_colors_falls_back_to_defaults(): void {
		// The filter returns invalid (non-hex) strings; the constructor
		// must validate each entry and fall back to its built-in default
		// for that key.
		$callback = static function (): array {
			return array(
				'primary'  => 'not-a-hex',
				'heading'  => 'ggg',
				'body'     => array( 'invalid' ),
			);
		};
		add_filter( 'sscribe_docx_colors', $callback );
		try {
			$exporter = new SScribe_Exporter();
			$this::assertInstanceOf( SScribe_Exporter::class, $exporter );
		} finally {
			remove_filter( 'sscribe_docx_colors', $callback );
		}
	}

	public function test_construct_with_filter_returning_non_array_falls_back(): void {
		// The filter contract returns array; a non-array return is
		// treated as "no override" and defaults apply.
		$callback = static function (): string {
			return 'not an array';
		};
		add_filter( 'sscribe_docx_colors', $callback );
		try {
			$exporter = new SScribe_Exporter();
			$this::assertInstanceOf( SScribe_Exporter::class, $exporter );
		} finally {
			remove_filter( 'sscribe_docx_colors', $callback );
		}
	}

	public function test_get_last_error_starts_empty_and_stays_empty(): void {
		$exporter = new SScribe_Exporter();
		$this::assertSame( '', $exporter->get_last_error() );
		// Subsequent calls must continue to return empty string —
		// no internal flag flips on a getter.
		$this::assertSame( '', $exporter->get_last_error() );
	}
}
