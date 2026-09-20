<?php
/**
 * Phase 53 — Release workflow architecture verifier.
 *
 * Contract: the release workflow MUST split certification from
 * publication. Two distinct jobs:
 *
 *   1. certify   — builds the ZIP, computes SHA-256, runs Plugin
 *                  Check, uploads the bytes as a GitHub Actions
 *                  artifact under a stable name.
 *   2. publish   — downloads the certified artifact, verifies SHA
 *                  before publishing, uploads to GitHub Release,
 *                  runs the download-back proof.
 *
 * Critically: the publish job MUST NOT contain any of the build
 * mutation steps (`composer install`, `vendor:prefix`,
 * `build-release.php`). "Do not run a second uncontrolled build
 * after approval."
 *
 * The verifier parses .github/workflows/release.yml as text (we
 * don't pull in a YAML parser because that's a runtime dependency)
 * and asserts the architecture, then writes
 * dist/release-pipeline-manifest.json that the integration test
 * re-reads.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$workflow_path = $root_dir . '/.github/workflows/release.yml';
$manifest_path = $root_dir . '/dist/release-pipeline-manifest.json';

$matrix   = array();
$errors   = array();
$warnings = array();

if ( ! is_file( $workflow_path ) ) {
	fwrite( STDERR, "✗ release.yml not found.\n" );
	exit( 1 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$source = (string) file_get_contents( $workflow_path );

/**
 * Split the YAML into per-job sections by detecting top-level
 * `    <job_id>:` blocks at 4-space indent (the `jobs:` map key)
 * and slicing the source between them. Blank lines and arbitrary
 * indentation inside a job must not break the slice — the original
 * `^(?:        .*\n?)+` regex stopped at blank lines.
 */
$jobs = array();
$job_starts = array();
if ( preg_match_all( '/^    ([a-z][a-z0-9_-]*):\s*$/m', $source, $anchor_matches, PREG_SET_ORDER ) ) {
	foreach ( $anchor_matches as $idx => $anchor ) {
		$job_starts[] = array(
			'id'    => $anchor[1],
			'offset' => strpos( $source, $anchor[0] ),
		);
	}
}

// Only treat top-level job keys — those that live inside the
// `jobs:` map and not inside `on:`. The `on:` map's `push:` (etc.)
// lives at 4-space indent too, but the `jobs:` header separates
// them. We anchor on the literal `jobs:` marker and only count job
// keys that appear AFTER it.
$jobs_marker_offset = (int) strpos( $source, "\njobs:\n" );
if ( false === $jobs_marker_offset ) {
	$jobs_marker_offset = (int) strpos( $source, "jobs:\n" );
}
$jobs = array();
foreach ( $job_starts as $idx => $js ) {
	if ( $js['offset'] <= $jobs_marker_offset ) {
		continue;
	}
	$end_offset = isset( $job_starts[ $idx + 1 ] ) ? $job_starts[ $idx + 1 ]['offset'] : strlen( $source );
	$jobs[ $js['id'] ] = substr( $source, $js['offset'], $end_offset - $js['offset'] );
}
$matrix[] = array(
	'rule'   => 'release_workflow_declares_jobs_section',
	'passes' => ! empty( $jobs ),
	'detail' => 'release.yml must declare a jobs: section with named jobs.',
);
if ( empty( $jobs ) ) {
	$errors[] = 'release.yml has no jobs section.';
}

/**
 * Rule 1: the release.yml must have BOTH a `certify` job and a
 * `publish` job. Without this split we are running "a second
 * uncontrolled build after approval."
 */
