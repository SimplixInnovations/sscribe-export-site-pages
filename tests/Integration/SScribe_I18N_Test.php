<?php
/**
 * Phase 42 — i18n contract integration test.
 *
 * The i18n contract has four parts:
 *
 *   1. Plugin mainfile declares the canonical Text Domain +
 *      Domain Path.
 *   2. Every translation call uses the canonical text domain as
 *      its domain argument.
 *   3. The POT file declares X-Domain and Project-Id-Version that
 *      match the canonical domain and the plugin version.
 *   4. Every translatable msgid extracted from the source appears
 *      in the POT.
 *
 * The verifier (`scripts/verify-i18n.php`) enforces all four. This
 * test class exercises the verifier against the live plugin tree
 * and against planted mutations so the gate is locked without
 * depending on the developer's local `wp i18n make-pot` invocation
 * producing anything new.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_I18N_Test extends TestCase {

	private const SCRIPT_PATH   = 'scripts/verify-i18n.php';
	private const MANIFEST_PATH = 'dist/i18n-manifest.json';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * @return array{0:int,1:string}
	 */
	private function run_verifier(): array {
		$root = self::plugin_root();
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
	}

	public function test_live_tree_passes_audit(): void {
		list( $code, $output ) = $this->run_verifier();
		$this::assertSame(
			0,
			$code,
			'Live plugin tree must satisfy the i18n contract. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'i18n contract audit passed', $output );
	}

	public function test_manifest_records_canonical_domain_and_version(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$abs     = self::plugin_root() . '/' . self::MANIFEST_PATH;
		$payload = json_decode( (string) file_get_contents( $abs ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'] );
		$this::assertSame( 'sscribe-export-site-pages', $payload['canonical_text_domain'] );
		$this::assertSame( 'sscribe-export-site-pages', $payload['header']['text_domain'] );
		$this::assertSame( '/languages', $payload['header']['domain_path'] );

		$mainfile = (string) file_get_contents( self::plugin_root() . '/sscribe-export-site-pages.php' );
		$this::assertMatchesRegularExpression(
			"/define\\s*\\(\\s*['\"]SSCRIBE_VERSION['\"]\\s*,\\s*['\"]([0-9]+\\.[0-9]+\\.[0-9]+)['\"]/",
			$mainfile
		);
		preg_match(
			"/define\\s*\\(\\s*['\"]SSCRIBE_VERSION['\"]\\s*,\\s*['\"]([0-9]+\\.[0-9]+\\.[0-9]+)['\"]/",
			$mainfile,
			$version_match
		);
		$this::assertSame( $version_match[1], $payload['header']['version'] );

		// Every translation call the verifier observed must have
		// declared the canonical domain — the verifier would have
		// flagged any deviation under `wrong_domain_calls`.
		$this::assertSame( 0, $payload['wrong_domain_calls'] );
		$this::assertSame( $payload['translation_calls_total'], $payload['translation_calls_with_domain'] );

		// POT must contain at least as many msgids as the source
		// has msgids (potentially more because some are listed in
		// context headers or header banners).
		$this::assertGreaterThanOrEqual(
			$payload['source_msgids_checked'],
			$payload['pot_msgid_count']
		);
		$this::assertSame( 0, $payload['source_msgids_missing_in_pot'] );
	}

	public function test_wrong_domain_in_source_fails(): void {
		// Plant a fresh file under admin/ that uses the i18n call
		// with the WRONG text domain. The verifier must flag it.
		$root        = self::plugin_root();
		$marker_dir  = $root . '/admin/__phase42_i18n_marker';
		$marker_path = $marker_dir . '/class-sscribe-wrong-domain.php';
		$original_existed = is_dir( $marker_dir );

		mkdir( $marker_dir, 0755, true );
		file_put_contents(
			$marker_path,
			"<?php\n// Phase 42 marker. Removed by the test.\n"
			. "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
			. "function sscribe_phase42_wrong_domain_misuse() {\n"
			. "    return __( 'This string uses the wrong domain intentionally.', 'wrong-domain-name' );\n"
			. "}\n"
		);

		try {
			list( $code, $output ) = $this->run_verifier();
			$this::assertSame(
				1,
				$code,
				'Wrong-domain i18n call must fail the audit. Output:' . "\n" . $output
			);
			$this::assertStringContainsString( 'wrong-domain-name', $output );
			$this::assertStringContainsString( '__phase42_i18n_marker', $output );
		} finally {
			@unlink( $marker_path );
			@rmdir( $marker_dir );
			$this::assertDirectoryDoesNotExist( $marker_dir );
			$this::assertFalse( $original_existed && is_dir( $marker_dir ), 'Defensive cleanup failed' );
		}
	}

	public function test_missing_text_domain_in_source_fails(): void {
		// i18n call with NO text domain argument. Single-arg
		// __() is a release blocker per the WP.org plugin-check
		// rules.
		$root        = self::plugin_root();
		$marker_dir  = $root . '/admin/__phase42_i18n_missing';
		$marker_path = $marker_dir . '/class-sscribe-no-domain.php';

		mkdir( $marker_dir, 0755, true );
		file_put_contents(
			$marker_path,
			"<?php\n// Phase 42 marker. Removed by the test.\n"
			. "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
			. "function sscribe_phase42_missing_domain_misuse() {\n"
			. "    return __( 'String with no domain argument at all.' );\n"
			. "}\n"
		);

		try {
			list( $code, $output ) = $this->run_verifier();
			$this::assertSame(
				1,
				$code,
				'i18n call without a text domain must fail the audit. Output:' . "\n" . $output
			);
			$this::assertStringContainsString( '__phase42_i18n_missing', $output );
		} finally {
			@unlink( $marker_path );
			@rmdir( $marker_dir );
		}
	}

	public function test_stale_pot_with_wrong_xdomain_fails(): void {
		// Snapshot the live POT, plant a stale one with a wrong
		// X-Domain, expect the verifier to flag the divergence.
		$root          = self::plugin_root();
		$pot_path      = $root . '/languages/sscribe-export-site-pages.pot';
		$backup_path   = $pot_path . '.phase42-snapshot';
		$original_had  = is_file( $pot_path );

		if ( $original_had ) {
			copy( $pot_path, $backup_path );
		}
		$stale_pot = "# Copyright (C) 2026 Simplix Innovations\n"
			. "# This file is distributed under the GPL-2.0-or-later.\n"
			. "msgid \"\"\n"
			. "msgstr \"\"\n"
			. "\"Project-Id-Version: SScribe Export Site Pages 2.0.0\\n\"\n"
			. "\"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/sscribe-export-site-pages\\n\"\n"
			. "\"POT-Creation-Date: 2026-09-03T03:00:00+00:00\\n\"\n"
			. "\"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n\"\n"
			. "\"MIME-Version: 1.0\\n\"\n"
			. "\"Content-Type: text/plain; charset=UTF-8\\n\"\n"
			. "\"Content-Transfer-Encoding: 8bit\\n\"\n"
			. "\"X-Domain: wrong-text-domain\\n\"\n";
		file_put_contents( $pot_path, $stale_pot );

		try {
			list( $code, $output ) = $this->run_verifier();
			$this::assertSame(
				1,
				$code,
				'POT with wrong X-Domain must fail the audit. Output:' . "\n" . $output
			);
			$this::assertStringContainsString( 'wrong-text-domain', $output );
		} finally {
			if ( $original_had && is_file( $backup_path ) ) {
				copy( $backup_path, $pot_path );
				@unlink( $backup_path );
			} elseif ( is_file( $pot_path ) ) {
				@unlink( $pot_path );
			}
		}
	}

	public function test_stale_pot_with_wrong_version_fails(): void {
		$root          = self::plugin_root();
		$pot_path      = $root . '/languages/sscribe-export-site-pages.pot';
		$backup_path   = $pot_path . '.phase42-snapshot-version';
		$original_had  = is_file( $pot_path );

		if ( $original_had ) {
			copy( $pot_path, $backup_path );
		}
		$stale_pot = "# Copyright (C) 2026 Simplix Innovations\n"
			. "# This file is distributed under the GPL-2.0-or-later.\n"
			. "msgid \"\"\n"
			. "msgstr \"\"\n"
			. "\"Project-Id-Version: SScribe Export Site Pages 1.2.3\\n\"\n"
			. "\"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/sscribe-export-site-pages\\n\"\n"
			. "\"POT-Creation-Date: 2026-09-03T03:00:00+00:00\\n\"\n"
			. "\"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n\"\n"
			. "\"MIME-Version: 1.0\\n\"\n"
			. "\"Content-Type: text/plain; charset=UTF-8\\n\"\n"
			. "\"Content-Transfer-Encoding: 8bit\\n\"\n"
			. "\"X-Domain: sscribe-export-site-pages\\n\"\n";
		file_put_contents( $pot_path, $stale_pot );

		try {
			list( $code, $output ) = $this->run_verifier();
			$this::assertSame(
				1,
				$code,
				'POT with wrong Project-Id-Version must fail the audit. Output:' . "\n" . $output
			);
			$this::assertStringContainsString( '1.2.3', $output );
		} finally {
			if ( $original_had && is_file( $backup_path ) ) {
				copy( $backup_path, $pot_path );
				@unlink( $backup_path );
			} elseif ( is_file( $pot_path ) ) {
				@unlink( $pot_path );
			}
		}
	}

	public function test_missing_pot_file_fails(): void {
		$root          = self::plugin_root();
		$pot_path      = $root . '/languages/sscribe-export-site-pages.pot';
		$backup_path   = $pot_path . '.phase42-snapshot-missing';
		$original_had  = is_file( $pot_path );

		if ( $original_had ) {
			copy( $pot_path, $backup_path );
		}
		if ( is_file( $pot_path ) ) {
			@unlink( $pot_path );
		}

		try {
			list( $code, $output ) = $this->run_verifier();
			$this::assertSame(
				1,
				$code,
				'Missing POT file must fail the audit. Output:' . "\n" . $output
			);
			$this::assertStringContainsString( 'POT file missing', $output );
		} finally {
			if ( $original_had && is_file( $backup_path ) ) {
				copy( $backup_path, $pot_path );
				@unlink( $backup_path );
			}
		}
	}


	public function test_pot_excludes_test_and_fixture_trees(): void {
		$root = self::plugin_root();
		$pot  = (string) file_get_contents( $root . '/languages/sscribe-export-site-pages.pot' );

		$this::assertStringNotContainsString( '#: tests-wp/', $pot );
		$this::assertStringNotContainsString( '#: tests-e2e/', $pot );
		$this::assertStringNotContainsString( '#: stubs/', $pot );
	}

	public function test_make_pot_excludes_nonproduction_trees(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/scripts/make-pot.php' );

		$this::assertStringContainsString( 'tests-wp', $source );
		$this::assertStringContainsString( 'tests-e2e', $source );
		$this::assertStringContainsString( 'stubs', $source );
	}

	public function test_mainfile_header_constants(): void {
		// The plugin mainfile MUST declare the canonical Text
		// Domain and Domain Path headers, exactly as the WP.org
		// plugin-check requires.
		$root   = self::plugin_root();
		$header = (string) file_get_contents( $root . '/sscribe-export-site-pages.php' );
		$this::assertMatchesRegularExpression(
			'/^[ \t\/*]*Text Domain:\s*sscribe-export-site-pages\s*$/m',
			$header
		);
		$this::assertMatchesRegularExpression(
			'/^[ \t\/*]*Domain Path:\s*\/languages\s*$/m',
			$header
		);
	}
}