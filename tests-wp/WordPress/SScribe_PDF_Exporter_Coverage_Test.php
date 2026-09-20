<?php
/**
 * Real-WordPress integration coverage tests for SScribe_PDF_Exporter.
 *
 * Slice #5 of the canonical Linux/Xdebug coverage architecture.
 * The PDF exporter is the third-largest production file at 1140 LOC and
 * hosts the TCPDF-rendering pipeline. Most of the heavy lifting lives in
 * the `export()` method itself (lines 122-416) and in the runtime
 * helpers it composes — `process_images_in_page_data`,
 * `collect_temp_image_paths`, `sanitize_pdf_image_sources`,
 * `create_tcpdf_document`, `resolve_pdf_page_size`,
 * `prepare_html_for_pdf_engine`, `filter_style_attribute`,
 * and the memory-pressure check.
 *
 * Strategy: drive the pure helpers via direct calls + reflection, plus
 * one full happy-path `export()` test that actually instantiates TCPDF
 * and writes a real PDF (the canonical Linux run already has TCPDF in
 * vendor-prefixed). That one test covers the largest part of `export()`
 * — the TCPDF init leg, the time-guard leg, the WriteHTML leg, and the
 * Output-to-file leg. Failure-path tests cover the early-out branches.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

require_once __DIR__ . '/SScribe_WP_TestCase.php';

final class SScribe_PDF_Exporter_Coverage_Test extends SScribe_WP_TestCase {

	private ?SScribe_PDF_Exporter $exporter = null;

	/** Scratch output directory inside the SScribe private storage. */
	private string $output_dir = '';

	public function set_up(): void {
		parent::set_up();
		SScribe_Activator::activate( false );

		// Make sure the Strauss-prefixed vendor autoloader is registered
		// before we instantiate the exporter. The testbench bootstrap
		// does not always wire it up automatically — without it, every
		// export call short-circuits at the `pdf_missing_library` guard
		// instead of exercising the real rendering pipeline.
		$prefixed_autoload = SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/autoload.php';
		if ( file_exists( $prefixed_autoload ) && ! class_exists( '\\SScribeVendor_TCPDF', false ) ) {
			require_once $prefixed_autoload;
		}

		$this->exporter = new SScribe_PDF_Exporter();

		// Anchor the output dir inside the SScribe private-storage tree so
		// the Filesystem::is_path_safe_for_write() guard accepts it.
		// SScribe_Private_Storage::get_subdirectory() walks through the
		// resolver, which honors SSCRIBE_PRIVATE_STORAGE_DIR when defined
		// (locked-in for the run by an earlier test) and falls back to
		// sys_get_temp_dir() otherwise. Either way the returned path is
		// inside SScribe-controlled storage.
		$this->output_dir = \SScribe_Private_Storage::get_subdirectory(
			'pdf-coverage-' . bin2hex( random_bytes( 4 ) )
		);
		$this::assertNotSame(
			'',
			$this->output_dir,
			'SScribe private-storage resolver must hand us a usable output directory.'
		);
	}

	public function tear_down(): void {
		if ( '' !== $this->output_dir && is_dir( $this->output_dir ) ) {
			foreach ( glob( $this->output_dir . '/*' ) as $leftover ) {
				@unlink( $leftover );
			}
			@rmdir( $this->output_dir );
		}

		// Tear down any filter overrides a test installed without a try/finally.
		remove_all_filters( 'sscribe_pdf_max_html_size' );
		remove_all_filters( 'sscribe_pdf_max_content_images' );
		remove_all_filters( 'sscribe_pdf_memory_soft_margin_bytes' );
		remove_all_filters( 'sscribe_pdf_memory_hard_margin_bytes' );

		parent::tear_down();
	}

	// -----------------------------------------------------------------
	// Pure-helper / option coverage
	// -----------------------------------------------------------------

	public function test_get_extension_returns_pdf(): void {
		$this::assertSame( 'pdf', $this->exporter->get_extension() );
	}

	public function test_get_mime_type_returns_application_pdf(): void {
		$this::assertSame( 'application/pdf', $this->exporter->get_mime_type() );
	}

	public function test_apply_format_options_persists_into_get_format_option(): void {
		$this->exporter->apply_format_options(
			array(
				'sscribe_pdf_page_size'             => 'A3',
				'sscribe_pdf_include_images'        => '1',
				'sscribe_pdf_include_page_numbers'  => '0',
			)
		);

		$this::assertSame(
			'A3',
			$this->call_private( 'get_format_option' )( 'sscribe_pdf_page_size', 'A4' )
		);
		$this::assertSame(
			'1',
			$this->call_private( 'get_format_option' )( 'sscribe_pdf_include_images', '0' )
		);
		// Unknown key returns its default.
		$this::assertSame(
			'default-x',
			$this->call_private( 'get_format_option' )( 'sscribe_pdf_unknown_key', 'default-x' )
		);
	}

	public function test_normalize_scalar_returns_default_for_non_scalar(): void {
		$normalize = $this->call_private( 'normalize_scalar' );

		$this::assertSame( 'fb', $normalize( array( 1, 2 ), 'fb' ) );
		$this::assertSame( 'fb', $normalize( new \stdClass(), 'fb' ) );
	}

	public function test_normalize_scalar_trims_and_returns_default_for_empty(): void {
		$normalize = $this->call_private( 'normalize_scalar' );

		$this::assertSame(
			'fb',
			$normalize( '   ', 'fb' ),
			'whitespace-only value falls back to default'
		);
		$this::assertSame(
			'fb',
			$normalize( '', 'fb' ),
			'empty string falls back to default'
		);
		$this::assertSame(
			'pasted',
			$normalize( '   pasted   ', 'fb' ),
			'whitespace gets trimmed and value returned'
		);
	}

	// -----------------------------------------------------------------
	// Page-size resolution
	// -----------------------------------------------------------------

	public function test_resolve_pdf_page_size_returns_each_allowed_value(): void {
		$resolve = $this->call_private( 'resolve_pdf_page_size' );

		$this->exporter->apply_format_options( array( 'sscribe_pdf_page_size' => 'A4' ) );
		$this::assertSame( 'A4', $resolve() );

		$this->exporter->apply_format_options( array( 'sscribe_pdf_page_size' => 'A3' ) );
		$this::assertSame( 'A3', $resolve() );

		$this->exporter->apply_format_options( array( 'sscribe_pdf_page_size' => 'Letter' ) );
		$this::assertSame( 'Letter', $resolve() );

		$this->exporter->apply_format_options( array( 'sscribe_pdf_page_size' => 'Legal' ) );
		$this::assertSame( 'Legal', $resolve() );
	}

	public function test_resolve_pdf_page_size_falls_back_for_unknown_or_empty(): void {
		$resolve = $this->call_private( 'resolve_pdf_page_size' );

		// Unknown value falls back to 'A4'.
		$this->exporter->apply_format_options( array( 'sscribe_pdf_page_size' => 'Tabloid' ) );
		$this::assertSame( 'A4', $resolve() );

		// Empty value falls back to 'A4'.
		$this->exporter->apply_format_options( array( 'sscribe_pdf_page_size' => '' ) );
		$this::assertSame( 'A4', $resolve() );

		// No option at all (default-arg branch).
		$this->exporter->apply_format_options( array() );
		$this::assertSame( 'A4', $resolve() );
	}

	// -----------------------------------------------------------------
	// Image handling helpers
	// -----------------------------------------------------------------

	public function test_collect_temp_image_paths_with_empty_data(): void {
		$collect = $this->call_private( 'collect_temp_image_paths' );

		$this::assertSame( array(), $collect( array() ) );

		// Only non-string / empty entries in _temp_image_paths are filtered.
		$this::assertSame(
			array(),
			$collect( array( '_temp_image_paths' => array( '', null, 0, false ) ) )
		);
	}

	public function test_collect_temp_image_paths_with_valid_temp_paths(): void {
		$collect = $this->call_private( 'collect_temp_image_paths' );
		$result  = $collect(
			array(
				'_temp_image_paths' => array( '/tmp/a.png', '', '/tmp/b.jpg' ),
			)
		);

		// Empty string must be skipped, others retained.
		$this::assertContains( '/tmp/a.png', $result );
		$this::assertContains( '/tmp/b.jpg', $result );
		$this::assertCount( 2, $result );
	}

	public function test_collect_temp_image_paths_with_featured_url_dedups(): void {
		$collect = $this->call_private( 'collect_temp_image_paths' );

		// Featured URL identical to one in _temp_image_paths: must dedup.
		$result = $collect(
			array(
				'_temp_image_paths'  => array( '/tmp/featured.png' ),
				'featured_image_url' => '/tmp/featured.png',
			)
		);
		$this::assertCount( 1, $result );
		$this::assertSame( '/tmp/featured.png', $result[0] );

		// Featured URL distinct: must add to list.
		$result = $collect(
			array(
				'_temp_image_paths'  => array( '/tmp/inline.png' ),
				'featured_image_url' => '/tmp/featured.png',
			)
		);
		$this::assertCount( 2, $result );
	}

	public function test_sanitize_pdf_image_sources_with_include_images_off_strips_all(): void {
		$sanitize = $this->call_private( 'sanitize_pdf_image_sources' );

		$html = '<p>hi</p><img src="https://example.test/x.png" />';
		$this::assertSame( '<p>hi</p>', $sanitize( $html, false ) );
	}

	public function test_sanitize_pdf_image_sources_with_no_img_tags_unchanged(): void {
		$sanitize = $this->call_private( 'sanitize_pdf_image_sources' );

		$html = '<p>plain text</p><div>no images</div>';
		$this::assertSame( $html, $sanitize( $html, true ) );
	}

	public function test_sanitize_pdf_image_sources_strips_tag_with_invalid_src(): void {
		$sanitize = $this->call_private( 'sanitize_pdf_image_sources' );

		// Remote URL fails SScribe_Image_Processor::validate_local_path() and gets stripped.
		$html   = '<img src="https://example.test/x.png" />after';
		$result = $sanitize( $html, true );
		$this::assertStringNotContainsString( '<img', $result );
		$this::assertStringContainsString( 'after', $result );
	}

	public function test_sanitize_pdf_image_sources_with_unparseable_tag_strips_tag(): void {
		// An unparseable src attribute — no quotes — fails the inner
		// preg_match on `\bsrc\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))`
		// in sanitize_pdf_image_sources() and the entire <img> tag is
		// stripped (line 643). This is a real production edge case for
		// hand-edited content.
		$sanitize = $this->call_private( 'sanitize_pdf_image_sources' );

		$html   = '<p>before</p><img src=/unquoted.png /><p>after</p>';
		$result = $sanitize( $html, true );

		$this::assertStringNotContainsString( '<img', $result );
		$this::assertStringContainsString( 'before', $result );
		$this::assertStringContainsString( 'after', $result );
	}

	public function test_sanitize_pdf_image_sources_strips_unmatched_image_when_local_path_missing(): void {
		// validate_local_path() requires the file to actually exist on
		// disk (it's the canonical-path guard). A URL pointing at a
		// path that doesn't exist gets stripped even if it lives under
		// the uploads tree.
		$sanitize = $this->call_private( 'sanitize_pdf_image_sources' );

		$uploads = wp_upload_dir();
		$url     = $uploads['baseurl'] . '/pdf-coverage-missing.png';

		$html   = '<p>before</p><img src="' . $url . '" /><p>after</p>';
		$result = $sanitize( $html, true );

		$this::assertStringNotContainsString( '<img', $result );
		$this::assertStringContainsString( 'before', $result );
		$this::assertStringContainsString( 'after', $result );
	}

	// -----------------------------------------------------------------
	// HTML preparation helpers
	// -----------------------------------------------------------------

	public function test_prepare_html_for_pdf_engine_with_no_style_blocks_unchanged(): void {
		$prepare = $this->call_private( 'prepare_html_for_pdf_engine' );

		$html = '<p>hello</p>';
		$this::assertSame( $html, $prepare( $html, false ) );
	}

	public function test_prepare_html_for_pdf_engine_strips_font_and_remote_resource_controls(): void {
		$prepare = $this->call_private( 'prepare_html_for_pdf_engine' );

		$html = '<style>'
			. '.x { color: red; font-family: Georgia; } '
			. '@font-face { font-family: foo; src: url(https://bad.example/font.woff2); } '
			. '@import url(https://bad.example/x.css); '
			. '.y { color: blue; }'
			. '</style><p>hi</p>';
		$result = $prepare( $html, false );

		$this::assertStringContainsString( 'color: red', $result );
		$this::assertStringContainsString( 'color: blue', $result );
		$this::assertStringNotContainsString( '@font-face', strtolower( $result ) );
		$this::assertStringNotContainsString( '@import', strtolower( $result ) );
		$this::assertStringNotContainsString( 'font-family', strtolower( $result ) );
		$this::assertStringNotContainsString( 'bad.example', strtolower( $result ) );
	}

	public function test_filter_style_attribute_keeps_allowed_and_strips_disallowed_declarations(): void {
		$filter_style = $this->call_private( 'filter_style_attribute' );

		// Allowed: direction, text-align, color. Disallowed: margin-left,
		// transform (not in the allow-list). Whitespace gets normalized
		// away and the result ends with a trailing semicolon.
		$out = $filter_style(
			'text-align: left; color: red; direction: ltr; margin-left: 5px; transform: rotate(2deg)',
			false
		);
		$this::assertSame(
			'text-align:left;color:red;direction:ltr;',
			$out,
			'filter_style_attribute must keep only allow-listed properties and drop the rest.'
		);
	}

	public function test_filter_style_attribute_with_empty_string_returns_empty(): void {
		$filter_style = $this->call_private( 'filter_style_attribute' );
		$this::assertSame( '', $filter_style( '', false ) );
		$this::assertSame( '', $filter_style( '   ', true ) );
	}

	public function test_filter_style_attribute_with_only_disallowed_declarations_returns_empty(): void {
		$filter_style = $this->call_private( 'filter_style_attribute' );
		// margin-left is not in the allow-list; the function has nothing
		// to keep and must return ''.
		$this::assertSame( '', $filter_style( 'margin-left: 5px; transform: rotate(2deg)', false ) );
	}

	// -----------------------------------------------------------------
	// Libxml and temporary-image helpers
	// -----------------------------------------------------------------


	public function test_get_libxml_error_details_with_no_recent_errors(): void {
		$get_libxml = $this->call_private( 'get_libxml_error_details' );

		$result = $get_libxml();
		$this::assertIsArray( $result );
		// libxml_use_internal_errors is independent of recent parse errors,
		// so we only assert the shape — either an empty array or one
		// populated by libxml global state from earlier tests.
	}

	public function test_cleanup_temp_images_with_empty_list_is_noop(): void {
		$cleanup = $this->call_private( 'cleanup_temp_images' );
		$cleanup( array() );
		$this::assertTrue( true, 'cleanup_temp_images([]) must complete without throwing.' );
	}


	// -----------------------------------------------------------------
	// export() — failure-path branches
	// -----------------------------------------------------------------

	public function test_export_with_unsafe_output_dir_returns_pdf_filesystem_failure(): void {
		$unsafe = '/tmp/sscribe-pdfcoverage-unsafe-' . bin2hex( random_bytes( 4 ) );
		// is_path_safe_for_write() rejects paths outside the private storage.
		$result = $this->exporter->export(
			array(
				'id'      => 1,
				'title'   => 'Hello',
				'content' => '<p>hello</p>',
			),
			$unsafe,
			0,
			1
		);

		$this::assertFalse( $result->is_success() );
		$this::assertSame(
			'pdf_filesystem',
			$result->get_context()['error_category'] ?? '',
			'Unsafe output dir must surface as pdf_filesystem failure.'
		);
	}

	public function test_export_with_oversized_html_returns_pdf_memory_guard(): void {
		// 6 MiB (above the 5 MiB default cap).
		$huge_html = '<p>' . str_repeat( 'A', 6 * 1024 * 1024 ) . '</p>';

		$result = $this->exporter->export(
			array(
				'id'      => 1,
				'title'   => 'Huge',
				'content' => $huge_html,
			),
			$this->output_dir,
			0,
			1
		);

		$this::assertFalse( $result->is_success() );
		$this::assertSame(
			'pdf_memory_guard',
			$result->get_context()['error_category'] ?? '',
			'Oversized HTML must surface as pdf_memory_guard failure.'
		);
	}

	public function test_export_with_zero_max_html_size_skips_guard(): void {
		// Drive a 5 KiB payload through with the filter clamped to 0 — the
		// guard branch (line 185) must NOT fire and the export proceeds.
		// We don't expect a real PDF because rendering this much HTML via
		// the actual TCPDF class without the title would still work, but
		// what's important is that the SIZE guard short-circuits and we
		// get past it (whatever the next branch decides).
		$filter = static function () {
			return 0;
		};
		add_filter( 'sscribe_pdf_max_html_size', $filter );
		try {
			$result = $this->exporter->export(
				array(
					'id'      => 1,
					'title'   => 'SkipGuard',
					'content' => '<p>small</p>',
				),
				$this->output_dir,
				0,
				1
			);
		} finally {
			remove_filter( 'sscribe_pdf_max_html_size', $filter );
		}

		// Either success or a downstream failure is acceptable; what we
		// care about is that the size guard did NOT short-circuit.
		$category = $result->get_context()['error_category'] ?? '';
		$this::assertNotSame(
			'pdf_memory_guard',
			$category,
			'Max-size=0 must disable the pdf_memory_guard check.'
		);
	}

	// -----------------------------------------------------------------
	// export() — happy path (full TCPDF render). Last in the file so a
	// failure here does not disqualify the cheaper tests above.
	// -----------------------------------------------------------------

	public function test_export_happy_path_writes_real_pdf(): void {
		$result = $this->exporter->export(
			array(
				'id'        => 42,
				'title'     => 'Coverage Test Page',
				'content'   => '<h1>Hello PDF</h1><p>Body text for PDF coverage.</p>',
				'language'  => 'en',
				'author'    => 'Coverage',
				'slug'      => 'coverage-test',
				'permalink' => 'https://example.test/coverage-test',
				'seo'       => array(
					'meta_description' => 'desc',
					'focus_keyword'    => 'pdf coverage',
				),
			),
			$this->output_dir,
			0,
			1
		);

		$this::assertTrue(
			$result->is_success(),
			'PDF export must succeed. Error: ' . (string) $result->get_error() . ' Context: ' . wp_json_encode( $result->get_context() )
		);

		$path = (string) ( $result->get_data()['path'] ?? '' );
		$this::assertNotSame( '', $path );
		$this::assertFileExists( $path );
		$this::assertGreaterThan(
			0,
			(int) filesize( $path ),
			'Real PDF on disk must be non-empty.'
		);
		// Sanity: the magic-bytes at the start of any PDF file.
		$head = file_get_contents( $path, false, null, 0, 4 );
		$this::assertSame( '%PDF', $head, 'Output file must start with %PDF magic.' );

		// Clean up the artifact so tear_down doesn't trip.
		if ( file_exists( $path ) ) {
			unlink( $path );
		}
	}

	public function test_export_rtl_arabic_writes_real_pdf(): void {
		$result = $this->exporter->export(
			array(
				'id'       => 43,
				'title'    => 'اختبار التصدير',
				'content'  => '<h1>مرحبا بالعالم</h1><p>هذا اختبار لاتجاه النص العربي.</p>',
				'language' => 'ar',
				'author'   => 'SScribe',
			),
			$this->output_dir,
			0,
			1
		);

		$this::assertTrue(
			$result->is_success(),
			'RTL PDF export must succeed. Error: ' . (string) $result->get_error() . ' Context: ' . wp_json_encode( $result->get_context() )
		);
		$path = (string) ( $result->get_data()['path'] ?? '' );
		$this::assertFileExists( $path );
		$this::assertSame( '%PDF', file_get_contents( $path, false, null, 0, 4 ) );
		@unlink( $path );
	}

	// -----------------------------------------------------------------
	// Reflection helper
	// -----------------------------------------------------------------

	/**
	 * Invoke a private method on the exporter via reflection.
	 *
	 * @param array<int, mixed> $args Positional arguments.
	 * @return mixed Return value of the private method.
	 */
	private function invoke_private( object $object, string $method, array $args = array() ): mixed {
		$ref  = new \ReflectionClass( $object );
		$func = $ref->getMethod( $method );
		$func->setAccessible( true );
		return $func->invokeArgs( $object, $args );
	}

	/**
	 * Build a variadic callable that invokes a private method on the
	 * exporter with positional arguments. Lets us write
	 * `$callable( $a, $b )` without leaking the reflection plumbing
	 * into every test.
	 *
	 * @return callable
	 */
	private function call_private( string $method ): callable {
		$exporter = $this->exporter;
		return static function ( ...$args ) use ( $exporter, $method ): mixed {
			$ref  = new \ReflectionClass( $exporter );
			$func = $ref->getMethod( $method );
			$func->setAccessible( true );
			return $func->invokeArgs( $exporter, $args );
		};
	}
}
