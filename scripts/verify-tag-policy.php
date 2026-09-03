<?php
/**
 * Phase 54 — Tag policy verifier.
 *
 * Contract: a release tag is cut ONLY when:
 *
 *   1. The tag's version component (stripping the leading `v`)
 *      equals SSCRIBE_VERSION in sscribe-export-site-pages.php —
 *      i.e. the tag names what the constant says.
 *   2. The tag points at the latest origin/main HEAD — i.e. the
 *      tagged commit is the most recent commit on the release
 *      branch that has passed the ci.yml gate (Phase 55 closes
 *      the loop with required-status checks).
 *   3. The certified artifact evidence records three columns:
 *      `source_sha`, `tag`, and `zip_sha256` — so an
 *      independent auditor can verify (a) which commit produced
 *      the artifact, (b) which tag it shipped under, and (c)
 *      the exact hash published to WP.org.
 *   4. The release workflow does NOT itself cut the tag — that
 *      remains a deliberate maintainer action guarded by all of
 *      the above. (CI consumes tags; it does not produce them.)
 *
 * The verifier walks .github/workflows/release.yml as text and
 * reads the plugin file's SSCRIBE_VERSION literal. No runtime
 * YAML parser dependency.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir      = dirname( __DIR__ );
$workflow_path = $root_dir . '/.github/workflows/release.yml';
$plugin_file   = $root_dir . '/sscribe-export-site-pages.php';
$manifest_path = $root_dir . '/dist/tag-policy-manifest.json';

$matrix = array();
$errors = array();

if ( ! is_file( $workflow_path ) ) {
	fwrite( STDERR, "✗ release.yml not found.\n" );
	exit( 1 );
}
if ( ! is_file( $plugin_file ) ) {
	fwrite( STDERR, "✗ sscribe-export-site-pages.php not found.\n" );
	exit( 1 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$source      = (string) file_get_contents( $workflow_path );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$plugin_src  = (string) file_get_contents( $plugin_file );

// Read SSCRIBE_VERSION literal.
$canonical_version = null;
if ( preg_match( "/define\\s*\\(\\s*['\"]SSCRIBE_VERSION['\"]\\s*,\\s*['\"]([0-9]+\\.[0-9]+\\.[0-9]+)['\"]/", $plugin_src, $m ) ) {
	$canonical_version = $m[1];
} else {
	$errors[] = 'SSCRIBE_VERSION literal not found in plugin file.';
}

/**
 * Rule 1: release.yml is triggered ONLY on `v*` tag pushes. A
 * branch push must never publish a release.
 */
$tag_only_trigger = (bool) preg_match(
	'/^on:\s*\n\s+push:\s*\n\s+tags:\s*\n\s+-\s*[\'"]?v\*[\'"]?\s*$/m',
	$source
);
$matrix[] = array(
	'rule'   => 'release_workflow_triggered_by_v_tags_only',
	'passes' => $tag_only_trigger,
	'detail' => 'release.yml must declare `on: push: tags: [ "v*" ]` only — branch pushes must NOT publish a release.',
);
if ( ! $tag_only_trigger ) {
	$errors[] = 'release.yml does not declare `push: tags: [v*]` as the trigger.';
}

// Rule 2: workflow must NOT itself create a tag (`git tag`,
// `gh release create --verify-tag` is the SHA-binding form, but
// a maintainer cutting the tag must remain a manual decision).
$ci_cuts_tags = (bool) preg_match( '/^\s*(?:-\s*)?run:\s*.*\bgit tag\b/m', $source );
$matrix[] = array(
	'rule'   => 'release_workflow_does_not_cut_tags',
	'passes' => ! $ci_cuts_tags,
	'detail' => 'CI MUST NOT cut tags. Cutting a tag is a deliberate maintainer action gated on Phase 55 branch-protection + the verify job.',
);
if ( $ci_cuts_tags ) {
	$errors[] = 'release.yml contains a `git tag` command — tag creation must remain a manual action outside CI.';
}

