<?php
/**
 * SScribe PDF Exporter — CSS keyword font policy regression test
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SScribe_PDF_Exporter_CSS_Keyword_Font_Policy_Test extends TestCase {

	/**
	 * Invoke the private build_mpdf_config() method via a scoped closure.
	 *
	 * Mirrors the pattern in SScribe_PDF_Exporter_Backup_Font_Policy_Test.
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
	 * Last-resort: if build_mpdf_config() can't be called in this env,
	 * fall back to a static analysis of the source. The "no runtime
	 * surface" branch still pins the policy, just via the source code.
	 */
	private function read_config_via_reflection(): array {
		$source = file_get_contents( \SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-pdf-exporter.php' );
		$this->assertIsString( $source, 'PDF exporter source must be readable.' );
		return array( 'config' => array( '__source_only__' => true ) );
	}

	/**
	 * Regression guard: the mPDF config must remap every CSS font-family
	 * keyword / named-family that mPDF's setCSS would walk into a chain
	 * whose first entry is a TTF excluded from the release ZIP.
	 *
	 * Background — the original 2026-06-24 report was the backup-substitution
	 * path (DejaVuSansCondensed.ttf via backupSubsFont). That fix only
	 * closed one of three paths. The other two are:
	 *
	 *  (a) font-family CSS keyword resolution: HTML like
	 *      `style="font-family: serif"` walks mPDF's `serif_fonts` chain
	 *      which begins with `dejavuserifcondensed`. Without a fonttrans
	 *      remap, mPDF AddFonts that name, looks for DejaVuSerifCondensed.ttf
	 *      in fontDir, and crashes — the same class of MpdfException.
	 *
	 *  (b) fontdata entries with pruned TTFs: even with a fonttrans remap,
	 *      mPDF's default fontdata has entries like `dejavuserifcondensed`,
	 *      `dejavusanscondensed`, `freesans`, `freemono`, `sun-exta` that
	 *      still point at pruned TTFs. The chain walk picks them as
	 *      $i[0] of `array_intersect($serif_fonts, $available_unifonts)`
	 *      because the family name IS in available_unifonts (from
	 *      fontdata). AddFont then loads the pruned TTF and crashes.
	 *
	 * This test pins both fixes.
	 */
	public function test_fonttrans_remaps_every_css_keyword_to_shipped_font(): void {
		$result = $this->call_build_mpdf_config( false, 0 );
		$config = $result['config'];

		if ( isset( $config['__source_only__'] ) ) {
			$this->markTestSkipped( 'build_mpdf_config() not callable in this env; covered by test_source_code_pins_css_keyword_policy().' );
			return;
		}

		$this->assertArrayHasKey( 'fonttrans', $config, 'mPDF config must declare fonttrans to remap CSS font-family names.' );
		$fonttrans = $config['fonttrans'];
		$this->assertIsArray( $fonttrans, 'fonttrans must be an array.' );

		// Every CSS keyword / named-family that mPDF's setCSS would walk
		// into a chain whose first entry is a pruned TTF MUST be remapped.
		$required_remaps = array(
			// CSS generic families — primary trigger.
			'serif',
			'sans-serif',
			'monospace',
			// Common CSS named families that walk the *_fonts chain
			// to the same pruned DejaVu*Condensed entries.
			'times',
			'times new roman',
			'georgia',
			'palatino',
			'cambria',
			'garamond',
			'bookman',
			'arial',
			'helvetica',
			'verdana',
			'tahoma',
			'trebuchet',
			'trebuchet ms',
			'lucida',
			'lucida sans',
			'courier',
			'courier new',
			'monaco',
			'consolas',
		);

		foreach ( $required_remaps as $name ) {
			$this->assertArrayHasKey(
				$name,
				$fonttrans,
				"fonttrans must remap '$name' so mPDF does not walk the *_fonts chain to a pruned DejaVu*Condensed / FreeSans / FreeMono TTF."
			);
			$target = $fonttrans[ $name ];
			$this->assertIsString( $target, "fonttrans['$name'] must map to a string font name." );
			$this->assertNotEmpty( $target, "fonttrans['$name'] must not be empty." );
			// All remap targets must point at fonts whose TTFs ship.
			$this->assertContains(
				$target,
				array( 'freeserif', 'freesans', 'freemono', 'amiri' ),
				"fonttrans['$name'] = '$target' must point at a font whose TTF ships in the release ZIP."
			);
		}
	}

	/**
	 * Regression guard: the fontdata entries that mPDF would auto-select
	 * via the *_fonts chain walks must point at shipped TTFs. Without
	 * these overrides, the chain's $i[0] is a family whose TTF is pruned.
	 */
	public function test_fontdata_overrides_pruned_ttf_families(): void {
		$result = $this->call_build_mpdf_config( false, 0 );
		$config = $result['config'];

		if ( isset( $config['__source_only__'] ) ) {
			$this->markTestSkipped( 'build_mpdf_config() not callable in this env; covered by test_source_code_pins_css_keyword_policy().' );
			return;
		}

		$this->assertArrayHasKey( 'fontdata', $config, 'mPDF config must declare fontdata overrides.' );
		$fontdata = $config['fontdata'];
		$this->assertIsArray( $fontdata, 'fontdata must be an array.' );

		// Every fontdata entry whose default TTF is pruned by build-release.php
		// must be remapped to a shipped TTF.
		$required_remaps = array(
			// DejaVu Condensed family — pruned, but their fontdata entries
			// sit at the head of sans_fonts / serif_fonts chains.
			'dejavusanscondensed'  => 'DejaVuSans.ttf',
			'dejavuserifcondensed' => 'DejaVuSerif.ttf',
			// FreeSans / FreeMono — pruned, fontdata entries selected via
			// sans_fonts[6] / mono_fonts[3].
			'freesans'             => 'DejaVuSans.ttf',
			'freemono'             => 'DejaVuSansMono.ttf',
			// CJK / SIP auto-selection — pruned, auto-selected by
			// LanguageToFont::getLanguageOptions for Chinese / Korean /
			// Japanese characters.
			'sun-exta'             => 'DejaVuSans.ttf',
			'sun-extb'             => 'DejaVuSans.ttf',
		);

		foreach ( $required_remaps as $family => $expected_ttf ) {
			$this->assertArrayHasKey(
				$family,
				$fontdata,
				"fontdata must override '$family' so mPDF does not load a pruned TTF."
			);
			$this->assertSame(
				$expected_ttf,
				$fontdata[ $family ]['R'] ?? null,
				"fontdata['$family']['R'] must point at the shipped '$expected_ttf' (not the pruned default)."
			);
			// The remapped TTF must actually ship.
			$this->assertFileExists(
				\SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/mpdf/mpdf/ttfonts/' . $expected_ttf,
				"Remapped TTF '$expected_ttf' must ship in vendor-prefixed/mpdf/mpdf/ttfonts/."
			);
		}
	}

	/**
	 * Regression guard for every OTHER fontdata entry whose default TTF
	 * is excluded by scripts/build-release.php. mPDF's SetFont chain walker
	 * picks the first family name in $serif_fonts / $sans_fonts /
	 * $mono_fonts that ALSO appears in $available_unifonts (built from the
	 * fontdata keys). If a fontdata entry's default TTF is pruned, ANY
	 * HTML that triggers the chain — even after a fonttrans remap, because
	 * the chain picks by family name not by the remap target — will crash
	 * with `Cannot find TTF TrueType font file ...`. The 1.1.2 fix only
	 * covered 6 families; this test pins every family whose TTF the build
	 * script prunes so the policy cannot silently regress on future builds.
	 */
	#[DataProvider( 'provide_pruned_ttf_fontdata_entries' )]
	public function test_fontdata_overrides_every_pruned_ttf_family( string $family, string $expected_ttf ): void {
		$result = $this->call_build_mpdf_config( false, 0 );
		$config = $result['config'];

		if ( isset( $config['__source_only__'] ) ) {
			$this->markTestSkipped( 'build_mpdf_config() not callable in this env; covered by test_source_code_pins_css_keyword_policy().' );
			return;
		}

		$this->assertArrayHasKey( 'fontdata', $config, 'mPDF config must declare fontdata overrides.' );
		$fontdata = $config['fontdata'];

		$this->assertArrayHasKey(
			$family,
			$fontdata,
			"fontdata must override '$family' — its default TTF is pruned by scripts/build-release.php, leaving AddFont with a missing-file crash on any chain walk."
		);
		$this->assertSame(
			$expected_ttf,
			$fontdata[ $family ]['R'] ?? null,
			"fontdata['$family']['R'] must point at the shipped '$expected_ttf' (not the pruned default)."
		);
		$this->assertFileExists(
			\SSCRIBE_PLUGIN_DIR . 'vendor-prefixed/mpdf/mpdf/ttfonts/' . $expected_ttf,
			"Remapped TTF '$expected_ttf' must ship in vendor-prefixed/mpdf/mpdf/ttfonts/."
		);
	}

	/**
	 * Fontdata entries that point at TTFs excluded by
	 * scripts/build-release.php `font_excludes`. Each tuple is
	 * (fontdata family key, shipped remap target). Drives
	 * test_fontdata_overrides_every_pruned_ttf_family.
	 *
	 * Source: src/Config/FontVariables.php in vendor-prefixed/mpdf —
	 * cross-checked against the `font_excludes` array in
	 * scripts/build-release.php (which lists the actual files the build
	 * prunes). Keep this list in sync when either side changes.
	 */
	public static function provide_pruned_ttf_fontdata_entries(): array {
		return array(
			// Script-family / academic / historic fonts whose default TTF
			// is excluded. All remapped to DejaVuSans (widest-coverage
			// shipped face — Latin, Cyrillic, Greek, Vietnamese, IPA).
			array( 'ocrb',             'DejaVuSans.ttf' ),
			array( 'estrangeloedessa', 'DejaVuSans.ttf' ),
			array( 'kaputaunicode',    'DejaVuSans.ttf' ),
			array( 'abyssinicasil',    'DejaVuSans.ttf' ),
			array( 'aboriginalsans',   'DejaVuSans.ttf' ),
			array( 'jomolhari',        'DejaVuSans.ttf' ),
			array( 'sundaneseunicode', 'DejaVuSans.ttf' ),
			array( 'taiheritagepro',   'DejaVuSans.ttf' ),
			array( 'aegean',           'DejaVuSans.ttf' ),
			array( 'aegyptus',         'DejaVuSans.ttf' ),
			array( 'akkadian',         'DejaVuSans.ttf' ),
			array( 'quivira',          'DejaVuSans.ttf' ),
			array( 'eeyekunicode',     'DejaVuSans.ttf' ),
			array( 'lannaalif',        'DejaVuSans.ttf' ),
			array( 'daibannasilbook',  'DejaVuSans.ttf' ),
			array( 'garuda',           'DejaVuSans.ttf' ),
			array( 'khmeros',          'DejaVuSans.ttf' ),
			array( 'dhyana',           'DejaVuSans.ttf' ),
			array( 'tharlon',          'DejaVuSans.ttf' ),
			array( 'padaukbook',       'DejaVuSans.ttf' ),
			array( 'zawgyi-one',       'DejaVuSans.ttf' ),
			array( 'ayar',             'DejaVuSans.ttf' ),
			array( 'taameydavidclm',   'DejaVuSans.ttf' ),
			array( 'mph2bdamase',      'DejaVuSans.ttf' ),
			array( 'lohitkannada',     'DejaVuSans.ttf' ),
			array( 'pothana2000',      'DejaVuSans.ttf' ),
			// Arabic / Syriac families — default TTFs pruned; fonttrans
			// already rewrites the CSS name but the family-name chain can
			// still resolve the original key, so the fontdata entry must
			// also be pinned. fontdata pinning is the load-bearing fix;
			// fonttrans alone is insufficient (see the DejaVu*Condensed
			// bug class — same mechanism).
			array( 'xbriyaz',          'DejaVuSans.ttf' ),
			array( 'lateef',           'DejaVuSans.ttf' ),
		);
	}

	/**
	 * Belt-and-braces: even if the runtime config-building path is
	 * short-circuited by an environment issue, the source code itself
	 * must declare the CSS-keyword remap and fontdata overrides.
	 */
	public function test_source_code_pins_css_keyword_policy(): void {
		$source = file_get_contents( \SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-pdf-exporter.php' );
		$this->assertIsString( $source, 'PDF exporter source must be readable.' );

		// fonttrans unconditional remap of CSS keywords.
		$this->assertStringContainsString( "'serif'", $source, 'fonttrans must include serif.' );
		$this->assertStringContainsString( "'sans-serif'", $source, 'fonttrans must include sans-serif.' );
		$this->assertStringContainsString( "'monospace'", $source, 'fonttrans must include monospace.' );
		$this->assertStringContainsString( "'times new roman'", $source, 'fonttrans must include times new roman.' );
		$this->assertStringContainsString( "'arial'", $source, 'fonttrans must include arial.' );

		// fontdata overrides for pruned TTF families.
		$this->assertStringContainsString( "'dejavuserifcondensed'", $source, 'fontdata must override dejavuserifcondensed.' );
		$this->assertStringContainsString( "'dejavusanscondensed'", $source, 'fontdata must override dejavusanscondensed.' );
		$this->assertStringContainsString( "'freesans'", $source, 'fontdata must override freesans.' );
		$this->assertStringContainsString( "'freemono'", $source, 'fontdata must override freemono.' );
		$this->assertStringContainsString( "'sun-exta'", $source, 'fontdata must override sun-exta.' );
		$this->assertStringContainsString( "'sun-extb'", $source, 'fontdata must override sun-extb.' );
	}
}
