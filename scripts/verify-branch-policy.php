<?php
/**
 * Branch topology policy verifier.
 *
 * Enforces the contract declared in docs/BRANCH_POLICY_v2.0.0.md:
 *
 *   - The policy doc exists with its canonical sections.
 *   - Only `main` and `develop` are allowed as long-lived branches.
 *   - Both branches exist on origin (the canonical contract is
 *     enforced against origin, not the local checkout, because
 *     CI checkouts are single-branch by design).
 *   - Both branches point to the same SHA on origin.
 *   - When a full local checkout IS available (local dev or a
 *     CI job that fetched all refs), local refs must match the
 *     remote refs and there must be no local-only refs.
 *
 * The CI-tolerance flag is detected via the GITHUB_ACTIONS env
 * var, which GitHub Actions always exports to `true`. In CI mode,
 * the strict local-ref checks are downgraded to soft warnings
 * so the gate can never block a PR on a single-branch checkout.
 *
 * Run from anywhere — every git invocation uses `git -C <repo_root>`
 * so the script does not depend on the shell's current directory.
 *
 *     php scripts/verify-branch-policy.php
 *
 * Always writes `dist/branch-policy-manifest.json` so the
 * documented debug recipe in docs/CI_COMMANDS.md is honoured.
 *
 * Exits 0 on full pass, 1 on any rule violation.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$repo_root = dirname( __DIR__ );

$failures     = array();
$warnings     = array();
$notes        = array();
$manifest     = array(
	'timestamp'  => gmdate( 'c' ),
	'ci_mode'    => getenv( 'GITHUB_ACTIONS' ) === 'true',
	'rules'      => array(),
	'failures'   => array(),
	'warnings'   => array(),
);

$canonical_sections = array(
	'## Why this exists',
	'## Canonical long-lived branches',
	'## Forbidden patterns',
	'## Promotion rules',
	'## How an independent auditor verifies this',
);

$canonical_branches = array( 'main', 'develop' );

/**
 * Run a git command from inside the repo, capturing stdout.
 * On Windows Git Bash, single quotes inside --format= are
 * required to escape the parentheses from bash itself; on a
 * native POSIX shell, double quotes would also work. We use
 * single quotes because Git Bash is the canonical Windows
 * shell for plugin contributors.
 *
 * stderr is routed to the OS-appropriate null device so a
 * missing remote (offline auditor) does not corrupt the
 * captured stdout.
 */
function git_run( string $repo_root, string $args ): string {
	$is_windows = ( PHP_OS_FAMILY === 'Windows' );
	$null_dev   = $is_windows ? 'NUL' : '/dev/null';
	$root       = $is_windows ? str_replace( '\\', '/', $repo_root ) : $repo_root;

	$cmd = 'git -C ' . escapeshellarg( $root ) . ' ' . $args . ' 2>' . $null_dev;
	return trim( (string) shell_exec( $cmd ) );
}

/**
 * Record a rule outcome for both stdout and the manifest.
 */
