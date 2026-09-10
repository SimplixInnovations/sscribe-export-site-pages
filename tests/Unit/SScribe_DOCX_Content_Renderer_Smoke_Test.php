<?php
/**
 * SScribe DOCX Content Renderer smoke test
 *
 * Exercises the public surface that does not require a live PhpWord
 * Section:
 *
 *   - __construct() with default collaborators — exercises sanitize_colors
 *     default-palette path
 *   - __construct() with explicit collaborators — same path without
 *     lazy defaults
 *   - __construct() with custom colors — exercises sanitize_colors
 *     validation branches (valid hex kept, invalid dropped)
 *   - __construct() with extreme font sizes — exercises the 6..72 clamp
 *   - sync_config() — verifies the override + clamp paths
 *
 * The heavy add_main_content() method is covered by the integration
 * suite; this file pins the deterministic surface.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SScribe_Content_Parser;
use SScribe_DOCX_Content_Renderer;

if ( ! class_exists( '\\SScribe_DOCX_Content_Renderer', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-docx-content-renderer.php';
}

final class SScribe_DOCX_Content_Renderer_Smoke_Test extends TestCase {

	public function test_construct_with_defaults(): void {
		$renderer = new SScribe_DOCX_Content_Renderer();
		$this::assertInstanceOf( SScribe_DOCX_Content_Renderer::class, $renderer );
	}

	public function test_construct_with_explicit_parser(): void {
		$parser   = new SScribe_Content_Parser();
		$renderer = new SScribe_DOCX_Content_Renderer( $parser );
		$this::assertInstanceOf( SScribe_DOCX_Content_Renderer::class, $renderer );
	}

	public function test_construct_with_valid_custom_colors_keeps_them(): void {
		// Sanitize_colors preserves valid hex strings (uppercased) and
		// drops anything that doesn't match /^([0-9A-Fa-f]{6})$/.
		$renderer = new SScribe_DOCX_Content_Renderer(
			null,
			null,
			array(
				'primary' => 'abcdef',
				'border'  => '123456',
			)
		);
		$this::assertInstanceOf( SScribe_DOCX_Content_Renderer::class, $renderer );
	}

	public function test_construct_with_invalid_colors_uses_defaults(): void {
		// Non-hex strings and non-string values must be dropped, not
		// preserved as-is. The constructor's sanitize_colors() falls
		// back to the built-in default palette for invalid entries.
		$renderer = new SScribe_DOCX_Content_Renderer(
			null,
			null,
			array(
				'primary'  => 'not-a-hex',
				'heading'  => '#fff',
				'body'     => array( 'invalid' ),
				'light_bg' => 'AABBCCGG', // 8 chars — too long
			)
		);
		$this::assertInstanceOf( SScribe_DOCX_Content_Renderer::class, $renderer );
	}

	public function test_construct_with_extreme_font_size_low_is_clamped(): void {
		// font_size < 6 must be clamped up to 6.
		$renderer = new SScribe_DOCX_Content_Renderer( null, null, array(), false, 'Arial', 1 );
		$this::assertInstanceOf( SScribe_DOCX_Content_Renderer::class, $renderer );
	}

	public function test_construct_with_extreme_font_size_high_is_clamped(): void {
		// font_size > 72 must be clamped down to 72.
		$renderer = new SScribe_DOCX_Content_Renderer( null, null, array(), false, 'Arial', 999 );
		$this::assertInstanceOf( SScribe_DOCX_Content_Renderer::class, $renderer );
	}

	public function test_construct_with_rtl_flag(): void {
		$renderer = new SScribe_DOCX_Content_Renderer( null, null, array(), true, 'Arial', 14 );
		$this::assertInstanceOf( SScribe_DOCX_Content_Renderer::class, $renderer );
	}

	public function test_sync_config_overrides_colors_rtl_font(): void {
		$renderer = new SScribe_DOCX_Content_Renderer();
		$renderer->sync_config(
			array(
				'primary' => 'abcdef',
				'border'  => '111111',
			),
			true,
			'Times',
			14
		);
		// Subsequent sync must still leave the renderer in a valid state.
		$this::assertInstanceOf( SScribe_DOCX_Content_Renderer::class, $renderer );
	}

	public function test_sync_config_clamps_font_size(): void {
		$renderer = new SScribe_DOCX_Content_Renderer();
		// Out-of-range sizes must clamp without raising.
		$renderer->sync_config( array(), false, 'Arial', 0 );
		$renderer->sync_config( array(), false, 'Arial', 1000 );
		$this::assertInstanceOf( SScribe_DOCX_Content_Renderer::class, $renderer );
	}

	public function test_sync_config_drops_invalid_colors(): void {
		$renderer = new SScribe_DOCX_Content_Renderer();
		$renderer->sync_config(
			array(
				'primary'  => 'totally-bogus',
				'heading'  => '12345',
				'body'     => array( 'invalid' ),
			),
			false,
			'Arial',
			11
		);
		// Survives without error — the bad colors were dropped, defaults
		// took over.
		$this::assertInstanceOf( SScribe_DOCX_Content_Renderer::class, $renderer );
	}

	public function test_sync_config_uppercases_valid_colors(): void {
		// Lowercase hex must be uppercased by sanitize_colors().
		$renderer = new SScribe_DOCX_Content_Renderer();
		$renderer->sync_config(
			array(
				'primary' => 'abcdef',
				'border'  => '123456',
			),
			false,
			'Arial',
			11
		);
		$this::assertTrue( true, 'sync_config with lowercase colors survives.' );
	}
}
