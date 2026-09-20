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

	public function test_pre_strauss_pruner_keeps_every_dejavusans_face_used_by_html(): void {
		$source = $this->read_plugin_file( 'scripts/prune-tcpdf-for-strauss.php' );

		foreach (
			array(
				'dejavusans.php',
				'dejavusans.z',
				'dejavusans.ctg.z',
				'dejavusansb.php',
				'dejavusansb.z',
				'dejavusansb.ctg.z',
				'dejavusansi.php',
				'dejavusansi.z',
				'dejavusansi.ctg.z',
				'dejavusansbi.php',
				'dejavusansbi.z',
				'dejavusansbi.ctg.z',
			) as $required
		) {
			$this::assertStringContainsString( "'" . $required . "'", $source, "TCPDF prune allow-list must retain {$required}." );
		}
	}

	public function test_pre_strauss_pruner_keeps_tcpdf_constructor_core_fonts_and_dejavu_licenses(): void {
		$source = $this->read_plugin_file( 'scripts/prune-tcpdf-for-strauss.php' );

		foreach ( array( 'helvetica.php', 'courier.php', 'times.php', 'symbol.php', 'zapfdingbats.php' ) as $required ) {
			$this::assertStringContainsString( "'" . $required . "'", $source, "TCPDF constructor/core fallback must retain {$required}." );
		}

		$this::assertStringContainsString( 'dejavu-fonts-ttf-2.33/LICENSE', $source );
		$this::assertStringContainsString( 'dejavu-fonts-ttf-2.34/LICENSE', $source );
	}

	public function test_release_builder_requires_tcpdf_license_and_prunes_font_catalog(): void {
		$source = $this->read_plugin_file( 'scripts/build-release.php' );

		$this::assertStringContainsString( "vendor-prefixed/tecnickcom/tcpdf/LICENSE.TXT", $source );
		$this::assertStringContainsString( "vendor-prefixed/tecnickcom/tcpdf/fonts", $source );
		$this::assertStringNotContainsString( 'vendor-prefixed/mpdf/', $source );
	}
}
