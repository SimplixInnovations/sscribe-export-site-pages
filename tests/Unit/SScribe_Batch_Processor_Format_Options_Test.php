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
	 * Plain scalar values must be cast to strings (the storage contract).
	 */
	public function test_parse_format_options_coerces_scalars_to_strings(): void {
		$result = \SScribe_Batch_Processor::parse_format_options(
			array(
				'page_size'      => 'A4',
				'include_images' => '1',
				'orientation'    => 0, // numeric must coerce, not error.
			)
		);

		$this->assertSame(
			array(
				'page_size'      => 'A4',
				'include_images' => '1',
				'orientation'    => '0',
			),
			$result
		);
	}

	/**
	 * Keys must be sanitized via sanitize_key() — uppercase, spaces, and
	 * unsafe characters must be normalized; empty keys must be dropped.
	 */
	public function test_parse_format_options_sanitizes_keys(): void {
		$result = \SScribe_Batch_Processor::parse_format_options(
			array(
				'Page Size'     => 'A4',
				'evil/key;'     => 'x',
				''              => 'dropped',
				'  spaced key' => 'y',
			)
		);

		// sanitize_key() lowercases + strips anything not [a-z0-9_-].
		$this->assertArrayHasKey( 'pagesize', $result );
		$this->assertArrayHasKey( 'evilkey', $result );
		$this->assertArrayNotHasKey( '', $result );
		$this->assertArrayHasKey( 'spacedkey', $result );
		$this->assertArrayNotHasKey( '  spaced key', $result );
	}

	/**
	 * Array values must have every element coerced to string; nested
	 * arrays must flatten to string('') for non-scalar children to keep
	 * the storage shape predictable.
	 */
	public function test_parse_format_options_handles_array_values(): void {
		$result = \SScribe_Batch_Processor::parse_format_options(
			array(
				'items'   => array( 'a', 'b', 1, 0, '1' ),
				'nested'  => array( array( 'deep' => 'x' ) ),
				'objects' => array( new \stdClass() ),
			)
		);

		$this->assertSame( array( 'a', 'b', '1', '0', '1' ), $result['items'] );
		// Non-scalar nested → string('') so the structure stays flat.
		$this->assertSame( array( '' ), $result['nested'] );
		$this->assertSame( array( '' ), $result['objects'] );
	}

	/**
	 * Top-level object values must be coerced to string('') — never
	 * serialized or stored as-is.
	 */
	public function test_parse_format_options_rejects_object_values(): void {
		$result = \SScribe_Batch_Processor::parse_format_options(
			array( 'bad' => new \stdClass() )
		);

		$this->assertSame( '', $result['bad'] );
	}

	/**
	 * Empty input must produce an empty result (no notices, no warnings).
	 */
	public function test_parse_format_options_handles_empty_array(): void {
		$this->assertSame( array(), \SScribe_Batch_Processor::parse_format_options( array() ) );
	}
}
