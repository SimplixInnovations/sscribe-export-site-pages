<?php
/**
 * Phase 54 — Tag policy integration test.
 *
 * Pin the rule that a release tag is cut ONLY when:
 *
 *   - the tag's version matches SSCRIBE_VERSION
 *   - the tag SHA equals origin/main HEAD (the commit just past ci.yml)
 *   - the certified release evidence (build.json) records
 *     source_sha, tag, and zip_sha256
 *   - `gh release create` uses `--verify-tag` (SHA-binding)
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
	private const PLUGIN_FILE     = 'sscribe-export-site-pages.php';

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

	public function test_manifest_records_eleven_passing_rules(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'] );
		$this::assertGreaterThanOrEqual( 11, $payload['rule_count'] );
		$this::assertSame( $payload['rule_count'], $payload['passed_count'] );
		$this::assertSame( 0, $payload['errors_count'] );
	}

	public function test_release_yml_does_not_cut_tags_in_ci(): void {
		// CI MUST NOT cut tags. A regression that adds `git tag` or
		// `gh release create` makes CI the cutting action, which
		// bypasses Phase 55 branch protection.
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::RELEASE_WORKFLOW );
		$this::assertDoesNotMatchRegularExpression(
			'/^\s*(?:-\s*)?run:.*\bgit\s+tag\b/m',
			$source,
			'CI must not cut tags. Releasing is a deliberate maintainer action.'
		);
	}

	public function test_release_yml_uses_verify_tag_on_release_create(): void {
		// `--verify-tag` makes the GitHub Release the SHA-binding
		// signature. The absence of this flag lets an attacker who
		// controls the tag ship a release that points at a moved
		// tag, not the SHA the maintainer intended.
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::RELEASE_WORKFLOW );
		$this::assertMatchesRegularExpression(
			'/\bgh release create\b/',
			$source,
			'release.yml must invoke `gh release create` for first-publish.'
		);
		$this::assertMatchesRegularExpression(
			'/--verify-tag\b/',
			$source,
			'`gh release create` must use `--verify-tag` to bind the release to a signed tag.'
		);
	}

	public function test_build_evidence_records_source_sha_tag_and_zip_sha256(): void {
		$source = (string) file_get_contents( self::plugin_root() . '/' . self::RELEASE_WORKFLOW );
		// The build.json evidence template must declare all three keys.
		$this::assertMatchesRegularExpression(
			'/"source_sha"\s*:\s*"\$\{SOURCE_SHA\}"/',
			$source,
			'build.json evidence must record source_sha.'
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
		// The zip_sha256 must come from extracting the SHA sidecar via
		// `awk '{print $1}'` so the field is not a hardcoded empty
		// string.
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
		// certify uploads build.json artifact, publish downloads it.
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
}
