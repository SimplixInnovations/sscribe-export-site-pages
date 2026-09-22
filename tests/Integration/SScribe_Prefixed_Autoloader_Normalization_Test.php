<?php
/**
 * Regression coverage for deterministic Strauss/Composer autoloader output.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( ! defined( 'SSCRIBE_TESTS_DIR' ) ) {
	define( 'SSCRIBE_TESTS_DIR', __DIR__ . '/..' );
}

require_once SSCRIBE_TESTS_DIR . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

final class SScribe_Prefixed_Autoloader_Normalization_Test extends TestCase {

	private string $tmp_dir;

	protected function setUp(): void {
		$this->tmp_dir = sys_get_temp_dir() . '/sscribe-autoload-normalize-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->tmp_dir . '/composer', 0777, true );
	}

	protected function tearDown(): void {
		if ( ! is_dir( $this->tmp_dir ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->tmp_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
			}
		}
		@rmdir( $this->tmp_dir );
	}

	public function test_normalizer_replaces_one_random_suffix_consistently_across_generated_autoloader_files(): void {
		$root       = dirname( __DIR__, 2 );
		$normalizer = $root . '/scripts/normalize-prefixed-autoloader.php';

		$this->assertFileExists(
			$normalizer,
			'The Strauss autoloader normalizer must exist so clean release builds are reproducible.'
		);

		$random_suffix = '0123456789abcdef0123456789abcdef';
		$stable_suffix = 'SScribeExportSitePages';

		file_put_contents(
			$this->tmp_dir . '/autoload.php',
			"<?php\nreturn SScribeVendor\\Composer\\Autoload\\ComposerAutoloaderInit{$random_suffix}::getLoader();\n"
		);
		file_put_contents(
			$this->tmp_dir . '/composer/autoload_real.php',
			"<?php\nclass ComposerAutoloaderInit{$random_suffix} { public static function getLoader() {} }\n"
		);
		file_put_contents(
			$this->tmp_dir . '/composer/autoload_static.php',
			"<?php\nclass ComposerStaticInit{$random_suffix} {}\n"
		);

		$command = escapeshellarg( PHP_BINARY )
			. ' '
			. escapeshellarg( $normalizer )
			. ' '
			. escapeshellarg( $this->tmp_dir );

		exec( $command . ' 2>&1', $output, $exit_code );

		$this->assertSame(
			0,
			$exit_code,
			"Normalizer failed:\n" . implode( "\n", $output )
		);

		$files = array(
			$this->tmp_dir . '/autoload.php',
			$this->tmp_dir . '/composer/autoload_real.php',
			$this->tmp_dir . '/composer/autoload_static.php',
		);

		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $file );
			$this->assertStringNotContainsString( $random_suffix, $source, "{$file} retained random Strauss suffix." );
			$this->assertStringContainsString( $stable_suffix, $source, "{$file} did not receive stable SScribe suffix." );
		}
	}

	public function test_normalizer_fails_closed_when_generated_files_do_not_share_exactly_one_suffix(): void {
		$root       = dirname( __DIR__, 2 );
		$normalizer = $root . '/scripts/normalize-prefixed-autoloader.php';

		$this->assertFileExists( $normalizer );

		file_put_contents(
			$this->tmp_dir . '/autoload.php',
			"<?php\nreturn ComposerAutoloaderInitaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa::getLoader();\n"
		);
		file_put_contents(
			$this->tmp_dir . '/composer/autoload_real.php',
			"<?php\nclass ComposerAutoloaderInitbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb {}\n"
		);
		file_put_contents(
			$this->tmp_dir . '/composer/autoload_static.php',
			"<?php\nclass ComposerStaticInitcccccccccccccccccccccccccccccccc {}\n"
		);

		$command = escapeshellarg( PHP_BINARY )
			. ' '
			. escapeshellarg( $normalizer )
			. ' '
			. escapeshellarg( $this->tmp_dir );

		exec( $command . ' 2>&1', $output, $exit_code );

		$this->assertNotSame( 0, $exit_code, 'Normalizer must fail closed on inconsistent generated suffixes.' );
	}
}
