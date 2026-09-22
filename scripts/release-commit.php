<?php
/**
 * SScribe release commit/tag helper.
 *
 * Non-tag mode commits prepared release changes to a transient release branch
 * so main can still be integrated through review. Tag mode is main-only and
 * refuses dirty or divergent state.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

if ( 'cli' !== php_sapi_name() ) {
	exit( 'This script must be run from the command line.' . PHP_EOL );
}

$create_tag        = in_array( '--tag', $argv, true );
$requested_version = null;
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( '--tag' === $arg ) {
		continue;
	}
	if ( null !== $requested_version ) {
		echo "Usage: php scripts/release-commit.php [version] [--tag]\n";
		exit( 1 );
	}
	$requested_version = $arg;
}

$root_dir      = dirname( __DIR__ );
$plugin_source = (string) file_get_contents( $root_dir . '/sscribe-export-site-pages.php' );
if ( ! preg_match( "/define\\s*\\(\\s*['\"]SSCRIBE_VERSION['\"]\\s*,\\s*['\"]([0-9]+\\.[0-9]+\\.[0-9]+)['\"]/", $plugin_source, $version_match ) ) {
	echo "Error: could not resolve SSCRIBE_VERSION.\n";
	exit( 1 );
}

$canonical_version = $version_match[1];
$new_version       = null === $requested_version ? $canonical_version : $requested_version;
if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $new_version ) ) {
	echo "Error: Version must be in format major.minor.patch.\n";
	exit( 1 );
}
if ( $new_version !== $canonical_version ) {
	echo "Error: requested release version does not match SSCRIBE_VERSION ({$canonical_version}).\n";
	exit( 1 );
}

$branch = trim( (string) shell_exec( 'git branch --show-current' ) );

if ( $create_tag ) {
	if ( 'main' !== $branch ) {
		echo "Error: release tags may be created only from main; current branch is '{$branch}'.\n";
		exit( 1 );
	}
	$status = trim( (string) shell_exec( 'git status --porcelain' ) );
	if ( '' !== $status ) {
		echo "Error: working tree must be clean before tagging. Merge reviewed release changes first.\n";
		exit( 1 );
	}
	exec( 'git fetch origin main', $fetch_output, $fetch_exit );
	if ( 0 !== $fetch_exit ) {
		echo "Error: git fetch origin main failed.\n";
		exit( 1 );
	}
	exec( 'git pull --ff-only origin main', $pull_output, $pull_exit );
	if ( 0 !== $pull_exit ) {
		echo "Error: local main cannot fast-forward to origin/main.\n";
		exit( 1 );
	}
	$local_main  = trim( (string) shell_exec( 'git rev-parse HEAD' ) );
	$remote_main = trim( (string) shell_exec( 'git rev-parse origin/main' ) );
	if ( '' === $remote_main || $local_main !== $remote_main ) {
		echo "Error: local main does not exactly match origin/main; refusing to tag.\n";
		exit( 1 );
	}

	// Tagging is the irreversible publication boundary. Re-run every strict
	// exact-release gate here instead of trusting a previous terminal command.
	// The gates bind the ignored evidence bundle and exact ZIP to this HEAD.
	$certified_sha = $local_main;
	putenv( 'SSCRIBE_RELEASE_CERTIFICATION=1' );
	$_ENV['SSCRIBE_RELEASE_CERTIFICATION']    = '1';
	$_SERVER['SSCRIBE_RELEASE_CERTIFICATION'] = '1';
	$strict_gates = array(
		'scripts/verify-manual-runtime-tests.php',
		'scripts/verify-release-blockers.php',
		'scripts/verify-final-ci-state.php',
		'scripts/verify-exact-artifact-evidence.php',
		'scripts/verify-agent-final-report.php',
		'scripts/verify-auditor-handoff.php',
		'scripts/release-audit.php',
	);
	foreach ( $strict_gates as $gate ) {
		echo "Running strict release gate: {$gate}\n";
		$gate_command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root_dir . '/' . $gate );
		passthru( $gate_command, $gate_exit );
		if ( 0 !== $gate_exit ) {
			echo "Error: Strict release certification failed at {$gate}; no tag was created.\n";
			exit( 1 );
		}
	}

	// Strict gates may write ignored dist evidence only. Any tracked mutation,
	// remote-main movement, or local HEAD movement invalidates the certification.
	$status = trim( (string) shell_exec( 'git status --porcelain' ) );
	if ( '' !== $status ) {
		echo "Error: strict certification left tracked/unignored changes; refusing to tag.\n";
		exit( 1 );
	}
	exec( 'git fetch origin main', $post_cert_fetch_output, $post_cert_fetch_exit );
	if ( 0 !== $post_cert_fetch_exit ) {
		echo "Error: could not refresh origin/main after certification; refusing to tag.\n";
		exit( 1 );
	}
	$post_cert_local  = trim( (string) shell_exec( 'git rev-parse HEAD' ) );
	$post_cert_remote = trim( (string) shell_exec( 'git rev-parse origin/main' ) );
	if ( $post_cert_local !== $certified_sha || $post_cert_remote !== $certified_sha ) {
		echo "Error: main moved during strict certification; evidence is stale and no tag was created.\n";
		exit( 1 );
	}

	$tag = 'v' . $new_version;
	exec( sprintf( 'git ls-remote --exit-code --tags origin refs/tags/%s', $tag ), $existing_tag_output, $existing_tag_exit );
	if ( 0 === $existing_tag_exit ) {
		echo "Error: remote tag {$tag} already exists; release tags are immutable.\n";
		exit( 1 );
	}
	if ( 2 !== $existing_tag_exit ) {
		echo "Error: could not verify whether remote tag {$tag} exists; refusing to create a tag.\n";
		exit( 1 );
	}
	exec( sprintf( 'git tag -a %s -m "Release %s"', $tag, $tag ), $tag_output, $tag_exit );
	if ( 0 !== $tag_exit ) {
		echo "Error: could not create annotated tag {$tag}.\n";
		exit( 1 );
	}
	exec( sprintf( 'git push origin %s', $tag ), $tag_push_output, $tag_push_exit );
	if ( 0 !== $tag_push_exit ) {
		echo "Error: could not push tag {$tag}.\n";
		exit( 1 );
	}
	echo "✓ Annotated tag {$tag} created from certified origin/main HEAD and pushed.\n";
	exit( 0 );
}

$release_branch = 'release/v' . $new_version;
if ( 'main' === $branch ) {
	exec( 'git fetch origin main', $fetch_output, $fetch_exit );
	if ( 0 !== $fetch_exit ) {
		echo "Error: git fetch origin main failed.\n";
		exit( 1 );
	}
	$local_main  = trim( (string) shell_exec( 'git rev-parse HEAD' ) );
	$remote_main = trim( (string) shell_exec( 'git rev-parse origin/main' ) );
	if ( '' === $remote_main || $local_main !== $remote_main ) {
		echo "Error: start release preparation from the current origin/main HEAD.\n";
		exit( 1 );
	}
	exec( sprintf( 'git switch -c %s', escapeshellarg( $release_branch ) ), $switch_output, $switch_exit );
	if ( 0 !== $switch_exit ) {
		echo "Error: could not create transient release branch {$release_branch}.\n";
		exit( 1 );
	}
	$branch = $release_branch;
}

if ( $branch !== $release_branch ) {
	echo "Error: release-commit must run on main or {$release_branch}; current branch is '{$branch}'.\n";
	exit( 1 );
}

exec( 'git add -A', $add_output, $add_exit );
if ( 0 !== $add_exit ) {
	echo "Error: git add failed.\n";
	exit( 1 );
}
$commit_msg = sprintf( 'chore: release v%s', $new_version );
exec( sprintf( 'git commit -m "%s"', $commit_msg ), $commit_output, $commit_exit );
if ( 0 !== $commit_exit ) {
	$combined = implode( "\n", $commit_output );
	if ( ! str_contains( $combined, 'nothing to commit' ) ) {
		echo "Error: git commit failed.\n";
		exit( 1 );
	}
	echo "Nothing to commit - release files are already prepared.\n";
} else {
	echo "✓ Committed: {$commit_msg}\n";
}

exec( sprintf( 'git push -u origin %s', escapeshellarg( $release_branch ) ), $push_output, $push_exit );
if ( 0 !== $push_exit ) {
	echo "Error: could not push transient release branch {$release_branch}.\n";
	exit( 1 );
}
echo "✓ Pushed {$release_branch}. Open a pull request to main, pass all gates, merge it, and delete the transient branch.\n";
echo "  After merge: switch to main, pull origin/main, then run composer release:tag.\n";
exit( 0 );
