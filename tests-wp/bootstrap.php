<?php
/**
 * PHPUnit bootstrap for real WordPress integration tests.
 *
 * Loads the WordPress test suite (which spins up a real wpdb connection
 * via the SQLite Database Integration drop-in) and then loads the SScribe
 * plugin so its activation hooks, AJAX handlers, and cron schedules run
 * against genuine WordPress APIs.
 *
 * Run with:
 *   php -d extension=sqlite3 -d extension=pdo_sqlite vendor/bin/phpunit --testsuite=WordPress
 *
 * The -d flags are required on environments where the SQLite extensions
 * are present on disk but not enabled in php.ini (the SQLite Database
 * Integration drop-in requires both the sqlite3 class and pdo_sqlite).
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( ! extension_loaded( 'sqlite3' ) ) {
	fwrite(
		STDERR,
		"[tests-wp/bootstrap] The SQLite3 PHP extension is not loaded. "
		. "Run PHPUnit with -d extension=sqlite3, or enable it in php.ini.\n"
	);
	exit( 1 );
}

/**
 * PHPUnit 11 ships `PHPUnit\Util\Test` with only `currentTestCase()` and
 * `isTestMethod()`. The upstream wp-phpunit suite still expects the long-
 * gone `parseTestMethodAnnotations(string $class, string $method)` which
 * existed through PHPUnit 9.6. PHP can't extend PHPUnit's `final` class
 * and can't add methods to it after the fact.
 *
 * Workaround: declare our own `PHPUnit\Util\Test` BEFORE PHPUnit's autoloader
 * gets a chance. Because PHP only invokes the autoloader when the class isn't
 * already declared, declaring it first short-circuits the autoloader and the
 * upstream code happily calls parseTestMethodAnnotations() on our version
 * without ever touching PHPUnit's stricter `final readonly` original.
 *
 * Our version keeps `currentTestCase()` and `isTestMethod()` (so any other
 * PHPUnit-internal callers don't blow up) and provides a real
 * `parseTestMethodAnnotations()` that returns the legacy shape WP iterates
 * over, populated from the class + method docblocks.
 */
if ( ! class_exists( 'PHPUnit\\Util\\Test', false ) ) {
	// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
	if ( ! class_exists( 'Yoast\\PHPUnitPolyfills\\TestCases\\TestCase', false ) ) {
		// We may be loaded before the polyfills; nothing to do — the polyfills
		// autoloader handles the rest as long as we don't trigger it first.
	}

	// PHPUnit's class is `final readonly` so we can't extend it. The only
	// safe operation is to define our own copy under the same name BEFORE
	// PHPUnit's autoloader runs. Class names with namespaces work.
	class PHPUnit_Util_Test_WpCompat {
		/**
		 * Replacement for PHPUnit's static `currentTestCase()`. Mirrors the
		 * behavior of PHPUnit 11's PHPUnit\Util\Test::currentTestCase() —
		 * walks the call stack and returns the first TestCase instance, or
		 * throws `NoTestCaseObjectOnCallStackException` if there isn't one.
		 *
		 * Callers like DispatchingEmitter::testRunnerTriggeredPhpunitDeprecation()
		 * already try/catch the exception; returning null here would crash
		 * them with "valueObjectForEvents() on null" because they assume
		 * a real TestCase or a thrown exception.
		 */
		public static function currentTestCase(): \PHPUnit\Framework\TestCase {
			foreach ( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
				if ( isset( $frame['object'] ) && $frame['object'] instanceof \PHPUnit\Framework\TestCase ) {
					return $frame['object'];
				}
			}
			throw new \PHPUnit\Event\Code\NoTestCaseObjectOnCallStackException();
		}

		/**
		 * Replacement for PHPUnit's `isTestMethod()`. Mirrors the heuristic
		 * the original used (public method starting with "test", or carrying
		 * a @test attribute/metadata).
		 */
		public static function isTestMethod( \ReflectionMethod $method ): bool {
			if ( ! $method->isPublic() ) {
				return false;
			}
			if ( str_starts_with( $method->getName(), 'test' ) ) {
				return true;
			}
			if ( class_exists( '\\PHPUnit\\Metadata\\Parser\\Registry', false ) ) {
				$parser = \PHPUnit\Metadata\Parser\Registry::parser();
				$metadata = $parser->forMethod(
					$method->getDeclaringClass()->getName(),
					$method->getName()
				);
				return $metadata->isTest()->isNotEmpty();
			}
			return false;
		}

		/**
		 * Replacement for PHPUnit <= 9.6 `parseTestMethodAnnotations`.
		 * Returns the legacy shape WP iterates over, populated only with
		 * `@expectedDeprecated` / `@expectedDeprecation` annotations from
		 * both the class and method docblocks.
		 *
		 * @param string $class  Fully-qualified class name.
		 * @param string $method Method name.
		 * @return array{class: array<string, array<int, string>>, method: array<string, array<int, string>>}
		 */
		public static function parseTestMethodAnnotations( string $class, string $method ): array {
			$shape = array(
				'class'  => array(),
				'method' => array(),
			);

			if ( '' === $class || ! class_exists( $class ) ) {
				return $shape;
			}

			try {
				$ref_method = new \ReflectionMethod( $class, $method );
			} catch ( \ReflectionException $e ) {
				return $shape;
			}

			$collect = static function ( string $doc, array &$shape, string $depth ): void {
				$hits = array();
				if ( preg_match_all( '/@expectedDeprecated\b[^\S\n]*([^\n]*)/', $doc, $m1 ) ) {
					$hits = array_merge( $hits, $m1[1] );
				}
				if ( preg_match_all( '/@expectedDeprecation\b[^\S\n]*([^\n]*)/', $doc, $m2 ) ) {
					$hits = array_merge( $hits, $m2[1] );
				}
				$hits = array_values(
					array_filter(
						array_map( 'trim', $hits ),
						static function ( string $arg ): bool {
							return '' !== $arg;
						}
					)
				);
				if ( ! empty( $hits ) ) {
					$shape[ $depth ]['expectedDeprecated'] = $hits;
				}
			};

			$ref_class = new \ReflectionClass( $class );
			$collect( (string) $ref_class->getDocComment(), $shape, 'class' );
			$collect( (string) $ref_method->getDocComment(), $shape, 'method' );

			return $shape;
		}
	}

	class_alias( 'PHPUnit_Util_Test_WpCompat', 'PHPUnit\\Util\\Test' );
	// phpcs:enable
}