/**
 * Rule 3: the verify job must check tag == SSCRIBE_VERSION.
 * A regression that drops this check ships a ZIP whose filename
 * tag disagrees with the in-file constant — WP.org rejects it.
 */
$verifies_tag_version = (bool) preg_match( "/verify.*tag matches version|verify.*tag.*version/i", $source )
	&& (bool) preg_match( '/TAG_VERSION/', $source )
	&& (bool) preg_match( '/PLUGIN_VERSION/', $source )
	&& (bool) preg_match( '/SSCRIBE_VERSION/', $source );
$matrix[] = array(
	'rule'   => 'verify_job_asserts_tag_version_matches_plugin_version',
	'passes' => $verifies_tag_version,
	'detail' => 'The `verify` job must compare the tag version (TAG_VERSION) against the SSCRIBE_VERSION constant (PLUGIN_VERSION) and exit non-zero on mismatch.',
);
if ( ! $verifies_tag_version ) {
	$errors[] = 'release.yml `verify` job does not check `TAG_VERSION == PLUGIN_VERSION`.';
}

/**
 * Rule 4: the verify job must check tag-SHA == origin/main HEAD.
 * Phase 17: "tag-on-main only" — release tags must point at the
 * latest commit on origin/main that has passed ci.yml.
 */
$verifies_tag_on_main = (bool) preg_match( '/verify.*tag.*main|tag.*current main HEAD/i', $source )
	&& (bool) preg_match( '/TAG_SHA/', $source )
	&& (bool) preg_match( '/MAIN_SHA/', $source )
	&& (bool) preg_match( '/origin\/main/', $source );
$matrix[] = array(
	'rule'   => 'verify_job_asserts_tag_sha_matches_origin_main_head',
	'passes' => $verifies_tag_on_main,
	'detail' => 'The `verify` job must compare TAG_SHA against MAIN_SHA (origin/main HEAD) and exit non-zero when they differ.',
);
if ( ! $verifies_tag_on_main ) {
	$errors[] = 'release.yml `verify` job does not check `TAG_SHA == MAIN_SHA (origin/main HEAD)`.';
}

/**
 * Rule 5: the certified release evidence (build.json) MUST record
 * three fields: source_sha, tag, zip_sha256. The auditor uses
 * these to verify (a) which commit produced the artifact,
 * (b) which tag it shipped under, (c) the exact hash published.
 */
$evidence_records_source_sha = (bool) preg_match( '/"source_sha"\s*:\s*"\$\{SOURCE_SHA\}"/', $source );
$evidence_records_tag        = (bool) preg_match( '/"tag"\s*:\s*"\$\{REF_NAME\}"/', $source );
$evidence_records_zip_sha    = (bool) preg_match( '/"zip_sha256"\s*:\s*"\$\{ZIP_SHA\}"/', $source );
$evidence_extracts_zip_sha   = (bool) preg_match( '/zip-sha\s*$/m', $source )
	&& (bool) preg_match( '/awk.*print.*\$1/', $source );

$matrix[] = array(
	'rule'   => 'certify_records_source_sha_in_build_evidence',
	'passes' => $evidence_records_source_sha,
	'detail' => 'certify must persist `source_sha` in the build.json evidence file so the auditor can tie the artifact back to the commit.',
);
$matrix[] = array(
	'rule'   => 'certify_records_tag_in_build_evidence',
	'passes' => $evidence_records_tag,
	'detail' => 'certify must persist `tag` (REF_NAME) in the build.json evidence file so the artifact is traceable to its release tag.',
);
$matrix[] = array(
	'rule'   => 'certify_records_zip_sha256_in_build_evidence',
	'passes' => $evidence_records_zip_sha,
	'detail' => 'certify must persist `zip_sha256` (a 64-hex hash) in the build.json evidence file so the artifact hash is decoupled from the .sha256 sidecar.',
);
$matrix[] = array(
	'rule'   => 'certify_extracts_zip_sha256_from_sidecar',
	'passes' => $evidence_extracts_zip_sha,
	'detail' => 'certify must extract the ZIP SHA-256 from the build-release.php sidecar (via awk) before writing build.json — otherwise the field is empty.',
);