function record_rule( array &$manifest, array &$failures, array &$warnings, array &$notes, string $rule, string $status, string $detail ): void {
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

$is_ci = ( getenv( 'GITHUB_ACTIONS' ) === 'true' );
$manifest['ci_mode'] = $is_ci;

/* ------------------------------------------------------------------ *
 *  Rule 1: docs/BRANCH_POLICY_v2.0.0.md exists.
 * ------------------------------------------------------------------ */
$policy_doc = $repo_root . '/docs/BRANCH_POLICY_v2.0.0.md';
if ( ! is_file( $policy_doc ) ) {
	record_rule( $manifest, $failures, $warnings, $notes, 'policy_doc_exists', 'FAIL', 'docs/BRANCH_POLICY_v2.0.0.md must exist so the branch policy is auditable.' );
} else {
	record_rule( $manifest, $failures, $warnings, $notes, 'policy_doc_exists', 'PASS', 'docs/BRANCH_POLICY_v2.0.0.md present.' );
}

/* ------------------------------------------------------------------ *
 *  Rule 2: doc declares the canonical sections.
 * ------------------------------------------------------------------ */
if ( is_file( $policy_doc ) ) {
	$src             = (string) file_get_contents( $policy_doc );
	$missing         = array();
	foreach ( $canonical_sections as $section ) {
		if ( false === strpos( $src, $section ) ) {
			$missing[] = $section;
		}
	}
	if ( ! empty( $missing ) ) {
		foreach ( $missing as $m ) {
			record_rule( $manifest, $failures, $warnings, $notes, 'policy_doc_has_canonical_sections', 'FAIL', "policy doc is missing canonical section: {$m}" );
		}
	} else {
		record_rule( $manifest, $failures, $warnings, $notes, 'policy_doc_has_canonical_sections', 'PASS', count( $canonical_sections ) . '/' . count( $canonical_sections ) . ' sections present' );
	}
}

/* ------------------------------------------------------------------ *
 *  Rule 3: only `main` and `develop` are local branches.
 *
 *  In CI mode this becomes a soft warning because the
 *  actions/checkout step is single-branch by design. The
 *  origin-side enforcement (Rules 4-5 below) is the real
 *  contract.
 * ------------------------------------------------------------------ */
$local_branches_raw = git_run( $repo_root, 'for-each-ref --format="%(refname:short)" refs/heads/' );
$local_branches     = array_values(
	array_filter(
		array_map( 'trim', explode( "\n", $local_branches_raw ) ),
		static function ( $b ) { return '' !== $b; }
	)
);
$forbidden_locals   = array_values( array_diff( $local_branches, $canonical_branches ) );
if ( ! empty( $forbidden_locals ) ) {
	$detail = 'forbidden local branch(es): ' . implode( ', ', $forbidden_locals );
	if ( $is_ci ) {
		record_rule( $manifest, $failures, $warnings, $notes, 'only_canonical_local_branches', 'WARN', $detail . ' (CI mode — origin-side rules still enforced)' );
	} else {
		record_rule( $manifest, $failures, $warnings, $notes, 'only_canonical_local_branches', 'FAIL', $detail . '. Only main and develop are allowed.' );
	}
} else {
	record_rule( $manifest, $failures, $warnings, $notes, 'only_canonical_local_branches', 'PASS', 'local: ' . implode( ', ', $local_branches ) );
}

/* ------------------------------------------------------------------ *
 *  Rule 4: both branches exist locally (CI-tolerant) AND on origin.
 *
 *  Origin-side is always enforced; local-side is WARN in CI
 *  because a single-branch checkout is the documented CI norm.
 * ------------------------------------------------------------------ */
$shas = array();
foreach ( $canonical_branches as $b ) {
	// show-ref --verify is exit-status-safe: unlike rev-parse, it does not
	// echo an unresolved token such as "main" and accidentally look like a SHA.
	$local_ref             = 'refs/heads/' . $b;
	$local_sha             = git_run( $repo_root, 'show-ref --verify --hash ' . escapeshellarg( $local_ref ) );
	$shas[ $b . '_local' ] = $local_sha;

	if ( '' === $local_sha ) {
		if ( $is_ci ) {
			record_rule( $manifest, $failures, $warnings, $notes, "canonical_branch_{$b}_exists_locally", 'WARN', "local branch '{$b}' not present (detached CI checkout — origin-side enforced instead)" );
		} else {
			record_rule( $manifest, $failures, $warnings, $notes, "canonical_branch_{$b}_exists_locally", 'FAIL', "local branch '{$b}' does not exist." );
		}
	} else {
		record_rule( $manifest, $failures, $warnings, $notes, "canonical_branch_{$b}_exists_locally", 'PASS', substr( $local_sha, 0, 8 ) );
	}

	if ( $is_ci ) {
		// actions/checkout fetched all heads before removing its credentials.
		// Private-repository CI must therefore verify the authenticated
		// remote-tracking ref rather than performing a later unauthenticated
		// ls-remote call, which returns an empty result for a private origin.
		$remote_ref       = 'refs/remotes/origin/' . $b;
		$remote_sha_clean = git_run( $repo_root, 'show-ref --verify --hash ' . escapeshellarg( $remote_ref ) );
	} else {
		$remote_sha       = git_run( $repo_root, 'ls-remote origin ' . escapeshellarg( 'refs/heads/' . $b ) );
		$remote_sha_clean = '' === $remote_sha ? '' : trim( (string) preg_split( '/\s+/', $remote_sha )[0] );
	}
	$shas[ $b . '_remote' ] = $remote_sha_clean;

	if ( '' === $remote_sha_clean ) {
		record_rule( $manifest, $failures, $warnings, $notes, "canonical_branch_{$b}_exists_on_origin", 'FAIL', "origin branch 'refs/heads/{$b}' does not exist in the authenticated checkout evidence." );
	} else {
		record_rule( $manifest, $failures, $warnings, $notes, "canonical_branch_{$b}_exists_on_origin", 'PASS', substr( $remote_sha_clean, 0, 8 ) );
	}
}

/* ------------------------------------------------------------------ *
 *  Rule 5: local == origin (when both exist), and the two
 *          branches share the same SHA on origin.
 * ------------------------------------------------------------------ */
$main_local     = $shas['main_local'];
$develop_local  = $shas['develop_local'];
$main_remote    = $shas['main_remote'];
$develop_remote = $shas['develop_remote'];

if ( '' !== $main_remote && '' !== $develop_remote && $main_remote !== $develop_remote ) {
	record_rule( $manifest, $failures, $warnings, $notes, 'branches_share_sha_remotely', 'FAIL', 'origin/main (' . substr( $main_remote, 0, 8 ) . ') and origin/develop (' . substr( $develop_remote, 0, 8 ) . ') point to different SHAs on origin.' );
} else {
	record_rule( $manifest, $failures, $warnings, $notes, 'branches_share_sha_remotely', 'PASS', 'origin: ' . substr( $main_remote, 0, 8 ) );
}

if ( '' !== $main_local && '' !== $main_remote && $main_local !== $main_remote ) {
	$status = $is_ci ? 'WARN' : 'FAIL';
	record_rule( $manifest, $failures, $warnings, $notes, 'local_main_matches_origin_main', $status, 'local main (' . substr( $main_local, 0, 8 ) . ') differs from origin/main (' . substr( $main_remote, 0, 8 ) . ').' );
} elseif ( '' !== $main_local ) {
	record_rule( $manifest, $failures, $warnings, $notes, 'local_main_matches_origin_main', 'PASS', substr( $main_local, 0, 8 ) );
}

if ( '' !== $develop_local && '' !== $develop_remote && $develop_local !== $develop_remote ) {
	$status = $is_ci ? 'WARN' : 'FAIL';
	record_rule( $manifest, $failures, $warnings, $notes, 'local_develop_matches_origin_develop', $status, 'local develop (' . substr( $develop_local, 0, 8 ) . ') differs from origin/develop (' . substr( $develop_remote, 0, 8 ) . ').' );
} elseif ( '' !== $develop_local ) {
	record_rule( $manifest, $failures, $warnings, $notes, 'local_develop_matches_origin_develop', 'PASS', substr( $develop_local, 0, 8 ) );
}

/* ------------------------------------------------------------------ *
 *  Rule 6: no local-only refs.
 *
 *  In CI mode this is a soft warning because CI checkouts are
 *  partial by design; the origin-side rules above are the
 *  real contract.
 *
 *  Implementation: do ONE batched ls-remote of all known
 *  local branches + tags (one round-trip), then compare
 *  in-memory — not one subprocess per ref.
 * ------------------------------------------------------------------ */
$local_refs_raw = git_run( $repo_root, "for-each-ref --format='%(refname)' refs/heads/ refs/tags/" );
$local_refs     = array_values(
	array_filter(
		array_map(
			static function ( $r ) { return trim( $r, "'\"" ); },
			explode( "\n", $local_refs_raw )
		),
		static function ( $r ) { return '' !== $r; }
	)
);

$remote_refs_set = array();
if ( $is_ci ) {
	// Reconstruct the origin namespace from the authenticated full checkout.
	// Remote-tracking branch refs map back to refs/heads/*; fetched tags already
	// use their canonical refs/tags/* names.
	$checkout_refs_raw = git_run(
		$repo_root,
		"for-each-ref --format='%(refname)' refs/remotes/origin/ refs/tags/"
	);
	foreach ( explode( "\n", $checkout_refs_raw ) as $line ) {
		$ref = trim( $line, " \t\n\r\0\x0B'\"" );
		if ( '' === $ref || 'refs/remotes/origin/HEAD' === $ref ) {
			continue;
		}
		if ( str_starts_with( $ref, 'refs/remotes/origin/' ) ) {
			$ref = 'refs/heads/' . substr( $ref, strlen( 'refs/remotes/origin/' ) );
		}
		$remote_refs_set[ $ref ] = true;
	}
} else {
	// Local auditor runs retain credentials and can query the origin directly.
	$remote_refs_raw = git_run( $repo_root, 'ls-remote' );
	foreach ( explode( "\n", $remote_refs_raw ) as $line ) {
		$parts = preg_split( '/\s+/', trim( $line ), 2 );
		if ( is_array( $parts ) && count( $parts ) >= 2 ) {
			$remote_refs_set[ $parts[1] ] = true;
		}
	}
}

$local_only = array();
foreach ( $local_refs as $ref ) {
	if ( ! isset( $remote_refs_set[ $ref ] ) ) {
		$local_only[] = $ref;
	}
}
if ( ! empty( $local_only ) ) {
	$detail = 'local-only ref(s) not mirrored on origin: ' . implode( ', ', $local_only );
	if ( $is_ci ) {
		record_rule( $manifest, $failures, $warnings, $notes, 'no_local_only_refs', 'WARN', $detail . ' (CI mode — partial checkout expected)' );
	} else {
		record_rule( $manifest, $failures, $warnings, $notes, 'no_local_only_refs', 'FAIL', $detail );
	}
} else {
	record_rule( $manifest, $failures, $warnings, $notes, 'no_local_only_refs', 'PASS', count( $local_refs ) . ' refs scanned, all mirrored' );
}

/* ------------------------------------------------------------------ *
 *  Persist the manifest.
 * ------------------------------------------------------------------ */
$manifest['failures'] = $failures;
$manifest['warnings'] = $warnings;
$manifest['summary']  = array(
	'total_rules' => count( $manifest['rules'] ),
	'passed'      => count( array_filter( $manifest['rules'], static function ( $r ) { return 'PASS' === $r['status']; } ) ),
	'warnings'    => count( $warnings ),
	'failures'    => count( $failures ),
);

$manifest_dir  = $repo_root . '/dist';
$manifest_path = $manifest_dir . '/branch-policy-manifest.json';
if ( ! is_dir( $manifest_dir ) ) {
	@mkdir( $manifest_dir, 0755, true );
}
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
);

/* ------------------------------------------------------------------ *
 *  Result
 * ------------------------------------------------------------------ */
echo '== Branch-Policy verifier ==' . PHP_EOL;
echo '  [mode] ' . ( $is_ci ? 'CI (GITHUB_ACTIONS=true)' : 'local' ) . PHP_EOL;
foreach ( $notes as $n ) {
	echo '  [note] ' . $n . PHP_EOL;
}
foreach ( $warnings as $w ) {
	echo '  [warn] ' . $w . PHP_EOL;
}
echo PHP_EOL;

if ( empty( $failures ) ) {
	echo '✓ Branch-Policy contract valid.' . PHP_EOL;
	echo '  manifest: ' . $manifest_path . PHP_EOL;
	exit( 0 );
}

echo '✗ Branch-Policy contract invalid:' . PHP_EOL;
foreach ( $failures as $f ) {
	echo '  - ' . $f . PHP_EOL;
}
echo '  manifest: ' . $manifest_path . PHP_EOL;
exit( 1 );
