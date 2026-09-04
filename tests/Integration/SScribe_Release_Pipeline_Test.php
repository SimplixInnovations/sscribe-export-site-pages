<?php
/**
 * Phase 53 — Release workflow architecture integration test.
 *
 * Pin the contract that the release workflow is split into a
 * `certify` job (build + plugin-check + artifact upload) and a
 * `publish` job (download certified artifact + SHA verify + gh
 * release upload). The publish job MUST NOT run composer install,
 * vendor:prefix, or build-release.php — that would be the second
 * uncontrolled build Phase 53 forbids.
 *
 * A regression that collapses these into a single job (or that
 * adds a second `composer install` to publish) silently lets the
 * gate bypass the certified artifact. The verifier + this test
 * lock the gate at two boundaries.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Release_Pipeline_Test extends TestCase {

	private const VERIFIER_PATH  = 'scripts/verify-release-pipeline.php';
	private const MANIFEST_PATH  = 'dist/release-pipeline-manifest.json';
	private const RELEASE_WORKFLOW = '.github/workflows/release.yml';

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

	public function test_live_tree_passes_release_pipeline_verifier(): void {
		list( $code, $output ) = $this->run_verifier();
		$this::assertSame(
			0,
			$code,
			'Live release.yml must satisfy the Phase 53 release-pipeline contract. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'Release pipeline architecture contract valid', $output );
	}

	public function test_manifest_records_seventeen_passing_rules(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'] );
		$this::assertGreaterThanOrEqual( 17, $payload['rule_count'] );
		$this::assertSame( $payload['rule_count'], $payload['passed_count'] );
		$this::assertSame( 0, $payload['errors_count'] );
	}

	public function test_release_yml_declares_certify_and_publish_jobs(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::RELEASE_WORKFLOW );
		// Strip YAML comments to avoid matching documentation that
		// describes the contract rather than implments it.
		$no_comments = (string) preg_replace( '/^\s*#[^\n]*$/m', '', $source );

		$this::assertMatchesRegularExpression(
			'/^    certify:\s*\n/m',
			$no_comments,
			'release.yml must declare a top-level `certify` job.'
		);
		$this::assertMatchesRegularExpression(
			'/^    publish:\s*\n/m',
			$no_comments,
			'release.yml must declare a top-level `publish` job.'
		);
	}

	public function test_publish_job_must_not_run_build_steps(): void {
		// Phase 53: "Do not run a second uncontrolled build after
		// approval." The publish job's body must contain no
		// `composer install`, `composer vendor:prefix`, or `build-release.php`.
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::RELEASE_WORKFLOW );
		$no_comments = (string) preg_replace( '/^\s*#[^\n]*$/m', '', $source );

		// Carve out the publish job block: from `    publish:` to
		// the next top-level `    <job>:` line or end of file.
		preg_match( '/^    publish:\s*\n(.*?)(?=^    [a-z][a-z0-9_-]*:\s*\n|\z)/sm', $no_comments, $m );
		$this::assertNotEmpty( $m, 'release.yml publish block not found.' );
		$publish_body = $m[1];

		$this::assertDoesNotMatchRegularExpression(
			'/\bcomposer install\b/',
			$publish_body,
			'publish job must NOT run `composer install` — that would be the second uncontrolled build.'
		);
		$this::assertDoesNotMatchRegularExpression(
			'/\bcomposer vendor:prefix\b/',
			$publish_body,
			'publish job must NOT run `composer vendor:prefix` — certify owns this.'
		);
		$this::assertDoesNotMatchRegularExpression(
			'/\bphp scripts\/build-release\.php\b/',
			$publish_body,
			'publish job must NOT run `build-release.php` — certify owns this.'
		);
	}

	public function test_publish_verifies_sha_before_uploading_to_github_release(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::RELEASE_WORKFLOW );
		$no_comments = (string) preg_replace( '/^\s*#[^\n]*$/m', '', $source );

		preg_match( '/^    publish:\s*\n(.*?)(?=^    [a-z][a-z0-9_-]*:\s*\n|\z)/sm', $no_comments, $m );
		$publish_body = $m[1];

		$this::assertMatchesRegularExpression(
			'/\bactions\/download-artifact@[0-9a-f]{40}\b/i',
			$publish_body,
			'publish job must consume the certified artifact via `actions/download-artifact`.'
		);
		$this::assertMatchesRegularExpression(
			'/\bsha256sum\b/',
			$publish_body,
			'publish job must compute sha256sum of the downloaded ZIP.'
		);
		// Expect both EXPECTED_SHA and ACTUAL_SHA variables to appear
		// AND an explicit `if [ "${EXPECTED_SHA}" != "${ACTUAL_SHA}" ]`
		// comparison that branches to `exit 1` before any later publish
		// step. We allow whitespace and any quote style inside the
		// comparison.
		$this::assertStringContainsString( 'EXPECTED_SHA', $publish_body );
		$this::assertStringContainsString( 'ACTUAL_SHA', $publish_body );
		$this::assertStringContainsString( 'LOCAL_SHA', $publish_body );
		$this::assertMatchesRegularExpression(
			'/EXPECTED_SHA.+ACTUAL_SHA.*\n[^#\n]*exit\s+1/s',
			$publish_body,
			'publish job must compare EXPECTED_SHA against ACTUAL_SHA and `exit 1` on mismatch.'
		);
		// sha mismatch must reject (not silent).
		$this::assertMatchesRegularExpression(
			'/SHA-?256 mismatch/i',
			$publish_body,
			'publish job must print "SHA-256 mismatch" on a checksum failure so the failure is observable.'
		);
		$this::assertMatchesRegularExpression(
			'/\bgh release (?:upload|create)\b/',
			$publish_body,
			'publish job must upload the verified bytes via `gh release upload` or `gh release create`.'
		);
	}

	public function test_release_workflow_trigger_is_tag_only(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::RELEASE_WORKFLOW );
		$this::assertMatchesRegularExpression(
			'/^on:\s*\n\s+push:\s*\n\s+tags:\s*\n\s+-\s*[\'"]?v\*[\'"]?\s*$/m',
			$source,
			'release.yml must trigger on `push: tags: - v*` only — branch pushes must not publish a release.'
		);
	}
}
