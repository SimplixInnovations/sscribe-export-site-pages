<?php
/**
 * Regression coverage for deterministic Strauss autoloader normalization.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( ! defined( 'SSCRIBE_TESTS_DIR' ) ) {
	define( 'SSCRIBE_TESTS_DIR', __DIR__ . '/..' );
}

require_once SSCRIBE_TESTS_DIR . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

final class SScribe_Strauss_Autoloader_Determinism_Test extends TestCase {

	private string $temp_dir;

	protected function setUp(): void {
		$this->temp_dir = sys_get_temp_dir() . '/sscribe-strauss-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->temp_dir . '/composer', 0777, true );

		$random_suffix = '0123456789abcdef0123456789abcdef';

		file_put_contents(
			$this->temp_dir . '/autoload.php',
			"<?php\nreturn SScribeVendor\\Composer\\Autoload\\ComposerAutoloaderInit{$random_suffix}::getLoader();\n"
		);

		file_put_contents(
			$this->temp_dir . '/composer/autoload_real.php',
			"<?php\nnamespace SScribeVendor\\Composer\\Autoload;\n"
			. "class ComposerAutoloaderInit{$random_suffix}\n{\n"
			. "\tpublic static function getLoader()\n\t{\n"
			. "\t\tComposerStaticInit{$random_suffix}::getInitializer( new \\Composer\\Autoload\\ClassLoader() );\n"
			. "\t}\n}\n"
		);

		file_put_contents(
			$this->temp_dir . '/composer/autoload_static.php',
			"<?php\nnamespace SScribeVendor\\Composer\\Autoload;\n"
			. "class ComposerStaticInit{$random_suffix}\n{\n"
			. "\tpublic static function getInitializer() {}\n"
			. "}\n"
		);
	}

	protected function tearDown(): void {
		$files = array(
			$this->temp_dir . '/composer/autoload_static.php',
			$this->temp_dir . '/composer/autoload_real.php',
			$this->temp_dir . '/autoload.php',
		);
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		if ( is_dir( $this->temp_dir . '/composer' ) ) {
			rmdir( $this->temp_dir . '/composer' );
		}
		if ( is_dir( $this->temp_dir ) ) {
			rmdir( $this->temp_dir );
		}
	}

	public function test_normalizer_rewrites_random_strauss_suffix_consistently(): void {
		$script = dirname( __DIR__, 2 ) . '/scripts/normalize-strauss-autoloader.php';
		$this->assertFileExists( $script, 'Deterministic Strauss autoloader normalizer must exist.' );

		$command = escapeshellarg( PHP_BINARY )
			. ' ' . escapeshellarg( $script )
			. ' --target-dir=' . escapeshellarg( $this->temp_dir );

		exec( $command . ' 2>&1', $output, $exit_code );
		$this->assertSame( 0, $exit_code, implode( "\n", $output ) );

		$expected_suffix = 'SScribeExportSitePages';
		foreach (
			array(
				$this->temp_dir . '/autoload.php',
				$this->temp_dir . '/composer/autoload_real.php',
				$this->temp_dir . '/composer/autoload_static.php',
			) as $file
		) {
			$contents = (string) file_get_contents( $file );
			$this->assertStringContainsString( $expected_suffix, $contents, "{$file} must use the stable suffix." );
			$this->assertDoesNotMatchRegularExpression(
				'/Composer(?:AutoloaderInit|StaticInit)[0-9a-f]{32}/i',
				$contents,
				"{$file} must not retain Strauss random suffixes."
			);
		}

		$real = (string) file_get_contents( $this->temp_dir . '/composer/autoload_real.php' );
		$this->assertStringContainsString( 'ComposerAutoloaderInitSScribeExportSitePages', $real );
		$this->assertStringContainsString( 'ComposerStaticInitSScribeExportSitePages', $real );
	}

	public function test_normalizer_is_idempotent(): void {
		$script = dirname( __DIR__, 2 ) . '/scripts/normalize-strauss-autoloader.php';
		$this->assertFileExists( $script );

		$command = escapeshellarg( PHP_BINARY )
			. ' ' . escapeshellarg( $script )
			. ' --target-dir=' . escapeshellarg( $this->temp_dir );

		exec( $command . ' 2>&1', $first_output, $first_exit );
		$this->assertSame( 0, $first_exit, implode( "\n", $first_output ) );

		$before = array_map(
			static fn( string $file ): string => hash_file( 'sha256', $file ),
			array(
				$this->temp_dir . '/autoload.php',
				$this->temp_dir . '/composer/autoload_real.php',
				$this->temp_dir . '/composer/autoload_static.php',
			)
		);

		exec( $command . ' 2>&1', $second_output, $second_exit );
		$this->assertSame( 0, $second_exit, implode( "\n", $second_output ) );

		$after = array_map(
			static fn( string $file ): string => hash_file( 'sha256', $file ),
			array(
				$this->temp_dir . '/autoload.php',
				$this->temp_dir . '/composer/autoload_real.php',
				$this->temp_dir . '/composer/autoload_static.php',
			)
		);

		$this->assertSame( $before, $after, 'Second normalization pass must be byte-idempotent.' );
	}
}
