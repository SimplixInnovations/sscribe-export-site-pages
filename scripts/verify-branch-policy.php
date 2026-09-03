<?php
/**
 * Branch topology policy verifier.
 *
 * Enforces the contract declared in docs/BRANCH_POLICY_v2.0.0.md:
 *
 *   - The policy doc exists with its canonical sections.
 *   - Only `main` and `develop` are allowed as long-lived branches.
 *   - Both branches exist locally AND on origin.
 *   - Both branches point to the same SHA locally and remotely.
 *   - There are no local-only refs (refs the remote does not mirror).
 *
 * Run from anywhere — every git invocation uses `git -C <repo_root>` so
 * the script does not depend on the shell's current working directory.
 *
 *     php scripts/verify-branch-policy.php
 *
 * Exits 0 on full pass, 1 on any rule violation.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$repo_root = dirname( __DIR__ );

$failures = array();
$notes    = array();

/**
 * Canonical sections every branch-policy doc must declare.
 * Add new sections here ONLY when docs/BRANCH_POLICY_v2.0.0.md
 * adds them — the integration test pins the same list.
 */
$canonical_sections = array(
	'## Why this exists',
	'## Canonical long-lived branches',
	'## Forbidden patterns',
	'## Promotion rules',
	'## How an independent auditor verifies this',
);

/**
 * Canonical long-lived branch set.
 */
$canonical_branches = array( 'main', 'develop' );

/**
 * Run a git command from inside the repo, capturing stdout.
 *
 * Note on stderr suppression: PHP's shell_exec on Windows shells out
 * to cmd.exe, which does not understand `2>/dev/null`. We therefore
 * use the OS-appropriate null device and silence stderr via `2>&1`
 * only when we actually need to ignore stderr noise.
 */
function git_run( string $repo_root, string $args ): string {
	$is_windows = ( PHP_OS_FAMILY === 'Windows' );
	$null_dev   = $is_windows ? 'NUL' : '/dev/null';

	// Forward-slash the repo root on Windows so cmd.exe does not
	// misinterpret backslashes; escapeshellarg then quotes it for
	// cmd.exe. On *nix, escapeshellarg uses single quotes.
	$root = $is_windows ? str_replace( '\\', '/', $repo_root ) : $repo_root;

	$cmd = 'git -C ' . escapeshellarg( $root ) . ' ' . $args . ' 2>' . $null_dev;
	return trim( (string) shell_exec( $cmd ) );
}

/* ------------------------------------------------------------------ *
 *  Rule 1: docs/BRANCH_POLICY_v2.0.0.md exists.
 * ------------------------------------------------------------------ */
$policy_doc = $repo_root . '/docs/BRANCH_POLICY_v2.0.0.md';
if ( ! is_file( $policy_doc ) ) {
	$failures[] = '[rule:policy_doc_exists] docs/BRANCH_POLICY_v2.0.0.md must exist so the branch policy is auditable.';
} else {
	$notes[] = '[rule:policy_doc_exists] OK';
}

/* ------------------------------------------------------------------ *
 *  Rule 2: doc declares the canonical sections.
 * ------------------------------------------------------------------ */
if ( is_file( $policy_doc ) ) {
	$src = (string) file_get_contents( $policy_doc );
	$missing_sections = array();
	foreach ( $canonical_sections as $section ) {
		if ( false === strpos( $src, $section ) ) {
			$missing_sections[] = $section;
		}
	}
	if ( ! empty( $missing_sections ) ) {
		foreach ( $missing_sections as $m ) {
			$failures[] = "[rule:policy_doc_has_canonical_sections] policy doc is missing canonical section: {$m}";
		}
	} else {
		$notes[] = '[rule:policy_doc_has_canonical_sections] OK (' . count( $canonical_sections ) . '/' . count( $canonical_sections ) . ' sections present)';
	}
}

/* ------------------------------------------------------------------ *
 *  Rule 3: only `main` and `develop` are local branches.
 * ------------------------------------------------------------------ */
$local_branches_raw = git_run( $repo_root, 'branch --format="%(refname:short)"' );
$local_branches     = array_values(
	array_filter(
		array_map( 'trim', explode( "\n", $local_branches_raw ) ),
		static function ( $b ) { return '' !== $b; }
	)
);
$forbidden_locals   = array_values( array_diff( $local_branches, $canonical_branches ) );
if ( ! empty( $forbidden_locals ) ) {
	$failures[] = '[rule:only_canonical_local_branches] forbidden local branch(es): ' . implode( ', ', $forbidden_locals ) . '. Only main and develop are allowed.';
} else {
	$notes[] = '[rule:only_canonical_local_branches] OK (local: ' . implode( ', ', $local_branches ) . ')';
}

