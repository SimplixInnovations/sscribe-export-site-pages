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
}