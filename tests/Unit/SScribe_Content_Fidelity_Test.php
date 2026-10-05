<?php
/**
 * Content-fidelity and artifact-hygiene regressions.
 *
 * Pins the fixes for: silent text truncation of human content (DOCX body
 * runs and exporter metadata), corrupt export artifacts surviving into the
 * deliverable ZIP, featured images lost when rendered from local paths, the
 * upgrader cache-key mismatch, and crash-orphaned staging ZIPs leaking past
 * cleanup.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Content_Fidelity_Test extends TestCase {

	private static function call_private( object $object, string $method, array $args = array() ): mixed {
		$reflection = new \ReflectionMethod( get_class( $object ), $method );

		return $reflection->invoke( $object, ...$args );
	}

	public function test_machine_heuristic_never_classifies_spaced_prose(): void {
		$prose = str_repeat( 'This is a perfectly ordinary sentence of human prose. ', 60 );

		$this::assertFalse(
			\SScribe_Helpers::is_machine_style_string( $prose ),
			'Spaced prose must never be classified as a machine payload.'
		);
		$this::assertFalse(
			\SScribe_Helpers::is_machine_style_string( 'https://example.com/日本語の長いURLパスを含むテストケースです' ),
			'URL-shaped strings containing CJK must stay human-classified.'
		);
		$this::assertTrue(
			\SScribe_Helpers::is_machine_style_string( str_repeat( 'A', 3000 ) ),
			'Spaceless ASCII blobs remain machine payloads.'
		);
		$this::assertTrue(
			\SScribe_Helpers::is_machine_style_string( 'https://example.com/this/is/a/very/long/url/path' ),
			'URL-scheme strings remain machine payloads.'
		);
		$this::assertFalse(
			\SScribe_Helpers::is_machine_style_string( str_repeat( 'ن', 3000 ) ),
			'Spaceless Arabic words must not be classified as machine payloads.'
		);
	}

	public function test_exporter_safe_text_preserves_long_prose(): void {
		$exporter = ( new \ReflectionClass( \SScribe_Exporter::class ) )->newInstanceWithoutConstructor();
		$method   = new \ReflectionMethod( \SScribe_Exporter::class, 'safe_text' );

		$prose = str_repeat( 'Natural language must survive intact across every export format. ', 50 );
		$this::assertSame(
			$prose,
			$method->invoke( $exporter, $prose ),
			'Metadata prose longer than 2048 characters must never be truncated.'
		);

		// Pinned contract: machine payloads still truncate at 2048.
		$blob   = str_repeat( 'A', 3000 );
		$result = $method->invoke( $exporter, $blob );
		$this::assertLessThanOrEqual( 2048, \SScribe_Helpers::mb_strlen( $result ) );
	}

	public function test_docx_body_runs_are_never_silently_truncated(): void {
		$renderer = ( new \ReflectionClass( \SScribe_Docx_Content_Renderer::class ) )->newInstanceWithoutConstructor();
		$method   = new \ReflectionMethod( \SScribe_Docx_Content_Renderer::class, 'safe_text' );

		$cjk   = str_repeat( '这是一个非常长的中文段落，没有任何空格。', 100 );
		$this::assertSame(
			$cjk,
			$method->invoke( $renderer, $cjk ),
			'A long spaceless CJK paragraph must reach the DOCX intact.'
		);

		$code = str_repeat( 'function(x){return x*2;}', 150 );
		$this::assertSame(
			$code,
			$method->invoke( $renderer, $code ),
			'Spaceless code runs must reach the DOCX intact.'
		);

		$pathological = str_repeat( 'B', 120000 );
		$this::assertLessThanOrEqual(
			100000,
			\SScribe_Helpers::mb_strlen( (string) $method->invoke( $renderer, $pathological ) ),
			'The memory ceiling only bounds pathological payloads.'
		);
	}

	public function test_empty_export_artifact_is_deleted_not_shipped(): void {
		$processor = ( new \ReflectionClass( \SScribe_Batch_Processor::class ) )->newInstanceWithoutConstructor();
		$method    = new \ReflectionMethod( \SScribe_Batch_Processor::class, 'validate_export_result' );

		$tmp = sys_get_temp_dir() . '/sscribe-fidelity-' . uniqid() . '.html';
		file_put_contents( $tmp, 'tiny' );
		$this::assertFileExists( $tmp );

		$result = new \SScribe_Result( true, array( 'path' => $tmp, 'size' => 4 ) );
		$out    = $method->invoke( $processor, $result, 'html', 1 );

		$this::assertFalse( $out['is_valid'] );
		$this::assertSame( 'empty_file', $out['category'] );
		$this::assertFileDoesNotExist(
			$tmp,
			'A file the pipeline itself rejected must not survive to be packed into the ZIP.'
		);
	}

	public function test_failed_export_result_removes_partial_artifact(): void {
		$processor = ( new \ReflectionClass( \SScribe_Batch_Processor::class ) )->newInstanceWithoutConstructor();
		$method    = new \ReflectionMethod( \SScribe_Batch_Processor::class, 'validate_export_result' );

		$tmp = sys_get_temp_dir() . '/sscribe-fidelity-' . uniqid() . '.docx';
		file_put_contents( $tmp, str_repeat( 'x', 9000 ) );

		$result = new \SScribe_Result( false, array( 'path' => $tmp ), 'writer exploded' );
		$out    = $method->invoke( $processor, $result, 'docx', 1 );

		$this::assertFalse( $out['is_valid'] );
		$this::assertFileDoesNotExist( $tmp, 'Partial output of a failed export must be removed.' );
	}

	public function test_featured_image_renders_from_local_paths(): void {
		$exporter = ( new \ReflectionClass( \SScribe_HTML_Exporter::class ) )->newInstanceWithoutConstructor();
		$method   = new \ReflectionMethod( \SScribe_HTML_Exporter::class, 'get_featured_image_html' );

		$tmp = sys_get_temp_dir() . '/sscribe-fidelity-' . uniqid() . '.png';
		file_put_contents( $tmp, 'fake-image-bytes' );

		try {
			$html = (string) $method->invoke( $exporter, array( 'featured_image_url' => $tmp ) );
			$this::assertStringContainsString(
				basename( $tmp ),
				$html,
				'A validated local featured-image path must render (esc_url drops drive-letter paths on Windows).'
			);
			$this::assertStringNotContainsString( '<img src=""', $html );
		} finally {
			@unlink( $tmp );
		}
	}

	public function test_upgrader_invalidates_the_real_page_data_cache_key(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-sscribe-upgrader.php' );

		$this::assertStringContainsString(
			"'sscribe_admin_page_data_v2_' . SSCRIBE_VERSION . '_' . get_current_blog_id()",
			$source,
			'The upgrader must invalidate the exact cache key shape built in SScribe.'
		);
	}

	public function test_cleanup_collects_dot_prefixed_staging_zips(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-sscribe-zip-handler.php' );

		$this::assertStringContainsString(
			'.tmp-sscribe-*.zip',
			$source,
			'Crash-orphaned staging ZIPs are dot-prefixed and must be collected explicitly by cleanup.'
		);
	}

	public function test_pdf_export_survives_filename_sanitizing_titles(): void {
		// Regression: the PDF engine writes sanitized filenames ("!" becomes
		// "_"), the exporter then validated the ORIGINAL name and reported a
		// phantom "Failed to write PDF file." while the artifact shipped
		// under the sibling name. A title with "!" must succeed AND land
		// under the intended, consistent name.
		$exporter = new \SScribe_PDF_Exporter();
		// The filesystem guard only permits writes under the private storage
		// root, exactly like the real pipeline.
		$out_dir = trailingslashit( (string) \SScribe_Private_Storage::get_export_dir() ) . 'pdf-bang-' . uniqid();
		wp_mkdir_p( $out_dir );

		try {
			$result = $exporter->export(
				array(
					'id'              => 1,
					'title'           => 'Hello world!',
					'slug'            => 'hello-world',
					'language'        => 'en',
					'content'         => '<p>Welcome to WordPress. This is your first post.</p>',
					'permalink'       => 'http://example.test/hello-world/',
					'author'          => 'admin',
					'date_published'  => '2026-10-05 00:00:00',
					'date_modified'   => '2026-10-05 00:00:00',
					'word_count'      => 7,
					'reading_time'    => 1,
					'featured_image_url' => '',
					'seo'             => array(),
				),
				$out_dir,
				1,
				1
			);

			$this::assertTrue(
				$result->is_success(),
				'A PDF for a title containing "!" must not report a write failure. Error: ' . (string) $result->get_error()
			);
			$data = $result->get_data();
			$path = is_array( $data ) ? (string) ( $data['path'] ?? '' ) : '';
			$this::assertNotSame( '', $path );
			$this::assertFileExists( $path );
			$this::assertStringContainsString(
				'Hello world!-1.pdf',
				basename( $path ),
				'The artifact must be normalized back to the intended filename after the engine sanitizes its own copy.'
			);
		} finally {
			if ( is_dir( $out_dir ) ) {
				foreach ( (array) glob( $out_dir . '/*' ) as $stale ) {
					if ( is_string( $stale ) && is_file( $stale ) ) {
						@unlink( $stale );
					}
				}
				@rmdir( $out_dir );
			}
		}
	}
}