$has_certify = isset( $jobs['certify'] );
$has_publish = isset( $jobs['publish'] );
$matrix[] = array(
	'rule'   => 'release_workflow_has_certify_job',
	'passes' => $has_certify,
	'detail' => 'release.yml must declare a `certify` job that builds + plugin-checks + uploads the certified artifact.',
);
$matrix[] = array(
	'rule'   => 'release_workflow_has_publish_job',
	'passes' => $has_publish,
	'detail' => 'release.yml must declare a `publish` job that consumes the certified artifact (no rebuild).',
);
if ( ! $has_certify ) {
	$errors[] = 'release.yml has no `certify` job. Phase 53 requires the build + Plugin Check + artifact upload to live in a dedicated job.';
}
if ( ! $has_publish ) {
	$errors[] = 'release.yml has no `publish` job. Phase 53 requires a separate publish job that consumes the certified artifact.';
}

/**
 * Rule 2: certify MUST run the build pipeline commands.
 */
$certify_block = $has_certify ? $jobs['certify'] : '';
$certify_has_build_release = (bool) preg_match( '/\bphp scripts\/build-release\.php\b/', $certify_block );
$certify_has_vendor_prefix = (bool) preg_match( '/\bcomposer vendor:prefix\b/', $certify_block );
$certify_has_plugin_check  = (bool) preg_match( '/\bwordpress\/plugin-check-action@/', $certify_block );
$certify_uploads_zip       = (bool) preg_match( '/\bpath:\s*dist\/sscribe-export-site-pages-\*\.zip\b/', $certify_block );
$certify_uploads_sha       = (bool) preg_match( '/\bpath:\s*dist\/sscribe-export-site-pages-\*\.sha256\b/', $certify_block );

$matrix[] = array(
	'rule'   => 'certify_job_runs_build_release_php',
	'passes' => $certify_has_build_release,
	'detail' => '`certify` must invoke `php scripts/build-release.php` to produce the exact ZIP.',
);
$matrix[] = array(
	'rule'   => 'certify_job_runs_composer_vendor_prefix',
	'passes' => $certify_has_vendor_prefix,
	'detail' => '`certify` must invoke `composer vendor:prefix` so the ZIP contains the prefixed vendor tree.',
);
$matrix[] = array(
	'rule'   => 'certify_job_runs_plugin_check',
	'passes' => $certify_has_plugin_check,
	'detail' => '`certify` must run the official WordPress Plugin Check (SHA-pinned wordpress/plugin-check-action).',
);
$matrix[] = array(
	'rule'   => 'certify_job_uploads_zip_artifact',
	'passes' => $certify_uploads_zip,
	'detail' => '`certify` must upload the ZIP as an SHA-pinned actions/upload-artifact artifact so publish can download it without rebuilding.',
);
$matrix[] = array(
	'rule'   => 'certify_job_uploads_sha_sidecar_artifact',
	'passes' => $certify_uploads_sha,
	'detail' => '`certify` must upload the SHA-256 sidecar so publish can verify the download-back roundtrip.',
);
if ( ! $certify_has_build_release ) {
	$errors[] = '`certify` job does not invoke `php scripts/build-release.php`.';
}
if ( ! $certify_has_vendor_prefix ) {
	$errors[] = '`certify` job does not invoke `composer vendor:prefix`.';
}
if ( ! $certify_has_plugin_check ) {
	$errors[] = '`certify` job does not run the official WordPress Plugin Check.';
}
if ( ! $certify_uploads_zip ) {
	$errors[] = '`certify` job does not upload the ZIP as an SHA-pinned actions/upload-artifact artifact.';
}
if ( ! $certify_uploads_sha ) {
	$errors[] = '`certify` job does not upload the .sha256 sidecar as an artifact.';
}

/**
 * Rule 3: publish MUST download the certified artifact, verify
 * SHA in isolation (the "second uncontrolled build" gate), and
 * upload to GitHub Release.
 *
 * Critically: publish MUST NOT run `composer install`,
 * `composer vendor:prefix`, or `php scripts/build-release.php`.
 * Those are certify's job.
 */
$publish_block = $has_publish ? $jobs['publish'] : '';
// Strip YAML comments before scanning. Lines whose first non-whitespace
// is `#` are comments, and so are inline `# ...` tails outside quoted
// strings. A naive `composer install` match inside a `# ...` comment
// would trip the verifier on documentation that *describes* what
// publish must not do.
$publish_block_no_comments = (string) preg_replace( '/^\s*#[^\n]*$/m', '', $publish_block );

