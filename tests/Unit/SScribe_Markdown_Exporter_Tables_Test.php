<?php
/**
 * SScribe Markdown Exporter — convert_tables() regression test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_Markdown_Exporter_Tables_Test extends TestCase {

	/**
	 * Invoke the private convert_tables() method via a scoped callable.
	 */
	private function call_convert_tables( string $html ): string {
		$method = \Closure::bind(
			function ( string $html ) {
				$exporter = new \SScribe_Markdown_Exporter();
				return $exporter->convert_tables( $html ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PrivateMethodFound
			},
			null,
			\SScribe_Markdown_Exporter::class
		);
		return $method( $html );
	}

	/**
	 * Invoke the complete private HTML-to-Markdown pipeline.
	 */
	private function call_html_to_markdown( string $html ): string {
		$method = \Closure::bind(
			function ( string $document ) {
				$exporter = new \SScribe_Markdown_Exporter();
				return $exporter->html_to_markdown( $document ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PrivateMethodFound
			},
			null,
			\SScribe_Markdown_Exporter::class
		);

		return $method( $html );
	}

	public function test_list_conversion_preserves_surrounding_text_nodes(): void {
		$out = $this->call_html_to_markdown( 'Before<ul><li>One</li><li>Two</li></ul>After' );

		$this->assertStringContainsString( 'Before', $out );
		$this->assertStringContainsString( '- One', $out );
		$this->assertStringContainsString( '- Two', $out );
		$this->assertStringContainsString( 'After', $out );
	}

	public function test_list_conversion_handles_nested_container_and_sublist(): void {
		$html = '<div><p>Intro</p><ul><li>Parent<ul><li>Child</li></ul></li></ul><p>Outro</p></div>';
		$out  = $this->call_html_to_markdown( $html );

		$this->assertStringContainsString( 'Intro', $out );
		$this->assertMatchesRegularExpression( '/^- Parent$/m', $out );
		$this->assertMatchesRegularExpression( '/^  - Child$/m', $out );
		$this->assertStringContainsString( 'Outro', $out );
	}

	public function test_code_fence_expands_when_content_contains_backticks(): void {
		$out = $this->call_html_to_markdown( '<pre><code>before ``` after</code></pre>' );

		$this->assertStringContainsString( "````\nbefore ``` after\n````", $out );
	}

	public function test_unsafe_urls_and_data_images_are_removed(): void {
		$out = $this->call_html_to_markdown(
			'<a href="javascript:alert(1)">Unsafe</a><img src="data:image/png;base64,AAAA" alt="Embedded">'
		);

		$this->assertStringContainsString( '[Unsafe](#)', $out );
		$this->assertStringNotContainsString( 'data:image', $out );
		$this->assertStringNotContainsString( 'Embedded', $out );
	}

	public function test_details_are_converted_to_plain_markdown(): void {
		$out = $this->call_html_to_markdown( '<details open><summary>More</summary><p>Body</p></details>' );

		$this->assertStringContainsString( '**More**', $out );
		$this->assertStringContainsString( 'Body', $out );
		$this->assertStringNotContainsString( '<details', $out );
	}

	/**
	 * Regression: tables with no merged cells must convert to a normal
	 * GFM table (separator row, aligned columns).
	 */
	public function test_simple_table_converts_to_gfm(): void {
		$html  = '<table><tr><th>Name</th><th>Value</th></tr><tr><td>foo</td><td>1</td></tr></table>';
		$out   = $this->call_convert_tables( $html );

		$this->assertStringContainsString( '| Name | Value |', $out );
		$this->assertStringContainsString( '| :--- | :--- |', $out );
		$this->assertStringContainsString( '| foo | 1 |', $out );
	}

	/**
	 * Regression: tables with colspan cannot be losslessly converted to
	 * GFM (which has no merged-cell support). Before the fix, the
	 * converter silently produced a broken table where a colspan="2"
	 * header row had fewer cells than the data rows, misaligning the
	 * GFM separator. The fix detects colspan/rowspan and emits a plain-
	 * text placeholder with an HTML comment so the user knows the table
	 * needs manual review.
	 */
	public function test_table_with_colspan_emits_placeholder(): void {
		$html = '<table>'
			. '<tr><th colspan="2">Header</th></tr>'
			. '<tr><td>foo</td><td>bar</td></tr>'
			. '</table>';

		$out = $this->call_convert_tables( $html );

		$this->assertStringContainsString( '<!-- SScribe: HTML table with merged cells', $out );
		$this->assertStringContainsString( 'Header', $out );
		$this->assertStringContainsString( 'foo', $out );
		$this->assertStringContainsString( 'bar', $out );
		// The broken GFM separator row must NOT be emitted.
		$this->assertStringNotContainsString( '| :--- | :--- |', $out );
	}

	/**
	 * Regression: tables with rowspan are also caught by the placeholder
	 * path (rowspan is just as unsupported in GFM as colspan).
	 */
	public function test_table_with_rowspan_emits_placeholder(): void {
		$html = '<table>'
			. '<tr><th>A</th><th>B</th></tr>'
			. '<tr><td rowspan="2">spans</td><td>x</td></tr>'
			. '<tr><td>y</td></tr>'
			. '</table>';

		$out = $this->call_convert_tables( $html );

		$this->assertStringContainsString( '<!-- SScribe: HTML table with merged cells', $out );
		$this->assertStringNotContainsString( '| :--- | :--- |', $out );
	}

	/**
	 * A colspan="1" (i.e., the default) must NOT trigger the placeholder
	 * — only merged cells (value >= 2) cause the lossless-conversion
	 * problem.
	 */
	public function test_colspan_one_does_not_trigger_placeholder(): void {
		$html  = '<table><tr><th colspan="1">H1</th><th>H2</th></tr><tr><td>a</td><td>b</td></tr></table>';
		$out   = $this->call_convert_tables( $html );

		$this->assertStringNotContainsString( '<!-- SScribe:', $out );
		$this->assertStringContainsString( '| H1 | H2 |', $out );
	}

	/**
	 * Single-column tables must still produce a valid GFM table (one
	 * column wide, with the separator row).
	 */
	public function test_single_column_table_converts_normally(): void {
		$html  = '<table><tr><th>Only</th></tr><tr><td>cell</td></tr></table>';
		$out   = $this->call_convert_tables( $html );

		$this->assertStringContainsString( '| Only |', $out );
		$this->assertStringContainsString( '| :--- |', $out );
		$this->assertStringContainsString( '| cell |', $out );
		$this->assertStringNotContainsString( '<!-- SScribe:', $out );
	}
}
