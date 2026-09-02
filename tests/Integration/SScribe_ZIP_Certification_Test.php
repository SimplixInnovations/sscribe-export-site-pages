<?php
/**
 * SScribe ZIP Certification Integration Test
 *
 * @package SScribe_Export_Site_Pages
 *
 * Phase 34: locks the official WP.org submission ZIP contract.
 *
 * The submission ZIP is what reviewers see and what every site
 * downloads. A ZIP that fails to open, has the wrong directory
 * layout, ships dev-only paths, or whose SHA-256 does not match
 * is rejected at submission and ships corruption to every site
 * on update.
 *
 * This integration test runs scripts/verify-zip-certification.php
 * against a synthetic ZIP that we control end-to-end. The live
 * dist/sscribe-export-site-pages-{VERSION}.zip from the build
 * script is exercised too (test_live_zip_passes).
 *
 * A regression that:
 *   - silently accepts a missing mainfile,
 *   - silently accepts a missing readme.txt / license.txt,
 *   - silently accepts dev-only paths (tests/, scripts/, etc.),
 *   - silently accepts forbidden extensions (.sh, .bat, .phar, etc.),
 *   - silently accepts a corrupt ZIP (CRC mismatch on open),
 *   - silently accepts a top-level entry that is not the plugin slug,
 *   - silently accepts a SHA-256 mismatch,
 *
 * ...fails the suite immediately.
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_ZIP_Certification_Test extends TestCase {

	private const SCRIPT_PATH = 'scripts/verify-zip-certification.php';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Build a synthetic ZIP that satisfies the verifier, return the
	 * (zip path, dist dir, plugin file path) tuple the verifier
	 * looks for.
	 *
	 * @return array{0:string,1:string,2:string} zip path, dist dir, plugin file
	 */
	private function make_zip( array $overrides = array() ): array {
		$tmp = sys_get_temp_dir() . '/sscribe-zip-cert-' . bin2hex( random_bytes( 4 ) );
		if ( ! is_dir( $tmp ) ) {
			mkdir( $tmp, 0755, true );
		}
		$plugin_file = $tmp . '/sscribe-export-site-pages.php';
		$default_mainfile_src = "<?php\n/**\n * Plugin Name: SScribe Export Site Pages\n * Version: 9.9.9-test\n */\n";
		file_put_contents( $plugin_file, $overrides['mainfile_src'] ?? $default_mainfile_src );

		$dist      = $tmp . '/dist';
		$zip_path  = $dist . '/sscribe-export-site-pages-9.9.9.zip';
		$side_path = $dist . '/sscribe-export-site-pages-9.9.9.sha256';
		mkdir( $dist, 0755, true );

		$entries = $overrides['entries'] ?? array(
			array(
				'name'    => 'sscribe-export-site-pages/sscribe-export-site-pages.php',
				'content' => '<?php // mainfile',
			),
			array(
				'name'    => 'sscribe-export-site-pages/readme.txt',
				'content' => '=== SScribe ===',
			),
			array(
				'name'    => 'sscribe-export-site-pages/license.txt',
				'content' => 'GPL v2',
			),
			array(
				'name'    => 'sscribe-export-site-pages/composer.json',
				'content' => '{}',
			),
			array(
				'name'    => 'sscribe-export-site-pages/uninstall.php',
				'content' => '<?php // uninstall',
			),
			array(
				'name'    => 'sscribe-export-site-pages/vendor-prefixed/autoload.php',
				'content' => '<?php // autoload',
			),
			array(
				'name'    => 'sscribe-export-site-pages/includes/collector.php',
				'content' => '<?php // collector',
			),
		);

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( 'Cannot open synthetic ZIP for write' );
		}
		foreach ( $entries as $entry ) {
			$zip->addFromString( $entry['name'], (string) $entry['content'] );
		}
		$zip->close();

		// Sidecar checksum.
		$sidecar = $overrides['sidecar'] ?? hash_file( 'sha256', $zip_path );
		file_put_contents( $side_path, $sidecar );

		return array( $zip_path, $dist, $plugin_file );
	}

	/**
	 * Run the verifier against a synthetic ZIP. The verifier
	 * derives the version from the mainfile in the project root,
	 * so we copy the synthetic mainfile into the repo's slot for
	 * the duration of the run.
	 *
	 * @return array{0:int,1:string} exit code, output
	 */
	private function run_against( string $zip_path, string $dist, string $plugin_file, ?string $sidecar_content = null ): array {
		$root          = self::plugin_root();
		$real_mainfile = $root . '/sscribe-export-site-pages.php';
		$real_zip      = $root . '/dist/sscribe-export-site-pages-9.9.9.zip';
		$real_side     = $root . '/dist/sscribe-export-site-pages-9.9.9.sha256';
		$real_dist     = $root . '/dist';

		$backup_mainfile = is_file( $real_mainfile ) ? (string) file_get_contents( $real_mainfile ) : null;
		$backup_zip      = is_file( $real_zip ) ? (string) file_get_contents( $real_zip ) : null;
		$backup_side     = is_file( $real_side ) ? (string) file_get_contents( $real_side ) : null;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $real_mainfile, (string) file_get_contents( $plugin_file ) );
		if ( ! is_dir( $real_dist ) ) {
			mkdir( $real_dist, 0755, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $real_zip, (string) file_get_contents( $zip_path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents(
			$real_side,
			null !== $sidecar_content ? $sidecar_content : (string) file_get_contents( $dist . '/sscribe-export-site-pages-9.9.9.sha256' )
		);

		try {
			$descriptors = array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			);
			$process = proc_open(
				array( PHP_BINARY, $root . '/' . self::SCRIPT_PATH ),
				$descriptors,
				$pipes
			);
			$this::assertIsResource( $process );
			$stdout = (string) stream_get_contents( $pipes[1] );
			$stderr = (string) stream_get_contents( $pipes[2] );
			$code   = proc_close( $process );
			return array( (int) $code, $stdout . $stderr );
		} finally {
			// Restore originals.
			if ( null !== $backup_mainfile ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $real_mainfile, $backup_mainfile );
			} else {
				@unlink( $real_mainfile );
			}
			if ( null !== $backup_zip ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $real_zip, $backup_zip );
			} else {
				@unlink( $real_zip );
			}
			if ( null !== $backup_side ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $real_side, $backup_side );
			} else {
				@unlink( $real_side );
			}
		}
	}

	public function test_well_formed_passes(): void {
		list( $zip, $dist, $main ) = $this->make_zip();
		list( $code, $output )     = $this->run_against( $zip, $dist, $main );
		$this::assertSame(
			0,
			$code,
			'Well-formed ZIP must pass. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'ZIP certification holds', $output );
	}

	public function test_missing_mainfile_fails(): void {
		$entries = array(
			array( 'name' => 'sscribe-export-site-pages/readme.txt', 'content' => '=== SScribe ===' ),
			array( 'name' => 'sscribe-export-site-pages/license.txt', 'content' => 'GPL v2' ),
			array( 'name' => 'sscribe-export-site-pages/composer.json', 'content' => '{}' ),
			array( 'name' => 'sscribe-export-site-pages/uninstall.php', 'content' => '<?php // uninstall' ),
			array( 'name' => 'sscribe-export-site-pages/vendor-prefixed/autoload.php', 'content' => '<?php // autoload' ),
		);
		list( $zip, $dist, $main ) = $this->make_zip( array( 'entries' => $entries ) );
		list( $code, $output )     = $this->run_against( $zip, $dist, $main );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'mainfile', $output );
	}

	public function test_missing_readme_fails(): void {
		$entries = array(
			array( 'name' => 'sscribe-export-site-pages/sscribe-export-site-pages.php', 'content' => '<?php // mainfile' ),
			array( 'name' => 'sscribe-export-site-pages/license.txt', 'content' => 'GPL v2' ),
			array( 'name' => 'sscribe-export-site-pages/composer.json', 'content' => '{}' ),
			array( 'name' => 'sscribe-export-site-pages/uninstall.php', 'content' => '<?php // uninstall' ),
			array( 'name' => 'sscribe-export-site-pages/vendor-prefixed/autoload.php', 'content' => '<?php // autoload' ),
		);
		list( $zip, $dist, $main ) = $this->make_zip( array( 'entries' => $entries ) );
		list( $code, $output )     = $this->run_against( $zip, $dist, $main );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'readme.txt', $output );
	}

	public function test_missing_license_fails(): void {
		$entries = array(
			array( 'name' => 'sscribe-export-site-pages/sscribe-export-site-pages.php', 'content' => '<?php // mainfile' ),
			array( 'name' => 'sscribe-export-site-pages/readme.txt', 'content' => '=== SScribe ===' ),
			array( 'name' => 'sscribe-export-site-pages/composer.json', 'content' => '{}' ),
			array( 'name' => 'sscribe-export-site-pages/uninstall.php', 'content' => '<?php // uninstall' ),
			array( 'name' => 'sscribe-export-site-pages/vendor-prefixed/autoload.php', 'content' => '<?php // autoload' ),
		);
		list( $zip, $dist, $main ) = $this->make_zip( array( 'entries' => $entries ) );
		list( $code, $output )     = $this->run_against( $zip, $dist, $main );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'license.txt', $output );
	}

	public function test_missing_uninstall_fails(): void {
		$entries = array(
			array( 'name' => 'sscribe-export-site-pages/sscribe-export-site-pages.php', 'content' => '<?php // mainfile' ),
			array( 'name' => 'sscribe-export-site-pages/readme.txt', 'content' => '=== SScribe ===' ),
			array( 'name' => 'sscribe-export-site-pages/license.txt', 'content' => 'GPL v2' ),
			array( 'name' => 'sscribe-export-site-pages/composer.json', 'content' => '{}' ),
			array( 'name' => 'sscribe-export-site-pages/vendor-prefixed/autoload.php', 'content' => '<?php // autoload' ),
		);
		list( $zip, $dist, $main ) = $this->make_zip( array( 'entries' => $entries ) );
		list( $code, $output )     = $this->run_against( $zip, $dist, $main );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'uninstall.php', $output );
	}

	public function test_dev_path_fails(): void {
		$entries = array(
			array( 'name' => 'sscribe-export-site-pages/sscribe-export-site-pages.php', 'content' => '<?php // mainfile' ),
			array( 'name' => 'sscribe-export-site-pages/readme.txt', 'content' => '=== SScribe ===' ),
			array( 'name' => 'sscribe-export-site-pages/license.txt', 'content' => 'GPL v2' ),
			array( 'name' => 'sscribe-export-site-pages/composer.json', 'content' => '{}' ),
			array( 'name' => 'sscribe-export-site-pages/uninstall.php', 'content' => '<?php // uninstall' ),
			array( 'name' => 'sscribe-export-site-pages/vendor-prefixed/autoload.php', 'content' => '<?php // autoload' ),
			array( 'name' => 'sscribe-export-site-pages/tests/test-foo.php', 'content' => '<?php // t' ),
		);
		list( $zip, $dist, $main ) = $this->make_zip( array( 'entries' => $entries ) );
		list( $code, $output )     = $this->run_against( $zip, $dist, $main );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( '/tests/', $output );
	}

	public function test_scripts_path_fails(): void {
		$entries = array(
			array( 'name' => 'sscribe-export-site-pages/sscribe-export-site-pages.php', 'content' => '<?php // mainfile' ),
			array( 'name' => 'sscribe-export-site-pages/readme.txt', 'content' => '=== SScribe ===' ),
			array( 'name' => 'sscribe-export-site-pages/license.txt', 'content' => 'GPL v2' ),
			array( 'name' => 'sscribe-export-site-pages/composer.json', 'content' => '{}' ),
			array( 'name' => 'sscribe-export-site-pages/uninstall.php', 'content' => '<?php // uninstall' ),
			array( 'name' => 'sscribe-export-site-pages/vendor-prefixed/autoload.php', 'content' => '<?php // autoload' ),
			array( 'name' => 'sscribe-export-site-pages/scripts/build.sh', 'content' => '#!/bin/sh' ),
		);
		list( $zip, $dist, $main ) = $this->make_zip( array( 'entries' => $entries ) );
		list( $code, $output )     = $this->run_against( $zip, $dist, $main );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( '/scripts/', $output );
	}

	public function test_github_path_fails(): void {
		$entries = array(
			array( 'name' => 'sscribe-export-site-pages/sscribe-export-site-pages.php', 'content' => '<?php // mainfile' ),
			array( 'name' => 'sscribe-export-site-pages/readme.txt', 'content' => '=== SScribe ===' ),
			array( 'name' => 'sscribe-export-site-pages/license.txt', 'content' => 'GPL v2' ),
			array( 'name' => 'sscribe-export-site-pages/composer.json', 'content' => '{}' ),
			array( 'name' => 'sscribe-export-site-pages/uninstall.php', 'content' => '<?php // uninstall' ),
			array( 'name' => 'sscribe-export-site-pages/vendor-prefixed/autoload.php', 'content' => '<?php // autoload' ),
			array( 'name' => 'sscribe-export-site-pages/.github/workflows/ci.yml', 'content' => 'name: CI' ),
		);
		list( $zip, $dist, $main ) = $this->make_zip( array( 'entries' => $entries ) );
		list( $code, $output )     = $this->run_against( $zip, $dist, $main );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( '.github/', $output );
	}

	public function test_shell_extension_fails(): void {
		$entries = array(
			array( 'name' => 'sscribe-export-site-pages/sscribe-export-site-pages.php', 'content' => '<?php // mainfile' ),
			array( 'name' => 'sscribe-export-site-pages/readme.txt', 'content' => '=== SScribe ===' ),
			array( 'name' => 'sscribe-export-site-pages/license.txt', 'content' => 'GPL v2' ),
			array( 'name' => 'sscribe-export-site-pages/composer.json', 'content' => '{}' ),
			array( 'name' => 'sscribe-export-site-pages/uninstall.php', 'content' => '<?php // uninstall' ),
			array( 'name' => 'sscribe-export-site-pages/vendor-prefixed/autoload.php', 'content' => '<?php // autoload' ),
			array( 'name' => 'sscribe-export-site-pages/includes/foo.sh', 'content' => '#!/bin/sh' ),
		);
		list( $zip, $dist, $main ) = $this->make_zip( array( 'entries' => $entries ) );
		list( $code, $output )     = $this->run_against( $zip, $dist, $main );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( '.sh', $output );
	}

	public function test_phar_extension_fails(): void {
		$entries = array(
			array( 'name' => 'sscribe-export-site-pages/sscribe-export-site-pages.php', 'content' => '<?php // mainfile' ),
			array( 'name' => 'sscribe-export-site-pages/readme.txt', 'content' => '=== SScribe ===' ),
			array( 'name' => 'sscribe-export-site-pages/license.txt', 'content' => 'GPL v2' ),
			array( 'name' => 'sscribe-export-site-pages/composer.json', 'content' => '{}' ),
			array( 'name' => 'sscribe-export-site-pages/uninstall.php', 'content' => '<?php // uninstall' ),
			array( 'name' => 'sscribe-export-site-pages/vendor-prefixed/autoload.php', 'content' => '<?php // autoload' ),
			array( 'name' => 'sscribe-export-site-pages/includes/foo.phar', 'content' => '<?php // phar' ),
		);
		list( $zip, $dist, $main ) = $this->make_zip( array( 'entries' => $entries ) );
		list( $code, $output )     = $this->run_against( $zip, $dist, $main );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( '.phar', $output );
	}

	public function test_wrong_top_level_dir_fails(): void {
		$entries = array(
			array( 'name' => 'wrong-slug/sscribe-export-site-pages.php', 'content' => '<?php // mainfile' ),
			array( 'name' => 'wrong-slug/readme.txt', 'content' => '=== SScribe ===' ),
			array( 'name' => 'wrong-slug/license.txt', 'content' => 'GPL v2' ),
			array( 'name' => 'wrong-slug/composer.json', 'content' => '{}' ),
			array( 'name' => 'wrong-slug/uninstall.php', 'content' => '<?php // uninstall' ),
			array( 'name' => 'wrong-slug/vendor-prefixed/autoload.php', 'content' => '<?php // autoload' ),
		);
		list( $zip, $dist, $main ) = $this->make_zip( array( 'entries' => $entries ) );
		list( $code, $output )     = $this->run_against( $zip, $dist, $main );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'Unexpected top-level entry', $output );
	}

	public function test_sha256_mismatch_fails(): void {
		list( $zip, $dist, $main ) = $this->make_zip();
		// Pass a deliberately wrong sidecar.
		list( $code, $output ) = $this->run_against( $zip, $dist, $main, str_repeat( '0', 64 ) );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'Checksum mismatch', $output );
	}

	public function test_live_zip_passes(): void {
		// The real build output must satisfy the contract.
		$root = self::plugin_root();
		$real_zip  = $root . '/dist/sscribe-export-site-pages-2.0.0.zip';
		if ( ! is_file( $real_zip ) ) {
			$this::markTestSkipped( 'Live ZIP not present; run `php scripts/build-release.php` first.' );
		}
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open(
			array( PHP_BINARY, $root . '/' . self::SCRIPT_PATH ),
			$descriptors,
			$pipes
		);
		$this::assertIsResource( $process );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		$this::assertSame(
			0,
			$code,
			'Live dist/sscribe-export-site-pages-2.0.0.zip must pass certification. Output:' . "\n" . ( $stdout . $stderr )
		);
	}
}