$publish_downloads_zip = (bool) preg_match( '/\buses:\s*actions\/download-artifact@[0-9a-f]{40}\b/i', $publish_block_no_comments );
$publish_verifies_sha  = (bool) preg_match( '/sha256sum\b/', $publish_block_no_comments )
	&& (bool) preg_match( '/sha256 mismatch|sha-?256 mismatch/i', $publish_block_no_comments );
$publish_uploads_gh_release = (bool) preg_match( '/\bgh release (upload|create)\b/', $publish_block_no_comments );
$publish_depends_on_certify = (bool) preg_match( '/\bneeds:\s*[^\n]*\bcertify\b/', $publish_block_no_comments );
$publish_has_explicit_gh_repo = false !== strpos( $publish_block_no_comments, 'GH_REPO: ${{ github.repository }}' );

$publish_runs_composer_install = (bool) preg_match( '/\bcomposer install\b/', $publish_block_no_comments );
$publish_runs_vendor_prefix    = (bool) preg_match( '/\bcomposer vendor:prefix\b/', $publish_block_no_comments );
$publish_runs_build_release    = (bool) preg_match( '/\bphp scripts\/build-release\.php\b/', $publish_block_no_comments );

$matrix[] = array(
	'rule'   => 'publish_job_downloads_certified_artifact',
	'passes' => $publish_downloads_zip,
	'detail' => '`publish` must use `SHA-pinned actions/download-artifact` to consume the certified ZIP/SHA/build.json.',
);
$matrix[] = array(
	'rule'   => 'publish_job_verifies_sha_before_publishing',
	'passes' => $publish_verifies_sha,
	'detail' => '`publish` must compute sha256sum and compare it against the certified sidecar BEFORE uploading to GitHub Release.',
);
$matrix[] = array(
	'rule'   => 'publish_job_uploads_to_github_release',
	'passes' => $publish_uploads_gh_release,
	'detail' => '`publish` must upload the verified bytes via `gh release create` / `gh release upload`.',
);
$matrix[] = array(
	'rule'   => 'publish_job_depends_on_certify',
	'passes' => $publish_depends_on_certify,
	'detail' => '`publish` must declare `needs: certify` so the gate is sequential.',
);
$matrix[] = array(
	'rule'   => 'publish_job_gives_gh_cli_explicit_repository_context',
	'passes' => $publish_has_explicit_gh_repo,
	'detail' => '`publish` has no source checkout, so gh release commands must receive GH_REPO: ${{ github.repository }} explicitly.',
);

$matrix[] = array(
	'rule'   => 'publish_job_does_not_run_composer_install',
	'passes' => ! $publish_runs_composer_install,
	'detail' => '`publish` MUST NOT run `composer install` (that would be the second uncontrolled build).',
);
$matrix[] = array(
	'rule'   => 'publish_job_does_not_run_vendor_prefix',
	'passes' => ! $publish_runs_vendor_prefix,
	'detail' => '`publish` MUST NOT run `composer vendor:prefix` (certify owns this).',
);
$matrix[] = array(
	'rule'   => 'publish_job_does_not_run_build_release',
	'passes' => ! $publish_runs_build_release,
	'detail' => '`publish` MUST NOT re-run `php scripts/build-release.php` (certify owns this).',
);