if ( ! $evidence_records_source_sha ) {
	$errors[] = 'certify does not persist `source_sha` in build.json evidence.';
}
if ( ! $evidence_records_tag ) {
	$errors[] = 'certify does not persist `tag` in build.json evidence.';
}
if ( ! $evidence_records_zip_sha ) {
	$errors[] = 'certify does not persist `zip_sha256` in build.json evidence.';
}
if ( ! $evidence_extracts_zip_sha ) {
	$errors[] = 'certify does not extract the ZIP SHA-256 from the build-release.php sidecar via awk.';
}

/**
 * Rule 6: the build.json evidence is uploaded as a GitHub Actions
 * artifact in certify, AND downloaded in publish — so the auditor
 * can fetch the certified evidence without rerunning certify.
 */
$evidence_uploaded_in_certify = (bool) preg_match( "/path:\\s*dist\\/sscribe-export-site-pages-\\*\\.build\\.json/", $source );
$evidence_downloaded_in_publish = (bool) preg_match( '/name:\s*sscribe-release-build-json/', $source );

$matrix[] = array(
	'rule'   => 'build_evidence_uploaded_in_certify',
	'passes' => $evidence_uploaded_in_certify,
	'detail' => 'The build.json evidence must be uploaded as an actions/upload-artifact@v6 artifact from the certify job.',
);
$matrix[] = array(
	'rule'   => 'build_evidence_downloaded_in_publish',
	'passes' => $evidence_downloaded_in_publish,
	'detail' => 'The build.json evidence must be downloadable from the publish job (artifact `sscribe-release-build-json`).',
);
if ( ! $evidence_uploaded_in_certify ) {
	$errors[] = 'certify does not upload build.json evidence as a GitHub Actions artifact.';
}
if ( ! $evidence_downloaded_in_publish ) {
	$errors[] = 'publish does not download build.json evidence from the certify artifact.';
}

/**
 * Rule 7: when GitHub releases support signing
 * (SIGSTORE / Sigstore-style attestation, or GPG), the release
 * payload SHOULD be signed. We assert that the workflow uses
 * `--verify-tag` on first release creation (the SHA-binding form)
 * AND that the release tools are present. Note that `--verify-tag`
 * is SHA-binding via `gh release`, which is the standard way
 * GitHub Releases ties a release to its tagged commit.
 */
$releases_verify_tag = (bool) preg_match( '/--verify-tag/', $source );
$matrix[] = array(
	'rule'   => 'gh_release_create_uses_verify_tag',
	'passes' => $releases_verify_tag,
	'detail' => '`gh release create` must use `--verify-tag` so the release is bound to the SHA GitHub has signed.',
);
if ( ! $releases_verify_tag ) {
	$errors[] = '`gh release create` does not use `--verify-tag` — release must be bound to a signed tag.';
}

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at'       => gmdate( 'c' ),
	'canonical_version'  => $canonical_version,
	'rule_count'         => count( $matrix ),
	'passed_count'       => count( array_filter( $matrix, static fn( $r ) => $r['passes'] ) ),
	'matrix'             => $matrix,
	'errors_count'       => count( $errors ),
	'passes'             => 0 === count( $errors ),
	'errors'             => $errors,
);
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

echo "=== SScribe Tag Policy ===\n\n";
if ( null !== $canonical_version ) {
	echo "Canonical SSCRIBE_VERSION: {$canonical_version}\n\n";
}
foreach ( $matrix as $row ) {
	$status = $row['passes'] ? '✓' : '✗';
	echo sprintf( "  %s  %s\n      %s\n", $status, $row['rule'], $row['detail'] );
}
echo "\nErrors: " . count( $errors ) . "\n";
foreach ( $errors as $error ) {
	echo "  ✗ {$error}\n";
}
echo "\nManifest persisted to: {$manifest_path}\n";

if ( ! empty( $errors ) ) {
	exit( 1 );
}
echo "✓ Tag policy contract valid.\n";
exit( 0 );
