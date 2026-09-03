<?php
/**
 * Phase 43 — Accessibility contract integration test.
 *
 * The accessibility contract has eight static rules, all enforced
 * by `scripts/verify-accessibility.php`:
 *
 *   1. Form fields carry an accessible name.
 *   2. Buttons carry an accessible name (text or aria-label).
 *   3. Inline SVGs are either decorative (`aria-hidden="true"`) or
 *      semantic (`role="img"` + `aria-label`).
 *   4. `aria-controls` targets exist in the same file.
 *   5. No positive `tabindex` (anti-pattern).
 *   6. No inline event handlers.
 *   7. No skipped heading levels.
 *   8. The shipped CSS has visible `:focus`/`:focus-visible`
 *      styles.
 *
 * This test class runs the verifier against the live plugin tree
 * and against planted mutations so the gate stays locked.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Accessibility_Test extends TestCase {

	private const SCRIPT_PATH   = 'scripts/verify-accessibility.php';
	private const MANIFEST_PATH = 'dist/accessibility-manifest.json';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * @return array{0:int,1:string}
	 */
	private function run_verifier(): array {
		$root        = self::plugin_root();
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open(
			array( PHP_BINARY, $root . '/' . self::SCRIPT_PATH ),
			$descriptors,
			$pipes
		);
		$this::assertIsResource( $process );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		return array( (int) $code, $stdout . $stderr );
	}

	public function test_live_tree_passes_static_audit(): void {
		list( $code, $output ) = $this->run_verifier();
		$this::assertSame(
			0,
			$code,
			'Live plugin tree must satisfy the accessibility static contract. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'Accessibility static audit passed', $output );
	}

	public function test_manifest_records_focus_indicators(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$abs     = self::plugin_root() . '/' . self::MANIFEST_PATH;
		$this::assertFileExists( $abs, 'accessibility-manifest.json must be persisted under dist/' );
		$payload = json_decode( (string) file_get_contents( $abs ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'] );
		$this::assertGreaterThan( 0, $payload['files_scanned'] );
		// Every shipped CSS focus rule must produce a visible style.
		$this::assertGreaterThan( 0, $payload['focus_selectors_seen'] );
		$this::assertSame( $payload['focus_selectors_seen'], $payload['focus_visible_styles'] );
		$this::assertSame( 0, $payload['errors_count'] );
	}

	public function test_unlabeled_input_fails(): void {
		$root        = self::plugin_root();
		$marker_dir  = $root . '/admin/partials/__phase43_a11n_unlabeled';
		$marker_path = $marker_dir . '/sscribe-a11n-unlabeled.php';
		$original_existed = is_dir( $marker_dir );

		mkdir( $marker_dir, 0755, true );
		file_put_contents(
			$marker_path,
			"<?php\n// Phase 43 marker. Removed by the test.\n"
			. "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
			. "?>\n"
			. '<div><input type="text" id="sscribe-a11n-bad-input"></div>' . "\n"
		);

		try {
			list( $code, $output ) = $this->run_verifier();
			$this::assertSame(
				1,
				$code,
				'Unlabeled <input> must fail the audit. Output:' . "\n" . $output
			);
			$this::assertStringContainsString( 'sscribe-a11n-unlabeled', $output );
			$this::assertStringContainsString( 'sscribe-a11n-bad-input', $output );
		} finally {
			@unlink( $marker_path );
			@rmdir( $marker_dir );
			$this::assertDirectoryDoesNotExist( $marker_dir );
			$this::assertFalse( $original_existed && is_dir( $marker_dir ), 'Defensive cleanup failed' );
		}
	}

	public function test_icon_only_button_without_aria_label_fails(): void {
		$root        = self::plugin_root();
		$marker_dir  = $root . '/admin/partials/__phase43_a11n_iconbutton';
		$marker_path = $marker_dir . '/sscribe-a11n-iconbutton.php';

		mkdir( $marker_dir, 0755, true );
		file_put_contents(
			$marker_path,
			"<?php\n// Phase 43 marker. Removed by the test.\n"
			. "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
			. "?>\n"
			. '<button type="button" id="sscribe-a11n-ghost-btn">'
			. '<svg width="20" height="20" viewBox="0 0 20 20"><circle cx="10" cy="10" r="8"/></svg>'
			. '</button>' . "\n"
		);

		try {
			list( $code, $output ) = $this->run_verifier();
			$this::assertSame(
				1,
				$code,
				'Icon-only button without aria-label must fail the audit. Output:' . "\n" . $output
			);
			$this::assertStringContainsString( 'sscribe-a11n-ghost-btn', $output );
		} finally {
			@unlink( $marker_path );
			@rmdir( $marker_dir );
		}
	}

	public function test_decorative_svg_without_aria_hidden_fails(): void {
		$root        = self::plugin_root();
		$marker_dir  = $root . '/admin/partials/__phase43_a11n_svg';
		$marker_path = $marker_dir . '/sscribe-a11n-svg.php';

		mkdir( $marker_dir, 0755, true );
		file_put_contents(
			$marker_path,
			"<?php\n// Phase 43 marker. Removed by the test.\n"
			. "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
			. "?>\n"
			. '<p><svg width="16" height="16" viewBox="0 0 16 16"><path d="M0 0h16v16H0z"/></svg>'
			. '<span><?php echo esc_html( \'Hello\' ); ?></span></p>' . "\n"
		);

		try {
			list( $code, $output ) = $this->run_verifier();
			$this::assertSame(
				1,
				$code,
				'<svg> without aria-hidden="true" or role="img" must fail. Output:' . "\n" . $output
			);
			$this::assertStringContainsString( 'needs aria-hidden', $output );
		} finally {
			@unlink( $marker_path );
			@rmdir( $marker_dir );
		}
	}

	public function test_aria_controls_pointing_to_missing_id_fails(): void {
		$root        = self::plugin_root();
		$marker_dir  = $root . '/admin/partials/__phase43_a11n_controls';
		$marker_path = $marker_dir . '/sscribe-a11n-controls.php';

		mkdir( $marker_dir, 0755, true );
		file_put_contents(
			$marker_path,
			"<?php\n// Phase 43 marker. Removed by the test.\n"
			. "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
			. "?>\n"
			. '<button id="sscribe-a11n-tab" aria-controls="sscribe-tab-panel-that-does-not-exist">'
			. '<?php echo esc_html( "Tab" ); ?></button>' . "\n"
		);

		try {
			list( $code, $output ) = $this->run_verifier();
			$this::assertSame(
				1,
				$code,
				'aria-controls pointing to a non-existent id must fail. Output:' . "\n" . $output
			);
			$this::assertStringContainsString( 'aria-controls="sscribe-tab-panel-that-does-not-exist"', $output );
		} finally {
			@unlink( $marker_path );
			@rmdir( $marker_dir );
		}
	}

	public function test_positive_tabindex_fails(): void {
		$root        = self::plugin_root();
		$marker_dir  = $root . '/admin/partials/__phase43_a11n_tabindex';
		$marker_path = $marker_dir . '/sscribe-a11n-tabindex.php';

		mkdir( $marker_dir, 0755, true );
		file_put_contents(
			$marker_path,
			"<?php\n// Phase 43 marker. Removed by the test.\n"
			. "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
			. "?>\n"
			. '<button tabindex="5" id="sscribe-a11n-pos-tab"><?php echo esc_html( "Skip" ); ?></button>' . "\n"
		);

		try {
			list( $code, $output ) = $this->run_verifier();
			$this::assertSame(
				1,
				$code,
				'Positive tabindex must fail. Output:' . "\n" . $output
			);
			$this::assertStringContainsString( 'tabindex="5"', $output );
		} finally {
			@unlink( $marker_path );
			@rmdir( $marker_dir );
		}
	}

	public function test_inline_event_handler_fails(): void {
		$root        = self::plugin_root();
		$marker_dir  = $root . '/admin/partials/__phase43_a11n_inline';
		$marker_path = $marker_dir . '/sscribe-a11n-inline.php';

		mkdir( $marker_dir, 0755, true );
		file_put_contents(
			$marker_path,
			"<?php\n// Phase 43 marker. Removed by the test.\n"
			. "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
			. "?>\n"
			. '<button id="sscribe-a11n-inline-handler" onclick="alert(1)">'
			. '<?php echo esc_html( "Do it" ); ?></button>' . "\n"
		);

		try {
			list( $code, $output ) = $this->run_verifier();
			$this::assertSame(
				1,
				$code,
				'Inline onclick must fail. Output:' . "\n" . $output
			);
			$this::assertStringContainsString( 'inline onclick', $output );
		} finally {
			@unlink( $marker_path );
			@rmdir( $marker_dir );
		}
	}

	public function test_skipped_heading_level_fails(): void {
		$root        = self::plugin_root();
		$marker_dir  = $root . '/admin/partials/__phase43_a11n_heading';
		$marker_path = $marker_dir . '/sscribe-a11n-heading.php';

		mkdir( $marker_dir, 0755, true );
		file_put_contents(
			$marker_path,
			"<?php\n// Phase 43 marker. Removed by the test.\n"
			. "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
			. "?>\n"
			. '<h2><?php echo esc_html( "First" ); ?></h2>'
			. '<h4><?php echo esc_html( "Skipped" ); ?></h4>' . "\n"
		);

		try {
			list( $code, $output ) = $this->run_verifier();
			$this::assertSame(
				1,
				$code,
				'Skipped heading level (h2 → h4) must fail. Output:' . "\n" . $output
			);
			$this::assertStringContainsString( 'Heading hierarchy skip', $output );
		} finally {
			@unlink( $marker_path );
			@rmdir( $marker_dir );
		}
	}
}