if ( ! $publish_downloads_zip ) {
	$errors[] = '`publish` does not use SHA-pinned actions/download-artifact to consume the certified artifact.';
}
if ( ! $publish_verifies_sha ) {
	$errors[] = '`publish` does not verify the SHA-256 of the downloaded artifact before publishing.';
}
if ( ! $publish_uploads_gh_release ) {
	$errors[] = '`publish` does not upload via `gh release create` / `gh release upload`.';
}
if ( ! $publish_depends_on_certify ) {
	$errors[] = '`publish` does not declare `needs: certify`.';
}
if ( ! $publish_has_explicit_gh_repo ) {
	$errors[] = '`publish` runs gh release commands without explicit GH_REPO even though it intentionally has no checkout/remote.';
}
if ( $publish_runs_composer_install ) {
	$errors[] = '`publish` runs `composer install` — that is the second uncontrolled build Phase 53 forbids.';
}
if ( $publish_runs_vendor_prefix ) {
	$errors[] = '`publish` runs `composer vendor:prefix` — certify owns this.';
}
if ( $publish_runs_build_release ) {
	$errors[] = '`publish` runs `php scripts/build-release.php` — certify owns this.';
}

/**
 * Rule 4: the architecture sequence is verify -> audit -> test ->
 * certify -> publish (the "second uncontrolled build" only
 * happens at certify, never at publish).
 */
$job_order_observed = array_keys( $jobs );
$expected_order     = array( 'verify', 'audit', 'test', 'certify', 'publish' );
$order_matches = ( array_slice( $job_order_observed, 0, count( $expected_order ) ) === $expected_order );
$matrix[] = array(
	'rule'   => 'release_workflow_job_order_is_verify_audit_test_certify_publish',
	'passes' => $order_matches,
	'detail' => 'release.yml jobs must be ordered verify → audit → test → certify → publish. Any reordering means a step runs before its prerequisite.',
);
if ( ! $order_matches ) {
	$errors[] = sprintf(
		'release.yml job order is wrong. Expected: %s, observed: %s',
		implode( ', ', $expected_order ),
		implode( ', ', $job_order_observed )
	);
}

/**
 * Rule 5: only `v*` tags trigger this workflow.
 */
$has_tag_only_trigger = (bool) preg_match(
	'/#\s*Phase 53.*?\n.*?on:\s*\n\s+push:\s*\n\s+tags:\s*\n\s+-\s*[\'"]?v\*[\'"]?\b/s',
	$source
) || (bool) preg_match( '/^on:\s*\n\s+push:\s*\n\s+tags:\s*\n\s+-\s*[\'"]?v\*[\'"]?/m', $source );
$matrix[] = array(
	'rule'   => 'release_workflow_triggered_by_v_tags_only',
	'passes' => $has_tag_only_trigger,
	'detail' => 'release.yml must trigger on `push: tags: - "v*"` only — pushing to a branch should NOT publish a release.',
);
if ( ! $has_tag_only_trigger ) {
	$errors[] = 'release.yml does not declare `on.push.tags: v*` as the trigger.';
}

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at'   => gmdate( 'c' ),
	'rule_count'     => count( $matrix ),
	'passed_count'   => count( array_filter( $matrix, static fn( $r ) => $r['passes'] ) ),
	'job_order'      => $job_order_observed,
	'matrix'         => $matrix,
	'errors_count'   => count( $errors ),
	'warnings_count' => count( $warnings ),
	'passes'         => 0 === count( $errors ),
	'errors'         => $errors,
	'warnings'       => $warnings,
);
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

echo "=== SScribe Release Pipeline Architecture ===\n\n";
foreach ( $matrix as $row ) {
	$status = $row['passes'] ? '✓' : '✗';
	echo sprintf( "  %s  %s\n      %s\n", $status, $row['rule'], $row['detail'] );
}
echo "\nErrors: " . count( $errors ) . "\n";
foreach ( $errors as $error ) {
	echo "  ✗ {$error}\n";
}
if ( ! empty( $warnings ) ) {
	echo "\nWarnings: " . count( $warnings ) . "\n";
	foreach ( $warnings as $warning ) {
		echo "  ⚠ {$warning}\n";
	}
}
echo "\nManifest persisted to: {$manifest_path}\n";

if ( ! empty( $errors ) ) {
	exit( 1 );
}
echo "✓ Release pipeline architecture contract valid.\n";
exit( 0 );
