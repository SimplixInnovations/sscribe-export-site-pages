<?php
/**
 * SScribe Upgrade Notice Integration Test
 *
 * @package SScribe_Export_Site_Pages
 *
 * Phase 24: locks the WordPress.org Upgrade Notice contract.
 *
 * WordPress.org shows the readme.txt Upgrade Notice section to every
 * site admin during an update. A missing, stale, or malformed notice
 * is silent at the moment a real bug fix or migration step is being
 * installed.
 *
 * This integration test runs scripts/verify-upgrade-notice.php against
 * a series of synthetic readme.txt files (a well-formed fixture and a
 * number of mutated variants) and asserts the script's verdict for
 * each one. A regression that:
 *
 *   - removes the == Upgrade Notice == section,
 *   - silently accepts an empty notice body,
 *   - silently accepts an HTML tag inside a notice body,
 *   - silently accepts a Markdown link,
 *   - silently accepts a paragraph break,
 *   - silently accepts an entry for a version that no longer exists in
 *     the Changelog,
 *   - silently accepts a notice-less Stable tag,
 *
 * ...fails the suite immediately. The script is the single source of
 * truth and the test pins its behaviour.
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Upgrade_Notice_Test extends TestCase {

	private const SCRIPT_PATH = 'scripts/verify-upgrade-notice.php';

	/**
	 * Plugin root, one level above tests/.
	 */
	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Run the verifier against a synthetic readme.txt payload via
	 * a temp file. The verifier reads the real readme.txt from disk
	 * by default, so we run a tiny shim script that swaps the path.
	 *
	 * @param string $readme_payload Synthetic readme.txt contents.
	 * @return array{0:int,1:string} [exit_code, stdout_stderr]
	 */
	private function run_against( string $readme_payload ): array {
		$tmpdir = sys_get_temp_dir() . '/sscribe-upgrade-notice-' . bin2hex( random_bytes( 6 ) );
		if ( ! mkdir( $tmpdir, 0o700, true ) && ! is_dir( $tmpdir ) ) {
			$this->fail( 'Could not create temp dir for upgrade-notice test' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmpdir . '/readme.txt', $readme_payload );

		$script = self::plugin_root() . '/' . self::SCRIPT_PATH;
		$cmd    = sprintf(
			// Use a one-line PHP shim that overrides the readme path via an env var
			// before requiring the script.
			'cd %s && SSCRIBE_TEST_README=%s php -r %s',
			escapeshellarg( self::plugin_root() ),
			escapeshellarg( $tmpdir . '/readme.txt' ),
			escapeshellarg( 'require "scripts/verify-upgrade-notice.php";' )
		);

		// Our verifier reads $root_dir . '/readme.txt' unconditionally, so
		// we instead replace the readme in the real plugin root, run, restore.
		$real_path = self::plugin_root() . '/readme.txt';
		$backup    = file_get_contents( $real_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $real_path, $readme_payload );

		try {
			$descriptors = array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			);
			$process = proc_open( array( PHP_BINARY, $script ), $descriptors, $pipes );
			$this->assertIsResource( $process, 'verify-upgrade-notice.php must launch as a subprocess' );
			$stdout = (string) stream_get_contents( $pipes[1] );
			$stderr = (string) stream_get_contents( $pipes[2] );
			$code   = proc_close( $process );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $real_path, $backup );
			unset( $cmd );
		}

		return array( (int) $code, $stdout . $stderr );
	}

	/**
	 * A canonical well-formed readme.txt used as the positive fixture.
	 */
	private function well_formed_readme(): string {
		return "=== Plugin Name ===\n"
			. "Contributors: simplix\n"
			. "Tags: export\n"
			. "Requires at least: 6.0\n"
			. "Tested up to: 7.1\n"
			. "Stable tag: 2.0.0\n"
			. "Requires PHP: 8.2\n"
			. "License: GPL-2.0-or-later\n"
			. "\n"
			. "Short description.\n"
			. "\n"
			. "== Description ==\n"
			. "Body.\n"
			. "\n"
			. "== Changelog ==\n"
			. "\n"
			. "= 2.0.0 =\n"
			. "* Big release.\n"
			. "\n"
			. "= 1.9.0 =\n"
			. "* Earlier release.\n"
			. "\n"
			. "== Upgrade Notice ==\n"
			. "\n"
			. "= 2.0.0 =\n"
			. "Moves export data to private storage and fixes shared-host activation when the temp directory is root-owned but world-writable. Operators should review the new storage location before upgrading.\n";
	}

	public function test_well_formed_readme_passes(): void {
		list( $code, $output ) = $this->run_against( $this->well_formed_readme() );
		$this->assertSame(
			0,
			$code,
			"Well-formed readme.txt must pass the verifier. Output was:\n" . $output
		);
		$this->assertStringContainsString(
			'Upgrade Notice contract holds',
			$output
		);
	}

	public function test_missing_upgrade_notice_section_fails(): void {
		$readme = str_replace(
			array( "== Upgrade Notice ==\n", "= 2.0.0 =\nMoves export" ),
			array( '', '' ),
			$this->well_formed_readme()
		);
		list( $code, $output ) = $this->run_against( $readme );
		$this->assertSame( 1, $code, 'missing Upgrade Notice section must fail (exit 1)' );
		$this->assertStringContainsString( 'missing the `== Upgrade Notice ==`', $output );
	}

	public function test_empty_notice_body_fails(): void {
		$readme = str_replace(
			"Moves export data to private storage and fixes shared-host activation when the temp directory is root-owned but world-writable. Operators should review the new storage location before upgrading.\n",
			"Short.\n",
			$this->well_formed_readme()
		);
		list( $code, $output ) = $this->run_against( $readme );
		$this->assertSame( 1, $code, 'empty notice body must fail' );
		$this->assertStringContainsString( 'shorter than 50 chars', $output );
	}

	public function test_html_tag_in_notice_body_fails(): void {
		$readme = str_replace(
			'Moves export data to private storage and fixes shared-host activation when the temp directory is root-owned but world-writable. Operators should review the new storage location before upgrading.',
			'Moves export data to private storage with <strong>verified legacy migration</strong> and fixes shared-host activation when the temp directory is root-owned but world-writable. Operators should review before upgrading.',
			$this->well_formed_readme()
		);
		list( $code, $output ) = $this->run_against( $readme );
		$this->assertSame( 1, $code, 'HTML tag inside notice body must fail' );
		$this->assertStringContainsString( 'contains an HTML tag', $output );
	}

	public function test_markdown_link_in_notice_body_fails(): void {
		$readme = str_replace(
			'Moves export data to private storage and fixes shared-host activation when the temp directory is root-owned but world-writable. Operators should review the new storage location before upgrading.',
			'See [the changelog](https://example.org) for full details about the migration to private storage, the shared-host activation fix for world-writable temp directories, and required operator actions before upgrade.',
			$this->well_formed_readme()
		);
		list( $code, $output ) = $this->run_against( $readme );
		$this->assertSame( 1, $code, 'Markdown link inside notice body must fail' );
		$this->assertStringContainsString( 'Markdown link', $output );
	}

	public function test_paragraph_break_in_notice_body_fails(): void {
		$readme = str_replace(
			'Moves export data to private storage and fixes shared-host activation when the temp directory is root-owned but world-writable. Operators should review the new storage location before upgrading.',
			"Moves export data to private storage.\n\nFixes shared-host activation when the temp directory is root-owned but world-writable. Operators should review the new storage location before upgrading.",
			$this->well_formed_readme()
		);
		list( $code, $output ) = $this->run_against( $readme );
		$this->assertSame( 1, $code, 'paragraph break inside notice body must fail' );
		$this->assertStringContainsString( 'paragraph break', $output );
	}

	public function test_orphan_upgrade_notice_version_fails(): void {
		// Add a notice for 1.0.0 even though 1.0.0 is not in the Changelog.
		$readme = $this->well_formed_readme() . "\n= 1.0.0 =\nLegacy release with at least fifty characters of prose here so the body length check passes.\n";
		list( $code, $output ) = $this->run_against( $readme );
		$this->assertSame( 1, $code, 'orphan upgrade-notice version (not in Changelog) must fail' );
		$this->assertStringContainsString( 'is not present in the Changelog', $output );
	}

	public function test_stable_tag_without_upgrade_notice_fails(): void {
		// Bump the Stable tag to 2.0.1 — a version that has no entry
		// in the Upgrade Notice section.
		$readme = str_replace(
			"Stable tag: 2.0.0\n",
			"Stable tag: 2.0.1\n",
			$this->well_formed_readme()
		);
		list( $code, $output ) = $this->run_against( $readme );
		$this->assertSame( 1, $code, 'Stable tag with no matching upgrade-notice entry must fail' );
		$this->assertStringContainsString( 'no Upgrade Notice entry', $output );
	}

	public function test_live_readme_passes(): void {
		// Sanity check: the script is wired against the real readme.txt
		// on disk. If the live readme regresses (e.g. someone deletes
		// the upgrade-notice entry), this test fires immediately.
		$script = self::plugin_root() . '/' . self::SCRIPT_PATH;
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open( array( PHP_BINARY, $script ), $descriptors, $pipes );
		$this->assertIsResource( $process );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		$this->assertSame(
			0,
			$code,
			"Live readme.txt must satisfy the upgrade-notice contract. Output:\n" . $stdout . $stderr
		);
	}
}
