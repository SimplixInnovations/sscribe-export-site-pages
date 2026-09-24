<?php
/**
 * SScribe PDF exporter bundled-font policy regression tests.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_PDF_Exporter_Backup_Font_Policy_Test extends TestCase {

	private function read_plugin_file( string $relative ): string {
		$path = \SSCRIBE_PLUGIN_DIR . $relative;
		$this::assertFileExists( $path );
		$contents = file_get_contents( $path );
		$this::assertIsString( $contents );
		return $contents;
	}

	public function test_pdf_exporter_pins_tcpdf_to_dejavusans(): void {
		$source = $this->read_plugin_file( 'includes/exporters/class-sscribe-pdf-exporter.php' );

		$this::assertStringContainsString(
			"SetFont( 'dejavusans', '', 10, '', true )",
			$source,
			'PDF rendering must use the bundled DejaVu Sans family rather than host fonts.'
		);
		$this::assertStringNotContainsString( 'Mpdf', $source );
		$this::assertStringNotContainsString( 'build_mpdf_config', $source );
	}

	public function test_pre_strauss_stage_keeps_every_dejavusans_face_used_by_html(): void {
		$source = $this->read_plugin_file( 'scripts/prune-tcpdf-for-strauss.php' );

		foreach (
			array(
				'dejavu/dejavusans.json',
				'dejavu/dejavusans.z',
				'dejavu/dejavusans.ctg.z',
				'dejavu/dejavusansb.json',
				'dejavu/dejavusansb.z',
				'dejavu/dejavusansb.ctg.z',
				'dejavu/dejavusansi.json',
				'dejavu/dejavusansi.z',
				'dejavu/dejavusansi.ctg.z',
				'dejavu/dejavusansbi.json',
				'dejavu/dejavusansbi.z',
				'dejavu/dejavusansbi.ctg.z',
			) as $required
		) {
			$this::assertStringContainsString( "'" . $required . "'", $source, "TCPDF 7 staging manifest must retain {$required}." );
		}
	}

	public function test_pre_strauss_stage_keeps_tcpdf_constructor_core_fonts_and_licenses(): void {
		$source = $this->read_plugin_file( 'scripts/prune-tcpdf-for-strauss.php' );

		foreach ( array( 'core/helvetica.json', 'core/courier.json', 'core/times.json', 'core/symbol.json', 'core/zapfdingbats.json' ) as $required ) {
			$this::assertStringContainsString( "'" . $required . "'", $source, "TCPDF constructor/core fallback must retain {$required}." );
		}

		$this::assertStringContainsString( "'core/LICENSE'", $source );
		$this::assertStringContainsString( "'dejavu/LICENSE'", $source );
		$this::assertNotSame( '', trim( $this->read_plugin_file( 'scripts/resources/tcpdf-fonts/core/LICENSE' ) ) );
		$this::assertNotSame( '', trim( $this->read_plugin_file( 'scripts/resources/tcpdf-fonts/dejavu/LICENSE' ) ) );
	}

	public function test_release_builder_prunes_tcpdf_extensionless_build_metadata(): void {
		$source = $this->read_plugin_file( 'scripts/build-release.php' );

		$this::assertStringContainsString(
			"'tecnickcom/tcpdf/Makefile'",
			$source,
			'TCPDF Makefile must be removed before release-content validation.'
		);
		$this::assertStringContainsString(
			"'tecnickcom/tcpdf/VERSION'",
			$source,
			'TCPDF VERSION metadata must be removed before release-content validation.'
		);
	}


	public function test_release_builder_prunes_tcpdf7_transitive_build_metadata(): void {
		$source = $this->read_plugin_file( 'scripts/build-release.php' );

		$this::assertStringContainsString( "'Makefile', 'VERSION'", $source );
		$this::assertStringContainsString( "'tecnickcom/tc-lib-pdf-font/util'", $source );
	}

	public function test_release_builder_requires_tcpdf_and_font_license_notices(): void {
		$source = $this->read_plugin_file( 'scripts/build-release.php' );

		$this::assertStringContainsString( 'vendor-prefixed/tecnickcom/tcpdf/LICENSE.TXT', $source );
		$this::assertStringContainsString( 'vendor-prefixed/tecnickcom/tc-lib-pdf-font/target/fonts/core/LICENSE', $source );
		$this::assertStringContainsString( 'vendor-prefixed/tecnickcom/tc-lib-pdf-font/target/fonts/dejavu/LICENSE', $source );
		$this::assertStringNotContainsString( 'vendor-prefixed/mpdf/', $source );
	}

	public function test_tcpdf7_pre_strauss_stage_targets_tc_lib_font_assets(): void {
		$source = $this->read_plugin_file( 'scripts/prune-tcpdf-for-strauss.php' );

		$this::assertStringContainsString( 'vendor/tecnickcom/tc-lib-pdf-font', $source );
		$this::assertStringContainsString( 'scripts/resources/tcpdf-fonts', $source );
		$this::assertStringContainsString( 'core/courier.json', $source );
		$this::assertStringContainsString( 'core/times.json', $source );
		$this::assertStringContainsString( 'core/symbol.json', $source );
		$this::assertStringContainsString( 'core/zapfdingbats.json', $source );
	}

	public function test_release_builder_uses_tcpdf7_tc_lib_font_tree_not_removed_legacy_tree(): void {
		$source = $this->read_plugin_file( 'scripts/build-release.php' );

		$this::assertStringContainsString( 'tecnickcom/tc-lib-pdf-font/target/fonts', $source );
		$this::assertStringNotContainsString( "/tecnickcom/tcpdf/fonts", $source );
	}

	public function test_readme_reports_locked_tcpdf_runtime_version(): void {
		$lock = json_decode( $this->read_plugin_file( 'composer.lock' ), true );
		$this::assertIsArray( $lock );

		$locked = '';
		foreach ( (array) ( $lock['packages'] ?? array() ) as $package ) {
			if ( is_array( $package ) && 'tecnickcom/tcpdf' === ( $package['name'] ?? '' ) ) {
				$locked = ltrim( (string) ( $package['version'] ?? '' ), 'v' );
				break;
			}
		}
		$this::assertMatchesRegularExpression( '/^\\d+\\.\\d+\\.\\d+$/', $locked );

		$readme = $this->read_plugin_file( 'readme.txt' );
		$this::assertStringContainsString( 'TCPDF ' . $locked, $readme );
	}
}
