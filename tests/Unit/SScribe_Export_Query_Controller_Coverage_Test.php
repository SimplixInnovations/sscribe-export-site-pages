<?php
/**
 * SScribe Export Query Controller coverage test
 *
 * Targets the constructor and DI wiring paths of the controller without
 * exercising the AJAX entry points (those are tested through the
 * integration suite). Each constructor variant covers the lazy-default
 * branch of one collaborator.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

if ( ! class_exists( '\\SScribe_Export_Query_Controller', false ) ) {
	require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-export-query-controller.php';
}

final class SScribe_Export_Query_Controller_Coverage_Test extends TestCase {

	public function test_construct_with_defaults(): void {
		$c = new \SScribe_Export_Query_Controller();
		$this::assertInstanceOf( \SScribe_Export_Query_Controller::class, $c );
	}

	public function test_construct_with_explicit_collaborators(): void {
		$rl   = new \SScribe_Export_Rate_Limiter();
		$diag = new \SScribe_Diagnostics();
		$coll = new \SScribe_Page_Collector();
		$log  = \SScribe_Logger::instance( false );
		$zip  = new \SScribe_Zip_Handler();
		$am   = new \SScribe_Adaptive_Metrics();
		$eh   = new \SScribe_Export_Error_Handler();

		$c = new \SScribe_Export_Query_Controller( $rl, $diag, $coll, $log, $zip, $am, $eh );
		$this::assertInstanceOf( \SScribe_Export_Query_Controller::class, $c );
	}

	public function test_construct_with_partial_collaborators(): void {
		$rl = new \SScribe_Export_Rate_Limiter();
		$c  = new \SScribe_Export_Query_Controller( $rl );
		$this::assertInstanceOf( \SScribe_Export_Query_Controller::class, $c );
	}

	public function test_construct_with_null_rate_limiter(): void {
		$c = new \SScribe_Export_Query_Controller( null );
		$this::assertInstanceOf( \SScribe_Export_Query_Controller::class, $c );
	}
}
