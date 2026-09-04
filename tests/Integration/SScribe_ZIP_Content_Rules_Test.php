<?php
/**
 * SScribe ZIP Content Rules Integration Test
 *
 * @package SScribe_Export_Site_Pages
 *
 * Phase 35: locks the ZIP content rules contract.
 *
 * The submission ZIP must satisfy the deeper content rules
 * beyond the structural checks in Phase 34: every shipped .php
 * must parse, the composer.json must point at the prefixed
 * vendor tree, the readme must fit WP.org's 10 KiB cap, the
 * languages/ directory must contain a translation template,
 * no entry is empty, no OS metadata files slip in, and the
 * mainfile Version header must match the ZIP filename version.
 *
 * This integration test runs scripts/verify-zip-content-rules.php
 * against a synthetic dist/ tree, and against the live dist tree
 * (test_live_tree_passes).
 *
 * A regression that:
 *   - silently accepts a PHP parse error in the dist tree,
 *   - silently accepts composer.json pointing at `vendor/`,
 *   - silently accepts an empty file,
 *   - silently accepts an OS metadata file,
 *   - silently accepts readme.txt over 10 KiB,
 *   - silently accepts a missing languages/ directory,
 *   - silently accepts a Version mismatch,
 *
 * ...fails the suite immediately.
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\Group('release-contract')]
final class SScribe_ZIP_Content_Rules_Test extends TestCase {

	private const SCRIPT_PATH = 'scripts/verify-zip-content-rules.php';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Build a synthetic dist/ tree with overrides and run the verifier
	 * against it. Returns [exit code, output].
	 *
	 * @param array<string,mixed> $overrides
	 * @return array{0:int,1:string}
	 */
	private function run_against_dist( array $overrides ): array {
		$root          = self::plugin_root();
		$real_dist     = $root . '/dist/sscribe-export-site-pages';
		$real_mainfile = $root . '/sscribe-export-site-pages.php';
		$real_zip      = $root . '/dist/sscribe-export-site-pages-9.9.9.zip';
		$dist_dir      = $root . '/dist';

		$backup_dist     = is_dir( $real_dist ) ? $this->snapshot_dir( $real_dist ) : null;
		$backup_mainfile = is_file( $real_mainfile ) ? (string) file_get_contents( $real_mainfile ) : null;
		$backup_zip      = is_file( $real_zip ) ? (string) file_get_contents( $real_zip ) : null;
		// Snapshot every ZIP in dist/ (not just 9.9.9) so the verifier
		// does not see a stray 2.0.0 archive from a prior real build.
		$stray_zips = array();
		$zip_glob   = glob( $dist_dir . '/sscribe-export-site-pages-*.zip' );
		if ( is_array( $zip_glob ) ) {
			foreach ( $zip_glob as $zp ) {
				if ( $zp !== $real_zip ) {
					$stray_zips[] = $zp;
				}
			}
		}
		$stray_sha = glob( $dist_dir . '/sscribe-export-site-pages-*.sha256' );
		$stray_sha_backup = array();
		if ( is_array( $stray_sha ) ) {
			foreach ( $stray_sha as $sf ) {
				$stray_sha_backup[ $sf ] = (string) file_get_contents( $sf );
			}
		}
		// Sentinels we touch in addition to the canonical 9.9.9 one.
		// We must clean them up in finally.
		$extra_sentinels = array();

		// Reset the dist/ tree.
		$this->rrmdir( $real_dist );
		if ( ! is_dir( $real_dist ) ) {
			mkdir( $real_dist, 0755, true );
		}
		// Move stray ZIPs and SHAs out of dist/ while the test runs.
		$stray_tmp_dir = sys_get_temp_dir() . '/sscribe-content-rules-stray-' . bin2hex( random_bytes( 4 ) );
		mkdir( $stray_tmp_dir, 0755, true );
		foreach ( $stray_zips as $zp ) {
			rename( $zp, $stray_tmp_dir . '/' . basename( $zp ) );
		}
		foreach ( array_keys( $stray_sha_backup ) as $sf ) {
			rename( $sf, $stray_tmp_dir . '/' . basename( $sf ) );
		}
		// Use a 9.9.9 version for synthetic runs so the ZIP filename
		// pattern matches.
		$mainfile_src = "<?php\n/**\n * Plugin Name: SScribe Export Site Pages\n * Version: 9.9.9-test\n */\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $real_mainfile, $mainfile_src );
		// Create a sentinel ZIP so the verifier's "filename matches version"
		// check has a real file to look at.
		$zip_touch = fopen( $real_zip, 'w' );
		if ( is_resource( $zip_touch ) ) {
			fwrite( $zip_touch, 'PK' );
			fclose( $zip_touch );
		}

		// Apply overrides.
		if ( isset( $overrides['tree_builder'] ) && is_callable( $overrides['tree_builder'] ) ) {
			call_user_func( $overrides['tree_builder'], $real_dist );
		} else {
			$this->build_well_formed_tree( $real_dist );
		}

		// Note: the verifier globs dist/ for sscribe-export-site-pages-*.zip
		// and checks the version segment against the dist mainfile's version.
		// The 9.9.9 sentinel the test created above stays in place — for
		// tests where the dist mainfile version is 9.9.9, that sentinel
		// matches; for the version-mismatch test (mainfile says 1.0.0),
		// the verifier sees a 9.9.9 ZIP in dist/ that does NOT match the
		// 1.0.0 mainfile and reports the mismatch.

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
			// Restore original dist + mainfile + zip.
			$this->rrmdir( $real_dist );
			if ( null !== $backup_dist ) {
				// Snapshot is relative to $real_dist, so restore uses
				// $real_dist as the base — not the parent dist/ — to
				// put the files back where they came from.
				$this->restore_dir( $real_dist, $backup_dist );
			}
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
			foreach ( $extra_sentinels as $extra ) {
				@unlink( $extra );
			}
			// Move stray ZIPs and SHAs back into dist/.
			foreach ( $stray_zips as $zp ) {
				$moved = $stray_tmp_dir . '/' . basename( $zp );
				if ( is_file( $moved ) ) {
					rename( $moved, $zp );
				}
			}
			foreach ( $stray_sha_backup as $sf => $content ) {
				$moved = $stray_tmp_dir . '/' . basename( $sf );
				if ( is_file( $moved ) ) {
					rename( $moved, $sf );
				}
			}
			@rmdir( $stray_tmp_dir );
		}
	}

	private function build_well_formed_tree( string $root ): void {
		$tree = array(
			'sscribe-export-site-pages.php' => "<?php\n/**\n * Plugin Name: SScribe\n * Version: 9.9.9-test\n */\n",
			'readme.txt'                    => "=== SScribe ===\n",
			'license.txt'                   => "GPL v2\n",
			'uninstall.php'                 => "<?php // uninstall\n",
			'composer.json'                 => json_encode(
				array(
					'name'    => 'simplix/sscribe-export-site-pages',
					'autoload' => array(
						'psr-4' => array(
							'SScribe\\' => 'includes/',
						),
						'classmap' => array( 'vendor-prefixed/' ),
					),
				)
			),
			'vendor-prefixed/autoload.php'  => "<?php // autoload\n",
			'includes/collector.php'        => "<?php // collector\n",
			'languages/sscribe-export-site-pages.pot' => 'msgid ""',
			'assets/icons/index.svg'        => '<svg/>',
			'assets/fonts/icon.woff2'       => 'f',
		);
		$this->write_tree( $root, $tree );
	}

	private function write_tree( string $root, array $tree ): void {
		foreach ( $tree as $rel => $content ) {
			$path = $root . '/' . $rel;
			$dir  = dirname( $path );
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0755, true );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $path, (string) $content );
		}
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}

	private function snapshot_dir( string $dir ): array {
		$result = array();
		$iter   = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $iter as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$rel = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) + 1 ) );
			$result[ $rel ] = (string) file_get_contents( $file->getPathname() );
		}
		return $result;
	}

	private function restore_dir( string $dir, array $snapshot ): void {
		$this->rrmdir( $dir );
		mkdir( $dir, 0755, true );
		foreach ( $snapshot as $rel => $content ) {
			$path = $dir . '/' . $rel;
			$pdir = dirname( $path );
			if ( ! is_dir( $pdir ) ) {
				mkdir( $pdir, 0755, true );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $path, (string) $content );
		}
	}

	public function test_well_formed_passes(): void {
		list( $code, $output ) = $this->run_against_dist( array() );
		$this::assertSame(
			0,
			$code,
			'Well-formed dist tree must pass. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'ZIP content rules hold', $output );
	}

	public function test_php_parse_error_fails(): void {
		$builder = static function ( string $root ): void {
			$tree = array(
				'sscribe-export-site-pages.php' => "<?php\n/**\n * Plugin Name: SScribe\n * Version: 9.9.9-test\n */\n",
				'readme.txt'                    => "=== SScribe ===\n",
				'license.txt'                   => "GPL v2\n",
				'uninstall.php'                 => "<?php // uninstall\n",
				'composer.json'                 => json_encode( array( 'autoload' => array( 'classmap' => array( 'vendor-prefixed/' ) ) ) ),
				'vendor-prefixed/autoload.php'  => "<?php // autoload\n",
				'includes/broken.php'           => "<?php\nthis is not valid PHP syntax(((\n",
				'languages/sscribe-export-site-pages.pot' => 'msgid ""',
			);
			foreach ( $tree as $rel => $content ) {
				$path = $root . '/' . $rel;
				$dir  = dirname( $path );
				if ( ! is_dir( $dir ) ) {
					mkdir( $dir, 0755, true );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $path, (string) $content );
			}
		};
		list( $code, $output ) = $this->run_against_dist( array( 'tree_builder' => $builder ) );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'PHP parse error', $output );
		$this::assertStringContainsString( 'broken.php', $output );
	}

	public function test_composer_json_vendor_fails(): void {
		$builder = static function ( string $root ): void {
			$tree = array(
				'sscribe-export-site-pages.php' => "<?php\n/**\n * Plugin Name: SScribe\n * Version: 9.9.9-test\n */\n",
				'readme.txt'                    => "=== SScribe ===\n",
				'license.txt'                   => "GPL v2\n",
				'uninstall.php'                 => "<?php // uninstall\n",
				'composer.json'                 => json_encode(
					array(
						'autoload' => array(
							'psr-4' => array( 'SScribe\\' => 'vendor/sscribe/' ),
						),
					)
				),
				'vendor-prefixed/autoload.php'  => "<?php // autoload\n",
				'includes/collector.php'        => "<?php // collector\n",
				'languages/sscribe-export-site-pages.pot' => 'msgid ""',
			);
			foreach ( $tree as $rel => $content ) {
				$path = $root . '/' . $rel;
				$dir  = dirname( $path );
				if ( ! is_dir( $dir ) ) {
					mkdir( $dir, 0755, true );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $path, (string) $content );
			}
		};
		list( $code, $output ) = $this->run_against_dist( array( 'tree_builder' => $builder ) );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'composer.json autoload still references `vendor/`', $output );
	}

	public function test_empty_file_fails(): void {
		$builder = static function ( string $root ): void {
			$tree = array(
				'sscribe-export-site-pages.php' => "<?php\n/**\n * Plugin Name: SScribe\n * Version: 9.9.9-test\n */\n",
				'readme.txt'                    => "=== SScribe ===\n",
				'license.txt'                   => "GPL v2\n",
				'uninstall.php'                 => "<?php // uninstall\n",
				'composer.json'                 => json_encode( array( 'autoload' => array( 'classmap' => array( 'vendor-prefixed/' ) ) ) ),
				'vendor-prefixed/autoload.php'  => "<?php // autoload\n",
				'includes/empty.php'            => '',
				'languages/sscribe-export-site-pages.pot' => 'msgid ""',
			);
			foreach ( $tree as $rel => $content ) {
				$path = $root . '/' . $rel;
				$dir  = dirname( $path );
				if ( ! is_dir( $dir ) ) {
					mkdir( $dir, 0755, true );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $path, (string) $content );
			}
		};
		list( $code, $output ) = $this->run_against_dist( array( 'tree_builder' => $builder ) );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'empty file', $output );
	}

	public function test_os_metadata_fails(): void {
		$builder = static function ( string $root ): void {
			$tree = array(
				'sscribe-export-site-pages.php' => "<?php\n/**\n * Plugin Name: SScribe\n * Version: 9.9.9-test\n */\n",
				'readme.txt'                    => "=== SScribe ===\n",
				'license.txt'                   => "GPL v2\n",
				'uninstall.php'                 => "<?php // uninstall\n",
				'composer.json'                 => json_encode( array( 'autoload' => array( 'classmap' => array( 'vendor-prefixed/' ) ) ) ),
				'vendor-prefixed/autoload.php'  => "<?php // autoload\n",
				'includes/collector.php'        => "<?php // collector\n",
				'Thumbs.db'                     => 'binary',
				'languages/sscribe-export-site-pages.pot' => 'msgid ""',
			);
			foreach ( $tree as $rel => $content ) {
				$path = $root . '/' . $rel;
				$dir  = dirname( $path );
				if ( ! is_dir( $dir ) ) {
					mkdir( $dir, 0755, true );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $path, (string) $content );
			}
		};
		list( $code, $output ) = $this->run_against_dist( array( 'tree_builder' => $builder ) );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'OS metadata', $output );
	}

	public function test_oversized_readme_fails(): void {
		$builder = static function ( string $root ): void {
			$tree = array(
				'sscribe-export-site-pages.php' => "<?php\n/**\n * Plugin Name: SScribe\n * Version: 9.9.9-test\n */\n",
				'readme.txt'                    => str_repeat( 'A', 11000 ),
				'license.txt'                   => "GPL v2\n",
				'uninstall.php'                 => "<?php // uninstall\n",
				'composer.json'                 => json_encode( array( 'autoload' => array( 'classmap' => array( 'vendor-prefixed/' ) ) ) ),
				'vendor-prefixed/autoload.php'  => "<?php // autoload\n",
				'includes/collector.php'        => "<?php // collector\n",
				'languages/sscribe-export-site-pages.pot' => 'msgid ""',
			);
			foreach ( $tree as $rel => $content ) {
				$path = $root . '/' . $rel;
				$dir  = dirname( $path );
				if ( ! is_dir( $dir ) ) {
					mkdir( $dir, 0755, true );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $path, (string) $content );
			}
		};
		list( $code, $output ) = $this->run_against_dist( array( 'tree_builder' => $builder ) );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'readme.txt is', $output );
	}

	public function test_missing_languages_fails(): void {
		$builder = static function ( string $root ): void {
			$tree = array(
				'sscribe-export-site-pages.php' => "<?php\n/**\n * Plugin Name: SScribe\n * Version: 9.9.9-test\n */\n",
				'readme.txt'                    => "=== SScribe ===\n",
				'license.txt'                   => "GPL v2\n",
				'uninstall.php'                 => "<?php // uninstall\n",
				'composer.json'                 => json_encode( array( 'autoload' => array( 'classmap' => array( 'vendor-prefixed/' ) ) ) ),
				'vendor-prefixed/autoload.php'  => "<?php // autoload\n",
				'includes/collector.php'        => "<?php // collector\n",
			);
			foreach ( $tree as $rel => $content ) {
				$path = $root . '/' . $rel;
				$dir  = dirname( $path );
				if ( ! is_dir( $dir ) ) {
					mkdir( $dir, 0755, true );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $path, (string) $content );
			}
		};
		list( $code, $output ) = $this->run_against_dist( array( 'tree_builder' => $builder ) );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'languages/', $output );
	}

	public function test_version_mismatch_fails(): void {
		// The verifier reads the dist/mainfile version and expects the
		// ZIP file to be named `sscribe-export-site-pages-{version}.zip`.
		// We write a mainfile with Version: 1.0.0 but the run_against_dist
		// helper always touches a sentinel ZIP at the 9.9.9 path; that
		// produces a real mismatch.
		$builder = static function ( string $root ): void {
			$tree = array(
				'sscribe-export-site-pages.php' => "<?php\n/**\n * Plugin Name: SScribe\n * Version: 1.0.0-mismatch\n */\n",
				'readme.txt'                    => "=== SScribe ===\n",
				'license.txt'                   => "GPL v2\n",
				'uninstall.php'                 => "<?php // uninstall\n",
				'composer.json'                 => json_encode( array( 'autoload' => array( 'classmap' => array( 'vendor-prefixed/' ) ) ) ),
				'vendor-prefixed/autoload.php'  => "<?php // autoload\n",
				'includes/collector.php'        => "<?php // collector\n",
				'languages/sscribe-export-site-pages.pot' => 'msgid ""',
				'assets/icons/index.svg'        => '<svg/>',
			);
			foreach ( $tree as $rel => $content ) {
				$path = $root . '/' . $rel;
				$dir  = dirname( $path );
				if ( ! is_dir( $dir ) ) {
					mkdir( $dir, 0755, true );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $path, (string) $content );
			}
		};
		list( $code, $output ) = $this->run_against_dist( array( 'tree_builder' => $builder ) );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'ZIP filename', $output );
	}

	/**
	 * The dist tree must not contain any dev-only top-level directory
	 * — `.git/`, `.github/`, `tests/`, `node_modules/`, plain `vendor/`,
	 * `coverage/`, `scripts/`, etc. Add a `.git/HEAD` file to the tree
	 * and confirm the verifier catches it.
	 */
	public function test_forbidden_top_segment_fails(): void {
		$builder = static function ( string $root ): void {
			$tree = array(
				'sscribe-export-site-pages.php' => "<?php\n/**\n * Plugin Name: SScribe\n * Version: 9.9.9-test\n */\n",
				'readme.txt'                    => "=== SScribe ===\n",
				'license.txt'                   => "GPL v2\n",
				'uninstall.php'                 => "<?php // uninstall\n",
				'composer.json'                 => json_encode( array( 'autoload' => array( 'classmap' => array( 'vendor-prefixed/' ) ) ) ),
				'vendor-prefixed/autoload.php'  => "<?php // autoload\n",
				'includes/collector.php'        => "<?php // collector\n",
				'languages/sscribe-export-site-pages.pot' => 'msgid ""',
				'assets/icons/index.svg'        => '<svg/>',
				'.git/HEAD'                     => "ref: refs/heads/main\n",
				'tests/leftover.php'            => "<?php // leftover dev test\n",
			);
			foreach ( $tree as $rel => $content ) {
				$path = $root . '/' . $rel;
				$dir  = dirname( $path );
				if ( ! is_dir( $dir ) ) {
					mkdir( $dir, 0755, true );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $path, (string) $content );
			}
		};
		list( $code, $output ) = $this->run_against_dist( array( 'tree_builder' => $builder ) );
		$this::assertSame( 1, $code, 'Forbidden top-segment must fail. Output:' . "\n" . $output );
		$this::assertStringContainsString( 'dev-only top-level entry `.git/`', $output );
		$this::assertStringContainsString( 'dev-only top-level entry `tests/`', $output );
	}

	/**
	 * The dist tree must not contain IDE / temp / env / log markers
	 * (`.env`, `.env.local`, `*.log`, `*.swp`, `*~`, etc.). Plant a
	 * handful and confirm the verifier flags every pattern it sees.
	 */
	public function test_forbidden_basename_fails(): void {
		$builder = static function ( string $root ): void {
			$tree = array(
				'sscribe-export-site-pages.php' => "<?php\n/**\n * Plugin Name: SScribe\n * Version: 9.9.9-test\n */\n",
				'readme.txt'                    => "=== SScribe ===\n",
				'license.txt'                   => "GPL v2\n",
				'uninstall.php'                 => "<?php // uninstall\n",
				'composer.json'                 => json_encode( array( 'autoload' => array( 'classmap' => array( 'vendor-prefixed/' ) ) ) ),
				'vendor-prefixed/autoload.php'  => "<?php // autoload\n",
				'includes/collector.php'        => "<?php // collector\n",
				'languages/sscribe-export-site-pages.pot' => 'msgid ""',
				'assets/icons/index.svg'        => '<svg/>',
				// Plant three forbidden basename patterns.
				'includes/.env'                 => "SECRET=leaked\n",
				'includes/debug.log'            => "noise\n",
				'includes/collector.php.swp'    => "vim swap\n",
			);
			foreach ( $tree as $rel => $content ) {
				$path = $root . '/' . $rel;
				$dir  = dirname( $path );
				if ( ! is_dir( $dir ) ) {
					mkdir( $dir, 0755, true );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $path, (string) $content );
			}
		};
		list( $code, $output ) = $this->run_against_dist( array( 'tree_builder' => $builder ) );
		$this::assertSame( 1, $code, 'Forbidden basename must fail. Output:' . "\n" . $output );
		$this::assertStringContainsString( 'IDE/temp/env/log file shipped', $output );
		$this::assertStringContainsString( '.env', $output );
		$this::assertStringContainsString( 'debug.log', $output );
		$this::assertStringContainsString( 'collector.php.swp', $output );
	}

	/**
	 * A submission ZIP must contain the runtime files WP.org requires:
	 * vendor-prefixed/, vendor-prefixed/autoload.php, assets/, license.txt,
	 * uninstall.php, composer.json. Strip vendor-prefixed/ entirely and
	 * confirm the verifier fails on the missing-directory check (not
	 * just the missing-autoload check).
	 */
	public function test_missing_required_runtime_fails(): void {
		$builder = static function ( string $root ): void {
			// Defensive: clear any vendor-prefixed/ leftover from a
			// previous test's snapshot/restore before rebuilding the
			// tree. Windows file locks can leave an empty directory
			// behind after rrmdir() and that would mask the violation
			// this test is meant to flag.
			if ( is_dir( $root . '/vendor-prefixed' ) ) {
				$iter = new \RecursiveDirectoryIterator( $root . '/vendor-prefixed', \FilesystemIterator::SKIP_DOTS );
				foreach ( new \RecursiveIteratorIterator( $iter, \RecursiveIteratorIterator::CHILD_FIRST ) as $node ) {
					if ( $node->isDir() ) {
						@rmdir( $node->getPathname() );
					} else {
						@unlink( $node->getPathname() );
					}
				}
				@rmdir( $root . '/vendor-prefixed' );
			}
			$tree = array(
				'sscribe-export-site-pages.php' => "<?php\n/**\n * Plugin Name: SScribe\n * Version: 9.9.9-test\n */\n",
				'readme.txt'                    => "=== SScribe ===\n",
				'license.txt'                   => "GPL v2\n",
				'uninstall.php'                 => "<?php // uninstall\n",
				'composer.json'                 => json_encode( array( 'autoload' => array( 'classmap' => array( 'vendor-prefixed/' ) ) ) ),
				// vendor-prefixed/ intentionally absent.
				'includes/collector.php'        => "<?php // collector\n",
				'languages/sscribe-export-site-pages.pot' => 'msgid ""',
				'assets/icons/index.svg'        => '<svg/>',
			);
			foreach ( $tree as $rel => $content ) {
				$path = $root . '/' . $rel;
				$dir  = dirname( $path );
				if ( ! is_dir( $dir ) ) {
					mkdir( $dir, 0755, true );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $path, (string) $content );
			}
		};
		list( $code, $output ) = $this->run_against_dist( array( 'tree_builder' => $builder ) );
		$this::assertSame( 1, $code, 'Missing vendor-prefixed/ must fail. Output:' . "\n" . $output );
		$this::assertStringContainsString( 'Required runtime directory `vendor-prefixed/`', $output );
	}

	/**
	 * An empty assets/ directory is as useless as a missing one — the
	 * admin UI would 404 every icon/font it tries to load. The verifier
	 * must flag an empty directory as a violation.
	 */
	public function test_empty_assets_dir_fails(): void {
		$builder = static function ( string $root ): void {
			$tree = array(
				'sscribe-export-site-pages.php' => "<?php\n/**\n * Plugin Name: SScribe\n * Version: 9.9.9-test\n */\n",
				'readme.txt'                    => "=== SScribe ===\n",
				'license.txt'                   => "GPL v2\n",
				'uninstall.php'                 => "<?php // uninstall\n",
				'composer.json'                 => json_encode( array( 'autoload' => array( 'classmap' => array( 'vendor-prefixed/' ) ) ) ),
				'vendor-prefixed/autoload.php'  => "<?php // autoload\n",
				'includes/collector.php'        => "<?php // collector\n",
				'languages/sscribe-export-site-pages.pot' => 'msgid ""',
			);
			foreach ( $tree as $rel => $content ) {
				$path = $root . '/' . $rel;
				$dir  = dirname( $path );
				if ( ! is_dir( $dir ) ) {
					mkdir( $dir, 0755, true );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $path, (string) $content );
			}
			// assets/ exists but is empty.
			mkdir( $root . '/assets', 0755, true );
		};
		list( $code, $output ) = $this->run_against_dist( array( 'tree_builder' => $builder ) );
		$this::assertSame( 1, $code, 'Empty assets/ must fail. Output:' . "\n" . $output );
		$this::assertStringContainsString( '`assets/` is empty', $output );
	}

	public function test_live_tree_passes(): void {
		$root      = self::plugin_root();
		$real_dist = $root . '/dist/sscribe-export-site-pages';
		if ( ! is_dir( $real_dist ) ) {
			$this::markTestSkipped( 'Live dist/ tree not present; run `php scripts/build-release.php` first.' );
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
			'Live dist/ tree must satisfy the content-rules contract. Output:' . "\n" . ( $stdout . $stderr )
		);
	}
}
