<?php
/**
 * SScribe PDF exporter runtime-font-discovery removal regression tests.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_PDF_Exporter_Find_Font_File_Test extends TestCase {

	public function test_runtime_font_discovery_helper_is_removed(): void {
		$this::assertFalse(
			method_exists( \SScribe_PDF_Exporter::class, 'find_font_file' ),
			'PDF rendering must not discover arbitrary host/plugin fonts at runtime.'
		);
	}

	public function test_pdf_exporter_does_not_reference_or_ship_legacy_amiri_files(): void {
		$path = \SSCRIBE_PLUGIN_DIR . 'includes/exporters/class-sscribe-pdf-exporter.php';
		$source = file_get_contents( $path );

		$this::assertIsString( $source );
		$this::assertStringNotContainsString( 'Amiri-Regular.ttf', $source );
		$this::assertStringNotContainsString( 'Amiri-Bold.ttf', $source );
		$this::assertStringNotContainsString( 'find_font_file', $source );
		$this::assertStringContainsString( "'dejavusans'", $source );
		$this::assertFileDoesNotExist( \SSCRIBE_PLUGIN_DIR . 'assets/fonts/amiri/Amiri-Regular.ttf' );
		$this::assertFileDoesNotExist( \SSCRIBE_PLUGIN_DIR . 'assets/fonts/amiri/Amiri-Bold.ttf' );
		$this::assertFileDoesNotExist( \SSCRIBE_PLUGIN_DIR . 'assets/fonts/amiri/OFL.txt' );
	}

	public function test_tcpdf_prune_policy_is_closed_to_declared_files(): void {
		$path = \SSCRIBE_PLUGIN_DIR . 'scripts/prune-tcpdf-for-strauss.php';
		$source = file_get_contents( $path );

		$this::assertIsString( $source );
		$this::assertStringContainsString( '$allowed_top_level = array(', $source );
		$this::assertStringContainsString( '$allowed_nested = array(', $source );
		$this::assertStringContainsString( 'if ( ! in_array( $font_relative, $tcpdf_font_allow, true ) )', file_get_contents( \SSCRIBE_PLUGIN_DIR . 'scripts/build-release.php' ) ?: '' );
	}

	public function test_tcpdf_pruner_fails_if_required_font_asset_disappears(): void {
		$path = \SSCRIBE_PLUGIN_DIR . 'scripts/prune-tcpdf-for-strauss.php';
		$source = file_get_contents( $path );

		$this::assertIsString( $source );
		$this::assertStringContainsString( 'Required TCPDF font asset is missing after pruning', $source );
		$this::assertStringContainsString( 'exit( 1 );', $source );
	}
}
