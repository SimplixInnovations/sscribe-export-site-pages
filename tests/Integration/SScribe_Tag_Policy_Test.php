<?php
/**
 * Phase 54 — Tag policy integration test (refactored).
 *
 * Pin the rule that a release tag is cut ONLY when:
 *
 *   - the tag's version matches SSCRIBE_VERSION
 *   - the tag points at the certified source SHA recorded in the
 *     build evidence
 *   - the tag SHA equals origin/main HEAD (the commit just past
 *     ci.yml)
 *   - the certified release evidence (build.json) records
 *     source_sha, tag, and zip_sha256
 *   - `gh release create` uses `--verify-tag` so publication
 *     aborts when the remote tag does not exist
 *   - v2.0.3+ release tags are annotated tag objects
 *
 * Cryptographic signing (`git tag -s`) is recommended when the
 * maintainer has signing configured, NOT required. WP.org submission
 * does not require it. The verifier records signing status as
 * advisory.
 *
 * A regression that drops the verify job or lets CI cut tags
 * silently lets a stale or unsigned release ship.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Tag_Policy_Test extends TestCase {

	private const VERIFIER_PATH   = 'scripts/verify-tag-policy.php';
	private const MANIFEST_PATH   = 'dist/tag-policy-manifest.json';
	private const RELEASE_WORKFLOW = '.github/workflows/release.yml';
	private const POLICY_DOC      = 'docs/TAG_POLICY_v2.0.0.md';
	private const PLUGIN_FILE     = 'sscribe-export-site-pages.php';
	private const RELEASE_COMMIT   = 'scripts/release-commit.php';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	private function run_verifier(): array {
		$root        = self::plugin_root();
		$descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
		$process     = proc_open( array( PHP_BINARY, $root . '/' . self::VERIFIER_PATH ), $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
			throw new \RuntimeException( 'Could not spawn verifier subprocess.' );
		}
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		return array( (int) $code, $stdout . $stderr );
	}

	public function test_live_tree_passes_tag_policy_verifier(): void {
		list( $code, $output ) = $this->run_verifier();
		$this::assertSame(
			0,
			$code,
			'Live release.yml must satisfy the Phase 54 tag-policy contract. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'Tag policy contract valid', $output );
	}

	public function test_manifest_records_at_least_sixteen_passing_rules(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'] );
		// The refactored verifier declares ≥ 16 rules (8 canonical +
		// workflow constraints + crypto advisory + SHA-binding clarifications).
		$this::assertGreaterThanOrEqual( 16, $payload['rule_count'] );
		$this::assertSame( $payload['rule_count'], $payload['passed_count'] );
		$this::assertSame( 0, $payload['errors_count'] );
	}

	public function test_release_yml_does_not_cut_tags_in_ci(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::RELEASE_WORKFLOW );
		$this::assertDoesNotMatchRegularExpression(
			'/^\s*(?:-\s*)?run:.*\bgit\s+tag\b/m',
			$source,
			'CI must not cut tags. Releasing is a deliberate maintainer action.'
		);
	}

	public function test_release_yml_uses_verify_tag_on_release_create(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::RELEASE_WORKFLOW );
		$this::assertMatchesRegularExpression(
			'/\bgh release create\b/',
			$source,
			'release.yml must invoke `gh release create` for first-publish.'
		);
		$this::assertMatchesRegularExpression(
			'/--verify-tag\b/',
			$source,
			'`gh release create` must use `--verify-tag` so publication aborts if the remote tag does not exist.'
		);
	}

	public function test_release_yml_does_not_force_move_tags(): void {
		// Rule 6: a release tag, once cut, must not be re-cut. The
		// workflow must not pass --force / --force-with-lease to
		// `git tag`.
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::RELEASE_WORKFLOW );
		$this::assertDoesNotMatchRegularExpression(
			'/git\s+tag[^\n]*--force(?:-with-lease)?/i',
			$source,
			'release.yml must not pass `--force` to `git tag` (rule 6: no tag re-cut).'
		);
	}

	public function test_build_evidence_records_source_sha_tag_and_zip_sha256(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::RELEASE_WORKFLOW );
		$this::assertMatchesRegularExpression(
			'/"source_sha"\s*:\s*"\$\{SOURCE_SHA\}"/',
			$source,
			'build.json evidence must record source_sha (rule 4: certified source SHA).'
		);
		$this::assertMatchesRegularExpression(
			'/"tag"\s*:\s*"\$\{REF_NAME\}"/',
			$source,
			'build.json evidence must record tag (REF_NAME).'
		);
		$this::assertMatchesRegularExpression(
			'/"zip_sha256"\s*:\s*"\$\{ZIP_SHA\}"/',
			$source,
			'build.json evidence must record zip_sha256.'
		);
		$this::assertMatchesRegularExpression(
			"/id:[[:space:]]+zip-sha/",
			$source,
			'certify must declare `id: zip-sha` step that captures the SHA.'
		);
		$this::assertMatchesRegularExpression(
			'/awk.*print.*\$1/',
			$source,
			'certify must extract the ZIP SHA-256 via `awk \'{print $1}\'`.'
		);
	}

	public function test_release_evidence_is_uploaded_and_downloaded_across_jobs(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::RELEASE_WORKFLOW );
		$this::assertMatchesRegularExpression(
			'#path:\s*dist/sscribe-export-site-pages-[^[:space:]\n]*\.build\.json#',
			$source,
			'certify must declare `path:` glob for build.json evidence (e.g. `dist/sscribe-export-site-pages-*.build.json`).'
		);
		$this::assertStringContainsString( 'sscribe-release-build-json', $source );
	}

	public function test_release_workflow_still_triggers_on_v_tag_only(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::RELEASE_WORKFLOW );
		$this::assertMatchesRegularExpression(
			'/^on:\s*\n\s+push:\s*\n\s+tags:\s*\n\s+-\s*[\'"]?v\*[\'"]?\s*$/m',
			$source,
			'release.yml must trigger on `v*` tags only.'
		);
	}

	public function test_policy_doc_declares_eight_canonical_rules(): void {
		$policy = (string) file_get_contents( self::plugin_root() . '/' . self::POLICY_DOC );
		for ( $i = 1; $i <= 8; $i++ ) {
			$this::assertMatchesRegularExpression(
				"/\\|\\s*{$i}\\s*\\|/",
				$policy,
				"docs/TAG_POLICY_v2.0.0.md must declare rule #{$i} in its canonical rules table."
			);
		}
	}

	public function test_policy_doc_describes_verify_tag_accurately(): void {
		$policy = (string) file_get_contents( self::plugin_root() . '/' . self::POLICY_DOC );
		$this::assertStringContainsString( '--verify-tag', $policy );
		$this::assertStringContainsString( 'remote tag existence', $policy );
		$this::assertStringNotContainsString( 'signed-tag store', $policy );
		$this::assertMatchesRegularExpression(
			'/cryptographic.*recommend|recommend.*cryptographic|signing.*recommend|recommend.*signing/si',
			$policy,
			'docs/TAG_POLICY_v2.0.0.md must state that cryptographic tag signing is recommended (not required).'
		);
	}

	public function test_tag_helper_requires_strict_exact_release_certification_before_git_tag(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::RELEASE_COMMIT );
		$this::assertStringContainsString( "putenv( 'SSCRIBE_RELEASE_CERTIFICATION=1' )", $source );

		$tag_position = strpos( $source, "git tag -a" );
		$this::assertNotFalse( $tag_position, 'Tag helper must create annotated release tags.' );

		$required_gates = array(
			'scripts/verify-manual-runtime-tests.php',
			'scripts/verify-release-blockers.php',
			'scripts/verify-final-ci-state.php',
			'scripts/verify-exact-artifact-evidence.php',
			'scripts/verify-agent-final-report.php',
			'scripts/verify-auditor-handoff.php',
			'scripts/release-audit.php',
		);
		foreach ( $required_gates as $gate ) {
			$gate_position = strpos( $source, $gate );
			$this::assertNotFalse( $gate_position, "Tag helper must execute strict gate {$gate}." );
			$this::assertLessThan( $tag_position, $gate_position, "Strict gate {$gate} must execute before tag creation." );
		}
		$this::assertStringContainsString( 'Strict release certification failed', $source );
	}

	public function test_verifier_enforces_annotated_tags_for_v2_0_3_and_later(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::VERIFIER_PATH );
		$this::assertStringContainsString( 'git cat-file -t', $source );
		$this::assertStringContainsString( 'release_tag_is_annotated_from_v2_0_3_onward', $source );
		$this::assertStringContainsString( "version_compare( \$canonical_version, '2.0.3', '>=' )", $source );
	}
}
