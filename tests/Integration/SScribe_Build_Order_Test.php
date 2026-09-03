<?php
/**
 * Phase 62 — Build-after-test vs test-after-build integration test.
 *
 * Pins the canonical pipeline order:
 *
 *   1. Tests (PHPUnit + every verifier) run FIRST.
 *   2. THEN the ZIP is built (composer release / build-release.php).
 *   3. THEN WordPress Plugin Check runs on the ZIP.
 *
 * A regression that breaks this order (e.g., a new `needs:` dep
 * added without listing `test`, or plugin-check-action moved
 * before build-release.php) fails this test BEFORE the release
 * tag is cut.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Build_Order_Test extends TestCase {

	private const VERIFIER_PATH = 'scripts/verify-build-order.php';
	private const MANIFEST_PATH = 'dist/build-order-manifest.json';
	private const CI_PATH       = '.github/workflows/ci.yml';
	private const RELEASE_PATH  = '.github/workflows/release.yml';
	private const AUDIT_SCRIPT  = 'bin/release-audit.sh';

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

	/**
	 * Extract a single job's body from a workflow YAML. We work on
	 * raw lines (not a YAML parser) so the assertion is decoupled
	 * from yaml/symfony deps. Returns every line from the matching
	 * `<anchor>:` header (with leading whitespace allowed) until
	 * the next sibling job header at the same indent, or EOF —
	 * whichever comes first.
	 */
	private static function extract_job_block( string $yaml, string $anchor ): ?string {
		$lines        = explode( "\n", $yaml );
		$start        = null;
		$start_indent = 0;
		foreach ( $lines as $i => $line ) {
			if ( false !== strpos( $line, $anchor ) && preg_match( '/^\s*' . preg_quote( rtrim( $anchor, ':' ), '/' ) . ':\s*$/', $line ) ) {
				$start        = $i;
				$start_indent = strspn( $line, ' ' );
				break;
			}
		}
		if ( null === $start ) {
			return null;
		}
		$end = count( $lines );
		for ( $i = $start + 1; $i < count( $lines ); $i++ ) {
			$line = $lines[ $i ];
			if ( '' === $line || 0 === strpos( ltrim( $line ), '#' ) ) {
				continue;
			}
			$indent = strspn( $line, ' ' );
			// Sibling job: same indent as anchor AND a `<word>:` follows.
			if ( $indent === $start_indent && preg_match( '/^[A-Za-z][A-Za-z0-9_-]*:\s/', ltrim( $line ) ) ) {
				$end = $i;
				break;
			}
			// A line that starts at column 0 is the next top-level
			// section (e.g. `on:`, `jobs:`). End of jobs block.
			if ( 0 === $indent && preg_match( '/^[A-Za-z]/', $line ) ) {
				$end = $i;
				break;
			}
		}
		return implode( "\n", array_slice( $lines, $start, $end - $start ) );
	}

	public function test_live_manifest_passes_verifier(): void {
		list( $code, $output ) = $this->run_verifier();
		$this::assertSame(
			0,
			$code,
			'Phase 62 build-order verifier must return exit 0. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'Build-order contract valid', $output );
	}

	public function test_manifest_records_all_rules_passing(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'] );
		$this::assertGreaterThanOrEqual( 7, $payload['rule_count'] );
		$this::assertSame( $payload['rule_count'], $payload['passed_count'] );
		$this::assertSame( 0, $payload['errors_count'] );
	}

	public function test_ci_plugin_check_depends_on_test(): void {
		$ci = (string) file_get_contents( self::plugin_root() . '/' . self::CI_PATH );
		// The plugin-check job must list `test` in its `needs:`.
		$this::assertMatchesRegularExpression(
			'/plugin-check:\s*\n(?:\s+name:\s+[^\n]+\n|\s+runs-on:\s+[^\n]+\n)+\s+needs:\s+(?:\[[^\]]*\btest\b[^\]]*\]|\btest\b)/',
			$ci,
			'.github/workflows/ci.yml plugin-check job must depend on `test`.'
		);
	}

	public function test_ci_plugin_check_build_before_plugin_check(): void {
		$ci = (string) file_get_contents( self::plugin_root() . '/' . self::CI_PATH );
		// Scope to the plugin-check job (the Submission Package Check).
		// The version-check job also runs build-release.php earlier in
		// the file (for its own ZIP-cert pipeline), so a whole-file
		// substring check is wrong — we MUST scope to the plugin-check
		// job's `steps:` block.
		$job = self::extract_job_block( $ci, 'plugin-check:' );
		$this::assertNotNull( $job, 'plugin-check job block not found in ci.yml.' );
		// Build step (composer release / build-release.php) MUST
		// appear before wordpress/plugin-check-action in the plugin-
		// check job's steps.
		$build_pos  = strpos( $job, 'build-release.php' );
		$check_pos  = strpos( $job, 'wordpress/plugin-check-action' );
		$this::assertNotFalse( $build_pos, 'plugin-check job must reference build-release.php.' );
		$this::assertNotFalse( $check_pos, 'plugin-check job must reference wordpress/plugin-check-action.' );
		$this::assertLessThan(
			$check_pos,
			$build_pos,
			'plugin-check job must run build-release.php BEFORE wordpress/plugin-check-action.'
		);
	}

	public function test_release_certify_depends_on_test(): void {
		$release = (string) file_get_contents( self::plugin_root() . '/' . self::RELEASE_PATH );
		$this::assertMatchesRegularExpression(
			'/\bcertify:\s*\n(?:\s+[a-z-]+:\s+[^\n]+\n)*\s+needs:\s+(?:\[[^\]]*\btest\b[^\]]*\]|\btest\b)/',
			$release,
			'.github/workflows/release.yml certify job must list `test` in its `needs:`.'
		);
	}

	public function test_release_certify_build_before_plugin_check(): void {
		$release = (string) file_get_contents( self::plugin_root() . '/' . self::RELEASE_PATH );
		// Scope to the certify job. The release.yml header comments
		// also reference build-release.php, so a whole-file substring
		// check is wrong.
		$job = self::extract_job_block( $release, 'certify:' );
		$this::assertNotNull( $job, 'certify job block not found in release.yml.' );
		$build_pos  = strpos( $job, 'build-release.php' );
		$check_pos  = strpos( $job, 'wordpress/plugin-check-action' );
		$this::assertNotFalse( $build_pos, 'certify job must reference build-release.php.' );
		$this::assertNotFalse( $check_pos, 'certify job must reference plugin-check-action.' );
		$this::assertLessThan(
			$check_pos,
			$build_pos,
			'certify job must run build-release.php BEFORE plugin-check-action.'
		);
	}

	public function test_release_audit_runs_phpunit(): void {
		$audit = (string) file_get_contents( self::plugin_root() . '/' . self::AUDIT_SCRIPT );
		$this::assertMatchesRegularExpression(
			'/(^|\s)(vendor\/bin\/phpunit|composer test)(\s|$)/m',
			$audit,
			'bin/release-audit.sh must run PHPUnit (or composer test) as part of its gate.'
		);
	}

	public function test_release_audit_does_not_build(): void {
		$audit = (string) file_get_contents( self::plugin_root() . '/' . self::AUDIT_SCRIPT );
		// Strip comments to avoid false positives from docstrings.
		$stripped = preg_replace( '/^\s*#[^\n]*/m', '', $audit ) ?? '';
		$this::assertStringNotContainsString(
			'build-release.php',
			$stripped,
			'bin/release-audit.sh MUST NOT run build-release.php — that\'s the ci.yml plugin-check job\'s responsibility.'
		);
		$this::assertDoesNotMatchRegularExpression(
			'/composer\s+release\b(?!:audit)/',
			$stripped,
			'bin/release-audit.sh MUST NOT call composer release — that\'s the ci.yml plugin-check job\'s responsibility.'
		);
	}

	public function test_plugin_check_job_is_submission_package_check(): void {
		$ci = (string) file_get_contents( self::plugin_root() . '/' . self::CI_PATH );
		$this::assertMatchesRegularExpression(
			'/plugin-check:\s*\n\s*name:\s+Submission Package Check/',
			$ci,
			'.github/workflows/ci.yml plugin-check job must be named `Submission Package Check`.'
		);
	}
}
