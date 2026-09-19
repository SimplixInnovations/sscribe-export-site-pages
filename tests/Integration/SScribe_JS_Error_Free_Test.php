<?php
/**
 * Phase 65 — JavaScript error-free integration test.
 *
 * Pins the canonical no-runtime-error contract at the PHPUnit
 * boundary so a regression that introduces console.error / eval /
 * document.write / unhandled promise / loose `var` ships a build
 * whose admin UI surfaces errors to every site owner.
 *
 * The runtime side of the contract (real browser console output)
 * lives in the Playwright e2e project (`tests-e2e/e2e/`). This
 * integration test pins the source-level contract so the regression
 * is caught even when the e2e runtime isn't available.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_JS_Error_Free_Test extends TestCase {

	private const VERIFIER_PATH = 'scripts/verify-js-error-free.php';
	private const MANIFEST_PATH = 'dist/js-error-free-manifest.json';
	private const JS_DIR        = 'admin/js';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	private function run_verifier(): array {
		$root        = self::plugin_root();
		$descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
		$process     = proc_open( array( PHP_BINARY, $root . '/' . self::VERIFIER_PATH ), $descriptors, $pipes );
		if ( ! \is_resource( $process ) ) {
			throw new \RuntimeException( 'Could not spawn verifier subprocess.' );
		}
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		return array( (int) $code, $stdout . $stderr );
	}

	public function test_live_manifest_passes_verifier(): void {
		list( $code, $output ) = $this->run_verifier();
		$this::assertSame(
			0,
			$code,
			'Phase 65 verifier must return exit 0. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'JS error-free contract valid', $output );
	}

	public function test_manifest_records_all_rules_passing(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'] );
		$this::assertGreaterThanOrEqual( 8, $payload['rule_count'] );
		$this::assertSame( $payload['rule_count'], $payload['passed_count'] );
		$this::assertSame( 0, $payload['errors_count'] );
	}

	public function test_shipped_js_has_no_console_error_or_warn(): void {
		$root = self::plugin_root();
		$files = glob( $root . '/' . self::JS_DIR . '/*.js' );
		$this::assertNotFalse( $files, 'JS directory must exist.' );
		$this::assertNotEmpty( $files, 'At least one shipped JS file must exist.' );
		$violations = array();
		foreach ( $files as $file ) {
			$src = (string) file_get_contents( $file );
			$stripped = (string) ( preg_replace( '!/\*.*?\*/!s', '', $src ) ?? $src );
			$stripped = (string) ( preg_replace( '!(^|[^:])//[^\n]*!', '$1', $stripped ) ?? $stripped );
			if ( preg_match( '/\bconsole\.error\s*\(/', $stripped ) ) {
				$violations[] = basename( $file ) . ': console.error';
			}
			if ( preg_match( '/\bconsole\.warn\s*\(/', $stripped ) ) {
				$violations[] = basename( $file ) . ': console.warn';
			}
		}
		$this::assertSame( array(), $violations, 'Shipped JS must not log console.error or console.warn: ' . implode( ', ', $violations ) );
	}

	public function test_shipped_js_has_no_dialog_calls(): void {
		$root  = self::plugin_root();
		$files = glob( $root . '/' . self::JS_DIR . '/*.js' );
		$violations = array();
		foreach ( $files as $file ) {
			$src = (string) file_get_contents( $file );
			$stripped = (string) ( preg_replace( '!/\*.*?\*/!s', '', $src ) ?? $src );
			$stripped = (string) ( preg_replace( '!(^|[^:])//[^\n]*!', '$1', $stripped ) ?? $stripped );
			foreach ( array( 'alert(', 'confirm(', 'prompt(' ) as $dialog ) {
				if ( false !== strpos( $stripped, $dialog ) ) {
					$violations[] = basename( $file ) . ': ' . trim( $dialog );
				}
			}
		}
		$this::assertSame( array(), $violations, 'Shipped JS must not call alert/confirm/prompt: ' . implode( ', ', $violations ) );
	}

	public function test_shipped_js_has_no_document_write(): void {
		$root  = self::plugin_root();
		$files = glob( $root . '/' . self::JS_DIR . '/*.js' );
		$violations = array();
		foreach ( $files as $file ) {
			$src = (string) file_get_contents( $file );
			$stripped = (string) ( preg_replace( '!/\*.*?\*/!s', '', $src ) ?? $src );
			$stripped = (string) ( preg_replace( '!(^|[^:])//[^\n]*!', '$1', $stripped ) ?? $stripped );
			foreach ( array( 'document.write(', 'document.writeln(' ) as $w ) {
				if ( false !== strpos( $stripped, $w ) ) {
					$violations[] = basename( $file ) . ': ' . trim( $w );
				}
			}
		}
		$this::assertSame( array(), $violations, 'Shipped JS must not call document.write/writeln: ' . implode( ', ', $violations ) );
	}

	public function test_shipped_js_has_no_eval_or_new_function(): void {
		$root  = self::plugin_root();
		$files = glob( $root . '/' . self::JS_DIR . '/*.js' );
		$violations = array();
		foreach ( $files as $file ) {
			$src = (string) file_get_contents( $file );
			if ( preg_match( '/\beval\s*\(/', $src ) ) {
				$violations[] = basename( $file ) . ': eval()';
			}
			if ( preg_match( '/\bnew\s+Function\s*\(/', $src ) ) {
				$violations[] = basename( $file ) . ': new Function()';
			}
		}
		$this::assertSame( array(), $violations, 'Shipped JS must not use eval() or new Function(): ' . implode( ', ', $violations ) );
	}

	public function test_shipped_js_avoids_var_declarations(): void {
		$root  = self::plugin_root();
		$files = glob( $root . '/' . self::JS_DIR . '/*.js' );
		$violations = array();
		foreach ( $files as $file ) {
			$src = (string) file_get_contents( $file );
			$stripped = (string) ( preg_replace( '!/\*.*?\*/!s', '', $src ) ?? $src );
			$stripped = (string) ( preg_replace( '!(^|[^:])//[^\n]*!', '$1', $stripped ) ?? $stripped );
			if ( preg_match( '/(?:^|[^A-Za-z0-9_$.])var\s+[A-Za-z_$]/', $stripped ) ) {
				$violations[] = basename( $file ) . ': var declaration';
			}
		}
		$this::assertSame( array(), $violations, 'Shipped JS must not declare `var ` (use const/let): ' . implode( ', ', $violations ) );
	}


	public function test_history_download_handler_never_navigates_to_unvalidated_filename_data(): void {
		$src = (string) file_get_contents( self::plugin_root() . '/admin/js/sscribe-admin.js' );

		$this::assertStringNotContainsString(
			'window.location.href = filename',
			$src,
			'History downloads must use the same-origin href already validated during rendering; a data-filename value is not a navigation URL.'
		);
	}

	public function test_every_shipped_js_file_declares_strict_mode(): void {
		$root  = self::plugin_root();
		$files = glob( $root . '/' . self::JS_DIR . '/*.js' );
		$violations = array();
		foreach ( $files as $file ) {
			$src = (string) file_get_contents( $file );
			$has_iife_strict = (bool) preg_match( "/\\(function[^{]*\\{\\s*['\"]use strict['\"]/", $src );
			$has_top_strict  = (bool) preg_match( "/['\"]use strict['\"]/", $src );
			$is_es_module    = (bool) preg_match( '/^\s*(import|export)\s/m', $src );
			if ( ! $has_iife_strict && ! $has_top_strict && ! $is_es_module ) {
				$violations[] = basename( $file );
			}
		}
		$this::assertSame( array(), $violations, 'Every shipped JS file must declare strict mode: ' . implode( ', ', $violations ) );
	}
}
