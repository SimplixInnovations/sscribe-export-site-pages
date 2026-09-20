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

$is_ci = ( getenv( 'GITHUB_ACTIONS' ) === 'true' );
$manifest['ci_mode'] = $is_ci;

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