/* ------------------------------------------------------------------ *
 *  Rule 4 & 5: both branches exist locally + on origin, share SHA.
 * ------------------------------------------------------------------ */
$shas = array();
foreach ( $canonical_branches as $b ) {
	$local_sha = git_run( $repo_root, 'rev-parse ' . escapeshellarg( $b ) );
	$shas[ $b . '_local' ] = $local_sha;

	if ( '' === $local_sha ) {
		$failures[] = "[rule:canonical_branches_exist_locally] local branch '{$b}' does not exist.";
		continue;
	}
	$remote_sha = git_run( $repo_root, "ls-remote origin refs/heads/{$b} | awk '{print \$1}'" );
	$shas[ $b . '_remote' ] = $remote_sha;
	if ( '' === $remote_sha ) {
		$failures[] = "[rule:canonical_branches_exist_on_origin] origin branch 'refs/heads/{$b}' does not exist.";
	}
}

$main_local     = $shas['main_local']    ?? '';
$develop_local  = $shas['develop_local'] ?? '';
$main_remote    = $shas['main_remote']   ?? '';
$develop_remote = $shas['develop_remote'] ?? '';

if ( '' !== $main_local && '' !== $develop_local && $main_local !== $develop_local ) {
	$failures[] = '[rule:branches_share_sha_locally] main (' . substr( $main_local, 0, 8 ) . ') and develop (' . substr( $develop_local, 0, 8 ) . ') point to different SHAs locally.';
} else {
	$notes[] = '[rule:branches_share_sha_locally] OK (' . substr( $main_local, 0, 8 ) . ')';
}

if ( '' !== $main_remote && '' !== $develop_remote && $main_remote !== $develop_remote ) {
	$failures[] = '[rule:branches_share_sha_remotely] origin/main (' . substr( $main_remote, 0, 8 ) . ') and origin/develop (' . substr( $develop_remote, 0, 8 ) . ') point to different SHAs on origin.';
} else {
	$notes[] = '[rule:branches_share_sha_remotely] OK (' . substr( $main_remote, 0, 8 ) . ')';
}

if ( '' !== $main_local && '' !== $main_remote && $main_local !== $main_remote ) {
	$failures[] = '[rule:local_main_matches_origin_main] local main (' . substr( $main_local, 0, 8 ) . ') differs from origin/main (' . substr( $main_remote, 0, 8 ) . ').';
} else {
	$notes[] = '[rule:local_main_matches_origin_main] OK';
}

if ( '' !== $develop_local && '' !== $develop_remote && $develop_local !== $develop_remote ) {
	$failures[] = '[rule:local_develop_matches_origin_develop] local develop (' . substr( $develop_local, 0, 8 ) . ') differs from origin/develop (' . substr( $develop_remote, 0, 8 ) . ').';
} else {
	$notes[] = '[rule:local_develop_matches_origin_develop] OK';
}

/* ------------------------------------------------------------------ *
 *  Rule 6: no local-only refs (refs not mirrored on origin).
 * ------------------------------------------------------------------ */
$local_refs_raw = git_run( $repo_root, "for-each-ref --format='%(refname)'" );
$local_refs     = array_values(
	array_filter(
		array_map(
			static function ( $r ) {
				return trim( $r, "'\"" );
			},
			explode( "\n", $local_refs_raw )
		),
		static function ( $r ) { return '' !== $r; }
	)
);
$local_only     = array();
foreach ( $local_refs as $ref ) {
	if ( 0 === strpos( $ref, 'refs/remotes/' ) ) {
		continue; // remote-tracking refs are expected.
	}
	$on_origin = git_run( $repo_root, 'ls-remote origin ' . escapeshellarg( $ref ) . ' | wc -l' );
	if ( '0' === $on_origin ) {
		$local_only[] = $ref;
	}
}
if ( ! empty( $local_only ) ) {
	$failures[] = '[rule:no_local_only_refs] local-only ref(s) not mirrored on origin: ' . implode( ', ', $local_only );
} else {
	$notes[] = '[rule:no_local_only_refs] OK (' . count( $local_refs ) . ' refs scanned, all mirrored)';
}

/* ------------------------------------------------------------------ *
 *  Result
 * ------------------------------------------------------------------ */
echo '== Branch-Policy verifier ==' . PHP_EOL;
foreach ( $notes as $n ) {
	echo '  [note] ' . $n . PHP_EOL;
}
echo PHP_EOL;
if ( empty( $failures ) ) {
	echo '✓ Branch-Policy contract valid.' . PHP_EOL;
	exit( 0 );
}

echo '✗ Branch-Policy contract invalid:' . PHP_EOL;
foreach ( $failures as $f ) {
	echo '  - ' . $f . PHP_EOL;
}
exit( 1 );
