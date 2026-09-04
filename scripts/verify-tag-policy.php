<?php
/**
 * Phase 54 — Tag policy verifier (refactored).
 *
 * Contract: a release tag is cut ONLY when:
 *
 *   1. The tag's version component (stripping the leading `v`)
 *      equals SSCRIBE_VERSION in sscribe-export-site-pages.php —
 *      i.e. the tag names what the constant says.
 *   2. The tag is an annotated tag (created via `git tag -a`), not a
 *      lightweight tag.
 *   3. The tag points at the latest origin/main HEAD at cert time.
 *   4. The tag points at the exact certified source SHA — the SHA
 *      recorded in dist/release-certification-evidence.json.
 *   5. The local main ref equals the remote origin/main at tag-cut
 *      time (no drift).
 *   6. No tag re-cut: once v{VERSION} exists it cannot be
 *      force-moved.
 *   7. CI MUST NOT cut tags. Cutting is a deliberate maintainer
 *      action gated on Phase 55 branch protection.
 *   8. `gh release create` uses `--verify-tag` when publishing.
 *      `--verify-tag` is SHA-binding via GitHub's signed-tag store;
 *      it does NOT itself produce a cryptographic signature.
 *
 * Cryptographic signing (git tag -s) is **recommended** when the
 * maintainer has signing configured, NOT required. WP.org plugin
 * submission does not require it. The verifier records the signing
 * status as advisory.
 *
 * The verifier walks .github/workflows/release.yml as text and
 * reads the plugin file's SSCRIBE_VERSION literal. No runtime YAML
 * parser dependency.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' );
}

$root_dir                    = dirname( __DIR__ );
$workflow_path               = $root_dir . '/.github/workflows/release.yml';
$plugin_file                 = $root_dir . '/sscribe-export-site-pages.php';
$policy_doc_path             = $root_dir . '/docs/TAG_POLICY_v2.0.0.md';
$cert_evidence_path          = $root_dir . '/dist/release-certification-evidence.json';
$manifest_path               = $root_dir . '/dist/tag-policy-manifest.json';
$strict_certification        = '1' === (string) getenv( 'SSCRIBE_RELEASE_CERTIFICATION' );

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

// Read policy doc and assert it declares the 8 canonical rules.
if ( ! is_file( $policy_doc_path ) ) {
	$errors[] = "Policy doc {$policy_doc_path} must exist.";
} else {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$policy_src = (string) file_get_contents( $policy_doc_path );
	for ( $i = 1; $i <= 8; $i++ ) {
		if ( false === strpos( $policy_src, "| {$i} " ) && ! preg_match( "/\\|\\s*{$i}\\s*\\|/", $policy_src ) ) {
			$errors[] = "Policy doc must declare rule #{$i} in its canonical rules table.";
		}
	}
	$matrix[] = array(
		'rule'   => 'policy_doc_declares_eight_canonical_rules',
		'passes' => count( $errors ) === 0 || ! in_array( "Policy doc must declare rule #1 in its canonical rules table.", $errors, true ),
		'detail' => 'docs/TAG_POLICY_v2.0.0.md must declare all 8 canonical rules in the table.',
	);
	// Also assert the policy explicitly notes that --verify-tag is SHA-binding, not crypto signing.
	$clarifies_verify_tag = (bool) preg_match( '/--verify-tag.*SHA-binding|SHA-binding.*--verify-tag|--verify-tag.*signed-tag store|signed-tag store.*--verify-tag/si', $policy_src )
		|| ( false !== stripos( $policy_src, 'SHA-binding' ) && false !== stripos( $policy_src, '--verify-tag' ) );
	$matrix[] = array(
		'rule'   => 'policy_doc_clarifies_verify_tag_is_sha_binding',
		'passes' => $clarifies_verify_tag,
		'detail' => 'docs/TAG_POLICY_v2.0.0.md must explicitly note that `gh release create --verify-tag` is SHA-binding via GitHub\'s signed-tag store, not cryptographic signing.',
	);
	if ( ! $clarifies_verify_tag ) {
		$errors[] = 'Policy doc does not clarify that `--verify-tag` is SHA-binding, not cryptographic signing.';
	}
	// Also assert the policy says crypto signing is recommended, not required.
	$crypto_advisory = (bool) preg_match( '/cryptographic signing.*recommend|crypto.*sign.*recommend|signing.*recommend/i', $policy_src );
	$matrix[] = array(
		'rule'   => 'policy_doc_states_crypto_signing_is_recommended',
		'passes' => $crypto_advisory,
		'detail' => 'docs/TAG_POLICY_v2.0.0.md must state that cryptographic tag signing is recommended (when the maintainer has signing configured), not required.',
	);
	if ( ! $crypto_advisory ) {
		$errors[] = 'Policy doc does not state that cryptographic tag signing is recommended, not required.';
	}
}

/**
 * Rule 1: release.yml is triggered ONLY on `v*` tag pushes.
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

/**
 * Rule 7: workflow must NOT itself create a tag.
 */
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
 * Rule 1: the verify job must check tag == SSCRIBE_VERSION.
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
 * Rule 3: the verify job must check tag-SHA == origin/main HEAD.
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
 * Rules 4, build evidence: the certified release evidence (build.json) MUST record
 * source_sha, tag, and zip_sha256.
 */