$plugin_dir     = dirname( __DIR__ );
$wp_tests_dir   = getenv( 'WP_TESTS_DIR' ) ?: $plugin_dir . '/tests-wp/_wordpress-tests-lib';
$wp_core_dir    = getenv( 'WP_CORE_DIR' )  ?: $plugin_dir . '/tests-wp/_wordpress';

if ( ! is_dir( $wp_tests_dir ) || ! is_dir( $wp_core_dir ) ) {
	fwrite(
		STDERR,
		"[tests-wp/bootstrap] WordPress testbench not installed. Run: bin/install-wp-tests.sh --sqlite\n"
	);
	exit( 1 );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $wp_core_dir . '/' );
}

if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', $wp_tests_dir . '/wp-tests-config.php' );
}

if ( ! defined( 'WP_TESTS_ABSPATH' ) ) {
	define( 'WP_TESTS_ABSPATH', $wp_core_dir . '/' );
}

// The wp-phpunit suite ships its own bootstrap.php. It calls install.php via
// WP_PHP_BINARY + argv (correct CLI args) when WP_TESTS_SKIP_INSTALL is unset,
// then loads wp-settings.php and every test base class we need.
//
// We only need to:
//   1. ensure WP_PHP_BINARY points at the running PHP binary,
//   2. expose WP_TESTS_PHPUNIT_POLYFILLS_PATH so its Yoast polyfill loader finds
//      our copy,
//   3. load PHPUnit classes first (so tests_get_phpunit_version() resolves),
//   4. ensure the constants wp-phpunit bootstrap expects (WP_TESTS_DOMAIN, etc.)
//      are already defined by wp-tests-config.php before this is required.

// Load PHPUnit + polyfills + Composer autoload so PHPUnit\Runner\Version
// resolves BEFORE wp-phpunit/bootstrap.php calls tests_get_phpunit_version().
$autoload = $plugin_dir . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

$legacy_phpunit_compat = $plugin_dir . '/tests-wp/phpunit-legacy-compat.php';
if ( file_exists( $legacy_phpunit_compat ) ) {
	require_once $legacy_phpunit_compat;
}

$wp_php_binary_env = getenv( 'WP_PHP_BINARY' );
if ( false !== $wp_php_binary_env && '' !== $wp_php_binary_env ) {
	// WP_PHP_BINARY is supplied as an environment variable from
	// phpunit-wp.xml (<env name="WP_PHP_BINARY" value="…"/>). On Windows
	// / XAMPP / shared-host dev boxes where sqlite3 + pdo_sqlite are
	// already loaded by php.ini, the wrapper pointed at by the env
	// value skips the redundant `-d extension=…` flags; on a clean
	// system it appends them. See tests-wp/wp-php-wrapper.php.
	define( 'WP_PHP_BINARY', $wp_php_binary_env );
} elseif ( ! defined( 'WP_PHP_BINARY' ) ) {
	// Fallback for ad-hoc `php install.php` invocations outside
	// phpunit-wp.xml (e.g. running install.php directly to re-seed
	// the WP test database). Forces both extensions so the SQLite
	// drop-in works; on systems where they're already loaded the
	// subprocess will warn "Module already loaded" and continue.
	define( 'WP_PHP_BINARY', PHP_BINARY . ' -d extension=sqlite3 -d extension=pdo_sqlite' );
}

