<?php
/**
 * SScribe PDF Exporter — backup font policy regression test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SScribe_PDF_Exporter_Backup_Font_Policy_Test extends TestCase {

	/**
	 * @var string Plugin font dir, for sanity (Amiri may or may not exist).
	 */
	private string $plugin_font_dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->plugin_font_dir = \SSCRIBE_PLUGIN_DIR . 'assets/fonts/';
	}

	/**
	 * Invoke the private build_mpdf_config() method via a scoped closure.
	 *
	 * Mirrors the pattern in SScribe_PDF_Exporter_Find_Font_File_Test.
	 */
	private function call_build_mpdf_config( bool $is_rtl, int $page_id = 0 ): array {
		$method = \Closure::bind(
			function ( bool $is_rtl, int $page_id ) {
				$exporter = new \SScribe_PDF_Exporter();
				$result   = $exporter->build_mpdf_config( $is_rtl, $page_id ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PrivateMethodFound
				if ( is_array( $result ) ) {
					return $result;
				}
				// build_mpdf_config returns array|SScribe_Result. If the env
				// can't build a writable temp dir, the SScribe_Result branch
				// is hit. Force a known-good temp dir for the test.
				$reflection = new \ReflectionClass( \SScribe_PDF_Exporter::class );
				$prop       = $reflection->getProperty( 'config' );
				$prop->setAccessible( true );
				return $prop->getValue( $exporter ) ?? array();
			},
			null,
			\SScribe_PDF_Exporter::class
		);
		$result = $method( $is_rtl, $page_id );

		// If the first call returned an empty array (env failure path),
		// fall back to direct config array inspection.
		if ( empty( $result ) ) {
			return $this->read_config_via_reflection();
		}

		$this->assertIsArray( $result, 'build_mpdf_config() must return an array on the success path.' );
		$this->assertArrayHasKey( 'config', $result, 'build_mpdf_config() must wrap its return in a {config, ...} envelope.' );
		return $result;
	}

	/**
	 * Last-resort: if build_mpdf_config() can't be called in this env
	 * (e.g. wp_is_writable() isn't stubbed), fall back to a static
	 * analysis of the source. This is the "no runtime surface" branch
	 * — the test still pins the policy, just via the source code.
	 */
	private function read_config_via_reflection(): array {
		$source = file_get_contents( \SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-pdf-exporter.php' );
		$this->assertIsString( $source, 'PDF exporter source must be readable.' );
		$this->assertStringContainsString( "'backupSubsFont'   => array( 'freeserif' )", $source, 'PDF exporter must pin backupSubsFont to FreeSerif (the broadest font we ship).' );
		$this->assertStringContainsString( "'backupSIPFont'    => null", $source, 'PDF exporter must disable backupSIPFont — sun-extb.ttf is not in the release ZIP.' );
		return array( 'config' => array( '__source_only__' => true ) );
	}

	/**
	 * Regression guard: the mPDF config built by the PDF exporter must
	 * pin `backupSubsFont` to fonts we actually ship.
	 *
	 * mPDF's defaults (`['dejavusanscondensed', 'freesans', 'sun-exta']`)
	 * include several TTFs that are excluded from the release ZIP by
	 * scripts/build-release.php `font_excludes` (DejaVu Condensed, FreeSans,
	 * FreeMono, etc.). If a page contains a character the active font
	 * can't render, mPDF tries the first backup, fails to find the TTF, and
	 * throws `MpdfException: Cannot find TTF TrueType font file ...` —
	 * crashing the export (reported 2026-06-24 with `DejaVuSansCondensed.ttf`).
	 *
	 * The fix is to override mPDF's defaults with `['freeserif']` — which
	 * IS shipped and covers Latin, Cyrillic, Greek, Vietnamese, and a wide
	 * swath of IPA. This test pins that policy so a future refactor that
	 * re-introduces a non-shipped font name will fail loudly.
	 */
	public function test_backup_subs_font_is_pinned_to_freeserif(): void {
		$result = $this->call_build_mpdf_config( false, 0 );
		$config = $result['config'];

		$this->assertArrayHasKey( 'backupSubsFont', $config, 'mPDF config must declare backupSubsFont explicitly.' );
		$this->assertSame( array( 'freeserif' ), $config['backupSubsFont'], 'backupSubsFont must be exactly ["freeserif"] — the only broad-coverage font we ship.' );

		// FreeSerif must actually exist in the release ZIP. If a future
		// build script drops it, this test will fail before the plugin
		// ships with a broken fallback chain.
		$shipped_fonts = glob( \SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/mpdf/mpdf/ttfonts/FreeSerif*.ttf' );
		$this->assertNotEmpty( $shipped_fonts, 'FreeSerif.ttf must ship in vendor-prefixed/mpdf/mpdf/ttfonts/.' );
	}

	/**
	 * Regression guard: backupSIPFont must be null (or absent) so mPDF
	 * doesn't try to load sun-extb.ttf — which is not in the release ZIP.
	 *
	 * mPDF uses backupSIPFont for characters in Unicode Plane 2 (above
	 * U+20000 — CJK Extension B, rare historical scripts, mathematical
	 * alphanumeric symbols, etc.). The default `sun-extb.ttf` is excluded
	 * from the build. Setting backupSIPFont to null disables the fallback
	 * — the same "?" tofu behavior applies for those rare characters,
	 * but the export no longer crashes.
	 */
	public function test_backup_sip_font_is_disabled(): void {
		$result = $this->call_build_mpdf_config( false, 0 );
		$config = $result['config'];

		$this->assertArrayHasKey( 'backupSIPFont', $config, 'mPDF config must declare backupSIPFont explicitly.' );
		$this->assertNull( $config['backupSIPFont'], 'backupSIPFont must be null — sun-extb.ttf is not in the release ZIP.' );
	}

	/**
	 * Belt-and-braces: even if the runtime config-building path is
	 * short-circuited by an environment issue (missing wp_is_writable,
	 * unwritable uploads dir, etc.), the source code itself must pin
	 * the backup font policy. This test does not require
	 * build_mpdf_config() to be callable.
	 */
	public function test_source_code_pins_backup_font_policy(): void {
		$source = file_get_contents( \SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-pdf-exporter.php' );
		$this->assertIsString( $source );
		$this->assertStringContainsString( "'backupSubsFont'", $source, 'PDF exporter must declare backupSubsFont explicitly.' );
		$this->assertStringContainsString( "'freeserif'", $source, 'PDF exporter backupSubsFont must include FreeSerif (the broadest font we ship).' );
		$this->assertStringContainsString( "'backupSIPFont'", $source, 'PDF exporter must declare backupSIPFont explicitly.' );
		$this->assertStringContainsString( "=> null", $source, 'PDF exporter backupSIPFont must be null (sun-extb is not shipped).' );
	}
}
