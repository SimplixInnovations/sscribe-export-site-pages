<?php
/**
 * SScribe Batch File Handler coverage test
 *
 * Targets the constructor DI-wiring branches and ensures the public class
 * surface (download / delete AJAX endpoints) is reachable from the test
 * environment.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Batch_File_Handler', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-batch-file-handler.php';
}

final class SScribe_Batch_File_Handler_Coverage_Test extends TestCase {

	public function test_construct_with_defaults(): void {
		$h = new \SScribe_Batch_File_Handler();
		$this::assertInstanceOf( \SScribe_Batch_File_Handler::class, $h );
	}

	public function test_construct_with_explicit_collaborators(): void {
		$rl  = new \SScribe_Export_Rate_Limiter();
		$zip = new \SScribe_Zip_Handler();
		$log = \SScribe_Logger::instance( false );
		$aud = new \SScribe_Export_Auditor();

		$h = new \SScribe_Batch_File_Handler( $rl, $zip, $log, $aud );
		$this::assertInstanceOf( \SScribe_Batch_File_Handler::class, $h );
	}

	public function test_construct_with_partial_collaborators(): void {
		$rl = new \SScribe_Export_Rate_Limiter();
		$h  = new \SScribe_Batch_File_Handler( $rl );
		$this::assertInstanceOf( \SScribe_Batch_File_Handler::class, $h );
	}

	public function test_class_has_ajax_download_method(): void {
		$this::assertTrue( method_exists( \SScribe_Batch_File_Handler::class, 'ajax_download' ) );
	}

	public function test_class_has_ajax_delete_export_method(): void {
		$this::assertTrue( method_exists( \SScribe_Batch_File_Handler::class, 'ajax_delete_export' ) );
	}
}
