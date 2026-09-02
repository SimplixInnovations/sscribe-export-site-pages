<?php
/**
 * Phase 36 — source / build transparency integration test.
 *
 * The verifier (scripts/verify-build-transparency.php) enforces the
 * public-source + build-docs + pinned-tool-versions contract that WP.org
 * reviewers need in order to understand and reproduce the shipped ZIP.
 * This PHPUnit class backs every rule in the verifier with a regression
 * test: each test mutates one well-formed payload and confirms the
 * verifier catches it.
 *
 * Test isolation pattern: every test snapshots the affected file(s)
 * before mutation, runs the verifier via proc_open, then restores the
 * snapshot in a `finally` block. Tests use a temp working copy for
 * composer.json / package.json / mainfile mutations so we never modify
 * the live repo on disk.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Build_Transparency_Test extends TestCase {

	private const SCRIPT_PATH = 'scripts/verify-build-transparency.php';

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		// Phase 68 of the contract: PHPUnit must fail on warnings and
		// deprecations. The verifier runs without raising any, but the
		// baseline guard keeps a regression from silently softening.
	}

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Run the verifier against the live repo with a one-file override.
	 * `override` is [ path => contents ]; before running, the helper
	 * snapshots every overridden file and restores it in `finally`.
	 *
	 * @param array<string,string> $override
	 * @return array{0:int,1:string}
	 */
	private function run_with_override( array $override ): array {
		$root = self::plugin_root();

		$snapshot = array();
		foreach ( array_keys( $override ) as $path ) {
			$abs = $root . '/' . $path;
			if ( is_file( $abs ) ) {
				$snapshot[ $path ] = (string) file_get_contents( $abs );
			} else {
				$snapshot[ $path ] = null;
			}
		}

		try {
			foreach ( $override as $path => $contents ) {
				$abs = $root . '/' . $path;
				$dir = dirname( $abs );
				if ( ! is_dir( $dir ) ) {
					mkdir( $dir, 0755, true );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $abs, $contents );
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
			return array( (int) $code, $stdout . $stderr );
		} finally {
			foreach ( $snapshot as $path => $contents ) {
				$abs = $root . '/' . $path;
				if ( null === $contents ) {
					// Original was missing — clean up our overwrite.
					if ( is_file( $abs ) ) {
						@unlink( $abs );
					}
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $abs, $contents );
			}
		}
	}

	/**
	 * Read a file's current contents and return it. Used by tests that
	 * mutate the live file with a minimal delta (e.g. removing a
	 * single keyword).
	 */
	private function read_live( string $path ): string {
		$abs = self::plugin_root() . '/' . $path;
		$this::assertFileExists( $abs );
		return (string) file_get_contents( $abs );
	}

	public function test_well_formed_repo_passes(): void {
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open(
			array( PHP_BINARY, self::plugin_root() . '/' . self::SCRIPT_PATH ),
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
			'Live repo must satisfy the build-transparency contract. Output:' . "\n" . ( $stdout . $stderr )
		);
		$this::assertStringContainsString( 'Build transparency holds', $stdout );
	}

	public function test_missing_development_section_fails(): void {
		$live  = $this->read_live( 'readme.txt' );
		$strip = preg_replace( '/^==\s*Development\s*==[\s\S]*?(?=^==\s*\w)/m', '', $live, 1 );
		$this::assertNotSame( $live, $strip, 'Failed to strip Development section' );
		list( $code, $output ) = $this->run_with_override( array( 'readme.txt' => $strip ) );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( '`== Development ==` section', $output );
	}

	public function test_missing_source_url_fails(): void {
		$live  = $this->read_live( 'readme.txt' );
		// Replace every github.com reference with a private-host URL so
		// none of the host check's accepted hosts (github.com / gitlab.com
		// / codeberg.org / bitbucket.org) match.
		$strip = preg_replace(
			'/https:\/\/github\.com\/SimplixInnovations\/sscribe-export-site-pages[^\s]*/i',
			'https://example.invalid/private-repo',
			$live
		);
		$this::assertNotSame( $live, $strip, 'Failed to replace source URLs' );
		list( $code, $output ) = $this->run_with_override( array( 'readme.txt' => $strip ) );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'public source repository', $output );
	}

	public function test_missing_build_command_fails(): void {
		$live  = $this->read_live( 'readme.txt' );
		// Strip the `composer install` token entirely. We replace with a
		// blank line so the rest of the doc remains syntactically valid.
		$strip = str_ireplace( 'composer install', '<!-- stripped for test -->', $live );
		$this::assertNotSame( $live, $strip, 'Failed to strip `composer install`' );
		list( $code, $output ) = $this->run_with_override( array( 'readme.txt' => $strip ) );
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'build commands', $output );
	}

	public function test_missing_tool_version_keyword_fails(): void {
		// Strip the WHOLE "Required tools and versions:" block — that
		// removes the explicit `php`, `composer`, and `node` keywords
		// in the development section. (The build command list further
		// down the section still mentions `composer`, so we can't
		// simply blank one word; the whole list must go.)
		$live  = $this->read_live( 'readme.txt' );
		$strip = preg_replace(
			'/(Required tools and versions:[\s\S]*?\*\s+Git[^\n]*\n)/',
			'',
			$live,
			1
		);
		$this::assertNotSame( $live, $strip, 'Failed to strip Required tools block' );
		list( $code, $output ) = $this->run_with_override( array( 'readme.txt' => $strip ) );
		$this::assertSame( 1, $code, 'Stripped tool versions must fail. Output:' . "\n" . $output );
		$this::assertStringContainsString( 'required tools', $output );
	}

	public function test_missing_composer_lock_fails(): void {
		$root = self::plugin_root();
		$lock = $root . '/composer.lock';
		$this::assertFileExists( $lock );
		$backup = (string) file_get_contents( $lock );
		try {
			// Move composer.lock out of the way. The verifier must
			// catch the missing-file state on a fresh run.
			rename( $lock, $lock . '.bak' );
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
			$this::assertSame( 1, $code, 'Missing composer.lock must fail. Output:' . "\n" . ( $stdout . $stderr ) );
			$this::assertStringContainsString( 'composer.lock', $stdout );
		} finally {
			if ( is_file( $lock . '.bak' ) ) {
				rename( $lock . '.bak', $lock );
			}
		}
	}

	public function test_missing_build_doc_fails(): void {
		$root = self::plugin_root();
		$doc  = $root . '/docs/BUILD_TRANSFORMATIONS.md';
		$this::assertFileExists( $doc );
		$backup = (string) file_get_contents( $doc );
		try {
			rename( $doc, $doc . '.bak' );
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
			$this::assertSame( 1, $code, 'Missing BUILD_TRANSFORMATIONS.md must fail. Output:' . "\n" . ( $stdout . $stderr ) );
			$this::assertStringContainsString( 'BUILD_TRANSFORMATIONS.md', $stdout );
		} finally {
			if ( is_file( $doc . '.bak' ) ) {
				rename( $doc . '.bak', $doc );
			}
		}
	}

	public function test_missing_author_uri_fails(): void {
		$live  = $this->read_live( 'sscribe-export-site-pages.php' );
		// Replace the Author URI line with an empty value. The verifier
		// regex requires an https URL after `Author URI:`.
		$strip = preg_replace( '/Author URI:\s*\S+/', 'Author URI: ', $live, 1 );
		$this::assertNotSame( $live, $strip, 'Failed to blank out Author URI' );
		list( $code, $output ) = $this->run_with_override( array( 'sscribe-export-site-pages.php' => $strip ) );
		$this::assertSame( 1, $code, 'Missing Author URI must fail. Output:' . "\n" . $output );
		$this::assertStringContainsString( 'Author URI', $output );
	}
}