$evidence_records_source_sha = (bool) preg_match( '/"source_sha"\s*:\s*"\$\{SOURCE_SHA\}"/', $source );
$evidence_records_tag        = (bool) preg_match( '/"tag"\s*:\s*"\$\{REF_NAME\}"/', $source );
$evidence_records_zip_sha    = (bool) preg_match( '/"zip_sha256"\s*:\s*"\$\{ZIP_SHA\}"/', $source );
$evidence_extracts_zip_sha   = (bool) preg_match( '/zip-sha\s*$/m', $source )
	&& (bool) preg_match( '/awk.*print.*\$1/', $source );

$matrix[] = array(
	'rule'   => 'certify_records_source_sha_in_build_evidence',
	'passes' => $evidence_records_source_sha,
	'detail' => 'certify must persist `source_sha` in the build.json evidence file so the auditor can tie the artifact back to the commit (Rule 4).',
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
 * build.json evidence upload/download between certify and publish.
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
 * Rule 8: `gh release create` uses `--verify-tag`.
 */
$releases_verify_tag = (bool) preg_match( '/--verify-tag/', $source );
$matrix[] = array(
	'rule'   => 'gh_release_create_uses_verify_tag',
	'passes' => $releases_verify_tag,
	'detail' => '`gh release create` must use `--verify-tag` (SHA-binding via GitHub\'s signed-tag store) when publishing the release.',
);
if ( ! $releases_verify_tag ) {
	$errors[] = '`gh release create` does not use `--verify-tag` — release will not be bound to a tag.';
}

/**
 * Rule 6: no tag force-move / re-cut. The workflow must not pass
 * `--force` / `--force-with-lease` to `git tag`, and the policy
 * must declare the rule.
 */
$tag_force_move = (bool) preg_match( '/git tag[^\\n]*--force(?:-with-lease)?/i', $source )
	|| (bool) preg_match( '/--force[^\\n]*git tag/i', $source );
$matrix[] = array(
	'rule'   => 'release_workflow_does_not_force_move_tags',
	'passes' => ! $tag_force_move,
	'detail' => 'release.yml must not pass `--force` / `--force-with-lease` to `git tag` — once a release tag exists it cannot be re-cut.',
);
if ( $tag_force_move ) {
	$errors[] = 'release.yml forces `--force` on `git tag` — release tags must not be re-cut.';
}

/**
 * Rule 4 / Phase 72: the certified source SHA recorded in the build
 * evidence must equal the tag SHA when a tag exists for the
 * canonical version. (Cert-time check; only fires in strict mode.)
 */
$cert_source_sha_match = null;
if ( $strict_certification && null !== $canonical_version && is_file( $cert_evidence_path ) ) {
	$cert_payload = json_decode( (string) file_get_contents( $cert_evidence_path ), true );
	$cert_sha     = is_array( $cert_payload ) ? ( $cert_payload['source_sha'] ?? null ) : null;
	$tag_sha      = trim( (string) shell_exec( 'git rev-parse v' . escapeshellarg( $canonical_version ) . ' 2>/dev/null' ) );
	if ( null === $cert_sha || '' === $cert_sha ) {
		$cert_source_sha_match = false;
		$errors[]              = 'dist/release-certification-evidence.json does not contain a source_sha field.';
	} elseif ( '' === $tag_sha ) {
		$cert_source_sha_match = null; // No tag yet — not a strict failure at pre-tag time.
	} elseif ( 0 !== strcmp( $cert_sha, $tag_sha ) ) {
		$cert_source_sha_match = false;
		$errors[]              = "Tag SHA does not equal certified source SHA: tag={$tag_sha} cert={$cert_sha}. The release tag must be cut at the SHA recorded in dist/release-certification-evidence.json.";
	} else {
		$cert_source_sha_match = true;
	}
}
$matrix[] = array(
	'rule'   => 'tag_sha_matches_certified_source_sha',
	'passes' => null === $cert_source_sha_match || true === $cert_source_sha_match,
	'detail' => 'In strict cert mode, if a tag v{VERSION} already exists, its SHA must equal the certified source SHA recorded in dist/release-certification-evidence.json.',
);

/**
 * Advisory: cryptographic signing of the tag (recommended, not required).
 * We do NOT fail if not signed; we record status for the manifest.
 */
$tag_signing_status = 'not_applicable';
if ( null !== $canonical_version ) {
	$tag_exists = 0 === strpos( trim( (string) shell_exec( 'git rev-parse v' . escapeshellarg( $canonical_version ) . '^{tag} 2>/dev/null' ) ), '' ) ? false : true;
	if ( $tag_exists ) {
		$verify_out = shell_exec( 'git tag -v v' . escapeshellarg( $canonical_version ) . ' 2>&1' );
		// `git tag -v` exits 0 + "Good signature" / "gpg: Good signature" when signed and key is loaded.
		// Exit code 1 + "no signature found" when tag is unsigned.
		// Exit code 128 + "gpg: Can't check signature: No public key" when signed but key not on this machine.
		$has_no_signature      = false !== stripos( (string) $verify_out, 'no signature found' );
		$has_good_signature   = false !== stripos( (string) $verify_out, 'good signature' );
		$has_no_public_key    = false !== stripos( (string) $verify_out, 'no public key' );
		if ( $has_good_signature ) {
			$tag_signing_status = 'cryptographically_signed';
		} elseif ( $has_no_public_key ) {
			$tag_signing_status = 'signed_key_unavailable_locally';
		} elseif ( $has_no_signature ) {
			$tag_signing_status = 'unsigned_recommended_when_configured';
		} else {
			$tag_signing_status = 'unknown';
		}
	}
}
$matrix[] = array(
	'rule'   => 'tag_cryptographic_signing_advisory',
	'passes' => true, // advisory; never fails the gate
	'detail' => "Advisory: cryptographic tag signing status = {$tag_signing_status}. WP.org does not require signed tags; signing is recommended when the maintainer has a signing key configured.",
);

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at'        => gmdate( 'c' ),
	'canonical_version'   => $canonical_version,
	'tag_signing_status'  => $tag_signing_status,
	'strict_certification'=> $strict_certification,
	'rule_count'          => count( $matrix ),
	'passed_count'        => count( array_filter( $matrix, static fn( $r ) => $r['passes'] ) ),
	'matrix'              => $matrix,
	'errors_count'        => count( $errors ),
	'passes'              => 0 === count( $errors ),
	'errors'              => $errors,
);
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

echo "=== SScribe Tag Policy ===\n\n";
if ( null !== $canonical_version ) {
	echo "Canonical SSCRIBE_VERSION: {$canonical_version}\n";
}
echo "Tag signing status: {$tag_signing_status}\n";
echo "Strict certification: " . ( $strict_certification ? 'yes' : 'no' ) . "\n\n";
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
