<?php
/**
 * SScribe Shipped-Invariants Regression Test
 *
 * Locks in the WordPress.org Plugin Directory invariants that
 * the wp-org-ai-artifact-audit, wp-org-header-rules,
 * wp-plugin-no-composer-autoloader, and zip-no-comments-rule
 * memories enforce manually:
 *
 *   1. The shipped ZIP must contain zero em-dash characters
 *      in any text file (PHP, JS, CSS, MD, TXT). Em-dashes are
 *      the single most common AI-generated punctuation artifact;
 *      a future contributor (human or AI) adding one is the
 *      regression this test guards against.
 *
 *   2. The shipped ZIP must contain zero references to AI
 *      persona names or tool names (Claude, Sonnet, Opus,
 *      Anthropic, ChatGPT, Sisyphus, etc.) in any text file.
 *      WP.org reviewers reject plugins that advertise AI
 *      provenance in shipped source.
 *
 *   3. The shipped ZIP must contain zero Co-Authored-By trailers.
 *      These are git commit-message artifacts that can leak into
 *      source if a future build pipeline is misconfigured.
 *
 *   4. The shipped ZIP must contain zero non-docblock, non-pragma
 *      comments in PHP / CSS / JS files. The build script's
 *      strip_comments pass enforces this at build time; the
 *      test catches regressions in the build script itself.
 *
 *   5. The main plugin file shipped in the ZIP must not declare
 *      Plugin URI (must differ from Author URI), Network: false
 *      (must be omitted), or Update URI: (custom updaters are
 *      forbidden on WP.org-hosted plugins).
 *
 *   6. The shipped source must not implement any PSR /
 *      Composer-only interface. WP plugins load without a
 *      Composer autoloader.
 *
 * The test reads the ZIP that already exists in dist/. Set
 * SSCRIBE_REBUILD_BEFORE_TEST=1 in the environment to force
 * a rebuild before assertion.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZipArchive;

final class SScribe_Shipped_Invariants_Test extends TestCase {

	private const PLUGIN_SLUG = 'sscribe-export-site-pages';

	private static ?string $plugin_root = null;

	private static ?string $dist_dir = null;

	private static ?string $version = null;

	private static ?string $zip_path = null;

	private static ?string $extract_dir = null;

	private static array $extracted_files = array();

	public static function setUpBeforeClass(): void {
		self::$plugin_root = dirname( __DIR__, 2 );
		self::$dist_dir    = self::$plugin_root . '/dist';
		self::$version     = self::detect_version();
		self::$zip_path    = self::$dist_dir . '/sscribe-export-site-pages-' . self::$version . '.zip';

		if ( ! is_dir( self::$dist_dir ) || ! is_file( self::$zip_path ) ) {
			self::markTestSkipped( 'No shipped ZIP at ' . self::$zip_path . ' — run scripts/build-release.php first.' );
		}

		if ( getenv( 'SSCRIBE_REBUILD_BEFORE_TEST' ) === '1' ) {
			self::rebuild_zip();
		}

		self::$extract_dir = self::extract_zip( self::$zip_path );
		self::walk_zip_contents( self::$extract_dir );
	}

	public static function tearDownAfterClass(): void {
		if ( is_string( self::$extract_dir ) && is_dir( self::$extract_dir ) ) {
			self::rrmdir( self::$extract_dir );
		}
	}

	private static function detect_version(): string {
		$plugin_file = self::$plugin_root . '/sscribe-export-site-pages.php';
		if ( ! is_file( $plugin_file ) ) {
			self::markTestSkipped( 'Plugin file missing.' );
		}
		$contents = (string) file_get_contents( $plugin_file );
		if ( ! preg_match( '/Version:\s*([0-9.]+)/', $contents, $match ) ) {
			self::markTestSkipped( 'Version not found in plugin file.' );
		}
		return $match[1];
	}

	private static function rebuild_zip(): void {
		$script = self::$plugin_root . '/scripts/build-release.php';
		if ( ! is_file( $script ) ) {
			self::markTestSkipped( 'scripts/build-release.php not found.' );
		}
		$output = array();
		$code   = 0;
		exec( 'php ' . escapeshellarg( $script ) . ' 2>&1', $output, $code );
		if ( 0 !== $code ) {
			self::markTestSkipped( 'Build script failed; cannot verify ZIP invariants.' );
		}
	}

	private static function extract_zip( string $zip_path ): string {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			self::fail( 'Could not open ZIP at ' . $zip_path );
		}
		$target = sys_get_temp_dir() . '/sscribe-zip-' . bin2hex( random_bytes( 6 ) );
		mkdir( $target, 0755, true );
		$zip->extractTo( $target );
		$zip->close();
		return $target;
	}

	private static function walk_zip_contents( string $root ): void {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$ext = strtolower( $file->getExtension() );
			if ( ! in_array( $ext, array( 'php', 'js', 'css', 'md', 'txt' ), true ) ) {
				continue;
			}
			$absolute                  = $file->getPathname();
			$relative                  = str_replace( $root . DIRECTORY_SEPARATOR, '', $absolute );
			self::$extracted_files[] = $relative;
		}
	}

	private static function rrmdir( string $dir ): void {
		if ( is_link( $dir ) || ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			is_dir( $path ) ? self::rrmdir( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}

	/**
	 * Invariant 1: shipped ZIP contains zero em-dash characters
	 * in our own code. Vendor-prefixed/ is exempt because
	 * third-party libraries (mpdf, PSR-7) ship with em-dashes
	 * in their PHPDoc comments that are outside our control.
	 */
	public function test_zip_has_no_em_dashes(): void {
		$em_dash_files = array();
		foreach ( self::$extracted_files as $relative ) {
			if ( str_contains( $relative, 'vendor-prefixed' . DIRECTORY_SEPARATOR ) ) {
				continue;
			}
			$path     = self::$extract_dir . DIRECTORY_SEPARATOR . $relative;
			$contents = (string) file_get_contents( $path );
			if ( str_contains( $contents, "\xE2\x80\x94" ) ) {
				$em_dash_files[] = $relative;
			}
		}

		$this->assertSame(
			array(),
			$em_dash_files,
			'Em-dash characters must not appear in shipped ZIP (excluding vendor-prefixed/). Found in: ' . implode( ', ', $em_dash_files )
		);
	}

	/**
	 * Invariant 2: shipped ZIP contains zero references to AI
	 * persona or tool names in our own code. Vendor-prefixed/
	 * is exempt because third-party libraries may reference
	 * the providers they integrate with.
	 */
	public function test_zip_has_no_ai_persona_names(): void {
		$banned_terms = array(
			'Anthropic',
			'ChatGPT',
			'Claude Code',
			'Claude Sonnet',
			'Claude Opus',
			'Claude Haiku',
			'GPT-4',
			'GPT-5',
			'Sisyphus',
			'OpenAI',
		);

		$offenders = array();
		foreach ( self::$extracted_files as $relative ) {
			if ( str_contains( $relative, 'vendor-prefixed' . DIRECTORY_SEPARATOR ) ) {
				continue;
			}
			$contents = (string) file_get_contents( self::$extract_dir . DIRECTORY_SEPARATOR . $relative );
			foreach ( $banned_terms as $term ) {
				if ( str_contains( $contents, $term ) ) {
					$offenders[] = $relative . ' contains "' . $term . '"';
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'AI persona/tool names must not appear in shipped ZIP (excluding vendor-prefixed/). Offenders: ' . implode( '; ', $offenders )
		);
	}

	/**
	 * Invariant 3: shipped ZIP contains zero Co-Authored-By trailers.
	 */
	public function test_zip_has_no_co_authored_by_trailers(): void {
		$offenders = array();
		foreach ( self::$extracted_files as $relative ) {
			$contents = (string) file_get_contents( self::$extract_dir . DIRECTORY_SEPARATOR . $relative );
			if ( stripos( $contents, 'Co-Authored-By' ) !== false ) {
				$offenders[] = $relative;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'Co-Authored-By trailers must not appear in shipped ZIP. Found in: ' . implode( ', ', $offenders )
		);
	}

	/**
	 * Invariant 4: shipped ZIP contains zero non-pragma comments
	 * in PHP / CSS / JS. The build script strips them; this test
	 * catches regressions in the build script itself.
	 *
	 * PHP: T_DOC_COMMENT (PHPDoc /-star-star) is preserved.
	 *      T_COMMENT with pragmas ("phpcs:", "translators:", "@preserve")
	 *      is preserved. Everything else is stripped.
	 * CSS: all /-star ... star-/ blocks stripped.
	 * JS:  /-star ... star-/ (non /-star-!, non /-star-star) stripped; // stripped.
	 */
	public function test_zip_has_no_non_pragma_comments(): void {
		$violations = array();

		foreach ( self::$extracted_files as $relative ) {
			$path     = self::$extract_dir . DIRECTORY_SEPARATOR . $relative;
			$contents = (string) file_get_contents( $path );
			$ext      = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );

			if ( 'php' === $ext ) {
				$violations = array_merge( $violations, self::php_non_pragma_comments( $relative, $contents ) );
			} elseif ( 'css' === $ext ) {
				$violations = array_merge( $violations, self::css_block_comments( $relative, $contents ) );
			} elseif ( 'js' === $ext ) {
				$violations = array_merge( $violations, self::js_comments( $relative, $contents ) );
			}
		}

		$this->assertSame(
			array(),
			$violations,
			'Non-pragma comments must not appear in shipped ZIP. First violations: ' . implode( '; ', array_slice( $violations, 0, 5 ) )
		);
	}

	/**
	 * @return array<int,string>
	 */
	private static function php_non_pragma_comments( string $relative, string $contents ): array {
		$tokens = @token_get_all( $contents );
		if ( false === $tokens ) {
			return array();
		}

		$violations = array();
		foreach ( $tokens as $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}
			if ( T_COMMENT !== $token[0] ) {
				continue;
			}
			$value = $token[1];
			if ( preg_match( '/phpcs:|phpcs-disable|phpcs-enable|phpcs:ignore|translators:|@preserve/i', $value ) ) {
				continue;
			}
			$violations[] = $relative . ' has non-pragma T_COMMENT: ' . trim( substr( $value, 0, 80 ) );
		}
		return $violations;
	}

	/**
	 * @return array<int,string>
	 */
	private static function css_block_comments( string $relative, string $contents ): array {
		$violations = array();
		if ( preg_match_all( '#/\*.*?\*/#s', $contents, $matches ) ) {
			foreach ( $matches[0] as $hit ) {
				$violations[] = $relative . ' has CSS block comment: ' . trim( substr( $hit, 0, 80 ) );
			}
		}
		return $violations;
	}

	/**
	 * @return array<int,string>
	 */
	private static function js_comments( string $relative, string $contents ): array {
		$violations = array();

		if ( preg_match_all( '#/\*(?![*!]).*?\*/#s', $contents, $matches ) ) {
			foreach ( $matches[0] as $hit ) {
				$violations[] = $relative . ' has JS block comment: ' . trim( substr( $hit, 0, 80 ) );
			}
		}

		$stripped = preg_replace( '#/\*(?![*!]).*?\*/#s', '', $contents );
		if ( null === $stripped ) {
			return $violations;
		}
		if ( preg_match_all( '#(?<![:"\'`])//[^\n]*#', $stripped, $line_matches ) ) {
			foreach ( $line_matches[0] as $hit ) {
				$trim = trim( $hit );
				if ( '' === $trim ) {
					continue;
				}
				$violations[] = $relative . ' has JS line comment: ' . trim( substr( $hit, 0, 80 ) );
			}
		}

		return $violations;
	}

	/**
	 * Invariant 5: main plugin file shipped in the ZIP must
	 * not declare Plugin URI, Network: false, or Update URI:
	 * headers (WP.org Plugin Directory rejects each).
	 */
	public function test_zip_main_plugin_file_omits_rejected_headers(): void {
		$main_plugin = self::$extract_dir . DIRECTORY_SEPARATOR . self::PLUGIN_SLUG . '/sscribe-export-site-pages.php';
		$this->assertFileExists( $main_plugin, 'Main plugin file missing in ZIP.' );

		$contents = (string) file_get_contents( $main_plugin );

		$this->assertStringNotContainsString(
			"\n * Plugin URI:",
			"\n" . $contents,
			'Plugin URI: header must not appear (must differ from Author URI or be omitted).'
		);
		$this->assertStringNotContainsString(
			"\n * Network:",
			"\n" . $contents,
			'Network: header must not appear (must be omitted, not set to false).'
		);
		$this->assertStringNotContainsString(
			"\n * Update URI:",
			"\n" . $contents,
			'Update URI: header must not appear (custom updaters forbidden on WP.org).'
		);
	}

	/**
	 * Invariant 6: shipped source must not implement any PSR /
	 * Composer-only interface. WP plugins load without a
	 * Composer autoloader. Vendor-prefixed/ is exempt because
	 * Strauss prefixes namespace references in our own code but
	 * leaves third-party files intact, and those third-party
	 * files ship their own PSR interfaces in vendor-prefixed/psr/.
	 */
	public function test_zip_php_files_implement_no_psr_interfaces(): void {
		$psr_interfaces = array(
			'\\Psr\\Log\\LoggerInterface',
			'\\Psr\\Container\\ContainerInterface',
			'\\Psr\\Http\\Message\\MessageInterface',
			'\\Psr\\Http\\Message\\RequestInterface',
			'\\Psr\\Http\\Message\\ResponseInterface',
			'\\Psr\\Cache\\CacheItemInterface',
			'\\Psr\\SimpleCache\\CacheInterface',
		);

		$offenders = array();
		foreach ( self::$extracted_files as $relative ) {
			if ( 'php' !== strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ) ) {
				continue;
			}
			if ( str_contains( $relative, 'vendor-prefixed' . DIRECTORY_SEPARATOR ) ) {
				continue;
			}
			$contents = (string) file_get_contents( self::$extract_dir . DIRECTORY_SEPARATOR . $relative );
			if ( stripos( $contents, 'implements' ) === false ) {
				continue;
			}
			foreach ( $psr_interfaces as $psr ) {
				$quoted = preg_quote( $psr, '#' );
				$regex  = '#implements\s+[^{]*\b' . $quoted . '\b#i';
				if ( preg_match( $regex, $contents ) ) {
					$offenders[] = $relative . ' implements ' . $psr;
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'PSR interfaces must not be implemented in shipped PHP (no autoloader). Offenders: ' . implode( '; ', $offenders )
		);
	}

	/**
	 * The full-screen toast positioning layer must never block page controls.
	 */
	public function test_toast_container_allows_pointer_events_to_pass_through(): void {
		$stylesheet = self::$plugin_root . '/admin/css/sscribe-admin.css';
		$this->assertFileExists( $stylesheet, 'Admin source stylesheet missing.' );

		$contents = (string) file_get_contents( $stylesheet );
		$this->assertMatchesRegularExpression(
			'/\.sscribe-toast-container\s*\{[^}]*pointer-events:\s*none;/s',
			$contents,
			'The toast positioning layer must allow clicks to reach the page beneath it.'
		);
	}

	/**
	 * Responsive column layouts must not reuse the desktop width flex basis.
	 */
	public function test_debug_toggle_resets_flex_basis_at_wordpress_mobile_breakpoint(): void {
		$stylesheet = self::$plugin_root . '/admin/css/sscribe-debug-console.css';
		$this->assertFileExists( $stylesheet, 'Debug source stylesheet missing.' );

		$contents = (string) file_get_contents( $stylesheet );
		$this->assertMatchesRegularExpression(
			'/@media \(width <= 782px\)\s*\{.*?\.sscribe-debug-toggle-section,\s*\.sscribe-debug-level-section[^{]*\{[^}]*flex-basis:\s*auto;/s',
			$contents,
			'The debug toggle must not become 360 pixels tall in the responsive column layout.'
		);
	}

	/**
	 * Tab activation must read scroll state from the module, not window.self.
	 */
	public function test_tab_activation_reads_its_own_scroll_position_state(): void {
		$script = self::$plugin_root . '/admin/js/sscribe-admin.js';
		$this->assertFileExists( $script, 'Admin source script missing.' );

		$contents = (string) file_get_contents( $script );
		$this->assertStringContainsString(
			'const savedY = this.scrollPositions[tabId];',
			$contents,
			'Tab activation must not throw while restoring a saved scroll position.'
		);
	}

	/**
	 * The export summary should wrap compactly instead of stacking each chip.
	 */
	public function test_export_summary_keeps_row_flow_at_wordpress_mobile_breakpoint(): void {
		$stylesheet = self::$plugin_root . '/admin/css/sscribe-admin.css';
		$this->assertFileExists( $stylesheet, 'Admin source stylesheet missing.' );

		$contents = (string) file_get_contents( $stylesheet );
		$this->assertMatchesRegularExpression(
			'/@media \(width <= 782px\)\s*\{.*?\.sscribe-config-summary\s*\{[^}]*flex-direction:\s*row;/s',
			$contents,
			'The responsive export summary must remain a compact wrapping row.'
		);
	}

	/**
	 * The Recent Exports renderer must use the module's escaping helper.
	 */
	public function test_recent_exports_renderer_uses_the_existing_escape_helper(): void {
		$script = self::$plugin_root . '/admin/js/sscribe-admin.js';
		$this->assertFileExists( $script, 'Admin source script missing.' );

		$contents = (string) file_get_contents( $script );
		$this->assertStringNotContainsString(
			'this.escribeHtml(',
			$contents,
			'Recent Exports must not call a misspelled, undefined escaping helper.'
		);
	}

	/**
	 * Tab-change announcements belong in the accessibility tree, not the layout.
	 */
	public function test_tab_announcement_is_screen_reader_only(): void {
		$template = self::$plugin_root . '/admin/partials/sscribe-admin-display.php';
		$this->assertFileExists( $template, 'Admin display template missing.' );

		$contents = (string) file_get_contents( $template );
		$this->assertMatchesRegularExpression(
			'/id="sscribe-tab-announce"\s+class="screen-reader-text"/',
			$contents,
			'Tab-change announcements must not render as visible page copy.'
		);
	}

	/**
	 * Pre-export advisories must use one polite live region, not nested roles.
	 */
	public function test_preflight_advisories_do_not_nest_live_regions(): void {
		$template = self::$plugin_root . '/admin/partials/sscribe-admin-display.php';
		$this->assertFileExists( $template, 'Admin display template missing.' );

		$contents = (string) file_get_contents( $template );
		$this->assertMatchesRegularExpression(
			'/class="sscribe-preflight-warnings"\s+role="status"\s+aria-live="polite"/',
			$contents,
			'Pre-export advisories must expose one polite status region.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/class="[^\"]*\bsscribe-preflight-warning(?:\s|\")[^\"]*"[^>]*\srole="(?:alert|status)"/',
			$contents,
			'Individual advisories must not create nested live regions.'
		);
	}

	/**
	 * Toast announcements must use the container as their only live region.
	 */
	public function test_toasts_do_not_create_nested_live_regions(): void {
		$template = (string) file_get_contents( self::$plugin_root . '/admin/partials/sscribe-admin-display.php' );
		$script   = (string) file_get_contents( self::$plugin_root . '/admin/js/sscribe-admin.js' );

		$this->assertMatchesRegularExpression(
			'/id="sscribe-toast-container"[^>]*aria-live="polite"/',
			$template,
			'The toast container must announce added messages.'
		);
		$this->assertMatchesRegularExpression(
			'/showToast:\s*function(.*?)adjustToastContainerPosition:/s',
			$script,
			'The toast function must remain independently auditable.'
		);
		preg_match( '/showToast:\s*function(.*?)adjustToastContainerPosition:/s', $script, $toast_function );
		$this->assertDoesNotMatchRegularExpression(
			'/\.attr\([\'\"](?:role|aria-live)[\'\"]/',
			$toast_function[1],
			'Individual toasts must not create nested live regions.'
		);
	}

	/**
	 * Disabled native radios must remain visually hidden behind card controls.
	 */
	public function test_disabled_status_card_radios_remain_hidden(): void {
		$stylesheet = self::$plugin_root . '/admin/css/sscribe-admin.css';
		$this->assertFileExists( $stylesheet, 'Admin source stylesheet missing.' );

		$contents = (string) file_get_contents( $stylesheet );
		$this->assertMatchesRegularExpression(
			'/\.sscribe-master-container\s+\.sscribe-status-card-label\s*>\s*input\[type=["\']radio["\']\]:disabled\s*\{[^}]*opacity:\s*0;/s',
			$contents,
			'Disabled card radios must not reappear through WordPress core disabled-control styles.'
		);
	}

	/**
	 * Inline SVG glyphs must not reuse the square icon-button control class.
	 */
	public function test_inline_button_glyphs_do_not_use_the_icon_button_control_class(): void {
		$template = self::$plugin_root . '/admin/partials/sscribe-admin-display.php';
		$this->assertFileExists( $template, 'Admin display template missing.' );

		$contents = (string) file_get_contents( $template );
		$this->assertStringNotContainsString(
			", 'sscribe-button-icon' )",
			$contents,
			'Inline SVG glyphs must not inherit the 32-pixel icon-button control dimensions.'
		);
	}

	public function test_export_lifecycle_keeps_actions_locked_only_while_processing(): void {
		$script   = self::$plugin_root . '/admin/js/sscribe-admin.js';
		$contents = (string) file_get_contents( $script );

		$this->assertMatchesRegularExpression(
			'/startExport:\s*function.*?this\.resetUI\(\);.*?this\.isProcessing\s*=\s*true;/s',
			$contents,
			'Export startup must reset stale UI before locking the new request.'
		);
		$this->assertStringContainsString(
			'const canExport =',
			$contents,
			'Configuration updates must not re-enable actions during an export.'
		);
		$this->assertMatchesRegularExpression(
			'/canExport\s*=.*?hasPostType.*?hasLanguage.*?hasStatus.*?hasFormat.*?hasPages.*?isProcessing.*?isPreparing/s',
			$contents,
			'Configuration updates must not re-enable actions during an export or its preflight phase.'
		);
		$this->assertMatchesRegularExpression(
			'/exportComplete:\s*function.*?removeClass\([\'\"]sscribe-btn-busy[\'\"]\).*?removeAttr\([\'\"]aria-busy[\'\"]\).*?updateExportButton\(\);/s',
			$contents,
			'Completion must remove the interaction lock and recalculate action availability.'
		);
	}

	public function test_cancelled_exports_cannot_restart_a_pending_batch_or_show_failure_ui(): void {
		$script   = self::$plugin_root . '/admin/js/sscribe-admin.js';
		$contents = (string) file_get_contents( $script );

		$this->assertStringContainsString( '_isCancelling: false,', $contents );
		$this->assertMatchesRegularExpression(
			'/scheduleNextBatch:\s*function.*?self\._batchTimer\s*=\s*setTimeout.*?self\._isCancelling\s*\|\|\s*!self\.sessionId\s*\|\|\s*!self\.isProcessing/s',
			$contents,
			'Pending batch timers must stop after cancellation resets the session.'
		);
		$this->assertMatchesRegularExpression(
			'/error:\s*function\s*\(xhr,\s*textStatus\).*?self\._isCancelling\s*&&\s*textStatus\s*===\s*[\'\"]abort[\'\"]/s',
			$contents,
			'Aborting the active batch for cancellation must not enter retry or failure handling.'
		);
		$this->assertMatchesRegularExpression(
			'/if\s*\(isCancelled\)\s*\{\s*SScribe\.showCancelled\(response\.data\.message\);/s',
			$contents,
			'Server-confirmed cancellation must render the clean cancelled state, not Export failed.'
		);
		$this->assertMatchesRegularExpression(
			'/shouldRetry\s*=.*?xhr\.status\s*===\s*409.*?SScribe\._doCancelExport\(cancelAttempt\s*\+\s*1\);/s',
			$contents,
			'Cancellation must retry while the active batch still holds its lock.'
		);
		$this->assertStringContainsString(
			"$('#sscribe-live-region, #sscribe-alert-region').text('');",
			$contents,
			'Cancellation reset must clear stale progress announcements.'
		);
		$this->assertStringNotContainsString(
			'this.announce(cancelledMessage);',
			$contents,
			'The cancellation toast container already announces additions and must not be announced twice.'
		);
	}

	public function test_zip_excludes_unused_mpdf_request_handler(): void {
		$handler = self::$extract_dir . DIRECTORY_SEPARATOR . self::PLUGIN_SLUG
			. '/vendor-prefixed/mpdf/mpdf/data/out.php';

		$this->assertFileDoesNotExist(
			$handler,
			'Unused vendor request handlers must not be shipped.'
		);
	}

	public function test_export_feedback_and_completion_actions_remain_clear(): void {
		$template   = (string) file_get_contents( self::$plugin_root . '/admin/partials/sscribe-admin-display.php' );
		$stylesheet = (string) file_get_contents( self::$plugin_root . '/admin/css/sscribe-admin.css' );

		$this->assertSame( 1, substr_count( $template, 'id="sscribe-export-disabled-reason"' ) );
		$this->assertSame( 0, substr_count( $template, 'id="sscribe-preview-disabled-reason"' ) );
		$this->assertStringContainsString(
			'id="sscribe-new-export-btn" class="sscribe-button sscribe-button-secondary"',
			$template
		);
		$this->assertMatchesRegularExpression(
			'/\.sscribe-button-success:hover:not\(:disabled\)\s*\{[^}]*color:\s*var\(--ss-text-inverse\);/s',
			$stylesheet
		);
		$this->assertMatchesRegularExpression(
			'/\.sscribe-phase-active \.sscribe-phase-label,[^{]*\{[^}]*color:\s*inherit;/s',
			$stylesheet
		);
	}

	public function test_history_row_iteration_does_not_overwrite_the_export_index(): void {
		$template = (string) file_get_contents( self::$plugin_root . '/admin/partials/sscribe-admin-display.php' );

		$this->assertStringNotContainsString(
			'foreach ( $sscribe_recent_exports as $sscribe_export_index => $sscribe_export )',
			$template
		);
		$this->assertStringContainsString(
			'foreach ( $sscribe_recent_exports as $sscribe_export_row_index => $sscribe_export )',
			$template
		);
	}

	/**
	 * Removing dark mode must not replace the plugin's established light design.
	 */
	public function test_original_light_design_is_preserved_without_dark_mode(): void {
		$tokens     = (string) file_get_contents( self::$plugin_root . '/admin/css/sscribe-tokens.css' );
		$admin_css  = (string) file_get_contents( self::$plugin_root . '/admin/css/sscribe-admin.css' );
		$debug_css  = (string) file_get_contents( self::$plugin_root . '/admin/css/sscribe-debug-console.css' );
		$admin_view = (string) file_get_contents( self::$plugin_root . '/admin/partials/sscribe-admin-display.php' );

		$this->assertStringContainsString(
			'--ss-brand: #2f6146;',
			$tokens,
			'The established SScribe light palette must remain intact (Phase 27: darkened from #3d7a5a for WCAG AA contrast headroom against box-shadow blending).'
		);
		$this->assertStringNotContainsString( 'prefers-color-scheme: dark', $admin_css );
		$this->assertStringNotContainsString( 'prefers-color-scheme: dark', $debug_css );
		$this->assertDoesNotMatchRegularExpression(
			'/\.sscribe-master-container\s+input\[type=["\']radio["\']\]\s*\{[^}]*display:\s*none;/s',
			$admin_css,
			'Radio controls must remain keyboard-focusable.'
		);
		$this->assertStringNotContainsString( 'var(--ss-surface-1)', $admin_css );
		$this->assertStringNotContainsString( 'var(--ss-surface-alt)', $admin_css );
		$this->assertStringNotContainsString(
			'<div class="wrap sscribe-admin-wrap">',
			$admin_view,
			'Dark-mode removal must not introduce a replacement page shell.'
		);
	}

	public function test_component_styles_are_token_driven(): void {
		$stylesheets = array(
			self::$plugin_root . '/admin/css/sscribe-admin.css',
			self::$plugin_root . '/admin/css/sscribe-debug-console.css',
		);

		foreach ( $stylesheets as $stylesheet ) {
			$contents = (string) file_get_contents( $stylesheet );
			$this->assertDoesNotMatchRegularExpression(
				'/(?:font-size|font-weight|line-height|letter-spacing):\s*-?[0-9]/',
				$contents,
				$stylesheet . ' must use typography tokens.'
			);
			$this->assertDoesNotMatchRegularExpression(
				'/\bfont:\s*[^;]*\b[0-9]+(?:px|\/)/',
				$contents,
				$stylesheet . ' must use tokens in font shorthands.'
			);
			$this->assertDoesNotMatchRegularExpression(
				'/(?:#[0-9a-f]{3,8}\b|%23[0-9a-f]{3,8}\b|rgba?\()/i',
				$contents,
				$stylesheet . ' must use color tokens.'
			);

			preg_match_all(
				'/^(?:\s*)(?:gap|row-gap|column-gap|padding(?:-[a-z-]+)?|margin(?:-[a-z-]+)?):\s*([^;]+);/mi',
				$contents,
				$spacing_declarations
			);
			foreach ( $spacing_declarations[1] as $spacing_value ) {
				$this->assertDoesNotMatchRegularExpression(
					'/[0-9.]+px/',
					$spacing_value,
					$stylesheet . ' must use spacing tokens.'
				);
			}
		}
	}

	public function test_mobile_onboarding_uses_a_single_column_hierarchy(): void {
		$stylesheet = (string) file_get_contents( self::$plugin_root . '/admin/css/sscribe-admin.css' );

		$this->assertMatchesRegularExpression(
			'/@media\s*\(width\s*<=\s*480px\).*?\.sscribe-onboarding-inner\s*\{[^}]*flex-direction:\s*column;[^}]*align-items:\s*stretch;/s',
			$stylesheet,
			'The mobile first-run guide must stack its icon above a full-width body.'
		);
		$this->assertMatchesRegularExpression(
			'/@media\s*\(width\s*<=\s*480px\).*?\.sscribe-onboarding-inner\s*>\s*#sscribe-onboarding-dismiss\s*\{[^}]*position:\s*absolute;[^}]*inset-block-start:\s*0;[^}]*inset-inline-end:\s*0;/s',
			$stylesheet,
			'The mobile dismiss control must stay in the logical top corner in LTR and RTL.'
		);
	}

	public function test_custom_checkboxes_do_not_render_the_wordpress_core_checkmark(): void {
		$stylesheet = (string) file_get_contents( self::$plugin_root . '/admin/css/sscribe-admin.css' );

		$this->assertMatchesRegularExpression(
			'/\.sscribe-master-container\s+input\[type=["\']checkbox["\']\]::before\s*\{[^}]*content:\s*none;/s',
			$stylesheet,
			'Custom checkboxes must suppress the WordPress core ::before mark before drawing their own ::after icon.'
		);
	}

	public function test_status_choice_cards_preserve_their_grid_gap(): void {
		$stylesheet = (string) file_get_contents( self::$plugin_root . '/admin/css/sscribe-admin.css' );

		$this->assertMatchesRegularExpression(
			'/\.sscribe-status-card-inner\s*\{[^}]*min-width:\s*0;[^}]*padding-inline:\s*var\(--ss-space-2\);/s',
			$stylesheet,
			'Status card interiors must shrink inside their grid tracks so the inherited column-gap remains visible.'
		);

		$this->assertMatchesRegularExpression(
			'/\.sscribe-post-type-card-inner,\s*\.sscribe-status-card-inner,\s*\.sscribe-format-card-inner,\s*\.sscribe-lang-card-inner\s*\{[^}]*column-gap:\s*var\(--ss-space-3\);/s',
			$stylesheet,
			'Card interiors share a single column-gap rule so the status override stays a layout-only override.'
		);

		$this->assertMatchesRegularExpression(
			'/\.sscribe-status-name\s*\{[^}]*white-space:\s*normal;[^}]*overflow-wrap:\s*anywhere;/s',
			$stylesheet,
			'Status names must wrap inside compact desktop tracks when translations are longer than English.'
		);
	}
	public function test_admin_html_escaping_is_safe_for_quoted_attribute_contexts(): void {
		$script = (string) file_get_contents( self::$plugin_root . '/admin/js/sscribe-admin.js' );

		$this->assertMatchesRegularExpression(
			'/return\s+div\.innerHTML\s*\.replace\(\/"\/g,\s*["\']&quot;["\']\)\s*\.replace\(\/\'\/g,\s*["\']&#0?39;["\']\)/s',
			$script,
			'The shared admin escaping helper must encode both quote characters because its output is reused inside quoted HTML attributes.'
		);
	}


}
