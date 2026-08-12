<?php
/**
 * Regression: lock-state and rate-limit plumbing must never feed a NULL
 * into a strict `bool` parameter.
 *
 * Root cause that drove this test:
 *
 *   `wp_using_ext_object_cache()` reads the global `$_wp_using_ext_object_cache`
 *   and returns it directly. WordPress normally initializes that global in
 *   `wp-settings.php`, but some embedded runtimes (notably WP-Playground's
 *   SQLite bundle, which does NOT ship `wp-settings.php`) never declare it.
 *   In that environment, accessing the undeclared global yields NULL,
 *   so the function returns NULL even though its docblock says `bool`.
 *
 *   Several code paths in this plugin then pass that value to typed methods
 *   like `SScribe_Export_Rate_Limiter::release_lock( bool $using_cache, ... )`,
 *   which fails with:
 *
 *     TypeError: Argument #1 ($using_cache) must be of type bool, null given
 *
 *   Locking in: every call site that hands the result of
 *   `wp_using_ext_object_cache()` to a strict-typed bool parameter must
 *   coerce with `(bool)` first. This test enforces that contract by reading
 *   the source files and asserting each call site has the coercion.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Using_Cache_Bool_Test extends TestCase {

	/**
	 * Files in the plugin tree that read `wp_using_ext_object_cache()`.
	 *
	 * If a new file starts using this helper, add it here so the cast is
	 * still required on every assignment.
	 *
	 * @return array<string, string>
	 */
	private static function source_files_using_helper(): array {
		return array(
			'rate-limiter' => __DIR__ . '/../../includes/class-sscribe-export-rate-limiter.php',
			'lock-manager' => __DIR__ . '/../../includes/class-sscribe-export-lock-manager.php',
		);
	}

	/**
	 * For every file that uses `wp_using_ext_object_cache()`, EVERY assignment
	 * of its return value to a variable must be coerced to bool. Otherwise
	 * the strict-typed `bool` parameter in the lock manager / rate limiter
	 * crashes when WP-Playground (or any other runtime that skips
	 * `wp-settings.php`) hands back NULL.
	 *
	 * @dataProvider provideSourceFile
	 */
	public function test_every_assignment_coerces_to_bool( string $label, string $path ): void {
		$source = file_get_contents( $path );
		$this->assertNotFalse( $source, "Could not read $path" );

		$lines      = preg_split( '/\r?\n/', $source );
		$bad        = array();
		$assignment = '/\$(\w+)\s*=[^=;]*?wp_using_ext_object_cache\s*\(/';

		foreach ( $lines as $i => $line ) {
			if ( preg_match( $assignment, $line ) && false === strpos( $line, '(bool)' ) ) {
				$bad[] = trim( $line );
			}
		}

		$this->assertSame(
			array(),
			$bad,
			"File {$label} ({$path}) assigns wp_using_ext_object_cache() without a (bool) cast. " .
			"Coerce the return value with `(bool)` because some embedded runtimes (WP-Playground) return NULL."
		);
	}

	public static function provideSourceFile(): array {
		$out = array();
		foreach ( self::source_files_using_helper() as $label => $path ) {
			$out[] = array( $label, $path );
		}
		return $out;
	}

	/**
	 * The plugin's test bootstrap must continue to declare
	 * `wp_using_ext_object_cache(): bool` returning false. Otherwise
	 * downstream rate-limit / lock tests run against a function with the
	 * wrong signature, which silently hides the NULL regression we are
	 * guarding against.
	 */
	public function test_bootstrap_declares_bool_returning_stub(): void {
		$bootstrap = file_get_contents( __DIR__ . '/../bootstrap.php' );
		$this->assertNotFalse( $bootstrap );

		$this->assertMatchesRegularExpression(
			'/function\s+wp_using_ext_object_cache\s*\(\s*\)\s*:\s*bool\b/',
			$bootstrap,
			'tests/bootstrap.php must declare wp_using_ext_object_cache(): bool.'
		);
	}
}
