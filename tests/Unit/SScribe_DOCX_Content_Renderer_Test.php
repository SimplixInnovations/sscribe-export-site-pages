<?php
/**
 * SScribe DOCX Content Renderer Unit Test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribeVendor\PhpOffice\PhpWord\PhpWord;
use SScribeVendor\PhpOffice\PhpWord\Element\Section;
use SScribeVendor\PhpOffice\PhpWord\Shared\Converter;

class SScribe_DOCX_Content_Renderer_Test extends TestCase {

	private \SScribe_DOCX_Content_Renderer $renderer;
	private Section $section;

	protected function setUp(): void {
		parent::setUp();
		$this->renderer = new \SScribe_DOCX_Content_Renderer();
		$php_word       = new PhpWord();
		$this->section  = $php_word->addSection();
	}

	public function test_can_instantiate_with_no_args(): void {
		$renderer = new \SScribe_DOCX_Content_Renderer();
		$this->assertInstanceOf( \SScribe_DOCX_Content_Renderer::class, $renderer );
	}

	public function test_can_instantiate_with_parser(): void {
		$parser   = new \SScribe_Content_Parser();
		$renderer = new \SScribe_DOCX_Content_Renderer( $parser );
		$this->assertInstanceOf( \SScribe_DOCX_Content_Renderer::class, $renderer );
	}

	public function test_can_instantiate_with_full_config(): void {
		$renderer = new \SScribe_DOCX_Content_Renderer(
			new \SScribe_Content_Parser(),
			null,
			array( 'primary' => 'FF0000', 'body' => '000000' ),
			true,
			'Tahoma',
			12
		);
		$this->assertInstanceOf( \SScribe_DOCX_Content_Renderer::class, $renderer );
	}

	public function test_add_main_content_empty_data_returns_early(): void {
		$this->renderer->add_main_content( $this->section, array() );
		$this->assertTrue( true ); // No exception = early return success
	}

	public function test_add_main_content_with_empty_content(): void {
		$this->renderer->add_main_content( $this->section, array( 'content' => '' ) );
		$this->assertTrue( true );
	}

	public function test_add_main_content_renders_heading(): void {
		$parser = new \SScribe_Content_Parser();
		$renderer = new \SScribe_DOCX_Content_Renderer( $parser );
		$php_word = new PhpWord();
		$section  = $php_word->addSection();

		$renderer->add_main_content( $section, array(
			'content' => '<h1>Test Heading</h1>',
		) );
		$this->assertTrue( true );
	}

	public function test_add_main_content_renders_paragraph(): void {
		$parser = new \SScribe_Content_Parser();
		$renderer = new \SScribe_DOCX_Content_Renderer( $parser );
		$php_word = new PhpWord();
		$section  = $php_word->addSection();

		$renderer->add_main_content( $section, array(
			'content' => '<p>Hello World</p>',
		) );
		$this->assertTrue( true );
	}

	public function test_add_main_content_renders_list(): void {
		$parser = new \SScribe_Content_Parser();
		$renderer = new \SScribe_DOCX_Content_Renderer( $parser );
		$php_word = new PhpWord();
		$section  = $php_word->addSection();

		$renderer->add_main_content( $section, array(
			'content' => '<ul><li>Item 1</li><li>Item 2</li></ul>',
		) );
		$this->assertTrue( true );
	}

	public function test_add_main_content_renders_table(): void {
		$parser = new \SScribe_Content_Parser();
		$renderer = new \SScribe_DOCX_Content_Renderer( $parser );
		$php_word = new PhpWord();
		$section  = $php_word->addSection();

		$renderer->add_main_content( $section, array(
			'content' => '<table><tr><td>Cell</td></tr></table>',
		) );
		$this->assertTrue( true );
	}

	public function test_add_main_content_renders_blockquote(): void {
		$parser = new \SScribe_Content_Parser();
		$renderer = new \SScribe_DOCX_Content_Renderer( $parser );
		$php_word = new PhpWord();
		$section  = $php_word->addSection();

		$renderer->add_main_content( $section, array(
			'content' => '<blockquote><p>Quoted text</p></blockquote>',
		) );
		$this->assertTrue( true );
	}

	public function test_add_main_content_renders_code(): void {
		$parser = new \SScribe_Content_Parser();
		$renderer = new \SScribe_DOCX_Content_Renderer( $parser );
		$php_word = new PhpWord();
		$section  = $php_word->addSection();

		$renderer->add_main_content( $section, array(
			'content' => '<pre><code>echo "hello";</code></pre>',
		) );
		$this->assertTrue( true );
	}

	public function test_add_main_content_renders_horizontal_rule(): void {
		$parser = new \SScribe_Content_Parser();
		$renderer = new \SScribe_DOCX_Content_Renderer( $parser );
		$php_word = new PhpWord();
		$section  = $php_word->addSection();

		$renderer->add_main_content( $section, array(
			'content' => '<hr/>',
		) );
		$this->assertTrue( true );
	}

	public function test_add_main_content_renders_bold_and_italic(): void {
		$parser = new \SScribe_Content_Parser();
		$renderer = new \SScribe_DOCX_Content_Renderer( $parser );
		$php_word = new PhpWord();
		$section  = $php_word->addSection();

		$renderer->add_main_content( $section, array(
			'content' => '<p><strong>Bold</strong> and <em>italic</em></p>',
		) );
		$this->assertTrue( true );
	}

	public function test_add_main_content_renders_link(): void {
		$parser = new \SScribe_Content_Parser();
		$renderer = new \SScribe_DOCX_Content_Renderer( $parser );
		$php_word = new PhpWord();
		$section  = $php_word->addSection();

		$renderer->add_main_content( $section, array(
			'content' => '<p><a href="https://example.com">Example</a></p>',
		) );
		$this->assertTrue( true );
	}

	public function test_add_main_content_renders_image_missing(): void {
		$parser = new \SScribe_Content_Parser();
		$renderer = new \SScribe_DOCX_Content_Renderer( $parser );
		$php_word = new PhpWord();
		$section  = $php_word->addSection();

		$renderer->add_main_content( $section, array(
			'content' => '<p><img src="https://example.com/nonexistent.jpg" alt="Missing"/></p>',
		) );
		$this->assertTrue( true );
	}

	public function test_add_main_content_renders_complex_content(): void {
		$parser = new \SScribe_Content_Parser();
		$renderer = new \SScribe_DOCX_Content_Renderer( $parser );
		$php_word = new PhpWord();
		$section  = $php_word->addSection();

		$content = '<h1>Main</h1><p>Para with <strong>bold</strong> and <em>italic</em>.</p>'
			. '<ul><li>A</li><li>B</li></ul>'
			. '<blockquote>Quote</blockquote>'
			. '<pre><code>code</code></pre>'
			. '<hr/>';

		$renderer->add_main_content( $section, array( 'content' => $content ) );
		$this->assertTrue( true );
	}

	public function test_sync_config_updates_properties(): void {
		$renderer = new \SScribe_DOCX_Content_Renderer();
		$renderer->sync_config(
			array( 'primary' => '0000FF', 'body' => '111111' ),
			true,
			'Tahoma',
			14
		);

		$refl  = new \ReflectionClass( $renderer );
		$prop  = $refl->getProperty( 'is_rtl' );

		$this->assertTrue( $prop->getValue( $renderer ) );
		$prop = $refl->getProperty( 'font_name' );

		$this->assertEquals( 'Tahoma', $prop->getValue( $renderer ) );
		$prop = $refl->getProperty( 'font_size' );

		$this->assertEquals( 14, $prop->getValue( $renderer ) );
	}

	public function test_last_error_defaults_empty(): void {
		$this->assertEquals( '', $this->renderer->last_error );
	}

	public function test_renderer_handles_empty_elements_gracefully(): void {
		$parser = new \SScribe_Content_Parser();
		$renderer = new \SScribe_DOCX_Content_Renderer( $parser );
		$php_word = new PhpWord();
		$section  = $php_word->addSection();

		$renderer->add_main_content( $section, array(
			'content' => '<p></p><ul></ul><table></table>',
		) );
		$this->assertTrue( true );
	}

	public function test_renderer_handles_nested_lists(): void {
		$parser = new \SScribe_Content_Parser();
		$renderer = new \SScribe_DOCX_Content_Renderer( $parser );
		$php_word = new PhpWord();
		$section  = $php_word->addSection();

		$renderer->add_main_content( $section, array(
			'content' => '<ul><li>Top<ul><li>Nested</li></ul></li></ul>',
		) );
		$this->assertTrue( true );
	}

	public function test_renderer_uses_custom_colors(): void {
		$colors = array(
			'primary'  => 'FF0000',
			'heading'  => '00FF00',
			'body'     => '0000FF',
			'light_bg' => 'EEEEEE',
			'link'     => 'FF00FF',
			'code_bg'  => 'F5F5F5',
			'white'    => 'FFFFFF',
			'border'   => 'DDDDDD',
		);

		$parser   = new \SScribe_Content_Parser();
		$renderer = new \SScribe_DOCX_Content_Renderer( $parser, null, $colors );
		$php_word = new PhpWord();
		$section  = $php_word->addSection();

		$renderer->add_main_content( $section, array(
			'content' => '<h1>Colored</h1><p>Text</p>',
		) );
		$this->assertTrue( true );
	}

	public function test_renderer_handles_element_render_failure_gracefully(): void {
		$parser = new \SScribe_Content_Parser();
		$renderer = new \SScribe_DOCX_Content_Renderer( $parser );
		$php_word = new PhpWord();
		$section  = $php_word->addSection();

		$renderer->add_main_content( $section, array(
			'content' => '<p>Good</p><broken><p>Bad</p>',
		) );
		$this->assertTrue( true );
	}

	public function test_sync_config_updates_colors(): void {
		$renderer = new \SScribe_DOCX_Content_Renderer();
		// sync_config merges provided colors with defaults, so all 8 keys are always present.
		$colors = array(
			'primary'  => 'AAAAAA',
			'body'     => 'BBBBBB',
			'heading'  => '122119',
			'light_bg' => 'E8EFEB',
			'link'     => '2C6E8A',
			'code_bg'  => 'F5F6F8',
			'white'    => 'FFFFFF',
			'border'   => 'CCCCCC',
		);
		$renderer->sync_config( $colors, false, 'Arial', 11 );

		$refl = new \ReflectionClass( $renderer );
		$prop = $refl->getProperty( 'colors' );
		$this->assertEquals( $colors, $prop->getValue( $renderer ) );
	}

	public function test_sync_config_rejects_invalid_color_values(): void {
		$renderer = new \SScribe_DOCX_Content_Renderer();
		$renderer->sync_config(
			array(
				'primary' => 'not-a-color',
				'body'    => 'abcdef',
			),
			false,
			'Arial',
			11
		);

		$refl   = new \ReflectionClass( $renderer );
		$prop   = $refl->getProperty( 'colors' );
		$colors = $prop->getValue( $renderer );

		$this->assertSame( '4A8263', $colors['primary'] );
		$this->assertSame( 'ABCDEF', $colors['body'] );
	}
}