$polyfills_candidates = array(
	$plugin_dir . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php',
	$plugin_dir . '/vendor-prefixed/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php',
);
foreach ( $polyfills_candidates as $candidate ) {
	if ( file_exists( $candidate ) ) {
		if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
			define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $candidate );
		}
		break;
	}
}

// Now hand control to wp-phpunit's own bootstrap. We wrap it in an
// output buffer so the "Running as single site..." / "Installing..."
// smoke messages the library emits don't bleed into STDOUT — that
// would corrupt every `ob_start()` we do later in the AJAX tests
// (PHPUnit's `_handleAjax()` assumes a clean stdout so its dieHandler
// can capture the JSON body in `_last_response`).
ob_start();
require_once $wp_tests_dir . '/includes/bootstrap.php';
ob_end_clean();

// Tell SScribe it is running inside a test harness. The main plugin file
// defines SSCRIBE_TESTING / SSCRIBE_DEBUG / SSCRIBE_VERSION / SSCRIBE_PLUGIN_*
// itself; we don't redefine them here.

// Alias prefixed vendor namespaces (mPDF, PhpWord, PSR-Container) so the
// plugin's shipped classes resolve the same way they do in production.
require_once $plugin_dir . '/includes/class-sscribe-vendor-bootstrap.php';
SScribe_Vendor_Bootstrap::require();

// Now load the plugin itself, which registers every WP hook, AJAX endpoint,
// cron schedule, and capability used by the suite. The main file (via its
// own constants block) defines SSCRIBE_VERSION / SSCRIBE_PLUGIN_DIR /
// SSCRIBE_PLUGIN_URL / SSCRIBE_PLUGIN_BASENAME — we don't redefine them.
require_once $plugin_dir . '/sscribe-export-site-pages.php';

// The wp-phpunit bootstrap loads plugin source directly; it does not execute
// register_activation_hook(). Model the production lifecycle explicitly so
// every integration test starts from an installed+activated SScribe instance.
// This became mandatory once the production upgrader correctly stopped doing
// schema/filesystem mutation during anonymous/front-end bootstrap.
SScribe_Activator::activate( false );

// The main plugin file attaches its bootstrap to `plugins_loaded`, but
// WP has already fired that action by the time we get here (during
// wp-settings.php inside the bootstrap we required above). Re-fire it
// so SScribe's `run()` registers the AJAX, cron, and admin hooks our
// tests depend on. The `plugins_loaded` callback only does idempotent
// registration; running it again is safe.
if ( did_action( 'plugins_loaded' ) ) {
	do_action( 'plugins_loaded' );
}

// ----------------------------------------------------------------------------
// Headers-already-sent warning guard.
//
// PHPUnit's CLI progress printer writes dots / W / S markers to STDOUT while
// the suite runs. Once STDOUT has data, PHP refuses any subsequent `header()`
// call with `Cannot modify header information - headers already sent by …`.
// SScribe's `SScribe_Admin_Debug::download_json()` and any other production
// code that calls `header()` (for `Content-Disposition`, `X-Content-Type-
// Options`, etc.) will hit this during AJAX-dispatch tests that exercise
// download-style endpoints.
//
// The warning is a benign CLI testbench artifact: in production HTTP
// requests PHP never reaches `header()` after output begins. We install a
// scoped error handler that swallows ONLY this specific warning text and
// delegates every other error to PHPUnit's existing handler (so genuine
// production issues still surface). The handler is removed automatically
// when PHPUnit tears the bootstrap down via `restore_error_handler()`-style
// cleanup, but in practice the bootstrap runs once per `phpunit` invocation
// so leaving the handler installed for the rest of the run is correct.
//
// We deliberately scope to the exact warning text rather than blanket-
// silencing E_WARNING — the goal is to keep the test surface for genuine
// warnings intact while removing the CLI-only header race.
//
// PHPUnit 11 routes PHP runtime warnings through its `phpWarnings` list and
// prints them via `ResultPrinter::printIssueList('PHP warning', …)`. With
// this guard installed, `numberOfWarnings()` stays at zero and
// `failOnWarning="true"` no longer escalates the run.
// ----------------------------------------------------------------------------
$_ss_prev_err_handler = null;
$_ss_prev_err_handler = set_error_handler(
	static function ( int $errno, string $errstr, string $errfile = '', int $errline = 0 ) use ( &$_ss_prev_err_handler ): bool {
		if ( E_WARNING === $errno
			&& ( false !== strpos( $errstr, 'Cannot modify header information' )
				|| false !== strpos( $errstr, 'headers already sent' ) )
		) {
			return true;
		}

		if ( is_callable( $_ss_prev_err_handler ) ) {
			return (bool) $_ss_prev_err_handler( $errno, $errstr, $errfile, $errline );
		}

		return false;
	}
);
