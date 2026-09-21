<?php
/**
 * Branch topology policy verifier.
 *
 * Enforces the main-only persistent branch model documented in
 * docs/BRANCH_POLICY_v2.0.0.md.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$repo_root = dirname( __DIR__ );

$failures = array();
$warnings = array();
$notes    = array();
$manifest = array(
	'timestamp' => gmdate( 'c' ),
	'ci_mode'   => getenv( 'GITHUB_ACTIONS' ) === 'true',
	'rules'     => array(),
	'failures'  => array(),
	'warnings'  => array(),
);

$canonical_sections = array(
	'## Why this exists',
	'## Canonical long-lived branches',
	'## Forbidden patterns',
	'## Promotion rules',
	'## How an independent auditor verifies this',
);
$canonical_branches = array( 'main' );

function sscribe_branch_policy_git( string $repo_root, string $args ): string {
	$is_windows = ( PHP_OS_FAMILY === 'Windows' );
	$null_dev   = $is_windows ? 'NUL' : '/dev/null';
	$root       = $is_windows ? str_replace( '\\', '/', $repo_root ) : $repo_root;
	return trim( (string) shell_exec( 'git -C ' . escapeshellarg( $root ) . ' ' . $args . ' 2>' . $null_dev ) );
}

function sscribe_branch_policy_record( array &$manifest, array &$failures, array &$warnings, array &$notes, string $rule, string $status, string $detail ): void {
	$manifest['rules'][ $rule ] = array(
		'status' => $status,
		'detail' => $detail,
	);
	if ( 'PASS' === $status ) {
		$notes[] = "[rule:{$rule}] OK ({$detail})";
	} elseif ( 'WARN' === $status ) {
		$warnings[] = "[rule:{$rule}] {$detail}";
	} else {
		$failures[] = "[rule:{$rule}] {$detail}";
	}
}

$is_ci             = ( getenv( 'GITHUB_ACTIONS' ) === 'true' );
$strict_release    = '1' === (string) getenv( 'SSCRIBE_RELEASE_CERTIFICATION' );
$manifest['ci_mode'] = $is_ci;
$manifest['strict_release_certification'] = $strict_release;

$policy_doc = $repo_root . '/docs/BRANCH_POLICY_v2.0.0.md';
if ( ! is_file( $policy_doc ) ) {
	sscribe_branch_policy_record( $manifest, $failures, $warnings, $notes, 'policy_doc_exists', 'FAIL', 'docs/BRANCH_POLICY_v2.0.0.md is missing.' );
} else {
	sscribe_branch_policy_record( $manifest, $failures, $warnings, $notes, 'policy_doc_exists', 'PASS', 'docs/BRANCH_POLICY_v2.0.0.md present.' );
	$src = (string) file_get_contents( $policy_doc );
	foreach ( $canonical_sections as $section ) {
		if ( false === strpos( $src, $section ) ) {
			sscribe_branch_policy_record( $manifest, $failures, $warnings, $notes, 'policy_doc_has_canonical_sections', 'FAIL', "missing section: {$section}" );
		}
	}
	if ( ! isset( $manifest['rules']['policy_doc_has_canonical_sections'] ) ) {
		sscribe_branch_policy_record( $manifest, $failures, $warnings, $notes, 'policy_doc_has_canonical_sections', 'PASS', count( $canonical_sections ) . '/' . count( $canonical_sections ) . ' sections present' );
	}
}

$local_raw      = sscribe_branch_policy_git( $repo_root, 'for-each-ref --format="%(refname:short)" refs/heads/' );
$local_branches = array_values( array_filter( array_map( 'trim', explode( "\n", $local_raw ) ) ) );
$forbidden      = array_values( array_diff( $local_branches, $canonical_branches ) );
if ( ! empty( $forbidden ) ) {
	$detail = 'forbidden local branch(es): ' . implode( ', ', $forbidden );
	if ( $is_ci ) {
		sscribe_branch_policy_record( $manifest, $failures, $warnings, $notes, 'only_canonical_local_branches', 'WARN', $detail . ' (CI checkout; origin/main remains authoritative)' );
	} else {
		sscribe_branch_policy_record( $manifest, $failures, $warnings, $notes, 'only_canonical_local_branches', 'FAIL', $detail . '. Only main may persist locally.' );
	}
} else {
	sscribe_branch_policy_record( $manifest, $failures, $warnings, $notes, 'only_canonical_local_branches', 'PASS', 'local: ' . implode( ', ', $local_branches ) );
}

$main_local = sscribe_branch_policy_git( $repo_root, 'show-ref --verify --hash ' . escapeshellarg( 'refs/heads/main' ) );
if ( '' === $main_local ) {
	if ( $is_ci ) {
		sscribe_branch_policy_record( $manifest, $failures, $warnings, $notes, 'canonical_branch_main_exists_locally', 'WARN', 'local main not present in detached CI checkout' );
	} else {
		sscribe_branch_policy_record( $manifest, $failures, $warnings, $notes, 'canonical_branch_main_exists_locally', 'FAIL', 'local branch main does not exist.' );
	}
} else {
	sscribe_branch_policy_record( $manifest, $failures, $warnings, $notes, 'canonical_branch_main_exists_locally', 'PASS', substr( $main_local, 0, 8 ) );
}

if ( $is_ci ) {
	$remote_ref  = 'refs/remotes/origin/main';
	$main_remote = sscribe_branch_policy_git( $repo_root, 'show-ref --verify --hash ' . escapeshellarg( $remote_ref ) );
} else {
	$remote_line = sscribe_branch_policy_git( $repo_root, 'ls-remote origin ' . escapeshellarg( 'refs/heads/main' ) );
	$main_remote = '' === $remote_line ? '' : trim( (string) preg_split( '/\s+/', $remote_line )[0] );
}

if ( '' === $main_remote ) {
	sscribe_branch_policy_record( $manifest, $failures, $warnings, $notes, 'canonical_branch_main_exists_on_origin', 'FAIL', 'origin/main does not exist in authenticated repository evidence.' );
} else {
	sscribe_branch_policy_record( $manifest, $failures, $warnings, $notes, 'canonical_branch_main_exists_on_origin', 'PASS', substr( $main_remote, 0, 8 ) );
}

if ( '' !== $main_local && '' !== $main_remote && $main_local !== $main_remote ) {
	sscribe_branch_policy_record(
		$manifest,
		$failures,
		$warnings,
		$notes,
		'local_main_matches_origin_main',
		$is_ci ? 'WARN' : 'FAIL',
		'local main (' . substr( $main_local, 0, 8 ) . ') differs from origin/main (' . substr( $main_remote, 0, 8 ) . ').'
	);
} elseif ( '' !== $main_local && '' !== $main_remote ) {
	sscribe_branch_policy_record( $manifest, $failures, $warnings, $notes, 'local_main_matches_origin_main', 'PASS', substr( $main_local, 0, 8 ) );
}

$local_refs_raw = sscribe_branch_policy_git( $repo_root, "for-each-ref --format='%(refname)' refs/heads/ refs/tags/" );
$local_refs     = array_values( array_filter( array_map( static fn( $ref ) => trim( $ref, " \t\n\r\0\x0B'\"" ), explode( "\n", $local_refs_raw ) ) ) );
$remote_refs    = array();

if ( $is_ci ) {
	$checkout_refs = sscribe_branch_policy_git( $repo_root, "for-each-ref --format='%(refname)' refs/remotes/origin/ refs/tags/" );
	foreach ( explode( "\n", $checkout_refs ) as $line ) {
		$ref = trim( $line, " \t\n\r\0\x0B'\"" );
		if ( '' === $ref || 'refs/remotes/origin/HEAD' === $ref ) {
			continue;
		}
		if ( str_starts_with( $ref, 'refs/remotes/origin/' ) ) {
			$ref = 'refs/heads/' . substr( $ref, strlen( 'refs/remotes/origin/' ) );
		}
		$remote_refs[ $ref ] = true;
	}
} else {
	$remote_lines = sscribe_branch_policy_git( $repo_root, 'ls-remote' );
	foreach ( explode( "\n", $remote_lines ) as $line ) {
		$parts = preg_split( '/\s+/', trim( $line ), 2 );
		if ( is_array( $parts ) && count( $parts ) >= 2 ) {
			$remote_refs[ $parts[1] ] = true;
		}
	}
}

$allowed_remote_branch_refs = array( 'refs/heads/main' => true );
$active_review_branch       = getenv( 'GITHUB_HEAD_REF' );
if ( $is_ci && is_string( $active_review_branch ) && '' !== trim( $active_review_branch ) ) {
	$allowed_remote_branch_refs[ 'refs/heads/' . trim( $active_review_branch ) ] = true;
}

$noncanonical_remote_branches = array();
foreach ( array_keys( $remote_refs ) as $ref ) {
	if ( str_starts_with( $ref, 'refs/heads/' ) && ! isset( $allowed_remote_branch_refs[ $ref ] ) ) {
		$noncanonical_remote_branches[] = substr( $ref, strlen( 'refs/heads/' ) );
	}
}
sort( $noncanonical_remote_branches );
if ( empty( $noncanonical_remote_branches ) ) {
	sscribe_branch_policy_record( $manifest, $failures, $warnings, $notes, 'no_noncanonical_remote_branches', 'PASS', 'no persistent remote branch exists outside the canonical/active-review set' );
} else {
	$detail = 'non-canonical remote branch(es): ' . implode( ', ', $noncanonical_remote_branches );
	sscribe_branch_policy_record(
		$manifest,
		$failures,
		$warnings,
		$notes,
		'no_noncanonical_remote_branches',
		$strict_release ? 'FAIL' : 'WARN',
		$detail . ( $strict_release ? '. Strict release certification requires remote cleanup.' : ' (allowed only as transient review state before final cleanup).' )
	);
}

$origin_default_branch = '';
if ( ! $is_ci ) {
	$origin_head = sscribe_branch_policy_git( $repo_root, 'ls-remote --symref origin HEAD' );
	if ( preg_match( '/^ref:\s+refs\/heads\/([^\s]+)\s+HEAD$/m', $origin_head, $default_match ) ) {
		$origin_default_branch = trim( (string) $default_match[1] );
	}
} else {
	$origin_head_ref = sscribe_branch_policy_git( $repo_root, 'symbolic-ref refs/remotes/origin/HEAD' );
	if ( str_starts_with( $origin_head_ref, 'refs/remotes/origin/' ) ) {
		$origin_default_branch = substr( $origin_head_ref, strlen( 'refs/remotes/origin/' ) );
	}
}

if ( 'main' === $origin_default_branch ) {
	sscribe_branch_policy_record( $manifest, $failures, $warnings, $notes, 'origin_default_branch_is_main', 'PASS', 'origin default branch: main' );
} elseif ( '' === $origin_default_branch ) {
	sscribe_branch_policy_record(
		$manifest,
		$failures,
		$warnings,
		$notes,
		'origin_default_branch_is_main',
		( $strict_release && ! $is_ci ) ? 'FAIL' : 'WARN',
		'origin default branch could not be resolved' . ( $is_ci ? ' from the offline CI checkout' : '' )
	);
} else {
	sscribe_branch_policy_record(
		$manifest,
		$failures,
		$warnings,
		$notes,
		'origin_default_branch_is_main',
		$strict_release ? 'FAIL' : 'WARN',
		'origin default branch is ' . $origin_default_branch . '; canonical default is main.'
	);
}

$governance_evidence_path = $repo_root . '/dist/repository-governance-evidence.json';
if ( $strict_release ) {
	$governance = array();
	if ( is_file( $governance_evidence_path ) ) {
		$decoded = json_decode( (string) file_get_contents( $governance_evidence_path ), true );
		if ( is_array( $decoded ) ) {
			$governance = $decoded;
		}
	}

	sscribe_branch_policy_record(
		$manifest, $failures, $warnings, $notes,
		'repository_governance_evidence_present',
		! empty( $governance ) ? 'PASS' : 'FAIL',
		! empty( $governance )
			? 'dist/repository-governance-evidence.json present'
			: 'strict release certification requires dist/repository-governance-evidence.json'
	);

	$current_sha = sscribe_branch_policy_git( $repo_root, 'rev-parse HEAD' );
	$governance_sha_matches = ! empty( $governance )
		&& (bool) preg_match( '/^[a-f0-9]{40}$/', $current_sha )
		&& hash_equals( $current_sha, (string) ( $governance['source_sha'] ?? '' ) );
	sscribe_branch_policy_record(
		$manifest, $failures, $warnings, $notes,
		'repository_governance_evidence_matches_current_sha',
		$governance_sha_matches ? 'PASS' : 'FAIL',
		$governance_sha_matches
			? 'repository governance evidence is bound to current HEAD ' . substr( $current_sha, 0, 8 )
			: 'repository governance evidence source_sha must equal current git HEAD'
	);

	$repo_identity_ok = 'SimplixInnovations/sscribe-export-site-pages' === (string) ( $governance['repository'] ?? '' );
	sscribe_branch_policy_record(
		$manifest, $failures, $warnings, $notes,
		'repository_governance_evidence_matches_repository',
		$repo_identity_ok ? 'PASS' : 'FAIL',
		$repo_identity_ok
			? 'repository identity matches SimplixInnovations/sscribe-export-site-pages'
			: 'repository governance evidence must identify SimplixInnovations/sscribe-export-site-pages'
	);

	$merge_policy_ok = true === ( $governance['allow_squash_merge'] ?? null )
		&& false === ( $governance['allow_merge_commit'] ?? null )
		&& false === ( $governance['allow_rebase_merge'] ?? null );
	sscribe_branch_policy_record(
		$manifest, $failures, $warnings, $notes,
		'repository_merge_methods_are_canonical',
		$merge_policy_ok ? 'PASS' : 'FAIL',
		$merge_policy_ok
			? 'squash enabled; merge commits disabled; rebase merge disabled'
			: 'strict release requires allow_squash_merge=true, allow_merge_commit=false, allow_rebase_merge=false'
	);

	$evidence_default_main = 'main' === (string) ( $governance['default_branch'] ?? '' );
	sscribe_branch_policy_record(
		$manifest, $failures, $warnings, $notes,
		'repository_governance_evidence_default_branch_is_main',
		$evidence_default_main ? 'PASS' : 'FAIL',
		$evidence_default_main
			? 'evidence default_branch=main'
			: 'repository governance evidence must record default_branch=main'
	);

	$evidence_branches = $governance['remote_branches'] ?? array();
	if ( is_array( $evidence_branches ) ) {
		$evidence_branches = array_values( array_unique( array_map( 'strval', $evidence_branches ) ) );
		sort( $evidence_branches );
	}
	$evidence_main_only = array( 'main' ) === $evidence_branches;
	sscribe_branch_policy_record(
		$manifest, $failures, $warnings, $notes,
		'repository_governance_evidence_has_main_only',
		$evidence_main_only ? 'PASS' : 'FAIL',
		$evidence_main_only
			? 'evidence remote_branches=[main]'
			: 'repository governance evidence must record remote_branches exactly [main]'
	);
} else {
	sscribe_branch_policy_record(
		$manifest, $failures, $warnings, $notes,
		'repository_governance_evidence_required_only_for_strict_release',
		'PASS',
		'normal source CI does not require mutable GitHub repository-settings evidence'
	);
}

$local_only = array();
foreach ( $local_refs as $ref ) {
	if ( ! isset( $remote_refs[ $ref ] ) ) {
		$local_only[] = $ref;
	}
}
if ( ! empty( $local_only ) ) {
	sscribe_branch_policy_record(
		$manifest,
		$failures,
		$warnings,
		$notes,
		'no_local_only_refs',
		$is_ci ? 'WARN' : 'FAIL',
		'local-only ref(s): ' . implode( ', ', $local_only )
	);
} else {
	sscribe_branch_policy_record( $manifest, $failures, $warnings, $notes, 'no_local_only_refs', 'PASS', count( $local_refs ) . ' local refs are mirrored by origin' );
}

$manifest['failures'] = $failures;
$manifest['warnings'] = $warnings;
$manifest['summary']  = array(
	'total_rules' => count( $manifest['rules'] ),
	'passed'      => count( array_filter( $manifest['rules'], static fn( $rule ) => 'PASS' === $rule['status'] ) ),
	'warnings'    => count( $warnings ),
	'failures'    => count( $failures ),
);

$manifest_dir = $repo_root . '/dist';
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest_path = $manifest_dir . '/branch-policy-manifest.json';
file_put_contents( $manifest_path, json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

echo '== Branch-Policy verifier ==' . PHP_EOL;
echo '  [mode] ' . ( $is_ci ? 'CI (GITHUB_ACTIONS=true)' : 'local' ) . PHP_EOL;
foreach ( $notes as $note ) {
	echo '  [note] ' . $note . PHP_EOL;
}
foreach ( $warnings as $warning ) {
	echo '  [warn] ' . $warning . PHP_EOL;
}
echo PHP_EOL;

if ( empty( $failures ) ) {
	echo '✓ Branch-Policy contract valid.' . PHP_EOL;
	echo '  manifest: ' . $manifest_path . PHP_EOL;
	exit( 0 );
}

echo '✗ Branch-Policy contract invalid:' . PHP_EOL;
foreach ( $failures as $failure ) {
	echo '  - ' . $failure . PHP_EOL;
}
echo '  manifest: ' . $manifest_path . PHP_EOL;
exit( 1 );
